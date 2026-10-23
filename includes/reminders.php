<?php
declare(strict_types=1);

/**
 * Rent due dates and payment reminders.
 *
 * Rent is billed monthly in advance, so a tenant who moved in on the 5th
 * pays again on the 5th of every month (see includes/finance.php). From
 * those dates this file works out, for every tenant:
 *   - the payment schedule (what is due on which date, and whether it is paid),
 *   - whether a payment is coming up, due today, missing, or only partly paid,
 *   - which reminder to send, once, without ever repeating the same one.
 */

function days_between(string $from, string $to): int
{
    return (int)round((strtotime($to . ' 00:00:00 UTC') - strtotime($from . ' 00:00:00 UTC')) / 86400);
}

function reminder_settings(): array
{
    return [
        'enabled' => setting('reminders_enabled', '1') === '1',
        'before'  => max(0, min(14, (int)setting('reminder_days_before', '3'))),
        'repeat'  => max(1, min(30, (int)setting('reminder_repeat_days', '3'))),
        'max'     => max(1, min(20, (int)setting('reminder_max', '6'))),
    ];
}

/**
 * What is due and when. Paid money is applied to the oldest item first
 * (deposit, then month 1, month 2, ...), the same way the balance is computed.
 * @return array<int,array{kind:string,label:string,due:string,amount:float,paid:float,state:string,when:string}>
 */
function payment_schedule(array $res, array $fin): array
{
    if ($res['status'] === 'cancelled') {
        return [];
    }
    $anchor = billing_anchor($res);
    $items = [];
    if ((float)$res['deposit_amount'] > 0) {
        $items[] = ['kind' => 'deposit', 'label' => 'Security deposit', 'due' => $anchor, 'amount' => (float)$res['deposit_amount']];
    }
    $cycles = (int)$fin['cycles'];
    for ($i = 0; $i < $cycles; $i++) {
        $items[] = ['kind' => 'rent', 'label' => 'Rent, month ' . ($i + 1), 'due' => add_months_clamped($anchor, $i), 'amount' => (float)$res['monthly_rate']];
    }
    if (!empty($fin['next_due'])) {
        $items[] = ['kind' => 'rent', 'label' => 'Rent, month ' . ($cycles + 1), 'due' => (string)$fin['next_due'], 'amount' => (float)$res['monthly_rate']];
    }
    $pool = (float)$fin['paid'];
    $today = today();
    foreach ($items as &$it) {
        $take = min($pool, $it['amount']);
        $pool = round($pool - $take, 2);
        $it['paid'] = round($take, 2);
        $it['state'] = $it['paid'] >= $it['amount'] - 0.005 ? 'paid' : ($it['paid'] > 0 ? 'partial' : 'unpaid');
        $it['when'] = $it['due'] < $today ? 'past' : ($it['due'] === $today ? 'today' : 'future');
    }
    unset($it);
    return $items;
}

/**
 * Where does this reservation stand today?
 * @return array|null null when nothing needs attention
 */
function reminder_state(array $res, array $fin, array $cfg): ?array
{
    $today = today();
    $out = ['state' => 'ok', 'due' => null, 'days' => 0, 'amount' => 0.0, 'paid_on_item' => 0.0, 'label' => ''];

    if (in_array($res['status'], ['pending', 'confirmed'], true)) {
        if ($fin['advance_needed'] > 0) {
            $ref = substr((string)($res['confirmed_at'] ?: $res['created_at']), 0, 10);
            return ['state' => 'advance', 'due' => $res['move_in_date'], 'days' => max(0, days_between($ref, $today)), 'amount' => (float)$fin['advance_needed'],
                    'paid_on_item' => (float)$fin['paid'], 'label' => 'Advance payment', 'ref' => $ref];
        }
        return null;
    }
    if ($res['status'] !== 'checked_in') {
        return null;
    }
    foreach (payment_schedule($res, $fin) as $it) {
        if ($it['state'] === 'paid') {
            continue;
        }
        $left = round($it['amount'] - $it['paid'], 2);
        if ($it['when'] === 'past') {
            return ['state' => $it['paid'] > 0 ? 'balance' : 'overdue', 'due' => $it['due'], 'days' => days_between($it['due'], $today),
                    'amount' => $left, 'paid_on_item' => $it['paid'], 'label' => $it['label']];
        }
        if ($it['when'] === 'today') {
            return ['state' => 'due_today', 'due' => $it['due'], 'days' => 0, 'amount' => $left, 'paid_on_item' => $it['paid'], 'label' => $it['label']];
        }
        $until = days_between($today, $it['due']);
        if ($until <= $cfg['before']) {
            return ['state' => 'upcoming', 'due' => $it['due'], 'days' => -$until, 'amount' => $left, 'paid_on_item' => $it['paid'], 'label' => $it['label']];
        }
        return ['state' => 'ok', 'due' => $it['due'], 'days' => -$until, 'amount' => $left, 'paid_on_item' => $it['paid'], 'label' => $it['label']];
    }
    return $out;
}

/** The (once only) key of the reminder that is due now, or null. */
function reminder_key(array $res, array $st, array $cfg): ?string
{
    $id = (int)$res['id'];
    switch ($st['state']) {
        case 'overdue':
        case 'balance':
            $n = intdiv(max(1, $st['days']) - 1, $cfg['repeat']);
            return $n < $cfg['max'] ? "res{$id}:over:{$st['due']}:{$n}" : null;
        case 'due_today':
            return "res{$id}:today:{$st['due']}";
        case 'upcoming':
            return "res{$id}:pre:{$st['due']}";
        case 'advance':
            if ($st['days'] < 1) {
                return null;
            }
            $n = intdiv($st['days'] - 1, $cfg['repeat']);
            return $n < $cfg['max'] ? "res{$id}:adv:{$n}" : null;
    }
    return null;
}

/** Everyone with a reservation that may need a reminder, with their current state. */
function reminder_overview(): array
{
    $cfg = reminder_settings();
    $rows = fetch_all("SELECT v.*, t.full_name, t.contact_no, t.email, t.messenger, t.id AS t_id, r.room_no
                         FROM reservations v
                         JOIN tenants t ON t.id = v.tenant_id
                         JOIN rooms r ON r.id = v.room_id
                        WHERE v.status IN ('checked_in','confirmed','pending') AND t.anonymized_at IS NULL");
    $totals = payment_totals(array_column($rows, 'id'));
    $out = [];
    foreach ($rows as $v) {
        $fin = compute_financials($v, $totals[(int)$v['id']] ?? []);
        $st = reminder_state($v, $fin, $cfg);
        if ($st === null || $st['state'] === 'ok') {
            continue;
        }
        $out[] = ['res' => $v, 'fin' => $fin, 'st' => $st, 'key' => reminder_key($v, $st, $cfg),
                  'tenant' => ['id' => $v['t_id'], 'full_name' => $v['full_name'], 'contact_no' => $v['contact_no'], 'email' => $v['email'], 'messenger' => $v['messenger']]];
    }
    $rank = ['overdue' => 0, 'balance' => 1, 'due_today' => 2, 'advance' => 3, 'upcoming' => 4];
    usort($out, fn($a, $b) => [$rank[$a['st']['state']] ?? 9, -$a['st']['days']] <=> [$rank[$b['st']['state']] ?? 9, -$b['st']['days']]);
    return $out;
}

function state_label(string $s): string
{
    return ['overdue' => 'Payment missing', 'balance' => 'Balance left', 'due_today' => 'Due today', 'upcoming' => 'Coming up', 'advance' => 'Advance not paid'][$s] ?? $s;
}

/* ------------------------------------------------------------ Messages */

function first_name(string $full): string
{
    $full = trim($full);
    $parts = preg_split('/\s+/', $full) ?: [];
    return $parts[0] ?? $full;
}

function how_to_pay_text(array $res): string
{
    $lines = ['How to pay:', '- Cash: give it to ' . (setting('landlord_name') ?: 'the landlord') . '.'];
    $g = setting('gcash_no');
    if ($g !== '') {
        $base = public_base_url();
        $lines[] = '- GCash: send it to ' . $g . ($base !== '' ? ', then report it at ' . $base . '/gcash_pay.php' : ', then report it on our website') . ' (reservation no. ' . $res['reservation_no'] . ').';
    }
    return implode("\n", $lines);
}

/** @return array{subject:string, body:string, sms:string} */
function reminder_message(array $c): array
{
    $res = $c['res'];
    $st = $c['st'];
    $site = setting('site_name', 'Pajuleras Boarding House');
    $first = first_name((string)$c['tenant']['full_name']);
    $amt = money($st['amount']);
    $due = $st['due'] ? fmt_date($st['due']) : '';
    $room = 'Room ' . $res['room_no'];
    $ref = $res['reservation_no'];
    $g = setting('gcash_no');
    $short = ($g !== '' ? 'Pay cash or GCash ' . $g . '.' : 'Pay cash to the landlord.') . ' Ref ' . $ref;
    $sign = "\nThank you,\n" . (setting('landlord_name') ?: $site) . "\n" . $site . (setting('phone') !== '' ? "\n" . setting('phone') : '');
    $ignore = "\n\nIf you already paid, thank you. Please ignore this message or tell us so we can update your record.";
    $how = "\n\n" . how_to_pay_text($res);

    switch ($st['state']) {
        case 'upcoming':
            $n = -$st['days'];
            return [
                'subject' => "Rent reminder: $amt due on $due",
                'body' => "Hi $first,\n\nThis is a friendly reminder from $site. Your next rent of $amt for $room is due on $due" . ($n === 1 ? ' (tomorrow)' : " (in $n days)") . ".$how$ignore\n$sign",
                'sms' => "$site: rent of $amt for $room is due $due. $short",
            ];
        case 'due_today':
            return [
                'subject' => "Rent due today: $amt",
                'body' => "Hi $first,\n\nYour rent of $amt for $room is due today ($due).$how$ignore\n$sign",
                'sms' => "$site: your rent of $amt for $room is due today. $short",
            ];
        case 'balance':
            return [
                'subject' => "Payment balance: $amt still unpaid",
                'body' => "Hi $first,\n\nWe received " . money($st['paid_on_item']) . " for the payment that was due on $due ({$st['label']}, $room), but $amt is still unpaid ($st[days] " . ($st['days'] === 1 ? 'day' : 'days') . " late). Total balance on your account: " . money($c['fin']['balance']) . ".$how$ignore\n$sign",
                'sms' => "$site: balance of $amt is unpaid for $room (was due $due). Total balance " . money($c['fin']['balance']) . ". $short",
            ];
        case 'advance':
            return [
                'subject' => "Advance payment needed to hold your room",
                'body' => "Hi $first,\n\nYour reservation $ref for $room (move-in " . fmt_date($res['move_in_date']) . ") is waiting for the advance payment of $amt. Until it is paid, the bed is not guaranteed.$how$ignore\n$sign",
                'sms' => "$site: advance of $amt is needed to hold $room from " . fmt_date($res['move_in_date']) . ". $short",
            ];
        default: // overdue
            return [
                'subject' => "Missing payment: $amt was due on $due",
                'body' => "Hi $first,\n\nWe have not received your payment of $amt for $room, which was due on $due ($st[days] " . ($st['days'] === 1 ? 'day' : 'days') . " ago). Total balance on your account: " . money($c['fin']['balance']) . ".\n\nPlease pay as soon as you can. If you have a problem paying, talk to us so we can help.$how$ignore\n$sign",
                'sms' => "$site: payment of $amt for $room was due $due and is missing. Total balance " . money($c['fin']['balance']) . ". $short",
            ];
    }
}

/* -------------------------------------------------------------- Running */

/** Take the right to run now (only one run at a time, never more often than $gapSeconds). */
function claim_reminder_run(int $gapSeconds): bool
{
    q("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('reminders_last_run', '0')");
    $now = time();
    $st = q("UPDATE settings SET setting_value = ? WHERE setting_key = 'reminders_last_run' AND CAST(setting_value AS UNSIGNED) <= ?", [(string)$now, $now - $gapSeconds]);
    return $st->rowCount() === 1;
}

/**
 * Check everyone and send the reminders that are due.
 * @return array{ok:bool, message:string, results:array, counts:array}
 */
function run_reminders(bool $onlyIfDue = false, ?int $onlyReservation = null, bool $force = false): array
{
    $cfg = reminder_settings();
    if (!$cfg['enabled'] && !$force) {
        return ['ok' => false, 'message' => 'Reminders are switched off in Settings.', 'results' => [], 'counts' => []];
    }
    $results = [];
    $counts = ['sent' => 0, 'manual' => 0, 'failed' => 0, 'skipped' => 0];
    foreach (reminder_overview() as $c) {
        if ($onlyReservation !== null && (int)$c['res']['id'] !== $onlyReservation) {
            continue;
        }
        if ($c['key'] === null) {
            continue;
        }
        $r = notify_tenant($c['tenant'], $c['st']['state'], reminder_message($c), [
            'reservation_id' => (int)$c['res']['id'], 'dedupe' => $c['key'], 'force' => $force && $onlyReservation !== null,
        ]);
        $counts[$r['status']] = ($counts[$r['status']] ?? 0) + 1;
        if ($r['status'] !== 'skipped') {
            $results[] = ['tenant' => $c['tenant']['full_name'], 'reservation' => $c['res']['reservation_no'], 'state' => $c['st']['state']] + $r;
        }
    }
    q("INSERT INTO settings (setting_key, setting_value) VALUES ('reminders_last_run', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", [(string)time()]);
    $msg = ($counts['sent'] + $counts['manual'] + $counts['failed']) === 0 ? 'Checked everyone: no new reminders were needed.'
         : 'Checked everyone: ' . $counts['sent'] . ' sent, ' . $counts['manual'] . ' ready to send by hand, ' . $counts['failed'] . ' failed.';
    return ['ok' => true, 'message' => $msg, 'results' => $results, 'counts' => $counts];
}

function cron_token(): string
{
    $t = setting('cron_token');
    if (!preg_match('/^[a-f0-9]{32}$/', $t)) {
        $t = bin2hex(random_bytes(16));
        q("INSERT INTO settings (setting_key, setting_value) VALUES ('cron_token', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", [$t]);
    }
    return $t;
}

/**
 * "Cron without cron": when a visitor opens the home page and the reminders have not been
 * checked for 6 hours, quietly ask cron_run.php to do it in the background.
 * (A real scheduled task is still best, see the README.)
 */
function trigger_reminders_async(): void
{
    try {
        if (!reminder_settings()['enabled'] || (time() - (int)setting('reminders_last_run', '0')) < 6 * 3600) {
            return;
        }
        $base = public_base_url();
        $p = $base !== '' ? parse_url($base) : false;
        if (!$p || empty($p['host'])) {
            return;
        }
        $ssl = ($p['scheme'] ?? 'http') === 'https';
        $fp = @fsockopen(($ssl ? 'ssl://' : '') . $p['host'], (int)($p['port'] ?? ($ssl ? 443 : 80)), $en, $es, 1.0);
        if ($fp) {
            $path = rtrim((string)($p['path'] ?? ''), '/') . '/cron_run.php?token=' . cron_token();
            @fwrite($fp, "GET $path HTTP/1.1\r\nHost: {$p['host']}" . (isset($p['port']) ? ':' . $p['port'] : '') . "\r\nConnection: Close\r\n\r\n");
            @fclose($fp);
        }
    } catch (Throwable $e) {
        // never break a page because of this
    }
}
