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
 * Get a user-friendly countdown string.
 */
function countdown_label(string $deadline): string {
    $diff = strtotime($deadline) - time();
    if ($diff <= 0) return 'Expired';
    if ($diff < 3600)  return ceil($diff / 60) . ' min left';
    if ($diff < 86400) return ceil($diff / 3600) . ' hours left';
    return ceil($diff / 86400) . ' days left';
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
 * Geocode a city name to lat/lng via Google Maps Geocoding API.
 * Returns ['lat' => float, 'lng' => float] or null on failure.
 */
function geocode_city(string $city): ?array {
    if (!defined('GMAPS_API_KEY') || GMAPS_API_KEY === 'YOUR_GOOGLE_MAPS_API_KEY') {
        return null;
    }
    $query = urlencode($city . ', Israel');
    $url   = "https://maps.googleapis.com/maps/api/geocode/json?address={$query}&key=" . GMAPS_API_KEY;
    $resp  = @file_get_contents($url);
    if (!$resp) return null;
    $data  = json_decode($resp, true);
    if (($data['status'] ?? '') !== 'OK') return null;
    $loc = $data['results'][0]['geometry']['location'] ?? null;
    if (!$loc) return null;
    return ['lat' => (float)$loc['lat'], 'lng' => (float)$loc['lng']];
}
