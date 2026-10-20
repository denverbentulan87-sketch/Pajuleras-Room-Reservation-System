<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();

$rooms = rooms_with_status(false);
$active = array_filter($rooms, fn($r) => $r['status'] === 'active');
$totalBeds = array_sum(array_map(fn($r) => (int)$r['capacity'], $active));
$occupied = array_sum(array_column($active, 'occupied_beds'));
$reserved = array_sum(array_column($active, 'reserved_beds'));
$open = array_sum(array_column($active, 'open_beds'));

$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');
$collected = (float)fetch_val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE is_void = 0 AND payment_type <> 'refund' AND payment_date BETWEEN ? AND ?", [$monthStart, $monthEnd]);

$newInquiries = (int)fetch_val("SELECT COUNT(*) FROM inquiries WHERE admin_unread = 1 AND status <> 'closed'");
$pendingRes = (int)fetch_val("SELECT COUNT(*) FROM reservations WHERE status = 'pending'");
$balances = outstanding_balances();
$balanceTotal = array_sum(array_map(fn($b) => $b['fin']['balance'], $balances));

$inqList = fetch_all("SELECT i.id, i.name, i.subject, i.status, i.admin_unread, i.created_at, i.updated_at
                        FROM inquiries i WHERE i.status IN ('new') OR i.admin_unread = 1
                       ORDER BY i.updated_at DESC LIMIT 5");
$pendingList = fetch_all("SELECT v.id, v.reservation_no, v.move_in_date, t.full_name, r.room_no
                            FROM reservations v JOIN tenants t ON t.id = v.tenant_id JOIN rooms r ON r.id = v.room_id
                           WHERE v.status = 'pending' ORDER BY v.created_at DESC LIMIT 5");
$moveIns = fetch_all("SELECT v.id, v.reservation_no, v.move_in_date, t.full_name, r.room_no
                        FROM reservations v JOIN tenants t ON t.id = v.tenant_id JOIN rooms r ON r.id = v.room_id
                       WHERE v.status = 'confirmed' AND v.move_in_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                       ORDER BY v.move_in_date LIMIT 8");
$rentDue = upcoming_rent_due(7);

// last 6 months of collections
$series = [];
for ($i = 5; $i >= 0; $i--) {
    $k = date('Y-m', strtotime(date('Y-m-01') . " -$i month"));
    $series[$k] = 0.0;
}
foreach (fetch_all("SELECT DATE_FORMAT(payment_date,'%Y-%m') ym, SUM(amount) s FROM payments
                     WHERE is_void = 0 AND payment_type <> 'refund' AND payment_date >= ?
                     GROUP BY ym", [date('Y-m-01', strtotime('-5 month'))]) as $r) {
    if (isset($series[$r['ym']])) $series[$r['ym']] = (float)$r['s'];
}
$maxS = max(1, max($series));

admin_header('Dashboard', 'dashboard');

$hour = (int)date('G');
$greet = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$first = explode(' ', trim((string)($GLOBALS['ADMIN']['full_name'] ?? 'Landlord')))[0];
$todo = [];
if ($newInquiries) $todo[] = plural($newInquiries, 'unread inquiry', 'unread inquiries');
$gcashPending = gcash_pending_count();
if ($gcashPending) $todo[] = plural($gcashPending, 'GCash payment') . ' to review';
if ($pendingRes)   $todo[] = plural($pendingRes, 'reservation') . ' to confirm';
if ($balances)     $todo[] = plural(count($balances), 'tenant') . ' with an unpaid balance';
?>
<section class="welcome">
  <div class="welcome-text">
    <p class="welcome-date"><?= icon('calendar') ?><span><?= e(date('l, F j, Y')) ?></span></p>
    <h1><?= e($greet) ?>, <?= e($first) ?></h1>
    <p class="welcome-sub"><?= $todo ? 'Today you have ' . e(implode(', ', $todo)) . '.' : 'Everything is up to date. Nothing needs your attention right now.' ?></p>
    <div class="welcome-actions">
      <a class="btn btn-primary" href="<?= e(url('admin/reservation_form.php')) ?>"><?= icon('plus') ?><span>New reservation</span></a>
      <a class="btn" href="<?= e(url('admin/payment_form.php')) ?>"><?= icon('cash') ?><span>Record payment</span></a>
      <a class="btn" href="<?= e(url('admin/tenant_form.php')) ?>"><?= icon('users') ?><span>Register tenant</span></a>
    </div>
  </div>
  <div class="welcome-photo"><?= art_house('ah', 'Illustration of the boarding house') ?></div>
</section>

<div class="stats">
  <a class="stat" href="<?= e(url('admin/rooms.php')) ?>"><span class="stat-ic st-leaf"><?= icon('bed') ?></span><span class="stat-body"><span class="stat-n"><?= $open ?></span><span class="stat-l">Open beds</span><small>of <?= $totalBeds ?> beds in <?= plural(count($active), 'active room') ?></small></span></a>
  <a class="stat" href="<?= e(url('admin/reservations.php?status=checked_in')) ?>"><span class="stat-ic st-slate"><?= icon('key') ?></span><span class="stat-body"><span class="stat-n"><?= $occupied ?></span><span class="stat-l">Occupied beds</span><small><?= $reserved ?> more reserved</small></span></a>
  <a class="stat <?= $newInquiries ? 'stat-alert' : '' ?>" href="<?= e(url('admin/inquiries.php?filter=unread')) ?>"><span class="stat-ic st-sky"><?= icon('inbox') ?></span><span class="stat-body"><span class="stat-n"><?= $newInquiries ?></span><span class="stat-l">Unread inquiries</span><small>waiting for your reply</small></span></a>
  <a class="stat <?= $pendingRes ? 'stat-alert' : '' ?>" href="<?= e(url('admin/reservations.php?status=pending')) ?>"><span class="stat-ic st-ochre"><?= icon('calendar-clock') ?></span><span class="stat-body"><span class="stat-n"><?= $pendingRes ?></span><span class="stat-l">Pending reservations</span><small>need confirmation</small></span></a>
  <a class="stat" href="<?= e(url('admin/payments.php')) ?>"><span class="stat-ic st-leaf"><?= icon('cash') ?></span><span class="stat-body"><span class="stat-n money"><?= e(money_short($collected)) ?></span><span class="stat-l">Collected in <?= e(date('F')) ?></span><small>payments received</small></span></a>
  <a class="stat <?= $balances ? 'stat-alert' : '' ?>" href="<?= e(url('admin/reports.php?type=balances')) ?>"><span class="stat-ic st-red"><?= icon('alert') ?></span><span class="stat-body"><span class="stat-n money"><?= e(money_short($balanceTotal)) ?></span><span class="stat-l">Unpaid balances</span><small><?= plural(count($balances), 'tenant') ?> with a balance</small></span></a>
</div>

<div class="grid-2">
  <section class="panel">
    <div class="panel-head"><h2><?= icon('door') ?>Room board</h2><a href="<?= e(url('admin/rooms.php')) ?>">Manage rooms</a></div>
    <?php if ($rooms): ?>
    <div class="table-scroll"><table>
      <thead><tr><th>Room</th><th>Beds</th><th>Status</th><th class="num">Pending</th></tr></thead>
      <tbody>
      <?php foreach ($rooms as $r): ?>
        <tr>
          <td><a href="<?= e(url('admin/room_form.php?id=' . (int)$r['id'])) ?>"><strong><?= e($r['room_no']) ?></strong></a><?= $r['room_name'] ? ' <span class="muted">' . e($r['room_name']) . '</span>' : '' ?></td>
          <td><?= bed_pips($r) ?> <span class="muted small"><?= $r['occupied_beds'] ?>/<?= $r['reserved_beds'] ?>/<?= $r['open_beds'] ?></span></td>
          <td><span class="status status-<?= e($r['status_key']) ?>"><?= e($r['status_label']) ?></span></td>
          <td class="num"><?= $r['pending_count'] ?: '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <div class="muted small legend-note">Beds shown as occupied / reserved / open. <?= pips_legend() ?></div>
    <?php else: ?>
      <p class="empty">No rooms yet. <a href="<?= e(url('admin/room_form.php')) ?>">Add your first room</a>.</p>
    <?php endif; ?>
  </section>

  <section class="panel">
    <div class="panel-head"><h2><?= icon('chart') ?>Collections, last 6 months</h2><a href="<?= e(url('admin/reports.php?type=payments')) ?>">Payments report</a></div>
    <div class="bars" role="img" aria-label="Monthly collections">
      <?php foreach ($series as $ym => $amt): ?>
        <div class="bar-col">
          <span class="bar-val"><?= $amt > 0 ? e(money_short($amt)) : '' ?></span>
          <span class="bar" style="height:<?= max(2, round($amt / $maxS * 100)) ?>%"></span>
          <span class="bar-lbl"><?= e(date('M', strtotime($ym . '-01'))) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
</div>

<div class="grid-2">
  <section class="panel">
    <div class="panel-head"><h2><?= icon('inbox') ?>Inquiries to answer</h2><a href="<?= e(url('admin/inquiries.php')) ?>">All inquiries</a></div>
    <?php if ($inqList): ?>
      <ul class="rows">
      <?php foreach ($inqList as $i): ?>
        <li><a href="<?= e(url('admin/inquiry_view.php?id=' . (int)$i['id'])) ?>"><strong><?= e($i['name']) ?></strong> <span class="muted"><?= e($i['subject']) ?></span></a>
          <span class="meta"><?= $i['admin_unread'] ? badge('Unread', 'warn') : '' ?> <?= e(fmt_datetime($i['updated_at'])) ?></span></li>
      <?php endforeach; ?>
      </ul>
    <?php else: ?><p class="empty">You are all caught up.</p><?php endif; ?>
  </section>

  <section class="panel">
    <div class="panel-head"><h2><?= icon('calendar-clock') ?>Pending reservations</h2><a href="<?= e(url('admin/reservations.php?status=pending')) ?>">View all</a></div>
    <?php if ($pendingList): ?>
      <ul class="rows">
      <?php foreach ($pendingList as $p): ?>
        <li><a href="<?= e(url('admin/reservation_view.php?id=' . (int)$p['id'])) ?>"><strong><?= e($p['full_name']) ?></strong> <span class="muted">Room <?= e($p['room_no']) ?></span></a>
          <span class="meta">Move-in <?= e(fmt_date($p['move_in_date'])) ?></span></li>
      <?php endforeach; ?>
      </ul>
    <?php else: ?><p class="empty">No reservations are waiting for confirmation.</p><?php endif; ?>
  </section>
</div>

<div class="grid-2">
  <section class="panel">
    <div class="panel-head"><h2><?= icon('key') ?>Move-ins this week</h2></div>
    <?php if ($moveIns): ?>
      <ul class="rows">
      <?php foreach ($moveIns as $m): $late = $m['move_in_date'] < today(); ?>
        <li><a href="<?= e(url('admin/reservation_view.php?id=' . (int)$m['id'])) ?>"><strong><?= e($m['full_name']) ?></strong> <span class="muted">Room <?= e($m['room_no']) ?></span></a>
          <span class="meta"><?= $late ? badge('Was due ' . fmt_date($m['move_in_date']), 'bad') : e(fmt_date($m['move_in_date'])) ?></span></li>
      <?php endforeach; ?>
      </ul>
    <?php else: ?><p class="empty">No confirmed move-ins in the next 7 days.</p><?php endif; ?>
  </section>

  <section class="panel">
    <div class="panel-head"><h2><?= icon('clock') ?>Rent due in the next 7 days</h2></div>
    <?php if ($rentDue): ?>
      <ul class="rows">
      <?php foreach ($rentDue as $d): ?>
        <li><a href="<?= e(url('admin/reservation_view.php?id=' . (int)$d['id'])) ?>"><strong><?= e($d['full_name']) ?></strong> <span class="muted">Room <?= e($d['room_no']) ?></span></a>
          <span class="meta"><?= e(money_short($d['monthly_rate'])) ?> on <?= e(fmt_date($d['fin']['next_due'])) ?></span></li>
      <?php endforeach; ?>
      </ul>
    <?php else: ?><p class="empty">No rent falls due in the next 7 days.</p><?php endif; ?>
  </section>
</div>

<section class="panel">
  <div class="panel-head"><h2><?= icon('alert') ?>Tenants with unpaid balances</h2><a href="<?= e(url('admin/reports.php?type=balances')) ?>">Full report</a></div>
  <?php if ($balances): ?>
  <div class="table-scroll"><table>
    <thead><tr><th>Tenant</th><th>Room</th><th>Reservation</th><th>Contact</th><th class="num">Balance</th></tr></thead>
    <tbody>
    <?php foreach (array_slice($balances, 0, 6) as $b): ?>
      <tr>
        <td><a href="<?= e(url('admin/reservation_view.php?id=' . (int)$b['id'])) ?>"><?= e($b['full_name']) ?></a></td>
        <td><?= e($b['room_no']) ?></td>
        <td><?= e($b['reservation_no']) ?> <?= reservation_badge($b['status']) ?></td>
        <td><?= e($b['contact_no']) ?></td>
        <td class="num"><strong><?= e(money($b['fin']['balance'])) ?></strong></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php else: ?><p class="empty">Everyone is paid up.</p><?php endif; ?>
</section>
<?php admin_footer();
