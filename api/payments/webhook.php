<?php
/**
 * SmartCart — Payment Provider Webhook
 * POST /api/payments/webhook.php
 *
 * Called by the payment provider (PayMe / Meshulam / mock) after a payment
 * succeeds or fails.  Never called by the browser directly.
 *
 * Flow:
 *  1. Verify provider signature / token.
 *  2. Parse provider-specific body to extract transaction_id + status.
 *  3. Find the payment record by provider_transaction_id.
 *  4. Update payments.status → paid / failed; set paid_at when paid.
 *  5. If paid: mark group_members.status = 'paid'; create orders row.
 *  6. Check if ALL group members have paid → update group to order_ready.
 *
 * PHP 7.4 compatible.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../services/PaymentService.php';

// Webhook must be POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

$raw_body = file_get_contents('php://input');

// Collect all headers (works on Apache and cPanel / PHP-FPM)
if (function_exists('getallheaders')) {
    $headers = getallheaders();
} else {
    $headers = [];
    foreach ($_SERVER as $k => $v) {
        if (strpos($k, 'HTTP_') === 0) {
            $name = str_replace('_', '-', substr($k, 5));
            $headers[$name] = $v;
        }
    }
}

// ── 1. Verify signature ────────────────────────────────────────────────────
if (!PaymentService::verifyWebhook($raw_body, $headers)) {
    PaymentService::log('webhook_invalid_signature', [
        'ip'      => $_SERVER['REMOTE_ADDR'] ?? '',
        'headers' => array_intersect_key($headers, array_flip(['Authorization','X-Webhook-Token','X-Meshulam-Signature'])),
    ]);
    http_response_code(401);
    exit('Unauthorized');
}

// ── 2. Parse provider body ─────────────────────────────────────────────────
$provider = defined('PAYMENT_PROVIDER') ? strtolower(trim(PAYMENT_PROVIDER)) : 'mock';
$data     = json_decode($raw_body, true) ?: [];

// Normalise provider-specific field names to a unified set:
//   $tx_id   — provider's transaction / sale id
//   $new_status — 'paid' | 'failed'
$tx_id     = '';
$new_status = '';

switch ($provider) {

    case 'payme':
        // PayMe sends: { "sale_payme_id": "...", "sale_status": "success|error", ... }
        $tx_id = $data['sale_payme_id'] ?? '';
        $new_status = strtolower($data['sale_status'] ?? '') === 'success' ? 'paid' : 'failed';
        break;

    case 'meshulam':
        // Meshulam sends: { "transaction_id": "...", "status": 1|0, ... }
        $tx_id = $data['transaction_id'] ?? ($data['data']['transaction_id'] ?? '');
        $mesh_status = (int)($data['status'] ?? ($data['data']['status'] ?? 0));
        $new_status  = $mesh_status === 1 ? 'paid' : 'failed';
        break;

    case 'mock':
    default:
        // Mock sends: { "payment_id": X, "status": "paid"|"failed", "transaction_id": "..." }
        // (fired internally by mock_confirm.php)
        $tx_id      = $data['transaction_id'] ?? '';
        $new_status = in_array($data['status'] ?? '', ['paid', 'failed'], true) ? $data['status'] : 'failed';

        // Mock mode also accepts payment_id directly (no tx lookup needed)
        if (empty($tx_id) && !empty($data['payment_id'])) {
            process_payment_by_id((int)$data['payment_id'], $new_status);
            echo json_encode(['ok' => true]);
            exit;
        }
        break;
}

if (!$tx_id) {
    PaymentService::log('webhook_missing_tx_id', ['body' => substr($raw_body, 0, 500)]);
    http_response_code(400);
    exit('Missing transaction id');
}

if (!in_array($new_status, ['paid', 'failed'], true)) {
    // Unknown status — acknowledge so provider stops retrying, but don't update
    http_response_code(200);
    echo json_encode(['ok' => true, 'note' => 'status ignored']);
    exit;
}

// ── 3. Find payment by provider_transaction_id ────────────────────────────
$pdo  = getPDO();
$stmt = $pdo->prepare("SELECT id FROM payments WHERE provider_transaction_id = ? LIMIT 1");
$stmt->execute([$tx_id]);
$pay  = $stmt->fetch();

if (!$pay) {
    PaymentService::log('webhook_tx_not_found', ['tx_id' => $tx_id]);
    // Return 200 so the provider stops retrying (idempotent)
    http_response_code(200);
    echo json_encode(['ok' => true, 'note' => 'transaction not found']);
    exit;
}

process_payment_by_id((int)$pay['id'], $new_status);
echo json_encode(['ok' => true]);

// ─────────────────────────────────────────────────────────────────────────────
// Core logic: update a payment, create order if paid, check all-paid condition
// ─────────────────────────────────────────────────────────────────────────────
function process_payment_by_id(int $payment_id, string $new_status): void
{
    $pdo = getPDO();

    $pdo->beginTransaction();
    try {
        // Lock payment row
        $stmt = $pdo->prepare("
            SELECT pay.id, pay.status, pay.group_id, pay.user_id,
                   pay.product_id, pay.amount_ils
            FROM payments pay
            WHERE pay.id = ?
            FOR UPDATE
        ");
        $stmt->execute([$payment_id]);
        $pay = $stmt->fetch();

        if (!$pay) {
            $pdo->rollBack();
            PaymentService::log('webhook_pay_row_not_found', ['payment_id' => $payment_id]);
            return;
        }

        // Idempotency: skip if already in a terminal state
        if (in_array($pay['status'], ['paid', 'completed', 'failed'], true)) {
            $pdo->rollBack();
            return;
        }

        if ($new_status === 'paid') {
            // Mark payment paid
            $pdo->prepare("
                UPDATE payments
                SET    status = 'paid',
                       paid_at = NOW()
                WHERE  id = ?
            ")->execute([$payment_id]);

            // Resolve product_id if missing (old rows that predate V4)
            $product_id = (int)($pay['product_id'] ?? 0);
            if (!$product_id) {
                $ps = $pdo->prepare("SELECT product_id FROM group_purchases WHERE id = ?");
                $ps->execute([$pay['group_id']]);
                $product_id = (int)($ps->fetchColumn() ?: 0);
            }

            // Mark group member as paid
            $pdo->prepare("
                UPDATE group_members
                SET    status = 'paid'
                WHERE  group_id = ? AND user_id = ?
            ")->execute([$pay['group_id'], $pay['user_id']]);

            // Create order record (guard against duplicate via INSERT IGNORE)
            if ($product_id) {
                $pdo->prepare("
                    INSERT IGNORE INTO orders
                           (group_id, payment_id, user_id, product_id, amount_paid, shipping_status)
                    VALUES (?, ?, ?, ?, ?, 'pending')
                ")->execute([
                    $pay['group_id'],
                    $payment_id,
                    $pay['user_id'],
                    $product_id,
                    $pay['amount_ils'],
                ]);
            }

            $pdo->commit();

            // Notify payer
            notify(
                (int)$pay['user_id'],
                'payment_confirmed',
                'Payment confirmed!',
                'Your payment has been received. We\'ll notify you when the order is ready.',
                defined('APP_URL') ? APP_URL . '/pages/my-orders.php' : ''
            );

            // Check if all group members have now paid
            check_all_paid((int)$pay['group_id']);

        } else {
            // Mark payment failed
            $pdo->prepare("
                UPDATE payments SET status = 'failed' WHERE id = ?
            ")->execute([$payment_id]);
            $pdo->commit();

            notify(
                (int)$pay['user_id'],
                'payment_failed',
                'Payment failed',
                'Your payment could not be processed. Please try again from the group page.',
                defined('APP_URL') ? APP_URL . '/pages/group.php?id=' . $pay['group_id'] : ''
            );
        }

        PaymentService::log('webhook_processed', [
            'payment_id' => $payment_id,
            'group_id'   => $pay['group_id'],
            'user_id'    => $pay['user_id'],
            'new_status' => $new_status,
        ]);

    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        PaymentService::log('webhook_error', [
            'payment_id' => $payment_id,
            'error'      => $e->getMessage(),
        ]);
    }
}

/**
 * After each successful payment, check whether every group member has paid.
 * If yes → set group status to order_ready and notify the business.
 */
function check_all_paid(int $group_id): void
{
    $pdo = getPDO();

    // Count total members vs paid members
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*)                                       AS total,
            SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) AS paid_count
        FROM group_members
        WHERE group_id = ?
    ");
    $stmt->execute([$group_id]);
    $row = $stmt->fetch();

    if (!$row || (int)$row['total'] === 0) return;
    if ((int)$row['paid_count'] < (int)$row['total']) return;

    // All paid — update group status
    $pdo->prepare("
        UPDATE group_purchases
        SET    status = 'order_ready'
        WHERE  id = ? AND status = 'ready_for_payment'
    ")->execute([$group_id]);

    if ($pdo->rowCount() > 0) {
        // Notify business owner
        $bstmt = $pdo->prepare("
            SELECT b.user_id, p.name AS product_name
            FROM   group_purchases gp
            JOIN   products p    ON p.id  = gp.product_id
            JOIN   businesses b  ON b.id  = p.business_id
            WHERE  gp.id = ?
        ");
        $bstmt->execute([$group_id]);
        $biz = $bstmt->fetch();

        if ($biz) {
            notify(
                (int)$biz['user_id'],
                'order_ready',
                'All payments received!',
                'Group #' . $group_id . ' (' . $biz['product_name'] . ') — all members have paid. Ready to fulfil.',
                defined('APP_URL') ? APP_URL . '/pages/business/dashboard.php' : ''
            );
        }

        // Notify customers too
        notify_group_members(
            $group_id,
            'order_ready',
            'Your order is confirmed!',
            'All members have paid. Your order is being prepared.',
            defined('APP_URL') ? APP_URL . '/pages/my-orders.php' : ''
        );

        PaymentService::log('group_order_ready', ['group_id' => $group_id]);
    }
}
