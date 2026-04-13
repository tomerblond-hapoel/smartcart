<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();
$user_id = current_user_id();
$user    = get_current_user_data();

$page_title = $t['profile_title'];
$categories = ['electronics','home','fashion','food','sports','beauty','toys','books','automotive','other'];
$cat_icons  = ['electronics'=>'💻','home'=>'🏠','fashion'=>'👗','food'=>'🍎','sports'=>'⚽','beauty'=>'💄','toys'=>'🧸','books'=>'📚','automotive'=>'🚗','other'=>'📦'];

$pdo = getPDO();

// Stats
$stmt = $pdo->prepare("SELECT COUNT(*) FROM group_members WHERE user_id = ?"); $stmt->execute([$user_id]); $joined_count = (int)$stmt->fetchColumn();
$stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE user_id = ?"); $stmt->execute([$user_id]); $orders_count = (int)$stmt->fetchColumn();
$stmt = $pdo->prepare("SELECT COALESCE(SUM(p.price_ils - p.group_price_ils),0) FROM orders o JOIN products p ON p.id = o.product_id WHERE o.user_id = ?"); $stmt->execute([$user_id]); $total_saved = (float)$stmt->fetchColumn();

include __DIR__ . '/../includes/header.php';
?>

<div class="container container-md" style="padding-top:32px;padding-bottom:60px;">
    <div class="page-header">
        <h1><?= $t['profile_title'] ?></h1>
    </div>

    <!-- Stats Bar -->
    <div class="stats-bar" style="margin-bottom:28px;">
        <div class="stat-card">
            <div class="stat-value"><?= $joined_count ?></div>
            <div class="stat-label"><?= $t['profile_groups_joined'] ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= format_ils($total_saved) ?></div>
            <div class="stat-label"><?= $t['profile_total_saved'] ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $orders_count ?></div>
            <div class="stat-label"><?= $t['profile_orders'] ?></div>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">

        <!-- Left: Profile Form -->
        <div>
            <div class="card" style="margin-bottom:20px;">
                <div class="card-header"><strong><?= $t['profile_title'] ?></strong></div>
                <div class="card-body">
                    <div id="profile-msg" style="display:none;"></div>
                    <form id="profile-form">
                        <div class="form-group">
                            <label class="form-label"><?= $t['register_name'] ?></label>
                            <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($user['full_name'] ?? '') ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= $t['register_phone'] ?></label>
                            <input type="tel" name="phone" class="form-control" value="<?= htmlspecialchars($user['phone'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= $t['label_city'] ?></label>
                            <input type="text" id="city-input" name="city" class="form-control" value="<?= htmlspecialchars($user['city'] ?? '') ?>" placeholder="Tel Aviv, Haifa...">
                            <div class="form-hint">Used by Smart Agent for proximity recommendations</div>
                        </div>
                        <input type="hidden" id="lat-input" name="lat" value="<?= $user['lat'] ?? '' ?>">
                        <input type="hidden" id="lng-input" name="lng" value="<?= $user['lng'] ?? '' ?>">
                        <button type="submit" class="btn btn-primary"><?= $t['profile_save'] ?></button>
                    </form>
                </div>
            </div>

            <!-- Change Password -->
            <div class="card">
                <div class="card-header"><strong><?= $t['profile_change_pass'] ?></strong></div>
                <div class="card-body">
                    <div id="pass-msg" style="display:none;"></div>
                    <form id="pass-form">
                        <div class="form-group">
                            <label class="form-label"><?= $t['profile_current_pass'] ?></label>
                            <input type="password" name="current_password" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= $t['profile_new_pass'] ?></label>
                            <input type="password" name="new_password" class="form-control" required minlength="8">
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= $t['profile_confirm_pass'] ?></label>
                            <input type="password" name="confirm_password" class="form-control" required minlength="8">
                        </div>
                        <button type="submit" class="btn btn-outline"><?= $t['profile_update_pass'] ?></button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Right: Preferred Categories -->
        <div>
            <div class="card">
                <div class="card-header"><strong><?= $t['profile_categories'] ?></strong></div>
                <div class="card-body">
                    <p class="form-hint" style="margin-bottom:16px;"><?= $t['profile_categories_help'] ?></p>
                    <?php $user_cats = $user['preferred_categories'] ?? []; ?>
                    <form id="cats-form">
                        <div class="category-grid">
                            <?php foreach ($categories as $cat): ?>
                            <div>
                                <input type="checkbox" class="category-checkbox" id="cat-<?= $cat ?>"
                                       name="preferred_categories[]" value="<?= $cat ?>"
                                       <?= in_array($cat, $user_cats) ? 'checked' : '' ?>>
                                <label class="category-label" for="cat-<?= $cat ?>">
                                    <span class="category-icon"><?= $cat_icons[$cat] ?></span>
                                    <?= $t['cat_' . $cat] ?>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="submit" class="btn btn-primary btn-full" style="margin-top:16px;">
                            <?= $t['profile_save'] ?>
                        </button>
                    </form>
                    <div id="cats-msg" style="display:none;margin-top:12px;"></div>
                </div>
            </div>
        </div>

    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<script>
// Profile form
document.getElementById('profile-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    const msg  = document.getElementById('profile-msg');
    const btn  = this.querySelector('[type=submit]');
    const fd   = new FormData(this);
    const data = Object.fromEntries(fd);
    btn.disabled = true;
    try {
        await SmartCart.api('<?= APP_URL ?>/api/auth.php?action=update_profile', {
            method: 'POST', body: JSON.stringify(data),
        });
        msg.style.display = 'flex';
        msg.className = 'flash flash-success';
        msg.style.cssText = 'display:flex;border-radius:8px;margin-bottom:12px;';
        msg.textContent = '<?= $t['success_saved'] ?>';
    } catch(err) {
        msg.style.display = 'flex';
        msg.className = 'flash flash-error';
        msg.style.cssText = 'display:flex;border-radius:8px;margin-bottom:12px;';
        msg.textContent = err.message;
    }
    btn.disabled = false;
});

// Password form
document.getElementById('pass-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    const msg = document.getElementById('pass-msg');
    const fd  = new FormData(this);
    if (fd.get('new_password') !== fd.get('confirm_password')) {
        msg.style.cssText = 'display:flex;border-radius:8px;margin-bottom:12px;';
        msg.className = 'flash flash-error'; msg.textContent = 'Passwords do not match.'; return;
    }
    const btn = this.querySelector('[type=submit]');
    btn.disabled = true;
    try {
        await SmartCart.api('<?= APP_URL ?>/api/auth.php?action=change_password', {
            method: 'POST', body: JSON.stringify(Object.fromEntries(fd)),
        });
        this.reset();
        msg.style.cssText = 'display:flex;border-radius:8px;margin-bottom:12px;';
        msg.className = 'flash flash-success'; msg.textContent = 'Password updated!';
    } catch(err) {
        msg.style.cssText = 'display:flex;border-radius:8px;margin-bottom:12px;';
        msg.className = 'flash flash-error'; msg.textContent = err.message;
    }
    btn.disabled = false;
});

// Categories form
document.getElementById('cats-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    const msg  = document.getElementById('cats-msg');
    const cats = [...this.querySelectorAll('input[name="preferred_categories[]"]:checked')].map(i => i.value);
    const btn  = this.querySelector('[type=submit]');
    btn.disabled = true;
    try {
        await SmartCart.api('<?= APP_URL ?>/api/auth.php?action=update_profile', {
            method: 'POST', body: JSON.stringify({ preferred_categories: cats }),
        });
        msg.style.cssText = 'display:block;padding:8px 12px;border-radius:8px;';
        msg.className = 'flash flash-success'; msg.textContent = '<?= $t['success_saved'] ?>';
    } catch(err) {
        msg.style.cssText = 'display:block;padding:8px 12px;border-radius:8px;';
        msg.className = 'flash flash-error'; msg.textContent = err.message;
    }
    btn.disabled = false;
});

// Geocode city when it changes
document.getElementById('city-input')?.addEventListener('blur', function() {
    const city = this.value.trim();
    if (!city || typeof google === 'undefined') return;
    const geocoder = new google.maps.Geocoder();
    geocoder.geocode({ address: city + ', Israel' }, (results, status) => {
        if (status === 'OK') {
            document.getElementById('lat-input').value = results[0].geometry.location.lat();
            document.getElementById('lng-input').value = results[0].geometry.location.lng();
        }
    });
});
function initMap() {} // required by Maps SDK
window.initMap = initMap;
</script>
