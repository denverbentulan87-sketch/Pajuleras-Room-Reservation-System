<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();

$id = get_int('id');
$tenant = $id ? fetch_one("SELECT * FROM tenants WHERE id = ?", [$id]) : null;
if ($id && !$tenant) {
    abort(404, 'That tenant does not exist.');
}
if ($tenant && $tenant['anonymized_at']) {
    flash('warn', 'This tenant\'s personal details were erased, so the record can no longer be edited.');
    redirect('admin/tenant_view.php?id=' . $id);
}
$inquiryId = get_int('inquiry') ?: post_int('inquiry_id');
$next = in_array(get_str('next') ?: post_str('next', 12), ['reservation'], true) ? 'reservation' : '';

$OLD = $tenant ?: ['full_name' => '', 'gender' => '', 'birth_date' => '', 'contact_no' => '', 'email' => '', 'messenger' => '', 'home_address' => '',
    'occupation' => 'student', 'school_or_workplace' => '', 'id_presented' => '', 'guardian_name' => '', 'guardian_contact' => '', 'notes' => '', 'status' => 'active'];

// Pre-fill from a customer inquiry
if (!$tenant && $inquiryId && !is_post()) {
    if ($inq = fetch_one("SELECT * FROM inquiries WHERE id = ?", [$inquiryId])) {
        $OLD['full_name'] = $inq['name'];
        $OLD['contact_no'] = $inq['contact_no'];
        $OLD['email'] = $inq['email'] ?? '';
        $OLD['occupation'] = $inq['occupation'] ?: 'student';
    }
}
$errors = [];

if (is_post()) {
    csrf_verify();
    $OLD = [
        'full_name' => post_str('full_name', 120), 'gender' => post_str('gender', 10), 'birth_date' => post_str('birth_date', 10),
        'contact_no' => post_str('contact_no', 30), 'email' => post_str('email', 150), 'messenger' => post_str('messenger', 120), 'home_address' => post_str('home_address', 255),
        'occupation' => post_str('occupation', 10), 'school_or_workplace' => post_str('school_or_workplace', 150),
        'id_presented' => post_str('id_presented', 100), 'guardian_name' => post_str('guardian_name', 120),
        'guardian_contact' => post_str('guardian_contact', 30), 'notes' => post_str('notes', 2000), 'status' => post_str('status', 10),
    ];
    $phone = normalize_phone($OLD['contact_no']);
    $gphone = $OLD['guardian_contact'] !== '' ? normalize_phone($OLD['guardian_contact']) : '';

    if (mb_strlen($OLD['full_name']) < 2) $errors[] = 'Enter the tenant\'s full name.';
    if (!in_array($OLD['gender'], ['male', 'female'], true)) $errors[] = 'Choose the tenant\'s gender (needed for male-only and female-only rooms).';
    if (!valid_phone($phone)) $errors[] = 'Enter a valid contact number (digits only, for example 09123456789).';
    if ($OLD['email'] !== '' && !valid_email($OLD['email'])) $errors[] = 'The email address does not look right.';
    if ($OLD['messenger'] !== '' && messenger_handle($OLD['messenger']) === null) $errors[] = 'That Messenger name or link cannot open a chat. Use the Facebook username (for example juan.delacruz or facebook.com/juan.delacruz). Links like profile.php?id=123 do not work.';
    if ($OLD['birth_date'] !== '' && (!valid_date($OLD['birth_date']) || $OLD['birth_date'] > today())) $errors[] = 'Enter a valid birth date (not in the future).';
    if (!in_array($OLD['occupation'], ['student', 'employee', 'other'], true)) $errors[] = 'Choose student, employee, or other.';
    if ($gphone !== '' && !valid_phone($gphone)) $errors[] = 'The guardian / emergency contact number is not valid.';
    if (!in_array($OLD['status'], ['active', 'inactive'], true)) $OLD['status'] = 'active';
    if ($OLD['status'] === 'inactive' && $id && (int)fetch_val("SELECT COUNT(*) FROM reservations WHERE tenant_id = ? AND status IN ('pending','confirmed','checked_in')", [$id]) > 0) {
        $errors[] = 'This tenant still has an open reservation or is currently staying. Finish or cancel it before marking the tenant inactive.';
    }
    if (!$errors && fetch_val("SELECT 1 FROM tenants WHERE full_name = ? AND contact_no = ? AND id <> ?", [$OLD['full_name'], $phone, $id])) {
        $errors[] = 'A tenant with the same name and contact number is already registered. Search the tenant list instead of registering them twice.';
    }

    if (!$errors) {
        $vals = [$OLD['full_name'], $OLD['gender'], $OLD['birth_date'] ?: null, $phone, $OLD['email'] !== '' ? mb_strtolower($OLD['email']) : null, messenger_handle($OLD['messenger']),
                 $OLD['home_address'] ?: null, $OLD['occupation'], $OLD['school_or_workplace'] ?: null, $OLD['id_presented'] ?: null,
                 $OLD['guardian_name'] ?: null, $gphone ?: null, $OLD['notes'] ?: null, $OLD['status']];
        if ($tenant) {
            q("UPDATE tenants SET full_name=?, gender=?, birth_date=?, contact_no=?, email=?, messenger=?, home_address=?, occupation=?, school_or_workplace=?, id_presented=?, guardian_name=?, guardian_contact=?, notes=?, status=? WHERE id=?",
              array_merge($vals, [$id]));
            log_activity('tenant_updated', 'tenant', $id, $OLD['full_name']);
            flash('success', 'Tenant record updated.');
            redirect('admin/tenant_view.php?id=' . $id);
        }
        $newId = in_transaction(function () use ($vals) {
            q("INSERT INTO tenants (full_name, gender, birth_date, contact_no, email, messenger, home_address, occupation, school_or_workplace, id_presented, guardian_name, guardian_contact, notes, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)", $vals);
            $nid = (int)db()->lastInsertId();
            q("UPDATE tenants SET tenant_code = ? WHERE id = ?", ['T-' . str_pad((string)$nid, 4, '0', STR_PAD_LEFT), $nid]);
            return $nid;
        });
        log_activity('tenant_created', 'tenant', $newId, $OLD['full_name']);
        flash('success', $OLD['full_name'] . ' was registered.');
        if ($inquiryId || $next === 'reservation') {
            redirect('admin/reservation_form.php?tenant_id=' . $newId . ($inquiryId ? '&inquiry_id=' . $inquiryId : ''));
        }
        redirect('admin/tenant_view.php?id=' . $newId);
    }
}

admin_header($tenant ? 'Edit tenant' : 'Register tenant', 'tenants');
page_head($tenant ? 'Edit ' . $tenant['full_name'] : 'Register tenant', 'Record the details the landlord needs before a reservation is confirmed.');
?>
<form class="panel form" method="post" novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="inquiry_id" value="<?= (int)$inquiryId ?>">
  <input type="hidden" name="next" value="<?= e($next) ?>">
  <?php render_errors($errors); ?>
  <div class="row-3">
    <label>Full name <span class="req">*</span><input type="text" name="full_name" value="<?= e(old('full_name')) ?>" maxlength="120" required></label>
    <label>Gender <span class="req">*</span>
      <select name="gender" required>
        <option value="">Choose</option>
        <option value="female"<?= selected(old('gender'), 'female') ?>>Female</option>
        <option value="male"<?= selected(old('gender'), 'male') ?>>Male</option>
      </select>
    </label>
    <label>Birth date <span class="opt">(optional)</span><input type="date" name="birth_date" value="<?= e(old('birth_date')) ?>" max="<?= e(today()) ?>"></label>
  </div>
  <div class="row-3">
    <label>Contact number <span class="req">*</span><input type="tel" name="contact_no" value="<?= e(old('contact_no')) ?>" maxlength="30" required inputmode="tel"></label>
    <label>Email <span class="opt">(optional)</span><input type="email" name="email" value="<?= e(old('email')) ?>" maxlength="150"></label>
    <label>Valid ID presented <span class="opt">(optional)</span><input type="text" name="id_presented" value="<?= e(old('id_presented')) ?>" maxlength="100" placeholder="e.g. School ID, Driver's license"></label>
  </div>
  <label>Messenger name or link <span class="opt">(optional, for payment reminders)</span><input type="text" name="messenger" value="<?= e(old('messenger')) ?>" maxlength="120" placeholder="e.g. juan.delacruz or facebook.com/juan.delacruz"></label>
  <label>Home address <span class="opt">(optional)</span><input type="text" name="home_address" value="<?= e(old('home_address')) ?>" maxlength="255"></label>
  <div class="row-3">
    <label>Occupation
      <select name="occupation">
        <option value="student"<?= selected(old('occupation'), 'student') ?>>Student</option>
        <option value="employee"<?= selected(old('occupation'), 'employee') ?>>Employee</option>
        <option value="other"<?= selected(old('occupation'), 'other') ?>>Other</option>
      </select>
    </label>
    <label>School or workplace <span class="opt">(optional)</span><input type="text" name="school_or_workplace" value="<?= e(old('school_or_workplace')) ?>" maxlength="150"></label>
    <label>Status
      <select name="status">
        <option value="active"<?= selected(old('status'), 'active') ?>>Active</option>
        <option value="inactive"<?= selected(old('status'), 'inactive') ?>>Inactive</option>
      </select>
    </label>
  </div>
  <div class="row-2">
    <label>Parent / guardian / emergency contact <span class="opt">(optional)</span><input type="text" name="guardian_name" value="<?= e(old('guardian_name')) ?>" maxlength="120"></label>
    <label>Their contact number <span class="opt">(optional)</span><input type="tel" name="guardian_contact" value="<?= e(old('guardian_contact')) ?>" maxlength="30" inputmode="tel"></label>
  </div>
  <label>Notes <span class="opt">(private, only you see this)</span><textarea name="notes" rows="3" maxlength="2000"><?= e(old('notes')) ?></textarea></label>
  <div class="form-actions">
    <button class="btn btn-primary" type="submit"><?= $tenant ? 'Save changes' : ($inquiryId || $next ? 'Save and continue to reservation' : 'Register tenant') ?></button>
    <a class="btn" data-cancel href="<?= e(url($tenant ? 'admin/tenant_view.php?id=' . $id : 'admin/tenants.php')) ?>">Cancel</a>
  </div>
</form>
<?php admin_footer();
