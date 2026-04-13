<?php
// SmartCart — Orders API
// POST ?action=update_shipping&id= — update shipping status (business owner or admin)

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

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
        SELECT o.id FROM orders o
        JOIN products p ON p.id = o.product_id
        JOIN businesses b ON b.id = p.business_id
        WHERE o.id = ? AND (b.user_id = ? OR ? IN (SELECT id FROM users WHERE role = 'admin'))
    ");
    $stmt->execute([$id, $user_id, $user_id]);
    if (!$stmt->fetch()) {
        json_response(['error' => 'Not authorized'], 403);
    }

    $pdo->prepare("UPDATE orders SET shipping_status=?, tracking_id=COALESCE(NULLIF(?,''), tracking_id) WHERE id=?")
        ->execute([$status, $tracking, $id]);

    json_response(['success' => true]);
} else {
    json_response(['error' => 'Unknown action'], 400);
}
