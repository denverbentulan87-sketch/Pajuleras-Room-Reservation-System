<?php
declare(strict_types=1);

/**
 * The reservation process, modelled on how boarding houses and dormitories really work:
 *
 *   Inquiry -> Reservation -> BED HELD for a few days -> reservation amount paid (bed SECURED)
 *           -> Move-in (collect deposit + rest of the first month, check the room)
 *           -> Monthly rent on the move-in date, reminders when late
 *           -> Move-out notice (30 days) -> Move-out: room check, deposit settled and returned
 *
 *  - A held bed that is not paid for in time is released automatically, so beds never stay
 *    blocked by someone who never paid.
 *  - Paying the reservation amount confirms the reservation by itself (no extra clicking).
 *  - A reservation fee can be set in Settings; it counts toward the first month's rent.
 *  - The deposit is settled at move-out: unpaid rent and damages are deducted, the rest is returned.
 */

function lifecycle_settings(): array
{
    return [
        'fee'            => max(0.0, (float)str_replace(',', '', setting('reservation_fee', '0'))),
        'hold_days'      => max(1, min(30, (int)setting('hold_days', '3'))),
        'notice_days'    => max(0, min(90, (int)setting('notice_days', '30'))),
        'fee_refundable' => setting('reservation_fee_refundable', '0') === '1',
    ];
}

/** The amount that secures a bed: the reservation fee when one is set, otherwise the advance months of rent. */
function default_advance_amount(float $monthlyRate): float
{
    $ls = lifecycle_settings();
    if ($ls['fee'] > 0) {
        return round($ls['fee'], 2);
    }
    return round($monthlyRate * max(0, (int)setting('advance_months', '1')), 2);
}

function hold_deadline(): string
{
    return date('Y-m-d H:i:s', strtotime('+' . lifecycle_settings()['hold_days'] . ' days'));
}

/* ------------------------------------------------------------- payments */

/** Insert a payment row with its official receipt number (call inside the caller's transaction). */
function record_payment_row(array $res, string $type, float $amount, string $date, string $method, ?string $ref, ?string $remarks): int
{
    q("INSERT INTO payments (reservation_id, tenant_id, payment_type, amount, payment_date, method, reference_no, remarks) VALUES (?,?,?,?,?,?,?,?)",
      [$res['id'], $res['tenant_id'], $type, $amount, $date, $method, $ref, $remarks]);
    $nid = (int)db()->lastInsertId();
    $no = 'OR-' . str_pad((string)$nid, 6, '0', STR_PAD_LEFT);
    q("UPDATE payments SET receipt_no = ? WHERE id = ?", [$no, $nid]);
    log_activity('payment_recorded', 'reservation', (int)$res['id'], $no . ': ' . payment_type_label($type) . ' ' . money($amount));
    return $nid;
}

/* ------------------------------------------------- one-time form tokens */

/** A single-use token for a form, so a double click or a refresh cannot save it twice. */
function form_token(): string
{
    $t = bin2hex(random_bytes(12));
    $_SESSION['form_tokens'][$t] = time();
    if (count($_SESSION['form_tokens']) > 40) {
        $_SESSION['form_tokens'] = array_slice($_SESSION['form_tokens'], -20, null, true);
    }
    return $t;
}

function form_token_field(): string
{
    return '<input type="hidden" name="form_token" value="' . e(form_token()) . '">';
}

/** True once per token. */
function consume_form_token(string $t): bool
{
    if ($t === '' || !isset($_SESSION['form_tokens'][$t])) {
        return false;
    }
    unset($_SESSION['form_tokens'][$t]);
    return true;
}

/* ------------------------------------------------- bed hold and securing */

/**
 * Called after any payment is recorded (cash or approved GCash).
 * - Clears the hold once the amount that secures the bed is paid.
 * - Confirms a pending reservation by itself when that amount is paid and the bed is still free.
 * @return array{changed:bool, message:string}
 */
function secure_reservation_after_payment(int $resId): array
{
    $none = ['changed' => false, 'message' => ''];
    $res = fetch_one("SELECT * FROM reservations WHERE id = ?", [$resId]);
    if (!$res || !in_array($res['status'], ['pending', 'confirmed'], true)) {
        return $none;
    }
    $fin = reservation_financials($res);
    if ($fin['advance_needed'] > 0) {
        return $none;
    }
    if ($res['status'] === 'confirmed') {
        if ($res['hold_until'] !== null) {
            q("UPDATE reservations SET hold_until = NULL WHERE id = ?", [$resId]);
            notify_reservation($resId, 'reservation_secured', [], true);
            return ['changed' => true, 'message' => 'The reservation amount is paid, so the bed is now secured.'];
        }
        return $none;
    }
    // pending -> confirmed
    try {
        $ok = in_transaction(function () use ($resId) {
            $r = fetch_one("SELECT * FROM reservations WHERE id = ? FOR UPDATE", [$resId]);
            if (!$r || $r['status'] !== 'pending') {
                return false;
            }
            $room = lock_room((int)$r['room_id']);
            $tenant = fetch_one("SELECT status FROM tenants WHERE id = ?", [$r['tenant_id']]);
            if (!$tenant || $tenant['status'] !== 'active') {
                throw new RuleException('the tenant is inactive');
            }
            assert_capacity($room, $r['move_in_date'], $r['move_out_date'], (int)$r['beds'], (int)$r['id']);
            q("UPDATE reservations SET status = 'confirmed', confirmed_at = NOW(), hold_until = NULL WHERE id = ?", [$resId]);
            log_activity('reservation_confirmed', 'reservation', $resId, $r['reservation_no'] . ' confirmed automatically because the reservation amount was paid');
            return true;
        });
    } catch (RuleException $ex) {
        return ['changed' => false, 'message' => 'The payment was recorded, but the bed could not be held automatically (' . rtrim($ex->getMessage(), '.') . '). Open the reservation to confirm it by hand.'];
    }
    if ($ok) {
        notify_reservation($resId, 'reservation_secured', [], true);
        return ['changed' => true, 'message' => 'The reservation amount is paid, so the reservation was confirmed automatically and the bed is secured.'];
    }
    return $none;
}

/** Release beds whose hold ran out without the reservation amount being paid. @return int how many */
function expire_unpaid_holds(): int
{
    $n = 0;
    try {
        $rows = fetch_all("SELECT id FROM reservations WHERE status = 'confirmed' AND hold_until IS NOT NULL AND hold_until < NOW()");
    } catch (Throwable $e) {
        return 0;   // older database without the column: nothing to do
    }
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $done = in_transaction(function () use ($id) {
            $res = fetch_one("SELECT * FROM reservations WHERE id = ? FOR UPDATE", [$id]);
            if (!$res || $res['status'] !== 'confirmed' || $res['hold_until'] === null || $res['hold_until'] >= date('Y-m-d H:i:s')) {
                return false;
            }
            if (reservation_financials($res)['advance_needed'] <= 0) {
                q("UPDATE reservations SET hold_until = NULL WHERE id = ?", [$id]);
                return false;
            }
            q("UPDATE reservations SET status = 'cancelled', cancelled_at = NOW(), cancel_reason = ?, hold_until = NULL WHERE id = ?",
              ['Hold expired: the reservation amount was not received by ' . fmt_datetime($res['hold_until']), $id]);
            log_activity('hold_expired', 'reservation', $id, $res['reservation_no'] . ' released: the hold ran out without payment');
            return true;
        });
        if ($done) {
            $n++;
            notify_reservation($id, 'hold_expired');
        }
    }
    return $n;
}

/** What the money position would be if the tenant left on $date. */
function checkout_simulation(array $res, string $date): array
{
    $when = $date === today() ? date('Y-m-d H:i:s') : $date . ' 12:00:00';
    $sim = array_merge($res, ['status' => 'completed', 'checked_out_at' => $when, 'move_out_date' => $date]);
    $t = payment_totals([(int)$res['id']]);
    return compute_financials($sim, $t[(int)$res['id']] ?? []);
}

/* ------------------------------------------------------------ progress */

/**
 * Where a reservation is in the process, and what the landlord should do next.
 * @return array{steps:array<int,array{label:string,state:string,note:string}>, next:string}
 */
function reservation_stage(array $res, array $fin): array
{
    $st = $res['status'];
    $held = $st === 'confirmed' && $res['hold_until'] !== null;
    $steps = [
        ['label' => 'Reserved', 'state' => 'done', 'note' => fmt_date(substr((string)$res['created_at'], 0, 10))],
        ['label' => 'Bed held', 'state' => 'todo', 'note' => ''],
        ['label' => 'Secured', 'state' => 'todo', 'note' => ''],
        ['label' => 'Moved in', 'state' => 'todo', 'note' => ''],
        ['label' => 'Monthly rent', 'state' => 'todo', 'note' => ''],
        ['label' => 'Moved out', 'state' => 'todo', 'note' => ''],
    ];
    $next = '';
    if ($st === 'cancelled') {
        return ['steps' => [['label' => 'Reserved', 'state' => 'done', 'note' => ''], ['label' => 'Cancelled', 'state' => 'current', 'note' => $res['cancelled_at'] ? fmt_date(substr((string)$res['cancelled_at'], 0, 10)) : '']],
                'next' => 'Cancelled: ' . ($res['cancel_reason'] ?: 'no reason given') . '.' . ($fin['refundable'] > 0 ? ' ' . money($fin['refundable']) . ' can still be refunded.' : '')];
    }
    if ($st === 'pending') {
        $steps[1]['state'] = 'current';
        $next = 'Confirm the reservation to hold the bed. It also confirms by itself as soon as the reservation amount (' . money($fin['advance_needed']) . ') is paid.';
    } elseif ($st === 'confirmed') {
        $steps[1]['state'] = 'done';
        $steps[1]['note'] = $res['confirmed_at'] ? fmt_date(substr((string)$res['confirmed_at'], 0, 10)) : '';
        if ($fin['advance_needed'] > 0) {
            $steps[2]['state'] = 'current';
            $steps[2]['note'] = 'pay ' . money($fin['advance_needed']);
            $next = 'Waiting for the reservation amount of ' . money($fin['advance_needed']) . ($held ? ' by ' . fmt_datetime($res['hold_until']) . '. The hold ends automatically after that and the bed is released.' : '.');
        } else {
            $steps[2]['state'] = 'done';
            $steps[3]['state'] = 'current';
            $steps[3]['note'] = fmt_date($res['move_in_date']);
            $left = max(0.0, round($fin['balance'], 2));
            $next = 'The bed is secured. At move-in (' . fmt_date($res['move_in_date']) . ') collect ' . money($left) . ' (deposit and the rest of the first month), then check the tenant in.';
        }
    } elseif ($st === 'checked_in') {
        foreach ([1, 2, 3] as $i) {
            $steps[$i]['state'] = 'done';
        }
        $steps[4]['state'] = 'current';
        $steps[3]['note'] = $res['checked_in_at'] ? fmt_date(substr((string)$res['checked_in_at'], 0, 10)) : '';
        $steps[4]['note'] = $fin['next_due'] ? 'next ' . fmt_date($fin['next_due']) : '';
        $next = $fin['balance'] > 0
            ? 'Collect the unpaid ' . money($fin['balance']) . '. Reminders go out automatically.'
            : ($fin['next_due'] ? 'Next rent of ' . money((float)$res['monthly_rate']) . ' is due ' . fmt_date($fin['next_due']) . '. Reminders go out automatically.' : 'The stay ends on the planned move-out date.');
        if (!empty($res['move_out_date'])) {
            $next .= ' Move-out planned for ' . fmt_date($res['move_out_date']) . '.';
        }
    } else { // completed
        foreach ([1, 2, 3, 4, 5] as $i) {
            $steps[$i]['state'] = 'done';
        }
        $steps[5]['note'] = $res['checked_out_at'] ? fmt_date(substr((string)$res['checked_out_at'], 0, 10)) : '';
        $next = $fin['refundable'] > 0 ? 'Return the deposit: ' . money($fin['refundable']) . ' is still waiting to be refunded.'
            : ($fin['balance'] > 0 ? 'The tenant left with an unpaid ' . money($fin['balance']) . '.' : 'Everything is settled.');
    }
    return ['steps' => $steps, 'next' => $next];
}

/* ------------------------------------------------------------- messages */

function lifecycle_message(string $type, array $c): array
{
    $res = $c['res'];
    $fin = $c['fin'];
    $site = setting('site_name', 'Pajuleras Boarding House');
    $first = first_name((string)$c['tenant']['full_name']);
    $room = 'Room ' . $res['room_no'];
    $ref = $res['reservation_no'];
    $sign = "\n\nThank you,\n" . (setting('landlord_name') ?: $site) . "\n" . $site . (setting('phone') !== '' ? "\n" . setting('phone') : '');
    $moveIn = fmt_date($res['move_in_date']);
    $atMoveIn = max(0.0, round(((float)$res['deposit_amount'] + (float)$res['monthly_rate']) - (float)$res['advance_amount'], 2));
    $x = $c['extra'] ?? [];

    switch ($type) {
        case 'reservation_held':
            $deadline = $res['hold_until'] ? fmt_datetime($res['hold_until']) : '';
            return ['subject' => "$room is held for you" . ($deadline ? " until $deadline" : ''),
                'body' => "Hi $first,\n\n$site is holding $room for you from $moveIn (reservation $ref).\n\n"
                    . 'To keep the bed, please pay the reservation amount of ' . money($fin['advance_needed']) . ($deadline ? " by $deadline" : '') . '. '
                    . "If it is not received in time, the hold ends automatically and the bed is offered to someone else.\n\n"
                    . how_to_pay_text($res)
                    . "\n\nThe reservation amount counts toward your first month. At move-in you pay the rest: the security deposit of " . money((float)$res['deposit_amount'])
                    . ' and the remaining ' . money(max(0.0, (float)$res['monthly_rate'] - (float)$res['advance_amount'])) . ' of the first month (about ' . money($atMoveIn) . ' in all).' . $sign];
        case 'reservation_secured':
            return ['subject' => "Your bed is secured ($ref)",
                'body' => "Hi $first,\n\nWe received your reservation payment. $room is now yours from $moveIn (reservation $ref).\n\n"
                    . 'At move-in please bring ' . money($atMoveIn) . ' (security deposit and the rest of the first month). We will check the room together before you move in.'
                    . "\n\nRent is then due on the same date every month. We send a reminder a few days before." . $sign];
        case 'hold_expired':
            return ['subject' => "Your room hold has ended ($ref)",
                'body' => "Hi $first,\n\nThe hold on $room (reservation $ref) ended because the reservation amount was not received in time, so the bed was released.\n\n"
                    . "If you still want a room, just send a new inquiry or message us. We will check what is open." . $sign];
        case 'moveout_notice':
            return ['subject' => 'We received your move-out notice',
                'body' => "Hi $first,\n\nWe received your notice. Your move-out date is " . fmt_date((string)$res['move_out_date']) . ' (' . $room . ', reservation ' . $ref . ").\n\n"
                    . 'Rent is billed until then' . ($fin['balance'] > 0 ? '. Please settle your balance of ' . money($fin['balance']) . ' before you leave.' : '.')
                    . "\n\nOn move-out day we check the room together. Your security deposit is returned after that, minus any unpaid rent or damages, and you will get a written summary." . $sign];
        case 'settlement':
            $lines = ['Deposit held: ' . money($x['deposit'] ?? 0)];
            if (($x['kept_rent'] ?? 0) > 0) $lines[] = 'Applied to unpaid rent: ' . money($x['kept_rent']);
            if (($x['kept_other'] ?? 0) > 0) $lines[] = 'Kept for damages or other costs: ' . money($x['kept_other']) . ($x['reason'] ? ' (' . $x['reason'] . ')' : '');
            $lines[] = ($x['refund'] ?? 0) > 0 ? 'Returned to you: ' . money($x['refund']) . ($x['refund_method'] ? ' by ' . $x['refund_method'] : '') : 'Nothing to return.';
            if (($x['balance_after'] ?? 0) > 0) $lines[] = 'Still unpaid: ' . money($x['balance_after']);
            return ['subject' => "Move-out summary for $room",
                'body' => "Hi $first,\n\nThank you for staying with us. Here is your move-out summary for $room (reservation $ref):\n\n- " . implode("\n- ", $lines)
                    . "\n\nYour receipts are on file. If anything looks wrong, please tell us." . $sign];
        case 'reservation_cancelled':
            $lines = ['Payments received: ' . money($x['held'] ?? 0)];
            $lines[] = ($x['refund'] ?? 0) > 0 ? 'Refunded to you: ' . money($x['refund']) . ($x['refund_method'] ? ' by ' . $x['refund_method'] : '') : 'No refund is due.';
            if (($x['kept'] ?? 0) > 0) $lines[] = 'Kept (reservation fee): ' . money($x['kept']);
            return ['subject' => "Reservation $ref was cancelled",
                'body' => "Hi $first,\n\nYour reservation $ref for $room was cancelled" . (!empty($x['reason']) ? ' (' . $x['reason'] . ')' : '') . ".\n\n- " . implode("\n- ", $lines) . $sign];
    }
    return ['subject' => $site, 'body' => ''];
}

/**
 * Tell the tenant about a step of their reservation (e-mail when set up, otherwise prepared for one tap in
 * Admin > Payment reminders). Never stops the action that triggered it.
 * @param bool $autoOnly true = only send when it can go out automatically, never add to the by-hand list
 */
function notify_reservation(int $resId, string $type, array $extra = [], bool $autoOnly = false): void
{
    try {
        $res = fetch_one("SELECT v.*, r.room_no FROM reservations v JOIN rooms r ON r.id = v.room_id WHERE v.id = ?", [$resId]);
        if (!$res) {
            return;
        }
        $tenant = fetch_one("SELECT id, full_name, contact_no, email, messenger FROM tenants WHERE id = ? AND anonymized_at IS NULL", [$res['tenant_id']]);
        if (!$tenant) {
            return;
        }
        $msg = lifecycle_message($type, ['res' => $res, 'fin' => reservation_financials($res), 'tenant' => $tenant, 'extra' => $extra]);
        if ($msg['body'] === '') {
            return;
        }
        $key = 'res' . $resId . ':' . $type . (isset($extra['key']) ? ':' . $extra['key'] : '');
        notify_tenant($tenant, $type, $msg, ['reservation_id' => $resId, 'dedupe' => $key, 'auto_only' => $autoOnly]);
    } catch (Throwable $e) {
        error_log('[PBH] notify_reservation failed: ' . $e->getMessage());
    }
}
