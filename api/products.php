<?php
// SmartCart — Products API
// GET  ?action=list&category=&city=&sort=newest|discount|ending_soon&page=1
// GET  ?action=get&id=
// POST ?action=create   (auth: business)
// POST ?action=update&id= (auth: business, own product)
// DELETE ?action=delete&id= (auth: business/admin)

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

session_start();
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

match(true) {
    $action === 'list'                         => list_products(),
    $action === 'get' && isset($_GET['id'])    => get_product((int)$_GET['id']),
    $action === 'create' && $method === 'POST' => create_product(),
    $action === 'update' && isset($_GET['id']) => update_product((int)$_GET['id']),
    $action === 'delete' && isset($_GET['id']) => delete_product((int)$_GET['id']),
    default => json_response(['error' => 'Unknown action'], 400),
};

// ─────────────────────────────────────────────────────────
function list_products(): void {
    $pdo      = getPDO();
    $category = $_GET['category'] ?? '';
    $city     = $_GET['city']     ?? '';
    $sort     = $_GET['sort']     ?? 'newest';
    $page     = max(1, (int)($_GET['page'] ?? 1));
    $per_page = 20;
    $offset   = ($page - 1) * $per_page;

    $where  = ['p.status = ?'];
    $params = ['active'];

    if ($category && $category !== 'all') {
        $where[]  = 'p.category = ?';
        $params[] = $category;
    }
    if ($city) {
        $where[]  = 'p.city LIKE ?';
        $params[] = "%$city%";
    }

    $order = match($sort) {
        'discount'    => 'disc DESC',
        'ending_soon' => 'earliest_deadline ASC',
        default       => 'p.created_at DESC',
    };

    $sql = "
        SELECT p.*,
               b.business_name,
               b.city AS business_city,
               ROUND((p.price_ils - p.group_price_ils) / p.price_ils * 100) AS disc,
               (SELECT COUNT(*) FROM group_purchases gp WHERE gp.product_id = p.id AND gp.status = 'open') AS active_groups,
               (SELECT MIN(gp.deadline) FROM group_purchases gp WHERE gp.product_id = p.id AND gp.status = 'open') AS earliest_deadline
        FROM products p
        JOIN businesses b ON b.id = p.business_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY $order
        LIMIT $per_page OFFSET $offset
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll();

    // Count
    $count_sql = "SELECT COUNT(*) FROM products p WHERE " . implode(' AND ', $where);
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($params);
    $total = (int)$count_stmt->fetchColumn();

    foreach ($products as &$p) {
        $p['price_ils']       = (float)$p['price_ils'];
        $p['group_price_ils'] = (float)$p['group_price_ils'];
        $p['disc']            = (int)$p['disc'];
        $p['active_groups']   = (int)$p['active_groups'];
    }

    json_response(['products' => $products, 'total' => $total, 'page' => $page, 'per_page' => $per_page]);
}

// ─────────────────────────────────────────────────────────
function get_product(int $id): void {
    $pdo  = getPDO();
    $stmt = $pdo->prepare("
        SELECT p.*, b.business_name, b.city AS business_city, b.phone AS business_phone,
               b.lat AS business_lat, b.lng AS business_lng, b.logo_url,
               ROUND((p.price_ils - p.group_price_ils) / p.price_ils * 100) AS disc
        FROM products p
        JOIN businesses b ON b.id = p.business_id
        WHERE p.id = ? AND p.status = 'active'
    ");
    $stmt->execute([$id]);
    $product = $stmt->fetch();
    if (!$product) {
        json_response(['error' => 'Product not found'], 404);
    }
    $product['price_ils']       = (float)$product['price_ils'];
    $product['group_price_ils'] = (float)$product['group_price_ils'];
    $product['disc']            = (int)$product['disc'];

    // Active groups for this product
    $gstmt = $pdo->prepare("
        SELECT gp.*, u.full_name AS creator_name,
               ROUND(gp.current_participants / gp.target_participants * 100) AS fill_pct
        FROM group_purchases gp
        JOIN users u ON u.id = gp.creator_id
        WHERE gp.product_id = ? AND gp.status = 'open'
        ORDER BY gp.created_at DESC
    ");
    $gstmt->execute([$id]);
    $groups = $gstmt->fetchAll();

    json_response(['product' => $product, 'groups' => $groups]);
}

// ─────────────────────────────────────────────────────────
function create_product(): void {
    $user_id = require_auth();
    $role = $_SESSION['user_role'] ?? '';
    if ($role !== 'business' && $role !== 'admin') {
        json_response(['error' => 'Only business users can create products'], 403);
    }

    $body = array_merge($_POST, get_json_body());

    $name     = trim($body['name']        ?? '');
    $desc     = trim($body['description'] ?? '');
    $price    = (float)($body['price_ils']       ?? 0);
    $gprice   = (float)($body['group_price_ils'] ?? 0);
    $category = trim($body['category']    ?? 'other');
    $min_p    = max(2, (int)($body['min_participants'] ?? 2));
    $image    = trim($body['image_url']   ?? '');
    $city     = trim($body['city']        ?? '');
    $lat      = isset($body['lat']) && $body['lat'] !== '' ? (float)$body['lat'] : null;
    $lng      = isset($body['lng']) && $body['lng'] !== '' ? (float)$body['lng'] : null;

    if (!$name || $price <= 0 || $gprice <= 0) {
        json_response(['error' => 'Name, price, and group price are required'], 400);
    }
    if ($gprice >= $price) {
        json_response(['error' => 'Group price must be less than original price'], 400);
    }

    // Get business_id for this user
    $pdo  = getPDO();
    $stmt = $pdo->prepare('SELECT id FROM businesses WHERE user_id = ?');
    $stmt->execute([$user_id]);
    $biz = $stmt->fetch();
    if (!$biz) {
        json_response(['error' => 'Business profile not found'], 404);
    }
    $business_id = $biz['id'];

    // Geocode city if lat/lng not provided
    if ((!$lat || !$lng) && $city) {
        $coords = geocode_city($city);
        if ($coords) { $lat = $coords['lat']; $lng = $coords['lng']; }
    }

    $stmt = $pdo->prepare("
        INSERT INTO products (business_id, name, description, price_ils, group_price_ils, category, min_participants, image_url, city, lat, lng)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$business_id, $name, $desc, $price, $gprice, $category, $min_p, $image ?: null, $city ?: null, $lat, $lng]);

    json_response(['success' => true, 'product_id' => (int)$pdo->lastInsertId()], 201);
}

// ─────────────────────────────────────────────────────────
function update_product(int $id): void {
    $user_id = require_auth();
    $pdo     = getPDO();
    $body    = array_merge($_POST, get_json_body());

    // Verify ownership
    $stmt = $pdo->prepare("SELECT p.id, b.user_id FROM products p JOIN businesses b ON b.id = p.business_id WHERE p.id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) json_response(['error' => 'Product not found'], 404);
    if ($row['user_id'] !== $user_id && ($_SESSION['user_role'] ?? '') !== 'admin') {
        json_response(['error' => 'Not authorized'], 403);
    }

    $name   = trim($body['name']        ?? '');
    $desc   = trim($body['description'] ?? '');
    $price  = (float)($body['price_ils']       ?? 0);
    $gprice = (float)($body['group_price_ils'] ?? 0);
    $cat    = trim($body['category']    ?? 'other');
    $min_p  = max(2, (int)($body['min_participants'] ?? 2));
    $image  = trim($body['image_url']   ?? '');
    $city   = trim($body['city']        ?? '');
    $lat    = isset($body['lat']) && $body['lat'] !== '' ? (float)$body['lat'] : null;
    $lng    = isset($body['lng']) && $body['lng'] !== '' ? (float)$body['lng'] : null;
    $status = in_array($body['status'] ?? '', ['active','inactive']) ? $body['status'] : 'active';

    $stmt = $pdo->prepare("
        UPDATE products SET name=?, description=?, price_ils=?, group_price_ils=?, category=?,
            min_participants=?, image_url=?, city=?, lat=?, lng=?, status=? WHERE id=?
    ");
    $stmt->execute([$name, $desc, $price, $gprice, $cat, $min_p, $image ?: null, $city ?: null, $lat, $lng, $status, $id]);

    json_response(['success' => true]);
}

// ─────────────────────────────────────────────────────────
function delete_product(int $id): void {
    $user_id = require_auth();
    $pdo     = getPDO();

    $stmt = $pdo->prepare("SELECT p.id, b.user_id FROM products p JOIN businesses b ON b.id = p.business_id WHERE p.id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) json_response(['error' => 'Product not found'], 404);
    if ($row['user_id'] !== $user_id && ($_SESSION['user_role'] ?? '') !== 'admin') {
        json_response(['error' => 'Not authorized'], 403);
    }

    // Soft delete
    $pdo->prepare("UPDATE products SET status = 'inactive' WHERE id = ?")->execute([$id]);
    json_response(['success' => true]);
}
