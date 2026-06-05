<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/lang.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/functions.php';

require_role('business');
$user_id    = current_user_id();
$page_title = 'Business Dashboard';
$needs_map  = false;

$pdo = getPDO();

$bstmt = $pdo->prepare("SELECT * FROM businesses WHERE user_id = ?");
$bstmt->execute([$user_id]);
$business = $bstmt->fetch();
if (!$business) {
    $_SESSION['flash_error'] = 'Business profile not found.';
    header('Location: ' . APP_URL . '/pages/index.php'); exit;
}
$biz_id = $business['id'];

// Stats
$stats = [];
$stats['active_deals'] = (int)$pdo->prepare("SELECT COUNT(*) FROM group_purchases gp JOIN products p ON p.id = gp.product_id WHERE p.business_id = ? AND gp.status = 'open'")->execute([$biz_id]) ? $pdo->prepare("SELECT COUNT(*) FROM group_purchases gp JOIN products p ON p.id = gp.product_id WHERE p.business_id = ? AND gp.status = 'open'")->execute([$biz_id]) : 0;

$s = $pdo->prepare("SELECT COUNT(*) FROM group_purchases gp JOIN products p ON p.id = gp.product_id WHERE p.business_id = ? AND gp.status='open'"); $s->execute([$biz_id]); $stats['active_deals'] = (int)$s->fetchColumn();
$s = $pdo->prepare("SELECT COUNT(*) FROM group_purchases gp JOIN products p ON p.id = gp.product_id WHERE p.business_id = ? AND gp.status='closed'"); $s->execute([$biz_id]); $stats['closed_deals'] = (int)$s->fetchColumn();
$s = $pdo->prepare("SELECT COUNT(*) FROM orders o JOIN products p ON p.id = o.product_id WHERE p.business_id = ?"); $s->execute([$biz_id]); $stats['orders'] = (int)$s->fetchColumn();
$s = $pdo->prepare("SELECT COALESCE(SUM(o.amount_paid),0) FROM orders o JOIN products p ON p.id = o.product_id WHERE p.business_id = ?"); $s->execute([$biz_id]); $stats['revenue'] = (float)$s->fetchColumn();
$s = $pdo->prepare("SELECT COUNT(DISTINCT gm.user_id) FROM group_members gm JOIN group_purchases gp ON gp.id = gm.group_id JOIN products p ON p.id = gp.product_id WHERE p.business_id = ?"); $s->execute([$biz_id]); $stats['buyers'] = (int)$s->fetchColumn();

// All deals: product + latest group_purchase per product
$deals_stmt = $pdo->prepare("
    SELECT
        p.id AS product_id, p.name AS product_name, p.image_url, p.category,
        p.price_ils, p.group_price_ils, p.min_participants, p.status AS product_status,
        p.created_at AS product_created,
        ROUND((p.price_ils - p.group_price_ils) / p.price_ils * 100) AS disc,
        gp.id AS group_id, gp.status AS group_status,
        gp.current_participants, gp.target_participants, gp.deadline,
        ROUND(COALESCE(gp.current_participants,0) / GREATEST(gp.target_participants,1) * 100) AS fill_pct,
        TIMESTAMPDIFF(SECOND, NOW(), gp.deadline) AS secs_left
    FROM products p
    LEFT JOIN group_purchases gp ON gp.id = (
        SELECT id FROM group_purchases WHERE product_id = p.id ORDER BY created_at DESC LIMIT 1
    )
    WHERE p.business_id = ? AND p.status = 'active'
    ORDER BY
        CASE WHEN gp.status = 'open' THEN 0 WHEN gp.status = 'closed' THEN 1 ELSE 2 END,
        gp.deadline ASC,
        p.created_at DESC
");
$deals_stmt->execute([$biz_id]);
$deals = $deals_stmt->fetchAll();

$open_deals   = array_filter($deals, fn($d) => ($d['group_status'] ?? '') === 'open');
$closed_deals = array_filter($deals, fn($d) => ($d['group_status'] ?? '') === 'closed');
$other_deals  = array_filter($deals, fn($d) => !in_array($d['group_status'] ?? '', ['open','closed']));

// Orders
$orders_stmt = $pdo->prepare("
    SELECT o.*, p.name AS product_name, u.full_name AS customer_name, u.email AS customer_email,
           gp.id AS group_id
    FROM orders o
    JOIN products p ON p.id = o.product_id
    JOIN users u ON u.id = o.user_id
    JOIN group_purchases gp ON gp.id = o.group_id
    WHERE p.business_id = ?
    ORDER BY o.created_at DESC LIMIT 50
");
$orders_stmt->execute([$biz_id]);
$orders = $orders_stmt->fetchAll();

$cat_icons = ['electronics'=>'💻','home'=>'🏠','fashion'=>'👗','food'=>'🍎','sports'=>'⚽','beauty'=>'💄','toys'=>'🧸','books'=>'📚','automotive'=>'🚗','other'=>'📦'];

include __DIR__ . '/../../includes/header.php';
?>

<!-- Business Dashboard -->
<div style="background:var(--btn-gradient);padding:28px 0 0;margin-bottom:0;">
    <div class="container">
        <div style="display:flex;justify-content:space-between;align-items:flex-end;padding-bottom:20px;">
            <div style="color:white;">
                <p style="font-size:12px;opacity:.7;margin-bottom:2px;text-transform:uppercase;letter-spacing:.08em;">Business Dashboard</p>
                <h1 style="font-size:26px;font-weight:800;margin-bottom:4px;"><?= htmlspecialchars($business['business_name']) ?></h1>
                <p style="opacity:.75;font-size:14px;">
                    <?= $cat_icons[$business['category']] ?? '📦' ?>
                    <?= $t['cat_' . $business['category']] ?>
                    <?php if ($business['city']): ?> · <?= htmlspecialchars($business['city']) ?><?php endif; ?>
                </p>
            </div>
            <div style="display:flex;gap:8px;">
                <a href="<?= APP_URL ?>/pages/business/profile.php" class="btn" style="background:rgba(255,255,255,.15);color:white;border:1.5px solid rgba(255,255,255,.4);backdrop-filter:blur(6px);font-weight:600;padding:10px 16px;border-radius:10px;text-decoration:none;transition:all .15s;" onmouseover="this.style.background='rgba(255,255,255,.25)'" onmouseout="this.style.background='rgba(255,255,255,.15)'">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-2px;margin-right:6px;"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                    Settings
                </a>
                <button class="btn" onclick="openDealModal()" style="background:rgba(255,255,255,.2);color:white;border:1.5px solid rgba(255,255,255,.4);backdrop-filter:blur(6px);font-weight:600;padding:10px 20px;border-radius:10px;cursor:pointer;transition:all .15s;" onmouseover="this.style.background='rgba(255,255,255,.3)'" onmouseout="this.style.background='rgba(255,255,255,.2)'">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-2px;margin-right:6px;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    New Deal
                </button>
            </div>
        </div>

        <!-- Stats -->
        <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:1px;background:rgba(255,255,255,.15);border-radius:12px 12px 0 0;overflow:hidden;">
            <?php foreach ([
                ['value' => $stats['active_deals'], 'label' => 'Live Deals',    'color' => '#7fff9a'],
                ['value' => $stats['closed_deals'], 'label' => 'Closed Deals',  'color' => '#89d4ff'],
                ['value' => $stats['buyers'],       'label' => 'Total Buyers',  'color' => '#ffd700'],
                ['value' => $stats['orders'],       'label' => 'Orders',        'color' => '#ff9eb5'],
                ['value' => format_ils($stats['revenue']), 'label' => 'Revenue','color' => '#b5f7e0'],
            ] as $stat): ?>
            <div style="background:rgba(255,255,255,.08);padding:16px;text-align:center;">
                <div style="font-size:22px;font-weight:800;color:<?= $stat['color'] ?>;"><?= $stat['value'] ?></div>
                <div style="font-size:11px;color:rgba(255,255,255,.65);margin-top:2px;text-transform:uppercase;letter-spacing:.06em;"><?= $stat['label'] ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Tabs -->
<div class="container" style="padding-top:0;padding-bottom:60px;">
    <div class="tabs" style="border-bottom:1px solid var(--border);background:white;margin:0 0 28px;padding:0;border-radius:0 0 0 0;position:sticky;top:var(--nav-height);z-index:100;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <button class="tab-btn active" data-tab="deals" style="padding:16px 24px;">🏷️ Live Deals <span style="background:var(--purple);color:white;font-size:11px;padding:2px 7px;border-radius:999px;margin-left:6px;font-weight:700;"><?= count($open_deals) ?></span></button>
        <button class="tab-btn" data-tab="products" style="padding:16px 24px;">📦 My Products <span style="background:var(--gray-200);color:var(--gray-700);font-size:11px;padding:2px 7px;border-radius:999px;margin-left:6px;font-weight:700;"><?= count($deals) ?></span></button>
        <button class="tab-btn" data-tab="orders" style="padding:16px 24px;">🧾 Orders <span style="background:var(--gray-200);color:var(--gray-700);font-size:11px;padding:2px 7px;border-radius:999px;margin-left:6px;font-weight:700;"><?= count($orders) ?></span></button>
    </div>

    <!-- TAB: LIVE DEALS -->
    <div class="tab-panel active" id="tab-deals">

        <?php if (empty($deals)): ?>
        <div class="empty-state" style="padding:80px 20px;">
            <div style="font-size:64px;margin-bottom:16px;">🏷️</div>
            <h3>No deals yet</h3>
            <p style="color:var(--gray-500);margin-bottom:20px;">Create your first group deal and start selling!</p>
            <button class="btn btn-primary btn-lg" onclick="openDealModal()">+ Create First Deal</button>
        </div>
        <?php else: ?>

        <!-- Open deals -->
        <?php if (!empty($open_deals)): ?>
        <h3 style="font-size:14px;font-weight:700;color:var(--gray-500);text-transform:uppercase;letter-spacing:.08em;margin-bottom:16px;">Live Now</h3>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:20px;margin-bottom:36px;">
            <?php foreach ($open_deals as $d):
                $more_needed = max(0, (int)$d['target_participants'] - (int)$d['current_participants']);
                $fill = (int)$d['fill_pct'];
                $secs = max(0, (int)$d['secs_left']);
                $urgent = $secs < 86400;
            ?>
            <div class="deal-card" style="background:white;border:<?= $urgent ? '2px solid var(--danger)' : '1px solid var(--border)' ?>;border-radius:16px;overflow:hidden;box-shadow:var(--shadow);">
                <!-- Image -->
                <?php if ($d['image_url']): ?>
                <div style="height:160px;background:url('<?= img_url($d['image_url']) ?>') center/cover no-repeat;position:relative;">
                <?php else: ?>
                <div style="height:160px;background:var(--purple-50);display:flex;align-items:center;justify-content:center;font-size:56px;position:relative;">
                    <?= $cat_icons[$d['category']] ?? '📦' ?>
                <?php endif; ?>
                    <span style="position:absolute;top:10px;left:10px;background:<?= $urgent ? 'var(--danger)' : 'var(--green)' ?>;color:white;font-size:11px;font-weight:700;padding:4px 10px;border-radius:999px;"><?= $urgent ? '🔥 Last chance' : '✓ Open' ?></span>
                    <span style="position:absolute;top:10px;right:10px;background:rgba(0,0,0,.55);color:white;font-size:11px;font-weight:700;padding:4px 10px;border-radius:999px;"><?= $d['disc'] ?>% OFF</span>
                </div>

                <div style="padding:16px;">
                    <!-- Title & Category -->
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px;">
                        <h3 style="font-size:15px;font-weight:700;color:var(--gray-900);line-height:1.3;flex:1;margin-right:8px;"><?= htmlspecialchars($d['product_name']) ?></h3>
                        <span style="font-size:11px;background:var(--purple-50);color:var(--purple);padding:3px 8px;border-radius:999px;white-space:nowrap;"><?= $cat_icons[$d['category']] ?> <?= $t['cat_' . $d['category']] ?></span>
                    </div>

                    <!-- Pricing -->
                    <div style="display:flex;align-items:baseline;gap:10px;margin-bottom:14px;">
                        <span style="font-size:24px;font-weight:800;color:var(--purple);"><?= format_ils($d['group_price_ils']) ?></span>
                        <span style="font-size:13px;text-decoration:line-through;color:var(--gray-400);"><?= format_ils($d['price_ils']) ?></span>
                        <span style="font-size:12px;color:var(--green);font-weight:600;">Save <?= format_ils($d['price_ils'] - $d['group_price_ils']) ?></span>
                    </div>

                    <!-- Progress -->
                    <div style="margin-bottom:8px;">
                        <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:5px;">
                            <span style="font-weight:600;color:var(--gray-700);"><?= $d['current_participants'] ?> / <?= $d['target_participants'] ?> participants</span>
                            <span style="color:<?= $more_needed === 0 ? 'var(--green)' : 'var(--gray-500)' ?>;font-weight:600;">
                                <?= $more_needed === 0 ? '✓ Goal met!' : $more_needed . ' more to close' ?>
                            </span>
                        </div>
                        <div style="height:8px;background:var(--gray-200);border-radius:999px;overflow:hidden;">
                            <div style="height:100%;width:<?= min(100, $fill) ?>%;background:<?= $fill >= 80 ? 'var(--green)' : ($fill >= 50 ? 'var(--orange)' : 'var(--purple)') ?>;border-radius:999px;transition:width .3s;"></div>
                        </div>
                    </div>

                    <!-- Countdown -->
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
                        <span class="deal-countdown" data-secs="<?= $secs ?>" style="font-size:13px;font-weight:700;color:<?= $urgent ? 'var(--danger)' : 'var(--gray-600)' ?>;font-variant-numeric:tabular-nums;">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-1px;"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            <?= countdown_label($d['deadline']) ?>
                        </span>
                        <span style="font-size:11px;color:var(--gray-400);">Deadline: <?= date('d/m/Y H:i', strtotime($d['deadline'])) ?></span>
                    </div>

                    <!-- Actions -->
                    <div style="display:flex;gap:8px;">
                        <?php if ($d['group_id']): ?>
                        <a href="<?= APP_URL ?>/pages/group.php?id=<?= $d['group_id'] ?>" class="btn btn-outline btn-sm" style="flex:1;text-align:center;">View Group</a>
                        <?php endif; ?>
                        <button class="btn btn-ghost btn-sm" onclick="editDeal(<?= htmlspecialchars(json_encode($d)) ?>)" style="flex:1;">Edit</button>
                        <?php if ($d['group_id'] && $d['current_participants'] >= $d['target_participants']): ?>
                        <button class="btn btn-sm" onclick="closeEarly(<?= $d['group_id'] ?>)" style="flex:1;background:var(--green);color:white;">Close Now</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Closed deals -->
        <?php if (!empty($closed_deals)): ?>
        <h3 style="font-size:14px;font-weight:700;color:var(--gray-500);text-transform:uppercase;letter-spacing:.08em;margin-bottom:16px;">Closed — Awaiting Shipment</h3>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px;margin-bottom:36px;">
            <?php foreach ($closed_deals as $d): ?>
            <div style="background:white;border:1px solid #DBEAFE;border-radius:12px;padding:16px;box-shadow:var(--shadow);">
                <div style="display:flex;align-items:center;gap:12px;">
                    <div style="width:48px;height:48px;border-radius:10px;background:var(--purple-50);display:flex;align-items:center;justify-content:center;font-size:24px;flex-shrink:0;"><?= $cat_icons[$d['category']] ?? '📦' ?></div>
                    <div style="flex:1;min-width:0;">
                        <div style="font-weight:600;font-size:14px;color:var(--gray-900);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($d['product_name']) ?></div>
                        <div style="font-size:12px;color:var(--gray-500);"><?= $d['current_participants'] ?> buyers · <?= format_ils($d['group_price_ils']) ?> each</div>
                    </div>
                    <span style="background:#DBEAFE;color:#1D4ED8;font-size:11px;font-weight:700;padding:4px 10px;border-radius:999px;white-space:nowrap;">Closed</span>
                </div>
                <?php if ($d['group_id']): ?>
                <a href="<?= APP_URL ?>/pages/group.php?id=<?= $d['group_id'] ?>" class="btn btn-outline btn-sm btn-full" style="margin-top:12px;">View & Manage</a>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Past/failed deals -->
        <?php if (!empty($other_deals)): ?>
        <h3 style="font-size:14px;font-weight:700;color:var(--gray-500);text-transform:uppercase;letter-spacing:.08em;margin-bottom:16px;">Past Deals</h3>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px;">
            <?php foreach ($other_deals as $d): ?>
            <div class="deal-card" style="background:white;border:1px solid var(--border);border-radius:10px;padding:14px;display:flex;align-items:center;gap:12px;opacity:.7;">
                <div style="font-size:24px;"><?= $cat_icons[$d['category']] ?? '📦' ?></div>
                <div style="flex:1;min-width:0;">
                    <div style="font-weight:500;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($d['product_name']) ?></div>
                    <div style="font-size:11px;color:var(--gray-400);">
                        <?= $d['group_status'] ?? ($d['product_status'] === 'inactive' ? 'Inactive' : 'No group') ?>
                    </div>
                </div>
                <button class="btn btn-ghost btn-sm" onclick="relaunchDeal(<?= htmlspecialchars(json_encode($d)) ?>)" title="Relaunch this deal">↻ Relaunch</button>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php endif; ?>
    </div>

    <!-- TAB: MY PRODUCTS -->
    <div class="tab-panel" id="tab-products">
        <?php if (empty($deals)): ?>
        <div class="empty-state" style="padding:80px 20px;">
            <div style="font-size:64px;margin-bottom:16px;">📦</div>
            <h3>No products yet</h3>
            <p style="color:var(--gray-500);margin-bottom:20px;">Create your first deal to add a product.</p>
            <button class="btn btn-primary btn-lg" onclick="openDealModal()">+ Add Product</button>
        </div>
        <?php else: ?>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <p style="color:var(--gray-500);font-size:14px;"><?= count($deals) ?> product<?= count($deals) !== 1 ? 's' : '' ?> total</p>
            <button class="btn btn-primary btn-sm" onclick="openDealModal()">+ Add Product</button>
        </div>
        <div class="card" style="overflow:hidden;">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th style="width:44px;"></th>
                            <th>Product</th>
                            <th>Prices</th>
                            <th>Status</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($deals as $d):
                        $is_active = ($d['product_status'] ?? 'active') === 'active';
                        $gs = $d['group_status'] ?? null;
                        if ($gs === 'open') { $status_label = 'Live'; $status_color = 'var(--green)'; }
                        elseif ($gs === 'closed' || $gs === 'ready_for_payment') { $status_label = 'Closed'; $status_color = '#1D4ED8'; }
                        elseif ($gs === 'order_ready') { $status_label = 'Order Ready'; $status_color = 'var(--green)'; }
                        elseif ($gs === 'failed') { $status_label = 'Failed'; $status_color = 'var(--danger)'; }
                        elseif (!$is_active) { $status_label = 'Inactive'; $status_color = 'var(--gray-400)'; }
                        else { $status_label = 'No group'; $status_color = 'var(--gray-400)'; }
                    ?>
                    <tr id="product-row-<?= (int)$d['product_id'] ?>">
                        <td>
                            <?php if ($d['image_url']): ?>
                            <img src="<?= img_url($d['image_url']) ?>" alt="" style="width:40px;height:40px;border-radius:8px;object-fit:cover;">
                            <?php else: ?>
                            <div style="width:40px;height:40px;border-radius:8px;background:var(--purple-50);display:flex;align-items:center;justify-content:center;font-size:20px;"><?= $cat_icons[$d['category']] ?? '📦' ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="font-weight:600;font-size:14px;"><?= htmlspecialchars($d['product_name']) ?></div>
                            <div style="font-size:11px;color:var(--gray-400);"><?= $cat_icons[$d['category']] ?? '' ?> <?= $t['cat_' . $d['category']] ?> <?= $d['city'] ? '· ' . htmlspecialchars($d['city']) : '' ?></div>
                        </td>
                        <td>
                            <div style="font-weight:700;color:var(--purple);"><?= format_ils($d['group_price_ils']) ?></div>
                            <div style="font-size:11px;text-decoration:line-through;color:var(--gray-400);"><?= format_ils($d['price_ils']) ?></div>
                        </td>
                        <td>
                            <span style="background:<?= $status_color ?>20;color:<?= $status_color ?>;font-size:11px;font-weight:700;padding:4px 10px;border-radius:999px;">
                                <?= $status_label ?>
                            </span>
                        </td>
                        <td style="text-align:right;">
                            <div style="display:flex;gap:8px;justify-content:flex-end;">
                                <?php if ($d['group_id']): ?>
                                <a href="<?= APP_URL ?>/pages/group.php?id=<?= (int)$d['group_id'] ?>" class="btn btn-outline btn-sm">View</a>
                                <?php endif; ?>
                                <button class="btn btn-ghost btn-sm" onclick="editDeal(<?= htmlspecialchars(json_encode($d)) ?>)">Edit</button>
                                <button class="btn btn-sm"
                                        data-action="delete-product"
                                        data-id="<?= (int)$d['product_id'] ?>"
                                        style="background:var(--danger);color:white;border-color:var(--danger);">
                                    🗑 Delete
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- TAB: ORDERS -->
    <div class="tab-panel" id="tab-orders">
        <?php if (empty($orders)): ?>
        <div class="empty-state">
            <div style="font-size:48px;margin-bottom:12px;">📦</div>
            <h3>No orders yet</h3>
            <p style="color:var(--gray-500);">Orders appear here once group deals close and buyers pay.</p>
        </div>
        <?php else: ?>
        <div class="card">
            <div class="card-body" style="padding:0;">
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Order</th>
                                <th>Customer</th>
                                <th>Amount</th>
                                <th>Shipping Status</th>
                                <th>Date</th>
                                <th>Update</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($orders as $o): ?>
                        <tr>
                            <td>
                                <div style="font-weight:500;font-size:13px;"><?= htmlspecialchars($o['product_name']) ?></div>
                                <?php if ($o['tracking_id']): ?>
                                <div style="font-size:11px;color:var(--gray-400);">Tracking: <?= htmlspecialchars($o['tracking_id']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="font-size:13px;"><?= htmlspecialchars($o['customer_name']) ?></div>
                                <div style="font-size:11px;color:var(--gray-400);"><?= htmlspecialchars($o['customer_email']) ?></div>
                            </td>
                            <td style="font-weight:600;color:var(--purple);"><?= format_ils($o['amount_paid']) ?></td>
                            <td>
                                <?php
                                $sc = ['pending'=>'var(--orange)','processing'=>'var(--info)','shipped'=>'var(--purple)','delivered'=>'var(--green)'];
                                ?>
                                <span style="background:<?= $sc[$o['shipping_status']] ?? 'var(--gray-300)' ?>20;color:<?= $sc[$o['shipping_status']] ?? 'var(--gray-500)' ?>;font-size:11px;font-weight:700;padding:4px 10px;border-radius:999px;text-transform:capitalize;">
                                    <?= $t['shipping_' . $o['shipping_status']] ?>
                                </span>
                            </td>
                            <td style="font-size:12px;color:var(--gray-400);"><?= date('d/m/Y', strtotime($o['created_at'])) ?></td>
                            <td>
                                <select class="form-control" style="font-size:12px;padding:4px 8px;width:auto;" onchange="updateShipping(<?= $o['id'] ?>, this.value)">
                                    <?php foreach (['pending','processing','shipped','delivered'] as $s): ?>
                                    <option value="<?= $s ?>" <?= $o['shipping_status']===$s?'selected':''?>><?= $t['shipping_'.$s] ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

</div>

<!-- New / Edit Deal Modal -->
<div class="modal-overlay" id="deal-modal">
    <div class="modal" style="max-width:640px;max-height:90vh;overflow-y:auto;">
        <h2 class="modal-title" id="deal-modal-title">Create a New Deal</h2>

        <form id="deal-form">
            <input type="hidden" id="deal-product-id" name="product_id" value="">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <!-- Product Name (full width) -->
                <div class="form-group" style="grid-column:1/-1;">
                    <label class="form-label">Product Name <span style="color:var(--danger);">*</span></label>
                    <input type="text" name="name" id="df-name" class="form-control" required placeholder="e.g. Samsung 65&quot; 4K TV">
                </div>

                <!-- Description (full width) -->
                <div class="form-group" style="grid-column:1/-1;">
                    <label class="form-label">Description</label>
                    <textarea name="description" id="df-desc" class="form-control" rows="2" placeholder="What makes this deal special?"></textarea>
                </div>

                <!-- Original Price -->
                <div class="form-group">
                    <label class="form-label">Original Price (₪) <span style="color:var(--danger);">*</span></label>
                    <input type="number" name="price_ils" id="df-price" class="form-control" step="0.01" min="1" required placeholder="5000" oninput="updateDiscount()">
                </div>

                <!-- Group Price with live discount preview -->
                <div class="form-group">
                    <label class="form-label">Group Deal Price (₪) <span style="color:var(--danger);">*</span></label>
                    <div style="position:relative;">
                        <input type="number" name="group_price_ils" id="df-gprice" class="form-control" step="0.01" min="1" required placeholder="3500" oninput="updateDiscount()" style="padding-right:70px;">
                        <span id="discount-preview" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);font-size:12px;font-weight:700;color:var(--green);"></span>
                    </div>
                </div>

                <!-- Min Participants -->
                <div class="form-group">
                    <label class="form-label">Minimum Buyers to Close <span style="color:var(--danger);">*</span></label>
                    <input type="number" name="min_participants" id="df-min" class="form-control" min="2" value="5" required>
                    <div class="form-hint">Deal locks automatically when reached</div>
                </div>

                <!-- Deadline -->
                <div class="form-group">
                    <label class="form-label">Deal Deadline <span style="color:var(--danger);">*</span></label>
                    <input type="datetime-local" name="deadline" id="df-deadline" class="form-control" required>
                    <div class="form-hint">After this date, unmet deals are cancelled</div>
                </div>

                <!-- Category -->
                <div class="form-group">
                    <label class="form-label">Category</label>
                    <select name="category" id="df-cat" class="form-control">
                        <?php foreach (['electronics','home','fashion','food','sports','beauty','toys','books','automotive','other'] as $cat): ?>
                        <option value="<?= $cat ?>"><?= $cat_icons[$cat] ?> <?= $t['cat_' . $cat] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- City -->
                <div class="form-group">
                    <label class="form-label">City</label>
                    <input type="text" name="city" id="df-city" class="form-control"
                           value="<?= htmlspecialchars($business['city'] ?? '') ?>"
                           autocomplete="off" placeholder="Tel Aviv, Haifa...">
                    <input type="hidden" id="df-lat" name="lat" value="<?= $business['lat'] ?? '' ?>">
                    <input type="hidden" id="df-lng" name="lng" value="<?= $business['lng'] ?? '' ?>">
                </div>

                <!-- Image (full width) -->
                <div class="form-group" style="grid-column:1/-1;">
                    <label class="form-label">Product Image</label>
                    <div style="display:flex;gap:10px;align-items:flex-start;">
                        <div style="flex:1;">
                            <input type="file" id="df-img-file" accept="image/jpeg,image/png,image/webp" style="display:block;margin-bottom:8px;">
                            <input type="url" name="image_url" id="df-img" class="form-control" placeholder="https://example.com/product.jpg (or upload above)">
                            <div class="form-hint">Upload a file (max 5MB) or paste a public image URL.</div>
                        </div>
                        <img id="df-img-preview" src="" alt="" style="display:none;width:80px;height:80px;border-radius:8px;object-fit:cover;border:1px solid var(--border);">
                    </div>
                </div>
            </div>

            <div style="display:flex;gap:10px;margin-top:8px;">
                <button type="submit" class="btn btn-primary" style="flex:1;" id="deal-submit-btn">Create Deal</button>
                <button type="button" class="btn btn-ghost" onclick="closeDealModal()">Cancel</button>
            </div>

            <!-- Delete — only shown when editing an existing product -->
            <div id="deal-delete-wrap" style="display:none;margin-top:16px;padding-top:16px;border-top:1px solid var(--border);">
                <button type="button"
                        id="deal-delete-btn"
                        data-action="delete-product"
                        data-id=""
                        class="btn btn-full"
                        style="background:transparent;color:var(--danger);border:1.5px solid var(--danger);font-weight:600;">
                    🗑 Delete this product
                </button>
            </div>
        </form>
    </div>
</div>

<?php
// Load maps only for profile tab (city autocomplete)
$needs_map = true;
include __DIR__ . '/../../includes/footer.php';
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="<?= APP_URL ?>/assets/js/maps.js"></script>

<script>
// ── Tabs ─────────────────────────────────────────────────
document.querySelectorAll('.tab-btn[data-tab]').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
        this.classList.add('active');
        document.getElementById('tab-' + this.dataset.tab).classList.add('active');
    });
});

// ── Live Countdown Timers ─────────────────────────────────
(function() {
    const timers = document.querySelectorAll('.deal-countdown[data-secs]');
    if (!timers.length) return;
    const items = Array.from(timers).map(el => ({
        el, secs: parseInt(el.dataset.secs, 10), start: Date.now()
    }));
    function fmt(s) {
        if (s <= 0) return '⏰ Expired';
        const d = Math.floor(s / 86400);
        const h = Math.floor((s % 86400) / 3600);
        const m = Math.floor((s % 3600) / 60);
        const sec = s % 60;
        if (d > 1) return `${d}d ${h}h left`;
        if (d === 1) return `1d ${h}h left`;
        if (h > 0)  return `${h}h ${String(m).padStart(2,'0')}m left`;
        return `${String(m).padStart(2,'0')}:${String(sec).padStart(2,'0')} left`;
    }
    function tick() {
        const now = Date.now();
        items.forEach(t => {
            const elapsed = Math.floor((now - t.start) / 1000);
            const remaining = t.secs - elapsed;
            t.el.innerHTML = `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-1px;"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> ${fmt(remaining)}`;
        });
    }
    tick();
    setInterval(tick, 1000);
})();

// ── Discount Preview ──────────────────────────────────────
function updateDiscount() {
    const price  = parseFloat(document.getElementById('df-price').value) || 0;
    const gprice = parseFloat(document.getElementById('df-gprice').value) || 0;
    const el = document.getElementById('discount-preview');
    if (price > 0 && gprice > 0 && gprice < price) {
        const disc = Math.round((price - gprice) / price * 100);
        el.textContent = disc + '% off';
        el.style.color = disc >= 20 ? 'var(--green)' : 'var(--orange)';
    } else {
        el.textContent = '';
    }
}

// ── Deal Modal ────────────────────────────────────────────
function openDealModal() {
    document.getElementById('deal-modal-title').textContent = 'Create a New Deal';
    document.getElementById('deal-product-id').value = '';
    document.getElementById('deal-form').reset();
    document.getElementById('deal-submit-btn').textContent = 'Create Deal';
    document.getElementById('deal-delete-wrap').style.display = 'none';
    // Set default deadline to +30 days
    const dt = new Date(Date.now() + 30 * 86400 * 1000);
    dt.setMinutes(dt.getMinutes() - dt.getTimezoneOffset());
    document.getElementById('df-deadline').value = dt.toISOString().slice(0,16);
    document.getElementById('deal-modal').classList.add('open');
}

function closeDealModal() {
    document.getElementById('deal-modal').classList.remove('open');
}

function editDeal(d) {
    document.getElementById('deal-modal-title').textContent = 'Edit Deal';
    document.getElementById('deal-product-id').value = d.product_id;
    document.getElementById('df-name').value   = d.product_name;
    document.getElementById('df-desc').value   = '';
    document.getElementById('df-price').value  = d.price_ils;
    document.getElementById('df-gprice').value = d.group_price_ils;
    document.getElementById('df-cat').value    = d.category;
    document.getElementById('df-min').value    = d.min_participants || d.target_participants;
    document.getElementById('df-img').value    = d.image_url || '';
    if (d.deadline) {
        const dt = new Date(d.deadline.replace(' ','T'));
        dt.setMinutes(dt.getMinutes() - dt.getTimezoneOffset());
        document.getElementById('df-deadline').value = dt.toISOString().slice(0,16);
    }
    document.getElementById('deal-submit-btn').textContent = 'Save Changes';
    // Show delete button when editing an existing product
    document.getElementById('deal-delete-btn').dataset.id = d.product_id;
    document.getElementById('deal-delete-wrap').style.display = 'block';
    document.getElementById('deal-modal').classList.add('open');
    updateDiscount();
}

function relaunchDeal(d) {
    editDeal(d);
    document.getElementById('deal-product-id').value = '';
    document.getElementById('deal-modal-title').textContent = 'Relaunch Deal';
    document.getElementById('deal-submit-btn').textContent = 'Relaunch';
    document.getElementById('deal-delete-wrap').style.display = 'none';
    // Reset deadline to +30 days
    const dt = new Date(Date.now() + 30 * 86400 * 1000);
    dt.setMinutes(dt.getMinutes() - dt.getTimezoneOffset());
    document.getElementById('df-deadline').value = dt.toISOString().slice(0,16);
}

// Close modal on overlay click
document.getElementById('deal-modal').addEventListener('click', function(e) {
    if (e.target === this) closeDealModal();
});

// ── Deal Form Submit ──────────────────────────────────────
document.getElementById('deal-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    const fd   = new FormData(this);
    const data = Object.fromEntries(fd);
    const pid  = data.product_id;
    delete data.product_id;

    const btn = document.getElementById('deal-submit-btn');
    btn.disabled = true;
    btn.textContent = 'Saving...';

    try {
        if (pid) {
            await SmartCart.api(`<?= APP_URL ?>/api/products.php?action=update&id=${pid}`, {
                method: 'POST', body: JSON.stringify(data),
            });
            SmartCart.showToast('Deal updated!');
        } else {
            await SmartCart.api('<?= APP_URL ?>/api/products.php?action=create', {
                method: 'POST', body: JSON.stringify(data),
            });
            SmartCart.showToast('Deal created! Your group is now live.');
        }
        setTimeout(() => location.reload(), 900);
    } catch(err) {
        SmartCart.showToast(err.message || 'Error saving deal.', 'error');
        btn.disabled = false;
        btn.textContent = pid ? 'Save Changes' : 'Create Deal';
    }
});

// ── Shipping Update ───────────────────────────────────────
async function updateShipping(orderId, status) {
    try {
        await SmartCart.api(`<?= APP_URL ?>/api/orders.php?action=update_shipping&id=${orderId}`, {
            method: 'POST', body: JSON.stringify({ shipping_status: status }),
        });
        SmartCart.showToast('Shipping status updated.');
    } catch(err) {
        SmartCart.showToast(err.message, 'error');
    }
}

// ── Close Early ───────────────────────────────────────────
async function closeEarly(groupId) {
    if (!confirm('Close this deal now and trigger payment for all participants?')) return;
    try {
        await SmartCart.api(`<?= APP_URL ?>/api/groups.php?action=lock&id=${groupId}`, {
            method: 'POST', body: '{}',
        });
        SmartCart.showToast('Deal closed! Payments will be processed.');
        setTimeout(() => location.reload(), 1200);
    } catch(err) {
        SmartCart.showToast(err.message, 'error');
    }
}

// ── Image Upload (deal modal) ─────────────────────────────
const dfFile    = document.getElementById('df-img-file');
const dfImg     = document.getElementById('df-img');
const dfPreview = document.getElementById('df-img-preview');
if (dfFile) {
    dfFile.addEventListener('change', async function() {
        const file = this.files[0];
        if (!file) return;
        if (file.size > 5 * 1024 * 1024) { SmartCart.showToast('File too large (max 5MB).', 'error'); this.value=''; return; }
        const fd = new FormData();
        fd.append('file', file);
        try {
            const res = await fetch('<?= APP_URL ?>/api/upload.php?action=product_image', {
                method: 'POST', credentials: 'same-origin', body: fd,
            });
            const data = await res.json();
            if (!res.ok) throw new Error(data.error || 'Upload failed');
            dfImg.value = data.url;
            dfPreview.src = data.url;
            dfPreview.style.display = 'block';
            SmartCart.showToast('Image uploaded.');
        } catch (err) {
            SmartCart.showToast(err.message, 'error');
            this.value = '';
        }
    });
}
if (dfImg) {
    dfImg.addEventListener('input', function() {
        if (this.value) { dfPreview.src = this.value; dfPreview.style.display = 'block'; }
        else            { dfPreview.style.display = 'none'; }
    });
}

// ── City Autocomplete (deal modal only — biz profile lives on its own page) ─
if (typeof SmartCartMaps !== 'undefined') {
    SmartCartMaps.initCityAutocomplete('df-city', 'df-lat', 'df-lng');
}

// ── Bottom-nav deep links: ?new=1 opens deal modal; #orders focuses Orders tab
(function() {
    const params = new URLSearchParams(window.location.search);
    if (params.get('new') === '1') {
        // Defer until DOM is ready
        setTimeout(() => openDealModal(), 50);
    }
    if (window.location.hash === '#orders') {
        const ordersBtn = document.querySelector('.tab-btn[data-tab="orders"]');
        if (ordersBtn) ordersBtn.click();
    }
})();
</script>
