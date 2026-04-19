<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/lang.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/functions.php';

require_role('business');
$user_id    = current_user_id();
$page_title = $t['biz_dashboard'];
$needs_map  = true;

$pdo = getPDO();

// Get business
$bstmt = $pdo->prepare("SELECT * FROM businesses WHERE user_id = ?");
$bstmt->execute([$user_id]);
$business = $bstmt->fetch();
if (!$business) {
    $_SESSION['flash_error'] = 'Business profile not found.';
    header('Location: ' . APP_URL . '/pages/index.php'); exit;
}
$biz_id = $business['id'];

// Stats
$stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE business_id = ? AND status = 'active'"); $stmt->execute([$biz_id]); $prod_count = (int)$stmt->fetchColumn();
$stmt = $pdo->prepare("SELECT COUNT(*) FROM group_purchases gp JOIN products p ON p.id = gp.product_id WHERE p.business_id = ? AND gp.status = 'open'"); $stmt->execute([$biz_id]); $active_groups = (int)$stmt->fetchColumn();
$stmt = $pdo->prepare("SELECT COUNT(*) FROM orders o JOIN products p ON p.id = o.product_id WHERE p.business_id = ?"); $stmt->execute([$biz_id]); $orders_count = (int)$stmt->fetchColumn();
$stmt = $pdo->prepare("SELECT COALESCE(SUM(o.amount_paid),0) FROM orders o JOIN products p ON p.id = o.product_id WHERE p.business_id = ?"); $stmt->execute([$biz_id]); $revenue = (float)$stmt->fetchColumn();

// My Products
$pstmt = $pdo->prepare("
    SELECT p.*,
           (SELECT COUNT(*) FROM group_purchases gp WHERE gp.product_id = p.id AND gp.status = 'open') AS active_groups,
           ROUND((p.price_ils - p.group_price_ils) / p.price_ils * 100) AS disc
    FROM products p WHERE p.business_id = ? ORDER BY p.created_at DESC
");
$pstmt->execute([$biz_id]);
$products = $pstmt->fetchAll();

// Recent orders
$ostmt = $pdo->prepare("
    SELECT o.*, p.name AS product_name, u.full_name AS customer_name, u.email AS customer_email
    FROM orders o
    JOIN products p ON p.id = o.product_id
    JOIN users u ON u.id = o.user_id
    WHERE p.business_id = ?
    ORDER BY o.created_at DESC LIMIT 20
");
$ostmt->execute([$biz_id]);
$orders = $ostmt->fetchAll();

// Active groups for this business
$gstmt = $pdo->prepare("
    SELECT gp.*, p.name AS product_name, ROUND(gp.current_participants / gp.target_participants * 100) AS fill_pct
    FROM group_purchases gp JOIN products p ON p.id = gp.product_id
    WHERE p.business_id = ? AND gp.status = 'open' ORDER BY gp.deadline ASC
");
$gstmt->execute([$biz_id]);
$active_gps = $gstmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div class="container" style="padding-top:32px;padding-bottom:60px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;">
        <div>
            <h1 style="font-size:24px;font-weight:700;"><?= htmlspecialchars($business['business_name']) ?></h1>
            <p style="color:var(--gray-500);"><?= $t['biz_dashboard'] ?></p>
        </div>
        <button class="btn btn-primary" onclick="document.getElementById('add-product-modal').classList.add('open')">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            <?= $t['biz_add_product'] ?>
        </button>
    </div>

    <!-- Stats -->
    <div class="stats-bar" style="margin-bottom:28px;">
        <div class="stat-card">
            <div class="stat-icon">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="16.5" y1="9.4" x2="7.5" y2="4.21"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
            </div>
            <div class="stat-info">
                <div class="stat-value"><?= $prod_count ?></div>
                <div class="stat-label">Products</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            </div>
            <div class="stat-info">
                <div class="stat-value"><?= $active_groups ?></div>
                <div class="stat-label">Active Groups</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
            </div>
            <div class="stat-info">
                <div class="stat-value"><?= $orders_count ?></div>
                <div class="stat-label">Orders</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
            </div>
            <div class="stat-info">
                <div class="stat-value"><?= format_ils($revenue) ?></div>
                <div class="stat-label"><?= $t['biz_revenue'] ?></div>
            </div>
        </div>
    </div>

    <!-- Active Groups -->
    <?php if (!empty($active_gps)): ?>
    <div class="card" style="margin-bottom:24px;">
        <div class="card-header"><strong><?= $t['biz_groups'] ?></strong></div>
        <div class="card-body" style="padding:0;">
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Product</th><th>Progress</th><th>Deadline</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($active_gps as $gp): ?>
                    <tr>
                        <td><?= htmlspecialchars($gp['product_name']) ?></td>
                        <td style="min-width:160px;">
                            <div class="progress-wrap"><div class="progress-bar" style="width:<?= $gp['fill_pct'] ?>%;"></div></div>
                            <small><?= $gp['current_participants'] ?>/<?= $gp['target_participants'] ?></small>
                        </td>
                        <td><?= countdown_label($gp['deadline']) ?></td>
                        <td><a href="<?= APP_URL ?>/pages/group.php?id=<?= $gp['id'] ?>" class="btn btn-sm btn-outline">View</a></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- My Products -->
    <div class="card" style="margin-bottom:24px;">
        <div class="card-header"><strong><?= $t['biz_products'] ?></strong></div>
        <div class="card-body" style="padding:0;">
            <?php if (empty($products)): ?>
            <div class="empty-state"><p>No products yet. Add your first product!</p></div>
            <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Product</th><th>Price</th><th>Group Price</th><th>Discount</th><th>Active Groups</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($products as $p): ?>
                    <tr>
                        <td>
                            <div style="display:flex;gap:10px;align-items:center;">
                                <?php if ($p['image_url']): ?>
                                <img src="<?= APP_URL . htmlspecialchars($p['image_url']) ?>" style="width:40px;height:40px;object-fit:cover;border-radius:6px;">
                                <?php endif; ?>
                                <span style="font-weight:500;"><?= htmlspecialchars($p['name']) ?></span>
                            </div>
                        </td>
                        <td><?= format_ils($p['price_ils']) ?></td>
                        <td><?= format_ils($p['group_price_ils']) ?></td>
                        <td><span class="card-badge badge-discount"><?= $p['disc'] ?>%</span></td>
                        <td><?= $p['active_groups'] ?></td>
                        <td><span class="card-badge badge-<?= $p['status'] === 'active' ? 'joined' : 'failed' ?>"><?= $p['status'] ?></span></td>
                        <td>
                            <div style="display:flex;gap:6px;">
                                <button class="btn btn-sm btn-outline" onclick="editProduct(<?= htmlspecialchars(json_encode($p)) ?>)">Edit</button>
                                <button class="btn btn-sm btn-danger" data-action="delete-product" data-id="<?= $p['id'] ?>">Delete</button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Orders -->
    <div class="card">
        <div class="card-header"><strong><?= $t['biz_orders'] ?></strong></div>
        <div class="card-body" style="padding:0;">
            <?php if (empty($orders)): ?>
            <div class="empty-state"><p>No orders yet.</p></div>
            <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Product</th><th>Customer</th><th>Amount</th><th>Shipping</th><th>Update</th></tr></thead>
                    <tbody>
                    <?php foreach ($orders as $o): ?>
                    <tr>
                        <td><?= htmlspecialchars($o['product_name']) ?></td>
                        <td><?= htmlspecialchars($o['customer_name']) ?><br><small class="text-muted"><?= htmlspecialchars($o['customer_email']) ?></small></td>
                        <td><?= format_ils($o['amount_paid']) ?></td>
                        <td><span class="card-badge badge-<?= $o['shipping_status'] === 'delivered' ? 'paid' : 'joined' ?>"><?= $t['shipping_' . $o['shipping_status']] ?></span></td>
                        <td>
                            <select class="form-control" style="font-size:12px;padding:4px 8px;" onchange="updateShipping(<?= $o['id'] ?>, this.value)">
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
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Add/Edit Product Modal -->
<div class="modal-overlay" id="add-product-modal">
    <div class="modal" style="max-width:560px;">
        <h2 class="modal-title" id="modal-title"><?= $t['biz_add_product'] ?></h2>
        <form id="product-form">
            <input type="hidden" id="product-id" name="product_id" value="">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div class="form-group" style="grid-column:1/-1;">
                    <label class="form-label"><?= $t['product_name'] ?></label>
                    <input type="text" name="name" id="pf-name" class="form-control" required>
                </div>
                <div class="form-group" style="grid-column:1/-1;">
                    <label class="form-label"><?= $t['product_desc'] ?></label>
                    <textarea name="description" id="pf-desc" class="form-control" rows="3"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= $t['product_price'] ?></label>
                    <input type="number" name="price_ils" id="pf-price" class="form-control" step="0.01" min="0" required>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= $t['product_gprice'] ?></label>
                    <input type="number" name="group_price_ils" id="pf-gprice" class="form-control" step="0.01" min="0" required>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= $t['product_cat'] ?></label>
                    <select name="category" id="pf-cat" class="form-control">
                        <?php foreach (['electronics','home','fashion','food','sports','beauty','toys','books','automotive','other'] as $cat): ?>
                        <option value="<?= $cat ?>"><?= $t['cat_' . $cat] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= $t['product_min'] ?></label>
                    <input type="number" name="min_participants" id="pf-min" class="form-control" min="2" value="3">
                </div>
                <div class="form-group">
                    <label class="form-label"><?= $t['product_city'] ?></label>
                    <input type="text" name="city" id="pf-city" class="form-control"
                           value="<?= htmlspecialchars($business['city'] ?? '') ?>"
                           autocomplete="off">
                    <input type="hidden" id="pf-lat" name="lat" value="">
                    <input type="hidden" id="pf-lng" name="lng" value="">
                </div>
                <div class="form-group">
                    <label class="form-label"><?= $t['product_img'] ?></label>
                    <input type="url" name="image_url" id="pf-img" class="form-control" placeholder="https://...">
                </div>
            </div>
            <div style="display:flex;gap:8px;margin-top:8px;">
                <button type="submit" class="btn btn-primary"><?= $t['product_save'] ?></button>
                <button type="button" class="btn btn-ghost" data-action="close-modal">Cancel</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

<script>
function editProduct(p) {
    document.getElementById('modal-title').textContent = 'Edit Product';
    document.getElementById('product-id').value = p.id;
    document.getElementById('pf-name').value  = p.name;
    document.getElementById('pf-desc').value  = p.description || '';
    document.getElementById('pf-price').value = p.price_ils;
    document.getElementById('pf-gprice').value= p.group_price_ils;
    document.getElementById('pf-cat').value   = p.category;
    document.getElementById('pf-min').value   = p.min_participants;
    document.getElementById('pf-city').value  = p.city || '';
    document.getElementById('pf-img').value   = p.image_url || '';
    document.getElementById('add-product-modal').classList.add('open');
}

document.getElementById('product-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    const fd = new FormData(this);
    const data = Object.fromEntries(fd);
    const pid  = data.product_id;
    delete data.product_id;
    const btn = this.querySelector('[type=submit]');
    btn.disabled = true;
    try {
        if (pid) {
            await SmartCart.api(`<?= APP_URL ?>/api/products.php?action=update&id=${pid}`, { method: 'POST', body: JSON.stringify(data) });
        } else {
            await SmartCart.api('<?= APP_URL ?>/api/products.php?action=create', { method: 'POST', body: JSON.stringify(data) });
        }
        SmartCart.showToast(pid ? 'Product updated!' : 'Product created!');
        setTimeout(() => location.reload(), 800);
    } catch(err) {
        SmartCart.showToast(err.message, 'error');
        btn.disabled = false;
    }
});

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

// City autocomplete (Photon / OpenStreetMap)
if (typeof SmartCartMaps !== 'undefined') {
    SmartCartMaps.initCityAutocomplete('pf-city', 'pf-lat', 'pf-lng');
}
</script>
