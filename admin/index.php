<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();

// "Cron without cron": check payment reminders at most every 6 hours when the landlord opens the dashboard.
try {
    if (reminder_settings()['enabled'] && claim_reminder_run(6 * 3600)) {
        run_reminders(false);
    }
} catch (Throwable $e) {
    error_log('[PBH] reminder check failed: ' . $e->getMessage());
}

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

/** Smooth monotone curve through the points (no overshoot below zero). */
function dash_smooth_path(array $pts): string
{
    $n = count($pts);
    if ($n < 2) return '';
    $dx = $m = $t = [];
    for ($i = 0; $i < $n - 1; $i++) {
        $dx[$i] = $pts[$i + 1][0] - $pts[$i][0];
        $m[$i] = ($pts[$i + 1][1] - $pts[$i][1]) / $dx[$i];
    }
    $t[0] = $m[0];
    $t[$n - 1] = $m[$n - 2];
    for ($i = 1; $i < $n - 1; $i++) $t[$i] = ($m[$i - 1] * $m[$i] <= 0) ? 0 : ($m[$i - 1] + $m[$i]) / 2;
    for ($i = 0; $i < $n - 1; $i++) {
        if ($m[$i] == 0) { $t[$i] = $t[$i + 1] = 0; continue; }
        $a = $t[$i] / $m[$i]; $b = $t[$i + 1] / $m[$i]; $q = $a * $a + $b * $b;
        if ($q > 9) { $k = 3 / sqrt($q); $t[$i] = $k * $a * $m[$i]; $t[$i + 1] = $k * $b * $m[$i]; }
    }
    $d = 'M' . round($pts[0][0], 1) . ' ' . round($pts[0][1], 1);
    for ($i = 0; $i < $n - 1; $i++) {
        $d .= ' C' . round($pts[$i][0] + $dx[$i] / 3, 1) . ' ' . round($pts[$i][1] + $t[$i] * $dx[$i] / 3, 1)
            . ',' . round($pts[$i + 1][0] - $dx[$i] / 3, 1) . ' ' . round($pts[$i + 1][1] - $t[$i + 1] * $dx[$i] / 3, 1)
            . ',' . round($pts[$i + 1][0], 1) . ' ' . round($pts[$i + 1][1], 1);
    }
    return $d;
}

$cW = 640; $cH = 250; $cL = 52; $cR = 18; $cT = 20; $cB = 36;
$hasData = array_sum($series) > 0;
$mag = 10 ** floor(log10($hasData ? $maxS : 1000));
$niceMax = $hasData ? ceil($maxS / $mag) * $mag : 1000;
$keys = array_keys($series);
$vals = array_values($series);
$cPts = [];
foreach ($vals as $i => $v) {
    $cPts[] = [$cL + ($cW - $cL - $cR) * $i / max(1, count($vals) - 1), $cT + ($cH - $cT - $cB) * (1 - $v / $niceMax)];
}
$linePath = dash_smooth_path($cPts);
$baseY = $cH - $cB;
$areaPath = $linePath . ' L' . round(end($cPts)[0], 1) . ' ' . $baseY . ' L' . round($cPts[0][0], 1) . ' ' . $baseY . ' Z';
$sixTotal = array_sum($vals);
$curM = (float)end($vals);
$prevM = (float)($vals[count($vals) - 2] ?? 0);
$delta = $prevM > 0 ? (int)round(($curM - $prevM) / $prevM * 100) : null;
$occPct = $totalBeds > 0 ? round($occupied / $totalBeds * 100) : 0;
$resPct = $totalBeds > 0 ? round($reserved / $totalBeds * 100) : 0;
$GLOBALS['ADMIN_SCRIPTS'] = ['assets/js/dash.js'];

admin_header('Dashboard', 'dashboard');

$hour = (int)date('G');
$greet = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$first = explode(' ', trim((string)($GLOBALS['ADMIN']['full_name'] ?? 'Landlord')))[0];
$todo = [];
if ($newInquiries) $todo[] = plural($newInquiries, 'unread inquiry', 'unread inquiries');
$gcashPending = gcash_pending_count();
if ($gcashPending) $todo[] = plural($gcashPending, 'GCash payment') . ' to review';
$manualReminders = (int)fetch_val("SELECT COUNT(*) FROM notifications WHERE status = 'manual'");
if ($manualReminders) $todo[] = plural($manualReminders, 'reminder') . ' to send by hand';
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
  <a class="stat" href="<?= e(url('admin/rooms.php')) ?>"><span class="stat-ic st-leaf"><?= icon('bed') ?></span><span class="stat-body"><span class="stat-l">Open beds</span><span class="stat-n" data-count="<?= $open ?>"><?= $open ?></span><small>of <?= $totalBeds ?> beds in <?= plural(count($active), 'active room') ?></small></span></a>
  <a class="stat" href="<?= e(url('admin/reservations.php?status=checked_in')) ?>"><span class="stat-ic st-slate"><?= icon('key') ?></span><span class="stat-body"><span class="stat-l">Occupied beds</span><span class="stat-n" data-count="<?= $occupied ?>"><?= $occupied ?></span><small><?= $reserved ?> more reserved</small></span></a>
  <a class="stat <?= $newInquiries ? 'stat-alert' : '' ?>" href="<?= e(url('admin/inquiries.php?filter=unread')) ?>"><span class="stat-ic st-sky"><?= icon('inbox') ?></span><span class="stat-body"><span class="stat-l">Unread inquiries</span><span class="stat-n" data-count="<?= $newInquiries ?>"><?= $newInquiries ?></span><small>waiting for your reply</small></span></a>
  <a class="stat <?= $pendingRes ? 'stat-alert' : '' ?>" href="<?= e(url('admin/reservations.php?status=pending')) ?>"><span class="stat-ic st-ochre"><?= icon('calendar-clock') ?></span><span class="stat-body"><span class="stat-l">Pending reservations</span><span class="stat-n" data-count="<?= $pendingRes ?>"><?= $pendingRes ?></span><small>need confirmation</small></span></a>
  <a class="stat" href="<?= e(url('admin/payments.php')) ?>"><span class="stat-ic st-leaf"><?= icon('cash') ?></span><span class="stat-body"><span class="stat-l">Collected in <?= e(date('F')) ?></span><span class="stat-n money"><?= e(money_short($collected)) ?></span><small><?php if ($delta !== null): ?><span class="delta <?= $delta >= 0 ? 'up' : 'down' ?>"><?= $delta >= 0 ? '+' : '' ?><?= $delta ?>%</span> vs last month<?php else: ?>payments received<?php endif; ?></small></span></a>
  <a class="stat <?= $balances ? 'stat-alert' : '' ?>" href="<?= e(url('admin/reports.php?type=balances')) ?>"><span class="stat-ic st-red"><?= icon('alert') ?></span><span class="stat-body"><span class="stat-l">Unpaid balances</span><span class="stat-n money"><?= e(money_short($balanceTotal)) ?></span><small><?= plural(count($balances), 'tenant') ?> with a balance</small></span></a>
</div>

<div class="grid-main">
  <section class="panel chart-panel">
    <div class="panel-head"><h2><?= icon('chart') ?>Collections</h2><a href="<?= e(url('admin/reports.php?type=payments')) ?>">Payments report</a></div>
    <p class="chart-sum"><strong><?= e(money_short($sixTotal)) ?></strong> <span class="muted">collected over the last 6 months</span></p>
    <div class="trend" data-trend>
      <svg viewBox="0 0 <?= $cW ?> <?= $cH ?>" role="img" aria-label="Monthly collections for the last 6 months: <?= e(implode(', ', array_map(fn($k, $v) => date('M', strtotime($k . '-01')) . ' ' . money_short($v), $keys, $vals))) ?>">
        <defs><linearGradient id="tg" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#1f5a43" stop-opacity=".22"/><stop offset="1" stop-color="#1f5a43" stop-opacity="0"/></linearGradient></defs>
        <?php foreach ([0, .5, 1] as $g): $gy = round($cT + ($cH - $cT - $cB) * (1 - $g), 1); ?>
          <line class="t-grid" x1="<?= $cL ?>" x2="<?= $cW - $cR ?>" y1="<?= $gy ?>" y2="<?= $gy ?>"/>
          <?php if ($hasData || $g == 0): ?><text class="t-ylab" x="<?= $cL - 10 ?>" y="<?= $gy + 4 ?>" text-anchor="end"><?= e($g == 0 ? '0' : money_short($niceMax * $g)) ?></text><?php endif; ?>
        <?php endforeach; ?>
        <path class="t-area" d="<?= e($areaPath) ?>" fill="url(#tg)"/>
        <path class="t-line" d="<?= e($linePath) ?>" pathLength="1"/>
        <line class="t-guide" x1="0" x2="0" y1="<?= $cT ?>" y2="<?= $baseY ?>"/>
        <?php foreach ($cPts as $i => [$px, $py]): ?>
          <text class="t-xlab" x="<?= round($px, 1) ?>" y="<?= $cH - 10 ?>" text-anchor="middle"><?= e(date('M', strtotime($keys[$i] . '-01'))) ?></text>
          <circle class="t-dot<?= $i === count($cPts) - 1 ? ' now' : '' ?>" cx="<?= round($px, 1) ?>" cy="<?= round($py, 1) ?>" r="5" data-label="<?= e(date('F Y', strtotime($keys[$i] . '-01'))) ?>" data-value="<?= e(money($vals[$i])) ?>"/>
        <?php endforeach; ?>
      </svg>
      <div class="t-tip" hidden><strong></strong><span></span></div>
    </div>
  </section>

  <section class="panel ring-panel">
    <div class="panel-head"><h2><?= icon('bed') ?>Occupancy</h2><a href="<?= e(url('admin/rooms.php')) ?>">Rooms</a></div>
    <div class="ring" data-ring data-occ="<?= $totalBeds ? $occupied / $totalBeds * 100 : 0 ?>" data-res="<?= $totalBeds ? $reserved / $totalBeds * 100 : 0 ?>">
      <svg viewBox="0 0 140 140" role="img" aria-label="<?= $occPct ?> percent of beds occupied, <?= $resPct ?> percent reserved">
        <circle class="r-track" cx="70" cy="70" r="56" pathLength="100"/>
        <circle class="r-res" cx="70" cy="70" r="56" pathLength="100"/>
        <circle class="r-occ" cx="70" cy="70" r="56" pathLength="100"/>
      </svg>
      <div class="ring-mid"><strong><?= (int)$occPct ?>%</strong><span>occupied</span></div>
    </div>
    <ul class="ring-legend">
      <li><i class="k-occ"></i>Occupied<b><?= (int)$occupied ?></b></li>
      <li><i class="k-res"></i>Reserved<b><?= (int)$reserved ?></b></li>
      <li><i class="k-open"></i>Open<b><?= (int)$open ?></b></li>
    </ul>
  </section>
</div>

<div class="grid-1">
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
