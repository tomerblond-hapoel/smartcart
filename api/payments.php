<?php
// SmartCart — PayPal Payments API
// POST ?action=create_order   — creates a PayPal order, returns {orderID}
// POST ?action=capture_order  — captures approved order, creates Payment + Order records
// GET  ?action=status&group_id= — check if current user has paid for a group

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

session_start();
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';

match(true) {
    $action === 'create_order'  => create_paypal_order(),
    $action === 'capture_order' => capture_paypal_order(),
    $action === 'status'        => check_payment_status(),
    default => json_response(['error' => 'Unknown action'], 400),
};

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
// ─────────────────────────────────────────────────────────
function check_payment_status(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $user_id  = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    $group_id = (int)($_GET['group_id'] ?? 0);

    if (!$user_id || !$group_id) {
        json_response(['paid' => false]);
    }

    $pdo  = getPDO();
    $stmt = $pdo->prepare("SELECT status FROM payments WHERE group_id = ? AND user_id = ? AND status = 'completed'");
    $stmt->execute([$group_id, $user_id]);
    $paid = (bool)$stmt->fetch();

    json_response(['paid' => $paid]);
}
