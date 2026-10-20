<?php
declare(strict_types=1);

/**
 * Authentication for the ONE administrator account.
 * Customers/tenants never log in - they only browse and send inquiries.
 */

function is_admin(): bool
{
    if (empty($_SESSION['admin_id'])) {
        return false;
    }
    $idle = (int)cfg('admin_idle_minutes', 30) * 60;
    if (isset($_SESSION['admin_last']) && (time() - (int)$_SESSION['admin_last']) > $idle) {
        admin_forget_session();
        return false;
    }
    return true;
}

function admin_forget_session(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', (bool)$p['secure'], true);
    }
    session_destroy();
    session_start();
}

/** Call at the top of every admin page. */
function require_admin(bool $allowPasswordChangeOnly = false): array
{
    if (!is_admin()) {
        $_SESSION['login_notice'] = 'Please sign in to continue.';
        redirect('?login=1');
    }
    $_SESSION['admin_last'] = time();

    $admin = fetch_one("SELECT id, username, full_name, email, must_change_password, last_login_at FROM admin WHERE id = 1");
    if (!$admin) {
        admin_forget_session();
        redirect('?login=1');
    }
    // Force the default password to be replaced before anything else.
    if ((int)$admin['must_change_password'] === 1 && !$allowPasswordChangeOnly) {
        flash('warn', 'For security, please set a new password before using the system.');
        redirect('admin/settings.php?tab=account');
    }
    $GLOBALS['ADMIN'] = $admin;
    return $admin;
}

function admin_login(string $username, string $password): array
{
    $max = (int)cfg('max_failed_logins', 5);
    $mins = (int)cfg('login_lock_minutes', 15);
    $ident = mb_strtolower($username);

    if (throttle_count('login', $mins, $ident) >= $max) {
        return [false, 'Too many failed attempts. Please wait ' . $mins . ' minutes and try again.'];
    }
    $admin = fetch_one("SELECT * FROM admin WHERE id = 1");
    $ok = false;
    // Always run a hash check so timing does not reveal whether the username exists.
    $hash = $admin['password_hash'] ?? password_hash('x', PASSWORD_DEFAULT);
    $passOk = password_verify($password, $hash);
    if ($admin && hash_equals(mb_strtolower((string)$admin['username']), $ident) && $passOk) {
        $ok = true;
    }
    if (!$ok) {
        throttle_hit('login', $ident);
        return [false, 'Incorrect username or password.'];
    }
    throttle_clear('login', $ident);
    session_regenerate_id(true);
    $_SESSION['admin_id'] = 1;
    $_SESSION['admin_last'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    if (password_needs_rehash($admin['password_hash'], PASSWORD_DEFAULT)) {
        q("UPDATE admin SET password_hash = ? WHERE id = 1", [password_hash($password, PASSWORD_DEFAULT)]);
    }
    q("UPDATE admin SET last_login_at = NOW() WHERE id = 1");
    log_activity('login', 'admin', 1, 'Signed in');
    return [true, ''];
}

function password_problems(string $pw): array
{
    $p = [];
    if (mb_strlen($pw) < 8) {
        $p[] = 'at least 8 characters';
    }
    if (!preg_match('/[A-Za-z]/', $pw)) {
        $p[] = 'a letter';
    }
    if (!preg_match('/\d/', $pw)) {
        $p[] = 'a number';
    }
    return $p;
}
