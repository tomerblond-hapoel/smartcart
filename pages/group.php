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

// ── Hosted-payment-page state ──────────────────────────────────────────────
// Load the user's payment record when the group is in a payment-related status
$my_payment = null;
if ($is_member && $user_id && in_array($group['status'], ['ready_for_payment', 'order_ready'], true)) {
    $pstmt = $pdo->prepare("
        SELECT id, status, payment_url, provider_transaction_id, paid_at
        FROM   payments
        WHERE  group_id = ? AND user_id = ?
        ORDER  BY created_at DESC
        LIMIT  1
    ");
    $pstmt->execute([$group_id, $user_id]);
    $my_payment = $pstmt->fetch() ?: null;
}

// ── Payment collection progress (ready_for_payment + order_ready) ─────────────
// Keyed by user_id so we can look up each member's status in O(1) while looping.
$pay_progress_by_user = [];
$paid_count           = 0;
$total_collected      = 0.0;

if (in_array($group['status'], ['ready_for_payment', 'order_ready'], true)) {
    $pp = $pdo->prepare("
        SELECT gm.user_id,
               pay.status    AS pay_status,
               pay.amount_ils,
               pay.paid_at
        FROM   group_members gm
        LEFT JOIN payments pay
               ON  pay.group_id = gm.group_id
               AND pay.user_id  = gm.user_id
        WHERE  gm.group_id = ?
          AND  gm.status  != 'cancelled'
    ");
    $pp->execute([$group_id]);
    foreach ($pp->fetchAll() as $row) {
        $pay_progress_by_user[(int)$row['user_id']] = $row;
        if (in_array($row['pay_status'], ['paid', 'completed'], true)) {
            $paid_count++;
            $total_collected += (float)$row['amount_ils'];
        }
    }
}

$total_member_count = count($members);
$total_expected     = $total_member_count * (float)$group['group_price_ils'];

// All providers (including paypal) now use free-join; payment happens after group fills.
$hosted_payment_mode = defined('PAYMENT_PROVIDER') && PAYMENT_PROVIDER !== '';
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
            <img src="<?= img_url($group['product_image']) ?>"
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
                        <span id="live-count"><strong><?= $group['current_participants'] ?></strong> / <?= $group['target_participants'] ?> <?= $t['group_members_count'] ?></span>
                        <span style="color:<?= $days_left <= 3 ? 'var(--danger)' : 'var(--gray-500)' ?>;">
                            ⏰ <span id="group-countdown" data-deadline="<?= strtotime($group['deadline']) ?>" style="font-variant-numeric:tabular-nums;font-weight:700;"><?= countdown_label($group['deadline']) ?></span>
                        </span>
                    </div>

                    <!-- Status Banner -->
                    <?php if ($group['status'] === 'ready_for_payment'): ?>
                    <div style="background:#EEF2FF;border-radius:8px;padding:12px;margin-bottom:16px;text-align:center;">
                        <strong style="color:#4338CA;">💳 <?= $t['group_ready_for_payment'] ?></strong>
                        <p style="font-size:12px;color:#6366F1;margin-top:4px;"><?= $t['group_ready_for_payment_desc'] ?></p>
                    </div>
                    <?php elseif ($group['status'] === 'order_ready'): ?>
                    <div style="background:#DCFCE7;border-radius:8px;padding:12px;margin-bottom:16px;text-align:center;">
                        <strong style="color:#166534;">🎉 <?= $t['group_order_ready'] ?></strong>
                        <p style="font-size:12px;color:#16A34A;margin-top:4px;"><?= $t['group_order_ready_desc'] ?></p>
                    </div>
                    <?php elseif ($group['status'] === 'closed'): ?>
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
                            <!-- Free join — payment requested after group fills -->
                            <p style="font-size:12px;color:var(--gray-500);margin-bottom:10px;text-align:center;">
                                Join for free — you'll be asked to pay via PayPal once the group reaches its target.
                            </p>
                            <button class="btn btn-primary btn-full btn-lg"
                                    data-action="join-group"
                                    data-group-id="<?= $group_id ?>">
                                <?= $t['group_join'] ?>
                            </button>

                        <?php else: /* already a member */ ?>
                        <div style="background:var(--primary-50);border-radius:8px;padding:12px;text-align:center;margin-bottom:12px;">
                            <span style="color:var(--primary);font-weight:600;">
                                <?php if ($my_status === 'paid'): ?>
                                    ✓ Payment captured — order created
                                <?php elseif ($hosted_payment_mode): ?>
                                    ✓ You're in! You'll be notified when payment is due.
                                <?php else: ?>
                                    ✓ You're in! Payment authorized — charged when group succeeds.
                                <?php endif; ?>
                            </span>
                            <?php if ($my_status === 'paid'): ?>
                            <br><a href="<?= APP_URL ?>/pages/my-orders.php" style="font-size:12px;">View your order →</a>
                            <?php endif; ?>
                        </div>
                        <?php if ($my_status !== 'paid'): ?>
                        <button class="btn btn-ghost btn-full btn-sm" data-action="leave-group" data-group-id="<?= $group_id ?>">
                            <?= $t['group_leave'] ?>
                        </button>
                        <?php endif; ?>
                        <?php endif; ?>

                    <?php elseif ($group['status'] === 'ready_for_payment'): ?>
                        <?php if (!$is_member): ?>
                        <div style="background:#F3F4F6;border-radius:8px;padding:12px;text-align:center;">
                            <span style="color:var(--gray-600);font-weight:600;">Group is full</span>
                            <p style="font-size:12px;color:var(--gray-500);margin-top:4px;">This group has reached its target and is awaiting payment from members.</p>
                        </div>

                        <?php elseif ($my_payment && in_array($my_payment['status'], ['paid','completed'], true)): ?>
                        <!-- Already paid -->
                        <div style="background:#DCFCE7;border-radius:8px;padding:14px;text-align:center;">
                            <span style="color:#166534;font-weight:700;font-size:15px;">✓ <?= $t['payment_paid'] ?></span>
                            <br><a href="<?= APP_URL ?>/pages/my-orders.php" style="font-size:12px;color:#16A34A;margin-top:4px;display:inline-block;">View your order →</a>
                        </div>

                        <?php elseif ($my_payment && $my_payment['status'] === 'pending' && !empty($my_payment['payment_url'])): ?>
                        <!-- Pending payment — show Pay Now button -->
                        <a href="<?= htmlspecialchars($my_payment['payment_url']) ?>"
                           class="btn btn-primary btn-full btn-lg"
                           style="text-align:center;display:block;text-decoration:none;"
                           id="pay-now-btn">
                            💳 <?= $t['group_pay'] ?> — <?= format_ils((float)$group['group_price_ils']) ?>
                        </a>
                        <p style="font-size:11px;color:var(--gray-400);text-align:center;margin-top:8px;">
                            You'll be redirected to a secure payment page.
                        </p>

                        <?php elseif ($my_payment && $my_payment['status'] === 'failed'): ?>
                        <!-- Failed payment — retry -->
                        <div style="background:#FEF3C7;border-radius:8px;padding:12px;margin-bottom:12px;text-align:center;">
                            <span style="color:#92400E;font-weight:600;">⚠️ <?= $t['payment_failed_retry'] ?></span>
                        </div>
                        <button class="btn btn-primary btn-full btn-lg" id="retry-payment-btn"
                                data-group-id="<?= $group_id ?>">
                            🔄 <?= $t['payment_retry'] ?>
                        </button>

                        <?php else: ?>
                        <!-- Payment record not yet generated (should be brief) -->
                        <div style="background:#F3F4F6;border-radius:8px;padding:12px;text-align:center;">
                            <span style="color:var(--gray-600);">Preparing your payment link…</span>
                            <p style="font-size:12px;color:var(--gray-500);margin-top:4px;">Please refresh in a moment.</p>
                        </div>
                        <?php endif; ?>

                    <?php elseif ($group['status'] === 'order_ready'): ?>
                        <?php if ($is_member && in_array($my_status, ['paid'], true)): ?>
                        <div style="background:#DCFCE7;border-radius:8px;padding:14px;text-align:center;">
                            <span style="color:#166534;font-weight:700;font-size:15px;">🎉 <?= $t['group_order_ready'] ?></span>
                            <p style="font-size:12px;color:#16A34A;margin-top:4px;"><?= $t['group_order_ready_desc'] ?></p>
                            <a href="<?= APP_URL ?>/pages/my-orders.php" style="font-size:13px;color:#15803D;font-weight:600;display:inline-block;margin-top:6px;">View your order →</a>
                        </div>
                        <?php endif; ?>

                    <?php elseif ($group['status'] === 'closed'): ?>
                        <?php if ($my_status === 'paid'): ?>
                        <div style="background:#DCFCE7;border-radius:8px;padding:12px;text-align:center;">
                            <span style="color:var(--success);font-weight:600;">✓ Payment complete!</span>
                            <br><a href="<?= APP_URL ?>/pages/my-orders.php" style="font-size:12px;">View your order →</a>
                        </div>
                        <?php elseif ($is_member): ?>
                        <div style="background:#FEF3C7;border-radius:8px;padding:12px;text-align:center;">
                            <span style="color:#92400E;font-weight:600;">Processing payment…</span>
                            <br><span style="font-size:12px;color:#92400E;">Your authorization is being captured. Refresh in a moment.</span>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Members List -->
            <div class="card" style="margin-bottom:16px;">
                <div class="card-header">
                    <strong><?= $t['group_members_title'] ?></strong>
                    <?php if ($paid_count > 0): ?>
                    <span style="margin-left:auto;font-size:11px;color:var(--gray-500);font-weight:500;">
                        <?= $paid_count ?>/<?= $total_member_count ?> paid
                    </span>
                    <?php endif; ?>
                </div>

                <?php if ($is_member && !empty($pay_progress_by_user)): ?>
                <!-- Payment collection progress — visible to all group members -->
                <div style="padding:14px 16px 10px;border-bottom:1px solid var(--border);">

                    <!-- Amount summary -->
                    <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:8px;">
                        <span style="font-size:13px;font-weight:700;color:var(--gray-900);">
                            💰 <?= format_ils($total_collected) ?> collected
                        </span>
                        <span style="font-size:11px;color:var(--gray-400);">
                            of <?= format_ils($total_expected) ?> total
                        </span>
                    </div>

                    <!-- Progress bar -->
                    <?php
                        $collect_pct = $total_expected > 0
                            ? min(100, (int)round($total_collected / $total_expected * 100))
                            : 0;
                        $bar_color = $collect_pct >= 100
                            ? 'var(--green)'
                            : ($collect_pct >= 50 ? 'var(--primary)' : 'var(--orange)');
                    ?>
                    <div style="height:8px;background:var(--gray-200);border-radius:999px;overflow:hidden;margin-bottom:6px;">
                        <div style="height:100%;width:<?= $collect_pct ?>%;background:<?= $bar_color ?>;border-radius:999px;transition:width .4s;"></div>
                    </div>

                    <!-- X of Y paid label -->
                    <div style="font-size:11px;color:var(--gray-500);">
                        <?= $paid_count ?> of <?= $total_member_count ?> member<?= $total_member_count !== 1 ? 's' : '' ?> paid
                        <?php if ($paid_count < $total_member_count): ?>
                        · <span style="color:var(--orange);font-weight:600;"><?= $total_member_count - $paid_count ?> still pending</span>
                        <?php else: ?>
                        · <span style="color:var(--green);font-weight:600;">All paid ✓</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="card-body" style="padding:12px;">
                    <?php foreach ($members as $m):
                        $initials  = strtoupper(substr($m['full_name'], 0, 1));
                        $is_me     = $user_id && (int)$m['user_id'] === $user_id;
                        $pay_row   = $pay_progress_by_user[(int)$m['user_id']] ?? null;
                        $mem_paid  = $pay_row && in_array($pay_row['pay_status'], ['paid','completed'], true);
                        $mem_name  = htmlspecialchars($m['full_name']) . ($is_me ? ' (you)' : '');
                    ?>
                    <div style="display:flex;align-items:center;gap:10px;padding:7px 0;<?= $pay_row && !$mem_paid ? 'opacity:.75;' : '' ?>">
                        <div style="width:32px;height:32px;border-radius:50%;background:<?= $mem_paid ? '#166534' : 'var(--purple)' ?>;color:white;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;flex-shrink:0;">
                            <?php if ($mem_paid): ?>✓<?php else: ?><?= $initials ?><?php endif; ?>
                        </div>
                        <div style="flex:1;min-width:0;">
                            <div style="font-size:13px;font-weight:<?= $is_me ? '700' : '500' ?>;">
                                <?= $mem_name ?>
                            </div>
                            <?php if ($pay_row && $mem_paid && $pay_row['paid_at']): ?>
                            <div style="font-size:10.5px;color:var(--gray-400);">
                                Paid <?= date('d/m H:i', strtotime($pay_row['paid_at'])) ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php if ($pay_row): ?>
                            <?php if ($mem_paid): ?>
                            <div style="text-align:right;flex-shrink:0;">
                                <span class="card-badge badge-paid"><?= $t['group_paid_badge'] ?></span>
                                <div style="font-size:10.5px;color:var(--gray-400);margin-top:2px;"><?= format_ils((float)$pay_row['amount_ils']) ?></div>
                            </div>
                            <?php else: ?>
                            <span style="font-size:11px;font-weight:600;color:var(--orange);background:#FEF3C7;border-radius:6px;padding:2px 8px;flex-shrink:0;">
                                Pending
                            </span>
                            <?php endif; ?>
                        <?php elseif ($m['status'] === 'paid'): ?>
                        <span class="card-badge badge-paid" style="margin-left:auto;"><?= $t['group_paid_badge'] ?></span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>

                    <!-- Empty spots (capped at 5 to avoid large DOM) -->
                    <?php $open = max(0, (int)$group['target_participants'] - count($members)); ?>
                    <?php for ($i = 0; $i < min($open, 5); $i++): ?>
                    <div style="display:flex;align-items:center;gap:10px;padding:6px 0;opacity:.4;">
                        <div style="width:32px;height:32px;border-radius:50%;border:2px dashed var(--gray-300);display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;">+</div>
                        <span style="font-size:13px;color:var(--gray-500);">Open spot</span>
                    </div>
                    <?php endfor; ?>
                    <?php if ($open > 5): ?>
                    <div style="font-size:12px;color:var(--gray-500);text-align:center;padding:4px 0;">+<?= $open - 5 ?> more open spots</div>
                    <?php endif; ?>
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
    var map = SmartCartMaps.createMap('map-group', BIZ_LAT, BIZ_LNG, 16);
    if (!map) return;
    SmartCartMaps.addMarker(map, BIZ_LAT, BIZ_LNG, <?= json_encode('<strong>' . htmlspecialchars($group['business_name']) . '</strong><br>' . htmlspecialchars($group['biz_address'] ?? $group['biz_city'] ?? '')) ?>);
})();


// Scroll chat to bottom
const chatMsgs = document.querySelector('.chat-messages');
if (chatMsgs) chatMsgs.scrollTop = chatMsgs.scrollHeight;

// Live countdown on group deadline
(function() {
    const el = document.getElementById('group-countdown');
    if (!el) return;
    const deadline = parseInt(el.dataset.deadline, 10) * 1000;
    function fmt(ms) {
        const s = Math.max(0, Math.floor(ms / 1000));
        if (s === 0) { el.textContent = 'Expired'; return; }
        const h = Math.floor(s / 3600);
        const m = Math.floor((s % 3600) / 60);
        const sec = s % 60;
        if (h >= 48) { el.textContent = Math.floor(h / 24) + 'd left'; return; }
        if (h >= 1)  { el.textContent = h + 'h ' + String(m).padStart(2,'0') + 'm left'; return; }
        el.textContent = String(m).padStart(2,'0') + ':' + String(sec).padStart(2,'0') + ' left';
    }
    fmt(deadline - Date.now());
    setInterval(() => fmt(deadline - Date.now()), 1000);
})();

// ── Retry failed payment ──────────────────────────────────
(function() {
    const retryBtn = document.getElementById('retry-payment-btn');
    if (!retryBtn) return;
    retryBtn.addEventListener('click', async function() {
        retryBtn.disabled = true;
        retryBtn.textContent = 'Generating link…';
        try {
            const res  = await fetch('<?= APP_URL ?>/api/payments.php?action=retry&group_id=<?= $group_id ?>', { method: 'POST', credentials: 'same-origin' });
            const data = await res.json();
            if (data.payment_url) {
                window.location.href = data.payment_url;
            } else if (data.already_paid) {
                location.reload();
            } else {
                alert(data.error || 'Could not generate a payment link. Please try again later.');
                retryBtn.disabled = false;
                retryBtn.textContent = '🔄 Retry Payment';
            }
        } catch (e) {
            alert('Network error. Please try again.');
            retryBtn.disabled = false;
            retryBtn.textContent = '🔄 Retry Payment';
        }
    });
})();

// Live participant count polling (every 15s)
<?php if ($group['status'] === 'open'): ?>
(function() {
    const countEl = document.getElementById('live-count');
    if (!countEl) return;
    setInterval(async function() {
        try {
            const res  = await fetch('<?= APP_URL ?>/api/groups.php?action=get&id=<?= $group_id ?>');
            const data = await res.json();
            if (data && data.current_participants !== undefined) {
                countEl.innerHTML = '<strong>' + data.current_participants + '</strong> / <?= $group['target_participants'] ?> <?= $t['group_members_count'] ?>';
            }
        } catch(e) {}
    }, 15000);
})();
<?php endif; ?>
</script>
