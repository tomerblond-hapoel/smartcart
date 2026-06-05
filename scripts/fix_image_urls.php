<?php
/**
 * One-time migration: fix double-URL image_url values in the products table.
 * PHP 7.4 compatible — no str_starts_with().
 *
 * Problem: APP_URL was prepended to an already-absolute URL, producing:
 *   https://noati2.mtacloud.co.il/smartcarthttps://noati2.mtacloud.co.il/smartcart/uploads/...
 *
 * Fix: strip the first duplicate APP_URL prefix.
 * Run once, then you can delete this file.
 *
 * Usage: visit https://yoursite/smartcart/scripts/fix_image_urls.php
 *         OR:  php scripts/fix_image_urls.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

$pdo  = getPDO();
$base = rtrim(APP_URL, '/');   // e.g. https://noati2.mtacloud.co.il/smartcart
$skip = strlen($base);

// Only update rows whose image_url starts with APP_URL immediately followed by
// another absolute URL — the signature of the double-prepend bug.
$stmt = $pdo->prepare("
    UPDATE products
    SET    image_url = SUBSTRING(image_url, :skip + 1)
    WHERE  image_url LIKE :p_https
       OR  image_url LIKE :p_http
");
$stmt->execute([
    ':skip'    => $skip,
    ':p_https' => $base . 'https://%',
    ':p_http'  => $base . 'http://%',
]);
$fixed = $stmt->rowCount();
echo "products: fixed {$fixed} broken image_url(s).\n";

// Verify no broken rows remain
$check = $pdo->prepare("
    SELECT id, image_url FROM products
    WHERE  image_url LIKE :p1 OR image_url LIKE :p2
");
$check->execute([':p1' => $base . 'https://%', ':p2' => $base . 'http://%']);
$remaining = $check->fetchAll();
if ($remaining) {
    echo 'WARNING: ' . count($remaining) . " row(s) still have broken URLs:\n";
    foreach ($remaining as $r) {
        echo "  ID {$r['id']}: {$r['image_url']}\n";
    }
} else {
    echo "All image URLs look clean.\n";
}
