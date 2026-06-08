<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();
$user_id    = current_user_id();
$page_title = $t['my_groups_title'];

$pdo = getPDO();
$stmt = $pdo->prepare("
    SELECT gp.id, gp.status, gp.target_participants, gp.current_participants, gp.deadline,
           p.name AS product_name, p.image_url, p.group_price_ils, p.price_ils,
           ROUND(gp.current_participants / gp.target_participants * 100) AS fill_pct,
           gm.status AS member_status,
           b.business_name
    FROM group_members gm
    JOIN group_purchases gp ON gp.id = gm.group_id
    JOIN products p ON p.id = gp.product_id
    JOIN businesses b ON b.id = p.business_id
    WHERE gm.user_id = ?
    ORDER BY gp.created_at DESC
");
$stmt->execute([$user_id]);
$all_groups = $stmt->fetchAll();

$active   = array_filter($all_groups, fn($g) => $g['status'] === 'open' && $g['member_status'] === 'joined');
$awaiting = array_filter($all_groups, fn($g) => in_array($g['status'], ['closed', 'ready_for_payment', 'order_ready']) && $g['member_status'] === 'joined');
$completed= array_filter($all_groups, fn($g) => $g['member_status'] === 'paid');
$failed   = array_filter($all_groups, fn($g) => $g['status'] === 'failed');

include __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding-top:32px;padding-bottom:60px;">
    <div class="page-header">
        <h1><?= $t['my_groups_title'] ?></h1>
    </div>

    <div class="tabs">
        <button class="tab-btn active" data-tab="active"><?= $t['tab_active'] ?> (<?= count($active) ?>)</button>
        <button class="tab-btn" data-tab="awaiting"><?= $t['tab_awaiting'] ?> (<?= count($awaiting) ?>)</button>
        <button class="tab-btn" data-tab="completed"><?= $t['tab_completed'] ?> (<?= count($completed) ?>)</button>
        <button class="tab-btn" data-tab="failed"><?= $t['tab_failed'] ?> (<?= count($failed) ?>)</button>
    </div>

    <?php
    $tabs = [
        'active'    => [$active,    $t['no_groups_active'],   'btn-ghost', $t['group_leave']],
        'awaiting'  => [$awaiting,  $t['no_groups_awaiting'], 'btn-primary', $t['group_pay']],
        'completed' => [$completed, $t['no_groups_done'],     'btn-outline', 'View Order'],
        'failed'    => [$failed,    $t['no_groups_failed'],   '', ''],
    ];
    foreach ($tabs as $tab_id => [$groups, $empty_msg, $btn_class, $btn_label]): ?>
    <div class="tab-panel <?= $tab_id === 'active' ? 'active' : '' ?>" id="tab-<?= $tab_id ?>">
        <?php if (empty($groups)): ?>
        <div class="empty-state"><p><?= $empty_msg ?></p></div>
        <?php else: ?>
        <div style="display:grid;gap:12px;">
            <?php foreach ($groups as $g):
                $fill = (int)$g['fill_pct'];
                $fill_class = $fill >= 80 ? 'high' : ($fill >= 50 ? 'medium' : '');
            ?>
            <div class="card">
                <div class="card-body" style="display:flex;gap:16px;align-items:center;">
                    <?php if ($g['image_url']): ?>
                    <img src="<?= img_url($g['image_url']) ?>" style="width:64px;height:64px;object-fit:cover;border-radius:8px;flex-shrink:0;">
                    <?php endif; ?>
                    <div style="flex:1;">
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:4px;">
                            <h3 style="font-size:15px;font-weight:600;"><?= htmlspecialchars($g['product_name']) ?></h3>
                            <span class="card-badge badge-<?= $g['status'] ?>"><?= $t['group_' . $g['status']] ?></span>
                        </div>
                        <p style="font-size:12px;color:var(--gray-500);margin-bottom:8px;"><?= htmlspecialchars($g['business_name']) ?> · <?= format_ils($g['group_price_ils']) ?></p>
                        <div class="progress-wrap" style="margin-bottom:4px;">
                            <div class="progress-bar <?= $fill_class ?>" style="width:<?= $fill ?>%;"></div>
                        </div>
                        <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--gray-500);">
                            <span><?= $g['current_participants'] ?>/<?= $g['target_participants'] ?> members</span>
                            <span>⏰ <?= countdown_label($g['deadline']) ?></span>
                        </div>
                    </div>
                    <div style="flex-shrink:0;display:flex;flex-direction:column;gap:8px;">
                        <a href="<?= APP_URL ?>/pages/group.php?id=<?= $g['id'] ?>" class="btn btn-sm btn-outline">View</a>
                        <?php if ($btn_class && $tab_id === 'awaiting'): ?>
                        <a href="<?= APP_URL ?>/pages/group.php?id=<?= $g['id'] ?>" class="btn btn-sm <?= $btn_class ?>"><?= $btn_label ?></a>
                        <?php elseif ($btn_class && $tab_id === 'completed'): ?>
                        <a href="<?= APP_URL ?>/pages/my-orders.php" class="btn btn-sm <?= $btn_class ?>"><?= $btn_label ?></a>
                        <?php elseif ($btn_class && $tab_id === 'active'): ?>
                        <button class="btn btn-sm btn-ghost text-danger" data-action="leave-group" data-group-id="<?= $g['id'] ?>"><?= $btn_label ?></button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
