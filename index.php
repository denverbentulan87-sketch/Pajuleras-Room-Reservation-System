<?php
require __DIR__ . '/includes/bootstrap.php';

// The landlord login is a pop-up on this page only.
if (is_admin() && get_str('login') === '1') {
    redirect('admin/');
}
$GLOBALS['LOGIN_MODAL'] = true;
$loginError  = (string)($_SESSION['login_error'] ?? '');
$loginNotice = (string)($_SESSION['login_notice'] ?? '');
$loginUser   = (string)($_SESSION['login_user'] ?? '');
unset($_SESSION['login_error'], $_SESSION['login_notice'], $_SESSION['login_user']);
$openLogin = get_str('login') === '1' || $loginError !== '';

/* ---- live data for the page ---------------------------------------- */
$rooms       = rooms_with_status(true);
$active      = array_values(array_filter($rooms, fn($r) => $r['status'] === 'active'));
$openBeds    = array_sum(array_column($active, 'open_beds'));
$roomsOpen   = count(array_filter($active, fn($r) => $r['open_beds'] > 0));
$totalBeds   = array_sum(array_map(fn($r) => (int)$r['capacity'], $active));
$minRate     = $active ? min(array_map(fn($r) => (float)$r['monthly_rate'], $active)) : 0;
$minBy = ['shared' => null, 'private' => null];
foreach ($active as $r) {
    $k = $r['room_type'];
    $minBy[$k] = $minBy[$k] === null ? (float)$r['monthly_rate'] : min($minBy[$k], (float)$r['monthly_rate']);
}
$shown       = array_slice($rooms, 0, 6);
$housePhoto  = house_photo_url();
$heroRoom    = null;
foreach ($active as $r) { if (room_has_photo($r)) { $heroRoom = $r; break; } }
$phone       = setting('phone');
$phoneHref   = 'tel:' . preg_replace('/[^0-9+]/', '', $phone);
$advMonths   = max(0, (int)setting('advance_months', '1'));
$advText     = $advMonths === 0 ? 'no advance payment' : ($advMonths === 1 ? 'one month of rent' : $advMonths . ' months of rent');

/** Reusable small components -------------------------------------------- */
function feature_card(string $ic, string $title, string $text, int $delay): void
{
    echo '<article class="feature" data-reveal style="--d:' . $delay . 'ms">'
       . '<span class="icon-badge">' . icon($ic) . '</span>'
       . '<h3>' . e($title) . '</h3><p>' . e($text) . '</p></article>';
}

public_header(setting('site_name', 'Pajuleras Boarding House'), 'home');
?>

<!-- ===================== HERO ===================== -->
<section id="home" class="hero" aria-labelledby="hero-title">
  <div class="hero-bg" aria-hidden="true"></div>
  <div class="wrap hero-grid">
    <div class="hero-copy">
      <p class="eyebrow" data-reveal><span class="live-dot" aria-hidden="true"></span><?= e(setting('address')) ?></p>
      <h1 id="hero-title" data-reveal style="--d:80ms">Find your room in <em>Poblacion, Inabanga.</em></h1>
      <p class="lead" data-reveal style="--d:160ms">Shared and private rooms for students and working professionals. See which beds are open right now, ask the landlord anything, and reserve in a few clear steps.</p>
      <div class="hero-actions" data-reveal style="--d:240ms">
        <a class="btn btn-primary btn-lg" href="#services"><span>Browse available rooms</span><?= icon('arrow') ?></a>
        <a class="btn btn-outline btn-lg" href="<?= e(url('inquire.php')) ?>"><?= icon('message') ?><span>Send an inquiry</span></a>
      </div>
      <p class="hero-gcash" data-reveal style="--d:280ms"><a href="<?= e(url('gcash_pay.php')) ?>" data-open-gcash><?= icon('wallet') ?><span>Already a tenant? <strong>Pay your rent by GCash</strong></span><?= icon('arrow') ?></a></p>
      <ul class="hero-points" data-reveal style="--d:320ms">
        <li><?= icon('shield') ?><span>No account needed</span></li>
        <li><?= icon('bed') ?><span>Live availability</span></li>
        <li><?= icon('message') ?><span>Landlord replies</span></li>
      </ul>
    </div>

    <div class="hero-visual" data-reveal="right" style="--d:160ms">
      <div class="hv-main parallax<?= $housePhoto ? ' has-photo' : '' ?>"><?= $housePhoto ? zoom_photo($housePhoto, 'Front of ' . setting('site_name', 'the boarding house'), 1400, 1000, true) : art_house('hh') ?></div>
      <div class="hv-second parallax-slow<?= $heroRoom ? ' has-photo' : '' ?>"><?= $heroRoom ? zoom_photo(room_photo_url($heroRoom), 'Photo of ' . room_title($heroRoom), 800, 600) : art_room('hr') ?></div>
      <div class="float-card fc-open" role="status">
        <span class="fc-ic"><?= icon('bed') ?></span>
        <span class="fc-text">
          <?php if ($openBeds > 0): ?>
            <strong><span class="live-dot" aria-hidden="true"></span><?= (int)$openBeds ?> open <?= $openBeds === 1 ? 'bed' : 'beds' ?></strong>
            <small>in <?= plural($roomsOpen, 'room') ?> right now</small>
          <?php else: ?>
            <strong>No open beds today</strong><small>Send an inquiry to ask</small>
          <?php endif; ?>
        </span>
      </div>
      <?php if ($minRate > 0): ?>
      <div class="float-card fc-rate">
        <span class="fc-ic fc-ic-warm"><?= icon('tag') ?></span>
        <span class="fc-text"><strong>From <?= e(money_short($minRate)) ?></strong><small>per month</small></span>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <a class="scroll-cue tip" data-tip="Scroll to features" href="#features" aria-label="Scroll to features"><?= icon('chevron') ?></a>
</section>

<!-- ===================== FEATURES ===================== -->
<section id="features" class="section" aria-labelledby="features-title">
  <div class="wrap">
    <header class="section-head center" data-reveal>
      <p class="kicker">Why stay with us</p>
      <h2 id="features-title">Everything you need to find a room and keep it hassle-free</h2>
      <p>From the first question to your move-in day, each step is simple, visible, and confirmed with you.</p>
    </header>
    <div class="feature-grid">
      <?php
      feature_card('bed', 'Live room availability', 'Open, reserved, and occupied beds update the moment the landlord records a change.', 0);
      feature_card('cash', 'Clear monthly rates', 'Rent, security deposit, and advance payment are shown up front, so there are no surprises.', 70);
      feature_card('message', 'Talk to the landlord', 'Send an inquiry without creating an account, then read the reply with your inquiry code.', 140);
      feature_card('calendar', 'Simple reservation steps', 'From inquiry to move-in, the landlord confirms each step with you.', 210);
      feature_card('receipt', 'Official receipts', 'Every payment is recorded and receipted, so you always have proof of what you paid.', 280);
      feature_card('shield', 'Private by design', 'Only the landlord can see your details. There is no public list of tenants.', 350);
      ?>
    </div>
  </div>
</section>

<!-- ===================== SERVICES ===================== -->
<section id="services" class="section section-soft" aria-labelledby="services-title">
  <div class="wrap">
    <header class="section-head" data-reveal>
      <p class="kicker">Services</p>
      <h2 id="services-title">Rooms and stays that fit how you live</h2>
      <p>Choose a shared room for the best value, or a private room for more quiet. Stay for a few months or for the long term.</p>
    </header>

    <div class="service-grid">
      <article class="service" data-reveal>
        <span class="icon-badge icon-badge-lg"><?= icon('users') ?></span>
        <h3>Shared rooms</h3>
        <p>Bunk-style beds, priced per bed. A budget-friendly choice for students and workers.</p>
        <?php if ($minBy['shared'] !== null): ?><p class="service-price">From <strong><?= e(money_short($minBy['shared'])) ?></strong> per bed / month</p><?php endif; ?>
      </article>
      <article class="service" data-reveal style="--d:80ms">
        <span class="icon-badge icon-badge-lg"><?= icon('door') ?></span>
        <h3>Private room</h3>
        <p>Your own room and door, for more quiet and privacy while you study or work.</p>
        <?php if ($minBy['private'] !== null): ?><p class="service-price">From <strong><?= e(money_short($minBy['private'])) ?></strong> per room / month</p><?php endif; ?>
      </article>
      <article class="service" data-reveal style="--d:160ms">
        <span class="icon-badge icon-badge-lg"><?= icon('calendar') ?></span>
        <h3>Flexible stays</h3>
        <p>Short-term or long-term. Rent is paid monthly in advance, with an official receipt each time.</p>
        <p class="service-price"><a class="link-arrow" href="<?= e(url('policies.php')) ?>">Rates &amp; house rules <?= icon('arrow') ?></a></p>
      </article>
    </div>

    <div class="board" id="rooms">
      <div class="board-head" data-reveal>
        <div>
          <h3>Room board</h3>
          <p class="muted">Updated from the landlord's records, so you see what is free today.</p>
        </div>
        <div class="board-tools">
          <div class="segmented" role="group" aria-label="Filter rooms by type" data-filter-group>
            <button type="button" class="seg is-on" data-filter="all" aria-pressed="true">All</button>
            <button type="button" class="seg" data-filter="shared" aria-pressed="false">Shared</button>
            <button type="button" class="seg" data-filter="private" aria-pressed="false">Private</button>
          </div>
          <?= pips_legend() ?>
        </div>
      </div>
      <?php if ($shown): ?>
        <div class="room-grid" data-room-grid>
          <?php foreach ($shown as $i => $r): ?>
            <div class="room-slot" data-reveal style="--d:<?= $i * 60 ?>ms" data-room-type="<?= e($r['room_type']) ?>"><?= room_card($r) ?></div>
          <?php endforeach; ?>
        </div>
        <p class="no-match" data-no-match hidden>No rooms of this type are listed right now.</p>
        <p class="board-more" data-reveal><a class="btn btn-outline" href="<?= e(url('rooms.php')) ?>"><span>See all <?= count($rooms) ?> rooms and check a move-in date</span><?= icon('arrow') ?></a></p>
      <?php else: ?>
        <p class="empty-box">Rooms will be listed here soon. In the meantime, <a href="<?= e(url('inquire.php')) ?>">send us an inquiry</a>.</p>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- ===================== HOW IT WORKS ===================== -->
<section id="how" class="section" aria-labelledby="how-title">
  <div class="wrap">
    <header class="section-head center" data-reveal>
      <p class="kicker">How it works</p>
      <h2 id="how-title">Reserve a room in six clear steps</h2>
    </header>
    <ol class="steps-flow">
      <?php
      $stepIcons = ['message', 'search', 'users', 'shield', 'cash', 'key'];
      foreach (reservation_steps() as $i => [$t, $d]): ?>
        <li class="step" data-reveal style="--d:<?= $i * 70 ?>ms">
          <span class="step-ic"><?= icon($stepIcons[$i]) ?><b><?= $i + 1 ?></b></span>
          <h3><?= e($t) ?></h3>
          <p><?= e($d) ?></p>
        </li>
      <?php endforeach; ?>
    </ol>
  </div>
</section>

<!-- ===================== PAY BY GCASH ===================== -->
<?php $gcashNo = setting('gcash_no'); ?>
<section id="pay" class="section section-pay" aria-labelledby="pay-title">
  <div class="wrap pay-grid">
    <div class="pay-copy" data-reveal="left">
      <p class="kicker">Pay by GCash</p>
      <h2 id="pay-title">Pay your rent from anywhere, in three steps</h2>
      <p class="lead">No need to come in. Send your payment by GCash, report it here, and get an official receipt once the landlord confirms it.</p>
      <ol class="pay-steps">
        <li><b>1</b><div><strong>Send it by GCash</strong><span>Send the amount to the number shown here.</span></div></li>
        <li><b>2</b><div><strong>Report your payment</strong><span>Type your reservation number and the GCash reference number.</span></div></li>
        <li><b>3</b><div><strong>Get confirmed</strong><span>The landlord checks it, then you receive a message and an official receipt.</span></div></li>
      </ol>
    </div>
    <div class="pay-card" data-reveal="right" style="--d:120ms">
      <span class="pay-badge"><?= icon('wallet') ?> GCash</span>
      <?php if ($gcashNo !== ''): ?>
        <p class="pay-label">Send your payment to</p>
        <p class="pay-number"><?= e($gcashNo) ?></p>
        <?php if (setting('landlord_name') !== ''): ?><p class="pay-name"><?= e(setting('landlord_name')) ?></p><?php endif; ?>
        <div class="pay-actions">
          <button type="button" class="btn btn-sm gc-copy" data-copy="<?= e($gcashNo) ?>"><?= icon('copy') ?><span>Copy number</span></button>
          <a class="btn btn-gcash btn-lg" href="<?= e(url('gcash_pay.php')) ?>" data-open-gcash><?= icon('wallet') ?><span>I paid. Report my payment</span></a>
        </div>
      <?php else: ?>
        <p class="pay-label">The landlord will share the GCash number soon.</p>
        <p class="pay-name">Please ask the landlord for it.</p>
      <?php endif; ?>
      <ul class="pay-trust">
        <li><?= icon('shield') ?><span>Every report gets a code you can check any time</span></li>
        <li><?= icon('check') ?><span>Nothing is recorded until the landlord confirms it</span></li>
        <li><?= icon('bell') ?><span>You get an e-mail reminder before rent is due, or a Messenger message from the landlord</span></li>
      </ul>
    </div>
  </div>
</section>

<!-- ===================== ABOUT ===================== -->
<section id="about" class="section section-soft" aria-labelledby="about-title">
  <div class="wrap about-grid">
    <div class="about-visual" data-reveal="left">
      <div class="about-art" data-welcome role="button" tabindex="0" aria-label="Your host says hello. Press to hear another greeting."><?= art_welcome('aw') ?></div>
      <p class="about-art-hint" aria-hidden="true">Tap me to say hi</p>
      <div class="av-badge"><span class="icon-badge"><?= icon('home') ?></span><span><strong>Your landlord</strong><small><?= e(setting('landlord_name')) ?></small></span></div>
    </div>
    <div class="about-copy">
      <div data-reveal>
        <p class="kicker">About us</p>
        <h2 id="about-title">A small boarding house with a landlord you can talk to</h2>
        <p class="lead-sm"><?= e(setting('about_text')) ?></p>
      </div>
      <?php if ($active): ?>
      <dl class="counters" data-reveal style="--d:100ms">
        <div><dt>Rooms</dt><dd><span data-count="<?= count($active) ?>"><?= count($active) ?></span></dd></div>
        <div><dt>Beds in total</dt><dd><span data-count="<?= (int)$totalBeds ?>"><?= (int)$totalBeds ?></span></dd></div>
        <div><dt>Open right now</dt><dd><span data-count="<?= (int)$openBeds ?>"><?= (int)$openBeds ?></span></dd></div>
        <div><dt>Rent from</dt><dd><span data-count="<?= (int)$minRate ?>" data-prefix="₱"><?= e(money_short($minRate)) ?></span></dd></div>
      </dl>
      <?php endif; ?>
      <div class="checklist" data-reveal style="--d:160ms">
        <h3><?= icon('clipboard') ?>What to prepare before you move in</h3>
        <ul class="tick">
          <?php foreach (array_slice(lines(setting('requirements')), 0, 5) as $l): ?><li><?= e($l) ?></li><?php endforeach; ?>
        </ul>
      </div>
    </div>
  </div>
</section>

<!-- ===================== FAQ ===================== -->
<section id="faq" class="section" aria-labelledby="faq-title">
  <div class="wrap faq-grid">
    <header class="section-head" data-reveal>
      <p class="kicker">Questions</p>
      <h2 id="faq-title">Good to know before you ask</h2>
      <p>Cannot find your answer? <a href="<?= e(url('inquire.php')) ?>">Send the landlord a message</a>.</p>
    </header>
    <div class="faq" data-reveal style="--d:80ms">
      <details name="faq"><summary><span>Do I need an account to inquire or reserve?</span><?= icon('chevron') ?></summary><div class="faq-body"><p>No. Send an inquiry with your name and contact number. You get an inquiry code, and you can use it later to read the landlord's reply and send follow-up messages.</p></div></details>
      <details name="faq"><summary><span>How do I reserve a room?</span><?= icon('chevron') ?></summary><div class="faq-body"><p>Send an inquiry, the landlord checks availability and replies, you share your details, and the landlord confirms your room. You then pay the advance to secure it and receive an official receipt.</p></div></details>
      <details name="faq"><summary><span>How much is the advance payment?</span><?= icon('chevron') ?></summary><div class="faq-body"><p>The advance is <?= e($advText) ?>, collected once your reservation is confirmed. Deposits and monthly rates for each room are listed on the <a href="<?= e(url('policies.php')) ?>">rates page</a>.</p></div></details>
      <details name="faq"><summary><span>Can I stay for just a few months?</span><?= icon('chevron') ?></summary><div class="faq-body"><p>Yes. We accept both short-term and long-term stays. Rent is paid monthly in advance.</p></div></details>
      <details name="faq"><summary><span>Are some rooms only for men or women?</span><?= icon('chevron') ?></summary><div class="faq-body"><p>Some rooms are male-only or female-only. This is shown on every room card, and you can filter rooms by gender on the <a href="<?= e(url('rooms.php')) ?>">rooms page</a>.</p></div></details>
      <details name="faq"><summary><span>Where can I read the house rules?</span><?= icon('chevron') ?></summary><div class="faq-body"><p>All house rules, requirements, and the payment schedule are on the <a href="<?= e(url('policies.php')) ?>">rates and rules page</a>.</p></div></details>
    </div>
  </div>
</section>

<!-- ===================== CONTACT ===================== -->
<section id="contact" class="section section-soft" aria-labelledby="contact-title">
  <div class="wrap contact-grid">
    <div class="contact-cta" data-reveal="zoom">
      <span class="cta-glow" aria-hidden="true"></span>
      <p class="kicker kicker-light">Get in touch</p>
      <h2 id="contact-title">Ready to find your room?</h2>
      <p>Ask about rates, availability, or requirements. There is no obligation, and the landlord replies personally.</p>
      <div class="hero-actions">
        <a class="btn btn-accent btn-lg" href="<?= e(url('inquire.php')) ?>"><?= icon('message') ?><span>Send an inquiry</span></a>
        <a class="btn btn-ghost-light btn-lg" href="<?= e(url('track.php')) ?>"><?= icon('search') ?><span>Check my inquiry</span></a>
      </div>
    </div>
    <ul class="contact-list" data-reveal style="--d:100ms">
      <?php if ($phone !== ''): ?>
      <li><span class="icon-badge"><?= icon('phone') ?></span><span class="cl-text"><small>Call or text</small><strong><?= e($phone) ?></strong></span>
        <a class="icon-btn icon-btn-soft tip" data-tip="Call the landlord" href="<?= e($phoneHref) ?>"><?= icon('phone') ?><span class="sr-only">Call the landlord</span></a></li>
      <?php endif; ?>
      <?php if (setting('email') !== ''): ?>
      <li><span class="icon-badge"><?= icon('mail') ?></span><span class="cl-text"><small>Email</small><strong><?= e(setting('email')) ?></strong></span>
        <a class="icon-btn icon-btn-soft tip" data-tip="Write an email" href="mailto:<?= e(setting('email')) ?>"><?= icon('mail') ?><span class="sr-only">Write an email</span></a></li>
      <?php endif; ?>
      <li><span class="icon-badge"><?= icon('pin') ?></span><span class="cl-text"><small>Find us</small><strong><?= e(setting('address')) ?></strong></span></li>
      <?php $fb = house_facebook(); if ($fb['page'] !== ''): ?>
      <li><span class="icon-badge"><?= icon('message') ?></span><span class="cl-text"><small>Facebook</small><strong><?= $fb['messenger'] ? 'Message us on Messenger' : 'Visit our Facebook page' ?></strong></span>
        <a class="icon-btn icon-btn-soft tip" data-tip="<?= $fb['messenger'] ? 'Open Messenger' : 'Open our Facebook page' ?>" href="<?= e($fb['messenger'] ?? $fb['page']) ?>" target="_blank" rel="noopener"><?= icon('message') ?><span class="sr-only"><?= $fb['messenger'] ? 'Message us on Messenger' : 'Open our Facebook page' ?></span></a></li>
      <?php endif; ?>
      <?php if (setting('contact_hours') !== ''): ?>
      <li><span class="icon-badge"><?= icon('clock') ?></span><span class="cl-text"><small>Messages answered</small><strong><?= e(setting('contact_hours')) ?></strong></span></li>
      <?php endif; ?>
    </ul>
  </div>
</section>

<dialog id="login-dialog" class="login-dialog" aria-labelledby="login-title"<?= $openLogin ? ' data-autoopen' : '' ?>>
  <div class="login-shell">
    <aside class="login-art">
      <div class="login-art-inner">
        <span class="login-badge"><?= icon('key') ?></span>
        <p class="login-art-title">Welcome back</p>
        <p class="login-art-sub">Everything for running the boarding house, in one place.</p>
        <ul class="login-points">
          <li><?= icon('bed') ?><span>Room and bed availability</span></li>
          <li><?= icon('calendar') ?><span>Reservations, check-in and check-out</span></li>
          <li><?= icon('receipt') ?><span>Payments and official receipts</span></li>
          <li><?= icon('inbox') ?><span>Replies to customer inquiries</span></li>
        </ul>
      </div>
    </aside>
    <div class="login-panel">
      <button type="button" class="login-close" data-close-login aria-label="Close sign in"><?= icon('x') ?></button>
      <p class="login-eyebrow"><?= icon('shield') ?> Landlord only</p>
      <h2 id="login-title">Sign in</h2>
      <p class="muted login-lead">This area is private. Customers do not need an account to browse or send an inquiry.</p>

      <?php if ($loginNotice !== ''): ?><div class="flash flash-info" role="status"><span><?= e($loginNotice) ?></span></div><?php endif; ?>
      <?php if ($loginError !== ''): ?><div class="flash flash-error" role="alert"><span><?= e($loginError) ?></span></div><?php endif; ?>

      <form method="post" action="<?= e(url('admin/login.php')) ?>" class="login-form" autocomplete="off">
        <?= csrf_field() ?>
        <div class="fld">
          <label for="login-user">Username</label>
          <span class="field-icon"><?= icon('user') ?><input id="login-user" type="text" name="username" value="<?= e($loginUser) ?>" required maxlength="50" autocomplete="username"></span>
        </div>
        <div class="fld">
          <label for="login-pass">Password</label>
          <span class="field-icon"><?= icon('lock') ?><input id="login-pass" type="password" name="password" required autocomplete="current-password">
            <button type="button" class="pass-toggle" data-toggle-pass aria-label="Show password" aria-pressed="false"><?= icon('eye', 'ic-show') ?><?= icon('eye-off', 'ic-hide') ?></button></span>
        </div>
        <button class="btn btn-primary btn-lg block" type="submit"><span>Sign in</span><?= icon('arrow') ?></button>
      </form>
      <p class="login-foot">Looking for a room? <a href="<?= e(url('inquire.php')) ?>">Send an inquiry</a></p>
    </div>
  </div>
</dialog>
<?php public_footer();
