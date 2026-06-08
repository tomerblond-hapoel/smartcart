<?php
/**
 * SmartCart — PayPal Return Handler
 * GET /api/payments/paypal_return.php?payment_id=X&token=PAYPAL_ORDER_ID
 *
 * PayPal redirects here after the user approves (or cancels) payment.
 * We capture the order and update the DB, then redirect the user.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/paypal.php';
require_once __DIR__ . '/../../includes/payment_processor.php';

session_start();

$payment_id = (int)($_GET['payment_id'] ?? 0);
$pp_order   = trim($_GET['token'] ?? '');   // PayPal calls it "token" on redirect

$base = rtrim(APP_URL, '/');

// User cancelled at PayPal
if (!$pp_order || isset($_GET['pay_cancelled'])) {
    $pdo = getPDO();
    $g   = $pdo->prepare("SELECT group_id FROM payments WHERE id=? LIMIT 1");
    $g->execute([$payment_id]);
    $row = $g->fetch();
    $gid = $row ? (int)$row['group_id'] : 0;
    header('Location: ' . $base . '/pages/group.php?id=' . $gid . '&pay_cancelled=1');
    exit;
}

if (!$payment_id) {
    header('Location: ' . $base . '/pages/my-groups.php');
    exit;
}

$pdo  = getPDO();
$stmt = $pdo->prepare("SELECT id, status, group_id, provider_transaction_id FROM payments WHERE id=? LIMIT 1");
$stmt->execute([$payment_id]);
$pay = $stmt->fetch();

if (!$pay) {
    header('Location: ' . $base . '/pages/my-groups.php');
    exit;
}

// Already paid — nothing to do
if (in_array($pay['status'], ['paid', 'completed'], true)) {
    header('Location: ' . $base . '/pages/my-orders.php?already_paid=1');
    exit;
}

// Capture the PayPal order
$token = paypal_token();
if (!$token) {
    PaymentService::log('paypal_return_token_failed', ['payment_id' => $payment_id]);
    header('Location: ' . $base . '/pages/group.php?id=' . $pay['group_id'] . '&pay_error=1');
    exit;
}

$ch = curl_init(PAYPAL_BASE_URL . '/v2/checkout/orders/' . urlencode($pp_order) . '/capture');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => '{}',
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
    CURLOPT_TIMEOUT        => 15,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode($resp, true);
if ($code !== 201 || ($data['status'] ?? '') !== 'COMPLETED') {
    PaymentService::log('paypal_return_capture_failed', [
        'payment_id' => $payment_id,
        'pp_order'   => $pp_order,
        'code'       => $code,
        'resp'       => substr($resp, 0, 500),
    ]);
    header('Location: ' . $base . '/pages/group.php?id=' . $pay['group_id'] . '&pay_error=1');
    exit;
}

$capture_id = $data['purchase_units'][0]['payments']['captures'][0]['id'] ?? $pp_order;

// Store capture ID as the provider transaction so webhook idempotency works
$pdo->prepare("UPDATE payments SET provider_transaction_id=? WHERE id=?")
    ->execute([$capture_id, $payment_id]);

// Mark paid, create order, check all-paid
process_payment_by_id($payment_id, 'paid');

header('Location: ' . $base . '/pages/my-orders.php?paid=1');
exit;
