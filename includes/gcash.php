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
