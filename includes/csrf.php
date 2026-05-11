<?php
// SmartCart — CSRF helper (V3)
// Session-based double-submit token.

if (!function_exists('csrf_token')) {
    function csrf_token(): string {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    function csrf_field(): string {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
    }

    function csrf_meta(): string {
        return '<meta name="csrf-token" content="' . htmlspecialchars(csrf_token()) . '">';
    }

    /**
     * Validates the CSRF token from header or body.
     * Returns true if valid, false otherwise.
     * Skips validation for GET/HEAD/OPTIONS.
     */
    function csrf_validate(?string $supplied = null): bool {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) return true;
        if (session_status() === PHP_SESSION_NONE) session_start();
        $expected = $_SESSION['csrf_token'] ?? '';
        if (!$expected) return false;
        if ($supplied === null) {
            $supplied = $_SERVER['HTTP_X_CSRF_TOKEN']
                     ?? ($_POST['csrf_token'] ?? '')
                     ?? '';
            // Fallback: parse JSON body if present
            if (!$supplied) {
                $raw = file_get_contents('php://input');
                if ($raw) {
                    $data = json_decode($raw, true);
                    $supplied = $data['csrf_token'] ?? '';
                }
            }
        }
        return is_string($supplied) && hash_equals($expected, $supplied);
    }

    /**
     * Hard-enforce CSRF: terminate with 403 if invalid.
     * Use at the top of state-changing API endpoints.
     */
    function csrf_require(): void {
        if (!csrf_validate()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Invalid or missing CSRF token']);
            exit;
        }
    }
}
