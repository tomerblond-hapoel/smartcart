<?php
// SmartCart — Group Expiration Cron
//
// Run every minute (or every few minutes) to handle two expiry cases:
//
//  1. Open groups whose deadline passed without filling → status=failed
//  2. ready_for_payment groups whose 24-hour payment window expired
//     with at least one member still unpaid → status=failed, expire payments
//
// CLI usage:  php /path/to/smartcart/cron/check_groups.php
// Crontab:    * * * * * /usr/bin/php /home/USER/public_html/smartcart/cron/check_groups.php >> /home/USER/cron.log 2>&1

if (php_sapi_name() !== 'cli' && empty($_GET['cron_token'])) {
    http_response_code(403);
    exit("Forbidden\n");
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

$pdo = getPDO();

// ── 1. Expire open groups whose deadline passed without filling ───────────────
$stmt = $pdo->prepare("
    SELECT id FROM group_purchases
    WHERE status = 'open'
      AND deadline < NOW()
      AND current_participants < target_participants
");
$stmt->execute();
$open_expired = array_column($stmt->fetchAll(), 'id');

foreach ($open_expired as $gid) {
    $pdo->prepare("UPDATE group_purchases SET status='failed' WHERE id=?")->execute([$gid]);
    notify_group_members((int)$gid, 'group_failed',
        'Group cancelled — not enough participants',
        'The group did not reach its target by the deadline. No payment was taken.',
        APP_URL . '/pages/my-groups.php');
}

// ── 2. Expire ready_for_payment groups past their 24-hour payment window ──────
$stmt = $pdo->prepare("
    SELECT id FROM group_purchases
    WHERE status = 'ready_for_payment'
      AND payment_deadline IS NOT NULL
      AND payment_deadline < NOW()
");
$stmt->execute();
$pay_expired = array_column($stmt->fetchAll(), 'id');

foreach ($pay_expired as $gid) {
    // Mark group as failed
    $pdo->prepare("UPDATE group_purchases SET status='failed' WHERE id=?")->execute([$gid]);

    // Expire all unpaid payment records for this group
    $pdo->prepare("
        UPDATE payments
        SET    status = 'expired'
        WHERE  group_id = ?
          AND  status NOT IN ('paid', 'completed', 'failed', 'expired')
    ")->execute([$gid]);

    // Notify all members
    notify_group_members((int)$gid, 'group_failed',
        'Group cancelled — payment window expired',
        'Not all members completed payment within 24 hours. The group has been cancelled. No charge was made.',
        APP_URL . '/pages/my-groups.php');
}

// ── 3. Also expire open groups that hit deadline even if they were full ────────
// (edge case: group filled exactly at deadline)
$stmt = $pdo->prepare("
    SELECT id FROM group_purchases
    WHERE status = 'ready_for_payment'
      AND deadline < NOW()
      AND payment_deadline IS NULL
");
$stmt->execute();
$deadline_expired = array_column($stmt->fetchAll(), 'id');

foreach ($deadline_expired as $gid) {
    $pdo->prepare("UPDATE group_purchases SET status='failed' WHERE id=?")->execute([$gid]);
    $pdo->prepare("
        UPDATE payments SET status='expired'
        WHERE group_id=? AND status NOT IN ('paid','completed','failed','expired')
    ")->execute([$gid]);
    notify_group_members((int)$gid, 'group_failed',
        'Group cancelled — deadline passed',
        'The group deadline expired before all payments were completed. No charge was made.',
        APP_URL . '/pages/my-groups.php');
}

$total_expired = count($open_expired) + count($pay_expired) + count($deadline_expired);
$msg = '[' . date('Y-m-d H:i:s') . '] '
     . 'open_expired=' . count($open_expired) . ' '
     . 'pay_window_expired=' . count($pay_expired) . ' '
     . 'deadline_expired=' . count($deadline_expired) . ' '
     . 'total=' . $total_expired;
echo $msg . "\n";
