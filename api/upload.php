<?php
// SmartCart — File upload API
// POST ?action=product_image — multipart file upload; auth: business/admin only

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

session_start();
header('Content-Type: application/json; charset=utf-8');

$user_id = require_auth();
$role = $_SESSION['user_role'] ?? '';
if ($role !== 'business' && $role !== 'admin') {
    json_response(['error' => 'Only business users can upload'], 403);
}

$action = $_GET['action'] ?? '';
if ($action !== 'product_image' && $action !== 'logo') {
    json_response(['error' => 'Unknown action'], 400);
}

if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    json_response(['error' => 'No file uploaded or upload error'], 400);
}

$f = $_FILES['file'];
if ($f['size'] > 5 * 1024 * 1024) {
    json_response(['error' => 'File too large (max 5MB)'], 400);
}

$allowed_exts  = ['jpg','jpeg','png','webp'];
$ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
if (!in_array($ext, $allowed_exts, true)) {
    json_response(['error' => 'Allowed formats: jpg, png, webp'], 400);
}

// Verify it's actually an image (not a PHP file with a .jpg extension)
$info = @getimagesize($f['tmp_name']);
if (!$info || empty($info['mime'])) {
    json_response(['error' => 'File is not a valid image'], 400);
}
$allowed_mimes = ['image/jpeg', 'image/png', 'image/webp'];
if (!in_array($info['mime'], $allowed_mimes, true)) {
    json_response(['error' => 'Invalid image type'], 400);
}

// Move into target directory
$subdir = $action === 'logo' ? 'logos' : 'products';
$target_dir = __DIR__ . '/../uploads/' . $subdir;
if (!is_dir($target_dir)) @mkdir($target_dir, 0755, true);

$name = bin2hex(random_bytes(12)) . '.' . $ext;
$path = $target_dir . '/' . $name;

if (!move_uploaded_file($f['tmp_name'], $path)) {
    json_response(['error' => 'Failed to save file'], 500);
}

// Return the relative path only — prevents double APP_URL if this URL gets
// saved to the DB and later displayed via img_url() which prepends APP_URL.
$relative = '/uploads/' . $subdir . '/' . $name;
json_response([
    'success'     => true,
    'url'         => $relative,                  // relative path — store this in DB
    'preview_url' => APP_URL . $relative,        // absolute URL — for <img src> preview only
    'name'        => $name,
    'width'       => $info[0] ?? null,
    'height'      => $info[1] ?? null,
]);
