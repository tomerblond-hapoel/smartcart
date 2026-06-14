<?php
// SmartCart — Conversational Agent Chat API
//
// POST /api/agent_chat.php
// Body (JSON): { message, history, user_lat?, user_lng? }
// Response:    { message, intent, groups, products }
//
// Architecture (generative):
//   1. Fetch ALL open groups + ALL catalog products from DB
//   2. Score groups for quality (preferences, discount, fill, urgency, location)
//   3. Send full list + user message to Groq — Groq picks relevant IDs semantically
//   4. Filter DB results to Groq's selected IDs and return cards + message

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/GroqService.php';

session_start();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'POST required'], 405);
}

$user_id = require_auth();
$pdo     = getPDO();

// ── Load user ─────────────────────────────────────────────────────────────────
$ustmt = $pdo->prepare('SELECT full_name, lat, lng, preferred_categories FROM users WHERE id = ? AND is_active = 1');
$ustmt->execute([$user_id]);
$user = $ustmt->fetch();
if (!$user) json_response(['error' => 'User not found'], 404);

$user_name = explode(' ', trim($user['full_name'] ?? 'there'))[0];
$user_lat  = $user['lat'] !== null ? (float)$user['lat'] : null;
$user_lng  = $user['lng'] !== null ? (float)$user['lng'] : null;
$user_cats = json_decode($user['preferred_categories'] ?? '[]', true) ?: [];

// ── Parse request ─────────────────────────────────────────────────────────────
$body    = get_json_body();
$message = trim($body['message'] ?? '');
$history = array_slice(array_values((array)($body['history'] ?? [])), -10);

if (!empty($body['user_lat']) && !empty($body['user_lng'])) {
    $user_lat = (float)$body['user_lat'];
    $user_lng = (float)$body['user_lng'];
}

if ($message === '') {
    json_response(['error' => 'Message required'], 400);
}

// ── 1. Detect greeting (no search intent) ─────────────────────────────────────
$is_greeting = empty(agent_normalize($message));

// ── 2. Fetch all data from DB ─────────────────────────────────────────────────
$all_groups   = agent_fetch_scored_groups($pdo, $user_lat, $user_lng, $user_cats);
$all_products = agent_fetch_catalog_products($pdo);

// ── 3. Groq semantic selection + natural response ─────────────────────────────
$groq_res   = groq_chat_semantic($message, $history, $all_groups, $all_products, $user_name, $is_greeting);
$intent     = $groq_res['intent'] ?? 'other';
$reply      = $groq_res['message'];
$group_ids  = $groq_res['group_ids'] ?? [];
$prod_ids   = $groq_res['product_ids'] ?? [];

// ── 4. Build result sets ──────────────────────────────────────────────────────
$is_search_intent = !$is_greeting && !in_array($intent, ['greeting', 'off_topic'], true);

if (!$is_search_intent) {
    // Greeting or off-topic: show top quality-scored profile groups
    $groups = array_slice($all_groups, 0, 4);
} else {
    // Search mode: show only what Groq selected — empty array is valid (no matches)
    $by_id  = [];
    foreach ($all_groups as $g) $by_id[$g['group_id']] = $g;
    $groups = array_values(array_filter(
        array_map(fn($id) => $by_id[$id] ?? null, $group_ids),
        fn($g) => $g !== null
    ));
}

$by_prod_id = [];
foreach ($all_products as $p) $by_prod_id[(int)$p['id']] = $p;
$products = array_values(array_filter(
    array_map(fn($id) => $by_prod_id[$id] ?? null, $prod_ids),
    fn($p) => $p !== null
));

// Fallback reply when Groq is unavailable
if ($reply === null) {
    $reply = agent_fallback_reply($groups, $products, $is_greeting);
}

// ── 5. Return ─────────────────────────────────────────────────────────────────
json_response([
    'message'  => $reply,
    'intent'   => $intent,
    'groups'   => $groups,
    'products' => $products,
]);

// ═════════════════════════════════════════════════════════════════════════════
// Helper functions
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Detect if the message has any real search intent (non-stop English words or Hebrew).
 * Returns empty array for pure greetings like "hi", "hello", "hey".
 */
function agent_normalize(string $q): array {
    static $stop = [
        'i','am','looking','for','need','want','find','a','an','the','some','any',
        'give','show','see','me','buy','get','can','you','please','best','good',
        'cheap','great','new','top','something','like','is','are','was','be','do',
        'does','have','has','will','would','could','all','open','group','groups',
        'deal','deals','product','products','browse','available','today','here',
        'list','search','now','hi','hello','hey','recommend','suggest','help',
        'just','really','very','about','price','purchase','order','on','in','at',
        'from','with','and','or','not','of','what','where','how','when','which',
        'create','start','join','make','shalom','sup','yo',
    ];
    // Hebrew input always has search intent
    if (preg_match('/[א-ת]/', $q)) return ['hebrew'];
    $words = preg_split('/[\s,;.!?\/\-]+/', strtolower($q), -1, PREG_SPLIT_NO_EMPTY);
    $out   = [];
    foreach ($words as $w) {
        $w = preg_replace('/[^a-z0-9]/', '', $w);
        if (strlen($w) < 2 || in_array($w, $stop, true)) continue;
        $out[] = $w;
    }
    return array_values(array_unique($out));
}

/**
 * Fetch all open groups and score them by quality (preferences, discount, fill, urgency, location).
 * Returns groups sorted by score descending, with diversity cap (max 2 per category).
 */
function agent_fetch_scored_groups(PDO $pdo, ?float $lat, ?float $lng, array $user_cats = []): array {
    $stmt = $pdo->prepare("
        SELECT gp.id AS group_id, gp.product_id,
               gp.current_participants, gp.target_participants,
               gp.deadline, gp.city,
               gp.lat AS group_lat, gp.lng AS group_lng,
               p.name AS product_name, p.description AS product_desc,
               p.image_url AS product_image, p.price_ils, p.group_price_ils,
               p.category, p.min_participants,
               b.business_name, b.city AS biz_city,
               b.lat AS biz_lat, b.lng AS biz_lng
        FROM   group_purchases gp
        JOIN   products  p ON p.id  = gp.product_id  AND p.status = 'active'
        JOIN   businesses b ON b.id = p.business_id  AND b.status = 'active'
        WHERE  gp.status = 'open'
        ORDER  BY gp.created_at DESC
    ");
    $stmt->execute();
    $all = $stmt->fetchAll();

    $scored = [];
    foreach ($all as $g) {
        $score = 0;

        if (!empty($user_cats) && in_array($g['category'], $user_cats, true)) $score += 30;

        $price  = (float)$g['price_ils'];
        $gprice = (float)$g['group_price_ils'];
        $disc   = $price > 0 ? (($price - $gprice) / $price * 100) : 0;
        $score += $disc >= 30 ? 20 : ($disc >= 15 ? 10 : 0);

        $current = (int)$g['current_participants'];
        $target  = (int)$g['target_participants'];
        $fill    = $target > 0 ? ($current / $target) : 0;
        $score  += $fill >= 0.5 ? 15 : ($fill >= 0.25 ? 7 : 0);

        $days_left = days_until($g['deadline']);
        $score    += $days_left <= 3 ? 10 : ($days_left <= 7 ? 5 : 0);

        $dist = null;
        if ($lat !== null && $lng !== null) {
            $glat = $g['group_lat'] ?? $g['biz_lat'];
            $glng = $g['group_lng'] ?? $g['biz_lng'];
            if ($glat !== null && $glng !== null) {
                $dist   = haversine($lat, $lng, (float)$glat, (float)$glng);
                $score += $dist <= 10 ? 25 : ($dist <= 30 ? 15 : 0);
            }
        }

        $scored[] = [
            'group_id'             => (int)$g['group_id'],
            'product_id'           => (int)$g['product_id'],
            'product_name'         => $g['product_name'],
            'product_desc'         => $g['product_desc'] ?? '',
            'product_image'        => $g['product_image'],
            'business_name'        => $g['business_name'],
            'group_price_ils'      => $gprice,
            'price_ils'            => $price,
            'city'                 => $g['city'] ?? $g['biz_city'],
            'category'             => $g['category'],
            'score'                => $score,
            'discount_percent'     => (int)round($disc),
            'fill_percent'         => (int)round($fill * 100),
            'current_participants' => $current,
            'target_participants'  => $target,
            'min_participants'     => (int)$g['min_participants'],
            'days_left'            => $days_left,
            'countdown'            => countdown_label($g['deadline']),
            'distance_km'          => $dist !== null ? round($dist, 1) : null,
        ];
    }

    usort($scored, fn($a, $b) => $b['score'] - $a['score']);

    // Diversity cap: max 2 per category
    $cat_counts = [];
    $diverse    = [];
    foreach ($scored as $g) {
        $c = $g['category'] ?? 'other';
        $cat_counts[$c] = ($cat_counts[$c] ?? 0) + 1;
        if ($cat_counts[$c] <= 2) $diverse[] = $g;
    }

    return $diverse;
}

/**
 * Fetch all active catalog products (regardless of open groups).
 */
function agent_fetch_catalog_products(PDO $pdo): array {
    $stmt = $pdo->prepare("
        SELECT p.id, p.name AS product_name, p.image_url AS product_image,
               p.price_ils, p.group_price_ils, p.category, p.min_participants,
               p.description AS product_desc,
               ROUND((p.price_ils - p.group_price_ils) / NULLIF(p.price_ils,0) * 100) AS discount_percent,
               b.business_name
        FROM   products  p
        JOIN   businesses b ON b.id = p.business_id AND b.status = 'active'
        WHERE  p.status = 'active'
        ORDER  BY p.created_at DESC
    ");
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Template-based fallback used when Groq is unavailable.
 */
function agent_fallback_reply(array $groups, array $products, bool $greeting = false): string {
    if ($greeting && !empty($groups)) {
        $n = count($groups);
        return "Hi! Here are **{$n} featured group deal" . ($n > 1 ? 's' : '') . "** right now. 🛍️ "
            . "Join one below, or tell me what you're looking for!";
    }
    if (!empty($groups)) {
        $n = count($groups);
        return "Found **{$n} open group" . ($n > 1 ? 's' : '') . "** for you! 🎉 "
            . "Pick one below to join and unlock the group discount.";
    }
    if (!empty($products)) {
        $n = count($products);
        return "No open groups yet, but **{$n} product" . ($n > 1 ? 's' : '') . "** in the catalog match. 💡 "
            . "Click **\"Start a Group\"** to be the first member!";
    }
    return "Tell me what product you're looking for and I'll find open group deals for you! 🛍️";
}
