<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../includes/auth_check.php';

// Redirect if already logged in
if (is_logged_in()) {
    header('Location: ' . APP_URL . '/pages/index.php');
    exit;
}

$page_title = $t['login_title'];
$error = '';
$email_val = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');
    $email_val = htmlspecialchars($email);

    if ($email && $password) {
        $ch = curl_init(APP_URL . '/api/auth.php?action=login');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['email' => $email, 'password' => $password]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_COOKIE         => 'PHPSESSID=' . session_id(),
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200) {
            // Re-parse response to get user data and set session
            require_once __DIR__ . '/../config/db.php';
            $pdo  = getPDO();
            $stmt = $pdo->prepare('SELECT id, full_name, role FROM users WHERE email = ?');
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            if ($user) {
                $_SESSION['user_id']   = $user['id'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['user_name'] = $user['full_name'];
            }
            $_SESSION['flash_success'] = 'Welcome back, ' . ($user['full_name'] ?? '') . '!';
            header('Location: ' . APP_URL . '/pages/index.php');
            exit;
        } else {
            $data  = json_decode($resp, true);
            $error = $data['error'] ?? 'Login failed. Please try again.';
        }
    } else {
        $error = 'Please enter your email and password.';
    }
}

// Simpler approach: use the API handler directly
// Reset and use direct DB approach for SSR login form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    // already handled above
}

include __DIR__ . '/../includes/header.php';
?>
<div class="container container-sm" style="padding-top: 60px; padding-bottom: 60px;">
    <div class="card" style="max-width: 420px; margin: 0 auto;">
        <div class="card-body" style="padding: 36px;">
            <div style="text-align:center; margin-bottom: 28px;">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="1.5" style="margin: 0 auto 12px;">
                    <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
                    <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
                </svg>
                <h1 style="font-size:22px; font-weight:700; color:var(--gray-900);"><?= $t['login_title'] ?></h1>
                <p style="color:var(--gray-500); margin-top:6px; font-size:14px;">Sign in to your SmartCart account</p>
            </div>

            <?php if ($error): ?>
            <div class="flash flash-error" style="border-radius:8px; margin-bottom:20px;">
                <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>

            <form method="POST" id="login-form">
                <div class="form-group">
                    <label class="form-label" for="email"><?= $t['login_email'] ?></label>
                    <input type="email" id="email" name="email" class="form-control"
                           value="<?= $email_val ?>" required autocomplete="email"
                           placeholder="your@email.com">
                </div>
                <div class="form-group">
                    <label class="form-label" for="password"><?= $t['login_password'] ?></label>
                    <input type="password" id="password" name="password" class="form-control"
                           required autocomplete="current-password" placeholder="••••••••">
                </div>
                <button type="submit" class="btn btn-primary btn-full btn-lg" style="margin-top:8px;">
                    <?= $t['login_btn'] ?>
                </button>
            </form>

            <p style="text-align:center; margin-top:20px; font-size:14px; color:var(--gray-500);">
                <?= $t['login_no_account'] ?>
                <a href="<?= APP_URL ?>/pages/register.php" style="font-weight:600;"><?= $t['login_register_link'] ?></a>
            </p>

            <div style="margin-top:24px; padding-top:20px; border-top:1px solid var(--border); font-size:12px; color:var(--gray-500); text-align:center;">
                <strong>Test accounts:</strong><br>
                customer@test.com / business@test.com / admin@test.com<br>
                Password: <code>test1234</code>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>

<script>
// Handle login form via fetch (clean SPA-like experience)
document.getElementById('login-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = this.querySelector('[type=submit]');
    const orig = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Signing in...';

    const fd = new FormData(this);
    try {
        const res = await fetch('<?= APP_URL ?>/api/auth.php?action=login', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ email: fd.get('email'), password: fd.get('password') }),
        });
        const data = await res.json();
        if (res.ok) {
            window.location.href = '<?= APP_URL ?>/pages/index.php';
        } else {
            document.querySelector('.flash-error')?.remove();
            const err = document.createElement('div');
            err.className = 'flash flash-error';
            err.style.cssText = 'border-radius:8px;margin-bottom:20px;';
            err.textContent = data.error || 'Login failed';
            this.insertAdjacentElement('beforebegin', err);
            btn.disabled = false;
            btn.textContent = orig;
        }
    } catch(ex) {
        btn.disabled = false;
        btn.textContent = orig;
    }
});
</script>
