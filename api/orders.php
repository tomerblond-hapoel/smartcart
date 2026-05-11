<?php
// SmartCart — Orders API
// POST ?action=update_shipping&id= — update shipping status (business owner or admin)
// GET  ?action=list                — list current user's orders (for polling)
// GET  ?action=get&id=              — fetch a single order

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

session_start();
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($action === 'update_shipping' && $id) {
    $user_id = require_auth();
    $body    = get_json_body();
    $status  = $body['shipping_status'] ?? '';
    $tracking = trim($body['tracking_id'] ?? '');

    if (!in_array($status, ['pending','processing','shipped','delivered'])) {
        json_response(['error' => 'Invalid shipping status'], 400);
    }

    $pdo = getPDO();

    // Verify user is the business owner of this order's product
    $stmt = $pdo->prepare("
        SELECT o.id, o.user_id AS customer_id, p.name AS product_name FROM orders o
        JOIN products p ON p.id = o.product_id
        JOIN businesses b ON b.id = p.business_id
        WHERE o.id = ? AND (b.user_id = ? OR ? IN (SELECT id FROM users WHERE role = 'admin'))
    ");
    $stmt->execute([$id, $user_id, $user_id]);
    $order = $stmt->fetch();
    if (!$order) {
        json_response(['error' => 'Not authorized'], 403);
    }

    $pdo->prepare("UPDATE orders SET shipping_status=?, tracking_id=COALESCE(NULLIF(?,''), tracking_id) WHERE id=?")
        ->execute([$status, $tracking, $id]);

    // Notify customer
    $titles = [
        'pending'    => 'Order received',
        'processing' => 'Order being prepared',
        'shipped'    => 'Order shipped',
        'delivered'  => 'Order delivered',
    ];
    notify((int)$order['customer_id'], 'order_' . $status,
        $titles[$status] . ' — ' . $order['product_name'],
        $tracking ? "Tracking: $tracking" : '',
        APP_URL . '/pages/my-orders.php');

    json_response(['success' => true]);

} elseif ($action === 'list') {
    $user_id = require_auth();
    $pdo = getPDO();
    $stmt = $pdo->prepare("
        SELECT o.id, o.amount_paid, o.shipping_status, o.tracking_id, o.created_at,
               p.name AS product_name, p.image_url, b.business_name
        FROM orders o
        JOIN products p ON p.id = o.product_id
        JOIN businesses b ON b.id = p.business_id
        WHERE o.user_id = ?
        ORDER BY o.created_at DESC
        LIMIT 100
    ");
    $stmt->execute([$user_id]);
    json_response(['orders' => $stmt->fetchAll()]);

} elseif ($action === 'get' && $id) {
    $user_id = require_auth();
    $pdo = getPDO();
    $stmt = $pdo->prepare("
        SELECT o.*, p.name AS product_name, p.image_url, b.business_name
        FROM orders o
        JOIN products p ON p.id = o.product_id
        JOIN businesses b ON b.id = p.business_id
        WHERE o.id = ? AND (o.user_id = ? OR b.user_id = ? OR ? IN (SELECT id FROM users WHERE role='admin'))
    ");
    $stmt->execute([$id, $user_id, $user_id, $user_id]);
    $row = $stmt->fetch();
    if (!$row) json_response(['error' => 'Order not found'], 404);
    json_response(['order' => $row]);

} else {
    json_response(['error' => 'Unknown action'], 400);
}
