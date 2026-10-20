<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();

// Streams a tenant's GCash receipt screenshot. The files are not reachable by URL.
$id = get_int('id');
$file = (string)fetch_val("SELECT proof_file FROM payment_submissions WHERE id = ?", [$id]);
if ($file === '' || !preg_match('/^gcash_[a-f0-9]{16}\.(jpg|png|webp)$/', $file)) {
    abort(404);
}
$path = APP_ROOT . '/uploads/gcash/' . $file;
if (!is_file($path)) {
    abort(404);
}
$types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
header('Content-Type: ' . $types[pathinfo($file, PATHINFO_EXTENSION)]);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');
readfile($path);
