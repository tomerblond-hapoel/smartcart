<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../includes/auth_check.php';

if (is_logged_in()) { header('Location: ' . APP_URL . '/pages/index.php'); exit; }
$page_title = 'Forgot Password';
include __DIR__ . '/../includes/header.php';
?>

<div class="container container-sm" style="padding-top:60px;padding-bottom:60px;">
    <div class="card" style="max-width:420px;margin:0 auto;">
        <div class="card-body" style="padding:36px;">
            <div style="text-align:center;margin-bottom:24px;">
                <div style="font-size:42px;margin-bottom:8px;">🔑</div>
                <h1 style="font-size:22px;font-weight:700;color:var(--gray-900);">Forgot password?</h1>
                <p style="color:var(--gray-500);font-size:14px;margin-top:6px;">Enter your email and we'll send you a reset link.</p>
            </div>

            <div id="flash" style="display:none;margin-bottom:16px;"></div>

            <form id="reset-form">
                <div class="form-group">
                    <label class="form-label" for="email">Email</label>
                    <input type="email" id="email" name="email" class="form-control" required autocomplete="email" placeholder="your@email.com">
                </div>
                <button type="submit" class="btn btn-primary btn-full btn-lg">Send reset link</button>
            </form>

            <p style="text-align:center;margin-top:20px;font-size:14px;color:var(--gray-500);">
                Remembered? <a href="<?= APP_URL ?>/pages/login.php" style="font-weight:600;">Back to login</a>
            </p>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<script>
document.getElementById('reset-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = this.querySelector('[type=submit]');
    const flash = document.getElementById('flash');
    btn.disabled = true;
    btn.textContent = 'Sending...';
    try {
        await fetch('<?= APP_URL ?>/api/auth.php?action=request_reset', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ email: this.email.value }),
        });
        flash.className = 'flash flash-success';
        flash.style.cssText = 'display:flex;border-radius:8px;';
        flash.textContent = 'If an account with that email exists, a reset link has been sent.';
        this.email.value = '';
    } catch (e) {
        flash.className = 'flash flash-error';
        flash.style.cssText = 'display:flex;border-radius:8px;';
        flash.textContent = 'Something went wrong. Please try again.';
    }
    btn.disabled = false;
    btn.textContent = 'Send reset link';
});
</script>
