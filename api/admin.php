<?php
// SmartCart — Admin-only API actions

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

session_start();
header('Content-Type: application/json; charset=utf-8');

// Admin only
require_auth();
if (($_SESSION['user_role'] ?? '') !== 'admin') {
    json_response(['error' => 'Admin access required'], 403);
}

$pdo    = getPDO();
$action = $_GET['action'] ?? '';
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$body   = get_json_body();

switch ($action) {
    case 'update_user_role':
        $uid  = (int)($body['user_id'] ?? 0);
        $role = $body['role'] ?? '';
        if (!$uid || !in_array($role, ['customer','business','admin'])) {
            json_response(['error' => 'Invalid input'], 400);
        }
        $pdo->prepare("UPDATE users SET role=? WHERE id=?")->execute([$role, $uid]);
        json_response(['success' => true]);

    case 'disable_user':
        $uid = (int)($body['user_id'] ?? 0);
        if (!$uid) json_response(['error' => 'user_id required'], 400);
        $pdo->prepare("UPDATE users SET is_active=0 WHERE id=?")->execute([$uid]);
        json_response(['success' => true]);

    case 'fail_group':
        if (!$id) json_response(['error' => 'id required'], 400);
        $pdo->prepare("UPDATE group_purchases SET status='failed' WHERE id=? AND status='open'")->execute([$id]);
        json_response(['success' => true]);

    case 'deactivate_product':
        if (!$id) json_response(['error' => 'id required'], 400);
        $pdo->prepare("UPDATE products SET status='inactive' WHERE id=?")->execute([$id]);
        json_response(['success' => true]);

    default:
        json_response(['error' => 'Unknown action'], 400);
}
