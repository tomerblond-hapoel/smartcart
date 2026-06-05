<?php
/**
 * One-time maintenance script — run after deploying, then keep or delete.
 * PHP 7.4 compatible.
 *
 * Does two things:
 *
 * 1. FILE PERMISSIONS — chmod all uploaded images to 0644 so Apache can serve them.
 *    On cPanel, PHP's move_uploaded_file() often creates files as 0600 (owner-only),
 *    which makes Apache return 403 Forbidden when a browser requests the image.
 *
 * 2. DB CLEANUP — fixes any double-URL values saved in products.image_url:
 *    https://noati2.mtacloud.co.il/smartcarthttps://noati2.mtacloud.co.il/smartcart/...
 *    → https://noati2.mtacloud.co.il/smartcart/...
 *
 * Usage: visit https://noati2.mtacloud.co.il/smartcart/scripts/fix_image_urls.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

// ── 1. Fix file permissions ───────────────────────────────
$uploads_root = __DIR__ . '/../uploads';
$subdirs      = ['products', 'logos'];
$chmod_fixed  = 0;
$chmod_failed = 0;

foreach ($subdirs as $sub) {
    $dir = $uploads_root . '/' . $sub;
    if (!is_dir($dir)) continue;
    foreach (glob($dir . '/*') as $file) {
        if (!is_file($file)) continue;
        if (substr($file, -8) === '.gitkeep') continue;  // skip placeholder
        if (@chmod($file, 0644)) {
            $chmod_fixed++;
        } else {
            $chmod_failed++;
            echo "WARNING: could not chmod {$file}\n";
        }
    }
}
echo "File permissions: set 0644 on {$chmod_fixed} file(s)";
if ($chmod_failed) echo " ({$chmod_failed} failed)";
echo ".\n";

// ── 2. Fix double-URL in DB ───────────────────────────────
$pdo  = getPDO();
$base = rtrim(APP_URL, '/');
$skip = strlen($base);

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
$db_fixed = $stmt->rowCount();
echo "DB cleanup: fixed {$db_fixed} broken image_url(s).\n";

// Verify
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

echo "\nDone. You can delete this file now.\n";
