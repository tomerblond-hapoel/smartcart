<?php
// SmartCart — Notifications helper (V3)
// In-app inbox via `notifications` table; PHP mail() as best-effort email dispatch.

require_once __DIR__ . '/../config/config.php';

if (!function_exists('notify')) {
    function notify(int $user_id, string $type, string $title, string $body = '', ?string $link_url = null, ?array $payload = null, bool $send_email = true): void {
        if ($user_id <= 0) return;
        try {
            $pdo = getPDO();
            $pdo->prepare("INSERT INTO notifications (user_id, type, title, body, link_url, payload_json, sent_email) VALUES (?, ?, ?, ?, ?, ?, ?)")
                ->execute([
                    $user_id, $type, mb_substr($title, 0, 200), $body,
                    $link_url ? mb_substr($link_url, 0, 500) : null,
                    $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
                    $send_email ? 1 : 0,
                ]);

            // Best-effort email dispatch in production
            if ($send_email && defined('APP_ENV') && APP_ENV === 'production') {
                $stmt = $pdo->prepare("SELECT email, full_name FROM users WHERE id=?");
                $stmt->execute([$user_id]);
                $u = $stmt->fetch();
                if ($u && filter_var($u['email'], FILTER_VALIDATE_EMAIL)) {
                    $from = defined('MAIL_FROM') ? MAIL_FROM : ('noreply@' . parse_url(APP_URL, PHP_URL_HOST));
                    $headers  = "From: SmartCart <$from>\r\n";
                    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
                    @mail($u['email'], '[SmartCart] ' . $title, $body, $headers);
                }
            }
        } catch (\Throwable $e) {
            // Swallow notification errors — never break the main flow
            @file_put_contents(__DIR__ . '/../logs/notify.log',
                '[' . date('Y-m-d H:i:s') . "] notify failed: " . $e->getMessage() . "\n", FILE_APPEND);
        }
    }
}

if (!function_exists('notify_group_members')) {
    function notify_group_members(int $group_id, string $type, string $title, string $body = '', ?string $link_url = null, ?array $payload = null): void {
        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare("SELECT user_id FROM group_members WHERE group_id = ?");
            $stmt->execute([$group_id]);
            foreach ($stmt->fetchAll() as $row) {
                notify((int)$row['user_id'], $type, $title, $body, $link_url, $payload);
            }
        } catch (\Throwable $e) {
            // Swallow
        }
    }
}
