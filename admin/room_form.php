<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();

$id = get_int('id');
$room = $id ? fetch_one("SELECT * FROM rooms WHERE id = ?", [$id]) : null;
if ($id && !$room) {
    abort(404, 'That room does not exist.');
}
$errors = [];
$OLD = $room ?: ['room_no' => '', 'room_name' => '', 'room_type' => 'shared', 'gender_policy' => 'any', 'capacity' => 4,
                 'monthly_rate' => '', 'deposit_amount' => '', 'amenities' => '', 'description' => '', 'status' => 'active', 'is_public' => 1, 'photo' => null];

if (is_post()) {
    csrf_verify();
    $OLD = [
        'room_no' => post_str('room_no', 20), 'room_name' => post_str('room_name', 80),
        'room_type' => post_str('room_type', 10), 'gender_policy' => post_str('gender_policy', 10),
        'capacity' => post_str('capacity', 3), 'monthly_rate' => post_str('monthly_rate', 15), 'deposit_amount' => post_str('deposit_amount', 15),
        'amenities' => post_str('amenities', 255), 'description' => post_str('description', 2000),
        'status' => post_str('status', 12), 'is_public' => isset($_POST['is_public']) ? 1 : 0,
        'photo' => $room['photo'] ?? null,
    ];
    if ($OLD['room_no'] === '') $errors[] = 'Room number or name is required (for example "1" or "A").';
    if (!in_array($OLD['room_type'], ['shared', 'private'], true)) $errors[] = 'Choose a room type.';
    if (!in_array($OLD['gender_policy'], ['any', 'male', 'female'], true)) $errors[] = 'Choose who can stay in the room.';
    if (!in_array($OLD['status'], ['active', 'maintenance'], true)) $errors[] = 'Choose a status.';
    if (!ctype_digit($OLD['capacity']) || (int)$OLD['capacity'] < 1 || (int)$OLD['capacity'] > 30) $errors[] = 'Number of beds must be a whole number from 1 to 30.';
    $rate = parse_money($OLD['monthly_rate']);
    $dep = $OLD['deposit_amount'] === '' ? 0.0 : parse_money($OLD['deposit_amount']);
    if ($rate === null || $rate <= 0) $errors[] = 'Enter the monthly rent as a positive amount, for example 700 or 700.50.';
    if ($dep === null) $errors[] = 'Enter the deposit as an amount, or leave it blank for none.';
    if ($OLD['room_no'] !== '' && fetch_val("SELECT 1 FROM rooms WHERE room_no = ? AND id <> ?", [$OLD['room_no'], $id])) {
        $errors[] = 'Another room already uses "' . $OLD['room_no'] . '". Room numbers must be unique.';
    }

    $newPhoto = null;
    if (!$errors) {
        try {
            $newPhoto = store_room_photo($_FILES['photo'] ?? []);
        } catch (RuleException $ex) {
            $errors[] = $ex->getMessage();
        }
    }

    if (!$errors) {
        try {
            $savedId = in_transaction(function () use ($OLD, $rate, $dep, $id, $room, $newPhoto) {
                $cap = (int)$OLD['capacity'];
                $photo = $room['photo'] ?? null;
                if ($newPhoto) {
                    $photo = $newPhoto;
                } elseif (isset($_POST['remove_photo'])) {
                    $photo = null;
                }
                if ($id) {
                    lock_room($id);
                    // Cannot shrink below beds that are already promised.
                    $peak = peak_beds_in_use(room_holds($id), today(), OPEN_END);
                    if ($peak > $cap) {
                        throw new RuleException('Room ' . $OLD['room_no'] . ' already has up to ' . plural($peak, 'bed') . ' reserved or occupied, so it cannot have only ' . $cap . '. Move or cancel those reservations first.');
                    }
                    if ($OLD['status'] === 'maintenance' && (int)fetch_val("SELECT COUNT(*) FROM reservations WHERE room_id = ? AND status IN ('confirmed','checked_in')", [$id]) > 0) {
                        throw new RuleException('Room ' . $OLD['room_no'] . ' still has confirmed or checked-in tenants. Transfer or cancel them before setting the room to maintenance.');
                    }
                    q("UPDATE rooms SET room_no=?, room_name=?, room_type=?, gender_policy=?, capacity=?, monthly_rate=?, deposit_amount=?, amenities=?, description=?, photo=?, status=?, is_public=? WHERE id=?",
                      [$OLD['room_no'], $OLD['room_name'] ?: null, $OLD['room_type'], $OLD['gender_policy'], $cap, $rate, $dep, $OLD['amenities'] ?: null, $OLD['description'] ?: null, $photo, $OLD['status'], $OLD['is_public'], $id]);
                    log_activity('room_updated', 'room', $id, 'Room ' . $OLD['room_no']);
                    return $id;
                }
                q("INSERT INTO rooms (room_no, room_name, room_type, gender_policy, capacity, monthly_rate, deposit_amount, amenities, description, photo, status, is_public) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
                  [$OLD['room_no'], $OLD['room_name'] ?: null, $OLD['room_type'], $OLD['gender_policy'], $cap, $rate, $dep, $OLD['amenities'] ?: null, $OLD['description'] ?: null, $photo, $OLD['status'], $OLD['is_public']]);
                $nid = (int)db()->lastInsertId();
                log_activity('room_created', 'room', $nid, 'Room ' . $OLD['room_no']);
                return $nid;
            });
            // remove the replaced/removed photo file only after the database change succeeded
            if ($room && ($newPhoto || isset($_POST['remove_photo'])) && $room['photo']) {
                delete_room_photo($room['photo']);
            }
            flash('success', 'Room ' . $OLD['room_no'] . ' was saved.');
            redirect('admin/rooms.php');
        } catch (RuleException $ex) {
            $errors[] = $ex->getMessage();
            if ($newPhoto) delete_room_photo($newPhoto);
        }
    } elseif ($newPhoto) {
        delete_room_photo($newPhoto);
    }
}

admin_header($room ? 'Edit room' : 'Add room', 'rooms');
page_head($room ? 'Edit Room ' . $room['room_no'] : 'Add room', 'Rates are per bed in shared rooms and per room when the room has one bed.');
?>
<form class="panel form" method="post" enctype="multipart/form-data" novalidate>
  <?= csrf_field() ?>
  <?php render_errors($errors); ?>
  <div class="row-3">
    <label>Room number <span class="req">*</span><input type="text" name="room_no" value="<?= e(old('room_no')) ?>" maxlength="20" required></label>
    <label>Room name <span class="opt">(optional)</span><input type="text" name="room_name" value="<?= e(old('room_name')) ?>" maxlength="80" placeholder="e.g. Ground floor, near the gate"></label>
    <label>Number of beds <span class="req">*</span><input type="number" name="capacity" value="<?= e(old('capacity')) ?>" min="1" max="30" required></label>
  </div>
  <div class="row-3">
    <label>Room type
      <select name="room_type">
        <option value="shared"<?= selected(old('room_type'), 'shared') ?>>Shared room (bunk beds)</option>
        <option value="private"<?= selected(old('room_type'), 'private') ?>>Private room</option>
      </select>
    </label>
    <label>Who can stay
      <select name="gender_policy">
        <option value="any"<?= selected(old('gender_policy'), 'any') ?>>Anyone</option>
        <option value="male"<?= selected(old('gender_policy'), 'male') ?>>Male only</option>
        <option value="female"<?= selected(old('gender_policy'), 'female') ?>>Female only</option>
      </select>
    </label>
    <label>Status
      <select name="status">
        <option value="active"<?= selected(old('status'), 'active') ?>>Active (can be reserved)</option>
        <option value="maintenance"<?= selected(old('status'), 'maintenance') ?>>Under maintenance</option>
      </select>
    </label>
  </div>
  <div class="row-3">
    <label>Monthly rent (₱) <span class="req">*</span><input type="text" name="monthly_rate" value="<?= e(old('monthly_rate')) ?>" inputmode="decimal" required placeholder="700"></label>
    <label>Security deposit (₱)<input type="text" name="deposit_amount" value="<?= e(old('deposit_amount')) ?>" inputmode="decimal" placeholder="0"></label>
    <label class="check pad-top"><input type="checkbox" name="is_public" value="1"<?= checked((int)old('is_public', 1) === 1) ?>> Show this room on the public website</label>
  </div>
  <label>What is included <span class="opt">(short, comma-separated)</span>
    <input type="text" name="amenities" value="<?= e(old('amenities')) ?>" maxlength="255" placeholder="Bunk beds, wall outlet, shared comfort room">
  </label>
  <label>Description <span class="opt">(optional)</span>
    <textarea name="description" rows="4" maxlength="2000"><?= e(old('description')) ?></textarea>
  </label>
  <div class="photo-field">
    <label>Room photo <span class="opt">(JPG, PNG or WebP, up to 3 MB)</span>
      <input type="file" name="photo" accept="image/jpeg,image/png,image/webp">
    </label>
    <?php if ($room && $room['photo']): ?>
      <div class="photo-current">
        <img src="<?= e(room_photo_url($room)) ?>" alt="Current photo of Room <?= e($room['room_no']) ?>" width="160">
        <label class="check"><input type="checkbox" name="remove_photo" value="1"> Remove this photo</label>
      </div>
    <?php else: ?>
      <p class="muted small">Without a photo, the website shows a room illustration.</p>
    <?php endif; ?>
  </div>
  <div class="form-actions">
    <button class="btn btn-primary" type="submit">Save room</button>
    <a class="btn" href="<?= e(url('admin/rooms.php')) ?>">Cancel</a>
  </div>
</form>
<?php admin_footer();
