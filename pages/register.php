<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../includes/auth_check.php';

if (is_logged_in()) {
    header('Location: ' . APP_URL . '/pages/index.php');
    exit;
}

$page_title = $t['register_title'];
$needs_map  = true;
include __DIR__ . '/../includes/header.php';
?>

<div class="container container-sm" style="padding-top:48px; padding-bottom:60px;">
    <div class="card" style="max-width:500px; margin:0 auto;">
        <div class="card-body" style="padding:36px;">
            <div style="text-align:center; margin-bottom:28px;">
                <h1 style="font-size:22px;font-weight:700;color:var(--gray-900);"><?= $t['register_title'] ?></h1>
                <p style="color:var(--gray-500);margin-top:6px;font-size:14px;">Join SmartCart today</p>
            </div>

            <div id="error-msg" class="flash flash-error" style="border-radius:8px;margin-bottom:20px;display:none;"></div>

            <!-- Step 1: Role Selection -->
            <div id="step-1">
                <p style="font-weight:600;margin-bottom:16px;text-align:center;"><?= $t['register_choose_role'] ?></p>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px;">
                    <button type="button" class="role-card" data-role="customer" style="padding:24px;border:2px solid var(--border);border-radius:12px;background:white;cursor:pointer;text-align:center;transition:all .15s;">
                        <div style="font-size:36px;margin-bottom:10px;">🛍️</div>
                        <div style="font-weight:600;font-size:14px;"><?= $t['register_customer'] ?></div>
                    </button>
                    <button type="button" class="role-card" data-role="business" style="padding:24px;border:2px solid var(--border);border-radius:12px;background:white;cursor:pointer;text-align:center;transition:all .15s;">
                        <div style="font-size:36px;margin-bottom:10px;">🏪</div>
                        <div style="font-weight:600;font-size:14px;"><?= $t['register_business'] ?></div>
                    </button>
                </div>
            </div>

            <!-- Step 2: Registration Form (hidden until role selected) -->
            <form id="register-form" style="display:none;">
                <input type="hidden" id="role-input" name="role" value="customer">

                <div class="form-group">
                    <label class="form-label" for="full_name"><?= $t['register_name'] ?></label>
                    <input type="text" id="full_name" name="full_name" class="form-control" required
                           placeholder="Your full name">
                </div>
                <div class="form-group">
                    <label class="form-label" for="reg-email"><?= $t['register_email'] ?></label>
                    <input type="email" id="reg-email" name="email" class="form-control" required
                           placeholder="your@email.com">
                </div>
                <div class="form-group">
                    <label class="form-label" for="reg-password"><?= $t['register_password'] ?></label>
                    <input type="password" id="reg-password" name="password" class="form-control" required
                           placeholder="Min. 8 characters" minlength="8">
                </div>
                <div class="form-group">
                    <label class="form-label" for="confirm-password"><?= $t['register_confirm'] ?></label>
                    <input type="password" id="confirm-password" name="confirm_password" class="form-control" required
                           placeholder="Repeat password">
                </div>
                <div class="form-group">
                    <label class="form-label" for="city"><?= $t['register_city'] ?></label>
                    <input type="text" id="reg-city" name="city" class="form-control"
                           placeholder="Tel Aviv, Haifa, etc."
                           autocomplete="off">
                    <input type="hidden" id="reg-lat" name="lat" value="">
                    <input type="hidden" id="reg-lng" name="lng" value="">
                </div>
                <div class="form-group">
                    <label class="form-label" for="phone"><?= $t['register_phone'] ?></label>
                    <input type="tel" id="phone" name="phone" class="form-control" placeholder="05X-XXXXXXX">
                </div>

                <!-- Business-only fields -->
                <div id="business-fields" style="display:none;">
                    <hr style="margin:16px 0;border:none;border-top:1px solid var(--border);">
                    <p style="font-size:13px;color:var(--gray-500);margin-bottom:12px;">Business Details</p>
                    <div class="form-group">
                        <label class="form-label" for="business_name"><?= $t['register_biz_name'] ?></label>
                        <input type="text" id="business_name" name="business_name" class="form-control"
                               placeholder="Your business name">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="business_category"><?= $t['register_biz_cat'] ?></label>
                        <select id="business_category" name="business_category" class="form-control">
                            <option value="electronics"><?= $t['cat_electronics'] ?></option>
                            <option value="home"><?= $t['cat_home'] ?></option>
                            <option value="fashion"><?= $t['cat_fashion'] ?></option>
                            <option value="food"><?= $t['cat_food'] ?></option>
                            <option value="sports"><?= $t['cat_sports'] ?></option>
                            <option value="beauty"><?= $t['cat_beauty'] ?></option>
                            <option value="toys"><?= $t['cat_toys'] ?></option>
                            <option value="books"><?= $t['cat_books'] ?></option>
                            <option value="automotive"><?= $t['cat_automotive'] ?></option>
                            <option value="other"><?= $t['cat_other'] ?></option>
                        </select>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-full btn-lg" style="margin-top:16px;">
                    <?= $t['register_btn'] ?>
                </button>

                <button type="button" id="back-btn" class="btn btn-ghost btn-full" style="margin-top:8px;">
                    ← Back
                </button>
            </form>

            <p style="text-align:center;margin-top:20px;font-size:14px;color:var(--gray-500);">
                <?= $t['register_have_account'] ?>
                <a href="<?= APP_URL ?>/pages/login.php" style="font-weight:600;"><?= $t['register_login_link'] ?></a>
            </p>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<script>
const step1 = document.getElementById('step-1');
const form  = document.getElementById('register-form');
const errEl = document.getElementById('error-msg');

document.querySelectorAll('.role-card').forEach(card => {
    card.addEventListener('click', function() {
        const role = this.dataset.role;
        document.getElementById('role-input').value = role;
        step1.style.display = 'none';
        form.style.display  = 'block';
        document.getElementById('business-fields').style.display = role === 'business' ? 'block' : 'none';
        document.getElementById('business_name').required = role === 'business';
    });
    card.addEventListener('mouseenter', () => { card.style.borderColor = 'var(--teal)'; card.style.background = 'var(--teal-50)'; });
    card.addEventListener('mouseleave', () => { card.style.borderColor = 'var(--border)'; card.style.background = 'white'; });
});

document.getElementById('back-btn').addEventListener('click', () => {
    form.style.display  = 'none';
    step1.style.display = 'block';
});

form.addEventListener('submit', async function(e) {
    e.preventDefault();
    errEl.style.display = 'none';
    const fd = new FormData(this);
    if (fd.get('password') !== fd.get('confirm_password')) {
        errEl.textContent = 'Passwords do not match.';
        errEl.style.display = 'flex';
        return;
    }
    const btn = this.querySelector('[type=submit]');
    btn.disabled = true;
    btn.textContent = 'Creating account...';

    const data = Object.fromEntries(fd);
    delete data.confirm_password;

    try {
        const res  = await fetch('<?= APP_URL ?>/api/auth.php?action=register', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(data),
        });
        const json = await res.json();
        if (res.ok) {
            window.location.href = '<?= APP_URL ?>/pages/profile.php';
        } else {
            errEl.textContent = json.error || 'Registration failed.';
            errEl.style.display = 'flex';
            btn.disabled = false;
            btn.textContent = '<?= $t['register_btn'] ?>';
        }
    } catch(ex) {
        btn.disabled = false;
        btn.textContent = '<?= $t['register_btn'] ?>';
    }
});
// City autocomplete (Photon / OpenStreetMap)
if (typeof SmartCartMaps !== 'undefined') {
    SmartCartMaps.initCityAutocomplete('reg-city', 'reg-lat', 'reg-lng');
}
</script>
