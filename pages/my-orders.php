<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();
$user_id    = current_user_id();
$page_title = $t['my_orders_title'];

$pdo  = getPDO();
$stmt = $pdo->prepare("
    SELECT o.*, p.name AS product_name, p.image_url, p.price_ils,
           pay.paypal_transaction_id, pay.created_at AS paid_at,
           b.business_name, b.city AS business_city
    FROM orders o
    JOIN products p ON p.id = o.product_id
    JOIN businesses b ON b.id = p.business_id
    JOIN payments pay ON pay.id = o.payment_id
    WHERE o.user_id = ?
    ORDER BY o.created_at DESC
");
$stmt->execute([$user_id]);
$orders = $stmt->fetchAll();

$shipping_steps = ['pending','processing','shipped','delivered'];

include __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding-top:32px;padding-bottom:60px;">
    <div class="page-header">
        <h1><?= $t['my_orders_title'] ?></h1>
    </div>

    <?php if (empty($orders)): ?>
    <div class="empty-state">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/>
            <path d="M16 10a4 4 0 0 1-8 0"/>
        </svg>
        <h3><?= $t['order_no_orders'] ?></h3>
        <a href="<?= APP_URL ?>/pages/browse.php" class="btn btn-primary mt-16">Browse Deals</a>
    </div>
    <?php else: ?>
    <div style="display:grid;gap:16px;">
        <?php foreach ($orders as $o):
            $step_index = array_search($o['shipping_status'], $shipping_steps);
            $savings    = (float)$o['price_ils'] - (float)$o['amount_paid'];
        ?>
        <div class="card" data-order-id="<?= $o['id'] ?>" data-shipping-status="<?= htmlspecialchars($o['shipping_status']) ?>">
            <div class="card-body">
                <div style="display:flex;gap:16px;margin-bottom:20px;">
                    <?php if ($o['image_url']): ?>
                    <img src="<?= APP_URL . htmlspecialchars($o['image_url']) ?>" style="width:80px;height:80px;object-fit:cover;border-radius:8px;flex-shrink:0;">
                    <?php endif; ?>
                    <div style="flex:1;">
                        <h3 style="font-size:16px;font-weight:600;margin-bottom:4px;"><?= htmlspecialchars($o['product_name']) ?></h3>
                        <p style="font-size:13px;color:var(--gray-500);margin-bottom:8px;"><?= htmlspecialchars($o['business_name']) ?></p>
                        <div style="display:flex;gap:16px;font-size:13px;">
                            <span><strong><?= $t['order_amount'] ?>:</strong> <?= format_ils($o['amount_paid']) ?></span>
                            <span style="color:var(--success);">Saved <?= format_ils(max(0, $savings)) ?></span>
                        </div>
                        <?php if ($o['paypal_transaction_id']): ?>
                        <div style="font-size:11px;color:var(--gray-500);margin-top:4px;">
                            PayPal TX: <?= htmlspecialchars($o['paypal_transaction_id']) ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div style="text-align:right;font-size:12px;color:var(--gray-500);flex-shrink:0;">
                        <?= date('d/m/Y', strtotime($o['created_at'])) ?>
                    </div>
                </div>

                <!-- Shipping Stepper -->
                <div style="margin-bottom:12px;">
                    <p style="font-size:12px;font-weight:600;color:var(--gray-700);margin-bottom:10px;"><?= $t['order_status'] ?></p>
                    <div style="display:flex;align-items:center;gap:0;">
                        <?php foreach ($shipping_steps as $i => $step): ?>
                        <?php $done = $i <= $step_index; $active = $i === $step_index; ?>
                        <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:4px;font-size:10px;color:<?= $done ? ($active ? 'var(--teal)' : 'var(--success)') : 'var(--gray-300)' ?>;">
                            <div style="width:20px;height:20px;border-radius:50%;border:2px solid <?= $done ? ($active ? 'var(--teal)' : 'var(--success)') : 'var(--gray-300)' ?>;background:<?= $done ? ($active ? 'var(--teal)' : 'var(--success)') : 'white' ?>;display:flex;align-items:center;justify-content:center;">
                                <?php if ($done && !$active): ?><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg><?php endif; ?>
                            </div>
                            <?= $t['shipping_' . $step] ?>
                        </div>
                        <?php if ($i < count($shipping_steps) - 1): ?>
                        <div style="flex:2;height:2px;background:<?= $i < $step_index ? 'var(--success)' : 'var(--gray-300)' ?>;margin: 0 0 16px;"></div>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php if ($o['tracking_id']): ?>
                <div style="font-size:12px;background:var(--gray-100);border-radius:6px;padding:8px 12px;">
                    📦 <?= $t['order_tracking'] ?>: <strong><?= htmlspecialchars($o['tracking_id']) ?></strong>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<script>
// Live order status polling — toast + reload when shipping_status changes
(function() {
    const initial = {};
    document.querySelectorAll('[data-order-id]').forEach(el => {
        initial[el.dataset.orderId] = el.dataset.shippingStatus || '';
    });
    if (Object.keys(initial).length === 0) return;
    async function tick() {
        try {
            const res = await fetch('<?= APP_URL ?>/api/orders.php?action=list', { credentials: 'same-origin' });
            if (!res.ok) return;
            const data = await res.json();
            let changed = false;
            (data.orders || []).forEach(o => {
                if (initial[o.id] && initial[o.id] !== o.shipping_status) { changed = true; }
            });
            if (changed && typeof SmartCart !== 'undefined') {
                SmartCart.showToast('Order status updated — refreshing…');
                setTimeout(() => location.reload(), 1000);
            }
        } catch (e) {}
    }
    setInterval(tick, 30000);
})();
</script>
