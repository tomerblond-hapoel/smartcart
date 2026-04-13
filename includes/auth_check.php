<?php
// SmartCart — Auth Guard Helpers
// Include this in any page that requires authentication.
// Usage:
//   require_once __DIR__ . '/../includes/auth_check.php';
//   require_login();              // Redirects to login if not authenticated
//   require_role('business');     // Redirects if user doesn't have the required role
//   $user = get_current_user_data(); // Returns user row from DB or null

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function require_login(string $redirect = '/pages/login.php'): void {
    if (empty($_SESSION['user_id'])) {
        $_SESSION['flash_error'] = 'Please log in to continue.';
        header('Location: ' . APP_URL . $redirect);
        exit;
    }
}

function require_role(string $role, string $redirect = '/pages/index.php'): void {
    require_login();
    if (($_SESSION['user_role'] ?? '') !== $role) {
        $_SESSION['flash_error'] = 'Access denied.';
        header('Location: ' . APP_URL . $redirect);
        exit;
    }
}

function require_admin(string $redirect = '/pages/index.php'): void {
    require_login();
    if (($_SESSION['user_role'] ?? '') !== 'admin') {
        $_SESSION['flash_error'] = 'Access denied.';
        header('Location: ' . APP_URL . $redirect);
        exit;
    }
}

function is_logged_in(): bool {
    return !empty($_SESSION['user_id']);
}

function current_user_id(): ?int {
    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

function current_user_role(): ?string {
    return $_SESSION['user_role'] ?? null;
}

function get_current_user_data(): ?array {
    if (!is_logged_in()) return null;
    require_once __DIR__ . '/../config/db.php';
    $pdo  = getPDO();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if ($user) {
        $user['preferred_categories'] = json_decode($user['preferred_categories'] ?? '[]', true);
    }
    return $user ?: null;
}
