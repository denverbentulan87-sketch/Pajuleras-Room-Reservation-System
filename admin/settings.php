<?php
require __DIR__ . '/../includes/bootstrap.php';
$admin = require_admin(true);   // reachable even while the default password must still be changed

$tab = in_array(get_str('tab'), ['business', 'policies', 'notify', 'account'], true) ? get_str('tab') : 'business';
if ((int)$admin['must_change_password'] === 1) {
    $tab = 'account';
}
$errors = [];

function save_setting(string $k, string $v): void
{
    q("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", [$k, $v]);
}

if (is_post()) {
    csrf_verify();
    $form = post_str('form', 12);

    if ($form === 'business' && (int)$admin['must_change_password'] !== 1) {
        $tab = 'business';
        $v = [
            'site_name' => post_str('site_name', 100), 'tagline' => post_str('tagline', 200), 'landlord_name' => post_str('landlord_name', 100),
            'address' => post_str('address', 200), 'phone' => post_str('phone', 40), 'email' => post_str('email', 150),
            'facebook' => post_str('facebook', 200), 'gcash_no' => post_str('gcash_no', 40), 'contact_hours' => post_str('contact_hours', 120),
            'about_text' => post_str('about_text', 1500),
        ];
        if ($v['site_name'] === '') $errors[] = 'The boarding house name is required.';
        if ($v['landlord_name'] === '') $errors[] = 'The landlord name is required (it appears on receipts).';
        if ($v['email'] !== '' && !valid_email($v['email'])) $errors[] = 'The email address does not look right.';
        $newHouse = null;
        if (!$errors) {
            try {
                $newHouse = store_room_photo($_FILES['house_photo'] ?? [], 'house');
            } catch (RuleException $ex) {
                $errors[] = $ex->getMessage();
            }
        }
        if (!$errors) {
            foreach ($v as $k => $val) save_setting($k, $val);
            $oldHouse = setting('house_photo');
            if ($newHouse) {
                save_setting('house_photo', $newHouse);
                delete_room_photo($oldHouse);
            } elseif (isset($_POST['remove_house_photo']) && $oldHouse !== '') {
                save_setting('house_photo', '');
                delete_room_photo($oldHouse);
            }
            log_activity('settings_updated', 'settings', null, 'Boarding house information updated');
            flash('success', 'Boarding house information saved.');
            redirect('admin/settings.php?tab=business');
        }
    } elseif ($form === 'policies' && (int)$admin['must_change_password'] !== 1) {
        $tab = 'policies';
        $adv = post_str('advance_months', 2);
        if (!ctype_digit($adv) || (int)$adv > 12) $errors[] = 'Advance months must be a whole number from 0 to 12.';
        $feeRaw = post_str('reservation_fee', 15);
        $fee = $feeRaw === '' ? 0.0 : parse_money($feeRaw);
        if ($fee === null || $fee < 0 || $fee > 100000) $errors[] = 'The reservation fee must be an amount such as 500 (or empty for none).';
        $holdDays = post_str('hold_days', 2);
        if (!ctype_digit($holdDays) || (int)$holdDays < 1 || (int)$holdDays > 30) $errors[] = 'Hold the bed for a whole number of days from 1 to 30.';
        $noticeDays = post_str('notice_days', 2);
        if (!ctype_digit($noticeDays) || (int)$noticeDays > 90) $errors[] = 'Move-out notice must be a whole number of days from 0 to 90.';
        if (!$errors) {
            save_setting('advance_months', (string)(int)$adv);
            save_setting('reservation_fee', $fee > 0 ? number_format($fee, 2, '.', '') : '0');
            save_setting('hold_days', (string)(int)$holdDays);
            save_setting('notice_days', (string)(int)$noticeDays);
            save_setting('reservation_fee_refundable', isset($_POST['reservation_fee_refundable']) ? '1' : '0');
            foreach (['requirements', 'payment_schedule', 'house_rules'] as $k) save_setting($k, post_str($k, 3000));
            log_activity('settings_updated', 'settings', null, 'Policies updated');
            flash('success', 'Policies saved. They are now shown on the public website.');
            redirect('admin/settings.php?tab=policies');
        }
    } elseif ($form === 'notify' && (int)$admin['must_change_password'] !== 1) {
        $tab = 'notify';
        $before = post_str('reminder_days_before', 2);
        $repeat = post_str('reminder_repeat_days', 2);
        $max = post_str('reminder_max', 2);
        if (!ctype_digit($before) || (int)$before > 14) $errors[] = 'Days before the due date must be a whole number from 0 to 14.';
        if (!ctype_digit($repeat) || (int)$repeat < 1 || (int)$repeat > 30) $errors[] = 'Repeat every must be a whole number of days from 1 to 30.';
        if (!ctype_digit($max) || (int)$max < 1 || (int)$max > 20) $errors[] = 'The most reminders per missed payment must be from 1 to 20.';
        if (!$errors) {
            save_setting('reminders_enabled', isset($_POST['reminders_enabled']) ? '1' : '0');
            save_setting('reminder_days_before', (string)(int)$before);
            save_setting('reminder_repeat_days', (string)(int)$repeat);
            save_setting('reminder_max', (string)(int)$max);
            log_activity('settings_updated', 'settings', null, 'Reminder settings updated');
            flash('success', 'Reminder settings saved.');
            redirect('admin/settings.php?tab=notify');
        }
    } elseif ($form === 'notifytest' && (int)$admin['must_change_password'] !== 1) {
        $tab = 'notify';
        $kind = post_str('kind', 6);
        $to = post_str('to', 150);
        if ($kind === 'email') {
            $err = null;
            if (!mail_configured()) $errors[] = 'E-mail is not set up yet. See the steps below.';
            elseif (!valid_email($to)) $errors[] = 'Type the e-mail address to send the test to.';
            elseif (smtp_send($to, 'Test message from ' . setting('site_name', 'your boarding house'), "This is a test.\n\nIf you can read this, tenants will receive their payment reminders by e-mail.", $err)) {
                flash('success', 'Test e-mail sent to ' . $to . '. Check the inbox (and the spam folder).');
                redirect('admin/settings.php?tab=notify');
            } else $errors[] = 'The test e-mail failed. ' . $err;
        }
    } elseif ($form === 'account') {
        $tab = 'account';
        $username = post_str('username', 50);
        $full = post_str('full_name', 120);
        $email = post_str('email', 150);
        $cur = (string)($_POST['current_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $conf = (string)($_POST['confirm_password'] ?? '');

        if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) $errors[] = 'Username must be 3 to 50 letters, numbers, dots, dashes or underscores.';
        if ($full === '') $errors[] = 'Enter your name.';
        if ($email !== '' && !valid_email($email)) $errors[] = 'The email address does not look right.';

        $row = fetch_one("SELECT password_hash FROM admin WHERE id = 1");
        $changing = $new !== '' || $conf !== '' || (int)$admin['must_change_password'] === 1;
        if ($changing || $username !== $admin['username']) {
            // changing the password or the username needs the current password
            if (!password_verify($cur, $row['password_hash'])) $errors[] = 'Your current password is not correct.';
        }
        if ($changing) {
            if ($new === '') $errors[] = 'Enter a new password.';
            elseif ($problems = password_problems($new)) $errors[] = 'Your new password needs ' . implode(', ', $problems) . '.';
            if ($new !== $conf) $errors[] = 'The new password and its confirmation do not match.';
            if ($new !== '' && hash_equals($cur, $new)) $errors[] = 'Choose a password different from the current one.';
        }
        if (!$errors) {
            if ($changing) {
                q("UPDATE admin SET username = ?, full_name = ?, email = ?, password_hash = ?, must_change_password = 0 WHERE id = 1",
                  [$username, $full, $email ?: null, password_hash($new, PASSWORD_DEFAULT)]);
                session_regenerate_id(true);
                log_activity('password_changed', 'admin', 1, 'Password changed');
                flash('success', 'Your password was changed.');
            } else {
                q("UPDATE admin SET username = ?, full_name = ?, email = ? WHERE id = 1", [$username, $full, $email ?: null]);
                log_activity('account_updated', 'admin', 1, 'Account details updated');
                flash('success', 'Account details saved.');
            }
            redirect('admin/settings.php?tab=account');
        }
        $admin['username'] = $username; $admin['full_name'] = $full; $admin['email'] = $email;
    }
}

$GLOBALS['ADMIN'] = $admin;
$S = settings_all();
$OLD = $_SERVER['REQUEST_METHOD'] === 'POST' && $errors ? array_merge($S, $_POST) : $S;

admin_header('Settings', 'settings');
page_head('Settings', 'Manage what customers see, and your sign-in details.');
$must = (int)$admin['must_change_password'] === 1;
?>
<?php if ($must): ?><div class="flash flash-warn"><span>You are using the default password. Choose a new one below to unlock the rest of the system.</span></div><?php endif; ?>
<section class="panel">
  <div class="tabs" role="navigation" aria-label="Settings">
    <?php if (!$must): ?>
      <a href="<?= e(url('admin/settings.php?tab=business')) ?>"<?= $tab === 'business' ? ' class="active"' : '' ?>>Boarding house</a>
      <a href="<?= e(url('admin/settings.php?tab=policies')) ?>"<?= $tab === 'policies' ? ' class="active"' : '' ?>>Rates, requirements &amp; rules</a>
    <?php endif; ?>
    <?php if (!$must): ?><a href="<?= e(url('admin/settings.php?tab=notify')) ?>"<?= $tab === 'notify' ? ' class="active"' : '' ?>>Reminders &amp; notifications</a><?php endif; ?>
    <a href="<?= e(url('admin/settings.php?tab=account')) ?>"<?= $tab === 'account' ? ' class="active"' : '' ?>>My account</a>
  </div>

  <?php render_errors($errors); ?>

  <?php if ($tab === 'business'): ?>
  <form class="form" method="post" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?><input type="hidden" name="form" value="business">
    <div class="row-2">
      <label>Boarding house name <span class="req">*</span><input type="text" name="site_name" value="<?= e($OLD['site_name'] ?? '') ?>" maxlength="100" required></label>
      <label>Landlord name <span class="req">*</span><input type="text" name="landlord_name" value="<?= e($OLD['landlord_name'] ?? '') ?>" maxlength="100" required></label>
    </div>
    <label>Short description (shown under the name)<input type="text" name="tagline" value="<?= e($OLD['tagline'] ?? '') ?>" maxlength="200"></label>
    <label>Address<input type="text" name="address" value="<?= e($OLD['address'] ?? '') ?>" maxlength="200"></label>
    <div class="row-3">
      <label>Phone / text number<input type="text" name="phone" value="<?= e($OLD['phone'] ?? '') ?>" maxlength="40"></label>
      <label>Email<input type="email" name="email" value="<?= e($OLD['email'] ?? '') ?>" maxlength="150"></label>
      <label>GCash number<input type="text" name="gcash_no" value="<?= e($OLD['gcash_no'] ?? '') ?>" maxlength="40"><span class="hint">Tenants see this in the "Pay with GCash" pop-up.</span></label>
    </div>
    <div class="row-2">
      <label>Hours you answer messages<input type="text" name="contact_hours" value="<?= e($OLD['contact_hours'] ?? '') ?>" maxlength="120"></label>
      <label>Facebook page link <span class="opt">(optional)</span><input type="text" name="facebook" value="<?= e($OLD['facebook'] ?? '') ?>" maxlength="200"></label>
    </div>
    <label>About the boarding house (shown on the home page)<textarea name="about_text" rows="5" maxlength="1500"><?= e($OLD['about_text'] ?? '') ?></textarea></label>
    <div class="photo-field">
      <label>Boarding house photo <span class="opt">(JPG, PNG or WebP, up to 3 MB)</span>
        <input type="file" name="house_photo" accept="image/jpeg,image/png,image/webp">
        <span class="hint">Shown on the home page. Without a photo, the website shows a house illustration.</span>
      </label>
      <?php $hp = house_photo_url(); if ($hp !== ''): ?>
        <div class="photo-current">
          <img src="<?= e($hp) ?>" alt="Current boarding house photo" width="200">
          <label class="check"><input type="checkbox" name="remove_house_photo" value="1"> Remove this photo</label>
        </div>
      <?php endif; ?>
    </div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Save changes</button></div>
  </form>

  <?php elseif ($tab === 'policies'): ?>
  <form class="form" method="post" novalidate>
    <?= csrf_field() ?><input type="hidden" name="form" value="policies">
    <label class="narrow-field">Advance payment (months of rent)
      <input type="number" name="advance_months" value="<?= e($OLD['advance_months'] ?? '1') ?>" min="0" max="12">
      <span class="hint">Used as the default advance when you create a reservation. You can still change it per reservation.</span>
    </label>
    <fieldset class="rules-box">
      <legend>How reserving works</legend>
      <p class="hint">Most boarding houses hold a bed for a few days while a small reservation fee is paid, then collect the deposit and the rest of the first month at move-in. These settings drive the whole process: the hold, the deadline, the automatic release, and the move-out notice.</p>
      <div class="row-3">
        <label>Reservation fee (&#8369;)<input type="text" name="reservation_fee" inputmode="decimal" value="<?= e((string)(lifecycle_settings()['fee'] > 0 ? lifecycle_settings()['fee'] : '')) ?>" placeholder="empty = the full advance"><span class="hint">Counts toward the first month. Empty means the tenant pays the whole advance to hold the bed.</span></label>
        <label>Hold the bed for (days)<input type="number" name="hold_days" min="1" max="30" value="<?= (int)lifecycle_settings()['hold_days'] ?>" required><span class="hint">If the fee is not paid by then, the bed is released automatically.</span></label>
        <label>Move-out notice (days)<input type="number" name="notice_days" min="0" max="90" value="<?= (int)lifecycle_settings()['notice_days'] ?>" required><span class="hint">The notice tenants should give before leaving.</span></label>
      </div>
      <label class="check"><input type="checkbox" name="reservation_fee_refundable" value="1"<?= lifecycle_settings()['fee_refundable'] ? ' checked' : '' ?>> <span>The reservation fee is refundable if a reservation is cancelled <span class="hint">(unticked: it is kept by default, and you can still change the amount for each cancellation)</span></span></label>
    </fieldset>
    <label>Requirements for new tenants <span class="opt">(one per line)</span><textarea name="requirements" rows="5" maxlength="3000"><?= e($OLD['requirements'] ?? '') ?></textarea></label>
    <label>Payment schedule and terms <span class="opt">(one per line)</span><textarea name="payment_schedule" rows="5" maxlength="3000"><?= e($OLD['payment_schedule'] ?? '') ?></textarea></label>
    <label>House rules <span class="opt">(one per line)</span><textarea name="house_rules" rows="7" maxlength="3000"><?= e($OLD['house_rules'] ?? '') ?></textarea></label>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Save policies</button></div>
  </form>

  <?php elseif ($tab === 'notify'): $rc = reminder_settings(); $ch = notify_channels(); $base = public_base_url(); ?>
  <form class="form" method="post" novalidate>
    <?= csrf_field() ?><input type="hidden" name="form" value="notify">
    <label class="check"><input type="checkbox" name="reminders_enabled" value="1"<?= $rc['enabled'] ? ' checked' : '' ?>> <span><strong>Send payment reminders automatically</strong><br><span class="hint">Tenants are reminded when rent is coming up, due today, missing, or only partly paid.</span></span></label>
    <div class="row-3">
      <label>Remind this many days before the due date<input type="number" name="reminder_days_before" value="<?= (int)$rc['before'] ?>" min="0" max="14" required></label>
      <label>While unpaid, repeat every (days)<input type="number" name="reminder_repeat_days" value="<?= (int)$rc['repeat'] ?>" min="1" max="30" required></label>
      <label>Stop after this many reminders<input type="number" name="reminder_max" value="<?= (int)$rc['max'] ?>" min="1" max="20" required></label>
    </div>
    <p class="hint">Rent is due one month after move-in, then every month on the same day. A reminder is never sent twice for the same due date.</p>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Save</button></div>
  </form>

  <h3 class="form-sub">How messages are sent</h3>
  <div class="chan-row">
    <div class="chan <?= $ch['email'] ? 'chan-on' : 'chan-off' ?>"><strong>E-mail</strong><span><?= $ch['email'] ? 'Set up' : 'Not set up' ?></span></div>
    <div class="chan chan-hand"><strong>Messenger</strong><span>One tap from Admin &gt; Payment reminders</span></div>
  </div>
  <form class="form test-form" method="post" novalidate>
    <?= csrf_field() ?><input type="hidden" name="form" value="notifytest">
    <div class="row-2">
      <label>Send a test to<input type="text" name="to" placeholder="your e-mail address" maxlength="150"></label>
      <div class="form-actions"><button class="btn" type="submit" name="kind" value="email"<?= $ch['email'] ? '' : ' disabled' ?>>Test e-mail</button></div>
    </div>
  </form>
  <details class="howto"><summary>How to set up e-mail and Messenger</summary>
    <p>For safety, the passwords and keys are kept in the file <code>config/config.php</code> (not in the database). Open it with Notepad, fill in the details below, save, then press the test button above.</p>
    <ol>
      <li><strong>E-mail (free):</strong> use a Gmail account. Turn on 2-step verification, create an <em>App password</em> (Google Account &gt; Security), then in <code>'mail'</code> set <code>host</code> = <code>smtp.gmail.com</code>, <code>port</code> = <code>587</code>, <code>secure</code> = <code>tls</code>, <code>user</code> = your Gmail address and <code>pass</code> = the app password.</li>
      <li><strong>Messenger:</strong> save each tenant's Messenger username on their tenant record. Facebook does not allow automatic messages without an approved app, so you send it with one tap from <em>Payment reminders</em>.</li>
    </ol>
    <p><strong>Make it run every day, even when nobody opens the website:</strong></p>
    <ul>
      <li><strong>Windows (XAMPP):</strong> Task Scheduler &gt; Create Basic Task &gt; Daily &gt; Start a program: <code>C:\xampp\php\php.exe</code> with the argument <code><?= e(APP_ROOT) ?>\cron\reminders.php</code>.</li>
      <li><strong>Web hosting (cPanel cron) or an uptime service:</strong> open this address once a day: <code><?= e(($base !== '' ? $base : 'https://your-site') . '/cron_run.php?token=' . cron_token()) ?></code> (keep it private).</li>
      <li>Without either, the website also checks by itself every 6 hours when someone opens the home page or you open the dashboard.</li>
    </ul>
  </details>

  <?php else: ?>
  <form class="form" method="post" novalidate autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="form" value="account">
    <div class="row-3">
      <label>Username<input type="text" name="username" value="<?= e($admin['username']) ?>" maxlength="50" required autocomplete="username"></label>
      <label>Your name<input type="text" name="full_name" value="<?= e($admin['full_name']) ?>" maxlength="120" required></label>
      <label>Email <span class="opt">(optional)</span><input type="email" name="email" value="<?= e($admin['email'] ?? '') ?>" maxlength="150"></label>
    </div>
    <h3 class="form-sub"><?= $must ? 'Choose a new password' : 'Change password' ?> <?= $must ? '' : '<span class="opt">(leave blank to keep the current one)</span>' ?></h3>
    <div class="row-3">
      <label>Current password<input type="password" name="current_password" autocomplete="current-password"<?= $must ? ' required' : '' ?>></label>
      <label>New password<input type="password" name="new_password" autocomplete="new-password"<?= $must ? ' required' : '' ?>></label>
      <label>Confirm new password<input type="password" name="confirm_password" autocomplete="new-password"<?= $must ? ' required' : '' ?>></label>
    </div>
    <p class="hint">At least 8 characters with a letter and a number. There is only one administrator account; nobody else can be added.</p>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Save</button></div>
  </form>
  <?php endif; ?>
</section>
<?php admin_footer();
