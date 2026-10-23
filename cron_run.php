<?php
/**
 * Runs the payment reminders. Open it from a scheduled task, an uptime/cron service,
 * or let the website call it for you (see README). Protected by a secret token.
 */
require __DIR__ . '/includes/bootstrap.php';
ignore_user_abort(true);
set_time_limit(120);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$token = (string)($_GET['token'] ?? '');
if (!hash_equals(cron_token(), $token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Wrong token.']);
    exit;
}
if (!claim_reminder_run(isset($_GET['now']) ? 0 : 3600)) {
    echo json_encode(['ok' => true, 'message' => 'Already checked recently.']);
    exit;
}
$r = run_reminders(false);
echo json_encode(['ok' => $r['ok'], 'message' => $r['message'], 'counts' => $r['counts']]);
