<?php
// SmartCart — Shared Header
// Usage: include this at the top of every page.
// Before including, set $page_title (optional) and ensure lang.php and auth_check.php are loaded.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/config.php';

// Get flash messages
$flash_success = $_SESSION['flash_success'] ?? '';
$flash_error   = $_SESSION['flash_error']   ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$current_page = basename($_SERVER['PHP_SELF']);
$user_role    = $_SESSION['user_role'] ?? null;
$user_name    = $_SESSION['user_name'] ?? null;
$is_logged_in = !empty($_SESSION['user_id']);

$title = ($page_title ?? 'SmartCart') . ' — ' . APP_NAME;
?>
<!DOCTYPE html>
<html lang="<?= $lang_current ?? 'en' ?>" dir="<?= $dir ?? 'ltr' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="app-url" content="<?= APP_URL ?>">
    <title><?= htmlspecialchars($title) ?></title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
    <?php if (!empty($needs_map)): ?>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="<?= APP_URL ?>/assets/js/maps.js"></script>
    <?php endif; ?>
</head>
<body class="<?= $lang_class ?? '' ?>">

<nav class="navbar">
    <div class="navbar-container">
        <a href="<?= APP_URL ?>/pages/index.php" class="navbar-brand">
            <div class="logo-icon">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5">
                    <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
                    <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
                    <path d="M9 9h6M12 6v6"/>
                </svg>
            </div>
            <span class="brand-name">Smart<span>Cart</span></span>
        </a>

        <div class="navbar-links">
            <a href="<?= APP_URL ?>/pages/browse.php" class="<?= $current_page === 'browse.php' ? 'active' : '' ?>">
                <?= $t['nav_browse'] ?>
            </a>
            <a href="<?= APP_URL ?>/pages/agent.php" class="<?= $current_page === 'agent.php' ? 'active' : '' ?>">
                <?= $t['nav_agent'] ?>
            </a>
            <?php if ($is_logged_in): ?>
            <a href="<?= APP_URL ?>/pages/my-groups.php" class="<?= $current_page === 'my-groups.php' ? 'active' : '' ?>">
                <?= $t['nav_my_groups'] ?>
            </a>
            <a href="<?= APP_URL ?>/pages/my-orders.php" class="<?= $current_page === 'my-orders.php' ? 'active' : '' ?>">
                <?= $t['nav_my_orders'] ?>
            </a>
            <?php if ($user_role === 'business'): ?>
            <a href="<?= APP_URL ?>/pages/business/dashboard.php" class="<?= $current_page === 'dashboard.php' ? 'active' : '' ?>">
                <?= $t['nav_business'] ?>
            </a>
            <?php endif; ?>
            <?php if ($user_role === 'admin'): ?>
            <a href="<?= APP_URL ?>/pages/admin/dashboard.php">
                <?= $t['nav_admin'] ?>
            </a>
            <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="navbar-right">
            <?php if ($is_logged_in): ?>
                <a href="<?= APP_URL ?>/pages/profile.php" class="btn btn-ghost btn-sm">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
                    </svg>
                    <?= htmlspecialchars($user_name ?? $t['nav_profile']) ?>
                </a>
                <a href="<?= APP_URL ?>/api/auth.php?action=logout" class="btn btn-ghost btn-sm" id="logout-btn">
                    <?= $t['nav_logout'] ?>
                </a>
            <?php else: ?>
                <a href="<?= APP_URL ?>/pages/login.php" class="btn btn-ghost btn-sm"><?= $t['nav_login'] ?></a>
                <a href="<?= APP_URL ?>/pages/register.php" class="btn btn-primary btn-sm"><?= $t['nav_register'] ?></a>
            <?php endif; ?>
        </div>

        <!-- Mobile hamburger -->
        <button class="navbar-toggle" id="navbar-toggle" aria-label="Toggle menu">
            <span></span><span></span><span></span>
        </button>
    </div>
</nav>

<!-- Flash Messages -->
<?php if ($flash_success): ?>
<div class="flash flash-success">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
    <?= htmlspecialchars($flash_success) ?>
    <button class="flash-close" onclick="this.parentElement.remove()">×</button>
</div>
<?php endif; ?>
<?php if ($flash_error): ?>
<div class="flash flash-error">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <?= htmlspecialchars($flash_error) ?>
    <button class="flash-close" onclick="this.parentElement.remove()">×</button>
</div>
<?php endif; ?>

<main class="main-content">
