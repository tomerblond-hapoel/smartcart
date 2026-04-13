<?php
// SmartCart — Configuration TEMPLATE
// Copy this file to config.php and fill in real values.
// NEVER commit config.php to GitHub.

define('DB_HOST', 'localhost');
define('DB_NAME', 'smartcart_db');
define('DB_USER', 'your_db_username');
define('DB_PASS', 'your_db_password');

define('APP_ENV',  'development');
define('APP_URL',  'http://localhost/smartcart');
define('APP_NAME', 'SmartCart');

define('PAYPAL_CLIENT_ID',    'your_paypal_sandbox_client_id');
define('PAYPAL_SECRET',       'your_paypal_sandbox_secret');
define('PAYPAL_BASE_URL',     'https://api-m.sandbox.paypal.com');
define('PAYPAL_CURRENCY',     'ILS');

define('GMAPS_API_KEY', 'your_google_maps_api_key');

define('ILS_TO_USD_RATE', 3.7);
define('SESSION_LIFETIME', 60 * 60 * 24 * 7);
