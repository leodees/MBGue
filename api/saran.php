<?php
/**
 * Saran & kritik menu MBG
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/bootstrap_session.php';

$pdo->exec("
    CREATE TABLE IF NOT EXISTS saran_kritik (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        jenis ENUM('saran_menu','kritik','lain') NOT NULL DEFAULT 'saran_menu',
        isi TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_created (created_at),
        CONSTRAINT fk_saran_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    siap_require_petugas($pdo);
    $rows = $pdo->query(
        'SELECT s.*, u.nama AS nama_pengguna, u.email
         FROM saran_kritik s
         JOIN users u ON u.id = s.user_id
         ORDER BY s.created_at DESC LIMIT 200'
    )->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['status' => 'ok', 'data' => $rows]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$user = siap_require_login($pdo);

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$jenis = $body['jenis'] ?? 'saran_menu';
if (!in_array($jenis, ['saran_menu', 'kritik', 'lain'], true)) {
    $jenis = 'saran_menu';
}
$isi = trim((string) ($body['isi'] ?? ''));
if (strlen($isi) < 5) {
    echo json_encode(['status' => 'error', 'pesan' => 'Isi minimal 5 karakter']);
    exit;
}

$stmt = $pdo->prepare(
    'INSERT INTO saran_kritik (user_id, jenis, isi) VALUES (?,?,?)'
);
$stmt->execute([(int) $user['id'], $jenis, $isi]);

echo json_encode(['status' => 'ok', 'pesan' => 'Terima kasih — masukan Anda tercatat']);
