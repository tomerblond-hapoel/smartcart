<?php
// SmartCart — Group Purchases API
// CRITICAL: Join logic uses MySQL transactions + SELECT FOR UPDATE to prevent race conditions
// GET  ?action=list&status=open&city=&category=&product_id=&page=
// GET  ?action=get&id=
// POST ?action=create
// POST ?action=join&group_id=
// POST ?action=leave&group_id=
// POST ?action=message&group_id=    (post chat message)
// GET  ?action=expire_failed        (admin/cron: mark expired groups as failed)

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/paypal.php';
require_once __DIR__ . '/../includes/notifications.php';

session_start();
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

if ($action === 'list') {
    list_groups();
} elseif ($action === 'get' && isset($_GET['id'])) {
    get_group((int)$_GET['id']);
} elseif ($action === 'create' && $method === 'POST') {
    create_group();
} elseif ($action === 'create_join_order' && isset($_GET['group_id'])) {
    create_join_order((int)$_GET['group_id']);
} elseif ($action === 'complete_join' && isset($_GET['group_id'])) {
    complete_join((int)$_GET['group_id']);
} elseif ($action === 'join' && isset($_GET['group_id'])) {
    join_group((int)$_GET['group_id']);  // legacy: dev-mode direct join without PayPal
} elseif ($action === 'leave' && isset($_GET['group_id'])) {
    leave_group((int)$_GET['group_id']);
} elseif ($action === 'message' && isset($_GET['group_id'])) {
    post_message((int)$_GET['group_id']);
} elseif ($action === 'expire_failed') {
    expire_failed_groups();
} elseif ($action === 'lock' && isset($_GET['id'])) {
    lock_group((int)$_GET['id']);
} else {
    json_response(['error' => 'Unknown action'], 400);
}

// Returns true when PayPal credentials are real (not the placeholder defaults).
function paypal_configured(): bool {
    return defined('PAYPAL_CLIENT_ID')
        && strpos(PAYPAL_CLIENT_ID, 'your_paypal') === false
        && !empty(PAYPAL_CLIENT_ID);
}

// ─────────────────────────────────────────────────────────
function list_groups(): void {
    $pdo       = getPDO();
    $status    = $_GET['status']     ?? 'open';
    $city      = $_GET['city']       ?? '';
    $category  = $_GET['category']   ?? '';
    $product_id = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
    $page      = max(1, (int)($_GET['page'] ?? 1));
    $per_page  = 20;
    $offset    = ($page - 1) * $per_page;

    $where  = [];
    $params = [];

    if ($status && $status !== 'all') {
        $where[]  = 'gp.status = ?';
        $params[] = $status;
    }
    if ($city) {
        $clean = trim(preg_replace('/[-,].*$/u', '', $city));
        $tokens = preg_split('/\s+/u', $clean, -1, PREG_SPLIT_NO_EMPTY);
        $tokens = array_filter($tokens, fn($t) => mb_strlen($t) >= 2);
        if (!$tokens) $tokens = [$city];
        $parts = [];
        foreach ($tokens as $tok) {
            $parts[] = '(gp.city LIKE ? OR b.city LIKE ? OR b.address LIKE ?)';
            $params[] = "%$tok%"; $params[] = "%$tok%"; $params[] = "%$tok%";
        }
        $where[] = '(' . implode(' OR ', $parts) . ')';
    }
    if ($product_id) {
        $where[]  = 'gp.product_id = ?';
        $params[] = $product_id;
    }
    if ($category && $category !== 'all') {
        $where[]  = 'p.category = ?';
        $params[] = $category;
    }

    $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $stmt = $pdo->prepare("
        SELECT gp.*,
               p.name AS product_name, p.image_url AS product_image,
               p.price_ils, p.group_price_ils, p.category,
               p.min_participants,
               ROUND((p.price_ils - p.group_price_ils) / p.price_ils * 100) AS disc,
               ROUND(gp.current_participants / gp.target_participants * 100) AS fill_pct,
               u.full_name AS creator_name,
               b.business_name
        FROM group_purchases gp
        JOIN products p ON p.id = gp.product_id
        JOIN users u ON u.id = gp.creator_id
        JOIN businesses b ON b.id = p.business_id
        $where_sql
        ORDER BY gp.created_at DESC
        LIMIT $per_page OFFSET $offset
    ");
    $stmt->execute($params);
    $groups = $stmt->fetchAll();

    foreach ($groups as &$g) {
        $g['fill_pct']        = (int)$g['fill_pct'];
        $g['disc']            = (int)$g['disc'];
        $g['days_left']       = days_until($g['deadline']);
        $g['countdown']       = countdown_label($g['deadline']);
        $g['price_ils']       = (float)$g['price_ils'];
        $g['group_price_ils'] = (float)$g['group_price_ils'];
    }

    json_response(['groups' => $groups, 'page' => $page, 'per_page' => $per_page]);
}

// ─────────────────────────────────────────────────────────
function get_group(int $id): void {
    $pdo = getPDO();

    $stmt = $pdo->prepare("
        SELECT gp.*,
               p.name AS product_name, p.image_url AS product_image,
               p.price_ils, p.group_price_ils, p.category, p.description AS product_desc,
               p.min_participants, p.lat AS product_lat, p.lng AS product_lng,
               ROUND((p.price_ils - p.group_price_ils) / p.price_ils * 100) AS disc,
               ROUND(gp.current_participants / gp.target_participants * 100) AS fill_pct,
               u.full_name AS creator_name,
               b.business_name, b.lat AS biz_lat, b.lng AS biz_lng, b.address AS biz_address
        FROM group_purchases gp
        JOIN products p ON p.id = gp.product_id
        JOIN users u ON u.id = gp.creator_id
        JOIN businesses b ON b.id = p.business_id
        WHERE gp.id = ?
    ");
    $stmt->execute([$id]);
    $group = $stmt->fetch();
    if (!$group) json_response(['error' => 'Group not found'], 404);

    $group['fill_pct']        = (int)$group['fill_pct'];
    $group['disc']            = (int)$group['disc'];
    $group['days_left']       = days_until($group['deadline']);
    $group['countdown']       = countdown_label($group['deadline']);
    $group['price_ils']       = (float)$group['price_ils'];
    $group['group_price_ils'] = (float)$group['group_price_ils'];

    // Members
    $mstmt = $pdo->prepare("
        SELECT gm.status, gm.joined_at, u.id AS user_id, u.full_name
        FROM group_members gm
        JOIN users u ON u.id = gm.user_id
        WHERE gm.group_id = ?
        ORDER BY gm.joined_at ASC
    ");
    $mstmt->execute([$id]);
    $members = $mstmt->fetchAll();

    // Is current user a member?
    $user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    $is_member = false;
    $my_status = null;
    foreach ($members as $m) {
        if ((int)$m['user_id'] === $user_id) {
            $is_member = true;
            $my_status = $m['status'];
            break;
        }
    }

    // Messages
    $msgstmt = $pdo->prepare("
        SELECT gm.message_text, gm.sent_at, u.full_name, u.id AS user_id
        FROM group_messages gm
        JOIN users u ON u.id = gm.user_id
        WHERE gm.group_id = ?
        ORDER BY gm.sent_at ASC
    ");
    $msgstmt->execute([$id]);
    $messages = $msgstmt->fetchAll();

    json_response([
        'group'     => $group,
        'members'   => $members,
        'messages'  => $messages,
        'is_member' => $is_member,
        'my_status' => $my_status,
    ]);
}

// ─────────────────────────────────────────────────────────
function create_group(): void {
    $user_id = require_auth();
    $body    = array_merge($_POST, get_json_body());

    $product_id = (int)($body['product_id'] ?? 0);
    $target     = max(2, (int)($body['target_participants'] ?? 0));
    $deadline   = trim($body['deadline'] ?? '');

    if (!$product_id || !$deadline) {
        json_response(['error' => 'Product and deadline are required'], 400);
    }

    // Validate deadline is in the future
    if (strtotime($deadline) <= time()) {
        json_response(['error' => 'Deadline must be in the future'], 400);
    }

    $pdo = getPDO();

    // Get product details (for lat/lng and min_participants)
    $pstmt = $pdo->prepare("SELECT p.*, b.lat AS biz_lat, b.lng AS biz_lng, b.city AS biz_city FROM products p JOIN businesses b ON b.id = p.business_id WHERE p.id = ? AND p.status = 'active'");
    $pstmt->execute([$product_id]);
    $product = $pstmt->fetch();
    if (!$product) json_response(['error' => 'Product not found'], 404);

    if ($target < (int)$product['min_participants']) {
        json_response(['error' => "Target must be at least {$product['min_participants']} participants"], 400);
    }

    $lat  = $product['lat']      ?? $product['biz_lat'];
    $lng  = $product['lng']      ?? $product['biz_lng'];
    $city = $product['city']     ?? $product['biz_city'];

    $stmt = $pdo->prepare("
        INSERT INTO group_purchases (product_id, creator_id, target_participants, deadline, city, lat, lng)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$product_id, $user_id, $target, $deadline, $city, $lat, $lng]);
    $group_id = (int)$pdo->lastInsertId();

    // Creator automatically joins their own group
    $pdo->prepare("INSERT INTO group_members (group_id, user_id, status) VALUES (?, ?, 'joined')")->execute([$group_id, $user_id]);
    $pdo->prepare("UPDATE group_purchases SET current_participants = 1 WHERE id = ?")->execute([$group_id]);

    json_response(['success' => true, 'group_id' => $group_id], 201);
}

// ─────────────────────────────────────────────────────────
// V3 join flow (PayPal Smart Buttons):
//   1) Frontend calls create_join_order → backend creates AUTHORIZE order at PayPal, returns order_id
//   2) PayPal popup, user approves
//   3) Frontend calls complete_join with order_id → backend authorizes capture (hold funds, no charge),
//      inserts group_members row, increments participant count, possibly auto-closes (which captures all auths).
// ─────────────────────────────────────────────────────────
function create_join_order(int $group_id): void {
    $user_id = require_auth();
    $pdo     = getPDO();

    // Validate group + user is not already a member
    $stmt = $pdo->prepare("
        SELECT gp.status, gp.current_participants, gp.target_participants,
               p.name AS product_name, p.group_price_ils
        FROM group_purchases gp
        JOIN products p ON p.id = gp.product_id
        WHERE gp.id = ?
    ");
    $stmt->execute([$group_id]);
    $group = $stmt->fetch();
    if (!$group)                          json_response(['error' => 'Group not found'], 404);
    if ($group['status'] !== 'open')      json_response(['error' => 'Group is not open'], 409);
    if ($group['current_participants'] >= $group['target_participants']) {
        json_response(['error' => 'Group is full'], 409);
    }

    $exists = $pdo->prepare("SELECT id FROM group_members WHERE group_id = ? AND user_id = ?");
    $exists->execute([$group_id, $user_id]);
    if ($exists->fetch()) json_response(['error' => 'Already a member'], 409);

    $amount_ils = (float)$group['group_price_ils'];

    if (!paypal_configured()) {
        // Dev mode: skip PayPal, return synthetic order id; complete_join will accept it.
        json_response([
            'ok'         => true,
            'order_id'   => 'DEV-' . bin2hex(random_bytes(8)),
            'amount_ils' => $amount_ils,
            'dev_mode'   => true,
        ]);
    }

    $return_url = APP_URL . '/pages/group.php?id=' . $group_id;
    $cancel_url = APP_URL . '/pages/group.php?id=' . $group_id . '&cancelled=1';
    $res = paypal_create_authorize(
        $amount_ils,
        $group['product_name'] . ' (Group #' . $group_id . ')',
        "user_$user_id" . '_group_' . $group_id,
        $return_url, $cancel_url
    );
    if (!$res['ok']) json_response(['error' => $res['error']], 502);

    json_response([
        'ok'         => true,
        'order_id'   => $res['order_id'],
        'amount_ils' => $amount_ils,
        'amount_usd' => $res['amount_usd'],
    ]);
}

function complete_join(int $group_id): void {
    $user_id = require_auth();
    $body    = get_json_body();
    $order_id = trim($body['order_id'] ?? '');
    if (!$order_id) json_response(['error' => 'order_id required'], 400);

    $pdo = getPDO();
    $pdo->beginTransaction();
    try {
        // Lock group row
        $stmt = $pdo->prepare("
            SELECT gp.id, gp.current_participants, gp.target_participants, gp.status,
                   p.group_price_ils, p.id AS product_id
            FROM group_purchases gp JOIN products p ON p.id = gp.product_id
            WHERE gp.id = ? FOR UPDATE
        ");
        $stmt->execute([$group_id]);
        $group = $stmt->fetch();
        if (!$group)                     { $pdo->rollBack(); json_response(['error' => 'Group not found'], 404); }
        if ($group['status'] !== 'open') { $pdo->rollBack(); json_response(['error' => 'Group is no longer open'], 409); }

        $check = $pdo->prepare("SELECT id FROM group_members WHERE group_id = ? AND user_id = ?");
        $check->execute([$group_id, $user_id]);
        if ($check->fetch()) { $pdo->rollBack(); json_response(['error' => 'Already a member'], 409); }

        // Authorize at PayPal (dev mode skips)
        $auth_id = null;
        if (paypal_configured() && strpos($order_id, 'DEV-') !== 0) {
            $auth = paypal_authorize_capture($order_id);
            if (!$auth['ok']) {
                $pdo->rollBack();
                json_response(['error' => 'Payment authorization failed: ' . $auth['error']], 402);
            }
            $auth_id = $auth['auth_id'];
        } else {
            $auth_id = 'DEV-AUTH-' . bin2hex(random_bytes(8));
        }

        $amount = (float)$group['group_price_ils'];

        // Insert payment (authorized)
        $pdo->prepare("
            INSERT INTO payments (group_id, user_id, amount_ils, status, auth_status, auth_amount_ils, paypal_auth_id, authorized_at)
            VALUES (?, ?, ?, 'pending', 'authorized', ?, ?, NOW())
        ")->execute([$group_id, $user_id, $amount, $amount, $auth_id]);

        // Add member with deposit_status='held'
        $pdo->prepare("INSERT INTO group_members (group_id, user_id, status, deposit_status) VALUES (?, ?, 'joined', 'held')")
            ->execute([$group_id, $user_id]);

        $new_count = (int)$group['current_participants'] + 1;
        $target    = (int)$group['target_participants'];
        $pdo->prepare("UPDATE group_purchases SET current_participants = ? WHERE id = ?")
            ->execute([$new_count, $group_id]);

        $newly_closed = false;
        if ($new_count >= $target) {
            $pdo->prepare("UPDATE group_purchases SET status = 'closed' WHERE id = ?")->execute([$group_id]);
            $newly_closed = true;
        }
        $pdo->commit();

        // Notify joiner
        notify($user_id, 'group_joined',
            'You joined a group',
            'Payment authorized — you’ll be charged once the group succeeds.',
            APP_URL . '/pages/group.php?id=' . $group_id);

        // After commit: if newly closed, run capture pass (best-effort)
        if ($newly_closed) {
            capture_pending_for_group($group_id);
            notify_group_members($group_id, 'group_locked',
                'Group reached target — payment captured',
                'Your order has been placed. Track shipping on My Orders.',
                APP_URL . '/pages/my-orders.php');
        }

        json_response([
            'success'      => true,
            'current'      => $new_count,
            'target'       => $target,
            'fill_pct'     => (int)round($new_count / max(1,$target) * 100),
            'group_closed' => $newly_closed,
        ]);
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_response(['error' => 'Failed to complete join. Please contact support.', 'detail' => $e->getMessage()], 500);
    }
}

// Legacy direct join (no PayPal) — kept for admin/dev-mode use only.
// In production with PayPal configured, use create_join_order + complete_join instead.
function join_group(int $group_id): void {
    if (paypal_configured()) {
        json_response(['error' => 'Use PayPal flow: call create_join_order then complete_join'], 400);
    }
    // Dev path: mimic complete_join with a synthetic order id
    $user_id = require_auth();
    $_BODY = ['order_id' => 'DEV-' . bin2hex(random_bytes(8))];
    // Reuse complete_join by stuffing JSON into php://input is awkward — inline minimal logic:
    $pdo = getPDO();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT gp.*, p.group_price_ils FROM group_purchases gp JOIN products p ON p.id=gp.product_id WHERE gp.id = ? FOR UPDATE");
        $stmt->execute([$group_id]);
        $group = $stmt->fetch();
        if (!$group) { $pdo->rollBack(); json_response(['error' => 'Group not found'], 404); }
        if ($group['status'] !== 'open') { $pdo->rollBack(); json_response(['error' => 'This group is no longer open'], 409); }
        $check = $pdo->prepare("SELECT id FROM group_members WHERE group_id = ? AND user_id = ?");
        $check->execute([$group_id, $user_id]);
        if ($check->fetch()) { $pdo->rollBack(); json_response(['error' => 'You are already a member'], 409); }

        $auth_id = 'DEV-AUTH-' . bin2hex(random_bytes(8));
        $amount  = (float)$group['group_price_ils'];
        $pdo->prepare("INSERT INTO payments (group_id, user_id, amount_ils, status, auth_status, auth_amount_ils, paypal_auth_id, authorized_at) VALUES (?, ?, ?, 'pending', 'authorized', ?, ?, NOW())")
            ->execute([$group_id, $user_id, $amount, $amount, $auth_id]);
        $pdo->prepare("INSERT INTO group_members (group_id, user_id, status, deposit_status) VALUES (?, ?, 'joined', 'held')")
            ->execute([$group_id, $user_id]);
        $new_count = (int)$group['current_participants'] + 1;
        $target    = (int)$group['target_participants'];
        $pdo->prepare("UPDATE group_purchases SET current_participants = ? WHERE id = ?")->execute([$new_count, $group_id]);
        $newly_closed = false;
        if ($new_count >= $target) {
            $pdo->prepare("UPDATE group_purchases SET status = 'closed' WHERE id = ?")->execute([$group_id]);
            $newly_closed = true;
        }
        $pdo->commit();
        if ($newly_closed) capture_pending_for_group($group_id);
        json_response(['success' => true, 'current' => $new_count, 'target' => $target,
                       'fill_pct' => (int)round($new_count/max(1,$target)*100), 'group_closed' => $newly_closed]);
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_response(['error' => 'Failed to join'], 500);
    }
}

// ─────────────────────────────────────────────────────────
// Capture all 'authorized' payments for a group, creating orders and marking paid.
// Safe to call multiple times — only processes 'authorized' rows.
// ─────────────────────────────────────────────────────────
function capture_pending_for_group(int $group_id): array {
    $pdo = getPDO();
    $stmt = $pdo->prepare("
        SELECT pay.id AS payment_id, pay.user_id, pay.amount_ils, pay.paypal_auth_id, p.id AS product_id
        FROM payments pay
        JOIN group_purchases gp ON gp.id = pay.group_id
        JOIN products p ON p.id = gp.product_id
        WHERE pay.group_id = ? AND pay.auth_status = 'authorized'
    ");
    $stmt->execute([$group_id]);
    $pending = $stmt->fetchAll();

    $succeeded = 0; $failed = 0;
    foreach ($pending as $row) {
        $capture_id = null;
        if (paypal_configured() && strpos($row['paypal_auth_id'], 'DEV-') !== 0) {
            $res = paypal_capture($row['paypal_auth_id'], (float)$row['amount_ils']);
            if (!$res['ok']) {
                $pdo->prepare("UPDATE payments SET auth_status='failed', last_error=? WHERE id=?")
                    ->execute([substr($res['error'], 0, 500), $row['payment_id']]);
                $failed++;
                continue;
            }
            $capture_id = $res['capture_id'];
        } else {
            $capture_id = 'DEV-CAP-' . bin2hex(random_bytes(8));
        }

        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE payments SET status='completed', auth_status='captured', paypal_transaction_id=?, captured_at=NOW() WHERE id=?")
                ->execute([$capture_id, $row['payment_id']]);
            $pdo->prepare("UPDATE group_members SET status='paid', deposit_status='captured' WHERE group_id=? AND user_id=?")
                ->execute([$group_id, $row['user_id']]);
            $pdo->prepare("INSERT INTO orders (group_id, payment_id, user_id, product_id, amount_paid, shipping_status) VALUES (?, ?, ?, ?, ?, 'pending')")
                ->execute([$group_id, $row['payment_id'], $row['user_id'], $row['product_id'], $row['amount_ils']]);
            $pdo->commit();
            $succeeded++;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $pdo->prepare("UPDATE payments SET last_error=? WHERE id=?")
                ->execute(['DB error: ' . substr($e->getMessage(),0,400), $row['payment_id']]);
            $failed++;
        }
    }
    return ['succeeded' => $succeeded, 'failed' => $failed];
}

// Void all 'authorized' (not-yet-captured) payments for a group.
function void_pending_for_group(int $group_id): array {
    $pdo = getPDO();
    $stmt = $pdo->prepare("SELECT id, user_id, paypal_auth_id FROM payments WHERE group_id = ? AND auth_status = 'authorized'");
    $stmt->execute([$group_id]);
    $voided = 0; $failed = 0;
    foreach ($stmt->fetchAll() as $row) {
        if (paypal_configured() && strpos($row['paypal_auth_id'], 'DEV-') !== 0) {
            $res = paypal_void($row['paypal_auth_id']);
            if (!$res['ok']) {
                $pdo->prepare("UPDATE payments SET last_error=? WHERE id=?")
                    ->execute([substr($res['error'],0,500), $row['id']]);
                $failed++;
                continue;
            }
        }
        $pdo->prepare("UPDATE payments SET auth_status='voided', voided_at=NOW() WHERE id=?")
            ->execute([$row['id']]);
        $pdo->prepare("UPDATE group_members SET deposit_status='voided' WHERE group_id=? AND user_id=?")
            ->execute([$group_id, $row['user_id']]);
        $voided++;
    }
    return ['voided' => $voided, 'failed' => $failed];
}

// ─────────────────────────────────────────────────────────
function leave_group(int $group_id): void {
    $user_id = require_auth();
    $pdo     = getPDO();

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT status FROM group_members WHERE group_id = ? AND user_id = ?");
        $stmt->execute([$group_id, $user_id]);
        $member = $stmt->fetch();

        if (!$member) {
            $pdo->rollBack();
            json_response(['error' => 'You are not a member of this group'], 404);
        }
        if ($member['status'] === 'paid') {
            $pdo->rollBack();
            json_response(['error' => 'You cannot leave after paying'], 409);
        }

        // Check group is still open
        $gstmt = $pdo->prepare("SELECT status FROM group_purchases WHERE id = ? FOR UPDATE");
        $gstmt->execute([$group_id]);
        $group = $gstmt->fetch();
        if ($group['status'] !== 'open') {
            $pdo->rollBack();
            json_response(['error' => 'Cannot leave a closed or failed group'], 409);
        }

        // Void this user's authorization (if any) before deleting member
        $pstmt = $pdo->prepare("SELECT id, paypal_auth_id FROM payments WHERE group_id = ? AND user_id = ? AND auth_status = 'authorized'");
        $pstmt->execute([$group_id, $user_id]);
        foreach ($pstmt->fetchAll() as $pay) {
            if (paypal_configured() && strpos($pay['paypal_auth_id'], 'DEV-') !== 0) {
                paypal_void($pay['paypal_auth_id']); // best-effort
            }
            $pdo->prepare("UPDATE payments SET auth_status='voided', voided_at=NOW() WHERE id=?")
                ->execute([$pay['id']]);
        }

        $pdo->prepare("DELETE FROM group_members WHERE group_id = ? AND user_id = ?")
            ->execute([$group_id, $user_id]);
        $pdo->prepare("UPDATE group_purchases SET current_participants = GREATEST(0, current_participants - 1) WHERE id = ?")
            ->execute([$group_id]);

        $pdo->commit();
        json_response(['success' => true]);

    } catch (PDOException $e) {
        $pdo->rollBack();
        json_response(['error' => 'Failed to leave group. Please try again.'], 500);
    }
}

// ─────────────────────────────────────────────────────────
function post_message(int $group_id): void {
    $user_id = require_auth();
    $body    = array_merge($_POST, get_json_body());
    $text    = trim($body['message_text'] ?? '');

    if (!$text) json_response(['error' => 'Message cannot be empty'], 400);

    $pdo = getPDO();

    // Verify user is a member
    $stmt = $pdo->prepare("SELECT id FROM group_members WHERE group_id = ? AND user_id = ? AND status != 'cancelled'");
    $stmt->execute([$group_id, $user_id]);
    if (!$stmt->fetch()) {
        json_response(['error' => 'Only group members can send messages'], 403);
    }

    $pdo->prepare("INSERT INTO group_messages (group_id, user_id, message_text) VALUES (?, ?, ?)")
        ->execute([$group_id, $user_id, $text]);

    json_response(['success' => true, 'message_id' => (int)$pdo->lastInsertId()]);
}

// ─────────────────────────────────────────────────────────
// Business owner locks (closes early) their own deal
// ─────────────────────────────────────────────────────────
function lock_group(int $group_id): void {
    $user_id = require_auth();
    $pdo     = getPDO();

    // Verify the group belongs to this business owner
    $stmt = $pdo->prepare("
        SELECT gp.id, gp.status, b.user_id AS owner_id
        FROM group_purchases gp
        JOIN products p ON p.id = gp.product_id
        JOIN businesses b ON b.id = p.business_id
        WHERE gp.id = ?
    ");
    $stmt->execute([$group_id]);
    $row = $stmt->fetch();

    if (!$row) json_response(['error' => 'Group not found'], 404);
    if ((int)$row['owner_id'] !== $user_id && ($_SESSION['user_role'] ?? '') !== 'admin') {
        json_response(['error' => 'Not authorized'], 403);
    }
    if ($row['status'] !== 'open') {
        json_response(['error' => 'Group is already closed or failed'], 409);
    }

    $pdo->prepare("UPDATE group_purchases SET status = 'closed' WHERE id = ?")
        ->execute([$group_id]);

    // Capture all authorized payments for this group
    $result = capture_pending_for_group($group_id);

    json_response(['success' => true, 'captured' => $result['succeeded'], 'capture_failed' => $result['failed']]);
}

// ─────────────────────────────────────────────────────────
// Called by cron or admin to fail expired groups
// ─────────────────────────────────────────────────────────
function expire_failed_groups(): void {
    $result = run_expire_failed_groups();
    json_response(['success' => true, 'expired' => $result['expired'], 'voided' => $result['voided']]);
}

// Library function — callable from cron CLI as well.
function run_expire_failed_groups(): array {
    $pdo = getPDO();
    // Find groups about to expire
    $stmt = $pdo->prepare("
        SELECT id FROM group_purchases
        WHERE status = 'open'
          AND deadline < NOW()
          AND current_participants < target_participants
    ");
    $stmt->execute();
    $ids = array_column($stmt->fetchAll(), 'id');

    $voided_total = 0;
    foreach ($ids as $gid) {
        $pdo->prepare("UPDATE group_purchases SET status='failed' WHERE id=?")->execute([$gid]);
        $r = void_pending_for_group((int)$gid);
        $voided_total += $r['voided'];
        notify_group_members((int)$gid, 'group_failed',
            'Group cancelled — authorization released',
            'The group did not reach its target by the deadline. No money was charged.',
            APP_URL . '/pages/my-groups.php');
    }
    return ['expired' => count($ids), 'voided' => $voided_total];
}
