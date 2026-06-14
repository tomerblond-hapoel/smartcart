<?php
// SmartCart — Conversational Agent Chat API
//
// POST /api/agent_chat.php
// Body (JSON): { message, history, user_lat?, user_lng? }
// Response:    { message, intent, groups, products }
//
// Architecture:
//   1. Normalize query keywords (rule-based + Groq keyword extractor)
//   2. DB search: open groups ranked by relevance, then catalog products as fallback
//   3. Groq Llama 3.3 generates a natural-language response from real DB results
//   4. Return message text + structured card data to the frontend
//
// The frontend renders group cards with in-chat Join buttons and product cards
// with in-chat "Start a Group" buttons — actual DB mutations use existing api/groups.php.

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
// Keep at most the last 10 turns so the Groq context stays small
$history = array_slice(array_values((array)($body['history'] ?? [])), -10);

if (!empty($body['user_lat']) && !empty($body['user_lng'])) {
    $user_lat = (float)$body['user_lat'];
    $user_lng = (float)$body['user_lng'];
}

if ($message === '') {
    json_response(['error' => 'Message required'], 400);
}

// ── 1. Extract keywords ───────────────────────────────────────────────────────
$keywords = agent_normalize($message);

// Groq keyword extractor adds Hebrew translation + category detection
$groq_kw       = groq_extract_query($message);
$auto_category = null;
if ($groq_kw !== null && !empty($groq_kw['keywords'])) {
    $keywords      = array_values(array_unique(array_merge($keywords, $groq_kw['keywords'])));
    $auto_category = $groq_kw['category'] ?? null;
}

// ── 2. DB search ──────────────────────────────────────────────────────────────
$groups      = [];
$products    = [];
$profile_mode = false;

if (!empty($keywords)) {
    $groups   = agent_search_groups($pdo, $keywords, $user_lat, $user_lng, $user_cats);
    // Always search products too — needed for create-group intent & no-group fallback
    $products = agent_search_products($pdo, $keywords, $auto_category);
} else {
    // Greeting / general question — show personalised profile picks instead of empty response
    $groups       = agent_profile_groups($pdo, $user_lat, $user_lng, $user_cats);
    $profile_mode = true;
}

// ── 3. Build Groq context from real DB results ────────────────────────────────
$db_context = agent_build_context($groups, $products);

// ── 4. Generate natural response via Groq ─────────────────────────────────────
$groq_res = groq_chat_response($message, $history, $db_context, $user_name);
$intent   = $groq_res['intent']  ?? 'other';
// Fall back to template message when Groq is unavailable
$reply = $groq_res['message'] ?? agent_fallback_reply($groups, $products, $keywords, $profile_mode);

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
 * Strip stop-words and normalise an English/mixed query into product keywords.
 * Hebrew characters are stripped here; groq_extract_query() handles translation.
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
        'create','start','join','make',
    ];
    $words = preg_split('/[\s,;.!?\/\-]+/', strtolower($q), -1, PREG_SPLIT_NO_EMPTY);
    $out   = [];
    foreach ($words as $w) {
        $w = preg_replace('/[^a-z0-9]/', '', $w);
        if (strlen($w) < 2 || in_array($w, $stop, true)) continue;
        // Normalise plurals: "speakers" → "speaker"
        if (strlen($w) > 4 && substr($w, -1) === 's' && substr($w, -2) !== 'ss') {
            $w = substr($w, 0, -1);
        }
        $out[] = $w;
    }
    return array_values(array_unique($out));
}

/**
 * Search open group purchases and rank by relevance + quality score.
 * Returns up to 5 best matches; empty array when no keywords match any product name.
 */
function agent_search_groups(PDO $pdo, array $keywords, ?float $lat, ?float $lng, array $user_cats = []): array {
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

    $brand_kws = ['jbl','sony','samsung','apple','bose','nike','adidas','asus','lg',
                  'philips','dyson','xiaomi','huawei','dell','hp','lenovo','logitech'];

    $results = [];
    foreach ($all as $g) {
        $name_lower = strtolower($g['product_name']);
        $desc_lower = strtolower($g['product_desc'] ?? '');

        $in_name = [];
        $in_desc = [];
        foreach ($keywords as $kw) {
            if (strpos($name_lower, $kw) !== false)      $in_name[] = $kw;
            elseif (strpos($desc_lower, $kw) !== false)  $in_desc[] = $kw;
        }
        if (empty($in_name)) continue; // at least one keyword must hit the product name

        $total  = count($keywords);
        $n_hit  = count($in_name);
        $phrase = implode(' ', $keywords);
        $exact  = $total > 1 && strpos($name_lower, $phrase) !== false;

        if ($exact)                                           $score = 50;
        elseif ($total === 1 && $n_hit === 1)                 $score = 35;
        elseif ($total >= 2 && $n_hit === $total)             $score = 40;
        elseif ($total >= 3 && $n_hit >= (int)ceil($total * 0.66)) $score = 28;
        else                                                  $score = 18;

        foreach ($in_name as $mk) {
            if (in_array($mk, $brand_kws, true)) { $score += 5; break; }
        }
        $score += min(6, count($in_desc) * 3);

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

        // Preference boost: user has saved this category as a preference
        if (!empty($user_cats) && in_array($g['category'], $user_cats, true)) {
            $score += 10;
        }

        $dist = null;
        if ($lat !== null && $lng !== null) {
            $glat = $g['group_lat'] ?? $g['biz_lat'];
            $glng = $g['group_lng'] ?? $g['biz_lng'];
            if ($glat !== null && $glng !== null) {
                $dist   = haversine($lat, $lng, (float)$glat, (float)$glng);
                $score += $dist <= 10 ? 25 : ($dist <= 30 ? 15 : 0);
            }
        }

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

    usort($results, function ($a, $b) { return $b['score'] - $a['score']; });
    return array_slice($results, 0, 5);
}

/**
 * Search the product catalog (regardless of open groups).
 * Used for create-group intent and as a fallback when no open groups match.
 */
function agent_search_products(PDO $pdo, array $keywords, ?string $cat): array {
    if (empty($keywords)) return [];

    $parts  = [];
    $params = [];
    foreach ($keywords as $kw) {
        $parts[]  = '(p.name LIKE ? OR p.description LIKE ?)';
        $params[] = "%$kw%";
        $params[] = "%$kw%";
    }
    $cond = implode(' OR ', $parts);

    $stmt = $pdo->prepare("
        SELECT p.id, p.name AS product_name, p.image_url AS product_image,
               p.price_ils, p.group_price_ils, p.category, p.min_participants,
               ROUND((p.price_ils - p.group_price_ils) / NULLIF(p.price_ils,0) * 100)
                   AS discount_percent,
               b.business_name
        FROM   products  p
        JOIN   businesses b ON b.id = p.business_id AND b.status = 'active'
        WHERE  p.status = 'active' AND ($cond)
        ORDER  BY p.created_at DESC
        LIMIT  4
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // If keyword search returned nothing, fall back to category-only (so user isn't
    // left with an empty response when they search a valid category like "electronics")
    if (empty($rows) && $cat) {
        $stmt2 = $pdo->prepare("
            SELECT p.id, p.name AS product_name, p.image_url AS product_image,
                   p.price_ils, p.group_price_ils, p.category, p.min_participants,
                   ROUND((p.price_ils - p.group_price_ils) / NULLIF(p.price_ils,0) * 100)
                       AS discount_percent,
                   b.business_name
            FROM   products  p
            JOIN   businesses b ON b.id = p.business_id AND b.status = 'active'
            WHERE  p.status = 'active' AND p.category = ?
            ORDER  BY p.created_at DESC
            LIMIT  4
        ");
        $stmt2->execute([$cat]);
        $rows = $stmt2->fetchAll();
    }

    return $rows;
}

/**
 * Build a plain-text summary of DB results to inject into the Groq prompt.
 * This is the only data Groq is allowed to describe — preventing hallucination.
 */
function agent_build_context(array $groups, array $products): string {
    $lines = [];

    if (!empty($groups)) {
        $lines[] = 'OPEN GROUP PURCHASES FOUND (' . count($groups) . '):';
        foreach ($groups as $g) {
            $spots   = $g['target_participants'] - $g['current_participants'];
            $lines[] = "  • {$g['product_name']} by {$g['business_name']}: "
                . "₪{$g['group_price_ils']} (save {$g['discount_percent']}%), "
                . "{$g['current_participants']}/{$g['target_participants']} members, "
                . "{$spots} spot(s) left, {$g['countdown']}";
        }
    }

    if (!empty($products)) {
        $lines[] = !empty($groups)
            ? 'ALSO IN CATALOG (user can start a new group):'
            : 'NO OPEN GROUPS. PRODUCTS IN CATALOG (user can start a group):';
        foreach ($products as $p) {
            $lines[] = "  • {$p['product_name']} by {$p['business_name']}: "
                . "group price ₪{$p['group_price_ils']} (save {$p['discount_percent']}%), "
                . "min {$p['min_participants']} members needed";
        }
    }

    return empty($lines)
        ? 'No matching products or open groups found in the database.'
        : implode("\n", $lines);
}

/**
 * Load top open groups for profile/greeting mode (no keyword search).
 * Scored by: user preferences, discount, fill rate, urgency, location.
 * Returns up to 4 results with category diversity cap (max 2 per category).
 */
function agent_profile_groups(PDO $pdo, ?float $lat, ?float $lng, array $user_cats = []): array {
    $stmt = $pdo->prepare("
        SELECT gp.id AS group_id, gp.product_id,
               gp.current_participants, gp.target_participants,
               gp.deadline, gp.city,
               gp.lat AS group_lat, gp.lng AS group_lng,
               p.name AS product_name, p.image_url AS product_image,
               p.price_ils, p.group_price_ils,
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

    return array_slice($diverse, 0, 4);
}

/**
 * Template-based fallback used when Groq is unavailable.
 */
function agent_fallback_reply(array $groups, array $products, array $keywords, bool $profile_mode = false): string {
    if ($profile_mode && !empty($groups)) {
        $n = count($groups);
        return "Hi! Here are **{$n} featured group deal" . ($n > 1 ? 's' : '') . "** you might like. 🛍️ "
            . "Join one below to unlock the group discount — or tell me what you're looking for!";
    }
    if (!empty($groups)) {
        $n = count($groups);
        return "I found **{$n} open group" . ($n > 1 ? 's' : '') . "** matching your search! 🎉 "
            . "Pick one below to join and unlock the group discount.";
    }
    if (!empty($products)) {
        $n = count($products);
        return "No open groups yet, but I found **{$n} matching product" . ($n > 1 ? 's' : '') . "** in our catalog. 💡 "
            . "Click **\"Start a Group\"** on a card below to be the first member!";
    }
    if (!empty($keywords)) {
        return "I couldn't find any products or open groups matching **\"" . implode(' ', $keywords) . "\"** in SmartCart right now. "
            . "Try a different search term, or browse all available deals.";
    }
    return "Tell me what product you're looking for and I'll search all open group purchases for you! 🛍️";
}
