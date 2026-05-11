<?php
// SmartCart — Admin-only API actions

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/paypal.php';
require_once __DIR__ . '/../includes/notifications.php';

function admin_paypal_configured(): bool {
    return defined('PAYPAL_CLIENT_ID')
        && strpos(PAYPAL_CLIENT_ID, 'your_paypal') === false
        && !empty(PAYPAL_CLIENT_ID);
}

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
        $uid    = (int)($body['user_id'] ?? 0);
        $enable = isset($body['enable']) ? (bool)$body['enable'] : false;
        if (!$uid) json_response(['error' => 'user_id required'], 400);
        $pdo->prepare("UPDATE users SET is_active=? WHERE id=?")->execute([$enable ? 1 : 0, $uid]);
        json_response(['success' => true]);

    case 'fail_group':
        if (!$id) json_response(['error' => 'id required'], 400);
        // Void any authorized (un-captured) payments before marking failed
        $voids = $pdo->prepare("SELECT id, paypal_auth_id FROM payments WHERE group_id=? AND auth_status='authorized'");
        $voids->execute([$id]);
        foreach ($voids->fetchAll() as $p) {
            if (admin_paypal_configured() && strpos($p['paypal_auth_id'], 'DEV-') !== 0) {
                paypal_void($p['paypal_auth_id']);
            }
            $pdo->prepare("UPDATE payments SET auth_status='voided', voided_at=NOW() WHERE id=?")->execute([$p['id']]);
        }
        $pdo->prepare("UPDATE group_purchases SET status='failed' WHERE id=? AND status='open'")->execute([$id]);
        json_response(['success' => true]);

    case 'refund_group':
        if (!$id) json_response(['error' => 'id required'], 400);
        $reason = trim($body['reason'] ?? 'Group cancelled by admin');
        $caps = $pdo->prepare("SELECT id, paypal_transaction_id, amount_ils, user_id FROM payments WHERE group_id=? AND auth_status='captured'");
        $caps->execute([$id]);
        $refunded = 0; $failed = 0; $errors = [];
        foreach ($caps->fetchAll() as $p) {
            if (admin_paypal_configured() && strpos($p['paypal_transaction_id'], 'DEV-') !== 0) {
                $res = paypal_refund($p['paypal_transaction_id'], (float)$p['amount_ils'], $reason);
                if (!$res['ok']) {
                    $failed++;
                    $errors[] = "payment {$p['id']}: " . $res['error'];
                    $pdo->prepare("UPDATE payments SET last_error=? WHERE id=?")->execute([$res['error'], $p['id']]);
                    continue;
                }
                $refund_id = $res['refund_id'];
            } else {
                $refund_id = 'DEV-REF-' . bin2hex(random_bytes(8));
            }
            $pdo->prepare("UPDATE payments SET auth_status='refunded', refund_id=?, refunded_at=NOW() WHERE id=?")
                ->execute([$refund_id, $p['id']]);
            $pdo->prepare("UPDATE group_members SET deposit_status='refunded' WHERE group_id=? AND user_id=?")
                ->execute([$id, $p['user_id']]);
            notify((int)$p['user_id'], 'order_refunded',
                'Refund issued — ' . format_ils((float)$p['amount_ils']),
                'Reason: ' . $reason,
                APP_URL . '/pages/my-orders.php');
            $refunded++;
        }
        json_response(['success' => true, 'refunded' => $refunded, 'failed' => $failed, 'errors' => $errors]);

    case 'deactivate_product':
        if (!$id) json_response(['error' => 'id required'], 400);
        $pdo->prepare("UPDATE products SET status='inactive' WHERE id=?")->execute([$id]);
        json_response(['success' => true]);

    default:
        json_response(['error' => 'Unknown action'], 400);
}
