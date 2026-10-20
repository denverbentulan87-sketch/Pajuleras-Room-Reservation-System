<?php
require __DIR__ . '/includes/bootstrap.php';

$ajax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
$result = null;

if (is_post()) {
    $sent = (string)($_POST['_csrf'] ?? '');
    if ($sent === '' || !hash_equals(csrf_token(), $sent)) {
        if ($ajax) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(419);
            echo json_encode(['ok' => false, 'errors' => ['Your session expired. Please refresh the page and try again.']]);
            exit;
        }
        abort(419);
    }
    if (post_str('website', 100) !== '') {            // honeypot
        $result = ['ok' => true, 'errors' => [], 'code' => 'GC-RECEIVED', 'amount' => ''];
    } else {
        try {
        $result = gcash_submit([
            'reservation_no' => post_str('reservation_no', 20), 'contact_no' => post_str('contact_no', 30),
            'payment_type' => post_str('payment_type', 10), 'amount' => post_str('amount', 15),
            'paid_date' => post_str('paid_date', 10), 'gcash_ref' => post_str('gcash_ref', 40), 'sender_name' => post_str('sender_name', 120),
        ], $_FILES['proof'] ?? []);
        } catch (Throwable $ex) {
            error_log('[PBH] gcash_pay failed: ' . $ex->getMessage());
            $result = ['ok' => false, 'errors' => ['Online GCash reporting is not ready yet. Please contact the landlord.']];
        }
    }
    if ($ajax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result);
        exit;
    }
    if ($result['ok']) {
        $_SESSION['gcash_done'] = ['code' => $result['code'], 'amount' => $result['amount']];
        redirect('gcash_pay.php?sent=1');
    }
}

$done = null;
if (get_str('sent') === '1' && isset($_SESSION['gcash_done'])) {
    $done = $_SESSION['gcash_done'];
    unset($_SESSION['gcash_done']);
}

public_header('Pay with GCash', 'inquire', 'Send your rent or advance payment by GCash and report it to the landlord.');
?>
<section class="page-title"><div class="wrap">
  <h1>Pay with GCash</h1>
  <p>Send your payment from anywhere, then report it here so the landlord can confirm it.</p>
</div></section>
<section class="section tight"><div class="wrap narrow">
<?php if ($done): ?>
  <div class="success-box">
    <h2>Payment report received</h2>
    <p>The landlord will check your GCash payment and confirm it. You will get an official receipt once it is approved.</p>
    <div class="code-box"><span>Your report code</span><strong class="code"><?= e($done['code']) ?></strong></div>
    <p class="hero-actions"><a class="btn" href="<?= e(url('')) ?>">Back to home</a></p>
  </div>
<?php else: ?>
  <form class="card form" method="post" enctype="multipart/form-data" action="<?= e(url('gcash_pay.php')) ?>" novalidate>
    <?= csrf_field() ?>
    <?php render_errors($result['errors'] ?? []); ?>
    <?= gcash_pay_fields('pg', $_POST) ?>
    <button class="btn btn-primary btn-lg" type="submit">Send payment report</button>
  </form>
<?php endif; ?>
</div></section>
<?php public_footer();
