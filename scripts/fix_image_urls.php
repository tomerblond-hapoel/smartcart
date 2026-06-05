<?php
/**
 * One-time migration: fix double-URL image_url values in the products table.
 *
 * Problem pattern stored in DB:
 *   https://noati2.mtacloud.co.il/smartcarthttps://noati2.mtacloud.co.il/smartcart/uploads/products/file.jpg
 *                                  ^^^^^^^^^^ APP_URL was prepended to an already-absolute URL
 *
 * Fix: strip the first (duplicate) APP_URL prefix, leaving a single correct URL:
 *   https://noati2.mtacloud.co.il/smartcart/uploads/products/file.jpg
 *
 * Run once from CLI or browser, then delete this file.
 * Usage: php scripts/fix_image_urls.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

$pdo  = getPDO();
$base = rtrim(APP_URL, '/');   // e.g. https://noati2.mtacloud.co.il/smartcart

// How many characters to skip (the first duplicate prefix)
$strip_len = strlen($base);

// ── Fix products table ────────────────────────────────────────
// Match rows where image_url starts with APP_URL immediately followed by https:// or http://
// e.g. "https://…/smartcarthttps://…"
$stmt = $pdo->prepare("
    UPDATE products
    SET    image_url = SUBSTRING(image_url, :skip + 1)
    WHERE  image_url LIKE :pattern_https
       OR  image_url LIKE :pattern_http
");
$stmt->execute([
    ':skip'          => $strip_len,
    ':pattern_https' => $base . 'https://%',
    ':pattern_http'  => $base . 'http://%',
]);
$fixed = $stmt->rowCount();
echo "products: fixed {$fixed} broken image_url(s).\n";

// ── Verify ───────────────────────────────────────────────────
$check = $pdo->prepare("
    SELECT id, image_url FROM products
    WHERE  image_url LIKE :p1 OR image_url LIKE :p2
");
$check->execute([':p1' => $base . 'https://%', ':p2' => $base . 'http://%']);
$remaining = $check->fetchAll();
if ($remaining) {
    echo "WARNING: " . count($remaining) . " row(s) still have broken URLs:\n";
    foreach ($remaining as $r) {
        echo "  ID {$r['id']}: {$r['image_url']}\n";
    }
} else {
    echo "All image URLs look clean.\n";
}
