<?php
// SmartCart — Shared Footer
?>
</main>

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
</script>

</body>
</html>
