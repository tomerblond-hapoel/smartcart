<?php
// SmartCart — Language Loader
// Include this at the top of every page (before any output).
// After including, use $t['key'] for all translated strings.
// The lang toggle sets cookie 'lang' to 'en' or 'he' and reloads the page.

// Handle language switch request
if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'he'])) {
    $new_lang = $_GET['lang'];
    setcookie('lang', $new_lang, time() + 60 * 60 * 24 * 365, '/');
    // Redirect without the lang param to keep URLs clean
    $url = strtok($_SERVER['REQUEST_URI'], '?');
    $params = $_GET;
    unset($params['lang']);
    if ($params) {
        $url .= '?' . http_build_query($params);
    }
    header('Location: ' . $url);
    exit;
}

// Detect current language
$lang = $_COOKIE['lang'] ?? 'en';
if (!in_array($lang, ['en', 'he'])) {
    $lang = 'en';
}

// Load translation array
$t = require __DIR__ . '/../lang/' . $lang . '.php';

// Helper: get translated string (returns key if not found)
function __t(string $key): string {
    global $t;
    return $t[$key] ?? $key;
}

// Set RTL direction for Hebrew
$dir  = ($lang === 'he') ? 'rtl' : 'ltr';
$lang_class = ($lang === 'he') ? 'rtl' : '';
$lang_current = $lang;
