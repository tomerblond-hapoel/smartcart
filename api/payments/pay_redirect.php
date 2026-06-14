<?php
// Generates a fresh PayPal order and redirects the user to checkout.
// Avoids "Things don't appear to be working" errors caused by expired order tokens.
//
// GET /api/payments/pay_redirect.php?payment_id=X

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../services/PaymentService.php';

session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: ' . APP_URL . '/pages/login.php');
    exit;
}
$user_id = (int)$_SESSION['user_id'];

$payment_id = (int)($_GET['payment_id'] ?? 0);
if (!$payment_id) {
    header('Location: ' . APP_URL . '/pages/index.php');
    exit;
}

$pdo = getPDO();

// Load payment + product/user details needed to create a new PayPal order
$stmt = $pdo->prepare("
    SELECT pay.id, pay.status, pay.group_id, pay.user_id,
           pay.amount_ils, pay.product_id,
           COALESCE(pay.product_id, gp.product_id) AS resolved_product_id,
           p.name  AS product_name,
           u.full_name AS user_name,
           u.email     AS user_email,
           u.phone     AS user_phone
    FROM   payments pay
    JOIN   group_purchases gp ON gp.id = pay.group_id
    JOIN   products p         ON p.id  = COALESCE(pay.product_id, gp.product_id)
    JOIN   users u            ON u.id  = pay.user_id
    WHERE  pay.id = ? AND pay.user_id = ?
");
$stmt->execute([$payment_id, $user_id]);
$payment = $stmt->fetch();

if (!$payment) {
    header('Location: ' . APP_URL . '/pages/index.php');
    exit;
}

if (!in_array($payment['status'], ['pending', 'failed'], true)) {
    // Already paid — send to orders page
    header('Location: ' . APP_URL . '/pages/my-orders.php');
    exit;
}

// Always create a fresh PayPal order to avoid expired-token errors
$result = PaymentService::createPaymentLink([
    'id'          => $payment['id'],
    'user_id'     => $user_id,
    'group_id'    => (int)$payment['group_id'],
    'product_id'  => (int)$payment['resolved_product_id'],
    'amount'      => (float)$payment['amount_ils'],
    'currency'    => 'ILS',
    'description' => 'SmartCart — ' . $payment['product_name'],
    'user_name'   => $payment['user_name'],
    'user_email'  => $payment['user_email'],
    'user_phone'  => $payment['user_phone'] ?? '',
]);

if (!$result['ok']) {
    $err = $result['error'] ?? 'unknown';
    PaymentService::log('pay_redirect_failed', ['payment_id' => $payment_id, 'error' => $err]);
    header('Location: ' . APP_URL . '/pages/group.php?id=' . $payment['group_id'] . '&pay_error=' . urlencode($err));
    exit;
}

// Persist the fresh URL and transaction ID
$pdo->prepare("UPDATE payments SET payment_url = ?, provider_transaction_id = ? WHERE id = ?")
    ->execute([$result['payment_url'], $result['transaction_id'], $payment_id]);

// Redirect to fresh PayPal checkout
header('Location: ' . $result['payment_url']);
exit;
