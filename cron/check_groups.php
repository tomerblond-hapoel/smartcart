<?php
// SmartCart — Group Expiration Cron
//
// Run every minute to expire open groups whose deadline passed without filling.
// Voids all authorized payments for those groups.
//
// CLI usage:  php /path/to/smartcart/cron/check_groups.php
// Crontab:    * * * * * /usr/bin/php /home/USER/public_html/smart/cron/check_groups.php >> /home/USER/cron.log 2>&1

// Allow CLI without web context
if (php_sapi_name() !== 'cli' && empty($_GET['cron_token'])) {
    http_response_code(403);
    exit("Forbidden\n");
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/paypal.php';

// Pull the implementation out of api/groups.php without booting the HTTP router.
// We inline the function definition since requiring api/groups.php executes its router.

function _cron_paypal_configured(): bool {
    return defined('PAYPAL_CLIENT_ID')
        && strpos(PAYPAL_CLIENT_ID, 'your_paypal') === false
        && !empty(PAYPAL_CLIENT_ID);
}

function _cron_void_pending_for_group(int $group_id): int {
    $pdo = getPDO();
    $stmt = $pdo->prepare("SELECT id, user_id, paypal_auth_id FROM payments WHERE group_id = ? AND auth_status = 'authorized'");
    $stmt->execute([$group_id]);
    $voided = 0;
    foreach ($stmt->fetchAll() as $row) {
        if (_cron_paypal_configured() && strpos($row['paypal_auth_id'], 'DEV-') !== 0) {
            $res = paypal_void($row['paypal_auth_id']);
            if (!$res['ok']) {
                $pdo->prepare("UPDATE payments SET last_error=? WHERE id=?")
                    ->execute([substr($res['error'],0,500), $row['id']]);
                continue;
            }
        }
        $pdo->prepare("UPDATE payments SET auth_status='voided', voided_at=NOW() WHERE id=?")
            ->execute([$row['id']]);
        $pdo->prepare("UPDATE group_members SET deposit_status='voided' WHERE group_id=? AND user_id=?")
            ->execute([$group_id, $row['user_id']]);
        $voided++;
    }
    return $voided;
}

$pdo = getPDO();
$stmt = $pdo->prepare("
    SELECT id FROM group_purchases
    WHERE status = 'open'
      AND deadline < NOW()
      AND current_participants < target_participants
");
$stmt->execute();
$ids = array_column($stmt->fetchAll(), 'id');

$voided_total = 0;
foreach ($ids as $gid) {
    $pdo->prepare("UPDATE group_purchases SET status='failed' WHERE id=?")->execute([$gid]);
    $voided_total += _cron_void_pending_for_group((int)$gid);
}

$msg = '[' . date('Y-m-d H:i:s') . '] expired ' . count($ids) . ' group(s), voided ' . $voided_total . ' authorization(s)';
echo $msg . "\n";
