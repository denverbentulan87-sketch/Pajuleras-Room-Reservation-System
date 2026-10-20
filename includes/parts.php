<?php
declare(strict_types=1);

/** True when the landlord uploaded a photo for this room. */
function room_has_photo(array $r): bool
{
    return !empty($r['photo']) && is_file(APP_ROOT . '/uploads/rooms/' . basename((string)$r['photo']));
}

/** URL of a room's own photo, or the general photo of a shared room. */
function room_photo_url(array $r): string
{
    if (room_has_photo($r)) {
        return url('uploads/rooms/' . rawurlencode(basename((string)$r['photo'])));
    }
    return '';
}

/** URL of the boarding house photo the landlord uploaded in Settings, or '' when none. */
function house_photo_url(): string
{
    $f = basename(setting('house_photo'));
    if ($f !== '' && preg_match('/^house_[a-f0-9]{16}\.(jpg|png|webp)$/', $f) && is_file(APP_ROOT . '/uploads/rooms/' . $f)) {
        return url('uploads/rooms/' . rawurlencode($f));
    }
    return '';
}

/** A photo that zooms on hover, follows the pointer and opens full screen on click or tap. */
function zoom_photo(string $src, string $alt, int $w = 1400, int $h = 900, bool $eager = false): string
{
    return '<button type="button" class="zoomable" data-zoom="' . e($src) . '" data-alt="' . e($alt) . '" aria-label="Enlarge photo: ' . e($alt) . '">'
         . '<img src="' . e($src) . '" alt="' . e($alt) . '" width="' . $w . '" height="' . $h . '"' . ($eager ? ' fetchpriority="high"' : ' loading="lazy"') . ' decoding="async" draggable="false">'
         . '<span class="zoom-hint" aria-hidden="true">' . icon('expand') . '<span>Enlarge</span></span></button>';
}

function room_title(array $r): string
{
    return 'Room ' . $r['room_no'] . (!empty($r['room_name']) ? ' - ' . $r['room_name'] : '');
}

function rate_label(array $r): string
{
    return money_short($r['monthly_rate']) . ((int)$r['capacity'] > 1 ? ' per bed' : ' per room') . ' / month';
}

/**
 * Public room card.  $r comes from rooms_with_status().
 * If $r['free_on_date'] is set the card reports availability for that date.
 */
function room_card(array $r, string $dateParam = ''): string
{
    $key = $r['status_key'];
    $label = $r['status_label'];
    $openText = plural($r['open_beds'], 'bed') . ' open of ' . $r['capacity'];
    if (isset($r['free_on_date'])) {
        $free = max(0, (int)$r['free_on_date']);
        $key = $r['status_key'] === 'maintenance' ? 'maintenance' : ($free > 0 ? 'available' : 'occupied');
        $label = $r['status_key'] === 'maintenance' ? 'Under maintenance' : ($free > 0 ? 'Available' : 'Not available');
        $openText = plural($free, 'bed') . ' free from ' . fmt_date($dateParam);
    }
    $href = url('room.php?id=' . (int)$r['id'] . ($dateParam !== '' ? '&date=' . urlencode($dateParam) : ''));
    ob_start(); ?>
<article class="room-card room-<?= e($key) ?>" data-room-type="<?= e($r['room_type']) ?>">
  <div class="room-media">
    <a class="room-photo<?= room_has_photo($r) ? '' : ' room-photo-none' ?>" href="<?= e($href) ?>" tabindex="-1" aria-hidden="true">
      <?php if (room_has_photo($r)): ?>
        <img src="<?= e(room_photo_url($r)) ?>" alt="" loading="lazy" width="600" height="380">
      <?php else: ?>
        <span class="room-tile"><?= e($r['room_no']) ?></span>
      <?php endif; ?>
    </a>
    <span class="status status-<?= e($key) ?>"><?= e($label) ?></span>
  </div>
  <div class="room-body">
    <h3><a href="<?= e($href) ?>"><?= e(room_title($r)) ?></a></h3>
    <p class="room-meta"><?= $r['room_type'] === 'private' ? 'Private room' : 'Shared room' ?><?php if ($r['gender_policy'] !== 'any'): ?> &middot; <?= e(gender_label($r['gender_policy'])) ?><?php endif; ?></p>
    <div class="room-beds"><?= bed_pips($r) ?><span><?= e($openText) ?></span></div>
    <div class="room-foot">
      <p class="room-rate"><strong><?= e(money_short($r['monthly_rate'])) ?></strong><span><?= (int)$r['capacity'] > 1 ? 'per bed' : 'per room' ?> / month</span></p>
      <span class="room-go" aria-hidden="true">View room <?= icon('arrow') ?></span>
    </div>
  </div>
</article>
<?php
    return (string)ob_get_clean();
}

function pips_legend(): string
{
    return '<ul class="legend" aria-label="How to read the bed markers">'
         . '<li><i class="pip pip-occupied"></i> Occupied</li>'
         . '<li><i class="pip pip-reserved"></i> Reserved</li>'
         . '<li><i class="pip pip-open"></i> Open</li></ul>';
}

/** Numbered reservation steps taken from the enterprise process description. */
function reservation_steps(): array
{
    return [
        ['Send an inquiry', 'Ask about available rooms, rates, and requirements. You can message us here or call the landlord.'],
        ['We check availability', 'The landlord checks the current room records and replies with the rooms that fit your needs.'],
        ['Share your details', 'Provide your name, contact number, preferred room, and expected move-in date.'],
        ['Reservation confirmed', 'The landlord confirms your room and explains the reservation terms.'],
        ['Pay the advance', 'The advance payment secures your room. You will get an official receipt.'],
        ['Move in', 'On your move-in day the landlord checks you in and your room is assigned.'],
    ];
}
