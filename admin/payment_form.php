<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();

$resId = get_int('reservation_id') ?: post_int('reservation_id');
$open = fetch_all("SELECT v.id, v.reservation_no, v.status, t.full_name, r.room_no
                     FROM reservations v JOIN tenants t ON t.id = v.tenant_id JOIN rooms r ON r.id = v.room_id
                    WHERE v.status IN ('pending','confirmed','checked_in') OR v.id = ?
                    ORDER BY t.full_name, v.id", [$resId]);
$errors = [];
$OLD = ['reservation_id' => $resId ?: '', 'payment_type' => '', 'amount' => '', 'payment_date' => today(), 'method' => 'cash', 'reference_no' => '', 'remarks' => ''];

$current = $resId ? fetch_one("SELECT * FROM reservations WHERE id = ?", [$resId]) : null;
$fin = $current ? reservation_financials($current) : null;
if ($current && !is_post()) {
    // sensible default: advance first, then whatever is owed
    if (in_array($current['status'], ['pending', 'confirmed'], true) && $fin['advance_needed'] > 0) {
        $OLD['payment_type'] = 'advance';
        $OLD['amount'] = number_format($fin['advance_needed'], 2, '.', '');
    } elseif ($fin['balance'] > 0) {
        $OLD['payment_type'] = 'rent';
        $OLD['amount'] = number_format($fin['balance'], 2, '.', '');
    }
}

if (is_post()) {
    csrf_verify();
    $OLD = [
        'reservation_id' => $resId ?: '', 'payment_type' => post_str('payment_type', 10), 'amount' => post_str('amount', 15),
        'payment_date' => post_str('payment_date', 10), 'method' => post_str('method', 15),
        'reference_no' => post_str('reference_no', 80), 'remarks' => post_str('remarks', 255),
    ];
    $amount = parse_money($OLD['amount']);
    if (!$current) $errors[] = 'Choose the reservation this payment is for.';
    if (!in_array($OLD['payment_type'], ['advance', 'deposit', 'rent', 'other', 'refund'], true)) $errors[] = 'Choose what the payment is for.';
    if ($amount === null || $amount <= 0) $errors[] = 'Enter the amount as a number greater than zero, for example 700 or 1,500.50.';
    if (!valid_date($OLD['payment_date']) || $OLD['payment_date'] > today()) $errors[] = 'Enter the payment date (today or earlier).';
    if (!in_array($OLD['method'], ['cash', 'gcash', 'bank_transfer', 'other'], true)) $errors[] = 'Choose a payment method.';
    if (in_array($OLD['method'], ['gcash', 'bank_transfer'], true) && $OLD['reference_no'] === '') $errors[] = 'Enter the reference number for GCash or bank transfer payments.';
    if ($current && $current['status'] === 'cancelled' && $OLD['payment_type'] !== 'refund') $errors[] = 'This reservation is cancelled. Only refunds can be recorded.';

    if (!$errors) {
        try {
            $pid = in_transaction(function () use ($OLD, $amount, $resId) {
                $res = fetch_one("SELECT * FROM reservations WHERE id = ? FOR UPDATE", [$resId]);
                if (!$res) throw new RuleException('That reservation no longer exists.');
                if ($OLD['payment_type'] === 'refund') {
                    $f = reservation_financials($res);
                    if ($amount > $f['refundable'] + 0.001) {
                        throw new RuleException('A refund cannot be more than ' . money($f['refundable']) . ' for this reservation. Only the security deposit and rent paid ahead can be returned (everything, if the reservation was cancelled).');
                    }
                }
                q("INSERT INTO payments (reservation_id, tenant_id, payment_type, amount, payment_date, method, reference_no, remarks) VALUES (?,?,?,?,?,?,?,?)",
                  [$res['id'], $res['tenant_id'], $OLD['payment_type'], $amount, $OLD['payment_date'], $OLD['method'], $OLD['reference_no'] ?: null, $OLD['remarks'] ?: null]);
                $nid = (int)db()->lastInsertId();
                $no = 'OR-' . str_pad((string)$nid, 6, '0', STR_PAD_LEFT);
                q("UPDATE payments SET receipt_no = ? WHERE id = ?", [$no, $nid]);
                log_activity('payment_recorded', 'reservation', (int)$res['id'], $no . ': ' . payment_type_label($OLD['payment_type']) . ' ' . money($amount));
                return $nid;
            });
            flash('success', 'Payment recorded. Receipt is ready to print.');
            redirect('admin/receipt.php?id=' . $pid);
        } catch (RuleException $ex) {
            $errors[] = $ex->getMessage();
        }
    }
}

admin_header('Record payment', 'payments');
page_head('Record payment', 'An official receipt number is created automatically.');
?>
<form class="panel form" method="post" novalidate>
  <?= csrf_field() ?>
  <?php render_errors($errors); ?>
  <?php if ($resId && $current): ?>
    <input type="hidden" name="reservation_id" value="<?= $resId ?>">
    <div class="callout">
      <strong><?= e($current['reservation_no']) ?></strong>
      <?php foreach ($open as $o) if ((int)$o['id'] === $resId) echo ' &middot; ' . e($o['full_name']) . ', Room ' . e($o['room_no']); ?>
      <div class="callout-meta">
        Paid <?= e(money($fin['paid'])) ?> of <?= e(money($fin['due'])) ?> due
        <?php if ($fin['balance'] > 0): ?> &middot; <strong>Balance <?= e(money($fin['balance'])) ?></strong><?php endif; ?>
        <?php if ($fin['advance_needed'] > 0): ?> &middot; Advance still needed <?= e(money($fin['advance_needed'])) ?><?php endif; ?>
        <?php if ($fin['refundable'] > 0): ?> &middot; Can be refunded <?= e(money($fin['refundable'])) ?><?php endif; ?>
      </div>
      <a class="small" href="<?= e(url('admin/payment_form.php')) ?>">Choose a different reservation</a>
    </div>
  <?php else: ?>
    <label>Reservation <span class="req">*</span>
      <select name="reservation_id" required data-autosubmit-get>
        <option value="">Choose a reservation</option>
        <?php foreach ($open as $o): ?><option value="<?= (int)$o['id'] ?>"><?= e($o['full_name']) ?> &middot; <?= e($o['reservation_no']) ?> &middot; Room <?= e($o['room_no']) ?> (<?= e($o['status']) ?>)</option><?php endforeach; ?>
      </select>
      <span class="hint">Pick a reservation and the form fills in the amount that is due.</span>
    </label>
  <?php endif; ?>

  <?php if ($current): ?>
  <div class="row-3">
    <label>Payment for <span class="req">*</span>
      <select name="payment_type" required>
        <option value="">Choose</option>
        <?php foreach (['advance', 'deposit', 'rent', 'other', 'refund'] as $t): ?><option value="<?= $t ?>"<?= selected(old('payment_type'), $t) ?>><?= e(payment_type_label($t)) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label>Amount (₱) <span class="req">*</span><input type="text" name="amount" value="<?= e(old('amount')) ?>" inputmode="decimal" required></label>
    <label>Date paid <span class="req">*</span><input type="date" name="payment_date" value="<?= e(old('payment_date')) ?>" max="<?= e(today()) ?>" required></label>
  </div>
  <div class="row-3">
    <label>Method
      <select name="method">
        <?php foreach (['cash', 'gcash', 'bank_transfer', 'other'] as $m): ?><option value="<?= $m ?>"<?= selected(old('method'), $m) ?>><?= e(method_label($m)) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label>Reference number <span class="opt">(required for GCash / bank)</span><input type="text" name="reference_no" value="<?= e(old('reference_no')) ?>" maxlength="80"></label>
    <label>Remarks <span class="opt">(optional)</span><input type="text" name="remarks" value="<?= e(old('remarks')) ?>" maxlength="255" placeholder="e.g. Rent for March"></label>
  </div>
  <p class="hint">Use <strong>Refund</strong> when you return money, such as a deposit at check-out. Refunds do not create a balance.</p>
  <div class="form-actions">
    <button class="btn btn-primary" type="submit">Save payment</button>
    <a class="btn" href="<?= e(url('admin/reservation_view.php?id=' . $resId)) ?>">Cancel</a>
  </div>
  <?php endif; ?>
</form>
<?php admin_footer();
