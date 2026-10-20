<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();

$types = [
    'occupancy'    => 'Room occupancy',
    'payments'     => 'Payments received',
    'reservations' => 'Reservations',
    'balances'     => 'Unpaid balances',
    'tenants'      => 'Tenant list',
];
$type = array_key_exists(get_str('type'), $types) ? get_str('type') : 'occupancy';
$from = valid_date(get_str('from', 10)) ? get_str('from', 10) : date('Y-m-01');
$to   = valid_date(get_str('to', 10)) ? get_str('to', 10) : date('Y-m-t');
if ($to < $from) { [$from, $to] = [$to, $from]; }
$status = in_array(get_str('status'), ['pending', 'confirmed', 'checked_in', 'completed', 'cancelled'], true) ? get_str('status') : '';

$header = []; $rows = []; $kinds = []; $summary = [];   // kinds: text|money|date|int

switch ($type) {
    case 'occupancy':
        $header = ['Room', 'Type', 'Who can stay', 'Beds', 'Occupied', 'Reserved', 'Open', 'Occupancy %', 'Rent per bed', 'Status'];
        $kinds  = ['text', 'text', 'text', 'int', 'int', 'int', 'int', 'text', 'money', 'text'];
        $tb = $to_ = $tr = $to2 = 0;
        foreach (rooms_with_status(false) as $r) {
            $pct = $r['status'] === 'active' && $r['capacity'] > 0 ? round($r['occupied_beds'] / $r['capacity'] * 100) . '%' : '—';
            $rows[] = [$r['room_no'], $r['room_type'] === 'private' ? 'Private' : 'Shared', gender_label($r['gender_policy']), (int)$r['capacity'],
                       $r['occupied_beds'], $r['reserved_beds'], $r['open_beds'], $pct, (float)$r['monthly_rate'], $r['status_label']];
            if ($r['status'] === 'active') { $tb += (int)$r['capacity']; $to_ += $r['occupied_beds']; $tr += $r['reserved_beds']; $to2 += $r['open_beds']; }
        }
        $summary = ['Beds in active rooms' => $tb, 'Occupied' => $to_, 'Reserved' => $tr, 'Open' => $to2,
                    'Occupancy rate' => $tb ? round($to_ / $tb * 100) . '%' : '—'];
        break;

    case 'payments':
        $header = ['Date', 'Receipt no.', 'Tenant', 'Reservation', 'Room', 'Type', 'Method', 'Reference', 'Amount'];
        $kinds  = ['date', 'text', 'text', 'text', 'text', 'text', 'text', 'text', 'money'];
        $data = fetch_all("SELECT p.*, t.full_name, v.reservation_no, r.room_no
                             FROM payments p JOIN tenants t ON t.id = p.tenant_id JOIN reservations v ON v.id = p.reservation_id JOIN rooms r ON r.id = v.room_id
                            WHERE p.is_void = 0 AND p.payment_date BETWEEN ? AND ? ORDER BY p.payment_date, p.id", [$from, $to]);
        $in = 0.0; $ref = 0.0; $byType = [];
        foreach ($data as $p) {
            $signed = $p['payment_type'] === 'refund' ? -(float)$p['amount'] : (float)$p['amount'];
            $rows[] = [$p['payment_date'], $p['receipt_no'], $p['full_name'], $p['reservation_no'], $p['room_no'], payment_type_label($p['payment_type']), method_label($p['method']), $p['reference_no'], $signed];
            if ($p['payment_type'] === 'refund') $ref += (float)$p['amount']; else $in += (float)$p['amount'];
            $byType[payment_type_label($p['payment_type'])] = ($byType[payment_type_label($p['payment_type'])] ?? 0) + $signed;
        }
        $summary = ['Payments received' => money($in), 'Refunds given' => money($ref), 'Net collected' => money($in - $ref)];
        foreach ($byType as $k => $v) $summary[$k] = money($v);
        break;

    case 'reservations':
        $header = ['Reservation', 'Tenant', 'Contact', 'Room', 'Beds', 'Move-in', 'Move-out', 'Status', 'Monthly rent', 'Paid', 'Balance'];
        $kinds  = ['text', 'text', 'text', 'text', 'int', 'date', 'date', 'text', 'money', 'money', 'money'];
        $sql = "SELECT v.*, t.full_name, t.contact_no, r.room_no FROM reservations v JOIN tenants t ON t.id = v.tenant_id JOIN rooms r ON r.id = v.room_id
                 WHERE v.move_in_date BETWEEN ? AND ?" . ($status ? " AND v.status = ?" : "") . " ORDER BY v.move_in_date, v.id";
        $data = fetch_all($sql, $status ? [$from, $to, $status] : [$from, $to]);
        $totals = payment_totals(array_column($data, 'id'));
        $counts = [];
        foreach ($data as $v) {
            $f = compute_financials($v, $totals[(int)$v['id']] ?? []);
            $rows[] = [$v['reservation_no'], $v['full_name'], $v['contact_no'], $v['room_no'], (int)$v['beds'], $v['move_in_date'], $v['move_out_date'],
                       ucfirst(str_replace('_', ' ', $v['status'])), (float)$v['monthly_rate'], $f['paid'], $f['balance']];
            $counts[$v['status']] = ($counts[$v['status']] ?? 0) + 1;
        }
        $summary = ['Reservations' => count($rows)];
        foreach ($counts as $k => $c) $summary[ucfirst(str_replace('_', ' ', $k))] = $c;
        break;

    case 'balances':
        $header = ['Tenant', 'Contact', 'Reservation', 'Room', 'Status', 'Total due', 'Total paid', 'Balance', 'Next rent due'];
        $kinds  = ['text', 'text', 'text', 'text', 'text', 'money', 'money', 'money', 'date'];
        $sum = 0.0;
        foreach (outstanding_balances() as $b) {
            $rows[] = [$b['full_name'], $b['contact_no'], $b['reservation_no'], $b['room_no'], ucfirst(str_replace('_', ' ', $b['status'])),
                       $b['fin']['due'], $b['fin']['paid'], $b['fin']['balance'], $b['fin']['next_due']];
            $sum += $b['fin']['balance'];
        }
        $summary = ['Tenants with a balance' => count($rows), 'Total unpaid' => money($sum), 'As of' => fmt_date(today())];
        break;

    case 'tenants':
        $header = ['Code', 'Name', 'Gender', 'Contact', 'Email', 'Occupation', 'School / workplace', 'Guardian', 'Guardian contact', 'Room now', 'Status'];
        $kinds  = array_fill(0, 11, 'text');
        $data = fetch_all("SELECT t.*, (SELECT GROUP_CONCAT(DISTINCT r.room_no SEPARATOR ', ') FROM reservations v JOIN rooms r ON r.id = v.room_id WHERE v.tenant_id = t.id AND v.status = 'checked_in') AS current_room
                             FROM tenants t ORDER BY t.full_name");
        foreach ($data as $t) {
            $rows[] = [$t['tenant_code'], $t['full_name'], ucfirst($t['gender']), $t['contact_no'], $t['email'], occupation_label($t['occupation']),
                       $t['school_or_workplace'], $t['guardian_name'], $t['guardian_contact'], $t['current_room'], ucfirst($t['status'])];
        }
        $summary = ['Tenants' => count($rows), 'Currently staying' => count(array_filter($rows, fn($r) => $r[9]))];
        break;
}

if (get_str('export') === 'csv') {
    $out = [];
    foreach ($rows as $r) {
        $line = [];
        foreach ($r as $k => $c) {
            $line[] = ($kinds[$k] === 'money' && $c !== null) ? number_format((float)$c, 2, '.', '') : $c;
        }
        $out[] = $line;
    }
    log_activity('report_exported', 'report', null, $types[$type] . ' exported to CSV');
    csv_download('pajuleras-' . $type . '-' . date('Ymd') . '.csv', $header, $out);
}

$usesDates = in_array($type, ['payments', 'reservations'], true);
admin_header('Reports', 'reports');
page_head('Reports', 'View, print, or download your records.');
?>
<section class="panel no-print">
  <div class="tabs" role="navigation" aria-label="Report type">
    <?php foreach ($types as $k => $label): ?>
      <a href="<?= e(url('admin/reports.php?type=' . $k)) ?>"<?= $type === $k ? ' class="active"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>
  <form class="filters" method="get">
    <input type="hidden" name="type" value="<?= e($type) ?>">
    <?php if ($usesDates): ?>
      <label><?= $type === 'reservations' ? 'Move-in from' : 'From' ?><input type="date" name="from" value="<?= e($from) ?>"></label>
      <label>To<input type="date" name="to" value="<?= e($to) ?>"></label>
    <?php endif; ?>
    <?php if ($type === 'reservations'): ?>
      <label>Status<select name="status"><option value="">All</option>
        <?php foreach (['pending', 'confirmed', 'checked_in', 'completed', 'cancelled'] as $s): ?><option value="<?= $s ?>"<?= selected($status, $s) ?>><?= e(ucfirst(str_replace('_', ' ', $s))) ?></option><?php endforeach; ?></select></label>
    <?php endif; ?>
    <?php if ($usesDates): ?><button class="btn" type="submit">Update</button><?php endif; ?>
    <a class="btn" href="<?= e(url('admin/reports.php?' . http_build_query(array_merge($_GET, ['type' => $type, 'export' => 'csv'])))) ?>">Download CSV</a>
    <button class="btn btn-primary" type="button" data-print>Print</button>
  </form>
</section>

<section class="panel report">
  <div class="report-head">
    <h2><?= e($types[$type]) ?></h2>
    <p class="muted"><?= e(setting('site_name')) ?><?= $usesDates ? ' &middot; ' . e(fmt_date($from)) . ' to ' . e(fmt_date($to)) : '' ?> &middot; Printed <?= e(date('M j, Y g:i A')) ?></p>
  </div>
  <?php if ($summary): ?>
  <dl class="summary"><?php foreach ($summary as $k => $v): ?><div><dt><?= e($k) ?></dt><dd><?= e($v) ?></dd></div><?php endforeach; ?></dl>
  <?php endif; ?>
  <?php if ($rows): ?>
  <div class="table-scroll"><table>
    <thead><tr><?php foreach ($header as $k => $h): ?><th<?= in_array($kinds[$k], ['money', 'int'], true) ? ' class="num"' : '' ?>><?= e($h) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr><?php foreach ($r as $k => $c): ?>
        <td<?= in_array($kinds[$k], ['money', 'int'], true) ? ' class="num"' : '' ?>><?php
            if ($c === null || $c === '') echo '—';
            elseif ($kinds[$k] === 'money') echo e(($c < 0 ? '-' : '') . money(abs($c)));
            elseif ($kinds[$k] === 'date') echo e(fmt_date($c));
            else echo e($c);
        ?></td>
      <?php endforeach; ?></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php else: ?><p class="empty">Nothing to show for these filters.</p><?php endif; ?>
</section>
<?php admin_footer();
