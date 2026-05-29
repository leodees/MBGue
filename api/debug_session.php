<?php
/**
 * Debug endpoint untuk melihat status session
 * Akses: /api/debug_session.php
 */

require_once __DIR__ . '/../config/session_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$response = [
    'timestamp' => date('Y-m-d H:i:s'),
    'http_host' => $_SERVER['HTTP_HOST'] ?? 'N/A',
    'server_name' => $_SERVER['SERVER_NAME'] ?? 'N/A',
    'server_port' => $_SERVER['SERVER_PORT'] ?? 'N/A',
    'request_uri' => $_SERVER['REQUEST_URI'] ?? 'N/A',
    'php_self' => $_SERVER['PHP_SELF'] ?? 'N/A',
    'session_id' => session_id(),
    'session_status' => session_status_text(session_status()),
    'session_name' => session_name(),
    'session_cookie_params' => session_get_cookie_params(),
    'session_data' => $_SESSION,
    'user_id_from_session' => $_SESSION['user_id'] ?? null,
    'cookies_received' => $_COOKIE,
];

// Jika ada POST, simpan user_id untuk testing
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    if (isset($body['set_user_id'])) {
        $_SESSION['user_id'] = (int) $body['set_user_id'];
        session_write_close();
        $response['action'] = 'Session user_id set to ' . $body['set_user_id'];
    }
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

function session_status_text($status) {
    return match($status) {
        PHP_SESSION_DISABLED => 'DISABLED',
        PHP_SESSION_NONE => 'NONE',
        PHP_SESSION_ACTIVE => 'ACTIVE',
        default => 'UNKNOWN'
    };
}
