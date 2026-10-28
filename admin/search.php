<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();

// Search box in the top bar. Admin only, at least 2 characters, a few matches per group.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');

$q = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 60));
if (mb_strlen($q) < 2) {
    echo json_encode(['groups' => []]);
    exit;
}
$like = '%' . addcslashes($q, '%_\\') . '%';
$groups = [];

$rows = fetch_all("SELECT id, full_name, tenant_code, contact_no FROM tenants
                    WHERE anonymized_at IS NULL AND (full_name LIKE ? OR contact_no LIKE ? OR tenant_code LIKE ? OR email LIKE ?)
                    ORDER BY full_name LIMIT 5", [$like, $like, $like, $like]);
if ($rows) {
    $groups[] = ['label' => 'Tenants', 'icon' => 'users', 'items' => array_map(fn($r) => [
        'title' => $r['full_name'], 'sub' => trim(($r['tenant_code'] ?? '') . ' · ' . $r['contact_no'], ' ·'), 'url' => url('admin/tenant_view.php?id=' . (int)$r['id'])], $rows)];
}
$rows = fetch_all("SELECT v.id, v.reservation_no, v.status, t.full_name, r.room_no
                     FROM reservations v JOIN tenants t ON t.id = v.tenant_id JOIN rooms r ON r.id = v.room_id
                    WHERE v.reservation_no LIKE ? OR t.full_name LIKE ? OR t.contact_no LIKE ?
                    ORDER BY v.id DESC LIMIT 5", [$like, $like, $like]);
if ($rows) {
    $groups[] = ['label' => 'Reservations', 'icon' => 'calendar', 'items' => array_map(fn($r) => [
        'title' => $r['reservation_no'] . ' · ' . $r['full_name'], 'sub' => 'Room ' . $r['room_no'] . ' · ' . ucfirst(str_replace('_', ' ', $r['status'])), 'url' => url('admin/reservation_view.php?id=' . (int)$r['id'])], $rows)];
}
$rows = fetch_all("SELECT id, room_no, room_name FROM rooms WHERE room_no LIKE ? OR room_name LIKE ? ORDER BY room_no + 0, room_no LIMIT 4", [$like, $like]);
if ($rows) {
    $groups[] = ['label' => 'Rooms', 'icon' => 'bed', 'items' => array_map(fn($r) => [
        'title' => 'Room ' . $r['room_no'], 'sub' => (string)($r['room_name'] ?? ''), 'url' => url('admin/room_form.php?id=' . (int)$r['id'])], $rows)];
}
$rows = fetch_all("SELECT id, ref_code, name, subject FROM inquiries WHERE ref_code LIKE ? OR name LIKE ? OR contact_no LIKE ? ORDER BY id DESC LIMIT 4", [$like, $like, $like]);
if ($rows) {
    $groups[] = ['label' => 'Inquiries', 'icon' => 'inbox', 'items' => array_map(fn($r) => [
        'title' => $r['name'], 'sub' => $r['ref_code'] . ' · ' . $r['subject'], 'url' => url('admin/inquiry_view.php?id=' . (int)$r['id'])], $rows)];
}
echo json_encode(['groups' => $groups], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
