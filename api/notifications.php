<?php
// SmartCart — Notifications API
// GET  ?action=list&unread_only=1   — list notifications for current user
// GET  ?action=unread_count         — count of unread notifications
// POST ?action=mark_read&id=N       — mark one as read
// POST ?action=mark_all_read         — mark all unread as read

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

session_start();
header('Content-Type: application/json; charset=utf-8');

$user_id = require_auth();
$pdo     = getPDO();
$action  = $_GET['action'] ?? '';

switch ($action) {
    case 'list':
        $unread_only = !empty($_GET['unread_only']);
        $where = $unread_only ? 'AND read_at IS NULL' : '';
        $stmt = $pdo->prepare("SELECT id, type, title, body, link_url, payload_json, read_at, created_at
                               FROM notifications
                               WHERE user_id = ? $where
                               ORDER BY created_at DESC LIMIT 100");
        $stmt->execute([$user_id]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['payload'] = $r['payload_json'] ? json_decode($r['payload_json'], true) : null;
            unset($r['payload_json']);
            $r['is_read'] = !empty($r['read_at']);
        }
        json_response(['notifications' => $rows]);
        break;

    case 'unread_count':
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL");
        $stmt->execute([$user_id]);
        json_response(['count' => (int)$stmt->fetchColumn()]);
        break;

    case 'mark_read':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) json_response(['error' => 'id required'], 400);
        $pdo->prepare("UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ? AND read_at IS NULL")
            ->execute([$id, $user_id]);
        json_response(['success' => true]);
        break;

    case 'mark_all_read':
        $pdo->prepare("UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL")
            ->execute([$user_id]);
        json_response(['success' => true]);
        break;

    default:
        json_response(['error' => 'Unknown action'], 400);
}
