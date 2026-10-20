<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();

$statuses = ['pending', 'confirmed', 'checked_in', 'completed', 'cancelled'];
$status = in_array(get_str('status'), $statuses, true) ? get_str('status') : '';
$roomId = get_int('room');
$search = get_str('q', 80);
$from = valid_date(get_str('from', 10)) ? get_str('from', 10) : '';
$to = valid_date(get_str('to', 10)) ? get_str('to', 10) : '';

$where = [];
$params = [];
if ($status !== '') { $where[] = "v.status = ?"; $params[] = $status; }
if ($roomId) { $where[] = "v.room_id = ?"; $params[] = $roomId; }
if ($search !== '') {
    $where[] = "(t.full_name LIKE ? OR v.reservation_no LIKE ? OR t.contact_no LIKE ?)";
    array_push($params, like_escape($search), like_escape($search), like_escape(normalize_phone($search) ?: $search));
}
if ($from) { $where[] = "v.move_in_date >= ?"; $params[] = $from; }
if ($to)   { $where[] = "v.move_in_date <= ?"; $params[] = $to; }
$w = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int)fetch_val("SELECT COUNT(*) FROM reservations v JOIN tenants t ON t.id = v.tenant_id $w", $params);
$pg = paginate($total, 15);
$rows = fetch_all("SELECT v.*, t.full_name, t.contact_no, r.room_no
                     FROM reservations v JOIN tenants t ON t.id = v.tenant_id JOIN rooms r ON r.id = v.room_id
                     $w ORDER BY FIELD(v.status,'pending','confirmed','checked_in','completed','cancelled'), v.move_in_date DESC, v.id DESC
                     LIMIT {$pg['per']} OFFSET {$pg['offset']}", $params);
$totals = payment_totals(array_column($rows, 'id'));
$roomList = fetch_all("SELECT id, room_no FROM rooms ORDER BY LENGTH(room_no), room_no");
$counts = [];
foreach (fetch_all("SELECT status, COUNT(*) c FROM reservations GROUP BY status") as $c) $counts[$c['status']] = (int)$c['c'];

admin_header('Reservations', 'reservations');
page_head('Reservations', 'Track every reservation from pending to check-out.', '<a class="btn btn-primary" href="' . e(url('admin/reservation_form.php')) . '">New reservation</a>');
?>
<section class="panel">
  <div class="tabs" role="navigation" aria-label="Reservation status">
    <a href="<?= e(url('admin/reservations.php')) ?>"<?= $status === '' ? ' class="active"' : '' ?>>All</a>
    <?php foreach ($statuses as $s): ?>
      <a href="<?= e(url('admin/reservations.php?status=' . $s)) ?>"<?= $status === $s ? ' class="active"' : '' ?>><?= e(ucfirst(str_replace('_', ' ', $s))) ?> <span class="tab-n"><?= $counts[$s] ?? 0 ?></span></a>
    <?php endforeach; ?>
  </div>
  <form class="filters" method="get">
    <input type="hidden" name="status" value="<?= e($status) ?>">
    <label>Search<input type="search" name="q" value="<?= e($search) ?>" placeholder="Tenant, reservation no., contact"></label>
    <label>Room
      <select name="room"><option value="">All rooms</option>
        <?php foreach ($roomList as $r): ?><option value="<?= (int)$r['id'] ?>"<?= selected($roomId, $r['id']) ?>>Room <?= e($r['room_no']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label>Move-in from<input type="date" name="from" value="<?= e($from) ?>"></label>
    <label>to<input type="date" name="to" value="<?= e($to) ?>"></label>
    <button class="btn" type="submit">Filter</button>
    <?php if ($search || $roomId || $from || $to): ?><a class="btn btn-ghost" href="<?= e(url('admin/reservations.php' . ($status ? '?status=' . $status : ''))) ?>">Clear</a><?php endif; ?>
  </form>

  <?php if ($rows): ?>
  <div class="table-scroll"><table>
    <thead><tr><th>No.</th><th>Tenant</th><th>Room</th><th>Move-in</th><th>Move-out</th><th>Status</th><th class="num">Paid</th><th class="num">Balance</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $v): $f = compute_financials($v, $totals[(int)$v['id']] ?? []); ?>
      <tr>
        <td><a href="<?= e(url('admin/reservation_view.php?id=' . (int)$v['id'])) ?>"><?= e($v['reservation_no']) ?></a></td>
        <td><?= e($v['full_name']) ?><br><span class="muted small"><?= e($v['contact_no']) ?></span></td>
        <td>Room <?= e($v['room_no']) ?><?= (int)$v['beds'] > 1 ? '<br><span class="muted small">' . (int)$v['beds'] . ' beds</span>' : '' ?></td>
        <td><?= e(fmt_date($v['move_in_date'])) ?></td>
        <td><?= e(fmt_date($v['move_out_date'], 'Open')) ?></td>
        <td><?= reservation_badge($v['status']) ?></td>
        <td class="num"><?= e(money($f['paid'])) ?></td>
        <td class="num"><?= in_array($v['status'], ['cancelled'], true) ? '—' : ($f['balance'] > 0 ? '<strong>' . e(money($f['balance'])) . '</strong>' : ($f['credit'] > 0 ? '<span class="muted">credit ' . e(money($f['credit'])) . '</span>' : '—')) ?></td>
        <td class="actions"><a class="btn btn-sm" href="<?= e(url('admin/reservation_view.php?id=' . (int)$v['id'])) ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?= pager($pg) ?>
  <?php else: ?>
    <p class="empty">No reservations found. <a href="<?= e(url('admin/reservation_form.php')) ?>">Record a new reservation</a>.</p>
  <?php endif; ?>
</section>
<?php admin_footer();
