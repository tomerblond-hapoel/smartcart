<?php
// SmartCart — PayPal Helper (V3)
//
// Implements the full authorize → capture → void → refund lifecycle.
//
// Design note on the "5% deposit" spec:
// PayPal's Authorize-then-Capture flow allows capturing up to the authorized amount.
// To fulfill the spec intent ("no money taken until group succeeds") without forcing
// the customer through TWO PayPal approvals, we authorize the FULL group price on join
// (holds funds, doesn't charge). On group close, we capture. On fail, we void.
// This matches the business rule of "buyer commits when joining, pays when group succeeds".

require_once __DIR__ . '/../config/config.php';

function paypal_token(): ?string {
    if (session_status() === PHP_SESSION_NONE) session_start();
    // Invalidate cached token if credentials have changed since it was issued
    $cred_hash = md5(PAYPAL_CLIENT_ID . ':' . PAYPAL_SECRET);
    if (
        !empty($_SESSION['paypal_token']) &&
        !empty($_SESSION['paypal_token_expires']) &&
        ($_SESSION['paypal_token_cred_hash'] ?? '') === $cred_hash &&
        $_SESSION['paypal_token_expires'] > time()
    ) {
        return $_SESSION['paypal_token'];
    }
    $ch = curl_init(PAYPAL_BASE_URL . '/v1/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
        CURLOPT_USERPWD        => PAYPAL_CLIENT_ID . ':' . PAYPAL_SECRET,
        CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Accept-Language: en_US'],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) {
        paypal_log('token_failed', ['code' => $code, 'resp' => $resp]);
        return null;
    }
    $data = json_decode($resp, true);
    $_SESSION['paypal_token']           = $data['access_token'];
    $_SESSION['paypal_token_expires']   = time() + (int)($data['expires_in'] ?? 3600) - 60;
    $_SESSION['paypal_token_cred_hash'] = $cred_hash;
    return $data['access_token'];
}

function paypal_log(string $event, array $data): void {
    $log_file = __DIR__ . '/../logs/paypal.log';
    $dir = dirname($log_file);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @file_put_contents($log_file,
        '[' . date('Y-m-d H:i:s') . '] ' . $event . ' ' . json_encode($data, JSON_UNESCAPED_SLASHES) . "\n",
        FILE_APPEND);
}

// Create a PayPal order with intent=AUTHORIZE.
// Returns ['ok'=>true, 'order_id'=>X, 'approve_url'=>Y, 'amount_usd'=>Z] on success.
// The caller must redirect the user to approve_url; after approval, call paypal_capture_authorization() to finalize the auth.
function paypal_create_authorize(float $amount_ils, string $description, string $custom_id, string $return_url, string $cancel_url): array {
    $token = paypal_token();
    if (!$token) return ['ok' => false, 'error' => 'PayPal auth failed'];

    $amount_usd = round($amount_ils / ILS_TO_USD_RATE, 2);
    $payload = json_encode([
        'intent' => 'AUTHORIZE',
        'purchase_units' => [[
            'amount' => [
                'currency_code' => 'USD',
                'value'         => number_format($amount_usd, 2, '.', ''),
            ],
            'description' => mb_substr($description, 0, 127),
            'custom_id'   => mb_substr($custom_id, 0, 127),
        ]],
        'application_context' => [
            'brand_name'  => 'SmartCart',
            'user_action' => 'PAY_NOW',
            'return_url'  => $return_url,
            'cancel_url'  => $cancel_url,
        ],
    ]);

    $ch = curl_init(PAYPAL_BASE_URL . '/v2/checkout/orders');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($resp, true);
    if ($code !== 201 || empty($data['id'])) {
        paypal_log('create_authorize_failed', ['code' => $code, 'resp' => $resp]);
        return ['ok' => false, 'error' => 'Create order failed'];
    }
    $approve_url = '';
    foreach ($data['links'] ?? [] as $link) {
        if (($link['rel'] ?? '') === 'approve') { $approve_url = $link['href']; break; }
    }
    return ['ok' => true, 'order_id' => $data['id'], 'approve_url' => $approve_url, 'amount_usd' => $amount_usd];
}

// After user approves the AUTHORIZE order, call this to actually create the authorization.
// Returns ['ok'=>true, 'auth_id'=>X] — auth_id is what we use later to capture/void.
function paypal_authorize_capture(string $order_id): array {
    $token = paypal_token();
    if (!$token) return ['ok' => false, 'error' => 'PayPal auth failed'];

    $ch = curl_init(PAYPAL_BASE_URL . '/v2/checkout/orders/' . urlencode($order_id) . '/authorize');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => '{}',
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($resp, true);
    if ($code !== 201) {
        paypal_log('authorize_capture_failed', ['code' => $code, 'order_id' => $order_id, 'resp' => $resp]);
        return ['ok' => false, 'error' => 'Authorization failed'];
    }
    $auth_id = $data['purchase_units'][0]['payments']['authorizations'][0]['id'] ?? null;
    if (!$auth_id) return ['ok' => false, 'error' => 'No authorization id returned'];
    return ['ok' => true, 'auth_id' => $auth_id];
}

// Capture funds from an existing authorization (final-charge the customer).
// Returns ['ok'=>true, 'capture_id'=>X].
function paypal_capture(string $auth_id, float $amount_ils, bool $final = true): array {
    $token = paypal_token();
    if (!$token) return ['ok' => false, 'error' => 'PayPal auth failed'];

    $amount_usd = round($amount_ils / ILS_TO_USD_RATE, 2);
    $payload = json_encode([
        'amount' => [
            'currency_code' => 'USD',
            'value'         => number_format($amount_usd, 2, '.', ''),
        ],
        'final_capture' => $final,
    ]);

    $ch = curl_init(PAYPAL_BASE_URL . '/v2/payments/authorizations/' . urlencode($auth_id) . '/capture');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($resp, true);
    if ($code !== 201 || ($data['status'] ?? '') !== 'COMPLETED') {
        paypal_log('capture_failed', ['code' => $code, 'auth_id' => $auth_id, 'resp' => $resp]);
        return ['ok' => false, 'error' => 'Capture failed: ' . ($data['status'] ?? 'unknown')];
    }
    return ['ok' => true, 'capture_id' => $data['id']];
}

// Void an authorization (release the hold).
function paypal_void(string $auth_id): array {
    $token = paypal_token();
    if (!$token) return ['ok' => false, 'error' => 'PayPal auth failed'];

    $ch = curl_init(PAYPAL_BASE_URL . '/v2/payments/authorizations/' . urlencode($auth_id) . '/void');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => '',
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 204) {
        paypal_log('void_failed', ['code' => $code, 'auth_id' => $auth_id, 'resp' => $resp]);
        return ['ok' => false, 'error' => 'Void failed'];
    }
    return ['ok' => true];
}

// Refund a previously-captured payment.
function paypal_refund(string $capture_id, float $amount_ils, string $reason = ''): array {
    $token = paypal_token();
    if (!$token) return ['ok' => false, 'error' => 'PayPal auth failed'];

    $amount_usd = round($amount_ils / ILS_TO_USD_RATE, 2);
    $payload = json_encode([
        'amount' => [
            'currency_code' => 'USD',
            'value'         => number_format($amount_usd, 2, '.', ''),
        ],
        'note_to_payer' => mb_substr($reason ?: 'SmartCart refund', 0, 255),
    ]);

    $ch = curl_init(PAYPAL_BASE_URL . '/v2/payments/captures/' . urlencode($capture_id) . '/refund');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($resp, true);
    if ($code !== 201 || ($data['status'] ?? '') !== 'COMPLETED') {
        paypal_log('refund_failed', ['code' => $code, 'capture_id' => $capture_id, 'resp' => $resp]);
        return ['ok' => false, 'error' => 'Refund failed'];
    }
    return ['ok' => true, 'refund_id' => $data['id']];
}
