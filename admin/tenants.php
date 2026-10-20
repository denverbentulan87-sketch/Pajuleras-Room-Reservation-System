<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();

if (is_post()) {
    csrf_verify();
    if (post_str('action', 20) === 'delete') {
        $id = post_int('id');
        $t = fetch_one("SELECT * FROM tenants WHERE id = ?", [$id]);
        if (!$t) {
            flash('error', 'That tenant no longer exists.');
        } elseif ((int)fetch_val("SELECT COUNT(*) FROM reservations WHERE tenant_id = ?", [$id]) > 0) {
            flash('error', $t['full_name'] . ' has reservation history and cannot be deleted. Mark the tenant as inactive instead.');
        } else {
            q("DELETE FROM tenants WHERE id = ?", [$id]);
            log_activity('tenant_deleted', 'tenant', $id, $t['full_name']);
            flash('success', 'Tenant record for ' . $t['full_name'] . ' was deleted.');
        }
    }
    redirect('admin/tenants.php');
}

$search = get_str('q', 80);
$status = in_array(get_str('status'), ['active', 'inactive', 'staying'], true) ? get_str('status') : '';
$where = [];
$params = [];
if ($search !== '') {
    $where[] = "(t.full_name LIKE ? OR t.contact_no LIKE ? OR t.tenant_code LIKE ? OR t.school_or_workplace LIKE ?)";
    $like = like_escape($search);
    array_push($params, $like, like_escape(normalize_phone($search) ?: $search), $like, $like);
}
if ($status === 'active' || $status === 'inactive') {
    $where[] = "t.status = ?";
    $params[] = $status;
} elseif ($status === 'staying') {
    $where[] = "EXISTS (SELECT 1 FROM reservations v WHERE v.tenant_id = t.id AND v.status = 'checked_in')";
}
$w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$total = (int)fetch_val("SELECT COUNT(*) FROM tenants t $w", $params);
$pg = paginate($total, 15);
$rows = fetch_all("SELECT t.*,
        (SELECT GROUP_CONCAT(DISTINCT r.room_no ORDER BY r.room_no SEPARATOR ', ')
           FROM reservations v JOIN rooms r ON r.id = v.room_id
          WHERE v.tenant_id = t.id AND v.status = 'checked_in') AS current_room
      FROM tenants t $w ORDER BY t.full_name LIMIT {$pg['per']} OFFSET {$pg['offset']}", $params);

admin_header('Tenants', 'tenants');
page_head('Tenants', 'Registered tenants and customers.', '<a class="btn btn-primary" href="' . e(url('admin/tenant_form.php')) . '">Register tenant</a>');
?>
<section class="panel">
  <form class="filters" method="get">
    <label>Search<input type="search" name="q" value="<?= e($search) ?>" placeholder="Name, contact number, code, school or workplace"></label>
    <label>Show
      <select name="status">
        <option value="">All tenants</option>
        <option value="staying"<?= selected($status, 'staying') ?>>Currently staying</option>
        <option value="active"<?= selected($status, 'active') ?>>Active</option>
        <option value="inactive"<?= selected($status, 'inactive') ?>>Inactive</option>
      </select>
    </label>
    <button class="btn" type="submit">Filter</button>
    <?php if ($search || $status): ?><a class="btn btn-ghost" href="<?= e(url('admin/tenants.php')) ?>">Clear</a><?php endif; ?>
  </form>

  <?php if ($rows): ?>
  <div class="table-scroll"><table>
    <thead><tr><th>Code</th><th>Name</th><th>Contact</th><th>School / workplace</th><th>Room now</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $t): ?>
      <tr>
        <td><?= e($t['tenant_code']) ?></td>
        <td><a href="<?= e(url('admin/tenant_view.php?id=' . (int)$t['id'])) ?>"><strong><?= e($t['full_name']) ?></strong></a><br><span class="muted small"><?= e(occupation_label($t['occupation'])) ?>, <?= e(ucfirst($t['gender'])) ?></span></td>
        <td><?= e($t['contact_no']) ?><?= $t['email'] ? '<br><span class="muted small">' . e($t['email']) . '</span>' : '' ?></td>
        <td><?= e($t['school_or_workplace'] ?: '—') ?></td>
        <td><?= $t['current_room'] ? 'Room ' . e($t['current_room']) : '<span class="muted">—</span>' ?></td>
        <td><?= $t['status'] === 'active' ? badge('Active', 'ok') : badge('Inactive', 'neutral') ?></td>
        <td class="actions">
          <a class="btn btn-sm" href="<?= e(url('admin/tenant_view.php?id=' . (int)$t['id'])) ?>">View</a>
          <a class="btn btn-sm" href="<?= e(url('admin/tenant_form.php?id=' . (int)$t['id'])) ?>">Edit</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?= pager($pg) ?>
  <?php else: ?>
    <p class="empty"><?= ($search || $status) ? 'No tenants match your search.' : 'No tenants yet. Tenants are registered when you record a reservation.' ?></p>
  <?php endif; ?>
</section>
<?php admin_footer();
