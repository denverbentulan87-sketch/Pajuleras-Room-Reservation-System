<?php
require __DIR__ . '/includes/bootstrap.php';

/** Human-friendly reference code, e.g. K7M2-9QXA (no 0/O/1/I/L to avoid mix-ups). */
function new_ref_code(): string
{
    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    for ($try = 0; $try < 20; $try++) {
        $c = '';
        for ($i = 0; $i < 8; $i++) {
            $c .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $c = substr($c, 0, 4) . '-' . substr($c, 4);
        if (!fetch_val("SELECT 1 FROM inquiries WHERE ref_code = ?", [$c])) {
            return $c;
        }
    }
    throw new RuntimeException('Could not create a reference code.');
}

$rooms = rooms_with_status(true);
$errors = [];
$OLD = [
    'name' => '', 'contact_no' => '', 'email' => '', 'occupation' => '', 'gender' => '', 'school' => '', 'messenger' => '', 'consent' => '',
    'room_id' => get_int('room') ?: '', 'move_in' => valid_date(get_str('date', 10)) ? get_str('date', 10) : '', 'message' => '',
];

$ajax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
/** Reply to the pop-up form with JSON instead of a page. */
function inquiry_json(array $data, int $code = 200): never
{
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (is_post()) {
    if ($ajax) {
        $sent = (string)($_POST['_csrf'] ?? '');
        if ($sent === '' || !hash_equals(csrf_token(), $sent)) {
            inquiry_json(['ok' => false, 'errors' => ['Your session expired. Please refresh the page and try again.']], 419);
        }
    } else {
        csrf_verify();
    }
    $OLD = [
        'name' => post_str('name', 120), 'contact_no' => post_str('contact_no', 30), 'email' => post_str('email', 150),
        'occupation' => post_str('occupation', 20), 'room_id' => post_int('room_id') ?: '', 'move_in' => post_str('move_in', 10),
        'message' => post_str('message', 2000),
        'gender' => post_str('gender', 10), 'school' => post_str('school', 150), 'messenger' => post_str('messenger', 120), 'consent' => post_str('consent', 3),
    ];

    if (post_str('website', 100) !== '') {           // honeypot: real people never fill this in
        if ($ajax) {
            inquiry_json(['ok' => true, 'ref' => '']);
        }
        $_SESSION['inq_done'] = ['ref' => 'PBH', 'auto' => null];
        redirect('inquire.php?sent=1');
    }
    if (throttle_count('inquiry', 60) >= (int)cfg('max_inquiries_per_hour', 5)) {
        $errors[] = 'You have sent several inquiries in the last hour. Please wait a while, or call the landlord directly.';
    }

    $phone = normalize_phone($OLD['contact_no']);
    if (mb_strlen($OLD['name']) < 2) $errors[] = 'Please enter your full name.';
    if (!valid_phone($phone)) $errors[] = 'Please enter a valid contact number (digits only, for example 09123456789).';
    if ($OLD['email'] !== '' && !valid_email($OLD['email'])) $errors[] = 'The email address does not look right. Leave it blank if you do not have one.';
    if (!in_array($OLD['occupation'], ['student', 'employee', 'other', ''], true)) $errors[] = 'Please choose one of the options for "I am a".';
    if (!in_array($OLD['gender'], ['male', 'female'], true)) $errors[] = 'Please choose your gender. Some rooms are for men only or women only.';
    if ($OLD['consent'] !== '1') $errors[] = 'Please tick the box to agree that the boarding house may keep your details to answer your inquiry.';
    $roomId = null;
    if ($OLD['room_id'] !== '') {
        $roomId = (int)fetch_val("SELECT id FROM rooms WHERE id = ? AND is_public = 1", [(int)$OLD['room_id']]);
        if (!$roomId) $errors[] = 'That room is not available for inquiries. Please pick another or choose "Not sure yet".';
    }
    $moveIn = null;
    if ($OLD['move_in'] !== '') {
        if (!valid_date($OLD['move_in']))       $errors[] = 'Please pick a valid move-in date.';
        elseif ($OLD['move_in'] < today())      $errors[] = 'The move-in date cannot be in the past.';
        else                                    $moveIn = $OLD['move_in'];
    }
    if (mb_strlen($OLD['message']) < 10) $errors[] = 'Please write a short message (at least 10 characters) so the landlord knows what you need.';

    if ($errors && $ajax) {
        inquiry_json(['ok' => false, 'errors' => $errors]);
    }
    if (!$errors) {
        $roomNo = $roomId ? (string)fetch_val("SELECT room_no FROM rooms WHERE id = ?", [$roomId]) : '';
        $subject = $roomNo !== '' ? 'Inquiry about Room ' . $roomNo : 'Room inquiry';
        $result = in_transaction(function () use ($OLD, $phone, $roomId, $moveIn, $subject) {
            $ref = new_ref_code();
            $email = $OLD['email'] !== '' ? mb_strtolower($OLD['email']) : null;
            // 1. register the person as a tenant from what they typed (or match the existing tenant)
            $tenantId = inquiry_register_tenant(['name' => $OLD['name'], 'gender' => $OLD['gender'], 'contact_no' => $phone, 'email' => $email,
                                                 'occupation' => $OLD['occupation'], 'school' => $OLD['school'], 'messenger' => (string)messenger_handle($OLD['messenger'])]);
            // 2. record the inquiry
            q("INSERT INTO inquiries (ref_code, name, contact_no, email, room_id, preferred_move_in, occupation, gender, school_or_workplace, tenant_id, consent_at, subject, status, admin_unread, ip)
               VALUES (?,?,?,?,?,?,?,?,?,?, NOW(), ?, 'new', 1, ?)",
              [$ref, $OLD['name'], $phone, $email, $roomId, $moveIn, $OLD['occupation'] !== '' ? $OLD['occupation'] : null,
               $OLD['gender'], $OLD['school'] !== '' ? $OLD['school'] : null, $tenantId, $subject, client_ip()]);
            $id = (int)db()->lastInsertId();
            q("INSERT INTO inquiry_messages (inquiry_id, sender, body) VALUES (?, 'customer', ?)", [$id, $OLD['message']]);
            // 3. automatic reply: is the room open, or full?
            $auto = inquiry_auto_reply($roomId, $moveIn, $OLD['gender'], $OLD['name'], $ref);
            q("INSERT INTO inquiry_messages (inquiry_id, sender, body) VALUES (?, 'system', ?)", [$id, $auto['text']]);
            q("UPDATE inquiries SET auto_result = ? WHERE id = ?", [$auto['result'], $id]);
            return ['ref' => $ref, 'id' => $id, 'auto' => $auto, 'tenant_id' => $tenantId, 'email' => $email];
        });
        throttle_hit('inquiry');
        // 4. copy of the automatic reply by e-mail, when the person gave an address and e-mail is set up
        try {
            if ($result['email']) {
                notify_tenant(['id' => $result['tenant_id'], 'email' => $result['email'], 'contact_no' => $phone], 'inquiry_reply',
                    ['subject' => $result['auto']['title'] . ' (' . $result['ref'] . ')', 'body' => $result['auto']['text'] . "\n\nTo read replies and send follow-ups, open Check my inquiry on our website and enter code " . $result['ref'] . ' with your contact number.'],
                    ['inquiry_id' => $result['id'], 'dedupe' => 'inq' . $result['id'], 'auto_only' => true]);
            }
        } catch (Throwable $e) {
            error_log('[PBH] inquiry e-mail failed: ' . $e->getMessage());
        }
        $payload = ['ref' => $result['ref'], 'auto' => ['result' => $result['auto']['result'], 'title' => $result['auto']['title'], 'text' => $result['auto']['text']]];
        if ($ajax) {
            inquiry_json(['ok' => true] + $payload);
        }
        $_SESSION['inq_done'] = $payload;
        redirect('inquire.php?sent=1');
    }
}

/* ---- confirmation screen -------------------------------------- */
if (get_str('sent') === '1' && isset($_SESSION['inq_done'])) {
    $done = $_SESSION['inq_done'];
    $ref = is_array($done) ? (string)$done['ref'] : (string)$done;
    $auto = is_array($done) ? $done['auto'] : null;
    unset($_SESSION['inq_done']);
    public_header('Inquiry sent', 'inquire');
    ?>
    <section class="section"><div class="wrap narrow">
      <div class="success-box">
        <h1>Your inquiry was sent</h1>
        <?php if ($auto): ?>
          <div class="auto-reply auto-<?= e($auto['result']) ?>">
            <strong><?= e($auto['title']) ?></strong>
            <div><?= nl2p($auto['text']) ?></div>
          </div>
        <?php endif; ?>
        <p>The landlord will read your message and reply as soon as possible.</p>
        <?php if ($ref !== 'PBH'): ?>
        <div class="code-box">
          <span>Your inquiry code</span>
          <strong class="code"><?= e($ref) ?></strong>
        </div>
        <p><strong>Write this code down.</strong> Use it together with the contact number you gave to read the landlord's reply and send follow-up messages.</p>
        <p class="hero-actions"><a class="btn btn-primary" href="<?= e(url('track.php')) ?>">Check my inquiry</a><a class="btn" href="<?= e(url('rooms.php')) ?>">Back to rooms</a></p>
        <?php else: ?>
        <p class="hero-actions"><a class="btn" href="<?= e(url('rooms.php')) ?>">Back to rooms</a></p>
        <?php endif; ?>
      </div>
    </div></section>
    <?php
    public_footer();
    exit;
}

public_header('Send an inquiry', 'inquire', 'Ask the landlord about rooms, rates and requirements at Pajuleras Boarding House.');
?>
<section class="page-title">
  <div class="wrap">
    <h1>Send an inquiry</h1>
    <p>Ask about rooms, rates, or requirements. The landlord replies personally, usually within a day.</p>
  </div>
</section>
<section class="section tight">
  <div class="wrap form-layout">
    <form class="card form" method="post" action="<?= e(url('inquire.php')) ?>" novalidate>
      <?= csrf_field() ?>
      <?php render_errors($errors); ?>
      <div class="hp" aria-hidden="true"><label>Leave this empty <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

      <div class="row-2">
        <label>Your full name <span class="req">*</span>
          <input type="text" name="name" value="<?= e(old('name')) ?>" maxlength="120" required autocomplete="name">
        </label>
        <label>Contact number <span class="req">*</span>
          <input type="tel" name="contact_no" value="<?= e(old('contact_no')) ?>" maxlength="30" required inputmode="tel" placeholder="09123456789" autocomplete="tel">
        </label>
      </div>
      <div class="row-2">
        <label>Email <span class="opt">(optional)</span>
          <input type="email" name="email" value="<?= e(old('email')) ?>" maxlength="150" autocomplete="email">
        </label>
        <label>I am a
          <select name="occupation">
            <option value="">Choose one</option>
            <option value="student"<?= selected(old('occupation'), 'student') ?>>Student</option>
            <option value="employee"<?= selected(old('occupation'), 'employee') ?>>Employee</option>
            <option value="other"<?= selected(old('occupation'), 'other') ?>>Other</option>
          </select>
        </label>
      </div>
      <div class="row-2">
        <label>Gender <span class="req">*</span>
          <select name="gender" required>
            <option value="">Choose one</option>
            <option value="female"<?= selected(old('gender'), 'female') ?>>Female</option>
            <option value="male"<?= selected(old('gender'), 'male') ?>>Male</option>
          </select>
        </label>
        <label>School or workplace <span class="opt">(optional)</span>
          <input type="text" name="school" value="<?= e(old('school')) ?>" maxlength="150" autocomplete="organization">
        </label>
      </div>
      <label>Messenger name <span class="opt">(optional, so the landlord can message you about payments)</span>
        <input type="text" name="messenger" value="<?= e(old('messenger')) ?>" maxlength="120" placeholder="e.g. juan.delacruz or your Facebook link" autocomplete="off">
      </label>
      <div class="row-2">
        <label>Room you are interested in
          <select name="room_id">
            <option value="">Not sure yet</option>
            <?php foreach ($rooms as $r): ?>
              <option value="<?= (int)$r['id'] ?>"<?= selected(old('room_id'), $r['id']) ?>><?= e(room_title($r)) ?> (<?= e($r['status_label']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Expected move-in date <span class="opt">(optional)</span>
          <input type="date" name="move_in" value="<?= e(old('move_in')) ?>" min="<?= e(today()) ?>">
        </label>
      </div>
      <label>Your message <span class="req">*</span>
        <textarea name="message" rows="6" maxlength="2000" required placeholder="For example: I am a first-year student and need a bed space starting June. What is the monthly rent and what do I need to bring?"><?= e(old('message')) ?></textarea>
      </label>
      <label class="check consent"><input type="checkbox" name="consent" value="1"<?= old('consent') === '1' ? ' checked' : '' ?> required>
        <span>I agree that <?= e(setting('site_name', 'the boarding house')) ?> may keep the details I typed here (name, contact number, e-mail, gender, school or workplace) to answer my inquiry and to register me if I rent a room. I can ask the landlord to erase them.</span></label>
      <button class="btn btn-primary btn-lg" type="submit">Send inquiry</button>
    </form>

    <aside class="card side-note">
      <h2>What happens next</h2>
      <ol class="mini-steps">
        <li>You get an inquiry code and an instant reply that says if the room is open or full.</li>
        <li>The landlord reads your message and replies personally.</li>
        <li>Use your code and contact number on the <a href="<?= e(url('track.php')) ?>">Check my inquiry</a> page to read the reply.</li>
      </ol>
      <?php if (setting('phone') !== ''): ?>
        <p class="side-contact">Prefer to talk? Call or text<br><strong><?= e(setting('phone')) ?></strong><br><small><?= e(setting('contact_hours')) ?></small></p>
      <?php endif; ?>
    </aside>
  </div>
</section>
<?php public_footer();
