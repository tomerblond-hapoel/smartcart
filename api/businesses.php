<?php
// SmartCart — Businesses API
// POST ?action=update  — update business profile (auth: business owner)
// GET  ?action=get&id= — public business profile

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

session_start();
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

if ($action === 'get' && isset($_GET['id'])) {
    get_business((int)$_GET['id']);
} elseif ($action === 'update' && $method === 'POST') {
    update_business();
} else {
    json_response(['error' => 'Unknown action'], 400);
}

// ─────────────────────────────────────────────────────────
function get_business(int $id): void {
    $pdo  = getPDO();
    $stmt = $pdo->prepare("
        SELECT b.id, b.business_name, b.description, b.category, b.city, b.address,
               b.phone, b.logo_url, b.lat, b.lng, b.created_at
        FROM businesses b
        WHERE b.id = ?
    ");
    $stmt->execute([$id]);
    $biz = $stmt->fetch();
    if (!$biz) json_response(['error' => 'Business not found'], 404);
    json_response(['business' => $biz]);
}

// ─────────────────────────────────────────────────────────
function update_business(): void {
    $user_id = require_auth();
    $role    = $_SESSION['user_role'] ?? '';
    if ($role !== 'business' && $role !== 'admin') {
        json_response(['error' => 'Only business users can update profiles'], 403);
    }

    $pdo  = getPDO();
    $stmt = $pdo->prepare('SELECT id FROM businesses WHERE user_id = ?');
    $stmt->execute([$user_id]);
    $biz = $stmt->fetch();
    if (!$biz) json_response(['error' => 'Business profile not found'], 404);

    $body = array_merge($_POST, get_json_body());

    $name    = trim($body['business_name'] ?? '');
    $desc    = trim($body['description']   ?? '');
    $phone   = trim($body['phone']         ?? '');
    $city    = trim($body['city']          ?? '');
    $address = trim($body['address']       ?? '');
    $logo    = trim($body['logo_url']      ?? '');
    $lat     = isset($body['lat']) && $body['lat'] !== '' ? (float)$body['lat'] : null;
    $lng     = isset($body['lng']) && $body['lng'] !== '' ? (float)$body['lng'] : null;

    if (!$name) json_response(['error' => 'Business name is required'], 400);

    // Geocode: prefer full address (street-level), fall back to city.
    if ((!$lat || !$lng)) {
        if ($address) {
            $coords = geocode_address($address . ($city ? ', ' . $city : ''));
            if ($coords) { $lat = $coords['lat']; $lng = $coords['lng']; }
        }
        if ((!$lat || !$lng) && $city) {
            $coords = geocode_city($city);
            if ($coords) { $lat = $coords['lat']; $lng = $coords['lng']; }
        }
    }

    $pdo->prepare("
        UPDATE businesses
        SET business_name=?, description=?, phone=?, city=?, address=?, logo_url=?, lat=?, lng=?
        WHERE user_id=?
    ")->execute([$name, $desc, $phone, $city ?: null, $address ?: null, $logo ?: null, $lat, $lng, $user_id]);

    json_response(['success' => true]);
}
