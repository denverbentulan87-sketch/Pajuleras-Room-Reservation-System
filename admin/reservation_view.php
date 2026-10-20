<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();

$id = get_int('id');
$v = $id ? fetch_one("SELECT v.*, t.full_name, t.tenant_code, t.contact_no, t.email, t.gender, r.room_no, r.room_name, r.capacity, r.monthly_rate AS room_rate
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

<?php if (in_array($st, ['pending', 'confirmed', 'checked_in'], true)): ?>
<section class="panel actions-panel">
  <h2>What would you like to do?</h2>
  <div class="action-row">
    <?php if ($st === 'pending'): ?>
      <form method="post" action="<?= e(url('admin/reservation_action.php')) ?>">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="confirm">
        <button class="btn btn-primary" type="submit">Confirm reservation</button>
      </form>
    <?php elseif ($st === 'confirmed'): ?>
      <form method="post" action="<?= e(url('admin/reservation_action.php')) ?>" <?= $f['balance'] > 0 ? 'data-confirm="This tenant still owes ' . e(money($f['balance'])) . '. Check in anyway?"' : '' ?>>
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="checkin">
        <button class="btn btn-primary" type="submit">Check in tenant</button>
      </form>
    <?php endif; ?>

    <?php if ($st === 'checked_in'): ?>
    <details class="action-box">
      <summary class="btn">Check out</summary>
      <form method="post" action="<?= e(url('admin/reservation_action.php')) ?>" class="form mini">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="checkout">
        <label>Check-out date<input type="date" name="checkout_date" value="<?= e(today()) ?>" min="<?= e(substr($v['checked_in_at'], 0, 10)) ?>" max="<?= e(today()) ?>" required></label>
        <?php if ($f['balance'] > 0): ?><p class="hint warn">Unpaid balance: <?= e(money($f['balance'])) ?></p><?php endif; ?>
        <button class="btn btn-primary" type="submit">Confirm check-out</button>
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
        <button class="btn btn-danger" type="submit">Cancel reservation</button>
      </form>
    </details>
    <?php endif; ?>
  </div>
  <?php if ($st === 'pending'): ?><p class="hint">A pending reservation does not hold a bed. Confirming it checks the room's records for overlaps.</p><?php endif; ?>
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
