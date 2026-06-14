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
if ($raw && strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
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
    case 'request_reset': handle_request_reset($input); break;
    case 'confirm_reset': handle_confirm_reset($input); break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
}

// ─────────────────────────────────────────────────────────
function handle_request_reset(array $input): void {
    $email = trim($input['email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => true]); return;
    }

    // Anti-spam: 5 reset requests per IP per hour
    require_once __DIR__ . '/../includes/rate_limit.php';
    if (!rate_check(rate_key_for_ip('reset_request'), 5, 3600)) {
        echo json_encode(['success' => true]); // silent — don't leak rate limit
        return;
    }
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT id, full_name FROM users WHERE email = ? AND is_active = 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user) {
        // Generate token, store hash
        $token = bin2hex(random_bytes(32));
        $hash  = hash('sha256', $token);
        $expires = date('Y-m-d H:i:s', time() + 3600); // 1 hour
        $pdo->prepare('INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)')
            ->execute([$user['id'], $hash, $expires]);

        // Email link via notify (best-effort)
        require_once __DIR__ . '/../includes/notifications.php';
        $link = APP_URL . '/pages/reset-password.php?token=' . urlencode($token);
        notify((int)$user['id'], 'password_reset',
            'Password reset requested',
            "Hi " . $user['full_name'] . ",\n\nWe received a request to reset your password. Click the link below within 1 hour:\n\n" . $link . "\n\nIf you didn't request this, you can ignore this message.",
            $link, ['token' => substr($token, 0, 8) . '…']);
    }
    // Always return success to prevent enumeration
    echo json_encode(['success' => true]);
}

// ─────────────────────────────────────────────────────────
function handle_confirm_reset(array $input): void {
    $token = trim($input['token'] ?? '');
    $new   = $input['new_password'] ?? '';
    if (!$token || strlen($new) < 8) {
        http_response_code(400);
        echo json_encode(['error' => 'Token and new password (min 8 chars) required']);
        return;
    }
    $hash = hash('sha256', $token);
    $pdo  = getPDO();
    $stmt = $pdo->prepare('SELECT id, user_id, expires_at, used_at FROM password_reset_tokens WHERE token_hash = ?');
    $stmt->execute([$hash]);
    $row = $stmt->fetch();

    if (!$row || $row['used_at'] !== null || strtotime($row['expires_at']) < time()) {
        http_response_code(400);
        echo json_encode(['error' => 'Token is invalid or has expired']);
        return;
    }

    $new_hash = password_hash($new, PASSWORD_BCRYPT);
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$new_hash, $row['user_id']]);
        $pdo->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE id = ?')->execute([$row['id']]);
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Failed to update password']);
        return;
    }

    require_once __DIR__ . '/../includes/notifications.php';
    notify((int)$row['user_id'], 'password_changed',
        'Password changed successfully',
        'Your SmartCart password was updated. If this wasn\'t you, contact support immediately.',
        APP_URL . '/pages/login.php');

    echo json_encode(['success' => true]);
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

    // Brute-force protection: 10 attempts per IP per 15 min
    require_once __DIR__ . '/../includes/rate_limit.php';
    if (!rate_check(rate_key_for_ip('login'), 10, 900)) {
        http_response_code(429);
        echo json_encode(['error' => 'Too many login attempts. Try again in 15 minutes.']);
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
    $lat      = isset($input['lat']) && $input['lat'] !== '' ? (float)$input['lat'] : null;
    $lng      = isset($input['lng']) && $input['lng'] !== '' ? (float)$input['lng'] : null;

    // Preferred categories (customer only, optional)
    $allowed_cats  = ['electronics','home','fashion','food','sports','beauty','toys','books','automotive','other'];
    $pref_cats_raw = $input['preferred_categories'] ?? [];
    if (!is_array($pref_cats_raw)) $pref_cats_raw = [];
    $pref_cats = array_values(array_filter($pref_cats_raw, fn($c) => in_array($c, $allowed_cats, true)));

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

    // onboarding_complete = 1 when customer selected at least one preference
    $onboarding = ($role === 'customer' && !empty($pref_cats)) ? 1 : 0;

    $pdo->beginTransaction();
    try {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare(
            'INSERT INTO users (email, password_hash, full_name, role, phone, city, lat, lng, preferred_categories, onboarding_complete)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$email, $hash, $name, $role, $phone ?: null, $city ?: null,
                        $lat, $lng, json_encode($pref_cats), $onboarding]);
        $user_id = (int)$pdo->lastInsertId();

        if ($role === 'business') {
            $stmt = $pdo->prepare(
                'INSERT INTO businesses (user_id, business_name, phone, category, city) VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([$user_id, $business_name, $business_phone ?: null, $business_category, $city ?: null]);
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

    // Welcome notification
    require_once __DIR__ . '/../includes/notifications.php';
    $welcome_link = $role === 'business' ? '/pages/business/dashboard.php' : '/pages/index.php';
    notify($user_id, 'welcome',
        'Welcome to SmartCart, ' . $name . '!',
        $role === 'business'
            ? 'Your business account is ready. Create your first deal to start selling.'
            : 'Discover group deals near you and save big with collective buying.',
        APP_URL . $welcome_link);

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
    $user_id = (int)$_SESSION['user_id'];
    $pdo     = getPDO();

    // Load current row so we never accidentally null a NOT NULL column
    $cur_stmt = $pdo->prepare('SELECT full_name, phone, city, address, lat, lng, preferred_categories FROM users WHERE id = ?');
    $cur_stmt->execute([$user_id]);
    $cur = $cur_stmt->fetch();
    if (!$cur) {
        http_response_code(404);
        echo json_encode(['error' => 'User not found']);
        return;
    }

    // Only update a field when it is actually present in the request; fall back to current DB value.
    $name    = (array_key_exists('full_name', $input) && trim($input['full_name']) !== '')
               ? trim($input['full_name'])
               : $cur['full_name'];                      // NOT NULL — keep current
    $phone   = array_key_exists('phone',   $input) ? (trim($input['phone'])   ?: null) : $cur['phone'];
    $city    = array_key_exists('city',    $input) ? (trim($input['city'])    ?: null) : $cur['city'];
    $address = array_key_exists('address', $input) ? (trim($input['address']) ?: null) : $cur['address'];
    $lat     = (array_key_exists('lat', $input) && $input['lat'] !== '')
               ? (float)$input['lat']
               : ($cur['lat'] !== null ? (float)$cur['lat'] : null);
    $lng     = (array_key_exists('lng', $input) && $input['lng'] !== '')
               ? (float)$input['lng']
               : ($cur['lng'] !== null ? (float)$cur['lng'] : null);

    if (array_key_exists('preferred_categories', $input)) {
        $cats_raw = $input['preferred_categories'];
        if (!is_array($cats_raw)) $cats_raw = [];
        $allowed = ['electronics','home','fashion','food','sports','beauty','toys','books','automotive','other'];
        $cats = array_values(array_filter($cats_raw, fn($c) => is_string($c) && in_array($c, $allowed, true)));
    } else {
        $cats = json_decode($cur['preferred_categories'] ?? '[]', true) ?: [];
    }

    try {
        $pdo->prepare(
            'UPDATE users SET full_name=?, phone=?, city=?, address=?, lat=?, lng=?, preferred_categories=?, onboarding_complete=1
             WHERE id=?'
        )->execute([$name, $phone, $city, $address, $lat, $lng, json_encode($cats), $user_id]);
    } catch (\PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to update profile. Please try again.']);
        return;
    }

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
