<?php
require __DIR__ . '/includes/bootstrap.php';

$errors = [];
$ajax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
/** Reply to the pop-up with JSON instead of a page. */
function track_json(array $data, int $code = 200): never
{
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
$REF = '';

if (is_post()) {
    if ($ajax) {
        $sent = (string)($_POST['_csrf'] ?? '');
        if ($sent === '' || !hash_equals(csrf_token(), $sent)) {
            track_json(['ok' => false, 'errors' => ['Your session expired. Please refresh the page and try again.']], 419);
        }
    } else {
        csrf_verify();
    }
    $action = post_str('action', 20);

    if ($action === 'lookup') {
        $REF = post_str('ref', 30);
        $contact = post_str('contact', 150);
        if (throttle_count('track', 15) >= (int)cfg('max_track_failures', 8)) {
            $errors[] = 'Too many unsuccessful tries. Please wait 15 minutes, or call the landlord.';
        } else {
            $inq = fetch_one("SELECT * FROM inquiries WHERE ref_code = ?", [normalize_ref($REF)]);
            $ok = false;
            if ($inq && $contact !== '') {
                $ok = (normalize_phone($contact) !== '' && normalize_phone($contact) === $inq['contact_no'])
                   || ($inq['email'] && mb_strtolower($contact) === mb_strtolower((string)$inq['email']));
            }
            if ($ok) {
                session_regenerate_id(true);
                $_SESSION['track_id'] = (int)$inq['id'];
                $_SESSION['track_time'] = time();
                throttle_clear('track');
                if ($ajax) {
                    track_json(['ok' => true, 'html' => track_thread_html((array)tracked_inquiry(), [], true)]);
                }
                redirect('track.php');
            }
            throttle_hit('track');
            $errors[] = 'We could not find an inquiry with that code and contact number. Check them and try again.';
        }
    } elseif ($action === 'reply') {
        $inq = tracked_inquiry();
        if (!$inq) {
            if ($ajax) {
                track_json(['ok' => false, 'expired' => true, 'errors' => ['Your session timed out. Please enter your code again.']]);
            }
            flash('warn', 'Your session timed out. Please enter your code again.');
            redirect('track.php');
        }
        $body = post_str('body', 2000);
        $count = (int)fetch_val("SELECT COUNT(*) FROM inquiry_messages WHERE inquiry_id = ?", [$inq['id']]);
        $recent = (int)fetch_val("SELECT COUNT(*) FROM inquiry_messages WHERE inquiry_id = ? AND sender = 'customer' AND created_at > (NOW() - INTERVAL 1 HOUR)", [$inq['id']]);
        if ($inq['status'] === 'closed') {
            $errors[] = 'This inquiry is closed. Please send a new inquiry.';
        } elseif (mb_strlen($body) < 2) {
            $errors[] = 'Please write your message.';
        } elseif ($count >= MAX_MESSAGES_PER_INQUIRY || $recent >= 8) {
            $errors[] = 'You have sent many messages already. Please wait for the landlord to reply, or call the landlord.';
        } else {
            in_transaction(function () use ($inq, $body) {
                q("INSERT INTO inquiry_messages (inquiry_id, sender, body) VALUES (?, 'customer', ?)", [$inq['id'], $body]);
                q("UPDATE inquiries SET admin_unread = 1, status = CASE WHEN status = 'replied' THEN 'new' ELSE status END WHERE id = ?", [$inq['id']]);
            });
            $_SESSION['track_time'] = time();
            if ($ajax) {
                track_json(['ok' => true, 'notice' => 'Your message was sent to the landlord.', 'html' => track_thread_html((array)tracked_inquiry(), [], true)]);
            }
            flash('success', 'Your message was sent to the landlord.');
            redirect('track.php');
        }
    } elseif ($action === 'leave') {
        unset($_SESSION['track_id'], $_SESSION['track_time']);
        if ($ajax) {
            track_json(['ok' => true, 'left' => true]);
        }
        redirect('track.php');
    }
    if ($ajax && $errors) {
        track_json(['ok' => false, 'errors' => $errors]);
    }
}

$inq = tracked_inquiry();
public_header('Check my inquiry', 'track', 'Read the landlord\'s reply to your inquiry.');

if (!$inq): ?>
<section class="page-title">
  <div class="wrap">
    <h1>Check my inquiry</h1>
    <p>Enter the code you received when you sent your inquiry, plus the contact number (or email) you used.</p>
  </div>
</section>
<section class="section tight">
  <div class="wrap narrow">
    <form class="card form" method="post" action="<?= e(url('track.php')) ?>" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="lookup">
      <?php render_errors($errors); ?>
      <label>Inquiry code
        <input type="text" name="ref" value="<?= e($REF) ?>" maxlength="30" required placeholder="K7M2-9QXA" autocomplete="off" autocapitalize="characters" spellcheck="false">
      </label>
      <label>Contact number or email you used
        <input type="text" name="contact" maxlength="150" required autocomplete="off">
      </label>
      <button class="btn btn-primary btn-lg" type="submit">Show my inquiry</button>
      <p class="muted small">Lost your code? Call the landlord<?= setting('phone') !== '' ? ' at ' . e(setting('phone')) : '' ?> or send a new inquiry.</p>
    </form>
  </div>
</section>
<?php else: ?>
<section class="page-title">
  <div class="wrap">
    <h1>Your inquiry</h1>
    <p><?= e($inq['subject']) ?> &middot; code <strong class="code-inline"><?= e($inq['ref_code']) ?></strong></p>
  </div>
</section>
<section class="section tight">
  <div class="wrap narrow">
    <?= track_thread_html($inq, $errors) ?>
  </div>
</section>
<?php endif;
public_footer();
