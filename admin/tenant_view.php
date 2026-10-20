<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();

$id = get_int('id');
$t = $id ? fetch_one("SELECT * FROM tenants WHERE id = ?", [$id]) : null;
if (!$t) {
    abort(404, 'That tenant does not exist.');
}

if (is_post()) {
    csrf_verify();
    if (post_str('action', 20) === 'erase') {
        try {
            $label = in_transaction(function () use ($id) {
                $t = fetch_one("SELECT * FROM tenants WHERE id = ? FOR UPDATE", [$id]);
                if (!$t) throw new RuleException('That tenant no longer exists.');
                if ($t['anonymized_at']) throw new RuleException('This tenant\'s personal details were already erased.');
                if (strcasecmp(post_str('confirm_code', 20), (string)$t['tenant_code']) !== 0) {
                    throw new RuleException('To erase, type the tenant code exactly as shown (' . $t['tenant_code'] . ').');
                }
                if ((int)fetch_val("SELECT COUNT(*) FROM reservations WHERE tenant_id = ? AND status IN ('pending','confirmed','checked_in')", [$id]) > 0) {
                    throw new RuleException('This tenant still has an open reservation or is staying now. Check them out or cancel the reservation first.');
                }
                $label = 'Former tenant ' . $t['tenant_code'];
                $oldName = $t['full_name'];
                $oldPhone = $t['contact_no'];

                // inquiries from this person (linked to their reservations, or sent from the same number)
                $inqIds = array_column(fetch_all("SELECT id FROM inquiries WHERE contact_no = ? OR id IN (SELECT inquiry_id FROM reservations WHERE tenant_id = ? AND inquiry_id IS NOT NULL)", [$oldPhone, $id]), 'id');
                foreach ($inqIds as $iid) {
                    q("DELETE FROM inquiry_messages WHERE inquiry_id = ?", [$iid]);
                    q("UPDATE inquiries SET name = ?, contact_no = '00000000000', email = NULL, ip = NULL, status = 'closed', admin_unread = 0 WHERE id = ?", [$label, $iid]);
                    q("INSERT INTO inquiry_messages (inquiry_id, sender, body) VALUES (?, 'admin', '[Messages erased at the person''s request]')", [$iid]);
                }
                q("UPDATE reservations SET notes = NULL WHERE tenant_id = ?", [$id]);
                q("UPDATE payments SET remarks = NULL WHERE tenant_id = ?", [$id]);
                // names inside the audit trail
                if (mb_strlen($oldName) >= 3) {
                    q("UPDATE activity_log SET details = REPLACE(details, ?, ?) WHERE details LIKE ?", [$oldName, $label, like_escape($oldName)]);
                }
                q("UPDATE tenants SET full_name = ?, birth_date = NULL, contact_no = '00000000000', email = NULL, home_address = NULL,
                          school_or_workplace = NULL, id_presented = NULL, guardian_name = NULL, guardian_contact = NULL, notes = NULL,
                          status = 'inactive', anonymized_at = NOW() WHERE id = ?", [$label, $id]);
                log_activity('tenant_erased', 'tenant', $id, 'Personal details of ' . $t['tenant_code'] . ' erased');
                return $label;
            });
            flash('success', 'Personal details erased. Payments and receipts are kept under "' . $label . '".');
        } catch (RuleException $ex) {
            flash('error', $ex->getMessage());
        }
        redirect('admin/tenant_view.php?id=' . $id);
    }
    if (post_str('action', 20) === 'delete') {
        if ((int)fetch_val("SELECT COUNT(*) FROM reservations WHERE tenant_id = ?", [$id]) > 0) {
            flash('error', 'This tenant has reservation history and cannot be deleted. Mark the tenant inactive instead.');
            redirect('admin/tenant_view.php?id=' . $id);
        }
        q("DELETE FROM tenants WHERE id = ?", [$id]);
        log_activity('tenant_deleted', 'tenant', $id, $t['full_name']);
        flash('success', 'Tenant record deleted.');
        redirect('admin/tenants.php');
    }
}

$res = fetch_all("SELECT v.*, r.room_no FROM reservations v JOIN rooms r ON r.id = v.room_id WHERE v.tenant_id = ? ORDER BY v.created_at DESC", [$id]);
$totals = payment_totals(array_column($res, 'id'));
$pays = fetch_all("SELECT p.*, v.reservation_no FROM payments p JOIN reservations v ON v.id = p.reservation_id WHERE p.tenant_id = ? ORDER BY p.payment_date DESC, p.id DESC LIMIT 20", [$id]);

admin_header($t['full_name'], 'tenants');
$erased = !empty($t['anonymized_at']);
page_head($t['full_name'], 'Tenant ' . $t['tenant_code'] . ($erased ? ' (personal details erased)' : ''),
    $erased ? '' : '<a class="btn btn-primary" href="' . e(url('admin/reservation_form.php?tenant_id=' . $id)) . '">New reservation</a>'
              . '<a class="btn" href="' . e(url('admin/tenant_form.php?id=' . $id)) . '">Edit</a>');
if ($erased) echo '<div class="flash flash-info"><span>This person\'s personal details were erased on ' . e(fmt_datetime($t['anonymized_at'])) . '. Only the reservation and payment records remain, for your accounting.</span></div>';
$openRes = (int)fetch_val("SELECT COUNT(*) FROM reservations WHERE tenant_id = ? AND status IN ('pending','confirmed','checked_in')", [$id]);
?>
<div class="grid-2 top">
  <section class="panel">
    <div class="panel-head"><h2>Profile</h2><?= $t['status'] === 'active' ? badge('Active', 'ok') : badge('Inactive', 'neutral') ?></div>
    <dl class="details">
      <div><dt>Gender</dt><dd><?= e(ucfirst($t['gender'])) ?></dd></div>
      <div><dt>Birth date</dt><dd><?= e(fmt_date($t['birth_date'])) ?></dd></div>
      <div><dt>Contact number</dt><dd><?= e($t['contact_no']) ?></dd></div>
      <div><dt>Email</dt><dd><?= e($t['email'] ?: '—') ?></dd></div>
      <div><dt>Home address</dt><dd><?= e($t['home_address'] ?: '—') ?></dd></div>
      <div><dt>Occupation</dt><dd><?= e(occupation_label($t['occupation'])) ?><?= $t['school_or_workplace'] ? ', ' . e($t['school_or_workplace']) : '' ?></dd></div>
      <div><dt>Valid ID presented</dt><dd><?= e($t['id_presented'] ?: '—') ?></dd></div>
      <div><dt>Guardian / emergency</dt><dd><?= e($t['guardian_name'] ?: '—') ?><?= $t['guardian_contact'] ? ' (' . e($t['guardian_contact']) . ')' : '' ?></dd></div>
      <div><dt>Registered</dt><dd><?= e(fmt_date($t['created_at'])) ?></dd></div>
      <?php if ($t['notes']): ?><div class="wide"><dt>Notes</dt><dd><?= nl2p($t['notes']) ?></dd></div><?php endif; ?>
    </dl>
  </section>

  <section class="panel">
    <div class="panel-head"><h2>Recent payments</h2></div>
    <?php if ($pays): ?>
    <div class="table-scroll"><table>
      <thead><tr><th>Date</th><th>Receipt</th><th>Type</th><th class="num">Amount</th></tr></thead>
      <tbody>
      <?php foreach ($pays as $p): ?>
        <tr class="<?= $p['is_void'] ? 'is-void' : '' ?>">
          <td><?= e(fmt_date($p['payment_date'])) ?></td>
          <td><a href="<?= e(url('admin/receipt.php?id=' . (int)$p['id'])) ?>"><?= e($p['receipt_no']) ?></a></td>
          <td><?= e(payment_type_label($p['payment_type'])) ?><?= $p['is_void'] ? ' ' . badge('Void', 'bad') : '' ?></td>
          <td class="num"><?= $p['payment_type'] === 'refund' ? '-' : '' ?><?= e(money($p['amount'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php else: ?><p class="empty">No payments recorded yet.</p><?php endif; ?>
  </section>
</div>

<section class="panel">
  <div class="panel-head"><h2>Reservations and stays</h2></div>
  <?php if ($res): ?>
  <div class="table-scroll"><table>
    <thead><tr><th>Reservation</th><th>Room</th><th>Move-in</th><th>Move-out</th><th>Status</th><th class="num">Paid</th><th class="num">Balance</th></tr></thead>
    <tbody>
    <?php foreach ($res as $v): $f = compute_financials($v, $totals[(int)$v['id']] ?? []); ?>
      <tr>
        <td><a href="<?= e(url('admin/reservation_view.php?id=' . (int)$v['id'])) ?>"><?= e($v['reservation_no']) ?></a></td>
        <td>Room <?= e($v['room_no']) ?><?= (int)$v['beds'] > 1 ? ' (' . (int)$v['beds'] . ' beds)' : '' ?></td>
        <td><?= e(fmt_date($v['move_in_date'])) ?></td>
        <td><?= e(fmt_date($v['move_out_date'], 'Open')) ?></td>
        <td><?= reservation_badge($v['status']) ?></td>
        <td class="num"><?= e(money($f['paid'])) ?></td>
        <td class="num"><?= $f['balance'] > 0 ? '<strong>' . e(money($f['balance'])) . '</strong>' : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php else: ?>
    <p class="empty">No reservations yet. <a href="<?= e(url('admin/reservation_form.php?tenant_id=' . $id)) ?>">Create one</a>.</p>
    <form method="post" data-confirm="Delete this tenant record? This cannot be undone.">
      <?= csrf_field() ?><input type="hidden" name="action" value="delete">
      <button class="btn btn-danger btn-sm" type="submit">Delete tenant record</button>
    </form>
  <?php endif; ?>
</section>
<?php if (!$erased): ?>
<section class="panel">
  <div class="panel-head"><h2>Privacy: erase personal details</h2></div>
  <p class="muted">Use this when a tenant asks you to remove their information. Their name, contact number, address, ID, guardian, notes, and any inquiry messages are permanently erased. Payment and reservation records stay (as "Former tenant <?= e($t['tenant_code']) ?>") so your totals and receipts remain correct. This cannot be undone.</p>
  <?php if ($openRes): ?>
    <p class="hint warn">This tenant has an open reservation or is staying now. Check them out or cancel first.</p>
  <?php else: ?>
  <details class="action-box">
    <summary class="btn btn-danger-outline">Erase personal details</summary>
    <form method="post" class="form mini" data-confirm="Permanently erase all personal details of this tenant?">
      <?= csrf_field() ?><input type="hidden" name="action" value="erase">
      <label>Type <strong><?= e($t['tenant_code']) ?></strong> to confirm<input type="text" name="confirm_code" maxlength="20" required autocomplete="off"></label>
      <button class="btn btn-danger" type="submit">Erase permanently</button>
    </form>
  </details>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php admin_footer();
