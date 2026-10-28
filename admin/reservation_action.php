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
            $holdUntil = null;
            with_locked_reservation($id, (int)$peek['room_id'], [], function ($res, $rooms) use (&$holdUntil) {
                require_status($res, ['pending'], 'confirmed');
                $room = $rooms[(int)$res['room_id']] ?? null;
                if (!$room) throw new RuleException('The room changed while you were working. Please try again.');
                $tenant = fetch_one("SELECT status FROM tenants WHERE id = ?", [$res['tenant_id']]);
                if (!$tenant || $tenant['status'] !== 'active') throw new RuleException('The tenant is inactive. Reactivate the tenant first.');
                assert_capacity($room, $res['move_in_date'], $res['move_out_date'], (int)$res['beds'], (int)$res['id']);
                // a bed that is not paid for is only held for a few days, then released automatically
                $holdUntil = reservation_financials($res)['advance_needed'] > 0 ? hold_deadline() : null;
                q("UPDATE reservations SET status='confirmed', confirmed_at=NOW(), hold_until=? WHERE id=?", [$holdUntil, $res['id']]);
                log_activity('reservation_confirmed', 'reservation', (int)$res['id'], $res['reservation_no'] . ' confirmed' . ($holdUntil ? ', held until ' . $holdUntil : ''));
            });
            $f = reservation_financials(fetch_one("SELECT * FROM reservations WHERE id = ?", [$id]));
            if ($holdUntil) {
                flash('success', 'Reservation confirmed. The bed is held until ' . fmt_datetime($holdUntil) . '.');
                flash('info', 'The tenant has until then to pay ' . money($f['advance_needed']) . '. If it is not paid, the hold ends and the bed is released automatically.');
                notify_reservation($id, 'reservation_held');
            } else {
                flash('success', 'Reservation confirmed. The reservation amount is already paid, so the bed is secured.');
                notify_reservation($id, 'reservation_secured', [], true);
            }
            break;

        case 'checkin':
            $ack = post_str('ack_balance', 2) === '1';
            $notes = post_str('checkin_notes', 1500);
            with_locked_reservation($id, (int)$peek['room_id'], [], function ($res, $rooms) use ($ack, $notes) {
                require_status($res, ['confirmed'], 'checked in');
                $room = $rooms[(int)$res['room_id']] ?? null;
                if (!$room) throw new RuleException('The room changed while you were working. Please try again.');
                if ($res['move_out_date'] && $res['move_out_date'] <= today()) {
                    throw new RuleException('The planned move-out date (' . fmt_date($res['move_out_date']) . ') has already passed. Edit the reservation first.');
                }
                // what is still owed on move-in day (deposit + the rest of the first month)
                $owed = compute_financials(array_merge($res, ['status' => 'checked_in', 'checked_in_at' => date('Y-m-d H:i:s')]), payment_totals([(int)$res['id']])[(int)$res['id']] ?? [])['balance'];
                if ($owed > 0 && !$ack) {
                    throw new RuleException('The tenant still owes ' . money($owed) . ' for move-in. Record the payment first, or tick the box to allow check-in with a balance.');
                }
                $start = min($res['move_in_date'], today());
                assert_capacity($room, $start, $res['move_out_date'], (int)$res['beds'], (int)$res['id']);
                q("UPDATE reservations SET status='checked_in', checked_in_at=NOW(), hold_until=NULL, checkin_notes=? WHERE id=?", [$notes !== '' ? $notes : null, $res['id']]);
                log_activity('checked_in', 'reservation', (int)$res['id'], $res['reservation_no'] . ' checked in to Room ' . $room['room_no'] . ($owed > 0 ? ' with ' . money($owed) . ' unpaid' : ''));
            });
            flash('success', 'Tenant checked in. Rent is now due on the same date every month, and reminders go out automatically.');
            $f = reservation_financials(fetch_one("SELECT * FROM reservations WHERE id = ?", [$id]));
            if ($f['balance'] > 0) {
                flash('warn', 'This tenant still has an unpaid balance of ' . money($f['balance']) . '.');
            }
            break;

        case 'checkout':
            $date = post_str('checkout_date', 10);
            $notes = post_str('checkout_notes', 1500);
            $applyBal = post_str('apply_balance', 2) === '1';
            $damagesRaw = post_str('damages', 15);
            $dReason = post_str('deduct_reason', 255);
            $refundNow = post_str('refund_now', 2) === '1';
            $method = post_str('refund_method', 15);
            $refRef = post_str('refund_ref', 80);
            $out = [];
            with_locked_reservation($id, (int)$peek['room_id'], [], function ($res, $rooms) use ($date, $notes, $applyBal, $damagesRaw, $dReason, $refundNow, $method, $refRef, &$out) {
                require_status($res, ['checked_in'], 'checked out');
                if (!valid_date($date)) throw new RuleException('Enter a valid check-out date.');
                if ($date > today()) throw new RuleException('The check-out date cannot be in the future.');
                if ($date < substr((string)$res['checked_in_at'], 0, 10)) throw new RuleException('The check-out date cannot be before the check-in date (' . fmt_date($res['checked_in_at']) . ').');
                $sim = checkout_simulation($res, $date);
                $held = $sim['refundable'];                 // deposit (and rent paid ahead) that can still go back
                $keptRent = $applyBal ? min($sim['balance'], $held) : 0.0;
                $keptOther = $damagesRaw === '' ? 0.0 : parse_money($damagesRaw);
                if ($keptOther === null || $keptOther < 0) throw new RuleException('Enter the damages or other deductions as a number, for example 250 (or leave it empty).');
                if ($keptOther > 0 && mb_strlen($dReason) < 3) throw new RuleException('Write a short reason for the deduction (for example "broken window"). It is shown on the move-out summary.');
                if ($keptRent + $keptOther > $held + 0.001) throw new RuleException('The deductions (' . money($keptRent + $keptOther) . ') are more than the deposit held (' . money($held) . ').');
                $refund = round($held - $keptRent - $keptOther, 2);
                $pid = null;
                if ($refundNow && $refund > 0) {
                    if (!in_array($method, ['cash', 'gcash', 'bank_transfer', 'other'], true)) throw new RuleException('Choose how the refund is returned.');
                    if (in_array($method, ['gcash', 'bank_transfer'], true) && $refRef === '') throw new RuleException('Enter the reference number of the GCash or bank refund.');
                }
                $when = $date === today() ? date('Y-m-d H:i:s') : $date . ' 12:00:00';
                $reason = trim(($keptRent > 0 ? 'unpaid rent ' . money($keptRent) : '') . ($keptRent > 0 && $keptOther > 0 ? '; ' : '') . ($keptOther > 0 ? $dReason : ''));
                q("UPDATE reservations SET status='completed', checked_out_at=?, move_out_date=?, checkout_notes=?, deposit_kept_rent=?, deposit_kept_other=?, deposit_kept_reason=?, hold_until=NULL WHERE id=?",
                  [$when, $date, $notes !== '' ? $notes : null, $keptRent, $keptOther, $reason !== '' ? mb_substr($reason, 0, 255) : null, $res['id']]);
                if ($refundNow && $refund > 0) {
                    $pid = record_payment_row($res, 'refund', $refund, today(), $method, $refRef !== '' ? $refRef : null,
                        'Deposit refund at move-out' . ($reason !== '' ? ' (kept: ' . $reason . ')' : ''));
                }
                log_activity('checked_out', 'reservation', (int)$res['id'], $res['reservation_no'] . ' checked out on ' . $date . '; deposit held ' . money($held) . ', kept ' . money($keptRent + $keptOther) . ', refunded ' . money($pid ? $refund : 0));
                $out = ['held' => $held, 'kept_rent' => $keptRent, 'kept_other' => $keptOther, 'refund' => $refund, 'pid' => $pid, 'reason' => $dReason, 'method' => $method];
            });
            $f = reservation_financials(fetch_one("SELECT * FROM reservations WHERE id = ?", [$id]));
            flash('success', 'Tenant checked out. The beds are open again.');
            if ($out['pid']) {
                flash('success', 'Deposit refund of ' . money($out['refund']) . ' recorded (receipt ready).');
            } elseif ($out['refund'] > 0) {
                flash('info', 'Deposit to return: ' . money($out['refund']) . '. Record the refund in Payments when you hand it back.');
            }
            if ($f['balance'] > 0) {
                flash('warn', 'The tenant left with an unpaid balance of ' . money($f['balance']) . '. You can still record a payment.');
            }
            notify_reservation($id, 'settlement', ['deposit' => $out['held'] + 0.0, 'kept_rent' => $out['kept_rent'], 'kept_other' => $out['kept_other'], 'reason' => $out['reason'],
                'refund' => $out['pid'] ? $out['refund'] : 0.0, 'refund_method' => ['cash' => 'cash', 'gcash' => 'GCash', 'bank_transfer' => 'bank transfer', 'other' => ''][$out['method']] ?? '', 'balance_after' => $f['balance']]);
            break;

        case 'cancel':
            $reason = post_str('reason', 255);
            $refundRaw = post_str('refund_amount', 15);
            $method = post_str('refund_method', 15);
            $refRef = post_str('refund_ref', 80);
            $out = [];
            with_locked_reservation($id, (int)$peek['room_id'], [], function ($res, $rooms) use ($reason, $refundRaw, $method, $refRef, &$out) {
                require_status($res, ['pending', 'confirmed'], 'cancelled');
                if (mb_strlen($reason) < 3) throw new RuleException('Please write a short reason for the cancellation.');
                $held = reservation_financials($res)['held'];
                $ls = lifecycle_settings();
                $default = (!$ls['fee_refundable'] && $ls['fee'] > 0) ? max(0.0, round($held - min($held, $ls['fee']), 2)) : $held;
                $refund = $refundRaw === '' ? $default : parse_money($refundRaw);
                if ($refund === null || $refund < 0) throw new RuleException('Enter the refund as a number, for example 500 (or 0 for no refund).');
                if ($refund > $held + 0.001) throw new RuleException('The refund cannot be more than the ' . money($held) . ' received for this reservation.');
                if ($refund > 0) {
                    if (!in_array($method, ['cash', 'gcash', 'bank_transfer', 'other'], true)) throw new RuleException('Choose how the refund is returned.');
                    if (in_array($method, ['gcash', 'bank_transfer'], true) && $refRef === '') throw new RuleException('Enter the reference number of the GCash or bank refund.');
                }
                $keptNow = round($held - $refund, 2);
                q("UPDATE reservations SET status='cancelled', cancelled_at=NOW(), cancel_reason=?, hold_until=NULL, deposit_kept_other=?, deposit_kept_reason=? WHERE id=?",
                  [$reason, $keptNow, $keptNow > 0 ? 'Kept on cancellation (reservation fee policy)' : null, $res['id']]);
                if ($refund > 0) {
                    record_payment_row($res, 'refund', $refund, today(), $method, $refRef !== '' ? $refRef : null, 'Refund for cancelled reservation');
                }
                log_activity('reservation_cancelled', 'reservation', (int)$res['id'], $res['reservation_no'] . ' cancelled: ' . $reason . '; received ' . money($held) . ', refunded ' . money($refund) . ', kept ' . money($held - $refund));
                $out = ['held' => $held, 'refund' => $refund, 'kept' => round($held - $refund, 2), 'method' => $method, 'reason' => $reason];
            });
            flash('success', 'Reservation cancelled. Any held beds are open again.');
            if ($out['refund'] > 0) flash('success', 'Refund of ' . money($out['refund']) . ' recorded.');
            if ($out['kept'] > 0) flash('info', money($out['kept']) . ' was kept (reservation fee policy).');
            notify_reservation($id, 'reservation_cancelled', ['held' => $out['held'], 'refund' => $out['refund'], 'kept' => $out['kept'], 'reason' => $out['reason'],
                'refund_method' => ['cash' => 'cash', 'gcash' => 'GCash', 'bank_transfer' => 'bank transfer', 'other' => ''][$out['method']] ?? '']);
            break;

        case 'notice':
            $moveOut = post_str('notice_move_out', 10);
            $shortOk = post_str('short_ok', 2) === '1';
            with_locked_reservation($id, (int)$peek['room_id'], [], function ($res, $rooms) use ($moveOut, $shortOk) {
                require_status($res, ['checked_in'], 'given a move-out notice');
                if (!valid_date($moveOut) || $moveOut <= today()) throw new RuleException('Enter the date the tenant will move out (a day after today or later).');
                if ($moveOut <= substr((string)$res['checked_in_at'], 0, 10)) throw new RuleException('The move-out date must be after the check-in date.');
                $needDays = lifecycle_settings()['notice_days'];
                $given = days_between(today(), $moveOut);
                if ($given < $needDays && !$shortOk) {
                    throw new RuleException('Your house policy asks for ' . $needDays . ' days\' notice, and this gives ' . $given . '. Tick the box to accept the shorter notice.');
                }
                q("UPDATE reservations SET move_out_date=?, notice_date=CURDATE() WHERE id=?", [$moveOut, $res['id']]);
                log_activity('moveout_notice', 'reservation', (int)$res['id'], $res['reservation_no'] . ' will move out on ' . $moveOut . ' (' . $given . ' days\' notice)');
            });
            flash('success', 'Move-out notice recorded. Rent is billed until ' . fmt_date($moveOut) . '.');
            notify_reservation($id, 'moveout_notice', ['key' => $moveOut]);
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
