<?php
declare(strict_types=1);

/**
 * Loaded first by every page: config, time zone, sessions, security
 * headers, error handling, database and shared helpers.
 */

define('APP_ROOT', dirname(__DIR__));
$GLOBALS['PBH_CONFIG'] = require APP_ROOT . '/config/config.php';

function cfg(string $key, $default = null)
{
    $c = $GLOBALS['PBH_CONFIG'];
    foreach (explode('.', $key) as $part) {
        if (!is_array($c) || !array_key_exists($part, $c)) {
            return $default;
        }
        $c = $c[$part];
    }
    return $c;
}

date_default_timezone_set((string)cfg('timezone', 'Asia/Manila'));
mb_internal_encoding('UTF-8');

if (cfg('debug')) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
    ini_set('log_errors', '1');
}

/* Friendly error page instead of raw PHP errors ------------------- */
set_exception_handler(function (Throwable $ex): void {
    error_log('[PBH] ' . get_class($ex) . ': ' . $ex->getMessage() . ' @ ' . $ex->getFile() . ':' . $ex->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
    }
    $detail = cfg('debug') ? '<pre style="white-space:pre-wrap">' . htmlspecialchars((string)$ex, ENT_QUOTES, 'UTF-8') . '</pre>' : '';
    $isDb = ($ex instanceof PDOException);
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>Something went wrong</title>'
       . '<body style="font-family:system-ui,sans-serif;max-width:560px;margin:12vh auto;padding:0 20px;color:#26333a">'
       . '<h1 style="font-size:1.5rem">Something went wrong</h1>'
       . '<p>' . ($isDb
            ? 'The system could not reach its database. If you are the landlord, make sure MySQL is running and the database settings in <code>config/config.php</code> are correct.'
            : 'The page could not be shown. Please try again in a moment.')
       . '</p>' . $detail . '</body>';
    exit;
});

/* Base URL (where the site lives, e.g. "/pajuleras_bh" or "") ------ */
function base_url(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $cfg = (string)cfg('base_url', '');
    if ($cfg !== '') {
        return $base = rtrim($cfg, '/');
    }
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    if (basename($dir) === 'admin') {
        $dir = dirname($dir);
    }
    $dir = rtrim($dir, '/');
    return $base = ($dir === '.' ? '' : $dir);
}

function url(string $path = ''): string
{
    return base_url() . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    $file = APP_ROOT . '/' . ltrim($path, '/');
    $v = is_file($file) ? (string)filemtime($file) : '1';
    return url($path) . '?v=' . $v;
}

/* Sessions ---------------------------------------------------------- */
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
      || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

session_name('PBHSESSID');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_start();

/* Security headers -------------------------------------------------- */
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; font-src 'self'; form-action 'self'; frame-ancestors 'self'; base-uri 'self'");

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/art.php';
require_once __DIR__ . '/availability.php';
require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/parts.php';
require_once __DIR__ . '/uploads.php';
require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/reminders.php';
require_once __DIR__ . '/inquiry.php';
require_once __DIR__ . '/lifecycle.php';
require_once __DIR__ . '/track.php';
require_once __DIR__ . '/gcash.php';
require_once __DIR__ . '/chatbot.php';
require_once __DIR__ . '/widgets.php';
