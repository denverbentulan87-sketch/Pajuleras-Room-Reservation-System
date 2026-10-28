<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();

$id = get_int('id');
$v = $id ? fetch_one("SELECT v.*, t.full_name, t.tenant_code, t.contact_no, t.email, t.messenger, t.gender, r.room_no, r.room_name, r.capacity, r.monthly_rate AS room_rate
                        FROM reservations v JOIN tenants t ON t.id = v.tenant_id JOIN rooms r ON r.id = v.room_id WHERE v.id = ?", [$id]) : null;
if (!$v) {
    abort(404, 'That reservation does not exist.');
}
$v['reservation_no'] = $v['reservation_no'] ?: 'R-' . $id;
$f = reservation_financials($v);
$payments = fetch_all("SELECT * FROM payments WHERE reservation_id = ? ORDER BY payment_date DESC, id DESC", [$id]);
$history = fetch_all("SELECT action, details, created_at FROM activity_log WHERE entity = 'reservation' AND entity_id = ? ORDER BY id DESC LIMIT 15", [$id]);
$inq = $v['inquiry_id'] ? fetch_one("SELECT id, ref_code FROM inquiries WHERE id = ?", [$v['inquiry_id']]) : null;
$st = $v['status'];
$moveRooms = in_array($st, ['confirmed', 'checked_in'], true)
    ? array_filter(rooms_with_status(false), fn($r) => $r['status'] === 'active' && (int)$r['id'] !== (int)$v['room_id']) : [];

admin_header($v['reservation_no'], 'reservations');
page_head($v['reservation_no'], $v['full_name'] . ', Room ' . $v['room_no'],
    reservation_badge($st)
    . (in_array($st, ['pending', 'confirmed', 'checked_in'], true) ? '<a class="btn" href="' . e(url('admin/reservation_form.php?id=' . $id)) . '">Edit</a>' : '')
    . ($st !== 'cancelled' ? '<a class="btn btn-primary" href="' . e(url('admin/payment_form.php?reservation_id=' . $id)) . '">Record payment</a>' : ''));
?>

<?php
$stage = reservation_stage($v, $f);
$ls = lifecycle_settings();
if ($st === 'checked_in') {
    $sim = checkout_simulation($v, today());
}
if ($st === 'confirmed') {
    $owedMoveIn = compute_financials(array_merge($v, ['status' => 'checked_in', 'checked_in_at' => date('Y-m-d H:i:s')]), payment_totals([$id])[$id] ?? [])['balance'];
}
$held = $f['held'];
$cancelDefault = (!$ls['fee_refundable'] && $ls['fee'] > 0) ? max(0.0, round($held - min($held, $ls['fee']), 2)) : $held;
?>
<section class="panel stage-panel" aria-label="Where this reservation is in the process">
  <ol class="stage">
    <?php foreach ($stage['steps'] as $i => $step): ?>
      <li class="st-<?= e($step['state']) ?>"<?= $step['state'] === 'current' ? ' aria-current="step"' : '' ?>>
        <span class="st-dot"><?= $step['state'] === 'done' ? icon('check') : ($i + 1) ?></span>
        <span class="st-text"><strong><?= e($step['label']) ?></strong><?php if ($step['note'] !== ''): ?><small><?= e($step['note']) ?></small><?php endif; ?></span>
      </li>
    <?php endforeach; ?>
  </ol>
  <p class="stage-next"><?= icon('arrow') ?><span><strong>Next:</strong> <?= e($stage['next']) ?></span></p>
</section>

<?php if (in_array($st, ['pending', 'confirmed', 'checked_in'], true)): ?>
<?php
$msgState = reminder_state($v, $f, reminder_settings());
$msgBody = ($msgState && $msgState['state'] !== 'ok')
    ? reminder_message(['res' => $v, 'st' => $msgState, 'fin' => $f, 'tenant' => ['full_name' => $v['full_name']]])['body']
    : 'Hi ' . first_name((string)$v['full_name']) . ",\n\nThis is " . setting('site_name', 'the boarding house') . ' about your reservation ' . $v['reservation_no'] . ' for Room ' . $v['room_no'] . '.';
?>
<section class="panel actions-panel">
  <h2>What would you like to do?</h2>
  <div class="action-row">
    <?= messenger_button($v, $msgBody, 'Message on Messenger') ?>
    <?php if ($st === 'pending'): ?>
      <form method="post" action="<?= e(url('admin/reservation_action.php')) ?>">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="confirm">
        <button class="btn btn-primary" type="submit">Confirm and hold the bed</button>
      </form>
    <?php elseif ($st === 'confirmed'): ?>
    <details class="action-box">
      <summary class="btn btn-primary">Check in tenant</summary>
      <form method="post" action="<?= e(url('admin/reservation_action.php')) ?>" class="form mini">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="checkin">
        <div class="settle">
          <dl class="settle-lines">
            <div><dt>To collect at move-in</dt><dd><strong><?= e(money(max(0.0, $owedMoveIn))) ?></strong></dd></div>
            <div><dt>Includes</dt><dd>security deposit <?= e(money((float)$v['deposit_amount'])) ?> and the first month, less what is already paid</dd></div>
          </dl>
        </div>
        <label>Room condition at move-in <span class="opt">(optional, used at move-out)</span>
          <textarea name="checkin_notes" rows="3" maxlength="1500" placeholder="e.g. Clean room, 1 bed with mattress, 1 cabinet, fan works, no damage."></textarea>
        </label>
        <?php if ($owedMoveIn > 0): ?>
          <label class="check"><input type="checkbox" name="ack_balance" value="1"> <span>Check in anyway. The tenant still owes <?= e(money($owedMoveIn)) ?>. (Better: <a href="<?= e(url('admin/payment_form.php?reservation_id=' . $id)) ?>">record the payment</a> first.)</span></label>
        <?php endif; ?>
        <button class="btn btn-primary" type="submit">Check in</button>
      </form>
    </details>
    <?php endif; ?>

    <?php if ($st === 'checked_in'): ?>
    <details class="action-box">
      <summary class="btn btn-primary">Check out</summary>
      <form method="post" action="<?= e(url('admin/reservation_action.php')) ?>" class="form mini">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="checkout">
        <label>Check-out date<input type="date" name="checkout_date" value="<?= e(today()) ?>" min="<?= e(substr($v['checked_in_at'], 0, 10)) ?>" max="<?= e(today()) ?>" required></label>
        <label>Room condition at move-out <span class="opt">(optional)</span>
          <textarea name="checkout_notes" rows="2" maxlength="1500" placeholder="e.g. Room clean, keys returned, one broken curtain rod."></textarea>
        </label>
        <?php if ($v['checkin_notes']): ?><p class="hint">At move-in: <?= e($v['checkin_notes']) ?></p><?php endif; ?>
        <div class="settle" data-refund-calc data-held="<?= e(number_format($sim['refundable'], 2, '.', '')) ?>" data-balance="<?= e(number_format($sim['balance'], 2, '.', '')) ?>">
          <h3>Deposit settlement</h3>
          <dl class="settle-lines">
            <div><dt>Deposit held</dt><dd><?= e(money($sim['refundable'])) ?></dd></div>
            <div><dt>Unpaid rent</dt><dd><?= e(money(max(0.0, $sim['balance']))) ?></dd></div>
          </dl>
          <?php if ($sim['balance'] > 0 && $sim['refundable'] > 0): ?>
            <label class="check"><input type="checkbox" name="apply_balance" value="1" data-apply checked> <span>Take the unpaid rent (<?= e(money(min($sim['balance'], $sim['refundable']))) ?>) from the deposit</span></label>
          <?php endif; ?>
          <?php if ($sim['refundable'] > 0): ?>
            <label>Damages or other deductions (&#8369;) <span class="opt">(optional)</span><input type="text" name="damages" data-ded inputmode="decimal" placeholder="0.00" autocomplete="off"></label>
            <label>Reason for the deduction <span class="reason-note"></span><input type="text" name="deduct_reason" data-reason maxlength="255" placeholder="e.g. broken window"></label>
            <p class="refund-line">Refund to the tenant: <strong data-out><?= e(money($sim['refundable'])) ?></strong></p>
            <label class="check"><input type="checkbox" name="refund_now" value="1" checked> <span>Hand back the refund now and record it with a receipt</span></label>
            <div class="row-2">
              <label>Refund by<select name="refund_method"><option value="cash">Cash</option><option value="gcash">GCash</option><option value="bank_transfer">Bank transfer</option><option value="other">Other</option></select></label>
              <label>Reference number <span class="opt">(GCash or bank)</span><input type="text" name="refund_ref" maxlength="80" autocomplete="off"></label>
            </div>
          <?php else: ?>
            <p class="hint">No deposit is held, so there is nothing to return.</p>
          <?php endif; ?>
        </div>
        <button class="btn btn-primary" type="submit">Confirm check-out</button>
      </form>
    </details>

    <details class="action-box">
      <summary class="btn"><?= $v['notice_date'] ? 'Change move-out date' : 'Move-out notice' ?></summary>
      <form method="post" action="<?= e(url('admin/reservation_action.php')) ?>" class="form mini">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="notice">
        <label>The tenant will move out on<input type="date" name="notice_move_out" value="<?= e((string)$v['move_out_date']) ?>" min="<?= e(date('Y-m-d', strtotime('+1 day'))) ?>" required></label>
        <p class="hint">House policy: <?= (int)$ls['notice_days'] ?> days' notice. Rent is billed until the move-out date. The tenant is told the date and how the deposit is handled.</p>
        <label class="check"><input type="checkbox" name="short_ok" value="1"> <span>Accept a shorter notice than <?= (int)$ls['notice_days'] ?> days</span></label>
        <button class="btn btn-primary" type="submit">Save move-out date</button>
      </form>
    </details>
    <?php endif; ?>

    <?php if ($moveRooms): ?>
    <details class="action-box">
      <summary class="btn">Transfer room</summary>
      <form method="post" action="<?= e(url('admin/reservation_action.php')) ?>" class="form mini">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="transfer">
        <label>Move to
          <select name="new_room_id" required>
            <option value="">Choose a room</option>
            <?php foreach ($moveRooms as $r): ?>
              <option value="<?= (int)$r['id'] ?>">Room <?= e($r['room_no']) ?> (<?= e($r['open_beds']) ?> open)<?= $r['gender_policy'] !== 'any' ? ' - ' . e(gender_label($r['gender_policy'])) : '' ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <p class="hint">The tenant keeps the same rent and deposit. The system checks that the new room has enough free beds.</p>
        <button class="btn btn-primary" type="submit">Transfer</button>
      </form>
    </details>
    <?php endif; ?>

    <?php if ($st !== 'checked_in'): ?>
    <details class="action-box">
      <summary class="btn btn-danger-outline">Cancel reservation</summary>
      <form method="post" action="<?= e(url('admin/reservation_action.php')) ?>" class="form mini">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="cancel">
        <label>Reason for cancelling<input type="text" name="reason" maxlength="255" required placeholder="e.g. Tenant changed plans"></label>
        <?php if ($held > 0): ?>
        <div class="settle">
          <dl class="settle-lines"><div><dt>Payments received</dt><dd><?= e(money($held)) ?></dd></div></dl>
          <p class="hint"><?= ($ls['fee'] > 0 && !$ls['fee_refundable']) ? 'House policy: the reservation fee of ' . e(money($ls['fee'])) . ' is not refundable, so it is kept by default.' : 'House policy: payments are refundable by default.' ?> You can change the amount.</p>
          <label>Refund to the tenant (&#8369;)<input type="text" name="refund_amount" value="<?= e(number_format($cancelDefault, 2, '.', '')) ?>" inputmode="decimal" autocomplete="off"></label>
          <div class="row-2">
            <label>Refund by<select name="refund_method"><option value="cash">Cash</option><option value="gcash">GCash</option><option value="bank_transfer">Bank transfer</option><option value="other">Other</option></select></label>
            <label>Reference number <span class="opt">(GCash or bank)</span><input type="text" name="refund_ref" maxlength="80" autocomplete="off"></label>
          </div>
        </div>
        <?php endif; ?>
        <button class="btn btn-danger" type="submit">Cancel reservation</button>
      </form>
    </details>
    <?php endif; ?>
  </div>
  <?php if ($st === 'pending'): ?><p class="hint">A pending reservation does not hold a bed yet. Confirming it holds the bed for <?= (int)$ls['hold_days'] ?> days while the reservation amount is paid; paying it confirms the reservation by itself.</p><?php endif; ?>
</section>
<?php endif; ?>

<div class="grid-2 top">
  <section class="panel">
    <div class="panel-head"><h2>Reservation details</h2></div>
    <dl class="details">
      <div><dt>Tenant</dt><dd><a href="<?= e(url('admin/tenant_view.php?id=' . (int)$v['tenant_id'])) ?>"><?= e($v['full_name']) ?></a> <span class="muted small"><?= e($v['tenant_code']) ?></span></dd></div>
      <div><dt>Contact</dt><dd><?= e($v['contact_no']) ?><?= $v['email'] ? '<br>' . e($v['email']) : '' ?></dd></div>
      <div><dt>Room</dt><dd>Room <?= e($v['room_no']) ?><?= $v['room_name'] ? ' (' . e($v['room_name']) . ')' : '' ?>, <?= e(plural((int)$v['beds'], 'bed')) ?> of <?= (int)$v['capacity'] ?></dd></div>
      <div><dt>Move-in date</dt><dd><?= e(fmt_date($v['move_in_date'])) ?></dd></div>
      <div><dt>Move-out date</dt><dd><?= e(fmt_date($v['move_out_date'], 'Open (long-term)')) ?></dd></div>
      <div><dt>Recorded</dt><dd><?= e(fmt_datetime($v['created_at'])) ?></dd></div>
      <?php if ($v['confirmed_at']): ?><div><dt>Confirmed</dt><dd><?= e(fmt_datetime($v['confirmed_at'])) ?></dd></div><?php endif; ?>
      <?php if ($v['hold_until'] && $st === 'confirmed'): ?><div><dt>Bed held until</dt><dd><strong><?= e(fmt_datetime($v['hold_until'])) ?></strong> <span class="muted small">then released if unpaid</span></dd></div><?php endif; ?>
      <?php if ($v['notice_date']): ?><div><dt>Move-out notice</dt><dd>given <?= e(fmt_date($v['notice_date'])) ?></dd></div><?php endif; ?>
      <?php if ($v['checkin_notes']): ?><div><dt>Room at move-in</dt><dd><?= e($v['checkin_notes']) ?></dd></div><?php endif; ?>
      <?php if ($v['checkout_notes']): ?><div><dt>Room at move-out</dt><dd><?= e($v['checkout_notes']) ?></dd></div><?php endif; ?>
      <?php if ((float)$v['deposit_kept_rent'] + (float)$v['deposit_kept_other'] > 0): ?><div><dt>Deposit kept</dt><dd><?= e(money((float)$v['deposit_kept_rent'] + (float)$v['deposit_kept_other'])) ?> <span class="muted small"><?= e((string)$v['deposit_kept_reason']) ?></span></dd></div><?php endif; ?>
      <?php if ($v['checked_in_at']): ?><div><dt>Checked in</dt><dd><?= e(fmt_datetime($v['checked_in_at'])) ?></dd></div><?php endif; ?>
      <?php if ($v['checked_out_at']): ?><div><dt>Checked out</dt><dd><?= e(fmt_datetime($v['checked_out_at'])) ?></dd></div><?php endif; ?>
      <?php if ($v['cancelled_at']): ?><div><dt>Cancelled</dt><dd><?= e(fmt_datetime($v['cancelled_at'])) ?><?= $v['cancel_reason'] ? '<br>' . e($v['cancel_reason']) : '' ?></dd></div><?php endif; ?>
      <?php if ($inq): ?><div><dt>From inquiry</dt><dd><a href="<?= e(url('admin/inquiry_view.php?id=' . (int)$inq['id'])) ?>"><?= e($inq['ref_code']) ?></a></dd></div><?php endif; ?>
      <?php if ($v['notes']): ?><div class="wide"><dt>Notes</dt><dd><?= nl2p($v['notes']) ?></dd></div><?php endif; ?>
    </dl>
  </section>

  <section class="panel">
    <div class="panel-head"><h2>Money</h2></div>
    <dl class="ledger">
      <div><dt>Monthly rent</dt><dd><?= e(money($v['monthly_rate'])) ?></dd></div>
      <div><dt>Security deposit</dt><dd><?= e(money($v['deposit_amount'])) ?></dd></div>
      <div><dt>Advance to secure</dt><dd><?= e(money($v['advance_amount'])) ?></dd></div>
      <div class="sep"><dt>Months billed so far</dt><dd><?= (int)$f['cycles'] ?></dd></div>
      <div><dt>Total due (deposit + rent)</dt><dd><?= e(money($f['due'])) ?></dd></div>
      <div><dt>Total paid</dt><dd><?= e(money($f['paid'])) ?></dd></div>
      <div class="total <?= $f['balance'] > 0 ? 'owe' : '' ?>"><dt><?= $f['credit'] > 0 ? 'Paid ahead (credit)' : 'Balance' ?></dt><dd><?= e(money($f['credit'] > 0 ? $f['credit'] : $f['balance'])) ?></dd></div>
      <?php if ($f['refunded'] > 0): ?><div><dt>Refunded</dt><dd><?= e(money($f['refunded'])) ?></dd></div><?php endif; ?>
      <?php if ($f['advance_needed'] > 0): ?><div><dt>Advance still needed</dt><dd><?= e(money($f['advance_needed'])) ?></dd></div><?php endif; ?>
      <?php if ($f['next_due']): ?><div><dt>Next rent due</dt><dd><?= e(fmt_date($f['next_due'])) ?></dd></div><?php endif; ?>
    </dl>
  </section>
</div>

<section class="panel">
  <div class="panel-head"><h2>Payments</h2><?php if ($st !== 'cancelled'): ?><a href="<?= e(url('admin/payment_form.php?reservation_id=' . $id)) ?>">Record payment</a><?php endif; ?></div>
  <?php if ($payments): ?>
  <div class="table-scroll"><table>
    <thead><tr><th>Date</th><th>Receipt</th><th>Type</th><th>Method</th><th>Reference</th><th class="num">Amount</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($payments as $p): ?>
      <tr class="<?= $p['is_void'] ? 'is-void' : '' ?>">
        <td><?= e(fmt_date($p['payment_date'])) ?></td>
        <td><a href="<?= e(url('admin/receipt.php?id=' . (int)$p['id'])) ?>"><?= e($p['receipt_no']) ?></a></td>
        <td><?= e(payment_type_label($p['payment_type'])) ?><?= $p['remarks'] ? '<br><span class="muted small">' . e($p['remarks']) . '</span>' : '' ?><?= $p['is_void'] ? '<br>' . badge('Void', 'bad') . ' <span class="muted small">' . e($p['void_reason']) . '</span>' : '' ?></td>
        <td><?= e(method_label($p['method'])) ?></td>
        <td><?= e($p['reference_no'] ?: '—') ?></td>
        <td class="num"><?= $p['payment_type'] === 'refund' ? '-' : '' ?><?= e(money($p['amount'])) ?></td>
        <td class="actions"><a class="btn btn-sm" href="<?= e(url('admin/receipt.php?id=' . (int)$p['id'])) ?>">Receipt</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php else: ?><p class="empty">No payments yet.</p><?php endif; ?>
</section>

<section class="panel">
  <div class="panel-head"><h2>Payment schedule</h2></div>
  <?php $sched = payment_schedule($v, $f); if ($sched): ?>
  <div class="table-scroll"><table class="sched">
    <thead><tr><th>What</th><th>Due date</th><th class="num">Amount</th><th class="num">Paid</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($sched as $i => $it):
        $late = $it['when'] === 'past' && $it['state'] !== 'paid';
        $isNext = $it['when'] === 'future' && $it['state'] !== 'paid' && !isset($shownNext) && ($shownNext = true); ?>
      <tr<?= $isNext ? ' class="is-next"' : '' ?>>
        <td><?= e($it['label']) ?></td>
        <td><?= e(fmt_date($it['due'])) ?><?= $it['when'] === 'today' ? ' <span class="muted small">(today)</span>' : '' ?></td>
        <td class="num"><?= e(money($it['amount'])) ?></td>
        <td class="num"><?= e(money($it['paid'])) ?></td>
        <td><?php if ($it['state'] === 'paid'): ?><span class="st-paid">Paid</span>
            <?php elseif ($late): ?><span class="st-late"><?= $it['state'] === 'partial' ? 'Balance ' . e(money($it['amount'] - $it['paid'])) : 'Missing' ?>, <?= days_between($it['due'], today()) ?> <?= days_between($it['due'], today()) === 1 ? 'day' : 'days' ?> late</span>
            <?php elseif ($it['when'] === 'today'): ?><strong>Due today</strong>
            <?php elseif ($it['state'] === 'partial'): ?>Partly paid
            <?php else: ?><span class="muted"><?= $isNext ? 'Next payment' : 'Upcoming' ?></span><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="hint sched-note">Rent is due one month after move-in, then every month on the same day. Money paid is applied to the oldest item first. Tenants are reminded automatically when a payment is coming up, missing, or only partly paid (see <a href="<?= e(url('admin/reminders.php')) ?>">Payment reminders</a>).</p>
  <?php else: ?><p class="empty">No schedule for a cancelled reservation.</p><?php endif; ?>
</section>

<?php if ($history): ?>
<section class="panel">
  <div class="panel-head"><h2>History</h2></div>
  <ul class="rows">
    <?php foreach ($history as $h): ?>
      <li><span><?= e($h['details'] ?: str_replace('_', ' ', $h['action'])) ?></span><span class="meta"><?= e(fmt_datetime($h['created_at'])) ?></span></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
<?php admin_footer();
