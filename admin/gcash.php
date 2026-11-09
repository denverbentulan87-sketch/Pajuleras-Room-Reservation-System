<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();

if (is_post()) {
    csrf_verify();
    defer_notifications();   // e-mails go out after the page is answered, so nobody waits for the mail server
    $action = post_str('action', 10);
    $sid = post_int('id');

    if ($action === 'qr_upload' || $action === 'qr_remove') {
        try {
            $old = setting('gcash_qr');
            if ($action === 'qr_remove') {
                q("INSERT INTO settings (setting_key, setting_value) VALUES ('gcash_qr', '') ON DUPLICATE KEY UPDATE setting_value = ''");
                delete_room_photo($old);
                log_activity('gcash_qr_removed', 'settings', null, 'GCash QR code removed');
                flash('success', 'The GCash QR code was removed.');
            } else {
                $new = store_room_photo($_FILES['qr'] ?? [], 'house');
                if ($new === null) throw new RuleException('Choose the QR code image first (a screenshot or photo saved from GCash).');
                q("INSERT INTO settings (setting_key, setting_value) VALUES ('gcash_qr', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", [$new]);
                if ($old !== '' && $old !== $new) delete_room_photo($old);
                log_activity('gcash_qr_saved', 'settings', null, $old !== '' ? 'GCash QR code replaced' : 'GCash QR code added');
                flash('success', 'GCash QR code saved. Tenants now see it when they pay.');
            }
        } catch (RuleException $ex) {
            flash('error', $ex->getMessage());
        }
        redirect('admin/gcash.php');
    }

    if ($action === 'approve') {
        try {
            $pid = in_transaction(function () use ($sid) {
                $sub = fetch_one("SELECT * FROM payment_submissions WHERE id = ? FOR UPDATE", [$sid]);
                if (!$sub) throw new RuleException('That report no longer exists.');
                if ($sub['status'] !== 'pending') throw new RuleException('That report was already ' . $sub['status'] . '.');
                $res = fetch_one("SELECT * FROM reservations WHERE id = ? FOR UPDATE", [$sub['reservation_id']]);
                if (!$res || $res['status'] === 'cancelled') throw new RuleException('This reservation is cancelled, so the payment cannot be added. Reject the report instead.');
                $amount = parse_money(post_str('amount', 15));
                if ($amount === null || $amount <= 0) throw new RuleException('Enter the amount you actually received.');
                $dupe = fetch_val("SELECT 1 FROM payments WHERE reference_no = ? AND is_void = 0 LIMIT 1", [$sub['gcash_ref']]);
                if ($dupe) throw new RuleException('A payment with that GCash reference already exists.');
                $remarks = 'Sent online via GCash (' . $sub['submission_code'] . ')' . ($sub['sender_name'] ? ', from ' . $sub['sender_name'] : '');
                $pid = create_gcash_payment($res, $sub['payment_type'], $amount, $sub['paid_date'], $sub['gcash_ref'], $remarks);
                q("UPDATE payment_submissions SET status = 'approved', payment_id = ?, reviewed_at = NOW() WHERE id = ?", [$pid, $sid]);
                log_activity('gcash_approved', 'reservation', (int)$res['id'], $sub['submission_code'] . ' approved: ' . money($amount) . ' (ref ' . $sub['gcash_ref'] . ')');
                return $pid;
            });
            // a reservation payment holds and confirms the bed by itself
            $secured = secure_reservation_after_payment((int)fetch_val("SELECT reservation_id FROM payment_submissions WHERE id = ?", [$sid]));
            if ($secured['message'] !== '') flash($secured['changed'] ? 'success' : 'warn', $secured['message']);
            // tell the tenant, clearly, that the money was received
            try {
                $row = fetch_one("SELECT s.*, t.id AS tid, t.full_name, t.contact_no, t.email, t.messenger, v.reservation_no, v.id AS rid, p.receipt_no, p.amount AS paid_amount
                                    FROM payment_submissions s JOIN tenants t ON t.id = s.tenant_id JOIN reservations v ON v.id = s.reservation_id JOIN payments p ON p.id = s.payment_id WHERE s.id = ?", [$sid]);
                if ($row) {
                    $fin = reservation_financials(fetch_one("SELECT * FROM reservations WHERE id = ?", [$row['rid']]));
                    $site = setting('site_name', 'Pajuleras Boarding House');
                    $balanceLine = $fin['balance'] > 0 ? 'Balance still unpaid: ' . money($fin['balance']) . '.' : ($fin['credit'] > 0 ? 'Your account is paid ahead by ' . money($fin['credit']) . '.' : 'Your account is fully paid. Thank you!');
                    notify_tenant(['id' => $row['tid'], 'contact_no' => $row['contact_no'], 'email' => $row['email'], 'messenger' => $row['messenger']], 'gcash_approved', [
                        'subject' => 'GCash payment received: ' . money($row['paid_amount']),
                        'body' => 'Hi ' . first_name($row['full_name']) . ",\n\nWe received your GCash payment of " . money($row['paid_amount']) . ' (GCash reference ' . $row['gcash_ref'] . ', report ' . $row['submission_code'] . ").\nOfficial receipt no.: " . $row['receipt_no'] . "\n" . $balanceLine . ($secured['changed'] ? "\nYour bed is now secured." : '') . "\n\nThank you,\n" . (setting('landlord_name') ?: $site) . "\n" . $site,
                    ], ['reservation_id' => (int)$row['rid'], 'dedupe' => 'gc' . $sid . ':ok']);
                }
            } catch (Throwable $e) {
                error_log('[PBH] gcash approve notice failed: ' . $e->getMessage());
            }
            flash('success', 'GCash payment approved and recorded. The tenant was notified. The receipt is ready to print.');
            redirect('admin/receipt.php?id=' . $pid);
        } catch (RuleException $ex) {
            flash('error', $ex->getMessage());
        }
    } elseif ($action === 'reject') {
        $reason = post_str('reason', 255);
        $sub = fetch_one("SELECT * FROM payment_submissions WHERE id = ?", [$sid]);
        if (!$sub || $sub['status'] !== 'pending') {
            flash('warn', 'That report is no longer waiting for review.');
        } elseif (mb_strlen($reason) < 3) {
            flash('error', 'Please write a short reason for rejecting the report.');
        } else {
            q("UPDATE payment_submissions SET status = 'rejected', reject_reason = ?, reviewed_at = NOW() WHERE id = ? AND status = 'pending'", [$reason, $sid]);
            log_activity('gcash_rejected', 'reservation', (int)$sub['reservation_id'], $sub['submission_code'] . ' rejected: ' . $reason);
            try {
                $row = fetch_one("SELECT t.id AS tid, t.full_name, t.contact_no, t.email, t.messenger FROM tenants t WHERE t.id = ?", [$sub['tenant_id']]);
                $site = setting('site_name', 'Pajuleras Boarding House');
                notify_tenant(['id' => $row['tid'], 'contact_no' => $row['contact_no'], 'email' => $row['email'], 'messenger' => $row['messenger']], 'gcash_rejected', [
                    'subject' => 'We could not confirm your GCash payment report',
                    'body' => 'Hi ' . first_name($row['full_name']) . ",\n\nWe could not confirm your GCash payment report " . $sub['submission_code'] . ' (reference ' . $sub['gcash_ref'] . ', ' . money($sub['amount']) . ").\nReason: " . $reason . "\n\nNo payment was recorded. If you did send the money, please contact us" . (setting('phone') !== '' ? ' at ' . setting('phone') : '') . " or send the report again with the correct details.\n\nThank you,\n" . (setting('landlord_name') ?: $site) . "\n" . $site,
                ], ['reservation_id' => (int)$sub['reservation_id'], 'dedupe' => 'gc' . $sid . ':no']);
            } catch (Throwable $e) {
                error_log('[PBH] gcash reject notice failed: ' . $e->getMessage());
            }
            flash('success', 'The report was rejected and the tenant was notified.');
        }
    }
    redirect('admin/gcash.php' . (post_str('tab', 10) !== '' ? '?tab=' . post_str('tab', 10) : ''));
}

$tab = in_array(get_str('tab'), ['pending', 'approved', 'rejected'], true) ? get_str('tab') : 'pending';
try {
    fetch_val("SELECT 1 FROM payment_submissions LIMIT 1");
} catch (Throwable $e) {
    admin_header('GCash payments', 'gcash');
    page_head('GCash payments');
    echo '<section class="panel"><p>The GCash feature needs one small database update. In phpMyAdmin, open the <strong>Import</strong> tab and run <code>database/04_upgrade_add_gcash_payments.sql</code>, then reload this page.</p></section>';
    admin_footer();
    exit;
}
$counts = [];
foreach (['pending', 'approved', 'rejected'] as $s) {
    $counts[$s] = (int)fetch_val("SELECT COUNT(*) FROM payment_submissions WHERE status = ?", [$s]);
}
$pg = paginate($counts[$tab], 10, 'page', '', true);
$rows = fetch_all("SELECT s.*, t.full_name, t.contact_no, v.reservation_no, r.room_no
                     FROM payment_submissions s
                     JOIN tenants t ON t.id = s.tenant_id
                     JOIN reservations v ON v.id = s.reservation_id
                     JOIN rooms r ON r.id = v.room_id
                    WHERE s.status = ?
                    ORDER BY s.created_at " . ($tab === 'pending' ? 'ASC' : 'DESC') . " LIMIT {$pg['per']} OFFSET {$pg['offset']}", [$tab]);

admin_header('GCash payments', 'gcash', ['fill' => true]);
page_head('GCash payments', 'Payments tenants sent online. Check them against your GCash history, then approve to record them and print the receipt.');
?>
<?php $qrUrl = gcash_qr_url(); ?>
<section class="panel qr-panel">
  <div class="qr-body">
    <?php if ($qrUrl !== ''): ?>
      <a class="qr-img" href="<?= e($qrUrl) ?>" target="_blank" rel="noopener" title="Open full size"><img src="<?= e($qrUrl) ?>" alt="Your GCash QR code" width="170" height="170"></a>
    <?php else: ?>
      <div class="qr-img qr-empty" aria-hidden="true"><?= icon('wallet') ?></div>
    <?php endif; ?>
    <div class="qr-text">
      <h2>GCash QR code</h2>
      <p class="muted"><?= $qrUrl !== '' ? 'Tenants see this code with your GCash number in the Pay with GCash pop-up, so they can scan and pay in seconds.' : 'Add your GCash QR code so tenants can scan it instead of typing your number. Save it from the GCash app (Profile, then My QR) and upload the picture here.' ?></p>
      <p class="qr-meta"><?= setting('gcash_no') !== '' ? 'GCash number: <strong>' . e(setting('gcash_no')) . '</strong>' : 'GCash number not set yet. <a href="' . e(url('admin/settings.php?tab=business')) . '">Add it in Settings</a>.' ?></p>
      <div class="qr-actions">
        <details class="action-box"><summary class="btn btn-primary"><?= icon('plus') ?><span><?= $qrUrl !== '' ? 'Replace QR code' : 'Add QR code' ?></span></summary>
          <form method="post" enctype="multipart/form-data" class="form mini">
            <?= csrf_field() ?><input type="hidden" name="action" value="qr_upload">
            <p class="m-sub">Use a clear, square picture of the QR code. A screenshot of GCash &ldquo;My QR&rdquo; works best. JPG, PNG or WebP, up to 3 MB.</p>
            <label>QR code picture <span class="req">*</span><input type="file" name="qr" accept="image/jpeg,image/png,image/webp" required data-preview="qr-prev"></label>
            <div class="qr-preview" id="qr-prev" hidden><img alt="Preview of the QR code"><span>This is how it will look.</span></div>
            <div class="form-actions"><button class="btn btn-primary" type="submit">Save QR code</button><button class="btn" type="button" data-cancel>Cancel</button></div>
          </form>
        </details>
        <?php if ($qrUrl !== ''): ?>
          <a class="btn" href="<?= e($qrUrl) ?>" download>Download</a>
          <form method="post" data-confirm="Remove the GCash QR code? Tenants will only see your GCash number." data-confirm-title="Remove QR code?" data-confirm-ok="Remove"><?= csrf_field() ?><input type="hidden" name="action" value="qr_remove"><button class="btn btn-danger-outline" type="submit">Remove</button></form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<section class="panel">
  <div class="tabs" role="navigation" aria-label="Report status">
    <?php foreach (['pending' => 'To review', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $k => $l): ?>
      <a href="<?= e(url('admin/gcash.php?tab=' . $k)) ?>"<?= $tab === $k ? ' class="active"' : '' ?>><?= e($l) ?> (<?= $counts[$k] ?>)</a>
    <?php endforeach; ?>
  </div>

  <?php if ($tab === 'pending' && setting('gcash_no') === ''): ?>
    <div class="flash flash-warn"><span>Your GCash number is not set, so tenants cannot see where to send money. Add it in <a href="<?= e(url('admin/settings.php?tab=business')) ?>">Settings</a>.</span></div>
  <?php endif; ?>

  <?php if ($rows): ?>
  <div class="table-scroll"><table>
    <thead><tr><th>Sent</th><th>Tenant</th><th>For</th><th>GCash reference</th><th class="num">Amount</th><th>Proof</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $s): ?>
      <tr>
        <td><?= e(fmt_date($s['paid_date'])) ?><br><span class="muted small">reported <?= e(fmt_datetime($s['created_at'])) ?></span></td>
        <td><strong><?= e($s['full_name']) ?></strong><br><span class="muted small"><a href="<?= e(url('admin/reservation_view.php?id=' . (int)$s['reservation_id'])) ?>"><?= e($s['reservation_no']) ?></a> &middot; Room <?= e($s['room_no']) ?><br><?= e($s['contact_no']) ?></span></td>
        <td><?= e(payment_type_label($s['payment_type'])) ?><?= $s['sender_name'] ? '<br><span class="muted small">from ' . e($s['sender_name']) . '</span>' : '' ?></td>
        <td><code><?= e($s['gcash_ref']) ?></code><br><span class="muted small"><?= e($s['submission_code']) ?></span></td>
        <td class="num"><?= e(money($s['amount'])) ?></td>
        <td><?php if ($s['proof_file']): ?><a class="btn btn-sm" href="<?= e(url('admin/gcash_proof.php?id=' . (int)$s['id'])) ?>" target="_blank" rel="noopener">View</a><?php else: ?><span class="muted small">None</span><?php endif; ?></td>
        <td class="actions">
          <?php if ($tab === 'pending'): ?>
            <details class="action-box inline-box"><summary class="btn btn-sm btn-primary">Approve</summary>
              <form method="post" class="form mini">
                <?= csrf_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                <label>Amount you received (₱)<input type="text" name="amount" value="<?= e(number_format((float)$s['amount'], 2, '.', '')) ?>" inputmode="decimal" required></label>
                <button class="btn btn-primary btn-sm" type="submit">Record payment</button>
              </form>
            </details>
            <details class="action-box inline-box"><summary class="btn btn-sm btn-danger-outline">Reject</summary>
              <form method="post" class="form mini">
                <?= csrf_field() ?><input type="hidden" name="action" value="reject"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                <label>Reason<input type="text" name="reason" maxlength="255" required placeholder="e.g. Not found in my GCash history"></label>
                <button class="btn btn-danger btn-sm" type="submit">Reject report</button>
              </form>
            </details>
          <?php elseif ($tab === 'approved' && $s['payment_id']): ?>
            <a class="btn btn-sm" href="<?= e(url('admin/receipt.php?id=' . (int)$s['payment_id'])) ?>">Receipt</a>
          <?php else: ?>
            <span class="muted small"><?= e($s['reject_reason'] ?? '') ?></span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?= pager($pg) ?>
  <?php else: ?>
    <p class="empty"><?= $tab === 'pending' ? 'Nothing to review. New GCash reports from tenants will appear here.' : 'Nothing here yet.' ?></p>
  <?php endif; ?>
</section>
<?php admin_footer();
