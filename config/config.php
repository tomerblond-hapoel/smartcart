<?php

define('DB_HOST', 'localhost');
define('DB_NAME', 'noati2_SmartCart');
define('DB_USER', 'noati2_noa');
define('DB_PASS', 'noatieder3112');

define('APP_ENV', 'production');
define('APP_URL', 'https://noati2.mtacloud.co.il/smartcart');
define('APP_NAME', 'SmartCart');

define('PAYPAL_BASE_URL', 'https://api-m.sandbox.paypal.com');
define('PAYPAL_CURRENCY', 'ILS');

define('GMAPS_API_KEY', 'your_google_maps_api_key');

define('ILS_TO_USD_RATE', 3.7);
define('SESSION_LIFETIME', 60 * 60 * 24 * 7);

// ── Load .env (if present) ────────────────────────────────────────────────────
// Values in .env override the defaults below.  The file must sit one directory
// above config/ (i.e. the project root) and is blocked from web access by
// the root .htaccess rule:  FilesMatch "\.(env|log|sql)$" → Deny.
(function () {
    $env_file = __DIR__ . '/../.env';
    if (!file_exists($env_file)) return;
    $lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;   // skip blank + comments
        if (strpos($line, '=') === false) continue;
        [$key, $val] = explode('=', $line, 2);
        $key = trim($key);
        $val = trim($val);
        if ($key === '') continue;
        // Only set if not already in environment (server env has highest priority)
        if (!isset($_ENV[$key]) && getenv($key) === false) {
            $_ENV[$key] = $val;
            putenv("{$key}={$val}");
        }
    }
})();

// ── PayPal credentials (loaded after .env so .env values take effect) ─────────
define('PAYPAL_CLIENT_ID', $_ENV['PAYPAL_CLIENT_ID'] ?? getenv('PAYPAL_CLIENT_ID') ?: '');
define('PAYPAL_SECRET',    $_ENV['PAYPAL_SECRET']    ?? getenv('PAYPAL_SECRET')    ?: '');

// ── Payment provider constants ────────────────────────────────────────────────
// Reads from .env first, falls back to the defaults listed here.
// Set PAYMENT_PROVIDER=payme or meshulam in .env for live payments.
define('PAYMENT_PROVIDER',       $_ENV['PAYMENT_PROVIDER']       ?? getenv('PAYMENT_PROVIDER')       ?: 'paypal');
define('PAYMENT_API_KEY',        $_ENV['PAYMENT_API_KEY']        ?? getenv('PAYMENT_API_KEY')        ?: '');
define('PAYMENT_SECRET',         $_ENV['PAYMENT_SECRET']         ?? getenv('PAYMENT_SECRET')         ?: '');
define('PAYMENT_WEBHOOK_SECRET', $_ENV['PAYMENT_WEBHOOK_SECRET'] ?? getenv('PAYMENT_WEBHOOK_SECRET') ?: 'dev_secret_change_me');
define('PAYMENT_SUCCESS_URL',    $_ENV['PAYMENT_SUCCESS_URL']    ?? getenv('PAYMENT_SUCCESS_URL')    ?: APP_URL . '/pages/my-orders.php');
define('PAYMENT_CANCEL_URL',     $_ENV['PAYMENT_CANCEL_URL']     ?? getenv('PAYMENT_CANCEL_URL')     ?: APP_URL . '/pages/my-groups.php');

// ── Groq LLM API key (for Smart Agent Hebrew/NL query understanding) ──────────
define('GROQ_API_KEY', $_ENV['GROQ_API_KEY'] ?? getenv('GROQ_API_KEY') ?: '');
