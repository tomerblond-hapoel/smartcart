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
} elseif ($action === 'join' && isset($_GET['group_id'])) {
    join_group((int)$_GET['group_id']);
} elseif ($action === 'leave' && isset($_GET['group_id'])) {
    leave_group((int)$_GET['group_id']);
} elseif ($action === 'message' && isset($_GET['group_id'])) {
    post_message((int)$_GET['group_id']);
} elseif ($action === 'expire_failed') {
    expire_failed_groups();
} else {
    json_response(['error' => 'Unknown action'], 400);
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
        $where[]  = 'gp.city LIKE ?';
        $params[] = "%$city%";
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
// CRITICAL: Atomic join with transaction + SELECT FOR UPDATE
// Prevents race condition where two users simultaneously try to join the last spot
// ─────────────────────────────────────────────────────────
function join_group(int $group_id): void {
    $user_id = require_auth();
    $pdo     = getPDO();

    $pdo->beginTransaction();
    try {
        // Lock the row to prevent concurrent joins
        $stmt = $pdo->prepare(
            "SELECT id, current_participants, target_participants, status FROM group_purchases WHERE id = ? FOR UPDATE"
        );
        $stmt->execute([$group_id]);
        $group = $stmt->fetch();

        if (!$group) {
            $pdo->rollBack();
            json_response(['error' => 'Group not found'], 404);
        }
        if ($group['status'] !== 'open') {
            $pdo->rollBack();
            json_response(['error' => 'This group is no longer open'], 409);
        }

        // Check if user is already a member
        $check = $pdo->prepare("SELECT id FROM group_members WHERE group_id = ? AND user_id = ?");
        $check->execute([$group_id, $user_id]);
        if ($check->fetch()) {
            $pdo->rollBack();
            json_response(['error' => 'You are already a member of this group'], 409);
        }

        // Add member
        $pdo->prepare("INSERT INTO group_members (group_id, user_id, status) VALUES (?, ?, 'joined')")
            ->execute([$group_id, $user_id]);

        $new_count = (int)$group['current_participants'] + 1;
        $target    = (int)$group['target_participants'];

        // Update count
        $pdo->prepare("UPDATE group_purchases SET current_participants = ? WHERE id = ?")
            ->execute([$new_count, $group_id]);

        // Auto-close when target reached
        $newly_closed = false;
        if ($new_count >= $target) {
            $pdo->prepare("UPDATE group_purchases SET status = 'closed' WHERE id = ?")
                ->execute([$group_id]);
            $newly_closed = true;
        }

        $pdo->commit();

        json_response([
            'success'      => true,
            'current'      => $new_count,
            'target'       => $target,
            'fill_pct'     => (int)round($new_count / $target * 100),
            'group_closed' => $newly_closed,
        ]);

    } catch (PDOException $e) {
        $pdo->rollBack();
        json_response(['error' => 'Failed to join group. Please try again.'], 500);
    }
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
// Called by cron or admin to fail expired groups
// ─────────────────────────────────────────────────────────
function expire_failed_groups(): void {
    $pdo  = getPDO();
    $stmt = $pdo->prepare("
        UPDATE group_purchases
        SET status = 'failed'
        WHERE status = 'open'
          AND deadline < NOW()
          AND current_participants < target_participants
    ");
    $stmt->execute();
    json_response(['success' => true, 'expired' => $stmt->rowCount()]);
}
