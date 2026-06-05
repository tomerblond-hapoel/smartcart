<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/functions.php';

$page_title = $t['nav_home'];
$user_id    = current_user_id();
$needs_map  = true;

// Logged-in users go straight to their role's home — index.php is the marketing landing.
if (current_user_role() === 'business') {
    header('Location: ' . APP_URL . '/pages/business/dashboard.php'); exit;
}
if (current_user_role() === 'admin') {
    header('Location: ' . APP_URL . '/pages/admin/dashboard.php'); exit;
}

$pdo = getPDO();

// Featured groups (6 newest open)
$featured_stmt = $pdo->query("
    SELECT gp.id, gp.target_participants, gp.current_participants, gp.deadline, gp.status,
           p.name AS product_name, p.image_url, p.group_price_ils, p.price_ils, p.category,
           ROUND((p.price_ils - p.group_price_ils) / p.price_ils * 100) AS disc,
           ROUND(gp.current_participants / gp.target_participants * 100) AS fill_pct,
           b.business_name
    FROM group_purchases gp
    JOIN products p ON p.id = gp.product_id AND p.status = 'active'
    JOIN businesses b ON b.id = p.business_id
    WHERE gp.status = 'open' AND gp.deadline > NOW()
    ORDER BY gp.created_at DESC
    LIMIT 6
");
$featured = $featured_stmt->fetchAll();

// Map groups (all open with coordinates)
$map_stmt = $pdo->query("
    SELECT gp.id, gp.lat, gp.lng, gp.current_participants, gp.target_participants,
           p.name AS product_name, p.group_price_ils
    FROM group_purchases gp
    JOIN products p ON p.id = gp.product_id AND p.status = 'active'
    WHERE gp.status = 'open' AND gp.lat IS NOT NULL AND gp.lng IS NOT NULL
    LIMIT 50
");
$map_groups = $map_stmt->fetchAll();

// Category stats
$cat_stmt = $pdo->query("
    SELECT p.category, COUNT(gp.id) AS cnt
    FROM group_purchases gp
    JOIN products p ON p.id = gp.product_id
    WHERE gp.status = 'open'
    GROUP BY p.category ORDER BY cnt DESC
");
$cat_counts = $cat_stmt->fetchAll();

// Last-minute deals (deadline within 24 hours)
$urgent_stmt = $pdo->query("
    SELECT gp.id, gp.target_participants, gp.current_participants, gp.deadline, gp.status,
           p.name AS product_name, p.image_url, p.group_price_ils, p.price_ils, p.category,
           ROUND((p.price_ils - p.group_price_ils) / p.price_ils * 100) AS disc,
           ROUND(gp.current_participants / gp.target_participants * 100) AS fill_pct,
           b.business_name,
           TIMESTAMPDIFF(SECOND, NOW(), gp.deadline) AS secs_left
    FROM group_purchases gp
    JOIN products p ON p.id = gp.product_id AND p.status = 'active'
    JOIN businesses b ON b.id = p.business_id
    WHERE gp.status = 'open' AND gp.deadline > NOW() AND gp.deadline <= DATE_ADD(NOW(), INTERVAL 24 HOUR)
    ORDER BY gp.deadline ASC
    LIMIT 6
");
$urgent_groups = $urgent_stmt->fetchAll();

// Real stats
$stats_stmt = $pdo->query("SELECT COUNT(DISTINCT user_id) FROM group_members WHERE status IN ('joined','paid')");
$active_buyers = (int)$stats_stmt->fetchColumn();

$stats_stmt2 = $pdo->query("SELECT COUNT(*) FROM group_purchases WHERE status = 'closed'");
$successful_deals = (int)$stats_stmt2->fetchColumn();

$stats_stmt3 = $pdo->query("SELECT ROUND(AVG((p.price_ils - p.group_price_ils) / p.price_ils * 100)) FROM products p WHERE p.status = 'active' AND p.price_ils > 0");
$avg_savings = (int)$stats_stmt3->fetchColumn();

$categories = ['electronics','home','fashion','food','sports','beauty','toys','books','automotive','other'];
$cat_icons  = ['electronics'=>'💻','home'=>'🏠','fashion'=>'👗','food'=>'🍎','sports'=>'⚽','beauty'=>'💄','toys'=>'🧸','books'=>'📚','automotive'=>'🚗','other'=>'📦'];

include __DIR__ . '/../includes/header.php';
?>

<!-- HERO -->
<section class="hero" style="padding-bottom:0;">
    <div class="container" style="max-width:860px;">
        <div class="tag">
            <svg width="10" height="10" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l2.4 7.4H22l-6.2 4.5 2.4 7.4L12 17l-6.2 4.3 2.4-7.4L2 9.4h7.6z"/></svg>
            Group buying, reimagined
        </div>
        <h1><?= $t['home_hero_title'] ?></h1>
        <p class="hero-subtitle"><?= $t['home_hero_subtitle'] ?></p>

        <?php if (!$user_id): ?>
        <!-- Choice cards for logged-out visitors -->
        <div class="hero-cards">
            <article class="choice-card">
                <div class="choice-icon purple">
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                </div>
                <h3><?= $t['home_customer_title'] ?? "I'm a Customer" ?></h3>
                <p><?= $t['home_customer_desc'] ?? 'Browse products, join group deals, and save big with collective buying power.' ?></p>
                <ul>
                    <li>Unlock exclusive group discounts</li>
                    <li>Zero payment until group succeeds</li>
                    <li>Personalized recommendations</li>
                </ul>
                <a href="<?= APP_URL ?>/pages/register.php?role=customer"><?= $t['home_cta_browse'] ?> →</a>
            </article>
            <article class="choice-card business-card">
                <div class="choice-icon orange">
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                </div>
                <h3><?= $t['home_business_title'] ?? "I'm a Business" ?></h3>
                <p><?= $t['home_business_desc'] ?? 'Create group deals, reach bulk buyers, and guarantee minimum orders.' ?></p>
                <ul class="business-list">
                    <li>Launch a group deal in minutes</li>
                    <li>Guaranteed bulk order volume</li>
                    <li>Full dashboard &amp; analytics</li>
                </ul>
                <a href="<?= APP_URL ?>/pages/register.php?role=business" class="green-link">Enter as Business →</a>
            </article>
        </div>
        <?php else: ?>
        <div class="hero-actions">
            <a href="<?= APP_URL ?>/pages/browse.php" class="btn btn-primary btn-lg"><?= $t['home_cta_browse'] ?></a>
            <a href="<?= APP_URL ?>/pages/agent.php" class="btn btn-outline btn-lg">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3l1.5 5.5L19 10l-5.5 1.5L12 17l-1.5-5.5L5 10l5.5-1.5L12 3z"/></svg>
                <?= $t['home_cta_agent'] ?>
            </a>
        </div>
        <?php endif; ?>
    </div>
</section>

<!-- STATS ROW -->
<div class="stats-row" style="background:var(--white);border-top:1px solid var(--border);">
    <div class="stat-item"><strong><?= $avg_savings > 0 ? $avg_savings . '%' : '—' ?></strong><span>Average savings</span></div>
    <div class="stat-item"><strong><?= $active_buyers > 0 ? number_format($active_buyers) . '+' : '—' ?></strong><span>Active buyers</span></div>
    <div class="stat-item"><strong><?= $successful_deals > 0 ? $successful_deals . '+' : '—' ?></strong><span>Successful deals</span></div>
</div>

<!-- SMART AGENT WIDGET (if logged in) -->
<?php if ($user_id): ?>
<section class="section" style="padding-bottom:0;">
    <div class="container">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
            <h2 class="section-title" style="margin-bottom:0;">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--purple)"><path d="M12 3l1.5 5.5L19 10l-5.5 1.5L12 17l-1.5-5.5L5 10l5.5-1.5L12 3z"/><path d="M5 3l.5 2 2 .5-2 .5-.5 2-.5-2-2-.5 2-.5.5-2z"/><path d="M19 16l.5 2 2 .5-2 .5-.5 2-.5-2-2-.5 2-.5.5-2z"/></svg>
                <?= $t['home_agent_title'] ?>
            </h2>
            <a href="<?= APP_URL ?>/pages/agent.php" class="section-header-link"><?= $t['home_view_all'] ?> →</a>
        </div>
        <div id="agent-widget" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px;">
            <p class="text-muted" style="padding:20px;"><?= $t['loading'] ?></p>
        </div>
    </div>
</section>
<?php else: ?>
<section class="section" style="padding-bottom:0;">
    <div class="container">
        <div style="background:var(--purple-50);border-radius:var(--radius);padding:20px 24px;display:flex;align-items:center;gap:16px;">
            <div style="width:40px;height:40px;background:var(--btn-gradient);border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.5 5.5L19 10l-5.5 1.5L12 17l-1.5-5.5L5 10l5.5-1.5L12 3z"/></svg>
            </div>
            <div>
                <strong><?= $t['nav_agent'] ?></strong>
                <p style="font-size:14px;color:var(--gray-500);margin:2px 0 0;"><?= $t['home_agent_login'] ?></p>
            </div>
            <a href="<?= APP_URL ?>/pages/login.php" class="btn btn-primary btn-sm" style="margin-left:auto;"><?= $t['nav_login'] ?></a>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- CATEGORIES -->
<section class="section" style="padding-bottom:0;">
    <div class="container">
        <h2 class="section-title"><?= $t['home_categories'] ?></h2>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <?php foreach ($categories as $cat): ?>
            <a href="<?= APP_URL ?>/pages/browse.php?category=<?= $cat ?>" class="btn-filter">
                <?= $t['cat_' . $cat] ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- LAST-MINUTE DEALS -->
<?php if (!empty($urgent_groups)): ?>
<section class="section" style="padding-bottom:0;">
    <div class="container">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
            <h2 class="section-title" style="margin-bottom:0;color:var(--danger);">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                <?= $t['home_last_minute'] ?>
            </h2>
            <span id="global-countdown" style="font-size:13px;font-weight:700;color:var(--danger);font-variant-numeric:tabular-nums;"></span>
        </div>
        <div class="grid-products grid">
            <?php foreach ($urgent_groups as $g):
                $remaining = max(0, (int)$g['secs_left']);
                $more_needed = max(0, (int)$g['target_participants'] - (int)$g['current_participants']);
            ?>
            <div class="urgent-card" style="background:#fff;border:2px solid var(--danger);border-radius:12px;overflow:hidden;cursor:pointer;transition:transform .2s,box-shadow .2s;" onmouseover="this.style.transform='translateY(-3px)';this.style.boxShadow='0 8px 24px rgba(239,68,68,.2)'" onmouseout="this.style.transform='';this.style.boxShadow=''" onclick="window.location='<?= APP_URL ?>/pages/group.php?id=<?= $g['id'] ?>'">
                <?php if ($g['image_url']): ?>
                <div style="width:100%;height:160px;background:url('<?= APP_URL . htmlspecialchars($g['image_url']) ?>') center/cover no-repeat;position:relative;">
                    <span style="position:absolute;top:8px;left:8px;font-size:11px;font-weight:700;color:#fff;background:var(--danger);padding:3px 10px;border-radius:999px;"><?= $g['disc'] ?>% OFF</span>
                </div>
                <?php else: ?>
                <div style="width:100%;height:160px;background:#fff0f0;display:flex;align-items:center;justify-content:center;position:relative;">
                    <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="color:var(--danger);opacity:.4"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    <span style="position:absolute;top:8px;left:8px;font-size:11px;font-weight:700;color:#fff;background:var(--danger);padding:3px 10px;border-radius:999px;"><?= $g['disc'] ?>% OFF</span>
                </div>
                <?php endif; ?>
                <div style="padding:12px;">
                    <h3 style="font-size:14px;font-weight:600;margin-bottom:4px;color:var(--gray-900);"><?= htmlspecialchars($g['product_name']) ?></h3>
                    <p style="font-size:12px;color:var(--gray-500);margin-bottom:8px;"><?= htmlspecialchars($g['business_name']) ?></p>
                    <div style="font-size:18px;font-weight:800;color:var(--purple);margin-bottom:8px;"><?= format_ils($g['group_price_ils']) ?></div>
                    <div style="font-size:12px;color:var(--gray-600);margin-bottom:6px;">
                        <strong><?= $g['current_participants'] ?></strong> joining now
                        <?php if ($more_needed > 0): ?>
                        · <strong style="color:var(--danger);"><?= $more_needed ?> <?= $t['home_more_to_save'] ?></strong>
                        <?php endif; ?>
                    </div>
                    <div class="countdown-timer" data-deadline="<?= $remaining ?>" style="font-size:13px;font-weight:700;color:var(--danger);font-variant-numeric:tabular-nums;"></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- FEATURED GROUPS -->
<section class="section">
    <div class="container">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
            <h2 class="section-title" style="margin-bottom:0;"><?= $t['home_featured'] ?></h2>
            <a href="<?= APP_URL ?>/pages/browse.php" style="font-size:14px;font-weight:500;">Browse all →</a>
        </div>

        <?php if (empty($featured)): ?>
        <div class="empty-state">
            <p>No active group purchases yet. Be the first to start one!</p>
            <a href="<?= APP_URL ?>/pages/browse.php" class="btn btn-primary mt-16">Browse Products</a>
        </div>
        <?php else: ?>
        <div class="grid-products grid">
            <?php foreach ($featured as $g):
                $fill = (int)$g['fill_pct'];
                $fill_class = $fill >= 80 ? 'high' : ($fill >= 50 ? 'medium' : '');
                $days = days_until($g['deadline']);
            ?>
            <div style="background:#fff;border:1px solid #e9e9f1;border-radius:12px;overflow:hidden;cursor:pointer;transition:transform .2s,box-shadow .2s;" onmouseover="this.style.transform='translateY(-3px)';this.style.boxShadow='0 8px 24px rgba(111,82,255,.15)'" onmouseout="this.style.transform='';this.style.boxShadow=''" onclick="window.location='<?= APP_URL ?>/pages/group.php?id=<?= $g['id'] ?>'">
                <?php if ($g['image_url']): ?>
                <div style="width:100%;height:180px;background:url('<?= APP_URL . htmlspecialchars($g['image_url']) ?>') center/cover no-repeat;"></div>
                <?php else: ?>
                <div style="width:100%;height:180px;background:var(--purple-50);display:flex;align-items:center;justify-content:center;"><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="color:var(--purple);opacity:.35"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg></div>
                <?php endif; ?>
                <div class="card-body">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px;">
                        <span class="card-badge badge-open"><?= $t['group_open'] ?></span>
                        <span class="card-badge badge-discount"><?= $g['disc'] ?>% <?= $t['off'] ?></span>
                    </div>
                    <h3 style="font-size:15px;font-weight:600;margin-bottom:4px;color:var(--gray-900);">
                        <?= htmlspecialchars($g['product_name']) ?>
                    </h3>
                    <p style="font-size:12px;color:var(--gray-500);margin-bottom:10px;"><?= htmlspecialchars($g['business_name']) ?></p>
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
                        <span style="font-size:20px;font-weight:700;color:var(--purple);">
                            <?= format_ils($g['group_price_ils']) ?>
                        </span>
                        <span style="font-size:13px;text-decoration:line-through;color:var(--gray-500);">
                            <?= format_ils($g['price_ils']) ?>
                        </span>
                    </div>
                    <div class="progress-wrap" style="margin-bottom:4px;">
                        <div class="progress-bar <?= $fill_class ?>" style="width:<?= $fill ?>%;"></div>
                    </div>
                    <div class="progress-label">
                        <?php $more = max(0, (int)$g['target_participants'] - (int)$g['current_participants']); ?>
                        <span><?= $g['current_participants'] ?>/<?= $g['target_participants'] ?> <?= $t['participants'] ?></span>
                        <?php if ($more > 0): ?>
                        <span style="color:var(--danger);font-weight:600;"><?= $more ?> <?= $t['home_more_to_save'] ?></span>
                        <?php else: ?>
                        <span style="color:var(--green);font-weight:600;">✓ Goal reached!</span>
                        <?php endif; ?>
                    </div>
                    <div style="margin-top:10px;font-size:12px;color:<?= $days <= 3 ? 'var(--danger)' : 'var(--gray-500)' ?>;display:flex;align-items:center;gap:4px;">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        <?= countdown_label($g['deadline']) ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</section>

<!-- MAP SECTION -->
<?php if (!empty($map_groups)): ?>
<section class="map-section">
    <div class="container">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:8px;">
            <h2 class="section-title" style="margin-bottom:0;"><?= $t['home_map_title'] ?></h2>
            <button id="map-locate-me" class="btn btn-outline btn-sm">📍 Find me on map</button>
        </div>
        <div id="map-main"></div>
    </div>
</section>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<script>
// ── Live countdown timers ─────────────────────────────────
(function() {
    const timers = document.querySelectorAll('.countdown-timer[data-deadline]');
    if (!timers.length) return;
    const starts = Array.from(timers).map(el => ({
        el: el,
        secs: parseInt(el.dataset.deadline, 10)
    }));
    const tick = Date.now();
    function fmt(s) {
        if (s <= 0) return 'Expired';
        const h = Math.floor(s / 3600);
        const m = Math.floor((s % 3600) / 60);
        const sec = s % 60;
        return [h,m,sec].map(n => String(n).padStart(2,'0')).join(':');
    }
    function update() {
        const elapsed = Math.floor((Date.now() - tick) / 1000);
        starts.forEach(t => { t.el.textContent = fmt(Math.max(0, t.secs - elapsed)); });
    }
    update();
    setInterval(update, 1000);
})();

// ── Smart Agent widget (only if logged in) ───────────────
<?php if ($user_id): ?>
(function() {
    const widget = document.getElementById('agent-widget');
    SmartCart.loadAgentRecommendations(widget, <?= $user_id ?>, 3, null);
})();
<?php endif; ?>

// ── Leaflet Map ──────────────────────────────────────────
const mapGroups = <?= json_encode($map_groups, JSON_UNESCAPED_UNICODE) ?>;
const appUrl    = '<?= APP_URL ?>';

let mainMap = null;
let mainMarkers = [];
(function() {
    if (!mapGroups.length || typeof SmartCartMaps === 'undefined') return;
    var center = SmartCartMaps.ISRAEL_CENTER;
    if (mapGroups[0].lat && mapGroups[0].lng) {
        center = { lat: parseFloat(mapGroups[0].lat), lng: parseFloat(mapGroups[0].lng) };
    }
    mainMap = SmartCartMaps.createMap('map-main', center.lat, center.lng, 8);
    if (!mainMap) return;
    mapGroups.forEach(function(g) {
        if (!g.lat || !g.lng) return;
        var popup = '<div style="font-family:Inter,sans-serif;padding:4px">'
            + '<strong>' + g.product_name + '</strong><br>'
            + '<span style="color:var(--primary);font-weight:600">\u20AA' + parseFloat(g.group_price_ils).toLocaleString() + '</span><br>'
            + '<span style="font-size:12px;color:#6B7280">' + g.current_participants + '/' + g.target_participants + ' members</span><br>'
            + '<a href="' + appUrl + '/pages/group.php?id=' + g.id + '" style="color:var(--primary);font-size:12px">Join Group \u2192</a>'
            + '</div>';
        mainMarkers.push(SmartCartMaps.addMarker(mainMap, parseFloat(g.lat), parseFloat(g.lng), popup));
    });
    SmartCartMaps.fitBounds(mainMap, mainMarkers);
})();

// Find me button
const locateBtn = document.getElementById('map-locate-me');
if (locateBtn && typeof SmartCartMaps !== 'undefined') {
    locateBtn.addEventListener('click', async function() {
        const orig = this.textContent;
        this.textContent = '\uD83D\uDCCD Locating\u2026';
        this.disabled = true;
        try {
            const loc = await SmartCartMaps.getCurrentLocation();
            if (mainMap) {
                SmartCartMaps.addMyLocationMarker(mainMap, loc.lat, loc.lng);
                mainMap.setView([loc.lat, loc.lng], 12);
            }
            this.textContent = '\u2713 Centered on you';
            setTimeout(() => { this.textContent = orig; this.disabled = false; }, 1500);
        } catch (err) {
            if (typeof SmartCart !== 'undefined') SmartCart.showToast(err.message, 'error');
            this.textContent = orig;
            this.disabled = false;
        }
    });
}
</script>
