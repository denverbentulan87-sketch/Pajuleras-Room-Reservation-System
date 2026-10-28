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

<?php $ls = lifecycle_settings(); $advMonths = (int)setting('advance_months', '1'); ?>
<section class="section tight how-reserve">
  <div class="wrap">
    <h2>How reserving works</h2>
    <p class="lead">Every step is clear, and you always get a receipt.</p>
    <ol class="reserve-steps">
      <li><b>1</b><div><strong>Send an inquiry</strong><span>You get an instant answer: confirmed if the room has an open bed, or not confirmed with other rooms that do.</span></div></li>
      <li><b>2</b><div><strong>The landlord holds your bed</strong><span>Once confirmed, the bed is held for you for <?= (int)$ls['hold_days'] ?> <?= $ls['hold_days'] === 1 ? 'day' : 'days' ?> while you pay the reservation <?= $ls['fee'] > 0 ? 'fee of ' . e(money($ls['fee'])) : 'amount' ?>.</span></div></li>
      <li><b>3</b><div><strong>Pay to secure it</strong><span>Pay by cash or GCash (from anywhere). Paying it confirms your reservation. If it is not paid in time, the bed is released so it can go to someone else.</span></div></li>
      <li><b>4</b><div><strong>Move in</strong><span>Pay the security deposit and the rest of the first month. <?= $ls['fee'] > 0 ? 'Your reservation fee counts toward the first month.' : ($advMonths > 0 ? 'Your advance of ' . $advMonths . ' ' . ($advMonths === 1 ? 'month' : 'months') . ' counts toward your rent.' : '') ?> We check the room together first.</span></div></li>
      <li><b>5</b><div><strong>Pay every month</strong><span>Rent is due on your move-in date each month. We remind you by e-mail or Messenger before it is due and if it is late.</span></div></li>
      <li><b>6</b><div><strong>Move out</strong><span>Give <?= (int)$ls['notice_days'] ?> days' notice. On the last day we check the room together, and your deposit is returned minus any unpaid rent or damages, with a written summary.</span></div></li>
    </ol>
    <?php if ($ls['fee'] > 0): ?><p class="hint">The reservation fee of <?= e(money($ls['fee'])) ?> is <?= $ls['fee_refundable'] ? 'refundable if you cancel' : 'not refundable if you cancel, but it counts toward your first month if you move in' ?>.</p><?php endif; ?>
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
