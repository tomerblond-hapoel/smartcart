<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/functions.php';

$page_title = $t['browse_title'];
$needs_map  = true;
$pdo = getPDO();

$category = trim($_GET['category'] ?? '');
$city     = trim($_GET['city']     ?? '');
$search   = trim($_GET['search']   ?? '');
$sort     = trim($_GET['sort']     ?? 'newest');
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$offset   = ($page - 1) * $per_page;

$categories = ['electronics','home','fashion','food','sports','beauty','toys','books','automotive','other'];
$cat_icons  = ['electronics'=>'💻','home'=>'🏠','fashion'=>'👗','food'=>'🍎','sports'=>'⚽','beauty'=>'💄','toys'=>'🧸','books'=>'📚','automotive'=>'🚗','other'=>'📦'];

// Build query
$where  = ['p.status = ?'];
$params = ['active'];
if ($category && in_array($category, $categories)) {
    $where[]  = 'p.category = ?';
    $params[] = $category;
}
if ($city) {
    // Fuzzy: "Tel Aviv" must match "Dizengoff 13, Tel Aviv"; "Tel Aviv-Yafo" must match "Tel Aviv".
    // Strip suffixes after dash/comma and require any token (≥2 chars) to match.
    $clean = trim(preg_replace('/[-,].*$/u', '', $city));
    $tokens = preg_split('/\s+/u', $clean, -1, PREG_SPLIT_NO_EMPTY);
    $tokens = array_filter($tokens, fn($t) => mb_strlen($t) >= 2);
    if (!$tokens) $tokens = [$city];
    $parts = [];
    foreach ($tokens as $tok) {
        $parts[] = '(p.city LIKE ? OR b.city LIKE ? OR b.address LIKE ?)';
        $params[] = "%$tok%"; $params[] = "%$tok%"; $params[] = "%$tok%";
    }
    $where[] = '(' . implode(' OR ', $parts) . ')';
}
if ($search) {
    $where[]  = '(p.name LIKE ? OR p.description LIKE ? OR b.business_name LIKE ? OR b.address LIKE ? OR b.city LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($sort === 'discount') {
    $order = 'disc DESC';
} elseif ($sort === 'ending_soon') {
    $order = '(SELECT MIN(gp2.deadline) FROM group_purchases gp2 WHERE gp2.product_id = p.id AND gp2.status = \'open\') ASC';
} else {
    $order = 'p.created_at DESC';
}

$where_sql = implode(' AND ', $where);
$sql = "
    SELECT p.*,
           b.business_name, b.city AS biz_city,
           ROUND((p.price_ils - p.group_price_ils) / p.price_ils * 100) AS disc,
           (SELECT COUNT(*) FROM group_purchases gp WHERE gp.product_id = p.id AND gp.status = 'open') AS active_groups
    FROM products p
    JOIN businesses b ON b.id = p.business_id
    WHERE $where_sql
    ORDER BY $order
    LIMIT $per_page OFFSET $offset
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

// Total count
$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM products p JOIN businesses b ON b.id = p.business_id WHERE $where_sql");
$count_stmt->execute($params);
$total = (int)$count_stmt->fetchColumn();
$total_pages = max(1, ceil($total / $per_page));

include __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding-top:32px; padding-bottom:60px;">
    <div class="page-header">
        <h1><?= $t['browse_title'] ?></h1>
        <p style="color:var(--gray-500);"><?= $total ?> products available</p>
    </div>

    <!-- Filter Bar -->
    <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:28px;background:white;padding:16px;border-radius:var(--radius);box-shadow:var(--shadow);">
        <div style="flex:2;min-width:200px;">
            <label class="form-label">Search</label>
            <input type="text" name="search" class="form-control"
                   value="<?= htmlspecialchars($search) ?>"
                   placeholder="Search products, businesses...">
        </div>
        <div style="flex:1;min-width:140px;">
            <label class="form-label"><?= $t['filter_category'] ?></label>
            <select name="category" class="form-control" onchange="this.form.submit()">
                <option value=""><?= $t['filter_all'] ?></option>
                <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat ?>" <?= $category === $cat ? 'selected' : '' ?>>
                    <?= $cat_icons[$cat] ?> <?= $t['cat_' . $cat] ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="flex:1;min-width:140px;">
            <label class="form-label"><?= $t['filter_city'] ?></label>
            <input type="text" id="browse-city" name="city" class="form-control"
                   value="<?= htmlspecialchars($city) ?>"
                   placeholder="City or street..."
                   autocomplete="off">
            <button type="button" id="use-my-location" class="btn btn-ghost btn-sm" style="margin-top:6px;padding:4px 10px;font-size:11px;">
                📍 Use my location
            </button>
        </div>
        <div style="flex:1;min-width:140px;">
            <label class="form-label"><?= $t['filter_sort'] ?></label>
            <select name="sort" class="form-control" onchange="this.form.submit()">
                <option value="newest" <?= $sort==='newest'?'selected':'' ?>><?= $t['sort_newest'] ?></option>
                <option value="discount" <?= $sort==='discount'?'selected':'' ?>><?= $t['sort_discount'] ?></option>
                <option value="ending_soon" <?= $sort==='ending_soon'?'selected':'' ?>><?= $t['sort_ending_soon'] ?></option>
            </select>
        </div>
        <div style="display:flex;align-items:flex-end;">
            <button type="submit" class="btn btn-primary">Search</button>
        </div>
        <?php if ($category || $city || $sort !== 'newest'): ?>
        <div style="display:flex;align-items:flex-end;">
            <a href="<?= APP_URL ?>/pages/browse.php" class="btn btn-ghost">Clear</a>
        </div>
        <?php endif; ?>
    </form>

    <!-- Category Pills (quick filter) -->
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:24px;">
        <a href="<?= APP_URL ?>/pages/browse.php" class="btn-filter <?= !$category ? 'active' : '' ?>">All</a>
        <?php foreach ($categories as $cat): ?>
        <a href="?category=<?= $cat ?>&sort=<?= $sort ?>" class="btn-filter <?= $category===$cat ? 'active' : '' ?>">
            <?= $t['cat_' . $cat] ?>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Products Grid -->
    <?php if (empty($products)): ?>
    <div class="empty-state">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
        </svg>
        <h3><?= $t['no_results'] ?></h3>
        <a href="<?= APP_URL ?>/pages/browse.php" class="btn btn-outline mt-16">Clear Filters</a>
    </div>
    <?php else: ?>
    <div class="grid grid-products">
        <?php foreach ($products as $p): ?>
        <div style="background:#fff;border:1px solid #e9e9f1;border-radius:12px;overflow:hidden;cursor:pointer;transition:transform .2s,box-shadow .2s;" onmouseover="this.style.transform='translateY(-3px)';this.style.boxShadow='0 8px 24px rgba(111,82,255,.15)'" onmouseout="this.style.transform='';this.style.boxShadow=''" onclick="window.location='<?= APP_URL ?>/pages/product.php?id=<?= $p['id'] ?>'">
            <?php if ($p['image_url']): ?>
            <div style="width:100%;height:180px;background:url('<?= img_url($p['image_url']) ?>') center/cover no-repeat;position:relative;">
                <span style="position:absolute;top:10px;right:10px;font-size:11px;font-weight:700;color:var(--green);background:rgba(26,199,133,.12);padding:3px 10px;border-radius:999px;"><?= $p['disc'] ?>% off</span>
            </div>
            <?php else: ?>
            <div style="width:100%;height:180px;background:var(--purple-50);display:flex;align-items:center;justify-content:center;position:relative;">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="color:var(--purple);opacity:.4"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
                <span style="position:absolute;top:10px;right:10px;font-size:11px;font-weight:700;color:var(--green);background:rgba(26,199,133,.12);padding:3px 10px;border-radius:999px;"><?= $p['disc'] ?>% off</span>
            </div>
            <?php endif; ?>
            <div style="padding:14px;">
                <span style="font-size:11px;font-weight:600;color:var(--purple);background:var(--purple-50);border-radius:999px;padding:3px 10px;display:inline-block;margin-bottom:8px;"><?= $t['cat_' . $p['category']] ?></span>
                <h3 style="font-size:14px;font-weight:600;color:#1a1a24;margin-bottom:6px;min-height:36px;line-height:1.4;">
                    <?= htmlspecialchars($p['name']) ?>
                </h3>
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                    <span style="font-size:18px;font-weight:800;color:var(--purple);"><?= format_ils($p['group_price_ils']) ?></span>
                    <span style="font-size:13px;text-decoration:line-through;color:var(--muted);"><?= format_ils($p['price_ils']) ?></span>
                </div>
                <div style="font-size:12px;color:var(--muted);display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                    <span style="display:flex;align-items:center;gap:3px;">
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                        Min <?= $p['min_participants'] ?>
                    </span>
                    <?php $display_city = $p['city'] ?? $p['biz_city'] ?? ''; ?>
                    <?php if ($display_city): ?>
                    <span style="display:flex;align-items:center;gap:3px;">
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        <?= htmlspecialchars($display_city) ?>
                    </span>
                    <?php endif; ?>
                    <?php if ($p['active_groups'] > 0): ?>
                    <span style="color:var(--green);font-weight:600;">✓ <?= $p['active_groups'] ?> active</span>
                    <?php else: ?>
                    <span>No active groups</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div style="display:flex;justify-content:center;gap:8px;margin-top:32px;">
        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
        <a href="?category=<?= $category ?>&city=<?= urlencode($city) ?>&sort=<?= $sort ?>&page=<?= $i ?>"
           class="btn btn-sm <?= $i === $page ? 'btn-primary' : 'btn-outline' ?>">
            <?= $i ?>
        </a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<script>
// City autocomplete for browse filter (text-only, no lat/lng needed)
if (typeof SmartCartMaps !== 'undefined') {
    SmartCartMaps.initCityAutocomplete('browse-city', null, null);
}

// "Use my location" — reverse-geocode to fill the city input
document.getElementById('use-my-location').addEventListener('click', async function() {
    const btn   = this;
    const cityIn = document.getElementById('browse-city');
    const orig  = btn.textContent;
    btn.textContent = '📍 Locating…';
    btn.disabled = true;
    try {
        const loc = await SmartCartMaps.getCurrentLocation();
        // Reverse-geocode via Nominatim
        const res = await fetch('https://nominatim.openstreetmap.org/reverse?lat=' + loc.lat +
            '&lon=' + loc.lng + '&format=json&zoom=14&accept-language=en', {
            headers: { 'Accept': 'application/json' },
        });
        const data = await res.json();
        const addr = data.address || {};
        let city = addr.city || addr.town || addr.village || addr.suburb || addr.county || '';
        // Strip common OSM suffixes so DB fuzzy match works: "Tel Aviv-Yafo" → "Tel Aviv"
        city = city.replace(/[-,].*$/, '').trim();
        if (city) {
            cityIn.value = city;
            btn.textContent = '✓ ' + city;
            setTimeout(() => { btn.textContent = orig; btn.disabled = false; }, 1500);
            // Auto-submit to refresh results
            cityIn.form.submit();
        } else {
            throw new Error('Could not determine city');
        }
    } catch (err) {
        if (typeof SmartCart !== 'undefined') SmartCart.showToast(err.message, 'error');
        btn.textContent = orig;
        btn.disabled = false;
    }
});
</script>
