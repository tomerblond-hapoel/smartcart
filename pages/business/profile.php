<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/lang.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/functions.php';

require_role('business');
$user_id    = current_user_id();
$user       = get_current_user_data();
$page_title = 'Business Profile';
$needs_map  = true;

$pdo = getPDO();

$bstmt = $pdo->prepare("SELECT * FROM businesses WHERE user_id = ?");
$bstmt->execute([$user_id]);
$business = $bstmt->fetch();
if (!$business) {
    $_SESSION['flash_error'] = 'Business profile not found.';
    header('Location: ' . APP_URL . '/pages/index.php'); exit;
}
$biz_id = $business['id'];

// Stats — same shape as dashboard
$s = $pdo->prepare("SELECT COUNT(*) FROM group_purchases gp JOIN products p ON p.id = gp.product_id WHERE p.business_id = ? AND gp.status='open'"); $s->execute([$biz_id]); $active_deals = (int)$s->fetchColumn();
$s = $pdo->prepare("SELECT COUNT(*) FROM group_purchases gp JOIN products p ON p.id = gp.product_id WHERE p.business_id = ? AND gp.status='closed'"); $s->execute([$biz_id]); $closed_deals = (int)$s->fetchColumn();
$s = $pdo->prepare("SELECT COUNT(*) FROM orders o JOIN products p ON p.id = o.product_id WHERE p.business_id = ?"); $s->execute([$biz_id]); $orders_count = (int)$s->fetchColumn();
$s = $pdo->prepare("SELECT COALESCE(SUM(o.amount_paid),0) FROM orders o JOIN products p ON p.id = o.product_id WHERE p.business_id = ?"); $s->execute([$biz_id]); $revenue = (float)$s->fetchColumn();
$s = $pdo->prepare("SELECT COUNT(DISTINCT gm.user_id) FROM group_members gm JOIN group_purchases gp ON gp.id = gm.group_id JOIN products p ON p.id = gp.product_id WHERE p.business_id = ?"); $s->execute([$biz_id]); $buyers = (int)$s->fetchColumn();

include __DIR__ . '/../../includes/header.php';
?>

<div class="container container-md" style="padding-top:32px;padding-bottom:60px;">
    <div class="page-header">
        <h1>Business Profile</h1>
    </div>

    <!-- Stats Bar -->
    <div class="stats-bar" style="margin-bottom:28px;">
        <div class="stat-card">
            <div class="stat-value"><?= $active_deals ?></div>
            <div class="stat-label">Live Deals</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $closed_deals ?></div>
            <div class="stat-label">Closed Deals</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $buyers ?></div>
            <div class="stat-label">Total Buyers</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $orders_count ?></div>
            <div class="stat-label">Orders</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= format_ils($revenue) ?></div>
            <div class="stat-label">Revenue</div>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">

        <!-- Left: Business Info -->
        <div>
            <div class="card" style="margin-bottom:20px;">
                <div class="card-header"><strong>Business Info</strong></div>
                <div class="card-body">
                    <div id="profile-msg" style="display:none;"></div>
                    <form id="biz-profile-form">
                        <div class="form-group">
                            <label class="form-label">Business Name</label>
                            <input type="text" name="business_name" class="form-control"
                                   value="<?= htmlspecialchars($business['business_name']) ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="3"><?= htmlspecialchars($business['description'] ?? '') ?></textarea>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Phone</label>
                            <input type="tel" name="phone" class="form-control"
                                   value="<?= htmlspecialchars($business['phone'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">City</label>
                            <input type="text" id="biz-city" name="city" class="form-control"
                                   value="<?= htmlspecialchars($business['city'] ?? '') ?>"
                                   autocomplete="off" placeholder="Tel Aviv, Haifa...">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Full Address</label>
                            <input type="text" id="biz-address" name="address" class="form-control"
                                   value="<?= htmlspecialchars($business['address'] ?? '') ?>"
                                   placeholder="e.g. Dizengoff 13, Tel Aviv"
                                   autocomplete="off">
                            <input type="hidden" id="biz-lat" name="lat" value="<?= $business['lat'] ?? '' ?>">
                            <input type="hidden" id="biz-lng" name="lng" value="<?= $business['lng'] ?? '' ?>">
                            <div class="form-hint">Start typing — we'll suggest exact addresses and place you accurately on the map.</div>
                        </div>
                        <?php if ($business['lat'] && $business['lng']): ?>
                        <div class="form-group">
                            <div id="biz-location-map" style="height:200px;border-radius:10px;overflow:hidden;border:1px solid var(--border);"></div>
                        </div>
                        <?php endif; ?>
                        <div class="form-group">
                            <label class="form-label">Logo URL</label>
                            <input type="url" name="logo_url" class="form-control"
                                   value="<?= htmlspecialchars($business['logo_url'] ?? '') ?>" placeholder="https://...">
                            <div class="form-hint">Public URL to your logo image. Upload coming soon.</div>
                        </div>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Right: Account & Password -->
        <div>
            <div class="card" style="margin-bottom:20px;">
                <div class="card-header"><strong>Account</strong></div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" value="<?= htmlspecialchars($user['email'] ?? '') ?>" disabled
                               style="background:var(--gray-50);color:var(--gray-500);">
                        <div class="form-hint">Email cannot be changed. Contact support if needed.</div>
                    </div>
                    <a href="<?= APP_URL ?>/api/auth.php?action=logout" class="btn btn-outline btn-full" style="margin-top:8px;">
                        Logout
                    </a>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><strong>Change Password</strong></div>
                <div class="card-body">
                    <div id="pass-msg" style="display:none;"></div>
                    <form id="pass-form">
                        <div class="form-group">
                            <label class="form-label">Current Password</label>
                            <input type="password" name="current_password" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">New Password</label>
                            <input type="password" name="new_password" class="form-control" required minlength="8">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Confirm New Password</label>
                            <input type="password" name="confirm_password" class="form-control" required minlength="8">
                        </div>
                        <button type="submit" class="btn btn-outline">Update Password</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

<script>
// Business profile form
document.getElementById('biz-profile-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    const msg = document.getElementById('profile-msg');
    const btn = this.querySelector('[type=submit]');
    btn.disabled = true;
    const data = Object.fromEntries(new FormData(this));
    try {
        await SmartCart.api('<?= APP_URL ?>/api/businesses.php?action=update', {
            method: 'POST', body: JSON.stringify(data),
        });
        msg.className = 'flash flash-success';
        msg.style.cssText = 'display:flex;border-radius:8px;margin-bottom:12px;';
        msg.textContent = 'Profile updated!';
    } catch(err) {
        msg.className = 'flash flash-error';
        msg.style.cssText = 'display:flex;border-radius:8px;margin-bottom:12px;';
        msg.textContent = err.message;
    }
    btn.disabled = false;
});

// Password change form
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

// Address autocomplete (full street-level) + live mini-map preview
if (typeof SmartCartMaps !== 'undefined') {
    SmartCartMaps.initAddressAutocomplete('biz-address', 'biz-lat', 'biz-lng');
    SmartCartMaps.initCityAutocomplete('biz-city', null, null);

    var initLat = parseFloat(document.getElementById('biz-lat').value);
    var initLng = parseFloat(document.getElementById('biz-lng').value);
    var mapEl = document.getElementById('biz-location-map');
    var bizMap = null, bizMarker = null;

    function ensureMap(lat, lng) {
        if (!mapEl) return;
        if (!bizMap) {
            bizMap = SmartCartMaps.createMap('biz-location-map', lat, lng, 16);
        } else {
            bizMap.setView([lat, lng], 16);
        }
        if (bizMarker) bizMarker.remove();
        bizMarker = SmartCartMaps.addMarker(bizMap, lat, lng, '<strong><?= htmlspecialchars($business['business_name']) ?></strong>');
    }

    if (mapEl && !isNaN(initLat) && !isNaN(initLng)) ensureMap(initLat, initLng);

    // Re-center on address selection
    document.getElementById('biz-address').addEventListener('change', function() {
        var lat = parseFloat(document.getElementById('biz-lat').value);
        var lng = parseFloat(document.getElementById('biz-lng').value);
        if (!isNaN(lat) && !isNaN(lng)) {
            if (!mapEl) {
                // No map div yet (first save) — wait for reload after submit
                return;
            }
            ensureMap(lat, lng);
        }
    });
}
</script>
