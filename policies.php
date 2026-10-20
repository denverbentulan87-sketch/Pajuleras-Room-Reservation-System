<?php
require __DIR__ . '/includes/bootstrap.php';

$rooms = rooms_with_status(true);
$advMonths = max(0, (int)setting('advance_months', '1'));

public_header('Rates & rules', 'policies', 'Rental rates, requirements, payment schedule, and house rules at Pajuleras Boarding House.');
?>
<section class="page-title">
  <div class="wrap">
    <h1>Rates and house rules</h1>
    <p>Everything you should know before you reserve, so there are no surprises.</p>
  </div>
</section>

<section class="section tight">
  <div class="wrap">
    <h2>Rental rates</h2>
    <?php if ($rooms): ?>
    <div class="table-scroll">
    <table class="plain">
      <thead><tr><th scope="col">Room</th><th scope="col">Type</th><th scope="col">Who can stay</th><th scope="col" class="num">Monthly rent</th><th scope="col" class="num">Security deposit</th></tr></thead>
      <tbody>
      <?php foreach ($rooms as $r): ?>
        <tr>
          <th scope="row"><a href="<?= e(url('room.php?id=' . (int)$r['id'])) ?>"><?= e(room_title($r)) ?></a></th>
          <td><?= $r['room_type'] === 'private' ? 'Private' : 'Shared, ' . e(plural((int)$r['capacity'], 'bed')) ?></td>
          <td><?= e(gender_label($r['gender_policy'])) ?></td>
          <td class="num"><?= e(rate_label($r)) ?></td>
          <td class="num"><?= $r['deposit_amount'] > 0 ? e(money_short($r['deposit_amount'])) . ((int)$r['capacity'] > 1 ? ' per bed' : '') : 'None' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php else: ?>
      <p class="muted">Rates will be posted here soon. Please send an inquiry to ask.</p>
    <?php endif; ?>
    <?php if ($advMonths > 0): ?>
      <p class="note">To secure a room you pay an advance of <strong><?= e($advMonths === 1 ? 'one month' : $advMonths . ' months') ?></strong> of rent once your reservation is confirmed.</p>
    <?php endif; ?>
  </div>
</section>

<section class="section section-tint">
  <div class="wrap three-col">
    <div>
      <h2>What to prepare</h2>
      <ul class="tick"><?php foreach (lines(setting('requirements')) as $l): ?><li><?= e($l) ?></li><?php endforeach; ?></ul>
    </div>
    <div>
      <h2>How payment works</h2>
      <ul class="tick"><?php foreach (lines(setting('payment_schedule')) as $l): ?><li><?= e($l) ?></li><?php endforeach; ?></ul>
      <?php if (setting('gcash_no') !== ''): ?><p class="note">GCash: <strong><?= e(setting('gcash_no')) ?></strong> &middot; <a href="<?= e(url('gcash_pay.php')) ?>" data-open-gcash>Pay online and report it</a></p><?php endif; ?>
    </div>
    <div>
      <h2>House rules</h2>
      <ul class="tick"><?php foreach (lines(setting('house_rules')) as $l): ?><li><?= e($l) ?></li><?php endforeach; ?></ul>
    </div>
  </div>
</section>

<section class="section cta-band">
  <div class="wrap cta-row">
    <div><h2>Ready to reserve?</h2><p>Send an inquiry and the landlord will check availability for you.</p></div>
    <div class="cta-actions"><a class="btn btn-primary btn-lg" href="<?= e(url('inquire.php')) ?>">Send an inquiry</a></div>
  </div>
</section>
<?php public_footer();
