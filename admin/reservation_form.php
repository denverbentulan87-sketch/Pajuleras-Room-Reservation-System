<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();

$id = get_int('id');
$res = $id ? fetch_one("SELECT * FROM reservations WHERE id = ?", [$id]) : null;
if ($id && !$res) {
    abort(404, 'That reservation does not exist.');
}
if ($res && !in_array($res['status'], ['pending', 'confirmed', 'checked_in'], true)) {
    flash('warn', 'Completed and cancelled reservations can no longer be edited.');
    redirect('admin/reservation_view.php?id=' . $id);
}
$limited = $res && $res['status'] === 'checked_in';   // tenant already moved in: only some fields can change
$statusLocked = $res && $res['status'] !== 'pending';  // a confirmed/checked-in reservation cannot go back to pending here

$inquiryId = $res ? (int)$res['inquiry_id'] : (get_int('inquiry_id') ?: post_int('inquiry_id'));
$inq = $inquiryId ? fetch_one("SELECT * FROM inquiries WHERE id = ?", [$inquiryId]) : null;

$tenants = fetch_all("SELECT id, tenant_code, full_name, gender, contact_no, status FROM tenants WHERE status = 'active' OR id = ? ORDER BY full_name", [$res['tenant_id'] ?? 0]);
$rooms = rooms_with_status(false);
$errors = [];

$OLD = $res ?: [
    'tenant_id' => get_int('tenant_id') ?: '',
    'room_id' => get_int('room_id') ?: ($inq['room_id'] ?? ''),
    'beds' => 1,
    'move_in_date' => valid_date(get_str('date', 10)) ? get_str('date', 10) : ($inq['preferred_move_in'] ?? ''),
    'move_out_date' => '', 'status' => 'pending', 'monthly_rate' => '', 'deposit_amount' => '', 'advance_amount' => '', 'notes' => '',
];
if (!$res && $inq && !$OLD['tenant_id'] && !is_post()) {
    // try to match the inquiry to an existing tenant by contact number
    $OLD['tenant_id'] = (int)fetch_val("SELECT id FROM tenants WHERE contact_no = ? LIMIT 1", [$inq['contact_no']]) ?: '';
}

if (is_post()) {
    csrf_verify();
    $OLD = [
        'tenant_id' => post_int('tenant_id') ?: '', 'room_id' => post_int('room_id') ?: '', 'beds' => post_str('beds', 3),
        'move_in_date' => post_str('move_in_date', 10), 'move_out_date' => post_str('move_out_date', 10),
        'status' => post_str('status', 12), 'monthly_rate' => post_str('monthly_rate', 15), 'deposit_amount' => post_str('deposit_amount', 15),
        'advance_amount' => post_str('advance_amount', 15), 'notes' => post_str('notes', 2000),
    ];
    if ($limited) {   // ignore attempts to change locked fields
        foreach (['tenant_id', 'room_id', 'beds', 'move_in_date'] as $k) $OLD[$k] = $res[$k];
        $OLD['status'] = 'checked_in';
    }

    $tenant = $OLD['tenant_id'] ? fetch_one("SELECT * FROM tenants WHERE id = ?", [$OLD['tenant_id']]) : null;
    $room = $OLD['room_id'] ? fetch_one("SELECT * FROM rooms WHERE id = ?", [$OLD['room_id']]) : null;
    $beds = ctype_digit((string)$OLD['beds']) ? (int)$OLD['beds'] : 0;
    $moveOut = $OLD['move_out_date'] !== '' ? $OLD['move_out_date'] : null;

    if (!$tenant) $errors[] = 'Choose a tenant. If the customer is not registered yet, use "Register new tenant".';
    elseif ($tenant['status'] !== 'active' && !$res) $errors[] = 'That tenant is inactive. Reactivate the tenant first.';
    if (!$room) $errors[] = 'Choose a room.';
    if ($room && $room['status'] !== 'active') $errors[] = 'Room ' . $room['room_no'] . ' is under maintenance.';
    if ($room && $tenant && $room['gender_policy'] !== 'any' && $room['gender_policy'] !== $tenant['gender']) {
        $errors[] = 'Room ' . $room['room_no'] . ' is ' . strtolower(gender_label($room['gender_policy'])) . ', which does not match this tenant.';
    }
    if ($beds < 1) $errors[] = 'Enter how many beds are being reserved (at least 1).';
    if (!valid_date($OLD['move_in_date'])) {
        $errors[] = 'Enter a valid move-in date.';
    } else {
        if ($OLD['move_in_date'] < date('Y-m-d', strtotime('-1 year')) || $OLD['move_in_date'] > date('Y-m-d', strtotime('+2 years'))) $errors[] = 'The move-in date is too far in the past or future. Please check it.';
    }
    if ($moveOut !== null) {
        if (!valid_date($moveOut)) $errors[] = 'Enter a valid move-out date, or leave it blank for a long-term stay.';
        elseif (valid_date($OLD['move_in_date']) && $moveOut <= $OLD['move_in_date']) $errors[] = 'The move-out date must be after the move-in date.';
    }
    // money (blank = use the room's rates)
    $rate = $OLD['monthly_rate'] !== '' ? parse_money($OLD['monthly_rate']) : ($room ? round((float)$room['monthly_rate'] * max(1, $beds), 2) : null);
    $dep  = $OLD['deposit_amount'] !== '' ? parse_money($OLD['deposit_amount']) : ($room ? round((float)$room['deposit_amount'] * max(1, $beds), 2) : null);
    $advMonths = max(0, (int)setting('advance_months', '1'));
    $adv  = $OLD['advance_amount'] !== '' ? parse_money($OLD['advance_amount']) : ($rate !== null ? round($rate * $advMonths, 2) : null);
    if ($rate === null || $rate <= 0) $errors[] = 'Enter the monthly rent as an amount greater than zero.';
    if ($dep === null) $errors[] = 'Enter the security deposit as an amount (0 for none).';
    if ($adv === null) $errors[] = 'Enter the advance payment as an amount (0 for none).';
    if ($statusLocked) $OLD['status'] = $res['status'];
    elseif (!in_array($OLD['status'], ['pending', 'confirmed'], true)) $errors[] = 'Choose whether to save the reservation as pending or confirm it now.';

    // the same tenant cannot hold two overlapping reservations
    if (!$errors && !$limited && valid_date($OLD['move_in_date'])) {
        $clash = fetch_one("SELECT reservation_no FROM reservations
                             WHERE tenant_id = ? AND id <> ? AND status IN ('pending','confirmed','checked_in')
                               AND move_in_date < ? AND COALESCE(move_out_date, '9999-12-31') > ?",
                           [$tenant['id'], $id, $moveOut ?: OPEN_END, $OLD['move_in_date']]);
        if ($clash) $errors[] = $tenant['full_name'] . ' already has an open reservation (' . $clash['reservation_no'] . ') for those dates.';
    }

    if (!$errors) {
        try {
            $newId = in_transaction(function () use ($OLD, $res, $id, $limited, $tenant, $room, $beds, $moveOut, $rate, $dep, $adv, $inq) {
                $lockedRoom = lock_room((int)$room['id']);
                if ($OLD['status'] === 'confirmed' || $limited) {
                    $start = $OLD['move_in_date'];
                    if ($limited && !empty($res['checked_in_at']) && substr($res['checked_in_at'], 0, 10) < $start) $start = substr($res['checked_in_at'], 0, 10);
                    assert_capacity($lockedRoom, $start, $moveOut, $beds, $id ?: null);
                }
                if ($res) {
                    $confirmedAt = ($res['status'] === 'pending' && $OLD['status'] === 'confirmed') ? date('Y-m-d H:i:s') : $res['confirmed_at'];
                    if ($limited) {
                        q("UPDATE reservations SET move_out_date=?, monthly_rate=?, deposit_amount=?, advance_amount=?, notes=? WHERE id=?",
                          [$moveOut, $rate, $dep, $adv, $OLD['notes'] ?: null, $id]);
                    } else {
                        q("UPDATE reservations SET tenant_id=?, room_id=?, beds=?, move_in_date=?, move_out_date=?, status=?, monthly_rate=?, deposit_amount=?, advance_amount=?, confirmed_at=?, notes=? WHERE id=?",
                          [$tenant['id'], $room['id'], $beds, $OLD['move_in_date'], $moveOut, $OLD['status'], $rate, $dep, $adv, $confirmedAt, $OLD['notes'] ?: null, $id]);
                    }
                    log_activity('reservation_updated', 'reservation', $id, $res['reservation_no'] . ' edited');
                    return $id;
                }
                q("INSERT INTO reservations (tenant_id, room_id, inquiry_id, beds, move_in_date, move_out_date, status, monthly_rate, deposit_amount, advance_amount, confirmed_at, notes)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
                  [$tenant['id'], $room['id'], $inq['id'] ?? null, $beds, $OLD['move_in_date'], $moveOut, $OLD['status'], $rate, $dep, $adv,
                   $OLD['status'] === 'confirmed' ? date('Y-m-d H:i:s') : null, $OLD['notes'] ?: null]);
                $nid = (int)db()->lastInsertId();
                $no = 'R' . date('Y') . '-' . str_pad((string)$nid, 4, '0', STR_PAD_LEFT);
                q("UPDATE reservations SET reservation_no = ? WHERE id = ?", [$no, $nid]);
                if ($inq) {
                    q("UPDATE inquiries SET status = 'converted', admin_unread = 0 WHERE id = ? AND status <> 'closed'", [$inq['id']]);
                }
                log_activity('reservation_created', 'reservation', $nid, $no . ' for ' . $tenant['full_name'] . ', Room ' . $room['room_no'] . ' (' . $OLD['status'] . ')');
                return $nid;
            });
            flash('success', $res ? 'Reservation updated.' : 'Reservation recorded.' . ($OLD['status'] === 'pending' ? ' It is pending until you confirm it.' : ''));
            redirect('admin/reservation_view.php?id=' . $newId);
        } catch (RuleException $ex) {
            $errors[] = $ex->getMessage();
        }
    }
}

admin_header($res ? 'Edit reservation' : 'New reservation', 'reservations');
page_head($res ? 'Edit ' . $res['reservation_no'] : 'New reservation',
          $inq ? 'From the inquiry by ' . $inq['name'] . ' (' . $inq['ref_code'] . ')' : 'Record the customer, the room, and the move-in date.');
if ($limited) echo '<div class="flash flash-info"><span>This tenant has already checked in, so the tenant, room, and move-in date are locked. Use "Transfer room" on the reservation page to move them.</span></div>';
?>
<form class="panel form" method="post" novalidate id="res-form">
  <?= csrf_field() ?>
  <input type="hidden" name="inquiry_id" value="<?= (int)$inquiryId ?>">
  <?php render_errors($errors); ?>

  <div class="row-2">
    <label>Tenant <span class="req">*</span>
      <select name="tenant_id" required<?= $limited ? ' disabled' : '' ?>>
        <option value="">Choose a registered tenant</option>
        <?php foreach ($tenants as $t): ?>
          <option value="<?= (int)$t['id'] ?>"<?= selected(old('tenant_id'), $t['id']) ?>><?= e($t['full_name']) ?> (<?= e($t['tenant_code']) ?>, <?= e($t['contact_no']) ?>)</option>
        <?php endforeach; ?>
      </select>
      <?php if (!$res): ?><span class="hint">Not registered yet? <a href="<?= e(url('admin/tenant_form.php?next=reservation' . ($inquiryId ? '&inquiry=' . $inquiryId : ''))) ?>">Register new tenant</a></span><?php endif; ?>
    </label>
    <label>Room <span class="req">*</span>
      <select name="room_id" required data-room-select<?= $limited ? ' disabled' : '' ?>>
        <option value="">Choose a room</option>
        <?php foreach ($rooms as $r): ?>
          <option value="<?= (int)$r['id'] ?>"
                  data-rate="<?= e($r['monthly_rate']) ?>" data-deposit="<?= e($r['deposit_amount']) ?>" data-capacity="<?= (int)$r['capacity'] ?>"
                  <?= $r['status'] !== 'active' ? 'disabled' : '' ?><?= selected(old('room_id'), $r['id']) ?>>
            Room <?= e($r['room_no']) ?> &middot; <?= e($r['open_beds']) ?> of <?= (int)$r['capacity'] ?> open &middot; <?= e(money_short($r['monthly_rate'])) ?><?= (int)$r['capacity'] > 1 ? '/bed' : '' ?><?= $r['gender_policy'] !== 'any' ? ' &middot; ' . e(gender_label($r['gender_policy'])) : '' ?><?= $r['status'] !== 'active' ? ' (maintenance)' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
  </div>

  <div class="row-3">
    <label>Beds reserved <span class="req">*</span>
      <input type="number" name="beds" value="<?= e(old('beds')) ?>" min="1" max="30" required data-beds<?= $limited ? ' readonly' : '' ?>>
    </label>
    <label>Move-in date <span class="req">*</span>
      <input type="date" name="move_in_date" value="<?= e(old('move_in_date')) ?>" required<?= $limited ? ' readonly' : '' ?>>
    </label>
    <label>Move-out date <span class="opt">(blank = long-term)</span>
      <input type="date" name="move_out_date" value="<?= e(old('move_out_date')) ?>">
    </label>
  </div>

  <div class="row-3">
    <label>Monthly rent (₱, total for the beds)
      <input type="text" name="monthly_rate" value="<?= e(old('monthly_rate')) ?>" inputmode="decimal" data-rate placeholder="Filled from the room's rate">
    </label>
    <label>Security deposit (₱)
      <input type="text" name="deposit_amount" value="<?= e(old('deposit_amount')) ?>" inputmode="decimal" data-deposit placeholder="Filled from the room's deposit">
    </label>
    <label>Advance payment to secure (₱)
      <input type="text" name="advance_amount" value="<?= e(old('advance_amount')) ?>" inputmode="decimal" data-advance data-advance-months="<?= (int)setting('advance_months', '1') ?>" placeholder="Default: <?= (int)setting('advance_months', '1') ?> month of rent">
    </label>
  </div>
  <?php if ($limited || $res): ?><p class="hint">Changing the monthly rent recalculates the total due for every month of this stay.</p><?php endif; ?>

  <?php if (!$statusLocked): ?>
  <fieldset class="choice">
    <legend>Reservation status</legend>
    <label class="radio"><input type="radio" name="status" value="pending"<?= checked(old('status') !== 'confirmed') ?>> <span><strong>Pending</strong> <small>Record it now, confirm later. Does not hold a bed yet.</small></span></label>
    <label class="radio"><input type="radio" name="status" value="confirmed"<?= checked(old('status') === 'confirmed') ?>> <span><strong>Confirmed</strong> <small>Secure the room now. The system checks that the beds are really free.</small></span></label>
  </fieldset>
  <?php endif; ?>

  <label>Notes <span class="opt">(private)</span><textarea name="notes" rows="3" maxlength="2000"><?= e(old('notes')) ?></textarea></label>
  <div class="form-actions">
    <button class="btn btn-primary" type="submit"><?= $res ? 'Save changes' : 'Save reservation' ?></button>
    <a class="btn" href="<?= e(url($res ? 'admin/reservation_view.php?id=' . $id : 'admin/reservations.php')) ?>">Cancel</a>
  </div>
</form>
<?php admin_footer();
