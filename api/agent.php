<?php
// SmartCart — Smart Agent API
// Real recommendation engine using Haversine GPS distance + weighted scoring
//
// GET /api/agent.php?user_id=X&limit=5&max_distance_km=50
//
// Scoring (max 100 pts):
//   Category match  — +30 if product category matches any of user's preferred_categories
//   Location        — +25 if ≤10km | +15 if ≤30km | +0 if farther
//   Discount        — +20 if ≥30% off | +10 if ≥15% off | +0 otherwise
//   Fill rate       — +15 if ≥50% full | +7 if ≥25% full | +0 otherwise
//   Urgency         — +10 if ≤3 days left | +5 if ≤7 days left | +0 otherwise

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

$user_id        = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$limit          = max(1, min(20, (int)($_GET['limit'] ?? 5)));
$max_dist_km    = isset($_GET['max_distance_km']) && $_GET['max_distance_km'] !== ''
                    ? (float)$_GET['max_distance_km']
                    : null; // null = no distance filter

if (!$user_id) {
    json_response(['error' => 'user_id is required'], 400);
}

$pdo = getPDO();

// 1. Load user profile
$ustmt = $pdo->prepare('SELECT lat, lng, preferred_categories FROM users WHERE id = ? AND is_active = 1');
$ustmt->execute([$user_id]);
$user = $ustmt->fetch();

if (!$user) {
    json_response(['error' => 'User not found'], 404);
}

$user_lat   = $user['lat']  !== null ? (float)$user['lat']  : null;
$user_lng   = $user['lng']  !== null ? (float)$user['lng']  : null;
$user_cats  = json_decode($user['preferred_categories'] ?? '[]', true) ?: [];

// Allow the frontend to pass live browser geolocation that overrides the saved profile location
if (isset($_GET['live_lat']) && isset($_GET['live_lng'])) {
    $live_lat = (float)$_GET['live_lat'];
    $live_lng = (float)$_GET['live_lng'];
    if ($live_lat !== 0.0 || $live_lng !== 0.0) {
        $user_lat = $live_lat;
        $user_lng = $live_lng;
    }
}

// 2. Load all open group purchases with product + business details
$gstmt = $pdo->prepare("
    SELECT
        gp.id                AS group_id,
        gp.product_id,
        gp.current_participants,
        gp.target_participants,
        gp.deadline,
        gp.city,
        gp.lat               AS group_lat,
        gp.lng               AS group_lng,
        p.name               AS product_name,
        p.image_url          AS product_image,
        p.price_ils,
        p.group_price_ils,
        p.category,
        b.business_name,
        b.city               AS biz_city,
        b.address            AS biz_address,
        b.lat                AS biz_lat,
        b.lng                AS biz_lng
    FROM group_purchases gp
    JOIN products p ON p.id = gp.product_id AND p.status = 'active'
    JOIN businesses b ON b.id = p.business_id AND b.status = 'active'
    WHERE gp.status = 'open'
      AND gp.deadline > NOW()
    ORDER BY gp.created_at DESC
");
$gstmt->execute();
$groups = $gstmt->fetchAll();

// 3. Score each group
$results = [];

foreach ($groups as $g) {
    $score = 0;
    $breakdown = [
        'category' => 0,
        'location' => 0,
        'discount' => 0,
        'fill'     => 0,
        'urgency'  => 0,
    ];

    // ── Category match (+30) ──────────────────────────────
    if (!empty($user_cats) && in_array($g['category'], $user_cats)) {
        $score += 30;
        $breakdown['category'] = 30;
    }

    // ── Location (+25/+15/0) ──────────────────────────────
    $distance_km = null;
    if ($user_lat !== null && $user_lng !== null && $g['group_lat'] !== null && $g['group_lng'] !== null) {
        $distance_km = haversine($user_lat, $user_lng, (float)$g['group_lat'], (float)$g['group_lng']);

        // Apply max_distance_km filter if set
        if ($max_dist_km !== null && $distance_km > $max_dist_km) {
            continue; // Skip groups too far away
        }

        if ($distance_km <= 10.0) {
            $loc_pts = 25;
        } elseif ($distance_km <= 30.0) {
            $loc_pts = 15;
        } else {
            $loc_pts = 0;
        }
        $score += $loc_pts;
        $breakdown['location'] = $loc_pts;
    }
    // If user has no location or group has no coords, location contributes 0 (not punished)

    // ── Discount (+20/+10/0) ─────────────────────────────
    $price    = (float)$g['price_ils'];
    $gprice   = (float)$g['group_price_ils'];
    $disc_pct = $price > 0 ? (($price - $gprice) / $price * 100) : 0;

    if ($disc_pct >= 30.0) {
        $disc_pts = 20;
    } elseif ($disc_pct >= 15.0) {
        $disc_pts = 10;
    } else {
        $disc_pts = 0;
    }
    $score += $disc_pts;
    $breakdown['discount'] = $disc_pts;

    // ── Fill rate (+15/+7/0) ─────────────────────────────
    $current = (int)$g['current_participants'];
    $target  = (int)$g['target_participants'];
    $fill    = $target > 0 ? ($current / $target) : 0;

    if ($fill >= 0.5) {
        $fill_pts = 15;
    } elseif ($fill >= 0.25) {
        $fill_pts = 7;
    } else {
        $fill_pts = 0;
    }
    $score += $fill_pts;
    $breakdown['fill'] = $fill_pts;

    // ── Urgency (+10/+5/0) ───────────────────────────────
    $days_left = days_until($g['deadline']);

    if ($days_left <= 3) {
        $urgency_pts = 10;
    } elseif ($days_left <= 7) {
        $urgency_pts = 5;
    } else {
        $urgency_pts = 0;
    }
    $score += $urgency_pts;
    $breakdown['urgency'] = $urgency_pts;

    // ── Build result entry ───────────────────────────────
    $results[] = [
        'group_id'        => (int)$g['group_id'],
        'product_id'      => (int)$g['product_id'],
        'product_name'    => $g['product_name'],
        'product_image'   => $g['product_image'],
        'business_name'   => $g['business_name'],
        'group_price_ils' => $gprice,
        'price_ils'       => $price,
        'city'            => $g['city'] ?? $g['biz_city'],
        'address'         => $g['biz_address'] ?? null,
        'category'        => $g['category'],
        'score'           => $score,
        'score_breakdown' => $breakdown,
        'discount_percent'=> (int)round($disc_pct),
        'fill_percent'    => (int)round($fill * 100),
        'current_participants' => $current,
        'target_participants'  => $target,
        'days_left'       => $days_left,
        'countdown'       => countdown_label($g['deadline']),
        'distance_km'     => $distance_km !== null ? round($distance_km, 1) : null,
        'lat'             => $g['group_lat'] !== null ? (float)$g['group_lat'] : ($g['biz_lat'] !== null ? (float)$g['biz_lat'] : null),
        'lng'             => $g['group_lng'] !== null ? (float)$g['group_lng'] : ($g['biz_lng'] !== null ? (float)$g['biz_lng'] : null),
    ];
}

// 4. Sort by score descending, then by fill_percent descending as tiebreaker
usort($results, function($a, $b) {
    if ($b['score'] !== $a['score']) return $b['score'] - $a['score'];
    return $b['fill_percent'] - $a['fill_percent'];
});

// 5. Slice to limit
$results = array_slice($results, 0, $limit);

// Return as flat array (no wrapper key) for easy frontend iteration
echo json_encode(array_values($results), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
