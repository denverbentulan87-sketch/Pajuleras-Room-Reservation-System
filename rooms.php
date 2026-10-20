<?php
require __DIR__ . '/includes/bootstrap.php';

$type   = in_array(get_str('type'), ['shared', 'private'], true) ? get_str('type') : '';
$gender = in_array(get_str('gender'), ['male', 'female'], true) ? get_str('gender') : '';
$only   = get_str('avail') === '1';
$date   = get_str('date', 10);
$dateErr = '';
if ($date !== '') {
    if (!valid_date($date)) {
        $dateErr = 'Please pick a valid date.';
        $date = '';
    } elseif ($date < today()) {
        $dateErr = 'The move-in date cannot be in the past.';
        $date = '';
    }
}

$rooms = rooms_with_status(true);
if ($date !== '') {
    foreach ($rooms as &$r) {
        $r['free_on_date'] = $r['status'] === 'active'
            ? beds_free((int)$r['id'], (int)$r['capacity'], $date, null)
            : 0;
    }
    unset($r);
}
$list = array_values(array_filter($rooms, function ($r) use ($type, $gender, $only, $date) {
    if ($type !== '' && $r['room_type'] !== $type) return false;
    // a "male only" room is not useful to a female searcher and vice versa
    if ($gender !== '' && !in_array($r['gender_policy'], ['any', $gender], true)) return false;
    if ($only) {
        if ($r['status'] !== 'active') return false;
        $free = $date !== '' ? (int)$r['free_on_date'] : $r['open_beds'];
        if ($free < 1) return false;
    }
    return true;
}));

public_header('Rooms', 'rooms', 'Browse the rooms at Pajuleras Boarding House and see which beds are open.');
?>
<section class="page-title">
  <div class="wrap">
    <h1>Rooms</h1>
    <p>See which rooms and beds are open. Pick a move-in date to check what will be free by then.</p>
  </div>
</section>
<section class="section tight">
  <div class="wrap">
    <form class="filter-bar" method="get" action="<?= e(url('rooms.php')) ?>">
      <label>Room type
        <select name="type">
          <option value="">All</option>
          <option value="shared"<?= selected($type, 'shared') ?>>Shared room</option>
          <option value="private"<?= selected($type, 'private') ?>>Private room</option>
        </select>
      </label>
      <label>I am
        <select name="gender">
          <option value="">Any</option>
          <option value="male"<?= selected($gender, 'male') ?>>Male</option>
          <option value="female"<?= selected($gender, 'female') ?>>Female</option>
        </select>
      </label>
      <label>Move-in date
        <input type="date" name="date" value="<?= e($date) ?>" min="<?= e(today()) ?>">
      </label>
      <label class="check"><input type="checkbox" name="avail" value="1"<?= checked($only) ?>> Only rooms with open beds</label>
      <button class="btn btn-primary" type="submit">Show rooms</button>
      <?php if ($type || $gender || $only || $date): ?><a class="btn btn-ghost" href="<?= e(url('rooms.php')) ?>">Clear</a><?php endif; ?>
    </form>
    <?php if ($dateErr): ?><p class="field-error" role="alert"><?= e($dateErr) ?></p><?php endif; ?>
    <?= pips_legend() ?>

    <?php if ($list): ?>
      <div class="room-grid">
        <?php foreach ($list as $r) { echo room_card($r, $date); } ?>
      </div>
    <?php else: ?>
      <div class="empty-box">
        <h2>No rooms match</h2>
        <p>Try a different date or clear the filters. You can also <a href="<?= e(url('inquire.php')) ?>">send an inquiry</a> and the landlord will let you know when a bed frees up.</p>
      </div>
    <?php endif; ?>
  </div>
</section>
<?php public_footer();
