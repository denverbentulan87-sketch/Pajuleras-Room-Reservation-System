<?php
declare(strict_types=1);


/** The move-in date the system records: the date asked for, otherwise one month after today. */
function inquiry_move_in_date(?string $asked): string
{
    if ($asked !== null && valid_date($asked) && $asked >= today()) {
        return $asked;
    }
    return add_months_clamped(today(), 1);
}

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

/** The signature line at the bottom of automatic messages. */
function inquiry_signature(): string
{
    $name = setting('landlord_name') ?: setting('site_name', 'Pajuleras Boarding House');
    return $name . (setting('phone') !== '' ? ', ' . setting('phone') : '');
}

/**
 * The reply for a confirmed room. The first sentence is the one the landlord asked for.
 * @param array|null $booking the pending reservation recorded for the person (see inquiry_create_booking)
 */
function inquiry_confirmed_text(array $room, int $free, string $name, string $refCode, string $moveIn, ?array $booking): string
{
    $label = 'Room ' . $room['room_no'];
    $text = 'Hi ' . first_name($name) . ', thank you for your inquiry (code ' . $refCode . ").\n\n"
          . "Confirmed. Approach me personally for more information.\n\n"
          . "$label has " . plural($free, 'open bed') . ', at ' . rate_label($room) . '.';
    if ($booking === null) {
        // no reservation details here: this is also what a returning number sees (their details are never shown to a stranger)
        return $text . "\n\n" . inquiry_signature();
    }
    $when = fmt_date($booking['move_in']);
    $atMoveIn = max(0.0, round($booking['deposit'] + $booking['monthly'] - $booking['advance'], 2));
    $text .= "\n\nYour move-in date is recorded as $when (you will be reminded as it gets close)."
           . "\nYour reservation number is " . $booking['reservation_no'] . '.';
    if ($booking['advance'] > 0) {
        $parts = [];
        if ($booking['deposit'] > 0) {
            $parts[] = 'the security deposit of ' . money($booking['deposit']);
        }
        if ($booking['monthly'] > $booking['advance']) {
            $parts[] = 'the remaining ' . money($booking['monthly'] - $booking['advance']) . ' of the first month';
        }
        $text .= "\n\nTo secure your bed, pay the advance of " . money($booking['advance']) . ' (it counts toward your first month). '
               . ($parts ? 'At move-in you pay the rest: ' . implode(' and ', $parts) . (count($parts) > 1 ? ' (about ' . money($atMoveIn) . ' in all)' : '') . '.' : 'Nothing more is due at move-in.')
               . ' Rent is then due on the same date every month.';
    } else {
        $text .= "\n\nAt move-in you pay the security deposit of " . money($booking['deposit']) . ' and the first month of ' . money($booking['monthly']) . '. Rent is then due on the same date every month.';
    }
    $text .= "\n\n" . how_to_pay_text(['reservation_no' => $booking['reservation_no']]);
    return $text . "\n\n" . inquiry_signature();
}

/**
 * @return array{result:string, title:string, text:string, room?:array, free?:int}
 *   For 'confirmed' the caller adds the reservation details with inquiry_confirmed_text().
 */
function inquiry_auto_reply(?int $roomId, string $moveIn, ?string $gender, string $name, string $refCode): array
{
    $start = $moveIn >= today() ? $moveIn : today();
    $when = $start > today() ? 'from ' . fmt_date($start) : 'right now';
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
                'text' => inquiry_confirmed_text($room, $free, $name, $refCode, $start, null), 'room' => $room, 'free' => $free];
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
 * @param bool|null $created set to true when a NEW tenant record was made, false when an existing one was matched
 * @return int tenant id
 */
function inquiry_register_tenant(array $d, ?bool &$created = null): int
{
    $created = false;
    $existing = fetch_one("SELECT id, email, messenger FROM tenants WHERE contact_no = ? AND anonymized_at IS NULL ORDER BY id LIMIT 1", [$d['contact_no']]);
    if ($existing) {
        if (($existing['email'] === null || $existing['email'] === '') && !empty($d['email'])) {
            q("UPDATE tenants SET email = ? WHERE id = ?", [$d['email'], $existing['id']]);
        }
        if (($existing['messenger'] === null || $existing['messenger'] === '') && !empty($d['messenger'])) {
            q("UPDATE tenants SET messenger = ? WHERE id = ?", [$d['messenger'], $existing['id']]);
        }
        return (int)$existing['id'];
    }
    $occ = in_array($d['occupation'], ['student', 'employee'], true) ? $d['occupation'] : 'other';
    $source = ($d['source'] ?? 'inquiry') === 'admin' ? 'admin' : 'inquiry';
    q("INSERT INTO tenants (full_name, gender, contact_no, email, messenger, occupation, school_or_workplace, status, source, consent_at)
       VALUES (?,?,?,?,?,?,?, 'active', ?, " . ($source === 'inquiry' ? 'NOW()' : 'NULL') . ")",
      [$d['name'], $d['gender'], $d['contact_no'], $d['email'] ?: null, ($d['messenger'] ?? '') ?: null, $occ, $d['school'] ?: null, $source]);
    $id = (int)db()->lastInsertId();
    q("UPDATE tenants SET tenant_code = ? WHERE id = ?", ['T-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT), $id]);
    $created = true;
    log_activity('tenant_registered', 'tenant', $id, $d['name'] . ($source === 'inquiry' ? ' registered automatically from an inquiry' : ' registered automatically when a room was reserved'));
    return $id;
}

/**
 * Record the PENDING reservation that carries the move-in date and the reservation number used for payments.
 * One bed. Rent, deposit and advance come from the room, exactly like a reservation made by the landlord.
 * Only called for a person who was just registered, so a stranger typing someone else's contact number can never
 * be shown that person's reservation. Call inside a transaction.
 * @return array{id:int, reservation_no:string, move_in:string, monthly:float, deposit:float, advance:float, status:string}|null
 *         null when the person cannot be booked automatically (inactive tenant, or an open reservation already exists)
 */
function inquiry_create_booking(int $tenantId, array $room, string $moveIn, int $inquiryId, string $refCode): ?array
{
    $tenant = fetch_one("SELECT id, status FROM tenants WHERE id = ? AND anonymized_at IS NULL", [$tenantId]);
    if (!$tenant || $tenant['status'] !== 'active') {
        return null;
    }
    if (fetch_val("SELECT 1 FROM reservations WHERE tenant_id = ? AND status IN ('pending','confirmed','checked_in') LIMIT 1", [$tenantId])) {
        return null;
    }
    $rate = round((float)$room['monthly_rate'], 2);
    $dep = round((float)$room['deposit_amount'], 2);
    $adv = default_advance_amount($rate);
    q("INSERT INTO reservations (tenant_id, room_id, inquiry_id, beds, move_in_date, status, monthly_rate, deposit_amount, advance_amount, notes)
       VALUES (?,?,?,1,?, 'pending', ?,?,?,?)",
      [$tenantId, $room['id'], $inquiryId, $moveIn, $rate, $dep, $adv, 'Recorded automatically from inquiry ' . $refCode]);
    $nid = (int)db()->lastInsertId();
    $no = 'R' . date('Y') . '-' . str_pad((string)$nid, 4, '0', STR_PAD_LEFT);
    q("UPDATE reservations SET reservation_no = ? WHERE id = ?", [$no, $nid]);
    log_activity('reservation_created', 'reservation', $nid, $no . ' recorded automatically from inquiry ' . $refCode . ', Room ' . $room['room_no'] . ', move-in ' . $moveIn . ' (pending)');
    return ['id' => $nid, 'reservation_no' => $no, 'move_in' => $moveIn, 'monthly' => $rate, 'deposit' => $dep, 'advance' => $adv, 'status' => 'pending'];
}
