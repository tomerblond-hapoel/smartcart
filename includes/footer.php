<?php
// SmartCart — Shared Footer
?>
</main>

<!-- Bottom Navigation Bar (mobile-first, role-aware) -->
<?php
$is_business_page = isset($user_role) && $user_role === 'business';
$on_business_path = strpos($_SERVER['PHP_SELF'], '/pages/business/') !== false;
?>
<?php if ($is_business_page || $on_business_path): ?>
<nav class="bottom-nav" id="bottom-nav">
    <a href="<?= APP_URL ?>/pages/business/dashboard.php" class="bottom-nav-item <?= ($current_page === 'dashboard.php' && $on_business_path) ? 'active' : '' ?>">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
        <span><?= $t['biz_nav_dashboard'] ?? 'Dashboard' ?></span>
    </a>
    <a href="<?= APP_URL ?>/pages/business/dashboard.php?new=1" class="bottom-nav-item">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
        <span><?= $t['biz_nav_new_deal'] ?? 'New Deal' ?></span>
    </a>
    <a href="<?= APP_URL ?>/pages/business/dashboard.php#orders" class="bottom-nav-item">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
        <span><?= $t['biz_nav_orders'] ?? 'Orders' ?></span>
    </a>
    <a href="<?= APP_URL ?>/pages/business/profile.php" class="bottom-nav-item <?= ($current_page === 'profile.php' && $on_business_path) ? 'active' : '' ?>">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        <span><?= $t['biz_nav_profile'] ?? 'Profile' ?></span>
    </a>
</nav>
<?php else: ?>
<nav class="bottom-nav" id="bottom-nav">
    <a href="<?= APP_URL ?>/pages/index.php" class="bottom-nav-item <?= ($current_page === 'index.php') ? 'active' : '' ?>">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        <span>Home</span>
    </a>
    <a href="<?= APP_URL ?>/pages/browse.php" class="bottom-nav-item <?= ($current_page === 'browse.php') ? 'active' : '' ?>">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <span>Search</span>
    </a>
    <a href="<?= APP_URL ?>/pages/my-groups.php" class="bottom-nav-item <?= ($current_page === 'my-groups.php') ? 'active' : '' ?>">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        <span>Groups</span>
    </a>
    <a href="<?= APP_URL ?>/pages/profile.php" class="bottom-nav-item <?= ($current_page === 'profile.php' && !$on_business_path) ? 'active' : '' ?>">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        <span>Profile</span>
    </a>
</nav>
<?php endif; ?>

<footer class="site-footer">
    <div class="footer-container">
        <div class="footer-brand">
            <strong>SmartCart</strong>
            <span><?= $t['home_hero_subtitle'] ?? 'Save More Together' ?></span>
        </div>
        <div class="footer-info">
            <span>Team 64 — Gaya Kishon, Rotem Maor, Noa Tider, Tomer Blond</span>
            <span>Academic Accelerator Project 20262X64 | Mentor: Mor Zaluf</span>
        </div>
    </div>
</footer>

<script src="<?= APP_URL ?>/assets/js/app.js"></script>

<script>
// Logout via fetch (prevent redirect loop)
const logoutBtn = document.getElementById('logout-btn');
if (logoutBtn) {
    logoutBtn.addEventListener('click', function(e) {
        e.preventDefault();
        fetch('<?= APP_URL ?>/api/auth.php?action=logout', { method: 'POST' })
            .then(() => { window.location.href = '<?= APP_URL ?>/pages/index.php'; });
    });
}

// Mobile nav toggle
const navToggle = document.getElementById('navbar-toggle');
const navLinks  = document.querySelector('.navbar-links');
if (navToggle && navLinks) {
    navToggle.addEventListener('click', () => navLinks.classList.toggle('open'));
}

// Auto-dismiss flash messages after 5 seconds
document.querySelectorAll('.flash').forEach(el => {
    setTimeout(() => el.remove(), 5000);
});

// Notification badge polling (every 30s for logged-in users)
<?php if (!empty($_SESSION['user_id'])): ?>
(function() {
    const badge = document.getElementById('notif-badge');
    if (!badge) return;
    async function refresh() {
        try {
            const res = await fetch('<?= APP_URL ?>/api/notifications.php?action=unread_count', { credentials: 'same-origin' });
            if (!res.ok) return;
            const data = await res.json();
            const n = parseInt(data.count, 10) || 0;
            if (n > 0) {
                badge.textContent = n > 99 ? '99+' : String(n);
                badge.style.display = 'inline-block';
            } else {
                badge.style.display = 'none';
            }
        } catch (e) {}
    }
    refresh();
    setInterval(refresh, 30000);
})();
<?php endif; ?>
</script>

</body>
</html>
