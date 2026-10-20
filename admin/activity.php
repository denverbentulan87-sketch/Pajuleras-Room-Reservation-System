<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();

$total = (int)fetch_val("SELECT COUNT(*) FROM activity_log");
$pg = paginate($total, 30);
$rows = fetch_all("SELECT * FROM activity_log ORDER BY id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}");
admin_header('Activity log', 'activity');
page_head('Activity log', 'A record of what was done in the system, and when.');
?>
<section class="panel">
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
<?php else: ?><p class="empty">Nothing has been logged yet.</p><?php endif; ?>
</section>
<?php admin_footer();
