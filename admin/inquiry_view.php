<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();

$id = get_int('id');
$i = $id ? fetch_one("SELECT i.*, r.room_no, r.room_name FROM inquiries i LEFT JOIN rooms r ON r.id = i.room_id WHERE i.id = ?", [$id]) : null;
if (!$i) {
    abort(404, 'That inquiry does not exist.');
}
$errors = [];

if (is_post()) {
    csrf_verify();
    $action = post_str('action', 20);
    if ($action === 'reply') {
        $body = post_str('body', 2000);
        if (mb_strlen($body) < 2) {
            $errors[] = 'Write your reply first.';
        } else {
            in_transaction(function () use ($id, $body) {
                q("INSERT INTO inquiry_messages (inquiry_id, sender, body) VALUES (?, 'admin', ?)", [$id, $body]);
                q("UPDATE inquiries SET admin_unread = 0, status = CASE WHEN status = 'new' THEN 'replied' ELSE status END WHERE id = ?", [$id]);
            });
            log_activity('inquiry_replied', 'inquiry', $id, 'Replied to ' . $i['name'] . ' (' . $i['ref_code'] . ')');
            flash('success', 'Reply sent. ' . $i['name'] . ' can read it with the inquiry code ' . $i['ref_code'] . '. You may also call or text ' . $i['contact_no'] . '.');
            redirect('admin/inquiry_view.php?id=' . $id);
        }
    } elseif ($action === 'close') {
        q("UPDATE inquiries SET status = 'closed', admin_unread = 0 WHERE id = ?", [$id]);
        log_activity('inquiry_closed', 'inquiry', $id, $i['ref_code'] . ' closed');
        flash('success', 'Inquiry closed.');
        redirect('admin/inquiry_view.php?id=' . $id);
    } elseif ($action === 'reopen') {
        $hasRes = (int)fetch_val("SELECT COUNT(*) FROM reservations WHERE inquiry_id = ?", [$id]) > 0;
        $newStatus = $hasRes ? 'converted' : ((int)fetch_val("SELECT COUNT(*) FROM inquiry_messages WHERE inquiry_id = ? AND sender = 'admin'", [$id]) > 0 ? 'replied' : 'new');
        q("UPDATE inquiries SET status = ? WHERE id = ?", [$newStatus, $id]);
        flash('success', 'Inquiry reopened.');
        redirect('admin/inquiry_view.php?id=' . $id);
    } elseif ($action === 'unread') {
        q("UPDATE inquiries SET admin_unread = 1 WHERE id = ?", [$id]);
        redirect('admin/inquiries.php');
    } elseif ($action === 'delete') {
        if ((int)fetch_val("SELECT COUNT(*) FROM reservations WHERE inquiry_id = ?", [$id]) > 0) {
            flash('error', 'A reservation was created from this inquiry, so it is kept for your records. You can close it instead.');
            redirect('admin/inquiry_view.php?id=' . $id);
        }
        q("DELETE FROM inquiries WHERE id = ?", [$id]);
        log_activity('inquiry_deleted', 'inquiry', $id, $i['ref_code'] . ' deleted');
        flash('success', 'Inquiry deleted.');
        redirect('admin/inquiries.php');
    }
}

// opening the thread marks it as read
if ($i['admin_unread']) {
    q("UPDATE inquiries SET admin_unread = 0 WHERE id = ?", [$id]);
    $i['admin_unread'] = 0;
}
$messages = fetch_all("SELECT * FROM inquiry_messages WHERE inquiry_id = ? ORDER BY created_at, id", [$id]);
$reservations = fetch_all("SELECT id, reservation_no, status FROM reservations WHERE inquiry_id = ?", [$id]);
$existingTenant = fetch_one("SELECT id, full_name, tenant_code FROM tenants WHERE contact_no = ? LIMIT 1", [$i['contact_no']]);
$roomInfo = $i['room_id'] ? (rooms_with_status(false, (int)$i['room_id'])[0] ?? null) : null;

admin_header('Inquiry ' . $i['ref_code'], 'inquiries');
page_head($i['name'], $i['subject'] . ' (' . $i['ref_code'] . ')', inquiry_badge($i['status']));
?>
<div class="grid-thread">
  <div>
    <?php render_errors($errors); ?>
    <section class="panel">
      <div class="thread" aria-label="Messages">
        <?php foreach ($messages as $m): ?>
          <div class="msg msg-<?= e($m['sender']) ?>">
            <div class="msg-meta"><strong><?= $m['sender'] === 'admin' ? 'You' : e($i['name']) ?></strong> <span><?= e(fmt_datetime($m['created_at'])) ?></span></div>
            <div class="msg-body"><?= nl2p($m['body']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if ($i['status'] !== 'closed'): ?>
      <form method="post" class="form reply-form">
        <?= csrf_field() ?><input type="hidden" name="action" value="reply">
        <label>Reply<textarea name="body" rows="4" maxlength="2000" required placeholder="Write your reply. The customer reads it with their inquiry code."></textarea></label>
        <button class="btn btn-primary" type="submit">Send reply</button>
      </form>
      <?php else: ?>
        <p class="muted">This inquiry is closed. Reopen it to reply.</p>
      <?php endif; ?>
    </section>
  </div>

  <aside>
    <section class="panel">
      <div class="panel-head"><h2>Customer</h2></div>
      <dl class="details compact">
        <div><dt>Name</dt><dd><?= e($i['name']) ?></dd></div>
        <div><dt>Contact</dt><dd><?= e($i['contact_no']) ?></dd></div>
        <div><dt>Email</dt><dd><?= e($i['email'] ?: '—') ?></dd></div>
        <div><dt>Is a</dt><dd><?= e(occupation_label($i['occupation'])) ?></dd></div>
        <div><dt>Room asked about</dt><dd><?= $i['room_no'] ? 'Room ' . e($i['room_no']) . ($roomInfo ? ' (' . e($roomInfo['status_label']) . ', ' . e(plural($roomInfo['open_beds'], 'bed')) . ' open)' : '') : 'Not sure yet' ?></dd></div>
        <div><dt>Wants to move in</dt><dd><?= e(fmt_date($i['preferred_move_in'], 'Not stated')) ?></dd></div>
        <div><dt>Sent</dt><dd><?= e(fmt_datetime($i['created_at'])) ?></dd></div>
      </dl>
    </section>

    <section class="panel">
      <div class="panel-head"><h2>Next step</h2></div>
      <?php if ($reservations): ?>
        <p>Reservations from this inquiry:</p>
        <ul class="rows"><?php foreach ($reservations as $r): ?><li><a href="<?= e(url('admin/reservation_view.php?id=' . (int)$r['id'])) ?>"><?= e($r['reservation_no']) ?></a> <?= reservation_badge($r['status']) ?></li><?php endforeach; ?></ul>
      <?php endif; ?>
      <?php if ($existingTenant): ?>
        <p class="hint">Already registered as <a href="<?= e(url('admin/tenant_view.php?id=' . (int)$existingTenant['id'])) ?>"><?= e($existingTenant['full_name']) ?></a>.</p>
        <a class="btn btn-primary block" href="<?= e(url('admin/reservation_form.php?tenant_id=' . (int)$existingTenant['id'] . '&inquiry_id=' . $id)) ?>">Create reservation</a>
      <?php else: ?>
        <a class="btn btn-primary block" href="<?= e(url('admin/tenant_form.php?inquiry=' . $id)) ?>">Register tenant and reserve</a>
      <?php endif; ?>
      <div class="stack">
        <?php if ($i['status'] !== 'closed'): ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="close"><button class="btn block" type="submit">Close inquiry</button></form>
        <?php else: ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="reopen"><button class="btn block" type="submit">Reopen inquiry</button></form>
        <?php endif; ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="unread"><button class="btn btn-ghost block" type="submit">Mark as unread</button></form>
        <?php if (!$reservations): ?>
        <form method="post" data-confirm="Delete this inquiry and its messages?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><button class="btn btn-danger-outline block" type="submit">Delete inquiry</button></form>
        <?php endif; ?>
      </div>
    </section>
  </aside>
</div>
<?php admin_footer();
