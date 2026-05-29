<?php
/**
 * SIAP-MBG — api/rekap_clear.php
 * Hapus riwayat rekap pengambilan dan pengembalian berdasarkan tanggal.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'pesan' => 'Method tidak diizinkan']);
    exit;
}

require_once '../config/db.php';

$body = json_decode(file_get_contents('php://input'), true);
$tanggal = trim($body['tanggal'] ?? '');

if (!$tanggal || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'pesan' => 'Tanggal tidak valid atau tidak dikirim']);
    exit;
}

try {
    $pdo->beginTransaction();

    $photoFiles = [];
    $stmt = $pdo->prepare("SELECT foto FROM pengembalian WHERE DATE(created_at) = :tanggal");
    $stmt->execute([':tanggal' => $tanggal]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!empty($row['foto'])) $photoFiles[] = $row['foto'];
    }

    $stmt = $pdo->prepare("SELECT foto FROM pengambilan WHERE DATE(created_at) = :tanggal");
    $stmt->execute([':tanggal' => $tanggal]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!empty($row['foto'])) $photoFiles[] = $row['foto'];
    }

    $stmtDeleteRet = $pdo->prepare("DELETE FROM pengembalian WHERE DATE(created_at) = :tanggal");
    $stmtDeleteRet->execute([':tanggal' => $tanggal]);
    $deletedRet = $stmtDeleteRet->rowCount();

    $stmtDeleteAmbil = $pdo->prepare("DELETE FROM pengambilan WHERE DATE(created_at) = :tanggal");
    $stmtDeleteAmbil->execute([':tanggal' => $tanggal]);
    $deletedAmbil = $stmtDeleteAmbil->rowCount();

    $pdo->commit();

    $uploadDir = __DIR__ . '/../uploads/foto/';
    foreach (array_unique($photoFiles) as $fileName) {
        $filePath = realpath($uploadDir . $fileName);
        if ($filePath && strpos($filePath, realpath($uploadDir)) === 0 && file_exists($filePath)) {
            @unlink($filePath);
        }
    }

    echo json_encode([
        'status' => 'ok',
        'pesan' => "Riwayat rekap tanggal {$tanggal} telah dibersihkan.",
        'deleted_pengambilan' => $deletedAmbil,
        'deleted_pengembalian' => $deletedRet,
    ]);
    exit;
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['status' => 'error', 'pesan' => 'Gagal membersihkan riwayat: ' . $e->getMessage()]);
    exit;
}
