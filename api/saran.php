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
        status ENUM('baru','approved','rejected','diproses') NOT NULL DEFAULT 'baru',
        respon_dapur TEXT NULL,
        respon_dapur_id INT NULL,
        respon_dapur_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_created (created_at),
        CONSTRAINT fk_saran_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS notifikasi (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        role ENUM('perwakilan_kelas','petugas_mbg','dapur_sppg') NULL,
        jenis ENUM('masukan_baru','masukan_feedback') NOT NULL,
        isi TEXT NOT NULL,
        target_type VARCHAR(50) NULL,
        target_id INT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = siap_require_login($pdo);
    $role = $user['role'] ?? '';

    if ($role === 'dapur_sppg') {
        $rows = $pdo->query(
            'SELECT s.*, u.nama AS nama_pengguna, u.role AS pengirim_role, u.kelas_info
             FROM saran_kritik s
             JOIN users u ON u.id = s.user_id
             ORDER BY s.created_at DESC LIMIT 200'
        )->fetchAll(PDO::FETCH_ASSOC);
    } elseif (in_array($role, ['perwakilan_kelas', 'petugas_mbg'], true)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'pesan' => 'Daftar masukan hanya dapat dilihat oleh Dapur SPPG.']);
        exit;
    } else {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'pesan' => 'Hanya pengguna terdaftar yang dapat melihat masukan.']);
        exit;
    }

    echo json_encode(['status' => 'ok', 'data' => $rows]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$user = siap_require_login($pdo);
$body = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? '';

if ($action === 'clear_history') {
    if (($user['role'] ?? '') !== 'dapur_sppg') {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'pesan' => 'Hanya Dapur SPPG yang dapat menghapus riwayat.']);
        exit;
    }
    $pdo->exec('DELETE FROM saran_kritik');
    echo json_encode(['status' => 'ok', 'pesan' => 'Riwayat masukan berhasil dihapus.']);
    exit;
}

if ($action === 'feedback') {
    if (($user['role'] ?? '') !== 'dapur_sppg') {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'pesan' => 'Hanya Dapur SPPG yang dapat menanggapi masukan.']);
        exit;
    }

    $id = intval($body['id'] ?? 0);
    $status = $body['status'] ?? 'approved';
    if (!in_array($status, ['approved', 'rejected', 'diproses'], true)) {
        $status = 'diproses';
    }
    $respon = trim((string) ($body['respon'] ?? '')) ?: null;

    $stmt = $pdo->prepare(
        'SELECT user_id, jenis, isi FROM saran_kritik WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'pesan' => 'Masukan tidak ditemukan']);
        exit;
    }

    $update = $pdo->prepare(
        'UPDATE saran_kritik
         SET status = :status, respon_dapur = :respon, respon_dapur_id = :respon_id, respon_dapur_at = NOW()
         WHERE id = :id'
    );
    $update->execute([
        ':status' => $status,
        ':respon' => $respon,
        ':respon_id' => $user['id'],
        ':id' => $id,
    ]);

    $statusLabel = $status === 'approved' ? 'disetujui' : ($status === 'rejected' ? 'ditolak' : 'sedang diproses');
    $notifText = "Masukan Anda telah {$statusLabel}.";
    if ($respon) {
        $notifText .= " Alasan/pesan: {$respon}";
    }

    $stmtNotif = $pdo->prepare(
        'INSERT INTO notifikasi (user_id, jenis, isi, target_type, target_id) VALUES (?,?,?,?,?)'
    );
    $stmtNotif->execute([(int) $row['user_id'], 'masukan_feedback', $notifText, 'saran_kritik', $id]);

    echo json_encode(['status' => 'ok', 'pesan' => 'Feedback berhasil disimpan']);
    exit;
}

if (!in_array($user['role'] ?? '', ['perwakilan_kelas', 'petugas_mbg'], true)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'pesan' => 'Hanya perwakilan kelas dan petugas MBG yang dapat mengirim masukan.']);
    exit;
}

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
$insertId = (int) $pdo->lastInsertId();
$jenisLabel = $jenis === 'kritik' ? 'Kritik' : 'Saran';
$notifText = "Masukan baru dari {$user['nama']} ({$jenisLabel}).";
$stmtNotif = $pdo->prepare(
    'INSERT INTO notifikasi (role, jenis, isi, target_type, target_id) VALUES (?,?,?,?,?)'
);
$stmtNotif->execute(['dapur_sppg', 'masukan_baru', $notifText, 'saran_kritik', $insertId]);

echo json_encode(['status' => 'ok', 'pesan' => 'Terima kasih — masukan Anda tercatat']);
