<?php
declare(strict_types=1);

/** Thrown for business-rule failures (shown to the user, not logged as errors). */
class RuleException extends Exception {}

/* ------------------------------------------------------------------
 *  Output & formatting
 * ------------------------------------------------------------------ */
function e($v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function money($v): string
{
    return '₱' . number_format((float)$v, 2);
}

function money_short($v): string
{
    $v = (float)$v;
    return '₱' . number_format($v, ($v == floor($v)) ? 0 : 2);
}

function fmt_date(?string $d, string $fallback = '—'): string
{
    if (!$d || $d === '0000-00-00') {
        return $fallback;
    }
    $t = strtotime($d);
    return $t ? date('M j, Y', $t) : $fallback;
}

function fmt_datetime(?string $d, string $fallback = '—'): string
{
    if (!$d) {
        return $fallback;
    }
    $t = strtotime($d);
    return $t ? date('M j, Y g:i A', $t) : $fallback;
}

function today(): string
{
    return date('Y-m-d');
}

function nl2p(string $text): string
{
    return nl2br(e($text), false);
}

/** Split a multi-line setting into a clean array of non-empty lines. */
function lines(?string $text): array
{
    if ($text === null || trim($text) === '') {
        return [];
    }
    $out = [];
    foreach (preg_split('/\R/u', $text) as $l) {
        $l = trim($l);
        if ($l !== '') {
            $out[] = $l;
        }
    }
    return $out;
}

function plural(int $n, string $one, ?string $many = null): string
{
    return $n . ' ' . ($n === 1 ? $one : ($many ?? $one . 's'));
}

/* ------------------------------------------------------------------
 *  Requests, redirects, flash messages
 * ------------------------------------------------------------------ */
function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function redirect(string $path): void
{
    if (!preg_match('#^https?://#i', $path)) {
        $path = url($path);
    }
    header('Location: ' . $path);
    exit;
}

/** Only allow redirects to pages inside this site (prevents open redirects). */
function safe_return(?string $r, string $default): string
{
    if ($r && preg_match('#^[a-zA-Z0-9_\-./?=&%]+$#', $r) && strpos($r, '..') === false && $r[0] !== '/') {
        return $r;
    }
    return $default;
}

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function pull_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function client_ip(): string
{
    // REMOTE_ADDR only: forwarded headers are trivially spoofed.
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

function abort(int $code, string $message = ''): void
{
    http_response_code($code);
    $titles = [403 => 'Not allowed', 404 => 'Page not found', 419 => 'Session expired', 429 => 'Slow down'];
    $title = $titles[$code] ?? 'Error';
    $msg = $message !== '' ? $message : match ($code) {
        404 => 'We could not find what you were looking for.',
        419 => 'Your form session expired. Please go back, refresh the page, and try again.',
        default => 'This request could not be completed.',
    };
    $inAdmin = (basename(dirname($_SERVER['SCRIPT_NAME'] ?? '')) === 'admin');
    if ($inAdmin && is_admin()) {
        admin_header($title, '');
        echo '<div class="panel"><h2>' . e($title) . '</h2><p>' . e($msg) . '</p><p><a class="btn" href="' . e(url('admin/')) . '">Back to dashboard</a></p></div>';
        admin_footer();
    } else {
        public_header($title, '');
        echo '<section class="section"><div class="wrap narrow"><h1>' . e($title) . '</h1><p>' . e($msg) . '</p><p><a class="btn" href="' . e(url('')) . '">Back to home</a></p></div></section>';
        public_footer();
    }
    exit;
}

/* ------------------------------------------------------------------
 *  CSRF
 * ------------------------------------------------------------------ */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): void
{
    $sent = (string)($_POST['_csrf'] ?? '');
    if ($sent === '' || !hash_equals(csrf_token(), $sent)) {
        abort(419);
    }
}

/* ------------------------------------------------------------------
 *  Input helpers
 * ------------------------------------------------------------------ */
function post_str(string $key, int $max = 255): string
{
    $v = $_POST[$key] ?? '';
    if (!is_string($v)) {
        return '';
    }
    $v = trim(str_replace("\0", '', $v));
    return mb_substr($v, 0, $max);
}

function get_str(string $key, int $max = 100): string
{
    $v = $_GET[$key] ?? '';
    if (!is_string($v)) {
        return '';
    }
    return mb_substr(trim(str_replace("\0", '', $v)), 0, $max);
}

function get_int(string $key): int
{
    return isset($_GET[$key]) && is_numeric($_GET[$key]) ? (int)$_GET[$key] : 0;
}

function post_int(string $key): int
{
    return isset($_POST[$key]) && is_numeric($_POST[$key]) ? (int)$_POST[$key] : 0;
}

/** Money input: accepts "1,500.50" ; returns null when invalid. */
function parse_money(string $s): ?float
{
    $s = str_replace([',', ' ', '₱'], '', $s);
    if ($s === '' || !preg_match('/^\d{1,8}(\.\d{1,2})?$/', $s)) {
        return null;
    }
    return round((float)$s, 2);
}

function valid_date(?string $s): bool
{
    if (!$s || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
        return false;
    }
    $d = DateTime::createFromFormat('!Y-m-d', $s);
    return $d && $d->format('Y-m-d') === $s;
}

/** Digits-only phone, PH numbers written as +63 9XX are turned into 09XX. */
function normalize_phone(string $s): string
{
    $d = preg_replace('/\D+/', '', $s) ?? '';
    if (strlen($d) === 12 && str_starts_with($d, '63')) {
        $d = '0' . substr($d, 2);
    }
    return $d;
}

function valid_phone(string $normalized): bool
{
    return (bool)preg_match('/^\d{7,15}$/', $normalized);
}

function valid_email(string $s): bool
{
    return $s !== '' && filter_var($s, FILTER_VALIDATE_EMAIL) !== false && mb_strlen($s) <= 150;
}

/** Re-display a submitted value in a form. */
function old(string $key, $default = '')
{
    global $OLD;
    if (isset($OLD) && is_array($OLD) && array_key_exists($key, $OLD)) {
        return $OLD[$key];
    }
    return $default;
}

function selected($a, $b): string
{
    return ((string)$a === (string)$b) ? ' selected' : '';
}

function checked($cond): string
{
    return $cond ? ' checked' : '';
}

/* ------------------------------------------------------------------
 *  Settings (boarding house info shown on the public site)
 * ------------------------------------------------------------------ */
function settings_all(): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (fetch_all("SELECT setting_key, setting_value FROM settings") as $r) {
            $cache[$r['setting_key']] = (string)$r['setting_value'];
        }
    }
    return $cache;
}

function setting(string $key, string $default = ''): string
{
    $all = settings_all();
    return array_key_exists($key, $all) && $all[$key] !== '' ? $all[$key] : $default;
}

/* ------------------------------------------------------------------
 *  Audit log
 * ------------------------------------------------------------------ */
function log_activity(string $action, ?string $entity = null, ?int $entityId = null, ?string $details = null): void
{
    try {
        q("INSERT INTO activity_log (action, entity, entity_id, details, ip) VALUES (?,?,?,?,?)",
          [$action, $entity, $entityId, $details !== null ? mb_substr($details, 0, 500) : null, client_ip()]);
    } catch (Throwable $e) {
        error_log('[PBH] activity log failed: ' . $e->getMessage());
    }
}

/* ------------------------------------------------------------------
 *  Throttling (login attempts, inquiry spam, tracking guesses)
 * ------------------------------------------------------------------ */
function throttle_count(string $kind, int $minutes, ?string $identifier = null): int
{
    $sql = "SELECT COUNT(*) FROM throttle WHERE kind = ? AND ip = ? AND created_at > (NOW() - INTERVAL " . (int)$minutes . " MINUTE)";
    $p = [$kind, client_ip()];
    if ($identifier !== null) {
        $sql .= " AND identifier = ?";
        $p[] = $identifier;
    }
    return (int)fetch_val($sql, $p);
}

function throttle_hit(string $kind, ?string $identifier = null): void
{
    q("INSERT INTO throttle (kind, ip, identifier) VALUES (?,?,?)", [$kind, client_ip(), $identifier !== null ? mb_substr($identifier, 0, 120) : null]);
    if (random_int(1, 50) === 1) { // occasional cleanup
        q("DELETE FROM throttle WHERE created_at < (NOW() - INTERVAL 2 DAY)");
    }
}

function throttle_clear(string $kind, ?string $identifier = null): void
{
    $sql = "DELETE FROM throttle WHERE kind = ? AND ip = ?";
    $p = [$kind, client_ip()];
    if ($identifier !== null) {
        $sql .= " AND identifier = ?";
        $p[] = $identifier;
    }
    q($sql, $p);
}

/* ------------------------------------------------------------------
 *  Pagination
 * ------------------------------------------------------------------ */
function paginate(int $total, int $perPage = 15): array
{
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min(max(1, get_int('page')), $pages);
    return ['page' => $page, 'pages' => $pages, 'per' => $perPage, 'offset' => ($page - 1) * $perPage, 'total' => $total];
}

function pager(array $p): string
{
    if ($p['pages'] <= 1) {
        return '';
    }
    $qs = $_GET;
    unset($qs['page']);
    $mk = function (int $n, string $label, bool $active = false, bool $disabled = false) use ($qs): string {
        if ($disabled) {
            return '<span class="pg disabled">' . $label . '</span>';
        }
        $q = http_build_query(array_merge($qs, ['page' => $n]));
        return '<a class="pg' . ($active ? ' active' : '') . '" href="?' . e($q) . '"' . ($active ? ' aria-current="page"' : '') . '>' . $label . '</a>';
    };
    $out = '<nav class="pager" aria-label="Pages">';
    $out .= $mk($p['page'] - 1, 'Previous', false, $p['page'] <= 1);
    $start = max(1, $p['page'] - 2);
    $end = min($p['pages'], $p['page'] + 2);
    if ($start > 1) {
        $out .= $mk(1, '1') . ($start > 2 ? '<span class="pg gap">…</span>' : '');
    }
    for ($i = $start; $i <= $end; $i++) {
        $out .= $mk($i, (string)$i, $i === $p['page']);
    }
    if ($end < $p['pages']) {
        $out .= ($end < $p['pages'] - 1 ? '<span class="pg gap">…</span>' : '') . $mk($p['pages'], (string)$p['pages']);
    }
    $out .= $mk($p['page'] + 1, 'Next', false, $p['page'] >= $p['pages']);
    return $out . '<span class="pager-info">' . number_format($p['total']) . ' records</span></nav>';
}

/* ------------------------------------------------------------------
 *  Status badges & labels
 * ------------------------------------------------------------------ */
function badge(string $text, string $tone = 'neutral'): string
{
    return '<span class="badge badge-' . e($tone) . '">' . e($text) . '</span>';
}

function reservation_badge(string $status): string
{
    $map = [
        'pending'    => ['Pending', 'warn'],
        'confirmed'  => ['Confirmed', 'info'],
        'checked_in' => ['Checked in', 'ok'],
        'completed'  => ['Completed', 'neutral'],
        'cancelled'  => ['Cancelled', 'bad'],
    ];
    [$t, $tone] = $map[$status] ?? [ucfirst($status), 'neutral'];
    return badge($t, $tone);
}

function inquiry_badge(string $status): string
{
    $map = ['new' => ['New', 'warn'], 'replied' => ['Replied', 'info'], 'converted' => ['Reserved', 'ok'], 'closed' => ['Closed', 'neutral']];
    [$t, $tone] = $map[$status] ?? [ucfirst($status), 'neutral'];
    return badge($t, $tone);
}

function payment_type_label(string $t): string
{
    return ['advance' => 'Advance payment', 'deposit' => 'Security deposit', 'rent' => 'Monthly rent', 'other' => 'Other', 'refund' => 'Refund'][$t] ?? ucfirst($t);
}

function method_label(string $m): string
{
    return ['cash' => 'Cash', 'gcash' => 'GCash', 'bank_transfer' => 'Bank transfer', 'other' => 'Other'][$m] ?? ucfirst($m);
}

function gender_label(string $g): string
{
    return ['any' => 'Anyone', 'male' => 'Male only', 'female' => 'Female only'][$g] ?? ucfirst($g);
}

function occupation_label(?string $o): string
{
    return ['student' => 'Student', 'employee' => 'Employee', 'other' => 'Other'][$o ?? ''] ?? '—';
}

/* ------------------------------------------------------------------
 *  CSV helper (guards against spreadsheet formula injection)
 * ------------------------------------------------------------------ */
function csv_cell($v): string
{
    $s = (string)($v ?? '');
    if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true) && !is_numeric($s)) {
        $s = "'" . $s;
    }
    return $s;
}

function csv_download(string $filename, array $header, array $rows): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $filename) . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows ₱ and accents correctly
    fputcsv($out, array_map('csv_cell', $header));
    foreach ($rows as $r) {
        fputcsv($out, array_map('csv_cell', $r));
    }
    fclose($out);
    exit;
}

/** Escape % and _ so user input is matched literally in LIKE searches. */
function like_escape(string $s): string
{
    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s) . '%';
}
