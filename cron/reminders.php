<?php
/**
 * Command-line version of the reminder check:
 *    php cron/reminders.php
 * Schedule it once a day (see README). It cannot be opened from a browser.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../includes/bootstrap.php';
$r = run_reminders(false);
echo date('Y-m-d H:i:s') . '  ' . $r['message'] . PHP_EOL;
foreach ($r['results'] as $x) {
    echo '  - ' . $x['tenant'] . ' (' . $x['reservation'] . ', ' . $x['state'] . '): ' . $x['status'] . ($x['note'] ? ', ' . $x['note'] : '') . PHP_EOL;
}
