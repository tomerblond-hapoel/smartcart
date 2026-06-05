<?php
// SmartCart — Smart Agent API v2
// Precision recommendation engine with strict relevance filtering
//
// TEXT SCORING (query mode):
//   Exact phrase in title       — 50 pts
//   All keywords in title       — 40 pts  (any order)
//   Single keyword in title     — 35 pts
//   ≥ 2/3 keywords in title     — 28 pts  (only for 3+ keyword queries)
//   Brand keyword bonus         — +5 pts
//   Description match bonus     — +3 pts per keyword (max +6)
//   Minimum threshold to show   — 28 pts  (anything below is filtered out)
//
// QUALITY SCORING (all modes, stacks on top of text score):
//   Location  — +25 if ≤10 km | +15 if ≤30 km
//   Discount  — +20 if ≥30%   | +10 if ≥15%
//   Fill rate — +15 if ≥50%   | +7  if ≥25%
//   Urgency   — +10 if ≤3 days | +5 if ≤7 days
//
// PROFILE MODE (no query): category preference matching, max $limit results
// QUERY MODE:              text matching, max 3 results

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

$user_id     = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$limit       = max(1, min(20, (int)($_GET['limit'] ?? 5)));
$max_dist_km = isset($_GET['max_distance_km']) && $_GET['max_distance_km'] !== ''
                 ? (float)$_GET['max_distance_km'] : null;

// ─────────────────────────────────────────────────────────────
// Query parsing and keyword normalisation
// ─────────────────────────────────────────────────────────────

$raw_query       = isset($_GET['query']) ? trim($_GET['query']) : '';
$query_mode      = $raw_query !== '';
$query_keywords  = [];  // cleaned, normalised, de-pluralised
$auto_category   = null;
$filter_category = isset($_GET['category']) && $_GET['category'] !== ''
                     ? $_GET['category'] : null;

/**
 * Normalise a free-text query into clean search keywords.
 *
 * Steps:
 *  1. Lowercase and strip punctuation.
 *  2. Remove stop-words and single-character tokens.
 *  3. Strip trailing 's' from words longer than 4 chars so "speakers"
 *     matches products labelled "speaker" (and vice-versa via strpos).
 */
function normalize_query(string $q): array {
    static $stop = [
        'i','am','looking','for','need','want','find','a','an','the','some','any',
        'give','show','see','me','buy','get','can','you','please','best','good',
        'cheap','great','new','top','something','like','student','students','is',
        'are','was','be','do','does','have','has','will','would','could','all',
        'open','group','groups','deal','deals','product','products','browse',
        'available','current','today','here','list','search','now','hi','hello',
        'hey','recommend','suggest','help','just','really','very','about','price',
        'purchase','order','on','in','at','from','with','and','or','not','of',
    ];

    $words = preg_split('/[\s,;.!?\/\-]+/', strtolower($q), -1, PREG_SPLIT_NO_EMPTY);
    $out   = [];
    foreach ($words as $w) {
        $w = preg_replace('/[^a-z0-9]/', '', $w);
        if (strlen($w) < 2 || in_array($w, $stop, true)) continue;
        // Strip trailing 's' to normalise plurals (e.g. "speakers" → "speaker")
        // Guard: don't strip from "ss" endings (e.g. "bass", "glass")
        if (strlen($w) > 4 && substr($w, -1) === 's' && substr($w, -2) !== 'ss') {
            $w = substr($w, 0, -1);
        }
        $out[] = $w;
    }
    return array_values(array_unique($out));
}

if ($query_mode) {
    $query_keywords = normalize_query($raw_query);
    if (empty($query_keywords)) {
        $query_mode = false; // every word was a stop-word → fall back to profile mode
    }

    // Auto-detect category from normalised keywords
    $kw_cat_map = [
        'electronics' => ['speaker','headphone','earphone','airpod','jbl','sony','samsung',
                          'bose','apple','iphone','laptop','computer','tablet','ipad','tv',
                          'camera','phone','bluetooth','earbud','keyboard','mouse','gaming',
                          'console','xbox','playstation','charger','smartwatch','watch',
                          'monitor','printer','router','modem','projector'],
        'home'        => ['sofa','chair','table','desk','bed','mattress','lamp','shelf',
                          'cabinet','pan','pot','blender','vacuum','curtain','rug','towel',
                          'mirror','fridge','washing','dishwasher','microwave','toaster','kettle'],
        'fashion'     => ['shirt','jean','dress','shoe','sneaker','bag','jacket','coat',
                          'hat','sock','pant','clothes','sandal','boot','hoodie','trouser',
                          'belt','glove','scarf','suit','underwear'],
        'food'        => ['food','snack','coffee','tea','juice','organic','protein','vitamin',
                          'supplement','capsule','chocolate','cereal','nutrition','grain'],
        'sports'      => ['gym','fitness','yoga','running','bike','bicycle','football',
                          'basketball','tennis','exercise','weight','dumbbell','treadmill',
                          'sport','workout','swim','cycling','hiking','climbing'],
        'beauty'      => ['makeup','lipstick','skincare','cream','serum','perfume','shampoo',
                          'lotion','foundation','mascara','conditioner','moisturiser','nail'],
        'toys'        => ['toy','game','lego','doll','puzzle','kid','children','baby','board'],
        'books'       => ['book','novel','textbook','guide','cookbook','reading','magazine'],
        'automotive'  => ['car','tire','wheel','motor','engine','vehicle','dash','oil','wiper'],
    ];
    foreach ($kw_cat_map as $cat => $cat_kws) {
        foreach ($query_keywords as $kw) {
            if (in_array($kw, $cat_kws, true)) { $auto_category = $cat; break 2; }
        }
    }
}

if (!$user_id) {
    json_response(['error' => 'user_id is required'], 400);
}

$pdo = getPDO();

// ─────────────────────────────────────────────────────────────
// Load user profile
// ─────────────────────────────────────────────────────────────
$ustmt = $pdo->prepare('SELECT lat, lng, preferred_categories FROM users WHERE id = ? AND is_active = 1');
$ustmt->execute([$user_id]);
$user = $ustmt->fetch();
if (!$user) { json_response(['error' => 'User not found'], 404); }

$user_lat  = $user['lat']  !== null ? (float)$user['lat']  : null;
$user_lng  = $user['lng']  !== null ? (float)$user['lng']  : null;
$user_cats = json_decode($user['preferred_categories'] ?? '[]', true) ?: [];

// Live browser geolocation overrides saved profile location
if (isset($_GET['live_lat'], $_GET['live_lng'])) {
    $ll = (float)$_GET['live_lat'];
    $lo = (float)$_GET['live_lng'];
    if ($ll !== 0.0 || $lo !== 0.0) { $user_lat = $ll; $user_lng = $lo; }
}

// ─────────────────────────────────────────────────────────────
// Load all open group purchases + product + business details
// ─────────────────────────────────────────────────────────────
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
        p.description        AS product_desc,
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
    JOIN products p  ON p.id  = gp.product_id  AND p.status = 'active'
    JOIN businesses b ON b.id = p.business_id  AND b.status = 'active'
    WHERE gp.status = 'open'
      AND gp.deadline > NOW()
    ORDER BY gp.created_at DESC
");
$gstmt->execute();
$groups = $gstmt->fetchAll();

// Brand keywords that receive a bonus point boost
$brand_kws = [
    'jbl','sony','samsung','apple','bose','nike','adidas','asus','lg','philips',
    'dyson','xiaomi','huawei','dell','hp','lenovo','reebok','puma','logitech',
    'canon','nikon','braun','bosch','ikea','zara','uniqlo',
];

// ─────────────────────────────────────────────────────────────
// Score each group
// ─────────────────────────────────────────────────────────────
$results = [];

foreach ($groups as $g) {
    $score        = 0;
    $match_reason = '';
    $breakdown    = ['category' => 0, 'location' => 0, 'discount' => 0, 'fill' => 0, 'urgency' => 0];

    // ── Category dropdown filter (hard exclusion) ─────────────
    if ($filter_category !== null && $g['category'] !== $filter_category) {
        continue;
    }

    // ── TEXT RELEVANCE (query mode) ───────────────────────────
    if ($query_mode && !empty($query_keywords)) {

        $name_lower = strtolower($g['product_name']);
        $desc_lower = strtolower($g['product_desc'] ?? '');

        // Find which keywords appear in the title vs description
        $in_name = [];
        $in_desc = [];
        foreach ($query_keywords as $kw) {
            if (strpos($name_lower, $kw) !== false) {
                $in_name[] = $kw;
            } elseif (strpos($desc_lower, $kw) !== false) {
                $in_desc[] = $kw;
            }
        }

        $total     = count($query_keywords);
        $name_hits = count($in_name);

        // Check for exact contiguous phrase (e.g. "coffee machine" as one phrase)
        $phrase = implode(' ', $query_keywords);
        $exact  = $total > 1 && strpos($name_lower, $phrase) !== false;

        $text_score = 0;

        if ($exact) {
            // Best possible: the whole search phrase is in the title
            $text_score   = 50;
            $match_reason = implode(' + ', $in_name);

        } elseif ($total === 1 && $name_hits === 1) {
            // Single-keyword query and it's found in the title
            $text_score   = 35;
            $match_reason = $in_name[0];

        } elseif ($total >= 2 && $name_hits === $total) {
            // Every keyword found in the title (any order)
            $text_score   = 40;
            $match_reason = implode(' + ', $in_name);

        } elseif ($total >= 3 && $name_hits >= (int)ceil($total * 0.66)) {
            // At least 2 out of 3+ keywords found in title
            $text_score   = 28;
            $match_reason = implode(' + ', $in_name);

        } else {
            // Too few title matches — discard (do not show)
            continue;
        }

        // Brand bonus: one of the matched title keywords is a known brand
        foreach ($in_name as $mk) {
            if (in_array($mk, $brand_kws, true)) { $text_score += 5; break; }
        }

        // Description bonus: unmatched keywords found in description (+3 each, max +6)
        if (!empty($in_desc)) {
            $text_score += min(6, count($in_desc) * 3);
        }

        $score += $text_score;
        $breakdown['category'] = $text_score;

    // ── PROFILE CATEGORY MATCH (no query) ────────────────────
    } elseif (!empty($user_cats) && in_array($g['category'], $user_cats, true)) {
        $score += 30;
        $breakdown['category'] = 30;
    }

    // ── LOCATION (+25 / +15 / 0) ─────────────────────────────
    $distance_km = null;
    if ($user_lat !== null && $user_lng !== null) {
        $g_lat = $g['group_lat'] !== null ? (float)$g['group_lat']
               : ($g['biz_lat'] !== null  ? (float)$g['biz_lat'] : null);
        $g_lng = $g['group_lng'] !== null ? (float)$g['group_lng']
               : ($g['biz_lng'] !== null  ? (float)$g['biz_lng'] : null);
        if ($g_lat !== null && $g_lng !== null) {
            $distance_km = haversine($user_lat, $user_lng, $g_lat, $g_lng);
            if ($max_dist_km !== null && $distance_km > $max_dist_km) continue;
            $loc_pts = $distance_km <= 10.0 ? 25 : ($distance_km <= 30.0 ? 15 : 0);
            $score += $loc_pts;
            $breakdown['location'] = $loc_pts;
        }
    }

    // ── DISCOUNT (+20 / +10 / 0) ─────────────────────────────
    $price    = (float)$g['price_ils'];
    $gprice   = (float)$g['group_price_ils'];
    $disc_pct = $price > 0 ? (($price - $gprice) / $price * 100) : 0;
    $disc_pts = $disc_pct >= 30.0 ? 20 : ($disc_pct >= 15.0 ? 10 : 0);
    $score += $disc_pts;
    $breakdown['discount'] = $disc_pts;

    // ── FILL RATE (+15 / +7 / 0) ─────────────────────────────
    $current  = (int)$g['current_participants'];
    $target   = (int)$g['target_participants'];
    $fill     = $target > 0 ? ($current / $target) : 0;
    $fill_pts = $fill >= 0.5 ? 15 : ($fill >= 0.25 ? 7 : 0);
    $score += $fill_pts;
    $breakdown['fill'] = $fill_pts;

    // ── URGENCY (+10 / +5 / 0) ───────────────────────────────
    $days_left   = days_until($g['deadline']);
    $urgency_pts = $days_left <= 3 ? 10 : ($days_left <= 7 ? 5 : 0);
    $score += $urgency_pts;
    $breakdown['urgency'] = $urgency_pts;

    // ── Append result ─────────────────────────────────────────
    $results[] = [
        'group_id'             => (int)$g['group_id'],
        'product_id'           => (int)$g['product_id'],
        'product_name'         => $g['product_name'],
        'product_image'        => $g['product_image'],
        'business_name'        => $g['business_name'],
        'group_price_ils'      => $gprice,
        'price_ils'            => $price,
        'city'                 => $g['city'] ?? $g['biz_city'],
        'category'             => $g['category'],
        'score'                => $score,
        'score_breakdown'      => $breakdown,
        'discount_percent'     => (int)round($disc_pct),
        'fill_percent'         => (int)round($fill * 100),
        'current_participants' => $current,
        'target_participants'  => $target,
        'days_left'            => $days_left,
        'countdown'            => countdown_label($g['deadline']),
        'distance_km'          => $distance_km !== null ? round($distance_km, 1) : null,
        'match_reason'         => $match_reason,
        'lat'  => $g['group_lat'] !== null ? (float)$g['group_lat']
                : ($g['biz_lat']  !== null ? (float)$g['biz_lat']  : null),
        'lng'  => $g['group_lng'] !== null ? (float)$g['group_lng']
                : ($g['biz_lng']  !== null ? (float)$g['biz_lng']  : null),
    ];
}

// ─────────────────────────────────────────────────────────────
// Sort: score descending, fill % as tiebreaker
// ─────────────────────────────────────────────────────────────
usort($results, function ($a, $b) {
    return $b['score'] !== $a['score']
        ? $b['score'] - $a['score']
        : $b['fill_percent'] - $a['fill_percent'];
});

// Cap: 3 results in query mode, $limit in profile mode
$effective_limit = ($query_mode && !empty($query_keywords)) ? 3 : $limit;
$results = array_slice($results, 0, $effective_limit);

// ─────────────────────────────────────────────────────────────
// Catalog fallback: if query returned zero groups, search products
// so the frontend can offer "start a new group for this product"
// ─────────────────────────────────────────────────────────────
$suggested_products = [];
if ($query_mode && !empty($query_keywords) && empty($results)) {
    $like_parts  = [];
    $bind_params = [];
    foreach ($query_keywords as $kw) {
        $like_parts[]  = 'p.name LIKE ?';
        $bind_params[] = '%' . $kw . '%';
    }
    // For multi-keyword searches require ALL keywords in the name (AND),
    // so "coffee machine" never surfaces "coffee capsules" or "coffee table".
    $name_cond = count($query_keywords) >= 2
        ? implode(' AND ', $like_parts)
        : $like_parts[0];

    $prod_stmt = $pdo->prepare("
        SELECT p.id,
               p.name             AS product_name,
               p.image_url        AS product_image,
               p.price_ils,
               p.group_price_ils,
               p.category,
               ROUND((p.price_ils - p.group_price_ils) / NULLIF(p.price_ils, 0) * 100)
                                  AS discount_percent,
               b.business_name
        FROM   products p
        JOIN   businesses b ON b.id = p.business_id AND b.status = 'active'
        WHERE  p.status = 'active'
          AND  ({$name_cond})
        ORDER  BY p.created_at DESC
        LIMIT  3
    ");
    $prod_stmt->execute($bind_params);
    $suggested_products = $prod_stmt->fetchAll();
}

// ─────────────────────────────────────────────────────────────
// Response
// ─────────────────────────────────────────────────────────────
echo json_encode([
    'meta' => [
        'query_mode'         => $query_mode,
        'query_keywords'     => $query_keywords,
        'auto_category'      => $auto_category,
        'total_found'        => count($results),
        'suggested_products' => $suggested_products,
    ],
    'results' => array_values($results),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
