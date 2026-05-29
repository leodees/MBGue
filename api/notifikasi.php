<?php
/**
 * SIAP-MBG — api/notifikasi.php
 * Endpoint notifikasi untuk pengguna dan peran.
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/bootstrap_session.php';

$pdo->exec("CREATE TABLE IF NOT EXISTS notifikasi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    role ENUM('perwakilan_kelas','petugas_mbg','dapur_sppg') NULL,
    jenis ENUM('masukan_baru','masukan_feedback') NOT NULL,
    isi TEXT NOT NULL,
    target_type VARCHAR(50) NULL,
    target_id INT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notifikasi_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = siap_require_login($pdo);
    $role = $user['role'] ?? '';

    $stmt = $pdo->prepare(
        'SELECT * FROM notifikasi
         WHERE (user_id = :uid) OR (role = :role)
         ORDER BY created_at DESC LIMIT 200'
    );
    $stmt->execute([
        ':uid' => $user['id'],
        ':role' => $role,
    ]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['status' => 'ok', 'data' => $rows]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = siap_require_login($pdo);
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $body['action'] ?? '';

    if ($action === 'mark_all_read') {
        $stmt = $pdo->prepare(
            'UPDATE notifikasi SET is_read = 1
             WHERE (user_id = :uid) OR (role = :role)'
        );
        $stmt->execute([
            ':uid' => $user['id'],
            ':role' => $user['role'] ?? '',
        ]);
        echo json_encode(['status' => 'ok', 'pesan' => 'Notifikasi ditandai sudah dibaca']);
        exit;
    }

    if ($action === 'delete' && isset($body['id'])) {
        $notifId = (int) $body['id'];
        $stmt = $pdo->prepare(
            'DELETE FROM notifikasi WHERE id = ? AND ((user_id = ?) OR (role = ?))'
        );
        $stmt->execute([$notifId, $user['id'], $user['role'] ?? '']);
        echo json_encode(['status' => 'ok', 'pesan' => 'Notifikasi dihapus']);
        exit;
    }
}

http_response_code(405);
echo json_encode(['status' => 'error', 'pesan' => 'Method tidak diizinkan']);
