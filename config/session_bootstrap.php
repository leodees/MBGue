<?php
/**
 * Sesi PHP — parameter cookie eksplisit agar login tetap valid setelah refresh (HTTP/HTTPS).
 */
if (session_status() === PHP_SESSION_ACTIVE) {
    return;
}

$https =
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])
        && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',  // Empty domain untuk host-only cookies
    'secure'   => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();

