<?php
declare(strict_types=1);

/**
 * What happens the moment someone sends an inquiry:
 *  1. The system checks the room they asked about (open bed? right gender? under repair?)
 *     for their move-in date and writes an automatic reply:
 *        - confirmed : the room has an open bed
 *        - full      : the room cannot take them (full, reserved, repair or other gender)
 *        - info      : they did not choose a room, so we list rooms with open beds
 *     NOTE: "confirmed" never reserves a bed. Beds are only held when the landlord confirms
 *     the reservation, so nobody can block rooms by sending inquiries.
 *  2. The person is registered as a tenant from what they typed (or matched to the
 *     tenant who already has that contact number), so the landlord does not retype anything.
 */

/** Rooms (public, in service, matching gender) that still have a free bed for the period. */
function inquiry_open_rooms(string $start, ?string $gender, ?int $excludeRoomId = null): array
{
    $out = [];
    foreach (fetch_all("SELECT * FROM rooms WHERE is_public = 1 AND status = 'active' ORDER BY room_no + 0, room_no") as $r) {
        if ($excludeRoomId && (int)$r['id'] === $excludeRoomId) {
            continue;
        }
        if ($gender && $r['gender_policy'] !== 'any' && $r['gender_policy'] !== $gender) {
            continue;
        }
        $free = beds_free((int)$r['id'], (int)$r['capacity'], $start, null);
        if ($free >= 1) {
            $out[] = $r + ['free' => $free];
        }
    }
    return $out;
}

function inquiry_rooms_text(array $rooms, int $limit = 4): string
{
    $lines = [];
    foreach (array_slice($rooms, 0, $limit) as $r) {
        $lines[] = '- ' . room_title($r) . ': ' . rate_label($r) . ', ' . plural((int)$r['free'], 'open bed');
    }
    if (count($rooms) > $limit) {
        $lines[] = '- and ' . (count($rooms) - $limit) . ' more (see the Rooms page)';
    }
    return implode("\n", $lines);
}

/**
 * @return array{result:string, title:string, text:string}
 */
function inquiry_auto_reply(?int $roomId, ?string $moveIn, ?string $gender, string $name, string $refCode): array
{
    $start = ($moveIn && $moveIn >= today()) ? $moveIn : today();
    $when = $moveIn && $moveIn > today() ? 'from ' . fmt_date($start) : 'right now';
    $hi = 'Hi ' . first_name($name) . ', thank you for your inquiry (code ' . $refCode . ").\n\n";
    $room = $roomId ? fetch_one("SELECT * FROM rooms WHERE id = ?", [$roomId]) : null;
    $next = "\n\nWhat happens next: the landlord will read your message and reply. A bed is only held for you once the landlord confirms your reservation and the advance payment of "
          . ((int)setting('advance_months', '1') > 0 ? plural((int)setting('advance_months', '1'), 'month') . ' of rent' : 'the agreed amount') . ' is received.';
    $cantConfirm = "\n\nThis is an automatic reply. The landlord may still be able to help, so your message was kept and will be read.";

    if ($room) {
        $label = 'Room ' . $room['room_no'];
        if ($room['status'] !== 'active') {
            $alt = inquiry_open_rooms($start, $gender, (int)$room['id']);
            return ['result' => 'full', 'title' => 'Not confirmed: ' . $label . ' is closed',
                'text' => $hi . "NOT CONFIRMED. $label is closed for repair or maintenance right now and cannot take new tenants." . ($alt ? "\n\nThese rooms do have an open bed:\n" . inquiry_rooms_text($alt) : "\n\nNo other room has an open bed at the moment.") . $cantConfirm];
        }
        if ($gender && $room['gender_policy'] !== 'any' && $room['gender_policy'] !== $gender) {
            $alt = inquiry_open_rooms($start, $gender);
            return ['result' => 'full', 'title' => 'Not confirmed: ' . $label . ' is ' . strtolower(gender_label($room['gender_policy'])),
                'text' => $hi . "NOT CONFIRMED. $label is for " . strtolower(gender_label($room['gender_policy'])) . ' only.' . ($alt ? "\n\nThese rooms match and have an open bed:\n" . inquiry_rooms_text($alt) : "\n\nNo matching room has an open bed at the moment.") . $cantConfirm];
        }
        $free = beds_free((int)$room['id'], (int)$room['capacity'], $start, null);
        if ($free >= 1) {
            return ['result' => 'confirmed', 'title' => 'Confirmed: ' . $label . ' has an open bed',
                'text' => $hi . "CONFIRMED. $label has " . plural($free, 'open bed') . " $when, at " . rate_label($room) . '.' . $next];
        }
        $alt = inquiry_open_rooms($start, $gender, (int)$room['id']);
        return ['result' => 'full', 'title' => 'Not confirmed: ' . $label . ' is full',
            'text' => $hi . "NOT CONFIRMED. $label is full (all beds are reserved or occupied) $when." . ($alt ? "\n\nThese rooms still have an open bed:\n" . inquiry_rooms_text($alt) : "\n\nNo other room has an open bed at the moment. The landlord will tell you when a bed frees up.") . $cantConfirm];
    }

    $open = inquiry_open_rooms($start, $gender);
    if ($open) {
        return ['result' => 'info', 'title' => 'Received: these rooms have open beds',
            'text' => $hi . "RECEIVED. You did not pick a room, so here is what is open $when:\n" . inquiry_rooms_text($open) . $next];
    }
    return ['result' => 'full', 'title' => 'Not confirmed: all rooms are full',
        'text' => $hi . "NOT CONFIRMED. All rooms are full $when." . "\n\nThe landlord will tell you as soon as a bed frees up." . $cantConfirm];
}

/**
 * Register the person as a tenant from their inquiry, or find the tenant who
 * already has the same contact number. Call inside a transaction.
 * @return int tenant id
 */
function inquiry_register_tenant(array $d): int
{
    $existing = fetch_one("SELECT id, email, messenger FROM tenants WHERE contact_no = ? AND anonymized_at IS NULL ORDER BY id LIMIT 1", [$d['contact_no']]);
    if ($existing) {
        if (($existing['email'] === null || $existing['email'] === '') && !empty($d['email'])) {
            q("UPDATE tenants SET email = ? WHERE id = ?", [$d['email'], $existing['id']]);
        }
        return (int)$existing['id'];
    }
    $occ = in_array($d['occupation'], ['student', 'employee'], true) ? $d['occupation'] : 'other';
    q("INSERT INTO tenants (full_name, gender, contact_no, email, occupation, school_or_workplace, status, source, consent_at)
       VALUES (?,?,?,?,?,?, 'active', 'inquiry', NOW())",
      [$d['name'], $d['gender'], $d['contact_no'], $d['email'] ?: null, $occ, $d['school'] ?: null]);
    $id = (int)db()->lastInsertId();
    q("UPDATE tenants SET tenant_code = ? WHERE id = ?", ['T-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT), $id]);
    log_activity('tenant_registered', 'tenant', $id, $d['name'] . ' registered automatically from an inquiry');
    return $id;
}
