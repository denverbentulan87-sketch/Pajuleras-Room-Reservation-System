<?php
declare(strict_types=1);

/**
 * Billing rules (kept simple and transparent for a boarding house):
 *
 *  - Rent is billed monthly IN ADVANCE.  Each started month is one
 *    "cycle".  The first cycle starts on the check-in date (or, before
 *    check-in, on the planned move-in date).
 *  - Amount due  = security deposit + monthly rent x cycles started.
 *  - Amount paid = advance payments + deposit payments + rent payments
 *                  (voided payments and refunds are not counted).
 *  - Balance     = due - paid.  A negative balance is a credit
 *                  (the tenant paid ahead).
 *  - Refunds (e.g. returning the deposit) are tracked separately and do
 *    not create a balance.
 */

function add_months_clamped(string $date, int $months): string
{
    $d = new DateTimeImmutable($date);
    $total = ((int)$d->format('Y')) * 12 + ((int)$d->format('n') - 1) + $months;
    $y = intdiv($total, 12);
    $m = $total % 12 + 1;
    $last = (int)(new DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m)))->format('t');
    return sprintf('%04d-%02d-%02d', $y, $m, min((int)$d->format('j'), $last));
}

function billing_anchor(array $res): string
{
    if (in_array($res['status'], ['checked_in', 'completed'], true) && !empty($res['checked_in_at'])) {
        return substr((string)$res['checked_in_at'], 0, 10);
    }
    return (string)$res['move_in_date'];
}

/** Number of monthly rent cycles that have started. */
function billing_cycles(array $res, ?string $asOf = null): int
{
    $asOf = $asOf ?: today();
    switch ($res['status']) {
        case 'cancelled':
            return 0;
        case 'pending':
        case 'confirmed':
            return 1;
    }
    $anchor = billing_anchor($res);
    $end = $asOf;
    if ($res['status'] === 'completed') {
        $end = !empty($res['checked_out_at']) ? substr((string)$res['checked_out_at'], 0, 10) : ($res['move_out_date'] ?: $asOf);
    }
    $moveOut = $res['move_out_date'] ?: null;
    $n = 1; // the first cycle always starts at check-in
    for ($k = 1; $k < 600; $k++) {
        $start = add_months_clamped($anchor, $k);
        if ($start > $end) {
            break;
        }
        if ($res['status'] === 'checked_in' && $moveOut && $start >= $moveOut) {
            break;                              // planned stay already ends before this cycle
        }
        $n++;
    }
    return $n;
}

/** Sum of non-void payments per type for the given reservation ids. */
function payment_totals(array $ids): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $rows = fetch_all("SELECT reservation_id, payment_type, SUM(amount) AS total
                         FROM payments
                        WHERE is_void = 0 AND reservation_id IN ($in)
                        GROUP BY reservation_id, payment_type", $ids);
    $out = [];
    foreach ($rows as $r) {
        $out[(int)$r['reservation_id']][$r['payment_type']] = (float)$r['total'];
    }
    return $out;
}

/** @param array<string,float> $t payment totals by type for this reservation */
function compute_financials(array $res, array $t = []): array
{
    $advance = (float)($t['advance'] ?? 0);
    $deposit = (float)($t['deposit'] ?? 0);
    $rent    = (float)($t['rent'] ?? 0);
    $other   = (float)($t['other'] ?? 0);
    $refund  = (float)($t['refund'] ?? 0);

    $cycles = billing_cycles($res);
    $cancelled = $res['status'] === 'cancelled';

    $rentDue = $cancelled ? 0.0 : round((float)$res['monthly_rate'] * $cycles, 2);
    $depDue  = $cancelled ? 0.0 : round((float)$res['deposit_amount'], 2);
    $due     = $rentDue + $depDue;
    $paid    = round($advance + $deposit + $rent, 2);
    $balance = $cancelled ? 0.0 : round($due - $paid, 2);

    $nextDue = null;
    if ($res['status'] === 'checked_in') {
        $cand = add_months_clamped(billing_anchor($res), $cycles);
        if (empty($res['move_out_date']) || $cand < $res['move_out_date']) {
            $nextDue = $cand;
        }
    }

    $advanceNeeded = 0.0;
    if (in_array($res['status'], ['pending', 'confirmed'], true)) {
        $advanceNeeded = max(0.0, round((float)$res['advance_amount'] - $paid, 2));
    }

    // What can still be handed back: everything for a cancelled reservation,
    // otherwise only the deposit and any rent paid ahead (never earned rent).
    $refundable = $cancelled
        ? max(0.0, round($paid + $other - $refund, 2))
        : max(0.0, round($deposit + ($balance < 0 ? abs($balance) : 0.0) - $refund, 2));

    return [
        'cycles'         => $cycles,
        'rent_due'       => $rentDue,
        'deposit_due'    => $depDue,
        'due'            => $due,
        'paid'           => $paid,
        'paid_advance'   => $advance,
        'paid_deposit'   => $deposit,
        'paid_rent'      => $rent,
        'paid_other'     => $other,
        'refunded'       => $refund,
        'held'           => round($paid + $other - $refund, 2),
        'refundable'     => $refundable,
        'balance'        => max(0.0, $balance),
        'credit'         => $balance < 0 ? abs($balance) : 0.0,
        'advance_needed' => $advanceNeeded,
        'next_due'       => $nextDue,
    ];
}

function reservation_financials(array $res): array
{
    $t = payment_totals([(int)$res['id']]);
    return compute_financials($res, $t[(int)$res['id']] ?? []);
}

/**
 * Tenants who are currently staying (checked in) and still owe money,
 * with tenant and room names, largest balance first.
 */
function outstanding_balances(): array
{
    $rows = fetch_all("SELECT v.*, t.full_name, t.contact_no, r.room_no
                         FROM reservations v
                         JOIN tenants t ON t.id = v.tenant_id
                         JOIN rooms r ON r.id = v.room_id
                        WHERE v.status = 'checked_in'");
    $totals = payment_totals(array_column($rows, 'id'));
    $out = [];
    foreach ($rows as $v) {
        $f = compute_financials($v, $totals[(int)$v['id']] ?? []);
        if ($f['balance'] > 0) {
            $out[] = $v + ['fin' => $f];
        }
    }
    usort($out, fn($a, $b) => $b['fin']['balance'] <=> $a['fin']['balance']);
    return $out;
}

/** Next rent due dates in the coming $days days for checked-in tenants. */
function upcoming_rent_due(int $days = 7): array
{
    $rows = fetch_all("SELECT v.*, t.full_name, t.contact_no, r.room_no
                         FROM reservations v
                         JOIN tenants t ON t.id = v.tenant_id
                         JOIN rooms r ON r.id = v.room_id
                        WHERE v.status = 'checked_in'");
    $totals = payment_totals(array_column($rows, 'id'));
    $limit = date('Y-m-d', strtotime("+$days days"));
    $out = [];
    foreach ($rows as $v) {
        $f = compute_financials($v, $totals[(int)$v['id']] ?? []);
        if ($f['next_due'] && $f['next_due'] <= $limit && $f['next_due'] >= today()) {
            $out[] = $v + ['fin' => $f];
        }
    }
    usort($out, fn($a, $b) => strcmp($a['fin']['next_due'], $b['fin']['next_due']));
    return $out;
}
