<?php
// SmartCart — Rate limiting helper (V3)
// Uses the rate_limits table; safe to import multiple times.

if (!function_exists('rate_check')) {
    /**
     * Returns true if the operation is allowed (count <= max within window).
     * Increments the counter atomically. Cleans expired keys.
     */
    function rate_check(string $key, int $max, int $window_sec): bool {
        try {
            $pdo = getPDO();
            // Cleanup expired (cheap; runs at most a few rows per call)
            $pdo->prepare("DELETE FROM rate_limits WHERE expires_at < NOW()")->execute();
            $exp = date('Y-m-d H:i:s', time() + $window_sec);
            $pdo->prepare("INSERT INTO rate_limits (`key`, `count`, expires_at) VALUES (?, 1, ?)
                           ON DUPLICATE KEY UPDATE `count` = `count` + 1")
                ->execute([$key, $exp]);
            $stmt = $pdo->prepare("SELECT `count` FROM rate_limits WHERE `key` = ?");
            $stmt->execute([$key]);
            $count = (int)$stmt->fetchColumn();
            return $count <= $max;
        } catch (\Throwable $e) {
            // On DB failure, fail-open to avoid blocking legitimate traffic
            return true;
        }
    }

    function rate_key_for_ip(string $name): string {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        return $name . ':' . $ip;
    }
}
