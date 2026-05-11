<?php
// SmartCart — Database Connection
// All PHP files that need DB access: require_once __DIR__ . '/../config/db.php';

require_once __DIR__ . '/config.php';

// Harden session cookies before any session_start() in the request lifecycle.
if (session_status() === PHP_SESSION_NONE) {
    $secure = defined('APP_ENV') && APP_ENV === 'production';
    @session_set_cookie_params([
        'lifetime' => defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 0,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function getPDO(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $port = defined('DB_PORT') ? DB_PORT : '3306';
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . $port . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        http_response_code(500);
        die(json_encode(['error' => 'Database connection failed']));
    }
    return $pdo;
}
