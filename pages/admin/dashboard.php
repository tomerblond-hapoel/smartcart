<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/lang.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/functions.php';

require_admin();
$page_title = $t['admin_dashboard'];
$pdo = getPDO();

// Platform stats
$stats = [];
foreach ([
    'users'     => "SELECT COUNT(*) FROM users",
    'businesses'=> "SELECT COUNT(*) FROM businesses",
    'products'  => "SELECT COUNT(*) FROM products WHERE status='active'",
    'groups'    => "SELECT COUNT(*) FROM group_purchases WHERE status='open'",
    'payments'  => "SELECT COALESCE(SUM(amount_ils),0) FROM payments WHERE status='completed'",
    'orders_today' => "SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()",
] as $key => $sql) {
    $stats[$key] = $pdo->query($sql)->fetchColumn();
}

// Recent users
$users   = $pdo->query("SELECT id,email,full_name,role,is_active,created_at FROM users ORDER BY created_at DESC LIMIT 20")->fetchAll();
// All groups
$groups  = $pdo->query("
    SELECT gp.id, gp.status, gp.current_participants, gp.target_participants, gp.deadline,
           p.name AS product_name, ROUND(gp.current_participants/gp.target_participants*100) AS fill_pct
    FROM group_purchases gp JOIN products p ON p.id = gp.product_id
    ORDER BY gp.created_at DESC LIMIT 30
")->fetchAll();
// Recent payments
$payments = $pdo->query("
    SELECT pay.*, u.full_name, u.email, p.name AS product_name
    FROM payments pay JOIN users u ON u.id = pay.user_id
    JOIN group_purchases gp ON gp.id = pay.group_id
    JOIN products p ON p.id = gp.product_id
    ORDER BY pay.created_at DESC LIMIT 20
")->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div class="container" style="padding-top:32px;padding-bottom:60px;">
    <div class="page-header">
        <h1>⚙️ <?= $t['admin_dashboard'] ?></h1>
    </div>

    <!-- Stats -->
    <div class="stats-bar" style="grid-template-columns:repeat(3,1fr);margin-bottom:28px;">
        <div class="stat-card"><div class="stat-value"><?= $stats['users'] ?></div><div class="stat-label">Total Users</div></div>
        <div class="stat-card"><div class="stat-value"><?= $stats['businesses'] ?></div><div class="stat-label">Businesses</div></div>
        <div class="stat-card"><div class="stat-value"><?= $stats['products'] ?></div><div class="stat-label">Active Products</div></div>
        <div class="stat-card"><div class="stat-value"><?= $stats['groups'] ?></div><div class="stat-label">Open Groups</div></div>
        <div class="stat-card"><div class="stat-value"><?= format_ils($stats['payments']) ?></div><div class="stat-label">Total Revenue</div></div>
        <div class="stat-card"><div class="stat-value"><?= $stats['orders_today'] ?></div><div class="stat-label">Orders Today</div></div>
    </div>

    <!-- Users -->
    <div class="card" style="margin-bottom:24px;">
        <div class="card-header"><strong>Users</strong></div>
        <div class="card-body" style="padding:0;">
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Joined</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($users as $u): ?>
                    <tr>
                        <td><?= htmlspecialchars($u['full_name']) ?></td>
                        <td><?= htmlspecialchars($u['email']) ?></td>
                        <td><span class="card-badge badge-joined"><?= $u['role'] ?></span></td>
                        <td><span style="color:<?= $u['is_active'] ? 'var(--success)' : 'var(--danger)' ?>;"><?= $u['is_active'] ? 'Active' : 'Disabled' ?></span></td>
                        <td style="font-size:12px;"><?= date('d/m/Y', strtotime($u['created_at'])) ?></td>
                        <td style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                            <select class="form-control" style="font-size:12px;padding:4px 8px;width:auto;" onchange="updateUserRole(<?= $u['id'] ?>, this.value)">
                                <?php foreach (['customer','business','admin'] as $r): ?>
                                <option value="<?= $r ?>" <?= $u['role']===$r?'selected':'' ?>><?= ucfirst($r) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-sm <?= $u['is_active'] ? 'btn-danger' : 'btn-outline' ?>"
                                onclick="toggleUser(<?= $u['id'] ?>, <?= $u['is_active'] ? 'false' : 'true' ?>)"
                                title="<?= $u['is_active'] ? 'Disable user' : 'Enable user' ?>">
                                <?= $u['is_active'] ? 'Disable' : 'Enable' ?>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Groups -->
    <div class="card" style="margin-bottom:24px;">
        <div class="card-header"><strong>Recent Groups</strong></div>
        <div class="card-body" style="padding:0;">
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Product</th><th>Progress</th><th>Deadline</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($groups as $g): ?>
                    <tr>
                        <td><?= htmlspecialchars($g['product_name']) ?></td>
                        <td>
                            <div class="progress-wrap" style="min-width:100px;"><div class="progress-bar" style="width:<?= $g['fill_pct'] ?>%;"></div></div>
                            <small><?= $g['current_participants'] ?>/<?= $g['target_participants'] ?></small>
                        </td>
                        <td style="font-size:12px;"><?= countdown_label($g['deadline']) ?></td>
                        <td><span class="card-badge badge-<?= $g['status'] ?>"><?= $g['status'] ?></span></td>
                        <td>
                            <a href="<?= APP_URL ?>/pages/group.php?id=<?= $g['id'] ?>" class="btn btn-sm btn-outline">View</a>
                            <?php if ($g['status'] === 'open'): ?>
                            <button class="btn btn-sm btn-danger" onclick="expireGroup(<?= $g['id'] ?>)">Fail</button>
                            <?php elseif ($g['status'] === 'closed'): ?>
                            <button class="btn btn-sm btn-danger" onclick="refundGroup(<?= $g['id'] ?>)">Refund</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Payments -->
    <div class="card">
        <div class="card-header"><strong>Recent Payments</strong></div>
        <div class="card-body" style="padding:0;">
            <div class="table-wrap">
                <table>
                    <thead><tr><th>User</th><th>Product</th><th>Amount</th><th>Status</th><th>PayPal TX</th><th>Date</th></tr></thead>
                    <tbody>
                    <?php foreach ($payments as $p): ?>
                    <tr>
                        <td><?= htmlspecialchars($p['full_name']) ?><br><small class="text-muted"><?= htmlspecialchars($p['email']) ?></small></td>
                        <td><?= htmlspecialchars($p['product_name']) ?></td>
                        <td><?= format_ils($p['amount_ils']) ?></td>
                        <td><span class="card-badge badge-<?= $p['status'] === 'completed' ? 'paid' : 'failed' ?>"><?= $p['status'] ?></span></td>
                        <td style="font-size:11px;"><?= htmlspecialchars($p['paypal_transaction_id'] ?? '—') ?></td>
                        <td style="font-size:12px;"><?= date('d/m/Y', strtotime($p['created_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

<script>
async function updateUserRole(userId, role) {
    try {
        await SmartCart.api('<?= APP_URL ?>/api/admin.php?action=update_user_role', {
            method: 'POST', body: JSON.stringify({ user_id: userId, role }),
        });
        SmartCart.showToast('Role updated.');
    } catch(err) {
        SmartCart.showToast(err.message, 'error');
    }
}
async function expireGroup(groupId) {
    if (!confirm('Mark this group as failed and void all authorizations?')) return;
    try {
        await SmartCart.api(`<?= APP_URL ?>/api/admin.php?action=fail_group&id=${groupId}`, { method: 'POST', body: '{}' });
        SmartCart.showToast('Group marked as failed.');
        setTimeout(() => location.reload(), 800);
    } catch(err) {
        SmartCart.showToast(err.message, 'error');
    }
}
async function refundGroup(groupId) {
    const reason = prompt('Refund reason (shown to customers):', 'Group cancelled by admin');
    if (reason === null) return;
    try {
        const res = await SmartCart.api(`<?= APP_URL ?>/api/admin.php?action=refund_group&id=${groupId}`, {
            method: 'POST', body: JSON.stringify({ reason }),
        });
        SmartCart.showToast(`Refunded ${res.refunded} payment(s).`);
        if (res.failed > 0) SmartCart.showToast(`${res.failed} refund(s) failed — check logs.`, 'error');
        setTimeout(() => location.reload(), 1200);
    } catch(err) {
        SmartCart.showToast(err.message, 'error');
    }
}
async function toggleUser(userId, enable) {
    const action = enable ? 'enable' : 'disable';
    if (!confirm((enable ? 'Enable' : 'Disable') + ' this user?')) return;
    try {
        await SmartCart.api('<?= APP_URL ?>/api/admin.php?action=disable_user', {
            method: 'POST', body: JSON.stringify({ user_id: userId, enable: enable }),
        });
        SmartCart.showToast('User ' + (enable ? 'enabled' : 'disabled') + '.');
        setTimeout(() => location.reload(), 800);
    } catch(err) {
        SmartCart.showToast(err.message, 'error');
    }
}
</script>
