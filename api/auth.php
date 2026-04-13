<?php
// SmartCart — Authentication API
// Handles: login, register, logout, get current user, update profile, change password
// All responses are JSON.

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

session_start();
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// Parse JSON body if sent as application/json
$body = [];
$raw = file_get_contents('php://input');
if ($raw && str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
    $body = json_decode($raw, true) ?? [];
}
$input = array_merge($_POST, $body);

switch ($action) {
    case 'login':    handle_login($input);    break;
    case 'register': handle_register($input); break;
    case 'logout':   handle_logout();         break;
    case 'me':       handle_me();             break;
    case 'update_profile': handle_update_profile($input); break;
    case 'change_password': handle_change_password($input); break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
}

// ─────────────────────────────────────────────────────────
function handle_login(array $input): void {
    $email    = trim($input['email']    ?? '');
    $password = trim($input['password'] ?? '');

    if (!$email || !$password) {
        http_response_code(400);
        echo json_encode(['error' => 'Email and password required']);
        return;
    }

    $pdo  = getPDO();
    $stmt = $pdo->prepare('SELECT id, email, password_hash, full_name, role, is_active FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid email or password']);
        return;
    }
    if (!$user['is_active']) {
        http_response_code(403);
        echo json_encode(['error' => 'Account has been disabled']);
        return;
    }

    $_SESSION['user_id']   = $user['id'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['user_name'] = $user['full_name'];

    echo json_encode([
        'success' => true,
        'user' => [
            'id'        => $user['id'],
            'email'     => $user['email'],
            'full_name' => $user['full_name'],
            'role'      => $user['role'],
        ]
    ]);
}

// ─────────────────────────────────────────────────────────
function handle_register(array $input): void {
    $email    = trim($input['email']    ?? '');
    $password = trim($input['password'] ?? '');
    $name     = trim($input['full_name'] ?? '');
    $role     = trim($input['role']     ?? 'customer');
    $city     = trim($input['city']     ?? '');
    $phone    = trim($input['phone']    ?? '');

    // Business-specific fields
    $business_name     = trim($input['business_name']     ?? '');
    $business_category = trim($input['business_category'] ?? 'other');
    $business_phone    = trim($input['business_phone']    ?? $phone);

    // Validation
    if (!$email || !$password || !$name) {
        http_response_code(400);
        echo json_encode(['error' => 'Name, email, and password are required']);
        return;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid email address']);
        return;
    }
    if (strlen($password) < 8) {
        http_response_code(400);
        echo json_encode(['error' => 'Password must be at least 8 characters']);
        return;
    }
    if (!in_array($role, ['customer', 'business'])) {
        $role = 'customer';
    }
    if ($role === 'business' && !$business_name) {
        http_response_code(400);
        echo json_encode(['error' => 'Business name is required for business accounts']);
        return;
    }

    $pdo = getPDO();

    // Check email uniqueness
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => 'An account with this email already exists']);
        return;
    }

    $pdo->beginTransaction();
    try {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare(
            'INSERT INTO users (email, password_hash, full_name, role, phone, city) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$email, $hash, $name, $role, $phone, $city]);
        $user_id = (int)$pdo->lastInsertId();

        if ($role === 'business') {
            $stmt = $pdo->prepare(
                'INSERT INTO businesses (user_id, business_name, phone, category, city) VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([$user_id, $business_name, $business_phone, $business_category, $city]);
        }

        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Registration failed. Please try again.']);
        return;
    }

    // Auto-login after registration
    $_SESSION['user_id']   = $user_id;
    $_SESSION['user_role'] = $role;
    $_SESSION['user_name'] = $name;

    echo json_encode([
        'success' => true,
        'user' => ['id' => $user_id, 'email' => $email, 'full_name' => $name, 'role' => $role]
    ]);
}

// ─────────────────────────────────────────────────────────
function handle_logout(): void {
    session_destroy();
    echo json_encode(['success' => true]);
}

// ─────────────────────────────────────────────────────────
function handle_me(): void {
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Not authenticated']);
        return;
    }
    $pdo  = getPDO();
    $stmt = $pdo->prepare(
        'SELECT id, email, full_name, role, phone, address, city, lat, lng, preferred_categories, onboarding_complete, created_at
         FROM users WHERE id = ?'
    );
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user) {
        http_response_code(404);
        echo json_encode(['error' => 'User not found']);
        return;
    }
    $user['preferred_categories'] = json_decode($user['preferred_categories'] ?? '[]', true);
    echo json_encode($user);
}

// ─────────────────────────────────────────────────────────
function handle_update_profile(array $input): void {
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Not authenticated']);
        return;
    }
    $user_id = $_SESSION['user_id'];
    $name    = trim($input['full_name'] ?? '');
    $phone   = trim($input['phone']    ?? '');
    $city    = trim($input['city']     ?? '');
    $address = trim($input['address']  ?? '');
    $lat     = isset($input['lat'])  ? (float)$input['lat']  : null;
    $lng     = isset($input['lng'])  ? (float)$input['lng']  : null;
    $cats    = $input['preferred_categories'] ?? [];

    if (is_array($cats)) {
        $allowed = ['electronics','home','fashion','food','sports','beauty','toys','books','automotive','other'];
        $cats = array_values(array_filter($cats, fn($c) => in_array($c, $allowed)));
    }

    $pdo  = getPDO();
    $stmt = $pdo->prepare(
        'UPDATE users SET full_name=?, phone=?, city=?, address=?, lat=?, lng=?, preferred_categories=?, onboarding_complete=1
         WHERE id=?'
    );
    $stmt->execute([$name ?: null, $phone ?: null, $city ?: null, $address ?: null, $lat, $lng, json_encode($cats), $user_id]);

    $_SESSION['user_name'] = $name;
    echo json_encode(['success' => true]);
}

// ─────────────────────────────────────────────────────────
function handle_change_password(array $input): void {
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Not authenticated']);
        return;
    }
    $current = $input['current_password'] ?? '';
    $new     = $input['new_password']     ?? '';
    $confirm = $input['confirm_password'] ?? '';

    if (!$current || !$new || !$confirm) {
        http_response_code(400);
        echo json_encode(['error' => 'All password fields are required']);
        return;
    }
    if ($new !== $confirm) {
        http_response_code(400);
        echo json_encode(['error' => 'New passwords do not match']);
        return;
    }
    if (strlen($new) < 8) {
        http_response_code(400);
        echo json_encode(['error' => 'Password must be at least 8 characters']);
        return;
    }

    $pdo  = getPDO();
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($current, $row['password_hash'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Current password is incorrect']);
        return;
    }

    $hash = password_hash($new, PASSWORD_BCRYPT);
    $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([$hash, $_SESSION['user_id']]);
    echo json_encode(['success' => true]);
}
