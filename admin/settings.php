<?php
require __DIR__ . '/../includes/bootstrap.php';
$admin = require_admin(true);   // reachable even while the default password must still be changed

$tab = in_array(get_str('tab'), ['business', 'policies', 'account'], true) ? get_str('tab') : 'business';
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
        if (!$errors) {
            save_setting('advance_months', (string)(int)$adv);
            foreach (['requirements', 'payment_schedule', 'house_rules'] as $k) save_setting($k, post_str($k, 3000));
            log_activity('settings_updated', 'settings', null, 'Policies updated');
            flash('success', 'Policies saved. They are now shown on the public website.');
            redirect('admin/settings.php?tab=policies');
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
    <label>Requirements for new tenants <span class="opt">(one per line)</span><textarea name="requirements" rows="5" maxlength="3000"><?= e($OLD['requirements'] ?? '') ?></textarea></label>
    <label>Payment schedule and terms <span class="opt">(one per line)</span><textarea name="payment_schedule" rows="5" maxlength="3000"><?= e($OLD['payment_schedule'] ?? '') ?></textarea></label>
    <label>House rules <span class="opt">(one per line)</span><textarea name="house_rules" rows="7" maxlength="3000"><?= e($OLD['house_rules'] ?? '') ?></textarea></label>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Save policies</button></div>
  </form>

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
