<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/functions.php';

$product_id = (int)($_GET['id'] ?? 0);
if (!$product_id) { header('Location: ' . APP_URL . '/pages/browse.php'); exit; }

$pdo = getPDO();
$stmt = $pdo->prepare("
    SELECT p.*, b.business_name, b.city AS biz_city, b.phone AS biz_phone, b.lat AS biz_lat, b.lng AS biz_lng,
           ROUND((p.price_ils - p.group_price_ils) / p.price_ils * 100) AS disc
    FROM products p JOIN businesses b ON b.id = p.business_id
    WHERE p.id = ? AND p.status = 'active'
");
$stmt->execute([$product_id]);
$product = $stmt->fetch();
if (!$product) { header('Location: ' . APP_URL . '/pages/browse.php'); exit; }

// Active groups for this product
$gstmt = $pdo->prepare("
    SELECT gp.*, ROUND(gp.current_participants / gp.target_participants * 100) AS fill_pct,
           u.full_name AS creator_name
    FROM group_purchases gp JOIN users u ON u.id = gp.creator_id
    WHERE gp.product_id = ? AND gp.status = 'open' AND gp.deadline > NOW()
    ORDER BY gp.created_at DESC
");
$gstmt->execute([$product_id]);
$groups = $gstmt->fetchAll();

$cat_icons = ['electronics'=>'💻','home'=>'🏠','fashion'=>'👗','food'=>'🍎','sports'=>'⚽','beauty'=>'💄','toys'=>'🧸','books'=>'📚','automotive'=>'🚗','other'=>'📦'];
$user_id   = current_user_id();
$page_title = $product['name'];
$needs_map  = true;
$biz_lat    = (float)($product['biz_lat'] ?? 0);
$biz_lng    = (float)($product['biz_lng'] ?? 0);

// Handle start group form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['target_participants']) && $user_id) {
    $target   = max((int)$product['min_participants'], (int)$_POST['target_participants']);
    $deadline = trim($_POST['deadline'] ?? '');
    if ($deadline && strtotime($deadline) > time()) {
        $lat  = $product['lat'] ?? $product['biz_lat'];
        $lng  = $product['lng'] ?? $product['biz_lng'];
        $city = $product['city'] ?? $product['biz_city'];
        $pdo->prepare("INSERT INTO group_purchases (product_id, creator_id, target_participants, deadline, city, lat, lng) VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$product_id, $user_id, $target, $deadline, $city, $lat, $lng]);
        $gid = $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO group_members (group_id, user_id) VALUES (?, ?)")->execute([$gid, $user_id]);
        $pdo->prepare("UPDATE group_purchases SET current_participants=1 WHERE id=?")->execute([$gid]);
        header('Location: ' . APP_URL . '/pages/group.php?id=' . $gid);
        exit;
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding-top:32px;padding-bottom:60px;">
    <a href="<?= APP_URL ?>/pages/browse.php" class="btn btn-ghost btn-sm" style="margin-bottom:16px;">← Back to Browse</a>

    <div style="display:grid;grid-template-columns:1fr 400px;gap:32px;align-items:start;">
        <!-- Image -->
        <div>
            <?php if ($product['image_url']): ?>
            <img src="<?= APP_URL . htmlspecialchars($product['image_url']) ?>" alt="<?= htmlspecialchars($product['name']) ?>"
                 style="width:100%;border-radius:var(--radius);max-height:420px;object-fit:cover;">
            <?php else: ?>
            <div style="width:100%;height:300px;background:var(--teal-50);border-radius:var(--radius);display:flex;align-items:center;justify-content:center;font-size:80px;">
                <?= $cat_icons[$product['category']] ?? '📦' ?>
            </div>
            <?php endif; ?>

            <?php if ($product['description']): ?>
            <div class="card" style="margin-top:20px;">
                <div class="card-body">
                    <h3 style="font-weight:600;margin-bottom:8px;">About this product</h3>
                    <p style="line-height:1.7;color:var(--gray-700);"><?= htmlspecialchars($product['description']) ?></p>
                </div>
            </div>
            <?php endif; ?>

            <!-- Business Location Map -->
            <?php if ($biz_lat && $biz_lng): ?>
            <div class="card" style="margin-top:20px;">
                <div class="card-header">
                    <strong>📍 <?= htmlspecialchars($product['business_name']) ?></strong>
                    <div style="font-size:12px;color:var(--gray-500);margin-top:2px;">
                        <?= htmlspecialchars($product['biz_city'] ?? '') ?>
                        <?php if ($product['biz_phone']): ?>
                        · <?= htmlspecialchars($product['biz_phone']) ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div id="map-product" style="height:220px;border-radius:0 0 var(--radius) var(--radius);overflow:hidden;"></div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Details & Groups -->
        <div>
            <div class="card" style="margin-bottom:20px;">
                <div class="card-body">
                    <div style="display:flex;gap:8px;margin-bottom:8px;">
                        <span class="card-badge badge-discount"><?= $product['disc'] ?>% off</span>
                        <span style="font-size:12px;color:var(--gray-500);"><?= $cat_icons[$product['category']] ?> <?= $t['cat_' . $product['category']] ?></span>
                    </div>
                    <h1 style="font-size:22px;font-weight:700;margin-bottom:6px;"><?= htmlspecialchars($product['name']) ?></h1>
                    <p style="font-size:13px;color:var(--gray-500);margin-bottom:16px;">
                        <?= $t['product_by'] ?> <?= htmlspecialchars($product['business_name']) ?> · <?= htmlspecialchars($product['biz_city'] ?? $product['city'] ?? '') ?>
                    </p>
                    <div style="display:flex;align-items:baseline;gap:12px;margin-bottom:16px;">
                        <span style="font-size:30px;font-weight:800;color:var(--teal);"><?= format_ils($product['group_price_ils']) ?></span>
                        <div>
                            <div style="font-size:13px;text-decoration:line-through;color:var(--gray-500);"><?= format_ils($product['price_ils']) ?></div>
                            <div style="font-size:12px;color:var(--success);font-weight:600;">
                                Save <?= format_ils($product['price_ils'] - $product['group_price_ils']) ?>
                            </div>
                        </div>
                    </div>
                    <div style="font-size:13px;color:var(--gray-600);display:flex;gap:16px;flex-wrap:wrap;">
                        <span>👥 Min <?= $product['min_participants'] ?> members</span>
                        <span>📍 <?= htmlspecialchars($product['city'] ?? $product['biz_city'] ?? '') ?></span>
                    </div>
                </div>
            </div>

            <!-- Active Groups -->
            <h3 style="font-size:16px;font-weight:600;margin-bottom:12px;"><?= $t['product_active_groups'] ?> (<?= count($groups) ?>)</h3>
            <?php if (empty($groups)): ?>
            <div style="text-align:center;padding:28px;background:white;border-radius:var(--radius);box-shadow:var(--shadow);margin-bottom:12px;">
                <div style="font-size:32px;margin-bottom:8px;">🚀</div>
                <p style="color:var(--gray-500);margin-bottom:12px;"><?= $t['product_no_groups'] ?> <?= $t['product_be_first'] ?></p>
            </div>
            <?php else: ?>
            <?php foreach ($groups as $g):
                $fill = (int)$g['fill_pct'];
                $fill_class = $fill >= 80 ? 'high' : ($fill >= 50 ? 'medium' : '');
            ?>
            <div class="card" style="margin-bottom:10px;" onclick="window.location='<?= APP_URL ?>/pages/group.php?id=<?= $g['id'] ?>'">
                <div class="card-body">
                    <div style="display:flex;justify-content:space-between;margin-bottom:8px;">
                        <span style="font-size:13px;color:var(--gray-600);">by <?= htmlspecialchars($g['creator_name']) ?></span>
                        <span style="color:<?= days_until($g['deadline']) <= 3 ? 'var(--danger)' : 'var(--gray-500)' ?>;font-size:12px;">⏰ <?= countdown_label($g['deadline']) ?></span>
                    </div>
                    <div class="progress-wrap" style="margin-bottom:4px;">
                        <div class="progress-bar <?= $fill_class ?>" style="width:<?= $fill ?>%;"></div>
                    </div>
                    <div class="progress-label">
                        <span><?= $g['current_participants'] ?>/<?= $g['target_participants'] ?> members</span>
                        <span><?= $fill ?>% full</span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>

            <!-- Start Group Button -->
            <?php if ($user_id): ?>
            <button class="btn btn-primary btn-full" onclick="document.getElementById('start-group-modal').classList.add('open')">
                + <?= $t['product_start_group'] ?>
            </button>
            <?php else: ?>
            <a href="<?= APP_URL ?>/pages/login.php" class="btn btn-outline btn-full">Login to Start a Group</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Start Group Modal -->
<div class="modal-overlay" id="start-group-modal">
    <div class="modal">
        <h2 class="modal-title"><?= $t['group_start_modal_title'] ?></h2>
        <form method="POST">
            <div class="form-group">
                <label class="form-label"><?= $t['group_target_members'] ?></label>
                <input type="number" name="target_participants" class="form-control"
                       min="<?= $product['min_participants'] ?>" value="<?= max(5, $product['min_participants']) ?>" required>
                <div class="form-hint">Minimum: <?= $product['min_participants'] ?> members</div>
            </div>
            <div class="form-group">
                <label class="form-label"><?= $t['group_deadline_label'] ?></label>
                <input type="datetime-local" name="deadline" class="form-control"
                       min="<?= date('Y-m-d\TH:i', strtotime('+1 day')) ?>"
                       value="<?= date('Y-m-d\TH:i', strtotime('+7 days')) ?>" required>
            </div>
            <div style="display:flex;gap:8px;">
                <button type="submit" class="btn btn-primary"><?= $t['group_create_btn'] ?></button>
                <button type="button" class="btn btn-ghost" data-action="close-modal">Cancel</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<script>
(function() {
    var lat = <?= $biz_lat ?>;
    var lng = <?= $biz_lng ?>;
    if (!lat || !lng || typeof SmartCartMaps === 'undefined') return;
    var map = SmartCartMaps.createMap('map-product', lat, lng, 15);
    if (!map) return;
    SmartCartMaps.addMarker(
        map, lat, lng,
        '<strong><?= htmlspecialchars($product['business_name']) ?></strong><br><?= htmlspecialchars($product['biz_city'] ?? '') ?>'
    );
})();
</script>
