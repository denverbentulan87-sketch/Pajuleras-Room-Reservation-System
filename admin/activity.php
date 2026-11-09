<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();

$search = get_str('q');
$action = get_str('action');
$from = get_str('from');
$to = get_str('to');
$where = []; $params = [];
if ($action !== '') { $where[] = 'action = ?'; $params[] = $action; }
if ($search !== '') { $where[] = '(details LIKE ? OR action LIKE ? OR ip LIKE ?)'; $l = like_escape($search); array_push($params, $l, $l, $l); }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where[] = 'DATE(created_at) >= ?'; $params[] = $from; } else { $from = ''; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $where[] = 'DATE(created_at) <= ?'; $params[] = $to; } else { $to = ''; }
$w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$actions = array_column(fetch_all("SELECT DISTINCT action FROM activity_log ORDER BY action"), 'action');
$total = (int)fetch_val("SELECT COUNT(*) FROM activity_log $w", $params);
$pg = paginate($total, 10, 'page', '', true);
$rows = fetch_all("SELECT * FROM activity_log $w ORDER BY id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}", $params);
$filtered = $action !== '' || $search !== '' || $from !== '' || $to !== '';
admin_header('Activity log', 'activity', ['fill' => true]);
page_head('Activity log', 'A record of what was done in the system, and when.');
?>
<section class="panel">
  <form class="filters" method="get">
    <label>Search<input type="search" name="q" value="<?= e($search) ?>" placeholder="Details, action or address"></label>
    <label>Action<select name="action"><option value="">All actions</option>
      <?php foreach ($actions as $a): ?><option value="<?= e($a) ?>"<?= $a === $action ? ' selected' : '' ?>><?= e(ucfirst(str_replace('_', ' ', $a))) ?></option><?php endforeach; ?>
    </select></label>
    <label>From<input type="date" name="from" value="<?= e($from) ?>"></label>
    <label>To<input type="date" name="to" value="<?= e($to) ?>"></label>
    <?php if (!empty($_GET['per'])): ?><input type="hidden" name="per" value="<?= (int)$_GET['per'] ?>"><?php endif; ?>
    <button class="btn" type="submit">Filter</button>
    <?php if ($filtered): ?><a class="btn btn-ghost" href="<?= e(url('admin/activity.php')) ?>">Clear</a><?php endif; ?>
  </form>
<?php if ($rows): ?>
  <div class="table-scroll"><table>
    <thead><tr><th>When</th><th>Action</th><th>Details</th><th>From</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td><?= e(fmt_datetime($r['created_at'])) ?></td><td><?= e(ucfirst(str_replace('_', ' ', $r['action']))) ?></td><td><?= e($r['details'] ?: '—') ?></td><td class="muted small"><?= e($r['ip']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?= pager($pg) ?>
<?php else: ?><p class="empty"><?= $filtered ? 'No activity matches these filters.' : 'Nothing has been logged yet.' ?></p><?php endif; ?>
</section>
<?php admin_footer();
