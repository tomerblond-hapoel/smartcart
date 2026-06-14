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
