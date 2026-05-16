<?php
/**
 * Sesi + helpers untuk endpoint yang membutuhkan user login / petugas MBG
 */
require_once __DIR__ . '/../config/session_bootstrap.php';

function siap_current_user(PDO $pdo): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $st = $pdo->prepare(
        'SELECT id, email, nama, role, kelas_info FROM users WHERE id = ? LIMIT 1'
    );
    $st->execute([(int) $_SESSION['user_id']]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function siap_require_login(PDO $pdo): array
{
    $u = siap_current_user($pdo);
    if (!$u) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'pesan' => 'Silakan masuk terlebih dahulu']);
        exit;
    }
    return $u;
}

function siap_require_petugas(PDO $pdo): array
{
    $u = siap_require_login($pdo);
    if (($u['role'] ?? '') !== 'petugas_mbg') {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'pesan' => 'Hanya petugas MBG yang dapat mengakses fitur ini']);
        exit;
    }
    return $u;
}
