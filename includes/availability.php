<?php
declare(strict_types=1);

/**
 * Room availability & overlap protection.
 *
 * A reservation HOLDS beds only when its status is 'confirmed' or
 * 'checked_in'.  Pending reservations hold nothing.
 *
 * A stay occupies the half-open date range [move_in_date, move_out_date).
 * A blank move_out_date means "open-ended / long-term".
 * Because the range is half-open, a new tenant may move in on the very
 * day another tenant moves out.
 */

const OPEN_END = '9999-12-31';

/** Lock a room row for the rest of the transaction and return it. */
function lock_room(int $roomId): array
{
    $room = fetch_one("SELECT * FROM rooms WHERE id = ? FOR UPDATE", [$roomId]);
    if (!$room) {
        throw new RuleException('That room does not exist.');
    }
    return $room;
}

/** The date ranges (with bed counts) that currently hold beds in a room. */
function room_holds(int $roomId, ?int $excludeReservationId = null): array
{
    $sql = "SELECT id, beds, move_in_date, move_out_date, status, checked_in_at
              FROM reservations
             WHERE room_id = ? AND status IN ('confirmed','checked_in')";
    $p = [$roomId];
    if ($excludeReservationId) {
        $sql .= " AND id <> ?";
        $p[] = $excludeReservationId;
    }
    $holds = [];
    foreach (fetch_all($sql, $p) as $r) {
        $start = $r['move_in_date'];
        $end = $r['move_out_date'] ?: OPEN_END;
        if ($r['status'] === 'checked_in') {
            $ci = substr((string)$r['checked_in_at'], 0, 10);
            if ($ci !== '' && $ci < $start) {
                $start = $ci;              // moved in earlier than planned
            }
            if ($end < today()) {
                $end = OPEN_END;           // still living there past the planned move-out
            }
        }
        $holds[] = ['start' => $start, 'end' => $end, 'beds' => (int)$r['beds']];
    }
    return $holds;
}

/** Highest number of beds in use at any moment inside [$start, $end). */
function peak_beds_in_use(array $holds, string $start, string $end): int
{
    $points = [$start];
    foreach ($holds as $h) {
        if ($h['start'] > $start && $h['start'] < $end) {
            $points[] = $h['start'];
        }
    }
    $peak = 0;
    foreach (array_unique($points) as $p) {
        $used = 0;
        foreach ($holds as $h) {
            if ($h['start'] <= $p && $p < $h['end']) {
                $used += $h['beds'];
            }
        }
        $peak = max($peak, $used);
    }
    return $peak;
}

/**
 * Beds still free for the whole period [$start, $end).
 * $end = null means open-ended.  May be negative if already over-booked.
 */
function beds_free(int $roomId, int $capacity, string $start, ?string $end = null, ?int $excludeReservationId = null): int
{
    $end = $end ?: OPEN_END;
    $holds = room_holds($roomId, $excludeReservationId);
    return $capacity - peak_beds_in_use($holds, $start, $end);
}

/**
 * Throws RuleException if $beds beds are not free for the whole period.
 * Call inside a transaction AFTER lock_room() so two requests cannot
 * both grab the last bed.
 */
function assert_capacity(array $room, string $start, ?string $end, int $beds, ?int $excludeReservationId = null): void
{
    if ($room['status'] !== 'active') {
        throw new RuleException('Room ' . $room['room_no'] . ' is under maintenance and cannot take reservations.');
    }
    if ($beds < 1 || $beds > (int)$room['capacity']) {
        throw new RuleException('Room ' . $room['room_no'] . ' has ' . plural((int)$room['capacity'], 'bed') . ', so ' . $beds . ' cannot be reserved.');
    }
    $free = beds_free((int)$room['id'], (int)$room['capacity'], $start, $end, $excludeReservationId);
    if ($free < $beds) {
        $msg = 'Room ' . $room['room_no'] . ' does not have enough free beds for ' . fmt_date($start)
             . ($end ? ' to ' . fmt_date($end) : ' onward')
             . ' (needs ' . plural($beds, 'bed') . ', ' . max(0, $free) . ' free). It is already reserved or occupied.';
        throw new RuleException($msg);
    }
}

/** Every room with its live bed counts (used by the public site and dashboard). */
function rooms_with_status(bool $publicOnly = false, ?int $onlyId = null): array
{
    $sql = "SELECT r.*,
                   COALESCE(SUM(CASE WHEN v.status = 'checked_in' THEN v.beds END), 0) AS occupied_beds,
                   COALESCE(SUM(CASE WHEN v.status = 'confirmed'  THEN v.beds END), 0) AS reserved_beds,
                   COALESCE(SUM(CASE WHEN v.status = 'pending'    THEN 1 END), 0)      AS pending_count
              FROM rooms r
              LEFT JOIN reservations v ON v.room_id = r.id AND v.status IN ('pending','confirmed','checked_in')";
    $where = [];
    $p = [];
    if ($publicOnly) {
        $where[] = "r.is_public = 1";
    }
    if ($onlyId !== null) {
        $where[] = "r.id = ?";
        $p[] = $onlyId;
    }
    if ($where) {
        $sql .= " WHERE " . implode(' AND ', $where);
    }
    $sql .= " GROUP BY r.id ORDER BY LENGTH(r.room_no), r.room_no";
    $rows = fetch_all($sql, $p);
    foreach ($rows as &$r) {
        $r['occupied_beds'] = (int)$r['occupied_beds'];
        $r['reserved_beds'] = (int)$r['reserved_beds'];
        $r['pending_count'] = (int)$r['pending_count'];
        $r['open_beds'] = max(0, (int)$r['capacity'] - $r['occupied_beds'] - $r['reserved_beds']);
        [$r['status_key'], $r['status_label']] = room_status($r);
    }
    unset($r);
    return $rows;
}

/** @return array{0:string,1:string} [css key, label] */
function room_status(array $r): array
{
    if ($r['status'] === 'maintenance') {
        return ['maintenance', 'Under maintenance'];
    }
    $open = (int)$r['capacity'] - (int)$r['occupied_beds'] - (int)$r['reserved_beds'];
    if ($open > 0) {
        return ['available', 'Available'];
    }
    if ((int)$r['occupied_beds'] >= (int)$r['capacity']) {
        return ['occupied', 'Occupied'];
    }
    return ['reserved', 'Reserved'];
}

/** Small bed-pip graphic: filled = occupied, half = reserved, outline = open. */
function bed_pips(array $r): string
{
    $out = '<span class="pips" role="img" aria-label="' . e($r['occupied_beds'] . ' occupied, ' . $r['reserved_beds'] . ' reserved, ' . $r['open_beds'] . ' open') . '">';
    $i = 0;
    for ($k = 0; $k < $r['occupied_beds']; $k++, $i++) {
        $out .= '<i class="pip pip-occupied"></i>';
    }
    for ($k = 0; $k < $r['reserved_beds']; $k++, $i++) {
        $out .= '<i class="pip pip-reserved"></i>';
    }
    for ($k = 0; $i < (int)$r['capacity']; $k++, $i++) {
        $out .= '<i class="pip pip-open"></i>';
    }
    return $out . '</span>';
}
