<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();
$user_id    = current_user_id();
$page_title = 'Notifications';

$pdo  = getPDO();
$stmt = $pdo->prepare("SELECT id, type, title, body, link_url, read_at, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 100");
$stmt->execute([$user_id]);
$notifications = $stmt->fetchAll();

$unread = 0;
foreach ($notifications as $n) if (empty($n['read_at'])) $unread++;

include __DIR__ . '/../includes/header.php';
?>

<div class="container container-md" style="padding-top:32px;padding-bottom:60px;">
    <div class="page-header" style="display:flex;justify-content:space-between;align-items:center;">
        <h1>
            Notifications
            <?php if ($unread): ?>
            <span style="background:var(--primary);color:#fff;font-size:12px;font-weight:700;padding:3px 10px;border-radius:999px;vertical-align:middle;margin-left:8px;"><?= $unread ?> unread</span>
            <?php endif; ?>
        </h1>
        <?php if ($unread): ?>
        <button class="btn btn-ghost btn-sm" onclick="markAllRead()">Mark all read</button>
        <?php endif; ?>
    </div>

    <?php if (empty($notifications)): ?>
    <div class="empty-state" style="padding:60px 20px;text-align:center;">
        <div style="font-size:48px;margin-bottom:12px;">🔔</div>
        <h3>No notifications yet</h3>
        <p style="color:var(--gray-500);">You'll see updates about your groups and orders here.</p>
    </div>
    <?php else: ?>
    <div class="card">
        <div class="card-body" style="padding:0;">
            <?php foreach ($notifications as $n):
                $is_unread = empty($n['read_at']);
                $when = strtotime($n['created_at']);
                $rel = (time() - $when < 3600) ? round((time()-$when)/60) . ' min ago'
                     : ((time() - $when < 86400) ? round((time()-$when)/3600) . ' hr ago'
                     : date('d/m/Y H:i', $when));
            ?>
            <div onclick="openNotif(<?= $n['id'] ?>, <?= $n['link_url'] ? json_encode($n['link_url']) : 'null' ?>)"
                 style="display:flex;gap:12px;padding:14px 16px;border-bottom:1px solid var(--border);cursor:pointer;<?= $is_unread ? 'background:var(--primary-50);' : '' ?>"
                 onmouseover="this.style.background='var(--gray-100)'"
                 onmouseout="this.style.background='<?= $is_unread ? 'var(--primary-50)' : '' ?>'">
                <div style="width:36px;height:36px;border-radius:50%;background:var(--primary-light);color:var(--primary);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-weight:700;">
                    <?php
                    $icon_map = [
                        'welcome' => '👋','group_joined' => '✓','group_locked' => '🎉','group_failed' => '⏰',
                        'order_pending' => '📦','order_processing' => '📦','order_shipped' => '🚚','order_delivered' => '🏠',
                        'order_refunded' => '💸','password_reset' => '🔑',
                    ];
                    echo $icon_map[$n['type']] ?? '🔔';
                    ?>
                </div>
                <div style="flex:1;min-width:0;">
                    <div style="display:flex;justify-content:space-between;gap:10px;margin-bottom:2px;">
                        <strong style="font-size:14px;<?= $is_unread ? 'color:var(--primary)' : 'color:var(--gray-900)' ?>;"><?= htmlspecialchars($n['title']) ?></strong>
                        <span style="font-size:11px;color:var(--gray-400);white-space:nowrap;"><?= $rel ?></span>
                    </div>
                    <?php if ($n['body']): ?>
                    <div style="font-size:13px;color:var(--gray-600);line-height:1.4;"><?= nl2br(htmlspecialchars($n['body'])) ?></div>
                    <?php endif; ?>
                </div>
                <?php if ($is_unread): ?>
                <div style="width:8px;height:8px;border-radius:50%;background:var(--primary);align-self:center;flex-shrink:0;"></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<script>
async function openNotif(id, link) {
    try {
        await SmartCart.api('<?= APP_URL ?>/api/notifications.php?action=mark_read&id=' + id, {
            method: 'POST', body: '{}',
        });
    } catch (e) {}
    if (link) window.location.href = link;
}
async function markAllRead() {
    try {
        await SmartCart.api('<?= APP_URL ?>/api/notifications.php?action=mark_all_read', {
            method: 'POST', body: '{}',
        });
        location.reload();
    } catch (err) {
        SmartCart.showToast(err.message, 'error');
    }
}
</script>
