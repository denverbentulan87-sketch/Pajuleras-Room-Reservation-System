<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();

$tenantOf = fn(?int $id) => $id ? fetch_one("SELECT id, full_name, contact_no, email, messenger FROM tenants WHERE id = ?", [$id]) : null;

if (is_post()) {
    csrf_verify();
    $action = post_str('action', 12);
    if ($action === 'run') {
        $r = run_reminders(false, null, true);
        flash($r['ok'] ? 'success' : 'warn', $r['message']);
    } elseif ($action === 'send_now') {
        $rid = post_int('reservation_id');
        $r = run_reminders(false, $rid, true);
        $x = $r['results'][0] ?? null;
        flash($x && $x['status'] === 'sent' ? 'success' : 'info', $x ? $x['tenant'] . ': ' . ($x['status'] === 'manual' ? 'prepared below, ready to send by hand.' : $x['note']) : 'Nothing to send for that reservation right now.');
    } elseif ($action === 'mark_sent' || $action === 'dismiss') {
        $nid = post_int('id');
        $n = fetch_one("SELECT * FROM notifications WHERE id = ? AND status = 'manual'", [$nid]);
        if ($n) {
            if ($action === 'mark_sent') {
                q("UPDATE notifications SET status = 'sent', sent_at = NOW(), error = NULL WHERE id = ?", [$nid]);
                log_activity('reminder_sent_by_hand', 'reservation', (int)$n['reservation_id'], 'Reminder marked as sent by hand (' . $n['type'] . ')');
                flash('success', 'Marked as sent.');
            } else {
                q("UPDATE notifications SET status = 'skipped' WHERE id = ?", [$nid]);
                flash('success', 'Dismissed. It will not be prepared again.');
            }
        }
    }
    redirect('admin/reminders.php');
}

$overview = reminder_overview();
$manual = fetch_all("SELECT n.*, t.full_name, t.contact_no, t.email, t.messenger, v.reservation_no
                       FROM notifications n
                       LEFT JOIN tenants t ON t.id = n.tenant_id
                       LEFT JOIN reservations v ON v.id = n.reservation_id
                      WHERE n.status = 'manual' ORDER BY n.created_at DESC LIMIT 50");
$log = fetch_all("SELECT n.*, t.full_name, v.reservation_no
                    FROM notifications n
                    LEFT JOIN tenants t ON t.id = n.tenant_id
                    LEFT JOIN reservations v ON v.id = n.reservation_id
                   ORDER BY n.id DESC LIMIT 30");
$lastSent = [];
foreach (fetch_all("SELECT reservation_id, MAX(id) AS nid FROM notifications WHERE reservation_id IS NOT NULL AND status IN ('sent','manual') GROUP BY reservation_id") as $r) {
    $lastSent[(int)$r['reservation_id']] = fetch_one("SELECT channel, status, created_at FROM notifications WHERE id = ?", [$r['nid']]);
}
$ch = notify_channels();
$cfg = reminder_settings();
$lastRun = (int)setting('reminders_last_run', '0');
$typeLabel = ['upcoming' => 'Coming up', 'due_today' => 'Due today', 'overdue' => 'Missing payment', 'balance' => 'Balance left', 'advance' => 'Advance not paid',
              'gcash_approved' => 'GCash received', 'reservation_held' => 'Room held', 'reservation_secured' => 'Bed secured', 'hold_expired' => 'Hold ended', 'moveout_notice' => 'Move-out notice', 'settlement' => 'Move-out summary', 'reservation_cancelled' => 'Cancellation', 'gcash_rejected' => 'GCash not confirmed', 'inquiry_reply' => 'Inquiry reply', 'test' => 'Test'];
$tone = ['overdue' => 'bad', 'balance' => 'warn', 'due_today' => 'warn', 'advance' => 'info', 'upcoming' => 'neutral'];

admin_header('Payment reminders', 'reminders');
page_head('Payment reminders', 'Rent is due one month after move-in, every month. Tenants who are late or have a balance are reminded automatically.',
    '<form method="post" class="inline-form">' . csrf_field() . '<input type="hidden" name="action" value="run"><button class="btn btn-primary" type="submit">Check and send now</button></form>');
?>
<section class="panel">
  <div class="panel-head"><h2>How reminders are delivered</h2><a href="<?= e(url('admin/settings.php?tab=notify')) ?>">Settings</a></div>
  <div class="chan-row">
    <div class="chan <?= $ch['email'] ? 'chan-on' : 'chan-off' ?>"><strong>E-mail</strong><span><?= $ch['email'] ? 'Set up. Sent automatically.' : 'Not set up yet.' ?></span></div>
    <div class="chan chan-hand"><strong>Messenger</strong><span>One tap from the list below (Facebook does not allow automatic messages).</span></div>
  </div>
  <p class="hint">E-mail is sent automatically. When a tenant has no e-mail, or e-mail is not set up or fails, the message is prepared below so you can send it with one tap on Messenger.
     Reminders are <?= $cfg['enabled'] ? '<strong>on</strong>' : '<strong>off</strong>' ?>: <?= (int)$cfg['before'] ?> days before the due date, on the due date, then every <?= (int)$cfg['repeat'] ?> days while unpaid.
     <?= $lastRun > 0 ? 'Last check: ' . e(fmt_datetime(date('Y-m-d H:i:s', $lastRun))) . '.' : 'Not checked yet.' ?></p>
</section>

<section class="panel">
  <div class="panel-head"><h2>Who needs attention</h2></div>
  <?php if ($overview): ?>
  <div class="table-scroll"><table>
    <thead><tr><th>Tenant</th><th>Status</th><th>Due date</th><th class="num">Amount</th><th>Last reminder</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($overview as $c): $st = $c['st']; $res = $c['res']; $ls = $lastSent[(int)$res['id']] ?? null; ?>
      <tr>
        <td><strong><?= e($c['tenant']['full_name']) ?></strong><br><span class="muted small"><a href="<?= e(url('admin/reservation_view.php?id=' . (int)$res['id'])) ?>"><?= e($res['reservation_no']) ?></a> &middot; Room <?= e($res['room_no']) ?></span></td>
        <td><?= badge(state_label($st['state']), $tone[$st['state']] ?? 'neutral') ?>
            <?php if (in_array($st['state'], ['overdue', 'balance'], true)): ?><br><span class="muted small"><?= (int)$st['days'] ?> <?= $st['days'] === 1 ? 'day' : 'days' ?> late</span>
            <?php elseif ($st['state'] === 'upcoming'): ?><br><span class="muted small">in <?= (int)(-$st['days']) ?> <?= -$st['days'] === 1 ? 'day' : 'days' ?></span><?php endif; ?></td>
        <td><?= $st['due'] ? e(fmt_date($st['due'])) : '' ?></td>
        <td class="num"><strong><?= e(money($st['amount'])) ?></strong><?php if (in_array($st['state'], ['overdue', 'balance'], true) && abs($c['fin']['balance'] - $st['amount']) > 0.005): ?><br><span class="muted small">total owed <?= e(money($c['fin']['balance'])) ?></span><?php endif; ?></td>
        <td><?= $ls ? e(['email' => 'E-mailed', 'manual' => ($ls['status'] === 'manual' ? 'Prepared' : 'Sent by hand'), 'messenger' => 'Messenger'][$ls['channel']] ?? $ls['channel']) . '<br><span class="muted small">' . e(fmt_datetime($ls['created_at'])) . '</span>' : '<span class="muted small">None yet</span>' ?></td>
        <td class="actions"><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="send_now"><input type="hidden" name="reservation_id" value="<?= (int)$res['id'] ?>"><button class="btn btn-sm" type="submit">Remind now</button></form></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php else: ?>
    <p class="empty">Everyone is up to date. Nobody is late, owes a balance, or is due soon.</p>
  <?php endif; ?>
</section>

<section class="panel" id="by-hand">
  <div class="panel-head"><h2>To send by hand (<?= count($manual) ?>)</h2></div>
  <?php if ($manual): ?>
    <p class="hint">These could not be sent automatically. Tap a button to open your phone's messaging app with the message already written, then mark it as sent.</p>
    <div class="manual-list">
    <?php foreach ($manual as $n):
        $t = ['contact_no' => $n['contact_no'], 'email' => $n['email'], 'messenger' => $n['messenger']];
        $links = manual_links($n, $t); ?>
      <article class="manual-item">
        <div class="manual-head">
          <div><strong><?= e($n['full_name'] ?? 'Tenant') ?></strong> <?= badge($typeLabel[$n['type']] ?? $n['type'], $tone[$n['type']] ?? 'neutral') ?><br>
            <span class="muted small"><?= e(fmt_datetime($n['created_at'])) ?><?= $n['reservation_no'] ? ' &middot; ' . e($n['reservation_no']) : '' ?><?= $n['error'] ? ' &middot; ' . e($n['error']) : '' ?></span></div>
        </div>
        <details><summary>Read the message</summary><pre class="msg-preview"><?= e($n['body']) ?></pre></details>
        <div class="manual-actions">
          <?php foreach ($links as $l): ?>
            <a class="btn btn-sm" href="<?= e($l['href']) ?>"<?= !empty($l['external']) ? ' target="_blank" rel="noopener"' : '' ?><?= !empty($l['copy']) ? ' data-copy-text="' . e($n['body']) . '"' : '' ?>><?= icon($l['icon']) ?><span><?= e($l['label']) ?></span></a>
          <?php endforeach; ?>
          <?php if (!$links): ?><span class="muted small">No phone, e-mail or Messenger on file. Add one in the tenant's record.</span><?php endif; ?>
          <button type="button" class="btn btn-sm btn-ghost" data-copy-text="<?= e($n['body']) ?>"><?= icon('copy') ?><span>Copy text</span></button>
          <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$n['id'] ?>"><button class="btn btn-sm btn-primary" name="action" value="mark_sent" type="submit">Mark as sent</button>
            <button class="btn btn-sm btn-ghost" name="action" value="dismiss" type="submit">Dismiss</button></form>
        </div>
      </article>
    <?php endforeach; ?>
    </div>
  <?php else: ?>
    <p class="empty">Nothing waiting. Every reminder was sent automatically or handled.</p>
  <?php endif; ?>
</section>

<section class="panel">
  <div class="panel-head"><h2>Recent messages</h2></div>
  <?php if ($log): ?>
  <div class="table-scroll"><table>
    <thead><tr><th>When</th><th>To</th><th>About</th><th>How</th><th>Result</th></tr></thead>
    <tbody>
    <?php foreach ($log as $n): ?>
      <tr>
        <td><?= e(fmt_datetime($n['created_at'])) ?></td>
        <td><?= e($n['full_name'] ?? '') ?><br><span class="muted small"><?= e($n['recipient'] ?? '') ?></span></td>
        <td><?= e($typeLabel[$n['type']] ?? $n['type']) ?><?= $n['reservation_no'] ? '<br><span class="muted small">' . e($n['reservation_no']) . '</span>' : '' ?></td>
        <td><?= e(['email' => 'E-mail', 'sms' => 'Text message (old)', 'messenger' => 'Messenger', 'manual' => 'By hand'][$n['channel']] ?? $n['channel']) ?></td>
        <td><?= badge(['sent' => 'Sent', 'failed' => 'Failed', 'manual' => 'Waiting for you', 'skipped' => 'Dismissed'][$n['status']] ?? $n['status'], ['sent' => 'ok', 'failed' => 'bad', 'manual' => 'warn', 'skipped' => 'neutral'][$n['status']] ?? 'neutral') ?><?= $n['error'] ? '<br><span class="muted small">' . e($n['error']) . '</span>' : '' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php else: ?><p class="empty">No messages yet.</p><?php endif; ?>
</section>
<?php admin_footer();
