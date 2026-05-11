<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../includes/auth_check.php';

if (is_logged_in()) { header('Location: ' . APP_URL . '/pages/index.php'); exit; }

$token = trim($_GET['token'] ?? '');
$page_title = 'Reset Password';
include __DIR__ . '/../includes/header.php';
?>

<div class="container container-sm" style="padding-top:60px;padding-bottom:60px;">
    <div class="card" style="max-width:420px;margin:0 auto;">
        <div class="card-body" style="padding:36px;">
            <div style="text-align:center;margin-bottom:24px;">
                <div style="font-size:42px;margin-bottom:8px;">🔒</div>
                <h1 style="font-size:22px;font-weight:700;color:var(--gray-900);">Set a new password</h1>
            </div>

            <div id="flash" style="display:none;margin-bottom:16px;"></div>

            <?php if (!$token): ?>
            <div class="flash flash-error" style="border-radius:8px;display:flex;">
                Missing reset token. <a href="<?= APP_URL ?>/pages/forgot-password.php" style="font-weight:600;margin-left:6px;">Request a new link</a>
            </div>
            <?php else: ?>
            <form id="reset-form">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                <div class="form-group">
                    <label class="form-label">New password</label>
                    <input type="password" name="new_password" class="form-control" required minlength="8" placeholder="Min. 8 characters">
                </div>
                <div class="form-group">
                    <label class="form-label">Confirm password</label>
                    <input type="password" name="confirm_password" class="form-control" required minlength="8">
                </div>
                <button type="submit" class="btn btn-primary btn-full btn-lg">Update password</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<script>
const form = document.getElementById('reset-form');
if (form) form.addEventListener('submit', async function(e) {
    e.preventDefault();
    const flash = document.getElementById('flash');
    const btn   = this.querySelector('[type=submit]');
    if (this.new_password.value !== this.confirm_password.value) {
        flash.className = 'flash flash-error';
        flash.style.cssText = 'display:flex;border-radius:8px;';
        flash.textContent = 'Passwords do not match.';
        return;
    }
    btn.disabled = true;
    btn.textContent = 'Updating...';
    try {
        const res = await fetch('<?= APP_URL ?>/api/auth.php?action=confirm_reset', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ token: this.token.value, new_password: this.new_password.value }),
        });
        const data = await res.json();
        if (res.ok && data.success) {
            flash.className = 'flash flash-success';
            flash.style.cssText = 'display:flex;border-radius:8px;';
            flash.innerHTML = 'Password updated! <a href="<?= APP_URL ?>/pages/login.php" style="font-weight:600;margin-left:6px;">Login →</a>';
            form.style.display = 'none';
        } else {
            flash.className = 'flash flash-error';
            flash.style.cssText = 'display:flex;border-radius:8px;';
            flash.textContent = data.error || 'Reset failed.';
        }
    } catch (e) {
        flash.className = 'flash flash-error';
        flash.style.cssText = 'display:flex;border-radius:8px;';
        flash.textContent = 'Something went wrong.';
    }
    btn.disabled = false;
    btn.textContent = 'Update password';
});
</script>
