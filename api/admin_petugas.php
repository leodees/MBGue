<?php
/**
 * Panel petugas MBG: kelola pengguna & soal quiz
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/bootstrap_session.php';
require_once __DIR__ . '/quiz_schema.php';

ensure_quiz_tables($pdo);
$user = siap_require_petugas($pdo);

function admin_json_fail(string $pesan, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['status' => 'error', 'pesan' => $pesan]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'users';
    if ($action === 'users') {
        $rows = $pdo->query(
            'SELECT id, email, nama, role, kelas_info, created_at FROM users ORDER BY id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'ok', 'data' => $rows]);
        exit;
    }
    if ($action === 'quiz') {
        $rows = $pdo->query(
            'SELECT id, nama_menu, petunjuk, created_at FROM quiz_soal ORDER BY id DESC'
        )->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'ok', 'data' => $rows]);
        exit;
    }
    admin_json_fail('Parameter tidak valid');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? '';

if ($action === 'user_delete') {
    $id = (int) ($body['id'] ?? 0);
    if ($id <= 0 || $id === (int) $user['id']) {
        admin_json_fail('Tidak dapat menghapus akun ini');
    }
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    echo json_encode(['status' => 'ok', 'pesan' => 'Pengguna dihapus']);
    exit;
}

if ($action === 'quiz_add') {
    $nama = trim((string) ($body['nama_menu'] ?? ''));
    $petunjuk = trim((string) ($body['petunjuk'] ?? ''));
    if (strlen($nama) < 2 || strlen($petunjuk) < 5) {
        admin_json_fail('Nama menu & petunjuk wajib diisi dengan benar');
    }
    $pdo->prepare(
        'INSERT INTO quiz_soal (nama_menu, petunjuk) VALUES (?,?)'
    )->execute([$nama, $petunjuk]);
    echo json_encode([
        'status' => 'ok',
        'pesan' => 'Soal ditambahkan',
        'id' => (int) $pdo->lastInsertId(),
    ]);
    exit;
}

if ($action === 'quiz_delete') {
    $id = (int) ($body['id'] ?? 0);
    if ($id <= 0) {
        admin_json_fail('ID tidak valid');
    }
    $pdo->prepare('DELETE FROM quiz_soal WHERE id = ?')->execute([$id]);
    echo json_encode(['status' => 'ok', 'pesan' => 'Soal dihapus']);
    exit;
}

admin_json_fail('Aksi tidak dikenal');
