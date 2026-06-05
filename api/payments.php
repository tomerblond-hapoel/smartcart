<?php
// SmartCart — Payments API
//
// Legacy PayPal actions (V3 authorize/capture flow):
//   POST ?action=create_order   — creates a PayPal order, returns {orderID}
//   POST ?action=capture_order  — captures approved order, creates Payment + Order records
//
// Hosted payment flow actions (V4):
//   GET  ?action=status&group_id=  — check payment status for current user + group
//   GET  ?action=get_url&group_id= — return the payment_url for current user + group
//   POST ?action=retry&group_id=   — re-generate a payment link for a failed payment

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

session_start();
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';

if ($action === 'create_order') {
    create_paypal_order();
} elseif ($action === 'capture_order') {
    capture_paypal_order();
} elseif ($action === 'status') {
    check_payment_status();
} elseif ($action === 'get_url') {
    get_payment_url();
} elseif ($action === 'retry' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    retry_payment();
} else {
    json_response(['error' => 'Unknown action'], 400);
}

// ─────────────────────────────────────────────────────────
// Get PayPal OAuth token (cached in session for 1 hour)
// ─────────────────────────────────────────────────────────
function get_paypal_token(): string {
    if (!empty($_SESSION['paypal_token']) && !empty($_SESSION['paypal_token_expires']) && $_SESSION['paypal_token_expires'] > time()) {
        return $_SESSION['paypal_token'];
    }

    $ch = curl_init(PAYPAL_BASE_URL . '/v1/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
        CURLOPT_USERPWD        => PAYPAL_CLIENT_ID . ':' . PAYPAL_SECRET,
        CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Accept-Language: en_US'],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) {
        json_response(['error' => 'PayPal authentication failed'], 502);
    }
    $data = json_decode($resp, true);
    $_SESSION['paypal_token']         = $data['access_token'];
    $_SESSION['paypal_token_expires'] = time() + (int)($data['expires_in'] ?? 3600) - 60;

    return $data['access_token'];
}

// ─────────────────────────────────────────────────────────
// POST action=create_order  body: {group_id}
// ─────────────────────────────────────────────────────────
function create_paypal_order(): void {
    $user_id = require_auth();
    $body    = get_json_body();
    $group_id = (int)($body['group_id'] ?? 0);

    if (!$group_id) json_response(['error' => 'group_id required'], 400);

    $pdo = getPDO();

    // Verify group is closed and user is an unpaid member
    $stmt = $pdo->prepare("
        SELECT gp.id, gp.status, gm.status AS member_status, p.name AS product_name, p.group_price_ils
        FROM group_purchases gp
        JOIN group_members gm ON gm.group_id = gp.id AND gm.user_id = ?
        JOIN products p ON p.id = gp.product_id
        WHERE gp.id = ?
    ");
    $stmt->execute([$user_id, $group_id]);
    $row = $stmt->fetch();

    if (!$row) json_response(['error' => 'You are not a member of this group'], 403);
    if ($row['status'] !== 'closed') json_response(['error' => 'Group is not closed yet'], 409);
    if ($row['member_status'] === 'paid') json_response(['error' => 'You have already paid'], 409);

    $amount_ils = (float)$row['group_price_ils'];
    $amount_usd = round($amount_ils / ILS_TO_USD_RATE, 2);

    // Create PayPal order
    $token  = get_paypal_token();
    $payload = json_encode([
        'intent' => 'CAPTURE',
        'purchase_units' => [[
            'amount' => [
                'currency_code' => 'USD', // PayPal sandbox works with USD
                'value'         => number_format($amount_usd, 2, '.', ''),
            ],
            'description' => $row['product_name'] . ' (Group #' . $group_id . ')',
        ]],
        'application_context' => [
            'brand_name'  => 'SmartCart',
            'user_action' => 'PAY_NOW',
        ],
    ]);

    $ch = curl_init(PAYPAL_BASE_URL . '/v2/checkout/orders');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($resp, true);
    if ($code !== 201 || empty($data['id'])) {
        json_response(['error' => 'Failed to create PayPal order'], 502);
    }

    json_response([
        'orderID'    => $data['id'],
        'amount_ils' => $amount_ils,
        'amount_usd' => $amount_usd,
    ]);
}

// ─────────────────────────────────────────────────────────
// POST action=capture_order  body: {orderID, group_id, shipping_address}
// ─────────────────────────────────────────────────────────
function capture_paypal_order(): void {
    $user_id = require_auth();
    $body    = get_json_body();
    $order_id       = trim($body['orderID']          ?? '');
    $group_id       = (int)($body['group_id']        ?? 0);
    $shipping_addr  = trim($body['shipping_address'] ?? '');

    if (!$order_id || !$group_id) {
        json_response(['error' => 'orderID and group_id are required'], 400);
    }

    // Capture payment at PayPal
    $token = get_paypal_token();
    $ch = curl_init(PAYPAL_BASE_URL . '/v2/checkout/orders/' . $order_id . '/capture');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => '{}',
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($resp, true);
    if ($code !== 201 || ($data['status'] ?? '') !== 'COMPLETED') {
        json_response(['error' => 'Payment capture failed'], 502);
    }

    $paypal_tx_id = $data['purchase_units'][0]['payments']['captures'][0]['id'] ?? $order_id;
    $amount_usd   = (float)($data['purchase_units'][0]['payments']['captures'][0]['amount']['value'] ?? 0);
    $amount_ils   = round($amount_usd * ILS_TO_USD_RATE, 2);

    // Save to DB
    $pdo = getPDO();
    $pdo->beginTransaction();
    try {
        // Get product_id
        $pstmt = $pdo->prepare("SELECT product_id FROM group_purchases WHERE id = ?");
        $pstmt->execute([$group_id]);
        $gp = $pstmt->fetch();
        if (!$gp) throw new RuntimeException('Group not found');

        // Insert payment
        $pdo->prepare("INSERT INTO payments (group_id, user_id, amount_ils, status, paypal_transaction_id) VALUES (?, ?, ?, 'completed', ?)")
            ->execute([$group_id, $user_id, $amount_ils, $paypal_tx_id]);
        $payment_id = (int)$pdo->lastInsertId();

        // Insert order
        $pdo->prepare("
            INSERT INTO orders (group_id, payment_id, user_id, product_id, amount_paid, shipping_status, shipping_address)
            VALUES (?, ?, ?, ?, ?, 'pending', ?)
        ")->execute([$group_id, $payment_id, $user_id, $gp['product_id'], $amount_ils, $shipping_addr ?: null]);
        $order_db_id = (int)$pdo->lastInsertId();

        // Mark member as paid
        $pdo->prepare("UPDATE group_members SET status = 'paid' WHERE group_id = ? AND user_id = ?")
            ->execute([$group_id, $user_id]);

        $pdo->commit();

        json_response([
            'success'    => true,
            'payment_id' => $payment_id,
            'order_id'   => $order_db_id,
            'amount_ils' => $amount_ils,
            'paypal_tx'  => $paypal_tx_id,
        ]);
    } catch (\Throwable $e) {
        $pdo->rollBack();
        json_response(['error' => 'Failed to record payment. Contact support with PayPal TX: ' . $paypal_tx_id], 500);
    }
}

// ─────────────────────────────────────────────────────────
// GET action=status&group_id=
// Returns full payment state for the current user + group.
// ─────────────────────────────────────────────────────────
function check_payment_status(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $user_id  = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    $group_id = (int)($_GET['group_id'] ?? 0);

    if (!$user_id || !$group_id) {
        json_response(['paid' => false, 'status' => null, 'payment_url' => null]);
    }

    $pdo  = getPDO();
    $stmt = $pdo->prepare("
        SELECT id, status, payment_url, provider_transaction_id, paid_at
        FROM   payments
        WHERE  group_id = ? AND user_id = ?
        ORDER  BY created_at DESC
        LIMIT  1
    ");
    $stmt->execute([$group_id, $user_id]);
    $pay = $stmt->fetch();

    $is_paid = $pay && in_array($pay['status'], ['paid', 'completed'], true);

    json_response([
        'paid'         => $is_paid,
        'status'       => $pay['status']       ?? null,
        'payment_url'  => $pay['payment_url']  ?? null,
        'payment_id'   => $pay['id']           ?? null,
        'paid_at'      => $pay['paid_at']      ?? null,
    ]);
}

// ─────────────────────────────────────────────────────────
// GET action=get_url&group_id=
// Returns the payment_url for the current user's pending payment.
// Used by the "Pay Now" button on the group page.
// ─────────────────────────────────────────────────────────
function get_payment_url(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $user_id  = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    $group_id = (int)($_GET['group_id'] ?? 0);

    if (!$user_id || !$group_id) {
        json_response(['error' => 'user and group_id required'], 400);
    }

    $pdo  = getPDO();
    $stmt = $pdo->prepare("
        SELECT id, status, payment_url
        FROM   payments
        WHERE  group_id = ? AND user_id = ?
        ORDER  BY created_at DESC
        LIMIT  1
    ");
    $stmt->execute([$group_id, $user_id]);
    $pay = $stmt->fetch();

    if (!$pay) {
        json_response(['error' => 'No payment record found for this group'], 404);
    }
    if (in_array($pay['status'], ['paid', 'completed'], true)) {
        json_response(['already_paid' => true, 'status' => $pay['status']]);
    }
    if (empty($pay['payment_url'])) {
        json_response(['error' => 'Payment link not yet generated. Please refresh and try again.'], 503);
    }

    json_response([
        'payment_url' => $pay['payment_url'],
        'payment_id'  => (int)$pay['id'],
        'status'      => $pay['status'],
    ]);
}

// ─────────────────────────────────────────────────────────
// POST action=retry&group_id=
// Re-generates a hosted payment link for a failed payment.
// Idempotent: if a pending link already exists it is returned unchanged.
// ─────────────────────────────────────────────────────────
function retry_payment(): void {
    $user_id  = require_auth();
    $group_id = (int)($_GET['group_id'] ?? 0);

    if (!$group_id) {
        json_response(['error' => 'group_id required'], 400);
    }

    require_once __DIR__ . '/../services/PaymentService.php';
    $pdo = getPDO();

    // Find the most recent payment record for this user + group
    $stmt = $pdo->prepare("
        SELECT pay.id, pay.status, pay.payment_url,
               pay.amount_ils, pay.product_id,
               gp.product_id AS gp_product_id,
               p.name AS product_name, p.group_price_ils,
               u.full_name, u.email, u.phone
        FROM   payments pay
        JOIN   group_purchases gp ON gp.id = pay.group_id
        JOIN   products p         ON p.id  = gp.product_id
        JOIN   users u            ON u.id  = pay.user_id
        WHERE  pay.group_id = ? AND pay.user_id = ?
        ORDER  BY pay.created_at DESC
        LIMIT  1
    ");
    $stmt->execute([$group_id, $user_id]);
    $pay = $stmt->fetch();

    if (!$pay) {
        json_response(['error' => 'No payment record found for this group'], 404);
    }
    if (in_array($pay['status'], ['paid', 'completed'], true)) {
        json_response(['already_paid' => true, 'status' => $pay['status']]);
    }

    // If there's already a working pending link, return it
    if ($pay['status'] === 'pending' && !empty($pay['payment_url'])) {
        json_response(['payment_url' => $pay['payment_url'], 'payment_id' => (int)$pay['id']]);
    }

    // (Re-)generate the payment link
    $link = PaymentService::createPaymentLink([
        'id'          => (int)$pay['id'],
        'user_id'     => $user_id,
        'group_id'    => $group_id,
        'product_id'  => (int)($pay['product_id'] ?: $pay['gp_product_id']),
        'amount'      => (float)($pay['amount_ils'] ?: $pay['group_price_ils']),
        'currency'    => 'ILS',
        'description' => $pay['product_name'] . ' (Group #' . $group_id . ')',
        'user_name'   => $pay['full_name'],
        'user_email'  => $pay['email'],
        'user_phone'  => $pay['phone'] ?? '',
    ]);

    if (!$link['ok']) {
        json_response(['error' => $link['error'] ?? 'Failed to generate payment link'], 502);
    }

    // Reset to pending with fresh URL
    $pdo->prepare("
        UPDATE payments
        SET    status                 = 'pending',
               payment_url             = ?,
               provider_transaction_id = ?,
               last_error              = NULL
        WHERE  id = ?
    ")->execute([$link['payment_url'], $link['transaction_id'], $pay['id']]);

    json_response(['payment_url' => $link['payment_url'], 'payment_id' => (int)$pay['id']]);
}
