<?php
// SmartCart — Shared Utility Functions

/**
 * Haversine formula: calculates great-circle distance between two GPS points.
 * Returns distance in kilometers.
 */
function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $R = 6371.0; // Earth's radius in km
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/**
 * Format an ILS price: ₪1,499
 */
function format_ils(float $amount): string {
    return '₪' . number_format($amount, 0, '.', ',');
}

/**
 * Calculate discount percent between original and group price.
 */
function discount_percent(float $original, float $group): int {
    if ($original <= 0) return 0;
    return (int)round(($original - $group) / $original * 100);
}

/**
 * Return days remaining until a deadline datetime string.
 * Returns 0 if deadline has passed.
 */
function days_until(string $deadline): int {
    $diff = strtotime($deadline) - time();
    return max(0, (int)ceil($diff / 86400));
}

/**
 * Return hours remaining until a deadline datetime string.
 */
function hours_until(string $deadline): int {
    $diff = strtotime($deadline) - time();
    return max(0, (int)ceil($diff / 3600));
}

/**
 * Get a user-friendly countdown string. Always uses the SMALLEST applicable unit
 * (so 12h shows "12h left", not "1 day"; 90 min shows "1h 30m left").
 */
function countdown_label(string $deadline): string {
    $diff = strtotime($deadline) - time();
    if ($diff <= 0) return 'Expired';
    if ($diff < 3600) {
        $m = (int)floor($diff / 60);
        return $m . ' min left';
    }
    if ($diff < 86400) {
        $h = (int)floor($diff / 3600);
        $m = (int)floor(($diff % 3600) / 60);
        return $m > 0 && $h < 6 ? "{$h}h {$m}m left" : "{$h}h left";
    }
    $d = (int)floor($diff / 86400);
    $h = (int)floor(($diff % 86400) / 3600);
    return $h > 0 && $d < 3 ? "{$d}d {$h}h left" : ($d === 1 ? '1 day left' : "{$d} days left");
}

/**
 * Category icons for UI display.
 */
function category_icon(string $category): string {
    $icons = [
        'electronics' => '💻',
        'home'        => '🏠',
        'fashion'     => '👗',
        'food'        => '🍎',
        'sports'      => '⚽',
        'beauty'      => '💄',
        'toys'        => '🧸',
        'books'       => '📚',
        'automotive'  => '🚗',
        'other'       => '📦',
    ];
    return $icons[$category] ?? '📦';
}

/**
 * Send JSON response and exit.
 */
function json_response(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Get JSON request body as associative array.
 */
function get_json_body(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    return json_decode($raw, true) ?? [];
}

/**
 * Require authentication — returns user_id or sends 401.
 */
function require_auth(): int {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['user_id'])) {
        json_response(['error' => 'Authentication required'], 401);
    }
    return (int)$_SESSION['user_id'];
}

/**
 * Geocode a free-text address (or city) to lat/lng via Nominatim.
 * Pass a full address like "Dizengoff 13, Tel Aviv" for street-level precision,
 * or just a city name for city-level precision.
 * Returns ['lat' => float, 'lng' => float, 'display' => string] or null on failure.
 */
function geocode_address(string $query): ?array {
    $query = trim($query);
    if ($query === '') return null;
    $q   = urlencode($query . ', Israel');
    $url = "https://nominatim.openstreetmap.org/search?q={$q}&format=json&limit=1&addressdetails=1&countrycodes=il";
    $ctx = stream_context_create(['http' => [
        'header'  => "User-Agent: SmartCart/1.0 (academic project)\r\n",
        'timeout' => 5,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    if (!$resp) return null;
    $data = json_decode($resp, true);
    if (empty($data[0])) return null;
    return [
        'lat'     => (float)$data[0]['lat'],
        'lng'     => (float)$data[0]['lon'],
        'display' => $data[0]['display_name'] ?? $query,
    ];
}

/**
 * Safe image URL helper.
 * If the stored value is already an absolute URL (starts with http/https//)
 * return it as-is. Otherwise prepend APP_URL so relative paths still work.
 * Always HTML-encodes the result ready for use in src= or url() attributes.
 */
function img_url(?string $url): string {
    if (!$url) return '';
    $abs = str_starts_with($url, 'http://') ||
           str_starts_with($url, 'https://') ||
           str_starts_with($url, '//');
    return htmlspecialchars($abs ? $url : APP_URL . $url, ENT_QUOTES, 'UTF-8');
}

/**
 * Back-compat alias: city-level geocode.
 */
function geocode_city(string $city): ?array {
    $r = geocode_address($city);
    if (!$r) return null;
    return ['lat' => $r['lat'], 'lng' => $r['lng']];
}
