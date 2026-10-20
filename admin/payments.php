<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();

if (is_post()) {
    csrf_verify();
    if (post_str('action', 20) === 'void') {
        $pid = post_int('id');
        $reason = post_str('reason', 255);
        $p = fetch_one("SELECT * FROM payments WHERE id = ?", [$pid]);
        if (!$p) {
            flash('error', 'That payment does not exist.');
        } elseif ($p['is_void']) {
            flash('warn', 'That payment was already voided.');
        } elseif (mb_strlen($reason) < 3) {
            flash('error', 'Please write a reason for voiding the payment.');
        } else {
            q("UPDATE payments SET is_void = 1, void_reason = ?, voided_at = NOW() WHERE id = ? AND is_void = 0", [$reason, $pid]);
            log_activity('payment_voided', 'reservation', (int)$p['reservation_id'], $p['receipt_no'] . ' voided: ' . $reason);
            flash('success', 'Payment ' . $p['receipt_no'] . ' was voided. Balances were recalculated.');
        }
        redirect(safe_return(post_str('return', 120), 'admin/payments.php'));
    }
    redirect('admin/payments.php');
}

$type = in_array(get_str('type'), ['advance', 'deposit', 'rent', 'other', 'refund'], true) ? get_str('type') : '';
$method = in_array(get_str('method'), ['cash', 'gcash', 'bank_transfer', 'other'], true) ? get_str('method') : '';
$from = valid_date(get_str('from', 10)) ? get_str('from', 10) : '';
$to = valid_date(get_str('to', 10)) ? get_str('to', 10) : '';
$search = get_str('q', 80);
$showVoid = get_str('void') === '1';

$where = [];
$params = [];
if (!$showVoid) $where[] = "p.is_void = 0";
if ($type) { $where[] = "p.payment_type = ?"; $params[] = $type; }
if ($method) { $where[] = "p.method = ?"; $params[] = $method; }
if ($from) { $where[] = "p.payment_date >= ?"; $params[] = $from; }
if ($to) { $where[] = "p.payment_date <= ?"; $params[] = $to; }
if ($search !== '') {
    $where[] = "(t.full_name LIKE ? OR p.receipt_no LIKE ? OR p.reference_no LIKE ? OR v.reservation_no LIKE ?)";
    $l = like_escape($search);
    array_push($params, $l, $l, $l, $l);
}
$w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$base = "FROM payments p JOIN tenants t ON t.id = p.tenant_id JOIN reservations v ON v.id = p.reservation_id $w";
$total = (int)fetch_val("SELECT COUNT(*) $base", $params);
$sum = (float)fetch_val("SELECT COALESCE(SUM(CASE WHEN p.is_void = 0 AND p.payment_type <> 'refund' THEN p.amount END),0) $base", $params);
$pg = paginate($total, 20);
$rows = fetch_all("SELECT p.*, t.full_name, v.reservation_no $base ORDER BY p.payment_date DESC, p.id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}", $params);

admin_header('Payments', 'payments');
page_head('Payments', 'Advance payments, deposits, rent, and refunds.', '<a class="btn" href="' . e(url('admin/gcash.php')) . '">GCash reports' . (gcash_pending_count() ? ' (' . gcash_pending_count() . ')' : '') . '</a> <a class="btn btn-primary" href="' . e(url('admin/payment_form.php')) . '">Record payment</a>');
?>
<section class="panel">
  <form class="filters" method="get">
    <label>Search<input type="search" name="q" value="<?= e($search) ?>" placeholder="Tenant, receipt, reference"></label>
    <label>Type<select name="type"><option value="">All</option>
      <?php foreach (['advance','deposit','rent','other','refund'] as $t): ?><option value="<?= $t ?>"<?= selected($type, $t) ?>><?= e(payment_type_label($t)) ?></option><?php endforeach; ?></select></label>
    <label>Method<select name="method"><option value="">All</option>
      <?php foreach (['cash','gcash','bank_transfer','other'] as $m): ?><option value="<?= $m ?>"<?= selected($method, $m) ?>><?= e(method_label($m)) ?></option><?php endforeach; ?></select></label>
    <label>From<input type="date" name="from" value="<?= e($from) ?>"></label>
    <label>To<input type="date" name="to" value="<?= e($to) ?>"></label>
    <label class="check"><input type="checkbox" name="void" value="1"<?= checked($showVoid) ?>> Show voided</label>
    <button class="btn" type="submit">Filter</button>
    <?php if ($type || $method || $from || $to || $search || $showVoid): ?><a class="btn btn-ghost" href="<?= e(url('admin/payments.php')) ?>">Clear</a><?php endif; ?>
  </form>
  <p class="summary-line">Received in this view (refunds not counted): <strong><?= e(money($sum)) ?></strong></p>

  <?php if ($rows): ?>
  <div class="table-scroll"><table>
    <thead><tr><th>Date</th><th>Receipt</th><th>Tenant</th><th>Reservation</th><th>Type</th><th>Method</th><th class="num">Amount</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $p): ?>
      <tr class="<?= $p['is_void'] ? 'is-void' : '' ?>">
        <td><?= e(fmt_date($p['payment_date'])) ?></td>
        <td><a href="<?= e(url('admin/receipt.php?id=' . (int)$p['id'])) ?>"><?= e($p['receipt_no']) ?></a></td>
        <td><?= e($p['full_name']) ?></td>
        <td><a href="<?= e(url('admin/reservation_view.php?id=' . (int)$p['reservation_id'])) ?>"><?= e($p['reservation_no']) ?></a></td>
        <td><?= e(payment_type_label($p['payment_type'])) ?><?= $p['is_void'] ? ' ' . badge('Void', 'bad') : '' ?></td>
        <td><?= e(method_label($p['method'])) ?><?= $p['reference_no'] ? '<br><span class="muted small">' . e($p['reference_no']) . '</span>' : '' ?></td>
        <td class="num"><?= $p['payment_type'] === 'refund' ? '-' : '' ?><?= e(money($p['amount'])) ?></td>
        <td class="actions">
          <a class="btn btn-sm" href="<?= e(url('admin/receipt.php?id=' . (int)$p['id'])) ?>">Receipt</a>
          <?php if (!$p['is_void']): ?>
          <details class="action-box inline-box"><summary class="btn btn-sm btn-danger-outline">Void</summary>
            <form method="post" class="form mini">
              <?= csrf_field() ?><input type="hidden" name="action" value="void"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
              <input type="hidden" name="return" value="admin/payments.php">
              <label>Reason<input type="text" name="reason" maxlength="255" required placeholder="e.g. Entered twice by mistake"></label>
              <button class="btn btn-danger btn-sm" type="submit">Void payment</button>
            </form>
          </details>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?= pager($pg) ?>
  <?php else: ?><p class="empty">No payments match.</p><?php endif; ?>
</section>
<?php admin_footer();
