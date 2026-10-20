<?php
/**
 * Sign-in handler for the landlord. There is no separate login page:
 * the form lives in a pop-up (modal) on the landing page, which posts here.
 * Opening this address directly just takes you to the landing page.
 */
require __DIR__ . '/../includes/bootstrap.php';

if (!is_post()) {
    redirect(is_admin() ? 'admin/' : '?login=1');
}
csrf_verify();

$username = post_str('username', 50);
[$ok, $msg] = admin_login($username, (string)($_POST['password'] ?? ''));
if ($ok) {
    redirect('admin/');
}
$_SESSION['login_error'] = $msg;
$_SESSION['login_user'] = $username;
redirect('?login=1');
