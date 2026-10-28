<?php
declare(strict_types=1);

function render_flashes(): void
{
    foreach (pull_flashes() as $f) {
        $type = in_array($f['type'], ['success', 'error', 'warn', 'info'], true) ? $f['type'] : 'info';
        $role = $type === 'error' ? 'alert' : 'status';
        echo '<div class="flash flash-' . e($type) . '" role="' . $role . '"><span>' . e($f['msg']) . '</span><button type="button" class="flash-x" aria-label="Dismiss message" data-dismiss>&times;</button></div>';
    }
}

/** Tab icon (favicon) links shared by the public site and the admin panel. */
function favicon_links(): string
{
    return '<link rel="icon" type="image/svg+xml" href="' . e(url('assets/img/favicon.svg')) . '">' . "\n"
         . '<link rel="icon" type="image/png" sizes="32x32" href="' . e(url('assets/img/favicon-32.png')) . '">' . "\n"
         . '<link rel="shortcut icon" href="' . e(url('favicon.ico')) . '">' . "\n"
         . '<link rel="apple-touch-icon" sizes="180x180" href="' . e(url('assets/img/apple-touch-icon.png')) . '">' . "\n"
         . '<link rel="manifest" href="' . e(url('site.webmanifest')) . '">' . "\n";
}

function render_errors(array $errors): void
{
    if (!$errors) {
        return;
    }
    echo '<div class="flash flash-error" role="alert"><div><strong>Please fix the following:</strong><ul class="err-list">';
    foreach ($errors as $err) {
        echo '<li>' . e($err) . '</li>';
    }
    echo '</ul></div></div>';
}

/**
 * The landlord login lives ONLY on the landing page, as a pop-up (modal).
 * On the landing page the button opens it; on other pages it links there.
 */
/** Text-only call-to-action version of the landlord login (used in the navbar). */
function login_cta(string $class = ''): string
{
    if (is_admin()) {
        return '<a class="' . e($class) . '" href="' . e(url('admin/')) . '"><span>Dashboard</span></a>';
    }
    if (!empty($GLOBALS['LOGIN_MODAL'])) {
        return '<button type="button" class="' . e($class) . '" data-open-login><span>Landlord login</span></button>';
    }
    return '<a class="' . e($class) . '" href="' . e(url('?login=1')) . '"><span>Landlord login</span></a>';
}

/* ==================================================================
 *  PUBLIC WEBSITE
 * ================================================================== */
function public_header(string $title, string $active = '', string $description = ''): void
{
    $site = setting('site_name', 'Pajuleras Boarding House');
    $desc = $description !== '' ? $description : setting('tagline');
    $landing = !empty($GLOBALS['LOGIN_MODAL']);          // true only on the landing page
    $anchor = fn(string $id): string => $landing ? '#' . $id : url('#' . $id);
    if ($active === 'policies') {
        $active = 'services';
    }
    $nav = [
        'home'     => ['Home',     $landing ? '#home' : url(''), 'home'],
        'features' => ['Features', $anchor('features'), 'sparkle'],
        'services' => ['Services', $anchor('services'), 'layers'],
        'rooms'    => ['Rooms',    url('rooms.php'),    'door'],
        'pay'      => ['Pay by GCash', url('gcash_pay.php'), 'wallet'],
        'about'    => ['About',    $anchor('about'),    'info'],
        'contact'  => ['Contact',  $anchor('contact'),  'mail'],
    ];
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title === $site ? $site . ' | Rooms in Poblacion, Inabanga' : $title . ' | ' . $site) ?></title>
<meta name="description" content="<?= e($desc) ?>">
<meta name="theme-color" content="#12372a">
<?= favicon_links() ?><link rel="preload" href="<?= e(url('assets/fonts/fraunces-latin-wght-normal.woff2')) ?>" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="<?= e(url('assets/fonts/instrument-sans-latin-wght-normal.woff2')) ?>" as="font" type="font/woff2" crossorigin>
<script src="<?= e(asset('assets/js/boot.js')) ?>"></script>
<link rel="stylesheet" href="<?= e(asset('assets/css/base.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('assets/css/site.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('assets/css/widgets.css')) ?>">
<?php if ($landing): ?><link rel="stylesheet" href="<?= e(asset('assets/css/landing.css')) ?>"><?php endif; ?>
</head>
<body class="public<?= $landing ? ' landing' : '' ?>">
<a class="skip" href="#main">Skip to content</a>
<?php if ($landing): ?><span id="top-sentinel" class="sentinel" aria-hidden="true"></span><?php endif; ?>
<header class="site-header<?= $landing ? ' is-top' : '' ?>" data-header>
  <div class="wrap header-row">
    <a class="brand" href="<?= e($landing ? '#home' : url('')) ?>" aria-label="<?= e($site) ?>, home">
      <span class="brand-mark" aria-hidden="true"><?= icon('home') ?></span>
      <span class="brand-text"><strong><?= e($site) ?></strong><small><?= e(setting('address')) ?></small></span>
    </a>
    <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="site-nav" data-nav-toggle>
      <span class="burger" aria-hidden="true"><i></i><i></i><i></i></span><span class="sr-only">Menu</span>
    </button>
    <nav id="site-nav" class="site-nav" aria-label="Main">
      <ul class="nav-links">
        <?php foreach ($nav as $key => [$label, $href, $ic]): ?>
          <li><a class="nav-link<?= $key === 'pay' ? ' nav-link-pay' : '' ?><?= $active === $key ? ' is-active' : '' ?>" href="<?= e($href) ?>" data-nav="<?= e($key) ?>"<?= $key === 'pay' ? ' data-open-gcash' : '' ?><?= $active === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a></li>
        <?php endforeach; ?>
      </ul>
      <div class="nav-actions" data-menu-actions>
        <?= login_cta('btn btn-outline btn-cta') ?>
        <a class="btn btn-primary btn-cta" href="<?= e(url('inquire.php')) ?>"><span>Send an inquiry</span><?= icon('arrow') ?></a>
      </div>
    </nav>
  </div>
</header>
<main id="main">
<?php if (!empty($_SESSION['flash'])): ?>
  <div class="wrap flash-wrap"><?php render_flashes(); ?></div>
<?php endif;
}

function public_footer(): void
{
    $site = setting('site_name', 'Pajuleras Boarding House');
    $landing = !empty($GLOBALS['LOGIN_MODAL']);
    ?>
</main>
<footer class="site-footer">
  <div class="wrap foot-cta-wrap">
    <div class="foot-cta">
      <div><strong>Are you the landlord?</strong><span>Sign in to manage rooms, tenants, reservations and payments.</span></div>
      <?= login_cta('btn btn-accent btn-lg') ?>
    </div>
  </div>
  <div class="wrap footer-grid">
    <div class="foot-brand">
      <a class="brand brand-light" href="<?= e(url('')) ?>"><span class="brand-mark" aria-hidden="true"><?= icon('home') ?></span><span class="brand-text"><strong><?= e($site) ?></strong></span></a>
      <p><?= e(setting('tagline')) ?></p>
    </div>
    <div>
      <h2 class="foot-title">Explore</h2>
      <ul class="foot-links">
        <li><a href="<?= e(url('rooms.php')) ?>">Available rooms</a></li>
        <li><a href="<?= e(url('policies.php')) ?>">Rates &amp; house rules</a></li>
        <li><a href="<?= e(url('inquire.php')) ?>">Send an inquiry</a></li>
        <li><a href="<?= e(url('gcash_pay.php')) ?>" data-open-gcash>Pay with GCash</a></li>
        <li><a href="<?= e(url('track.php')) ?>">Check my inquiry</a></li>
      </ul>
    </div>
    <div>
      <h2 class="foot-title">Contact the landlord</h2>
      <ul class="foot-links foot-contact">
        <?php if (setting('address') !== ''): ?><li><?= icon('pin') ?><span><?= e(setting('address')) ?></span></li><?php endif; ?>
        <?php if (setting('phone') !== ''): ?><li><?= icon('phone') ?><span><?= e(setting('phone')) ?></span></li><?php endif; ?>
        <?php if (setting('email') !== ''): ?><li><?= icon('mail') ?><span><?= e(setting('email')) ?></span></li><?php endif; ?>
        <?php if (setting('contact_hours') !== ''): ?><li><?= icon('clock') ?><span><?= e(setting('contact_hours')) ?></span></li><?php endif; ?>
      </ul>
    </div>
  </div>
  <div class="wrap footer-base">
    <span>&copy; <?= date('Y') ?> <?= e($site) ?>. All rights reserved.</span>
  </div>
</footer>
<?php $onInquirePage = basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'inquire.php'; ?>
<?php if (!$onInquirePage) { render_inquiry_modal(); } ?>
<?php if (basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) !== 'gcash_pay.php') { render_gcash_modal(); } ?>
<?php if (basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) !== 'track.php') { render_track_modal(); } ?>
<?php render_chat_widget(); ?>
<?php $onGcashPage = basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'gcash_pay.php'; ?>
<div class="fab-dock">
<?php if (!$onInquirePage): ?>
  <a class="fab fab-late<?= $landing ? '' : ' is-visible' ?>" href="<?= e(url('inquire.php')) ?>" data-fab><?= icon('message') ?><span>Send an inquiry</span></a>
<?php endif; ?>
<?php if (!$onGcashPage): ?>
  <a class="fab fab-gcash" href="<?= e(url('gcash_pay.php')) ?>" data-open-gcash><?= icon('wallet') ?><span>Pay with GCash</span></a>
<?php endif; ?>
</div>
<a class="to-top tip tip-left" data-tip="Back to top" href="<?= e($landing ? '#home' : '#main') ?>" data-to-top><?= icon('arrow-up') ?><span class="sr-only">Back to top</span></a>
<script src="<?= e(asset('assets/js/app.js')) ?>" defer></script>
<script src="<?= e(asset('assets/js/site.js')) ?>" defer></script>
<script src="<?= e(asset('assets/js/widgets.js')) ?>" defer></script>
</body>
</html>
<?php
    if ($landing) {
        trigger_reminders_async();   // quietly checks payment reminders in the background (at most every 6 hours)
    }
}

/* ==================================================================
 *  ADMIN PANEL
 * ================================================================== */
function admin_header(string $title, string $active = '', array $opts = []): void
{
    if (is_modal()) {
        // pop-up request: send only the content, none of the page around it (and no extra queries)
        $GLOBALS['ADMIN_ICON'] = '';
        echo '<div class="mfrag" data-mtitle="' . e($title) . '">';
        return;
    }
    if (($_SESSION['hold_check'] ?? 0) < time() - 300) {
        $_SESSION['hold_check'] = time();
        expire_unpaid_holds();      // beds held without payment are released when the deadline passes
    }
    $site = setting('site_name', 'Pajuleras Boarding House');
    $admin = $GLOBALS['ADMIN'] ?? ['full_name' => 'Landlord', 'username' => 'admin'];
    $unread = 0;
    $gcashPending = gcash_pending_count();
    $manualCount = 0;
    $pendingRes = 0;
    try {
        $manualCount = (int)fetch_val("SELECT COUNT(*) FROM notifications WHERE status = 'manual'");
        $pendingRes = (int)fetch_val("SELECT COUNT(*) FROM reservations WHERE status = 'pending'");
    } catch (Throwable $e) {
        $manualCount = 0;
    }
    try {
        $unread = (int)fetch_val("SELECT COUNT(*) FROM inquiries WHERE admin_unread = 1 AND status <> 'closed'");
    } catch (Throwable $e) {
    }
    $groups = [
        'Overview' => [
            'dashboard'    => ['Dashboard', 'admin/index.php', 'dashboard'],
        ],
        'Tenants & rooms' => [
            'inquiries'    => ['Inquiries', 'admin/inquiries.php', 'inbox'],
            'reservations' => ['Reservations', 'admin/reservations.php', 'calendar'],
            'tenants'      => ['Tenants', 'admin/tenants.php', 'users'],
            'rooms'        => ['Rooms', 'admin/rooms.php', 'bed'],
        ],
        'Money' => [
            'payments'     => ['Payments', 'admin/payments.php', 'cash'],
            'gcash'        => ['GCash', 'admin/gcash.php', 'wallet'],
            'reminders'    => ['Reminders', 'admin/reminders.php', 'bell'],
            'reports'      => ['Reports', 'admin/reports.php', 'chart'],
        ],
        'System' => [
            'settings'     => ['Settings', 'admin/settings.php', 'sliders'],
            'activity'     => ['Activity', 'admin/activity.php', 'history'],
        ],
    ];
    $counts = ['inquiries' => $unread, 'gcash' => $gcashPending, 'reminders' => $manualCount, 'reservations' => $pendingRes];
    $GLOBALS['ADMIN_ICON'] = '';
    foreach ($groups as $items) {
        if (isset($items[$active])) {
            $GLOBALS['ADMIN_ICON'] = $items[$active][2];
        }
    }
    $alerts = array_values(array_filter([
        $unread > 0       ? ['inbox',    plural($unread, 'unread inquiry', 'unread inquiries'), 'admin/inquiries.php'] : null,
        $pendingRes > 0   ? ['calendar', plural($pendingRes, 'reservation') . ' to confirm', 'admin/reservations.php?status=pending'] : null,
        $gcashPending > 0 ? ['wallet',   plural($gcashPending, 'GCash report') . ' to review', 'admin/gcash.php'] : null,
        $manualCount > 0  ? ['bell',     plural($manualCount, 'reminder') . ' to send by hand', 'admin/reminders.php#by-hand'] : null,
    ]));
    $alertTotal = $unread + $pendingRes + $gcashPending + $manualCount;
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<?= favicon_links() ?><title><?= e($title) ?> | Admin | <?= e($site) ?></title>
<script src="<?= e(asset('assets/js/admin-boot.js')) ?>"></script>
<link rel="stylesheet" href="<?= e(asset('assets/css/base.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('assets/css/admin.css')) ?>">
</head>
<body class="admin">
<a class="skip" href="#main">Skip to content</a>
<div class="shell">
  <aside class="sidebar" id="sidebar">
    <a class="side-brand" href="<?= e(url('admin/')) ?>" title="<?= e($site) ?>">
      <span class="brand-mark" aria-hidden="true"><?= icon('home') ?></span>
      <span class="side-brand-text"><strong><?= e($site) ?></strong><small>Management system</small></span>
    </a>
    <nav class="side-nav" aria-label="Admin">
      <?php foreach ($groups as $groupName => $items): ?>
        <p class="side-group"><?= e($groupName) ?></p>
        <?php foreach ($items as $key => [$label, $href, $ic]): ?>
          <a href="<?= e(url($href)) ?>" title="<?= e($label) ?>"<?= $active === $key ? ' class="active" aria-current="page"' : '' ?>>
            <?= icon($ic) ?><span class="side-label"><?= e($label) ?></span>
            <?php if (!empty($counts[$key])): ?><span class="count"><?= (int)$counts[$key] ?></span><?php endif; ?>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </nav>
    <div class="side-foot">
      <a href="<?= e(url('')) ?>" target="_blank" rel="noopener" title="View public website"><?= icon('globe') ?><span class="side-label">View public website</span></a>
    </div>
  </aside>
  <div class="content">
    <header class="topbar">
      <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="sidebar" data-side-toggle aria-label="Open menu"><?= icon('menu') ?></button>
      <button class="side-collapse" type="button" data-side-collapse aria-label="Collapse or expand the menu" title="Collapse or expand the menu"><?= icon('menu') ?></button>
      <div class="topbar-title"><?= $GLOBALS['ADMIN_ICON'] ? icon($GLOBALS['ADMIN_ICON']) : '' ?><span><?= e($title) ?></span></div>

      <div class="gsearch" data-gsearch data-endpoint="<?= e(url('admin/search.php')) ?>" role="search">
        <span class="gs-ic" aria-hidden="true"><?= icon('search') ?></span>
        <input id="gs-input" type="search" placeholder="Search tenants, reservations, rooms…" autocomplete="off" spellcheck="false" aria-label="Search" aria-controls="gs-results" aria-expanded="false">
        <kbd class="gs-key" aria-hidden="true">/</kbd>
        <div class="gs-results" id="gs-results" role="listbox" hidden></div>
      </div>

      <div class="dd" data-dd>
        <button class="btn btn-primary dd-btn" type="button" data-dd-toggle aria-haspopup="true" aria-expanded="false"><?= icon('plus') ?><span>New</span></button>
        <div class="dd-menu" role="menu" hidden>
          <a role="menuitem" href="<?= e(url('admin/reservation_form.php')) ?>"><?= icon('calendar') ?><span>Reservation</span></a>
          <a role="menuitem" href="<?= e(url('admin/payment_form.php')) ?>"><?= icon('cash') ?><span>Payment</span></a>
          <a role="menuitem" href="<?= e(url('admin/tenant_form.php')) ?>"><?= icon('users') ?><span>Tenant</span></a>
          <a role="menuitem" href="<?= e(url('admin/room_form.php')) ?>"><?= icon('bed') ?><span>Room</span></a>
        </div>
      </div>

      <div class="dd" data-dd>
        <button class="icon-btn bell" type="button" data-dd-toggle aria-haspopup="true" aria-expanded="false" aria-label="Alerts<?= $alertTotal ? ', ' . $alertTotal . ' need attention' : '' ?>"><?= icon('bell') ?><?php if ($alertTotal > 0): ?><span class="bell-count"><?= $alertTotal > 99 ? '99+' : $alertTotal ?></span><?php endif; ?></button>
        <div class="dd-menu dd-alerts" role="menu" hidden>
          <p class="dd-title">Needs your attention</p>
          <?php if ($alerts): foreach ($alerts as [$ai, $at, $ah]): ?>
            <a role="menuitem" href="<?= e(url($ah)) ?>"><?= icon($ai) ?><span><?= e($at) ?></span></a>
          <?php endforeach; else: ?>
            <p class="dd-empty"><?= icon('check') ?><span>All caught up</span></p>
          <?php endif; ?>
        </div>
      </div>

      <div class="topbar-user">
        <span class="tu-name"><?= e($admin['full_name']) ?></span>
        <form method="post" action="<?= e(url('admin/logout.php')) ?>">
          <?= csrf_field() ?>
          <button class="btn btn-sm btn-ghost" type="submit" title="Sign out"><?= icon('logout') ?><span class="tu-out">Sign out</span></button>
        </form>
      </div>
    </header>
    <main id="main" class="page">
      <?php render_flashes(); ?>
<?php
}

function admin_footer(): void
{
    if (is_modal()) {
        echo '</div>';
        return;
    }
    ?>
    </main>
  </div>
</div>
<script src="<?= e(asset('assets/js/app.js')) ?>" defer></script>
<script src="<?= e(asset('assets/js/admin.js')) ?>" defer></script>
</body>
</html>
<?php
}

/** Page heading with optional action buttons (HTML passed in $actions is trusted). */
function page_head(string $title, string $sub = '', string $actions = ''): void
{
    if (is_modal()) {
        echo $sub !== '' ? '<p class="m-sub">' . e($sub) . '</p>' : '';
        return;
    }
    $ic = (string)($GLOBALS['ADMIN_ICON'] ?? '');
    echo '<div class="page-head"><div class="page-head-main">' . ($ic !== '' ? '<span class="page-ic">' . icon($ic) . '</span>' : '')
       . '<div><h1>' . e($title) . '</h1>' . ($sub !== '' ? '<p class="sub">' . e($sub) . '</p>' : '') . '</div></div>';
    if ($actions !== '') {
        echo '<div class="page-actions">' . $actions . '</div>';
    }
    echo '</div>';
}
