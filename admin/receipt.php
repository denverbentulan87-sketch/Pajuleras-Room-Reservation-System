<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();

$id = get_int('id');
$p = $id ? fetch_one("SELECT p.*, t.full_name, t.tenant_code, r.room_no, v.reservation_no
                        FROM payments p JOIN tenants t ON t.id = p.tenant_id
                        JOIN reservations v ON v.id = p.reservation_id JOIN rooms r ON r.id = v.room_id WHERE p.id = ?", [$id]) : null;
if (!$p) {
    abort(404, 'That receipt does not exist.');
}
$f = fetch_one("SELECT * FROM reservations WHERE id = ?", [$p['reservation_id']]);
$fin = reservation_financials($f);
$site = setting('site_name', 'Pajuleras Boarding House');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Receipt <?= e($p['receipt_no']) ?> | <?= e($site) ?></title>
<link rel="stylesheet" href="<?= e(asset('assets/css/base.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('assets/css/admin.css')) ?>">
</head>
<body class="admin receipt-page">
<div class="no-print receipt-bar">
  <a class="btn" href="<?= e(url('admin/reservation_view.php?id=' . (int)$p['reservation_id'])) ?>">Back to reservation</a>
  <button class="btn btn-primary" type="button" data-print>Print receipt</button>
</div>
<article class="receipt">
  <?php if ($p['is_void']): ?><div class="void-stamp">VOID</div><?php endif; ?>
  <header>
    <h1><?= e($site) ?></h1>
    <p><?= e(setting('address')) ?><br><?= e(setting('landlord_name')) ?><?= setting('phone') !== '' ? ' &middot; ' . e(setting('phone')) : '' ?></p>
    <h2><?= $p['payment_type'] === 'refund' ? 'Refund voucher' : 'Official receipt' ?></h2>
  </header>
  <dl class="receipt-grid">
    <div><dt>Receipt no.</dt><dd><?= e($p['receipt_no']) ?></dd></div>
    <div><dt>Date</dt><dd><?= e(fmt_date($p['payment_date'])) ?></dd></div>
    <div><dt><?= $p['payment_type'] === 'refund' ? 'Paid to' : 'Received from' ?></dt><dd><?= e($p['full_name']) ?> (<?= e($p['tenant_code']) ?>)</dd></div>
    <div><dt>Room</dt><dd>Room <?= e($p['room_no']) ?> &middot; <?= e($p['reservation_no']) ?></dd></div>
    <div><dt>Payment for</dt><dd><?= e(payment_type_label($p['payment_type'])) ?><?= $p['remarks'] ? ' (' . e($p['remarks']) . ')' : '' ?></dd></div>
    <div><dt>Method</dt><dd><?= e(method_label($p['method'])) ?><?= $p['reference_no'] ? ', ref. ' . e($p['reference_no']) : '' ?></dd></div>
  </dl>
  <div class="receipt-amount"><span>Amount</span><strong><?= e(money($p['amount'])) ?></strong></div>
  <?php if (!$p['is_void'] && $p['payment_type'] !== 'refund'): ?>
    <p class="receipt-balance">Balance after this payment: <strong><?= e(money($fin['balance'])) ?></strong><?= $fin['credit'] > 0 ? ' (paid ahead ' . e(money($fin['credit'])) . ')' : '' ?></p>
  <?php endif; ?>
  <?php if ($p['is_void']): ?><p class="receipt-balance">Voided on <?= e(fmt_datetime($p['voided_at'])) ?>: <?= e($p['void_reason']) ?></p><?php endif; ?>
  <footer>
    <div class="sign"><span></span>Received by: <?= e(setting('landlord_name')) ?></div>
    <p class="muted small">Thank you. Please keep this receipt.</p>
  </footer>
</article>
<script src="<?= e(asset('assets/js/app.js')) ?>" defer></script>
</body>
</html>
