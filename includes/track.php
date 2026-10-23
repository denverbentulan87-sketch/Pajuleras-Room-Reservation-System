<?php
declare(strict_types=1);

/* Shared by the "Check my inquiry" page (track.php) and its pop-up. */

const TRACK_MINUTES = 30;
const MAX_MESSAGES_PER_INQUIRY = 40;

function normalize_ref(string $s): string
{
    $s = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $s) ?? '');
    return strlen($s) === 8 ? substr($s, 0, 4) . '-' . substr($s, 4) : $s;
}

function tracked_inquiry(): ?array
{
    $id = (int)($_SESSION['track_id'] ?? 0);
    $ts = (int)($_SESSION['track_time'] ?? 0);
    if (!$id || (time() - $ts) > TRACK_MINUTES * 60) {
        unset($_SESSION['track_id'], $_SESSION['track_time']);
        return null;
    }
    try {
        return fetch_one("SELECT i.*, r.room_no FROM inquiries i LEFT JOIN rooms r ON r.id = i.room_id WHERE i.id = ?", [$id]);
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * The conversation: status, messages and the follow-up form.
 * $modal = true adds a heading and marks the forms so the pop-up can send them without reloading.
 */
function track_thread_html(array $inq, array $errors = [], bool $modal = false): string
{
    $messages = fetch_all("SELECT * FROM inquiry_messages WHERE inquiry_id = ? ORDER BY created_at, id", [$inq['id']]);
    $label = ['new' => 'Waiting for the landlord\'s reply', 'replied' => 'The landlord has replied', 'converted' => 'A reservation was recorded for you', 'closed' => 'Closed'][$inq['status']] ?? $inq['status'];
    $attr = $modal ? ' data-track-form' : '';
    $action = e(url('track.php'));
    ob_start();
    if ($modal): ?>
<p class="pbh-eyebrow"><?= icon('inbox') ?> Your inquiry</p>
<h2 id="trk-title">Your inquiry</h2>
<p class="muted pbh-lead"><?= e($inq['subject']) ?> &middot; code <strong class="code-inline"><?= e($inq['ref_code']) ?></strong></p>
<?php endif; ?>
<div class="thread-head">
  <span>Status: <strong><?= e($label) ?></strong></span>
  <form method="post" action="<?= $action ?>"<?= $attr ?>><?= csrf_field() ?><input type="hidden" name="action" value="leave"><button class="btn btn-sm btn-ghost" type="submit">Close this view</button></form>
</div>
<?php render_errors($errors); ?>
<div class="thread" aria-label="Messages">
  <?php foreach ($messages as $m): ?>
    <div class="msg msg-<?= e($m['sender']) ?>">
      <div class="msg-meta"><strong><?= $m['sender'] === 'admin' ? 'Landlord' : ($m['sender'] === 'system' ? 'Automatic reply' : 'You') ?></strong> <span><?= e(fmt_datetime($m['created_at'])) ?></span></div>
      <div class="msg-body"><?= nl2p($m['body']) ?></div>
    </div>
  <?php endforeach; ?>
</div>
<?php if ($inq['status'] !== 'closed'): ?>
<form class="<?= $modal ? 'form pbh-form' : 'card form' ?>" method="post" action="<?= $action ?>"<?= $attr ?> novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="reply">
  <label>Send a follow-up message
    <textarea name="body" rows="4" maxlength="2000" required></textarea>
  </label>
  <button class="btn btn-primary" type="submit" data-submit><span>Send message</span></button>
</form>
<?php else: ?>
  <p class="empty-box">This inquiry is closed. <a href="<?= e(url('inquire.php')) ?>">Send a new inquiry</a> if you still need a room.</p>
<?php endif;
    return (string)ob_get_clean();
}
