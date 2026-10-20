<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();

if (is_post()) {
    csrf_verify();
    if (post_str('action', 20) === 'delete') {
        $id = post_int('id');
        $room = fetch_one("SELECT * FROM rooms WHERE id = ?", [$id]);
        if (!$room) {
            flash('error', 'That room no longer exists.');
        } elseif ((int)fetch_val("SELECT COUNT(*) FROM reservations WHERE room_id = ?", [$id]) > 0) {
            flash('error', 'Room ' . $room['room_no'] . ' has reservation history and cannot be deleted. Hide it from the public website or set it to maintenance instead.');
        } else {
            q("DELETE FROM rooms WHERE id = ?", [$id]);
            delete_room_photo($room['photo']);
            log_activity('room_deleted', 'room', $id, 'Room ' . $room['room_no']);
            flash('success', 'Room ' . $room['room_no'] . ' was deleted.');
        }
    }
    redirect('admin/rooms.php');
}

$rooms = rooms_with_status(false);
admin_header('Rooms', 'rooms');
page_head('Rooms', 'Set up rooms, beds, rates, and what shows on the public website.',
    '<a class="btn btn-primary" href="' . e(url('admin/room_form.php')) . '">Add room</a>');
?>
<section class="panel">
<?php if ($rooms): ?>
<div class="table-scroll"><table>
  <thead><tr><th>Room</th><th>Type</th><th>Beds</th><th class="num">Rent / bed</th><th class="num">Deposit / bed</th><th>Status</th><th>Website</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rooms as $r): ?>
    <tr>
      <td><a href="<?= e(url('admin/room_form.php?id=' . (int)$r['id'])) ?>"><strong><?= e($r['room_no']) ?></strong></a><?= $r['room_name'] ? '<br><span class="muted small">' . e($r['room_name']) . '</span>' : '' ?></td>
      <td><?= $r['room_type'] === 'private' ? 'Private' : 'Shared' ?><br><span class="muted small"><?= e(gender_label($r['gender_policy'])) ?></span></td>
      <td><?= bed_pips($r) ?><br><span class="muted small"><?= $r['occupied_beds'] ?> occupied, <?= $r['reserved_beds'] ?> reserved, <?= $r['open_beds'] ?> open</span></td>
      <td class="num"><?= e(money($r['monthly_rate'])) ?></td>
      <td class="num"><?= e(money($r['deposit_amount'])) ?></td>
      <td><span class="status status-<?= e($r['status_key']) ?>"><?= e($r['status_label']) ?></span></td>
      <td><?= $r['is_public'] ? 'Listed' : '<span class="muted">Hidden</span>' ?></td>
      <td class="actions">
        <a class="btn btn-sm" href="<?= e(url('admin/room_form.php?id=' . (int)$r['id'])) ?>">Edit</a>
        <form method="post" class="inline" data-confirm="Delete room <?= e($r['room_no']) ?>? This cannot be undone.">
          <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <button class="btn btn-sm btn-danger" type="submit">Delete</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php else: ?>
  <p class="empty">No rooms yet. <a href="<?= e(url('admin/room_form.php')) ?>">Add your first room</a> to start taking reservations.</p>
<?php endif; ?>
</section>
<?php admin_footer();
