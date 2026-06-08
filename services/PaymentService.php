<?php
/**
 * SmartCart — Modular Payment Provider Service
 *
 * Abstracts over hosted-payment-page providers.
 * Switch PAYMENT_PROVIDER in .env (or config/config.php):
 *
 *   mock      — local simulation (no real money, dev / CI)
 *   payme     — PayMe Israel  https://paymeservice.com
 *   meshulam  — Grow/Meshulam https://meshulam.co.il
 *
 * Usage:
 *   $result = PaymentService::createPaymentLink($payment);
 *   // $result = ['ok' => true,  'payment_url' => '...', 'transaction_id' => '...']
 *   // $result = ['ok' => false, 'error' => '...']
 *
 * PHP 7.4 compatible.
 */

class PaymentService
{
    // ──────────────────────────────────────────────────────────────────────────
    // PUBLIC API
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Create a hosted payment link for a payment record.
     *
     * Required keys in $payment:
     *   id          (int)    — payments.id
     *   user_id     (int)
     *   group_id    (int)
     *   product_id  (int)
     *   amount      (float)  — amount in ILS
     *   currency    (string) — e.g. 'ILS'
     *
     * Optional keys:
     *   description (string)
     *   user_name   (string)
     *   user_email  (string)
     *   user_phone  (string)
     *
     * @return array ['ok'=>bool, 'payment_url'=>string, 'transaction_id'=>string]
     *            or ['ok'=>false, 'error'=>string]
     */
    public static function createPaymentLink(array $payment): array
    {
        $provider = defined('PAYMENT_PROVIDER') ? strtolower(trim(PAYMENT_PROVIDER)) : 'mock';

        switch ($provider) {
            case 'paypal':
                return self::createPaypalLink($payment);
            case 'payme':
                return self::createPaymeLink($payment);
            case 'meshulam':
                return self::createMeshulamLink($payment);
            case 'mock':
            default:
                return self::createMockLink($payment);
        }
    }

    /**
     * Verify a webhook request from the payment provider.
     * Call before processing any webhook payload.
     *
     * @param string $raw_body   Raw request body (file_get_contents('php://input'))
     * @param array  $headers    All request headers (from getallheaders() or manual parse)
     * @return bool
     */
    public static function verifyWebhook(string $raw_body, array $headers): bool
    {
        $provider = defined('PAYMENT_PROVIDER') ? strtolower(trim(PAYMENT_PROVIDER)) : 'mock';

        switch ($provider) {
            case 'payme':
                return self::verifyPaymeWebhook($raw_body, $headers);
            case 'meshulam':
                return self::verifyMeshulamWebhook($raw_body, $headers);
            case 'mock':
            default:
                return self::verifyMockWebhook($headers);
        }
    }

    /**
     * Returns true if the current PAYMENT_PROVIDER is a hosted-page provider
     * (as opposed to 'paypal' or empty, which means the legacy PayPal flow).
     */
    public static function isHostedMode(): bool
    {
        $p = defined('PAYMENT_PROVIDER') ? strtolower(trim(PAYMENT_PROVIDER)) : '';
        return $p !== '';
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PAYPAL — hosted-pay-after-fill flow
    //
    // Creates a CAPTURE order and returns the approve URL.
    // After user approves, PayPal redirects to api/payments/paypal_return.php
    // which captures the payment and updates the DB.
    // ──────────────────────────────────────────────────────────────────────────

    private static function createPaypalLink(array $payment): array
    {
        require_once __DIR__ . '/../includes/paypal.php';

        $base        = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
        $return_url  = $base . '/api/payments/paypal_return.php?payment_id=' . $payment['id'];
        $cancel_url  = $base . '/pages/group.php?id=' . $payment['group_id'] . '&pay_cancelled=1';

        $token = paypal_token();
        if (!$token) return ['ok' => false, 'error' => 'PayPal auth failed'];

        $amount_usd = round((float)$payment['amount'] / (defined('ILS_TO_USD_RATE') ? ILS_TO_USD_RATE : 3.7), 2);

        $payload = json_encode([
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'amount' => [
                    'currency_code' => 'USD',
                    'value'         => number_format($amount_usd, 2, '.', ''),
                ],
                'description' => mb_substr($payment['description'] ?? 'SmartCart Purchase', 0, 127),
                'custom_id'   => 'payment_' . $payment['id'],
            ]],
            'application_context' => [
                'brand_name'          => 'SmartCart',
                'user_action'         => 'PAY_NOW',
                'return_url'          => $return_url,
                'cancel_url'          => $cancel_url,
                'shipping_preference' => 'NO_SHIPPING',
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
            self::log('paypal_create_order_failed', ['code' => $code, 'resp' => substr($resp, 0, 500)]);
            return ['ok' => false, 'error' => 'PayPal create order failed (HTTP ' . $code . ')'];
        }

        $approve_url = '';
        foreach ($data['links'] ?? [] as $link) {
            if (($link['rel'] ?? '') === 'approve') { $approve_url = $link['href']; break; }
        }

        return [
            'ok'             => true,
            'payment_url'    => $approve_url,
            'transaction_id' => $data['id'],
        ];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // MOCK PROVIDER — development / demo
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Generates a local "fake" payment page URL that the dev can visit to
     * simulate a successful (or failed) payment without a real provider.
     * The mock page lives at api/payments/mock_confirm.php.
     */
    private static function createMockLink(array $payment): array
    {
        $secret = defined('PAYMENT_WEBHOOK_SECRET') ? PAYMENT_WEBHOOK_SECRET : 'dev_secret_change_me';
        // HMAC ties the URL to this specific payment_id so it can't be tampered with
        $sig = hash_hmac('sha256', (string)$payment['id'], $secret);

        $base = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
        $url  = $base . '/api/payments/mock_confirm.php'
              . '?payment_id=' . urlencode((string)$payment['id'])
              . '&sig='        . urlencode($sig);

        return [
            'ok'             => true,
            'payment_url'    => $url,
            'transaction_id' => 'MOCK-' . strtoupper(bin2hex(random_bytes(8))),
        ];
    }

    private static function verifyMockWebhook(array $headers): bool
    {
        // Mock: accept requests that carry the webhook secret in X-Webhook-Token
        $secret = defined('PAYMENT_WEBHOOK_SECRET') ? PAYMENT_WEBHOOK_SECRET : '';
        if (!$secret) return true; // dev: allow all if secret not configured

        // Header names may arrive lower-cased (depends on server)
        $token = $headers['X-Webhook-Token']
              ?? $headers['x-webhook-token']
              ?? $headers['HTTP_X_WEBHOOK_TOKEN']
              ?? '';

        return hash_equals($secret, $token);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PAYME — https://paymeservice.com (Israel)
    // Docs: https://payme-doc.github.io/
    //
    // Config keys used:
    //   PAYMENT_API_KEY   — Seller API key from PayMe dashboard
    //   PAYMENT_SECRET    — Seller PayMe ID
    //   PAYMENT_SUCCESS_URL
    //   PAYMENT_CANCEL_URL
    // ──────────────────────────────────────────────────────────────────────────

    private static function createPaymeLink(array $payment): array
    {
        $api_key     = defined('PAYMENT_API_KEY')     ? PAYMENT_API_KEY     : '';
        $seller_id   = defined('PAYMENT_SECRET')      ? PAYMENT_SECRET      : '';
        $success_url = defined('PAYMENT_SUCCESS_URL') ? PAYMENT_SUCCESS_URL : '';
        $cancel_url  = defined('PAYMENT_CANCEL_URL')  ? PAYMENT_CANCEL_URL  : '';

        if (!$api_key || !$seller_id) {
            return ['ok' => false, 'error' => 'PayMe credentials not configured (PAYMENT_API_KEY / PAYMENT_SECRET)'];
        }

        // PayMe uses agorot (1 ILS = 100 agorot)
        $amount_agorot = (int)round((float)$payment['amount'] * 100);

        $payload = [
            'seller_payme_id'   => $seller_id,
            'sale_price'        => $amount_agorot,
            'currency'          => $payment['currency'] ?? 'ILS',
            'product_name'      => mb_substr($payment['description'] ?? 'SmartCart Purchase', 0, 100),
            'sale_callback_url' => $success_url . '?payment_id=' . $payment['id'],
            'sale_return_url'   => $success_url . '?payment_id=' . $payment['id'],
            'sale_cancel_url'   => $cancel_url  . '?payment_id=' . $payment['id'],
            'sale_email'        => $payment['user_email'] ?? '',
            'sale_name'         => mb_substr($payment['user_name'] ?? '', 0, 50),
            'sale_send_email'   => false,
            'language'          => 'he',
        ];

        $ch = curl_init('https://ng.payme.co.il/api/generate-sale');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: ApiKey ' . $api_key,
            ],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) {
            self::log('payme_create_failed', ['code' => $code, 'resp' => substr($resp, 0, 500)]);
            return ['ok' => false, 'error' => 'PayMe API error (HTTP ' . $code . ')'];
        }

        $data = json_decode($resp, true);
        if (empty($data['sale_url'])) {
            self::log('payme_no_url', ['resp' => substr($resp, 0, 500)]);
            return ['ok' => false, 'error' => 'PayMe did not return a payment URL'];
        }

        return [
            'ok'             => true,
            'payment_url'    => $data['sale_url'],
            'transaction_id' => $data['sale_payme_id'] ?? ('PAYME-' . bin2hex(random_bytes(8))),
        ];
    }

    private static function verifyPaymeWebhook(string $raw_body, array $headers): bool
    {
        // PayMe sends the seller's API key in the Authorization header
        $api_key = defined('PAYMENT_API_KEY') ? PAYMENT_API_KEY : '';
        if (!$api_key) return false;

        $auth = $headers['Authorization']  ?? $headers['authorization']  ?? '';
        return hash_equals('ApiKey ' . $api_key, $auth);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // MESHULAM / GROW — https://meshulam.co.il (Israel)
    // Docs: https://grow.co.il/api
    //
    // Config keys used:
    //   PAYMENT_API_KEY   — apiKey from Meshulam dashboard
    //   PAYMENT_SECRET    — Page code (pageCode) from Meshulam dashboard
    //   PAYMENT_SUCCESS_URL
    //   PAYMENT_CANCEL_URL
    // ──────────────────────────────────────────────────────────────────────────

    private static function createMeshulamLink(array $payment): array
    {
        $api_key     = defined('PAYMENT_API_KEY')     ? PAYMENT_API_KEY     : '';
        $page_code   = defined('PAYMENT_SECRET')      ? PAYMENT_SECRET      : '';
        $success_url = defined('PAYMENT_SUCCESS_URL') ? PAYMENT_SUCCESS_URL : '';
        $cancel_url  = defined('PAYMENT_CANCEL_URL')  ? PAYMENT_CANCEL_URL  : '';

        if (!$api_key || !$page_code) {
            return ['ok' => false, 'error' => 'Meshulam credentials not configured (PAYMENT_API_KEY / PAYMENT_SECRET)'];
        }

        $payload = [
            'pageCode'    => $page_code,
            'fullName'    => mb_substr($payment['user_name']  ?? 'Customer', 0, 100),
            'phone'       => $payment['user_phone'] ?? '',
            'email'       => $payment['user_email'] ?? '',
            'sum'         => number_format((float)$payment['amount'], 2, '.', ''),
            'description' => mb_substr($payment['description'] ?? 'SmartCart Purchase', 0, 100),
            'successUrl'  => $success_url . '?payment_id=' . $payment['id'],
            'cancelUrl'   => $cancel_url  . '?payment_id=' . $payment['id'],
        ];

        $ch = curl_init('https://secure.meshulam.co.il/api/v2/createTransaction');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'apiKey: ' . $api_key,
            ],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) {
            self::log('meshulam_create_failed', ['code' => $code, 'resp' => substr($resp, 0, 500)]);
            return ['ok' => false, 'error' => 'Meshulam API error (HTTP ' . $code . ')'];
        }

        $data = json_decode($resp, true);
        $url  = $data['data']['url'] ?? ($data['url'] ?? '');
        if (!$url) {
            self::log('meshulam_no_url', ['resp' => substr($resp, 0, 500)]);
            return ['ok' => false, 'error' => 'Meshulam did not return a payment URL'];
        }

        return [
            'ok'             => true,
            'payment_url'    => $url,
            'transaction_id' => $data['data']['transaction_id']
                             ?? ($data['transaction_id'] ?? ('MESH-' . bin2hex(random_bytes(8)))),
        ];
    }

    private static function verifyMeshulamWebhook(string $raw_body, array $headers): bool
    {
        // Meshulam sends HMAC-SHA256 of the raw body in X-Meshulam-Signature
        $secret = defined('PAYMENT_WEBHOOK_SECRET') ? PAYMENT_WEBHOOK_SECRET : '';
        if (!$secret) return false;

        $sig      = $headers['X-Meshulam-Signature']
                 ?? $headers['x-meshulam-signature']
                 ?? $headers['HTTP_X_MESHULAM_SIGNATURE']
                 ?? '';
        $expected = hash_hmac('sha256', $raw_body, $secret);

        return hash_equals($expected, $sig);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Shared logging
    // ──────────────────────────────────────────────────────────────────────────

    public static function log(string $event, array $data): void
    {
        $log_file = __DIR__ . '/../logs/payments.log';
        $dir      = dirname($log_file);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        @file_put_contents(
            $log_file,
            '[' . date('Y-m-d H:i:s') . '] ' . $event . ' '
                . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
            FILE_APPEND
        );
    }
}
