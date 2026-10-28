<?php
declare(strict_types=1);

/**
 * Online GCash payments.
 * A tenant sends money to the landlord's GCash number, then reports it here
 * (reservation number + contact number prove who they are). Nothing counts
 * toward a balance until the landlord checks it and approves it in
 * Admin > GCash payments, which creates the normal payment and receipt.
 */

const GCASH_MAX_AMOUNT = 50000;

function gcash_pending_count(): int
{
    try {
        return (int)fetch_val("SELECT COUNT(*) FROM payment_submissions WHERE status = 'pending'");
    } catch (Throwable $e) {
        return 0;   // table not created yet (older install)
    }
}

function new_submission_code(): string
{
    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    for ($try = 0; $try < 20; $try++) {
        $c = 'GC-';
        for ($i = 0; $i < 6; $i++) {
            $c .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        if (!fetch_val("SELECT 1 FROM payment_submissions WHERE submission_code = ?", [$c])) {
            return $c;
        }
    }
    throw new RuntimeException('Could not create a code.');
}

/**
 * Validate and store a GCash payment report.
 * @return array{ok:bool, errors:string[], code?:string, amount?:string}
 */
function gcash_submit(array $in, array $file): array
{
    $fail = fn(string ...$m) => ['ok' => false, 'errors' => array_values($m)];

    if (throttle_count('gcash_fail', 15) >= 8) {
        return $fail('Too many unsuccessful tries. Please wait 15 minutes, or contact the landlord directly.');
    }
    if (throttle_count('gcash_sub', 60) >= 5) {
        return $fail('You have sent several payment reports in the last hour. Please wait a while, or contact the landlord.');
    }

    $resNo   = strtoupper(trim((string)($in['reservation_no'] ?? '')));
    $contact = normalize_phone((string)($in['contact_no'] ?? ''));
    $type    = (string)($in['payment_type'] ?? '');
    $amount  = parse_money((string)($in['amount'] ?? ''));
    $date    = (string)($in['paid_date'] ?? '');
    $ref     = preg_replace('/\s+/', '', (string)($in['gcash_ref'] ?? '')) ?? '';
    $sender  = trim(mb_substr((string)($in['sender_name'] ?? ''), 0, 120));

    $errors = [];
    if ($resNo === '') $errors[] = 'Enter your reservation number (it is on your reservation slip and receipt, for example R2026-0001).';
    if (!valid_phone($contact)) $errors[] = 'Enter the contact number you gave the landlord (digits only, for example 09123456789).';
    if (!in_array($type, ['advance', 'deposit', 'rent', 'other'], true)) $errors[] = 'Choose what the payment is for.';
    if ($amount === null || $amount <= 0) $errors[] = 'Enter the amount you sent, for example 700 or 1,500.50.';
    elseif ($amount > GCASH_MAX_AMOUNT) $errors[] = 'That amount is too large to report online. Please contact the landlord.';
    if (!valid_date($date) || $date > today() || $date < date('Y-m-d', strtotime(today() . ' -60 days'))) {
        $errors[] = 'Enter the date you paid (within the last 60 days, not in the future).';
    }
    if (!preg_match('/^[A-Za-z0-9]{8,30}$/', $ref)) $errors[] = 'Enter the GCash reference number shown on your receipt (8 to 30 letters or numbers, no spaces).';
    if ($errors) {
        return ['ok' => false, 'errors' => $errors];
    }

    $res = fetch_one("SELECT v.*, t.contact_no AS t_contact FROM reservations v JOIN tenants t ON t.id = v.tenant_id
                       WHERE v.reservation_no = ? AND v.status IN ('pending','confirmed','checked_in')", [$resNo]);
    if (!$res || normalize_phone((string)$res['t_contact']) !== $contact) {
        throttle_hit('gcash_fail');
        return $fail('We could not find an open reservation with that number and contact number. Please check them and try again.');
    }

    $dupe = fetch_val("SELECT 1 FROM payments WHERE reference_no = ? AND is_void = 0 LIMIT 1", [$ref])
         ?: fetch_val("SELECT 1 FROM payment_submissions WHERE gcash_ref = ? AND status <> 'rejected' LIMIT 1", [$ref]);
    if ($dupe) {
        return $fail('That GCash reference number was already reported. If you think this is a mistake, please contact the landlord.');
    }

    $proof = null;
    try {
        $proof = store_room_photo($file, 'gcash');
    } catch (RuleException $ex) {
        return $fail($ex->getMessage());
    }

    try {
        $code = new_submission_code();
        q("INSERT INTO payment_submissions (submission_code, reservation_id, tenant_id, payment_type, amount, paid_date, gcash_ref, sender_name, proof_file, status, ip)
           VALUES (?,?,?,?,?,?,?,?,?, 'pending', ?)",
          [$code, $res['id'], $res['tenant_id'], $type, $amount, $date, $ref, $sender !== '' ? $sender : null, $proof, client_ip()]);
    } catch (Throwable $e) {
        delete_gcash_proof($proof);
        error_log('[PBH] gcash submit failed: ' . $e->getMessage());
        return $fail('Sorry, your report could not be saved. Please try again in a moment, or contact the landlord.');
    }
    throttle_hit('gcash_sub');
    log_activity('gcash_reported', 'reservation', (int)$res['id'], $code . ': ' . payment_type_label($type) . ' ' . money($amount) . ' (ref ' . $ref . ')');
    return ['ok' => true, 'errors' => [], 'code' => $code, 'amount' => money($amount)];
}

/** Create the real payment row + receipt number (inside the caller's transaction). */
function create_gcash_payment(array $res, string $type, float $amount, string $date, string $ref, string $remarks): int
{
    q("INSERT INTO payments (reservation_id, tenant_id, payment_type, amount, payment_date, method, reference_no, remarks) VALUES (?,?,?,?,?, 'gcash', ?, ?)",
      [$res['id'], $res['tenant_id'], $type, $amount, $date, $ref, $remarks]);
    $nid = (int)db()->lastInsertId();
    q("UPDATE payments SET receipt_no = ? WHERE id = ?", ['OR-' . str_pad((string)$nid, 6, '0', STR_PAD_LEFT), $nid]);
    return $nid;
}

/**
 * A tenant checks what happened to a GCash report. Needs the report code AND the
 * contact number on file, so nobody can read someone else's payment.
 * @return array{ok:bool, errors?:string[], status?:string, title?:string, text?:string}
 */
function gcash_status_lookup(string $code, string $contact): array
{
    if (throttle_count('gcash_fail', 15) >= 8) {
        return ['ok' => false, 'errors' => ['Too many unsuccessful tries. Please wait 15 minutes, or contact the landlord directly.']];
    }
    $code = strtoupper(trim($code));
    $phone = normalize_phone($contact);
    $row = $code !== '' && valid_phone($phone) ? fetch_one("SELECT s.*, t.contact_no, p.receipt_no FROM payment_submissions s
                                                            JOIN tenants t ON t.id = s.tenant_id LEFT JOIN payments p ON p.id = s.payment_id
                                                           WHERE s.submission_code = ?", [$code]) : null;
    if (!$row || normalize_phone((string)$row['contact_no']) !== $phone) {
        throttle_hit('gcash_fail');
        return ['ok' => false, 'errors' => ['We could not find a report with that code and contact number. Please check them and try again.']];
    }
    $sent = money($row['amount']) . ' on ' . fmt_date($row['paid_date']) . ' (GCash reference ' . $row['gcash_ref'] . ')';
    if ($row['status'] === 'approved') {
        return ['ok' => true, 'status' => 'approved', 'title' => 'Confirmed and recorded',
                'text' => 'The landlord confirmed your GCash payment of ' . $sent . '. Official receipt no. ' . ($row['receipt_no'] ?: 'on file') . '.'];
    }
    if ($row['status'] === 'rejected') {
        return ['ok' => true, 'status' => 'rejected', 'title' => 'Not confirmed',
                'text' => 'The landlord could not confirm your report of ' . $sent . '. Reason: ' . ($row['reject_reason'] ?: 'not stated') . '. No payment was recorded. Please contact the landlord' . (setting('phone') !== '' ? ' at ' . setting('phone') : '') . ' or send the report again with the correct details.'];
    }
    return ['ok' => true, 'status' => 'pending', 'title' => 'Waiting for the landlord to check it',
            'text' => 'Your report of ' . $sent . ' was received on ' . fmt_datetime($row['created_at']) . '. It is not counted yet. You will get a message when it is confirmed.'];
}


/**
 * Tenant types a reservation number and contact number; we show what they owe so they pick it instead of typing
 * amounts (fewer mistakes, fewer rejected reports). Same checks and the same rate limit as sending a report.
 * @return array{ok:bool, errors?:string[], summary?:string, items?:array}
 */
function gcash_lookup(string $resNo, string $contact): array
{
    if (throttle_count('gcash_fail', 15) >= 8) {
        return ['ok' => false, 'errors' => ['Too many unsuccessful tries. Please wait 15 minutes, or contact the landlord directly.']];
    }
    $resNo = strtoupper(trim($resNo));
    $phone = normalize_phone($contact);
    $res = ($resNo !== '' && valid_phone($phone)) ? fetch_one("SELECT v.*, t.full_name, t.contact_no AS t_contact, r.room_no FROM reservations v
                JOIN tenants t ON t.id = v.tenant_id JOIN rooms r ON r.id = v.room_id
               WHERE v.reservation_no = ? AND v.status IN ('pending','confirmed','checked_in')", [$resNo]) : null;
    if (!$res || normalize_phone((string)$res['t_contact']) !== $phone) {
        throttle_hit('gcash_fail');
        return ['ok' => false, 'errors' => ['We could not find an open reservation with that number and contact number. Please check them and try again.']];
    }
    $fin = reservation_financials($res);
    $items = [];
    $pendingReport = (float)fetch_val("SELECT COALESCE(SUM(amount),0) FROM payment_submissions WHERE reservation_id = ? AND status = 'pending'", [$res['id']]);
    if ($fin['advance_needed'] > 0) {
        $items[] = ['type' => 'advance', 'label' => $res['status'] === 'checked_in' ? 'Advance payment' : 'Reservation amount (holds your bed)', 'amount' => (float)$fin['advance_needed'],
                    'note' => ($res['status'] === 'confirmed' && $res['hold_until']) ? 'Pay by ' . fmt_datetime($res['hold_until']) . ' or the hold ends' : ''];
    }
    foreach (payment_schedule($res, $fin) as $it) {
        if ($it['state'] === 'paid' || $it['when'] === 'future' && $res['status'] !== 'checked_in') {
            continue;
        }
        $left = round($it['amount'] - $it['paid'], 2);
        if ($left <= 0) {
            continue;
        }
        $when = $it['when'] === 'past' ? 'was due ' . fmt_date($it['due']) . ' (late)' : ($it['when'] === 'today' ? 'due today' : 'due ' . fmt_date($it['due']));
        $items[] = ['type' => $it['kind'] === 'deposit' ? 'deposit' : 'rent', 'label' => $it['label'], 'amount' => $left, 'note' => $when];
        if (count($items) >= 4) {
            break;
        }
    }
    $summary = $res['full_name'] . ', Room ' . $res['room_no'] . ' (' . $res['reservation_no'] . ')'
        . ($fin['balance'] > 0 ? '. Total unpaid: ' . money($fin['balance']) : '. Nothing is overdue.')
        . ($pendingReport > 0 ? '. You already sent a report of ' . money($pendingReport) . ' that is waiting to be checked.' : '');
    return ['ok' => true, 'summary' => $summary, 'items' => $items, 'pending_report' => $pendingReport];
}
