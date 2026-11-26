<?php
/**
 * Language Switcher Endpoint
 * Persists selected language in session and cookie, then redirects safely back.
 */

require_once __DIR__ . '/../config/db.php';
configureSecureSession();

$supported = ['en', 'mr', 'hi'];
$lang = trim($_REQUEST['lang'] ?? 'en');
if (!in_array($lang, $supported, true)) {
    $lang = 'en';
}

$_SESSION['lang'] = $lang;
setcookie('hp_lang', $lang, time() + 86400 * 30, '/', '', false, true);

$redirect = $_REQUEST['redirect'] ?? '';
$target = BASE_URL . '/';

if (!empty($redirect)) {
    $parsed = parse_url($redirect);
    if ($parsed !== false && isset($parsed['path'])) {
        // Restrict redirect to local application paths only (prevent open redirects)
        $baseParsed = parse_url(BASE_URL);
        $sameHost = !isset($parsed['host']) || (isset($baseParsed['host']) && strcasecmp($parsed['host'], $baseParsed['host']) === 0);
        if ($sameHost) {
            $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';
            $target = $parsed['path'] . $query;
        }
    }
}

header('Location: ' . $target);
exit;
