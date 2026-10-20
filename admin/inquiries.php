<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();

$filter = get_str('filter');
$search = get_str('q', 80);
$where = [];
$params = [];
if ($filter === 'unread') {
    $where[] = "i.admin_unread = 1 AND i.status <> 'closed'";
} elseif (in_array($filter, ['new', 'replied', 'converted', 'closed'], true)) {
    $where[] = "i.status = ?";
    $params[] = $filter;
} else {
    $filter = '';
}
if ($search !== '') {
    $where[] = "(i.name LIKE ? OR i.ref_code LIKE ? OR i.contact_no LIKE ? OR i.subject LIKE ?)";
    $l = like_escape($search);
    array_push($params, $l, $l, like_escape(normalize_phone($search) ?: $search), $l);
}
$w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$total = (int)fetch_val("SELECT COUNT(*) FROM inquiries i $w", $params);
$pg = paginate($total, 15);
$rows = fetch_all("SELECT i.*, r.room_no,
        (SELECT body FROM inquiry_messages m WHERE m.inquiry_id = i.id ORDER BY m.id DESC LIMIT 1) AS last_body,
        (SELECT sender FROM inquiry_messages m WHERE m.inquiry_id = i.id ORDER BY m.id DESC LIMIT 1) AS last_sender
      FROM inquiries i LEFT JOIN rooms r ON r.id = i.room_id $w
      ORDER BY i.admin_unread DESC, i.updated_at DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}", $params);
$counts = ['unread' => (int)fetch_val("SELECT COUNT(*) FROM inquiries WHERE admin_unread = 1 AND status <> 'closed'")];
foreach (fetch_all("SELECT status, COUNT(*) c FROM inquiries GROUP BY status") as $c) $counts[$c['status']] = (int)$c['c'];

admin_header('Inquiries', 'inquiries');
page_head('Inquiries', 'Messages from customers on the public website.');
?>
<section class="panel">
  <div class="tabs" role="navigation" aria-label="Inquiry filter">
    <a href="<?= e(url('admin/inquiries.php')) ?>"<?= $filter === '' ? ' class="active"' : '' ?>>All</a>
    <a href="<?= e(url('admin/inquiries.php?filter=unread')) ?>"<?= $filter === 'unread' ? ' class="active"' : '' ?>>Unread <span class="tab-n"><?= $counts['unread'] ?></span></a>
    <?php foreach (['new' => 'New', 'replied' => 'Replied', 'converted' => 'Reserved', 'closed' => 'Closed'] as $k => $label): ?>
      <a href="<?= e(url('admin/inquiries.php?filter=' . $k)) ?>"<?= $filter === $k ? ' class="active"' : '' ?>><?= e($label) ?> <span class="tab-n"><?= $counts[$k] ?? 0 ?></span></a>
    <?php endforeach; ?>
  </div>
  <form class="filters" method="get">
    <input type="hidden" name="filter" value="<?= e($filter) ?>">
    <label>Search<input type="search" name="q" value="<?= e($search) ?>" placeholder="Name, code, contact number"></label>
    <button class="btn" type="submit">Search</button>
    <?php if ($search): ?><a class="btn btn-ghost" href="<?= e(url('admin/inquiries.php' . ($filter ? '?filter=' . $filter : ''))) ?>">Clear</a><?php endif; ?>
  </form>

  <?php if ($rows): ?>
  <ul class="inbox">
    <?php foreach ($rows as $i): ?>
      <li class="<?= $i['admin_unread'] && $i['status'] !== 'closed' ? 'unread' : '' ?>">
        <a href="<?= e(url('admin/inquiry_view.php?id=' . (int)$i['id'])) ?>">
          <span class="inbox-top">
            <strong><?= e($i['name']) ?></strong>
            <span class="muted small"><?= e($i['ref_code']) ?><?= $i['room_no'] ? ' &middot; Room ' . e($i['room_no']) : '' ?></span>
            <?= inquiry_badge($i['status']) ?>
            <span class="meta"><?= e(fmt_datetime($i['updated_at'])) ?></span>
          </span>
          <span class="inbox-preview"><?= $i['last_sender'] === 'admin' ? 'You: ' : '' ?><?= e(mb_strimwidth((string)$i['last_body'], 0, 140, '…')) ?></span>
        </a>
      </li>
    <?php endforeach; ?>
  </ul>
  <?= pager($pg) ?>
  <?php else: ?><p class="empty"><?= ($search || $filter) ? 'No inquiries match.' : 'No inquiries yet. They appear here when customers use the website.' ?></p><?php endif; ?>
</section>
<?php admin_footer();
