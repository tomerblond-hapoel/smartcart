<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/functions.php';

$group_id = (int)($_GET['id'] ?? 0);
if (!$group_id) {
    header('Location: ' . APP_URL . '/pages/browse.php');
    exit;
}

$pdo = getPDO();

// Load group with product and business info
$stmt = $pdo->prepare("
    SELECT gp.*,
           p.name AS product_name, p.image_url AS product_image,
           p.price_ils, p.group_price_ils, p.category, p.description AS product_desc,
           p.min_participants, p.id AS product_id,
           ROUND((p.price_ils - p.group_price_ils) / p.price_ils * 100) AS disc,
           ROUND(gp.current_participants / gp.target_participants * 100) AS fill_pct,
           u.full_name AS creator_name,
           b.business_name, b.lat AS biz_lat, b.lng AS biz_lng, b.address AS biz_address, b.city AS biz_city
    FROM group_purchases gp
    JOIN products p ON p.id = gp.product_id
    JOIN users u ON u.id = gp.creator_id
    JOIN businesses b ON b.id = p.business_id
    WHERE gp.id = ?
");
$stmt->execute([$group_id]);
$group = $stmt->fetch();

if (!$group) {
    $_SESSION['flash_error'] = 'Group not found.';
    header('Location: ' . APP_URL . '/pages/browse.php');
    exit;
}

// Members
$mstmt = $pdo->prepare("
    SELECT gm.status, gm.joined_at, u.id AS user_id, u.full_name
    FROM group_members gm
    JOIN users u ON u.id = gm.user_id
    WHERE gm.group_id = ? ORDER BY gm.joined_at ASC
");
$mstmt->execute([$group_id]);
$members = $mstmt->fetchAll();

// Current user membership status
$user_id   = current_user_id();
$is_member = false;
$my_status = null;
foreach ($members as $m) {
    if ((int)$m['user_id'] === $user_id) {
        $is_member = true;
        $my_status = $m['status'];
        break;
    }
}

// Messages
$msgstmt = $pdo->prepare("
    SELECT gm.message_text, gm.sent_at, u.full_name, u.id AS sender_id
    FROM group_messages gm
    JOIN users u ON u.id = gm.user_id
    WHERE gm.group_id = ? ORDER BY gm.sent_at ASC
");
$msgstmt->execute([$group_id]);
$messages = $msgstmt->fetchAll();

// Handle chat message submit (form POST for simplicity)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message_text']) && $user_id) {
    $text = trim($_POST['message_text']);
    if ($text && $is_member) {
        $pdo->prepare("INSERT INTO group_messages (group_id, user_id, message_text) VALUES (?, ?, ?)")
            ->execute([$group_id, $user_id, $text]);
        header('Location: ' . APP_URL . '/pages/group.php?id=' . $group_id . '#chat');
        exit;
    }
}

$fill_pct   = (int)$group['fill_pct'];
$fill_class = $fill_pct >= 80 ? 'high' : ($fill_pct >= 50 ? 'medium' : '');
$days_left  = days_until($group['deadline']);
$cat_icons  = ['electronics'=>'💻','home'=>'🏠','fashion'=>'👗','food'=>'🍎','sports'=>'⚽','beauty'=>'💄','toys'=>'🧸','books'=>'📚','automotive'=>'🚗','other'=>'📦'];

$page_title = $group['product_name'];
$needs_map  = true;
include __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding-top:32px;padding-bottom:60px;">
    <a href="<?= APP_URL ?>/pages/product.php?id=<?= $group['product_id'] ?>" class="btn btn-ghost btn-sm" style="margin-bottom:16px;">
        ← Back to Product
    </a>

    <div style="display:grid;grid-template-columns:1fr 360px;gap:28px;align-items:start;">

        <!-- LEFT: Product & Group Info -->
        <div>
            <!-- Product Image -->
            <?php if ($group['product_image']): ?>
            <img src="<?= APP_URL . htmlspecialchars($group['product_image']) ?>"
                 alt="<?= htmlspecialchars($group['product_name']) ?>"
                 style="width:100%;height:280px;object-fit:cover;border-radius:var(--radius);margin-bottom:20px;">
            <?php else: ?>
            <div style="width:100%;height:200px;background:var(--purple-50);border-radius:var(--radius);display:flex;align-items:center;justify-content:center;font-size:64px;margin-bottom:20px;">
                <?= $cat_icons[$group['category']] ?? '📦' ?>
            </div>
            <?php endif; ?>

            <!-- Product Info -->
            <div class="card" style="margin-bottom:20px;">
                <div class="card-body">
                    <div style="display:flex;gap:8px;margin-bottom:8px;">
                        <span class="card-badge badge-<?= $group['status'] ?>"><?= $t['group_' . $group['status']] ?></span>
                        <span class="card-badge badge-discount"><?= $group['disc'] ?>% <?= $t['off'] ?></span>
                    </div>
                    <h1 style="font-size:22px;font-weight:700;color:var(--gray-900);margin-bottom:6px;">
                        <?= htmlspecialchars($group['product_name']) ?>
                    </h1>
                    <p style="font-size:13px;color:var(--gray-500);margin-bottom:16px;">
                        <?= $t['product_by'] ?> <?= htmlspecialchars($group['business_name']) ?> · <?= $group['biz_city'] ?>
                    </p>
                    <div style="display:flex;align-items:baseline;gap:12px;margin-bottom:16px;">
                        <span style="font-size:28px;font-weight:800;color:var(--purple);"><?= format_ils($group['group_price_ils']) ?></span>
                        <span style="text-decoration:line-through;color:var(--gray-500);"><?= format_ils($group['price_ils']) ?></span>
                        <span style="background:var(--purple-50);color:var(--purple-dark);padding:3px 10px;border-radius:999px;font-size:13px;font-weight:600;">
                            Save <?= format_ils($group['price_ils'] - $group['group_price_ils']) ?>
                        </span>
                    </div>
                    <?php if ($group['product_desc']): ?>
                    <p style="font-size:14px;line-height:1.6;color:var(--gray-700);"><?= htmlspecialchars($group['product_desc']) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Group Chat -->
            <div class="card" id="chat">
                <div class="card-header">
                    <strong><?= $t['group_chat'] ?></strong>
                    <span style="font-size:12px;color:var(--gray-500);margin-left:8px;"><?= count($messages) ?> messages</span>
                </div>
                <div class="chat-messages" style="max-height:280px;overflow-y:auto;padding:16px;background:var(--gray-100);">
                    <?php if (empty($messages)): ?>
                    <p style="text-align:center;color:var(--gray-500);font-size:13px;">No messages yet. Start the conversation!</p>
                    <?php else: ?>
                    <?php foreach ($messages as $msg):
                        $initials = strtoupper(substr($msg['full_name'], 0, 1));
                        $is_mine  = $user_id && (int)$msg['sender_id'] === $user_id;
                    ?>
                    <div class="chat-msg" style="<?= $is_mine ? 'flex-direction:row-reverse;' : '' ?>">
                        <div class="chat-avatar" style="background:var(--<?= $is_mine ? 'teal' : 'dark' ?>);"><?= $initials ?></div>
                        <div>
                            <div class="chat-bubble" style="<?= $is_mine ? 'background:var(--purple-50);border-radius:12px 0 12px 12px;' : '' ?>">
                                <?= htmlspecialchars($msg['message_text']) ?>
                            </div>
                            <div class="chat-meta" style="<?= $is_mine ? 'text-align:right;' : '' ?>">
                                <?= $is_mine ? 'You' : htmlspecialchars($msg['full_name']) ?> ·
                                <?= date('d/m H:i', strtotime($msg['sent_at'])) ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <?php if ($user_id && $is_member): ?>
                <div class="card-footer">
                    <form method="POST" id="chat-form" style="display:flex;gap:8px;">
                        <input type="text" name="message_text" class="form-control"
                               placeholder="<?= $t['group_chat_placeholder'] ?>" required autocomplete="off">
                        <button type="submit" class="btn btn-primary btn-sm"><?= $t['group_chat_send'] ?></button>
                    </form>
                </div>
                <?php elseif (!$user_id): ?>
                <div class="card-footer" style="text-align:center;font-size:13px;color:var(--gray-500);">
                    <a href="<?= APP_URL ?>/pages/login.php">Login</a> to join the chat.
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- RIGHT: Group Status & Actions -->
        <div style="position:sticky;top:80px;">
            <!-- Group Progress Card -->
            <div class="card" style="margin-bottom:16px;">
                <div class="card-body">
                    <div style="text-align:center;margin-bottom:16px;">
                        <div style="font-size:40px;font-weight:800;color:var(--purple);"><?= $fill_pct ?>%</div>
                        <div style="font-size:14px;color:var(--gray-500);"><?= $t['group_full'] ?></div>
                    </div>

                    <div class="progress-wrap" style="margin-bottom:8px;height:12px;">
                        <div class="progress-bar <?= $fill_class ?>" style="width:<?= $fill_pct ?>%;"></div>
                    </div>
                    <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:16px;">
                        <span><strong><?= $group['current_participants'] ?></strong> / <?= $group['target_participants'] ?> <?= $t['group_members_count'] ?></span>
                        <span style="color:<?= $days_left <= 3 ? 'var(--danger)' : 'var(--gray-500)' ?>;">
                            ⏰ <?= countdown_label($group['deadline']) ?>
                        </span>
                    </div>

                    <!-- Status Banner -->
                    <?php if ($group['status'] === 'closed'): ?>
                    <div style="background:#DBEAFE;border-radius:8px;padding:12px;margin-bottom:16px;text-align:center;">
                        <strong style="color:#1D4ED8;"><?= $t['group_pay_required'] ?></strong>
                        <p style="font-size:12px;color:#3B82F6;margin-top:4px;"><?= $t['group_pay_desc'] ?></p>
                    </div>
                    <?php elseif ($group['status'] === 'failed'): ?>
                    <div style="background:#FEE2E2;border-radius:8px;padding:12px;margin-bottom:16px;text-align:center;">
                        <strong style="color:#DC2626;"><?= $t['group_failed_msg'] ?></strong>
                    </div>
                    <?php endif; ?>

                    <!-- Action Button -->
                    <?php if ($group['status'] === 'open'): ?>
                        <?php if (!$user_id): ?>
                        <a href="<?= APP_URL ?>/pages/login.php" class="btn btn-primary btn-full btn-lg">
                            Login to Join
                        </a>
                        <?php elseif (!$is_member): ?>
                        <button class="btn btn-primary btn-full btn-lg" data-action="join-group" data-group-id="<?= $group_id ?>">
                            <?= $t['group_join'] ?>
                        </button>
                        <?php else: ?>
                        <div style="background:var(--purple-50);border-radius:8px;padding:12px;text-align:center;margin-bottom:12px;">
                            <span style="color:var(--purple);font-weight:600;">✓ You're in this group!</span>
                        </div>
                        <?php if ($my_status !== 'paid'): ?>
                        <button class="btn btn-ghost btn-full btn-sm" data-action="leave-group" data-group-id="<?= $group_id ?>">
                            <?= $t['group_leave'] ?>
                        </button>
                        <?php endif; ?>
                        <?php endif; ?>

                    <?php elseif ($group['status'] === 'closed'): ?>
                        <?php if ($my_status === 'joined'): ?>
                        <!-- PayPal button -->
                        <div id="paypal-button-container"></div>
                        <?php elseif ($my_status === 'paid'): ?>
                        <div style="background:#DCFCE7;border-radius:8px;padding:12px;text-align:center;">
                            <span style="color:var(--success);font-weight:600;">✓ Payment complete!</span>
                            <br><a href="<?= APP_URL ?>/pages/my-orders.php" style="font-size:12px;">View your order →</a>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Members List -->
            <div class="card" style="margin-bottom:16px;">
                <div class="card-header">
                    <strong><?= $t['group_members_title'] ?></strong>
                </div>
                <div class="card-body" style="padding:12px;">
                    <?php foreach ($members as $m):
                        $initials = strtoupper(substr($m['full_name'], 0, 1));
                    ?>
                    <div style="display:flex;align-items:center;gap:10px;padding:6px 0;">
                        <div style="width:32px;height:32px;border-radius:50%;background:var(--purple);color:white;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;flex-shrink:0;">
                            <?= $initials ?>
                        </div>
                        <span style="font-size:13px;"><?= htmlspecialchars($m['full_name']) ?></span>
                        <?php if ($m['status'] === 'paid'): ?>
                        <span class="card-badge badge-paid" style="margin-left:auto;"><?= $t['group_paid_badge'] ?></span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>

                    <!-- Empty spots -->
                    <?php for ($i = count($members); $i < $group['target_participants']; $i++): ?>
                    <div style="display:flex;align-items:center;gap:10px;padding:6px 0;opacity:.4;">
                        <div style="width:32px;height:32px;border-radius:50%;border:2px dashed var(--gray-300);display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;">+</div>
                        <span style="font-size:13px;color:var(--gray-500);">Open spot</span>
                    </div>
                    <?php endfor; ?>
                </div>
            </div>

            <!-- Business Location Map -->
            <?php if ($group['biz_lat'] && $group['biz_lng']): ?>
            <div class="card">
                <div class="card-header">
                    <strong>📍 <?= htmlspecialchars($group['business_name']) ?></strong>
                    <div style="font-size:12px;color:var(--gray-500);margin-top:2px;"><?= htmlspecialchars($group['biz_address'] ?? $group['biz_city']) ?></div>
                </div>
                <div id="map-group"></div>
            </div>
            <?php endif; ?>
        </div>

    </div><!-- end grid -->
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<script>
const GROUP_ID     = <?= $group_id ?>;
const BIZ_LAT      = <?= (float)($group['biz_lat'] ?? 0) ?>;
const BIZ_LNG      = <?= (float)($group['biz_lng'] ?? 0) ?>;
const GROUP_PRICE  = <?= (float)$group['group_price_ils'] ?>;
const IS_MEMBER    = <?= $is_member ? 'true' : 'false' ?>;
const MY_STATUS    = '<?= $my_status ?? '' ?>';

// Leaflet map for business location
(function() {
    if (!BIZ_LAT || !BIZ_LNG || typeof SmartCartMaps === 'undefined') return;
    var map = SmartCartMaps.createMap('map-group', BIZ_LAT, BIZ_LNG, 14);
    if (!map) return;
    SmartCartMaps.addMarker(map, BIZ_LAT, BIZ_LNG, '<strong><?= htmlspecialchars($group['business_name']) ?></strong><br><?= htmlspecialchars($group['biz_address'] ?? $group['biz_city']) ?>');
})();

<?php if ($my_status === 'joined' && $group['status'] === 'closed' && defined('PAYPAL_CLIENT_ID') && PAYPAL_CLIENT_ID !== 'YOUR_PAYPAL_SANDBOX_CLIENT_ID'): ?>
// PayPal SDK
const script = document.createElement('script');
script.src = 'https://www.paypal.com/sdk/js?client-id=<?= PAYPAL_CLIENT_ID ?>&currency=USD';
script.onload = () => {
    paypal.Buttons({
        createOrder: async () => {
            const res  = await SmartCart.api('/api/payments.php?action=create_order', {
                method: 'POST', body: JSON.stringify({ group_id: GROUP_ID }),
            });
            return res.orderID;
        },
        onApprove: async (data) => {
            const shipping = prompt('Enter your shipping address:', '') || '';
            await SmartCart.api('/api/payments.php?action=capture_order', {
                method: 'POST',
                body: JSON.stringify({ orderID: data.orderID, group_id: GROUP_ID, shipping_address: shipping }),
            });
            SmartCart.showToast('Payment successful! Check My Orders for details.');
            setTimeout(() => location.reload(), 1500);
        },
        onError: (err) => SmartCart.showToast('Payment failed: ' + err.message, 'error'),
    }).render('#paypal-button-container');
};
document.head.appendChild(script);
<?php endif; ?>

// Scroll chat to bottom
const chatMsgs = document.querySelector('.chat-messages');
if (chatMsgs) chatMsgs.scrollTop = chatMsgs.scrollHeight;
</script>
