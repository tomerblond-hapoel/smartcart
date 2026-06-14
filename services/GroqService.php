<?php
// Groq LLM service — extracts product keywords from Hebrew/English natural-language queries.
// Used by api/agent.php to enhance the rule-based scoring engine with LLM understanding.

/**
 * Send a raw search query to Groq (Llama 3.3-70b) and extract structured keywords.
 *
 * @return array{keywords: string[], category: string|null}|null  null on error / timeout
 */
function groq_extract_query(string $raw_query): ?array {
    $api_key = defined('GROQ_API_KEY') ? GROQ_API_KEY : '';
    if ($api_key === '') return null;

    $system = <<<'SYS'
You are a product keyword extractor for an Israeli group-buying platform called SmartCart.
The user may write in Hebrew or English.

Given a search query, return ONLY a JSON object with:
  "keywords": array of 1-5 lowercase English product words (translate from Hebrew when needed)
  "category": one of electronics, home, fashion, food, sports, beauty, toys, books, automotive — or null

Rules:
- Translate Hebrew product names to English (e.g. "אוזניות" → "headphones", "כיסא" → "chair")
- Remove filler words, quantities, adjectives like "good" / "cheap"
- Only include concrete product terms
- NEVER split brand names or model names into parts: "iphone" stays "iphone" (not "iphone"+"phone"), "airpods" stays "airpods" (not "air"+"pod"), "macbook" stays "macbook"
- If the user types a specific model (e.g. "iphone 15"), include the brand and model as separate keywords: ["iphone","15"]
- Output ONLY the JSON object, no explanation
SYS;

    $payload = json_encode([
        'model'           => 'llama-3.3-70b-versatile',
        'messages'        => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $raw_query],
        ],
        'max_tokens'      => 80,
        'temperature'     => 0.1,
        'response_format' => ['type' => 'json_object'],
    ]);

    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
        ],
    ]);

    $body = curl_exec($ch);
    $err  = curl_errno($ch);
    curl_close($ch);

    if ($err || !$body) return null;

    $resp = json_decode($body, true);
    $text = $resp['choices'][0]['message']['content'] ?? '';
    if (!$text) return null;

    $parsed = json_decode($text, true);
    if (!is_array($parsed)) return null;

    $valid_cats = ['electronics','home','fashion','food','sports','beauty','toys','books','automotive'];

    $kws = array_values(array_filter(
        array_map('strtolower', (array)($parsed['keywords'] ?? [])),
        fn($k) => strlen($k) >= 2 && is_string($k)
    ));

    $cat = $parsed['category'] ?? null;
    if (!in_array($cat, $valid_cats, true)) $cat = null;

    return ['keywords' => $kws, 'category' => $cat];
}

/**
 * Generative semantic agent: receives the full list of all open groups and catalog products,
 * lets Groq pick which ones are relevant, and generates a natural English response.
 *
 * @param  string $message      Current user message (any language)
 * @param  array  $history      Previous turns
 * @param  array  $all_groups   All open groups (pre-scored, with group_id keys)
 * @param  array  $all_products All catalog products (with id keys)
 * @param  string $user_name    First name
 * @param  bool   $is_greeting  True when no search intent detected
 * @return array{intent:string, message:string|null, group_ids:int[], product_ids:int[]}
 */
function groq_chat_semantic(
    string $message,
    array  $history,
    array  $all_groups,
    array  $all_products,
    string $user_name,
    bool   $is_greeting = false
): array {
    $api_key = defined('GROQ_API_KEY') ? GROQ_API_KEY : '';
    if ($api_key === '') return ['intent' => 'other', 'message' => null, 'group_ids' => [], 'product_ids' => []];

    // Format groups concisely for Groq context
    $groups_text = '';
    foreach ($all_groups as $g) {
        $spots = $g['target_participants'] - $g['current_participants'];
        $groups_text .= "[GROUP:{$g['group_id']}] {$g['product_name']} ({$g['category']}) by {$g['business_name']}"
            . " — ₪{$g['group_price_ils']} group price, save {$g['discount_percent']}%"
            . ", {$g['current_participants']}/{$g['target_participants']} members, {$spots} spot(s) left, {$g['countdown']}\n";
    }

    // Format catalog products
    $products_text = '';
    foreach ($all_products as $p) {
        // Skip products that already have an open group (avoid redundancy)
        $has_group = false;
        foreach ($all_groups as $g) {
            if ((int)$g['product_id'] === (int)$p['id']) { $has_group = true; break; }
        }
        if ($has_group) continue;
        $products_text .= "[PRODUCT:{$p['id']}] {$p['product_name']} ({$p['category']}) by {$p['business_name']}"
            . " — group price ₪{$p['group_price_ils']}, save {$p['discount_percent']}%"
            . ", min {$p['min_participants']} members needed\n";
    }

    $greeting_hint = $is_greeting
        ? "The user sent a greeting. Welcome them warmly and highlight 2-3 of the featured group deals from AVAILABLE_GROUPS.\n"
        : '';

    $system =
        "You are SmartCart AI Shopping Assistant — helping users find and join group deals on an Israeli group-buying platform.\n"
        . "SmartCart lets people pool together to unlock bulk discounts on products from local businesses.\n\n"
        . $greeting_hint
        . "RULES:\n"
        . "1. ALWAYS respond in English, regardless of the language the user writes in.\n"
        . "2. ONLY discuss SmartCart products, groups, discounts, payments. For unrelated topics set intent='off_topic'.\n"
        . "3. Use SEMANTIC understanding — 'phone' = smartphone (not headphone), 'watch' = smartwatch, 'tablet' = iPad/tablet.\n"
        . "4. Select ONLY genuinely matching items. If the user wants a phone, do NOT select headphones or speakers.\n"
        . "5. NEVER invent products. Only reference IDs from AVAILABLE_GROUPS and CATALOG_PRODUCTS below.\n"
        . "6. Be friendly and concise (2–3 sentences). Use emojis.\n"
        . "7. If the user searched but nothing matches, say so and suggest searching differently.\n"
        . "8. Prefer returning group_ids (joinable deals) over product_ids (catalog only).\n\n"
        . "User's name: {$user_name}\n\n"
        . "Return ONLY valid JSON — no markdown, no explanation:\n"
        . '{"intent":"search|greeting|create|off_topic|other","message":"...","group_ids":[1,2],"product_ids":[3]}' . "\n\n"
        . "INTENT VALUES: search=product search, greeting=hi/hello, create=user wants to start a group, off_topic=unrelated, other=general SmartCart question\n\n"
        . "AVAILABLE_GROUPS (open group purchases the user can join):\n"
        . ($groups_text ?: "None currently open.\n") . "\n"
        . "CATALOG_PRODUCTS (no open group yet — user can start one):\n"
        . ($products_text ?: "None available.\n");

    $msgs = [['role' => 'system', 'content' => $system]];
    foreach ($history as $h) {
        if (!isset($h['role'], $h['content'])) continue;
        $role = in_array($h['role'], ['user', 'assistant'], true) ? $h['role'] : 'user';
        $msgs[] = ['role' => $role, 'content' => mb_substr((string)$h['content'], 0, 400)];
    }
    $msgs[] = ['role' => 'user', 'content' => $message];

    $payload = json_encode([
        'model'           => 'llama-3.3-70b-versatile',
        'messages'        => $msgs,
        'max_tokens'      => 320,
        'temperature'     => 0.3,
        'response_format' => ['type' => 'json_object'],
    ]);

    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
        ],
    ]);

    $body  = curl_exec($ch);
    $errno = curl_errno($ch);
    curl_close($ch);

    if ($errno || !$body) return ['intent' => 'other', 'message' => null, 'group_ids' => [], 'product_ids' => []];

    $resp = json_decode($body, true);
    $text = $resp['choices'][0]['message']['content'] ?? '';
    if (!$text) return ['intent' => 'other', 'message' => null, 'group_ids' => [], 'product_ids' => []];

    $parsed = json_decode($text, true);
    if (!is_array($parsed)) return ['intent' => 'other', 'message' => null, 'group_ids' => [], 'product_ids' => []];

    $valid = ['search', 'greeting', 'create', 'off_topic', 'other'];
    return [
        'intent'      => in_array($parsed['intent'] ?? '', $valid, true) ? $parsed['intent'] : 'other',
        'message'     => isset($parsed['message']) ? (string)$parsed['message'] : null,
        'group_ids'   => array_map('intval', (array)($parsed['group_ids']   ?? [])),
        'product_ids' => array_map('intval', (array)($parsed['product_ids'] ?? [])),
    ];
}

/**
 * Generate a natural-language chat response from Groq, given the user message,
 * conversation history, and a pre-built summary of real DB results.
 *
 * The model is instructed to ONLY describe what is in $db_context — never invent.
 *
 * @param  string $message     Current user message
 * @param  array  $history     Previous turns: [{role:'user'|'assistant', content:'...'}]
 * @param  string $db_context  Plain-text summary of DB search results (groups + products)
 * @param  string $user_name   First name for personalised greeting
 * @return array{intent:string, message:string|null}
 */
function groq_chat_response(string $message, array $history, string $db_context, string $user_name): array {
    $api_key = defined('GROQ_API_KEY') ? GROQ_API_KEY : '';
    if ($api_key === '') return ['intent' => 'other', 'message' => null];

    $system =
        "You are SmartCart AI Shopping Assistant — a friendly helper for an Israeli group-buying platform.\n"
        . "SmartCart lets users join group purchases together to unlock discounts on products from local businesses.\n\n"
        . "STRICT RULES:\n"
        . "1. ONLY discuss: SmartCart products, group purchases, joining groups, creating groups, discounts, and payments. Nothing else.\n"
        . "2. If the user asks about ANYTHING UNRELATED (weather, sports, coding, recipes, politics, math, etc.), "
        .    "respond ONLY with: \"I'm here to help with SmartCart shopping and group purchases. What product are you looking for? 🛍️\" "
        .    "and set intent to \"off_topic\".\n"
        . "3. NEVER invent products, prices, businesses, or groups. Describe ONLY what appears in [SEARCH_RESULTS].\n"
        . "4. Always respond in English, regardless of the language the user writes in.\n"
        . "5. Be friendly and concise (2–3 sentences max). Use relevant emojis.\n"
        . "6. If open groups are shown, mention the user can click 'Join Group' on the cards below.\n"
        . "7. If only products (no groups) are shown, tell the user they can click 'Start a Group' on the cards below.\n"
        . "8. On greeting (hi, hello, shalom, מה נשמע, etc.): welcome the user warmly and highlight the featured deals shown in [SEARCH_RESULTS]. Do NOT say 'nothing was found'.\n"
        . "9. Only say 'nothing found' when the user searched for a specific product AND [SEARCH_RESULTS] explicitly says no results were found.\n"
        . "User's name: {$user_name}\n\n"
        . "Return ONLY valid JSON (no markdown, no explanation):\n"
        . "{\"intent\": \"search|greeting|create|off_topic|other\", \"message\": \"your response text\"}\n\n"
        . "INTENT VALUES:\n"
        . "- search: user searched for a product or category\n"
        . "- greeting: user said hi or asked how SmartCart works\n"
        . "- create: user wants to start/create a new group\n"
        . "- off_topic: anything not related to SmartCart\n"
        . "- other: other SmartCart questions (payments, how groups work, etc.)";

    $msgs = [['role' => 'system', 'content' => $system]];

    foreach ($history as $h) {
        if (!isset($h['role'], $h['content'])) continue;
        $role = in_array($h['role'], ['user', 'assistant'], true) ? $h['role'] : 'user';
        $msgs[] = ['role' => $role, 'content' => mb_substr((string)$h['content'], 0, 600)];
    }

    $msgs[] = ['role' => 'user', 'content' => $message . "\n\n[SEARCH_RESULTS]\n" . $db_context];

    $payload = json_encode([
        'model'           => 'llama-3.3-70b-versatile',
        'messages'        => $msgs,
        'max_tokens'      => 220,
        'temperature'     => 0.4,
        'response_format' => ['type' => 'json_object'],
    ]);

    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
        ],
    ]);

    $body  = curl_exec($ch);
    $errno = curl_errno($ch);
    curl_close($ch);

    if ($errno || !$body) return ['intent' => 'other', 'message' => null];

    $resp   = json_decode($body, true);
    $text   = $resp['choices'][0]['message']['content'] ?? '';
    if (!$text) return ['intent' => 'other', 'message' => null];

    $parsed = json_decode($text, true);
    if (!is_array($parsed)) return ['intent' => 'other', 'message' => null];

    $valid_intents = ['search', 'greeting', 'create', 'off_topic', 'other'];
    $intent  = in_array($parsed['intent'] ?? '', $valid_intents, true) ? $parsed['intent'] : 'other';
    $msg_txt = isset($parsed['message']) ? (string)$parsed['message'] : null;

    return ['intent' => $intent, 'message' => $msg_txt];
}
