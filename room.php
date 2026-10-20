<?php
require __DIR__ . '/includes/bootstrap.php';

$id = get_int('id');
$rows = $id ? rooms_with_status(true, $id) : [];
if (!$rows) {
    abort(404, 'That room is not listed. Please choose a room from the rooms page.');
}
$r = $rows[0];

$date = get_str('date', 10);
$dateMsg = '';
$free = null;
if ($date !== '') {
    if (!valid_date($date) || $date < today()) {
        $dateMsg = 'Please pick today or a future date.';
        $date = '';
    } elseif ($r['status'] === 'active') {
        $free = max(0, beds_free((int)$r['id'], (int)$r['capacity'], $date, null));
    }
}

public_header(room_title($r), 'rooms');
?>
<section class="section tight">
  <div class="wrap">
    <p class="crumb"><a href="<?= e(url('rooms.php')) ?>">Rooms</a> / <?= e(room_title($r)) ?></p>
    <div class="room-detail">
      <div class="room-detail-photo">
        <?php if (room_has_photo($r)): ?>
          <?= zoom_photo(room_photo_url($r), 'Photo of ' . room_title($r), 1400, 867, true) ?>
        <?php else: ?>
          <div class="room-art"><?= art_room('rd') ?></div>
          <p class="photo-cap">Illustration of a shared room. A photo of this exact room will be added soon.</p>
        <?php endif; ?>
      </div>
      <div class="room-detail-info">
        <div class="room-top">
          <h1><?= e(room_title($r)) ?></h1>
          <span class="status status-<?= e($r['status_key']) ?>"><?= e($r['status_label']) ?></span>
        </div>
        <div class="room-beds big"><?= bed_pips($r) ?><span><?= e(plural($r['open_beds'], 'bed') . ' open of ' . $r['capacity']) ?></span></div>
        <?= pips_legend() ?>

        <dl class="facts">
          <div><dt>Rent</dt><dd><?= e(rate_label($r)) ?></dd></div>
          <div><dt>Security deposit</dt><dd><?= $r['deposit_amount'] > 0 ? e(money_short($r['deposit_amount'])) . ((int)$r['capacity'] > 1 ? ' per bed' : '') : 'None' ?></dd></div>
          <div><dt>Type</dt><dd><?= $r['room_type'] === 'private' ? 'Private room' : 'Shared room' ?>, <?= e(plural((int)$r['capacity'], 'bed')) ?></dd></div>
          <div><dt>Who can stay</dt><dd><?= e(gender_label($r['gender_policy'])) ?></dd></div>
          <?php if (!empty($r['amenities'])): ?><div><dt>Includes</dt><dd><?= e($r['amenities']) ?></dd></div><?php endif; ?>
        </dl>
        <?php if (!empty($r['description'])): ?><p class="room-desc"><?= nl2p($r['description']) ?></p><?php endif; ?>

        <?php if ($r['status'] === 'active'): ?>
        <form class="date-check" method="get" action="<?= e(url('room.php')) ?>">
          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <label>Will there be a bed on my move-in date?
            <span class="inline-fields">
              <input type="date" name="date" value="<?= e($date) ?>" min="<?= e(today()) ?>" required>
              <button class="btn" type="submit">Check</button>
            </span>
          </label>
          <?php if ($dateMsg): ?><span class="field-error"><?= e($dateMsg) ?></span><?php endif; ?>
          <?php if ($free !== null): ?>
            <p class="date-result <?= $free > 0 ? 'ok' : 'no' ?>" role="status">
              <?php if ($free > 0): ?>
                <?= e(plural($free, 'bed')) ?> free from <?= e(fmt_date($date)) ?>. Send an inquiry to reserve it.
              <?php else: ?>
                No beds are free from <?= e(fmt_date($date)) ?>. Try a later date or another room.
              <?php endif; ?>
            </p>
          <?php endif; ?>
        </form>
        <?php endif; ?>

        <div class="hero-actions">
          <a class="btn btn-primary btn-lg" href="<?= e(url('inquire.php?room=' . (int)$r['id'] . ($date !== '' ? '&date=' . urlencode($date) : ''))) ?>">Inquire about this room</a>
          <a class="btn btn-lg" href="<?= e(url('rooms.php')) ?>">Back to rooms</a>
        </div>
      </div>
    </div>
  </div>
</section>
<?php public_footer();
