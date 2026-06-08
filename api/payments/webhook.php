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
require_once __DIR__ . '/../../includes/payment_processor.php';

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
