<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();

if (!is_post()) {
    redirect('admin/reservations.php');
}
csrf_verify();

$id = post_int('id');
$action = post_str('action', 20);
$peek = $id ? fetch_one("SELECT * FROM reservations WHERE id = ?", [$id]) : null;
if (!$peek) {
    abort(404, 'That reservation does not exist.');
}

/**
 * Runs $work inside a transaction with the room(s) locked first (always in
 * ascending id order) and the reservation re-read afterwards, so two
 * simultaneous clicks or two browser tabs cannot double-book a bed.
 */
function with_locked_reservation(int $id, int $expectedRoomId, array $extraRoomIds, callable $work)
{
    return in_transaction(function () use ($id, $expectedRoomId, $extraRoomIds, $work) {
        $roomIds = array_values(array_unique(array_merge([$expectedRoomId], $extraRoomIds)));
        sort($roomIds);
        $rooms = [];
        foreach ($roomIds as $rid) {
            $rooms[$rid] = lock_room($rid);
        }
        $res = fetch_one("SELECT * FROM reservations WHERE id = ? FOR UPDATE", [$id]);
        if (!$res) {
            throw new RuleException('That reservation no longer exists.');
        }
        if ((int)$res['room_id'] !== $expectedRoomId) {
            throw new RuleException('This reservation was moved to another room while you were working. Refresh the page and try again.');
        }
        return $work($res, $rooms);
    });
}

function require_status(array $res, array $allowed, string $what): void
{
    if (!in_array($res['status'], $allowed, true)) {
        throw new RuleException('This reservation is now "' . str_replace('_', ' ', $res['status']) . '", so it cannot be ' . $what . '. Refresh the page to see its latest status.');
    }
}

try {
    switch ($action) {
        case 'confirm':
            with_locked_reservation($id, (int)$peek['room_id'], [], function ($res, $rooms) {
                require_status($res, ['pending'], 'confirmed');
                $room = $rooms[(int)$res['room_id']] ?? null;
                if (!$room) throw new RuleException('The room changed while you were working. Please try again.');
                $tenant = fetch_one("SELECT status FROM tenants WHERE id = ?", [$res['tenant_id']]);
                if (!$tenant || $tenant['status'] !== 'active') throw new RuleException('The tenant is inactive. Reactivate the tenant first.');
                assert_capacity($room, $res['move_in_date'], $res['move_out_date'], (int)$res['beds'], (int)$res['id']);
                q("UPDATE reservations SET status='confirmed', confirmed_at=NOW() WHERE id=?", [$res['id']]);
                log_activity('reservation_confirmed', 'reservation', (int)$res['id'], $res['reservation_no'] . ' confirmed');
            });
            flash('success', 'Reservation confirmed. The room is now held for the tenant.');
            $f = reservation_financials(fetch_one("SELECT * FROM reservations WHERE id = ?", [$id]));
            if ($f['advance_needed'] > 0) {
                flash('info', 'Next step: collect the advance payment of ' . money($f['advance_needed']) . ' and record it.');
            }
            break;

        case 'checkin':
            with_locked_reservation($id, (int)$peek['room_id'], [], function ($res, $rooms) {
                require_status($res, ['confirmed'], 'checked in');
                $room = $rooms[(int)$res['room_id']] ?? null;
                if (!$room) throw new RuleException('The room changed while you were working. Please try again.');
                if ($res['move_out_date'] && $res['move_out_date'] <= today()) {
                    throw new RuleException('The planned move-out date (' . fmt_date($res['move_out_date']) . ') has already passed. Edit the reservation first.');
                }
                $start = min($res['move_in_date'], today());
                assert_capacity($room, $start, $res['move_out_date'], (int)$res['beds'], (int)$res['id']);
                q("UPDATE reservations SET status='checked_in', checked_in_at=NOW() WHERE id=?", [$res['id']]);
                log_activity('checked_in', 'reservation', (int)$res['id'], $res['reservation_no'] . ' checked in to Room ' . $room['room_no']);
            });
            flash('success', 'Tenant checked in. The room status was updated.');
            $f = reservation_financials(fetch_one("SELECT * FROM reservations WHERE id = ?", [$id]));
            if ($f['balance'] > 0) {
                flash('warn', 'This tenant still has an unpaid balance of ' . money($f['balance']) . '.');
            }
            break;

        case 'checkout':
            $date = post_str('checkout_date', 10);
            with_locked_reservation($id, (int)$peek['room_id'], [], function ($res, $rooms) use ($date) {
                require_status($res, ['checked_in'], 'checked out');
                if (!valid_date($date)) throw new RuleException('Enter a valid check-out date.');
                if ($date > today()) throw new RuleException('The check-out date cannot be in the future.');
                if ($date < substr((string)$res['checked_in_at'], 0, 10)) throw new RuleException('The check-out date cannot be before the check-in date (' . fmt_date($res['checked_in_at']) . ').');
                $when = $date === today() ? date('Y-m-d H:i:s') : $date . ' 12:00:00';
                q("UPDATE reservations SET status='completed', checked_out_at=?, move_out_date=? WHERE id=?", [$when, $date, $res['id']]);
                log_activity('checked_out', 'reservation', (int)$res['id'], $res['reservation_no'] . ' checked out on ' . $date);
            });
            flash('success', 'Tenant checked out. The beds are open again.');
            $fresh = fetch_one("SELECT * FROM reservations WHERE id = ?", [$id]);
            $f = reservation_financials($fresh);
            if ($f['balance'] > 0) {
                flash('warn', 'The tenant left with an unpaid balance of ' . money($f['balance']) . '. You can still record a payment.');
            }
            $depHeld = $f['paid_deposit'] - $f['refunded'];
            if ($depHeld > 0) {
                flash('info', 'Security deposit held: ' . money($depHeld) . '. Record a refund payment when you return it.');
            }
            break;

        case 'cancel':
            $reason = post_str('reason', 255);
            with_locked_reservation($id, (int)$peek['room_id'], [], function ($res, $rooms) use ($reason) {
                require_status($res, ['pending', 'confirmed'], 'cancelled');
                if (mb_strlen($reason) < 3) throw new RuleException('Please write a short reason for the cancellation.');
                q("UPDATE reservations SET status='cancelled', cancelled_at=NOW(), cancel_reason=? WHERE id=?", [$reason, $res['id']]);
                log_activity('reservation_cancelled', 'reservation', (int)$res['id'], $res['reservation_no'] . ' cancelled: ' . $reason);
            });
            flash('success', 'Reservation cancelled. Any held beds are open again.');
            $f = reservation_financials(fetch_one("SELECT * FROM reservations WHERE id = ?", [$id]));
            if ($f['held'] > 0) {
                flash('info', 'Payments of ' . money($f['held']) . ' were received for this reservation. Record a refund if you are returning money.');
            }
            break;

        case 'transfer':
            $newRoomId = post_int('new_room_id');
            if (!$newRoomId) throw new RuleException('Choose the room to transfer to.');
            with_locked_reservation($id, (int)$peek['room_id'], [$newRoomId], function ($res, $rooms) use ($newRoomId) {
                require_status($res, ['confirmed', 'checked_in'], 'transferred');
                $old = $rooms[(int)$res['room_id']] ?? null;
                $new = $rooms[$newRoomId] ?? null;
                if (!$old) throw new RuleException('The reservation was moved while you were working. Please try again.');
                if ((int)$res['room_id'] === $newRoomId) throw new RuleException('The tenant is already in Room ' . $new['room_no'] . '.');
                $tenant = fetch_one("SELECT full_name, gender FROM tenants WHERE id = ?", [$res['tenant_id']]);
                if ($new['gender_policy'] !== 'any' && $new['gender_policy'] !== $tenant['gender']) {
                    throw new RuleException('Room ' . $new['room_no'] . ' is ' . strtolower(gender_label($new['gender_policy'])) . ', which does not match this tenant.');
                }
                $start = $res['status'] === 'checked_in' ? today() : $res['move_in_date'];
                assert_capacity($new, $start, $res['move_out_date'], (int)$res['beds'], (int)$res['id']);
                q("UPDATE reservations SET room_id=? WHERE id=?", [$newRoomId, $res['id']]);
                log_activity('room_transferred', 'reservation', (int)$res['id'], $res['reservation_no'] . ' moved from Room ' . $old['room_no'] . ' to Room ' . $new['room_no']);
            });
            flash('success', 'Room assignment updated.');
            break;

        default:
            throw new RuleException('Unknown action.');
    }
} catch (RuleException $ex) {
    flash('error', $ex->getMessage());
}
redirect('admin/reservation_view.php?id=' . $id);
