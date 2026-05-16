<?php
/**
 * SIAP-MBG — api/pengembalian.php
 * Endpoint: POST /api/pengembalian.php
 * Menerima data pengembalian ompreng MBG
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../config/db.php';

/* ── GET ────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $tanggal = $_GET['tanggal'] ?? date('Y-m-d');

    $stmt = $pdo->prepare("
        SELECT r.*,
               p.jumlah AS jumlah_diambil,
               p.jenjang AS jenjang,
               p.kelas  AS kelas_ambil,
               ROUND(r.jumlah_kembali / p.jumlah * 100, 1) AS persen_kembali
        FROM pengembalian r
        JOIN pengambilan p ON r.id_pengambilan = p.id
        WHERE DATE(r.created_at) = :tanggal
        ORDER BY r.created_at DESC
    ");
    $stmt->execute([':tanggal' => $tanggal]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Hitung rata-rata pengembalian (Matematika)
    $totalDiambil = 0;
    $totalKembali = 0;
    foreach ($rows as $r) {
        $totalDiambil += $r['jumlah_diambil'];
        $totalKembali += $r['jumlah_kembali'];
    }
    $rataKembali = $totalDiambil > 0
        ? round($totalKembali / $totalDiambil * 100, 1)
        : 0;

    echo json_encode([
        'status'         => 'ok',
        'tanggal'        => $tanggal,
        'data'           => $rows,
        'total_kembali'  => $totalKembali,
        'total_diambil'  => $totalDiambil,
        'rata_persen'    => $rataKembali,
        'formula'        => "{$totalKembali} / {$totalDiambil} × 100% = {$rataKembali}%",
    ]);
    exit;
}

/* ── POST ───────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);

    $idPengambilan = intval($body['idPengambilan'] ?? 0);
    $kelas         = trim($body['kelas']          ?? '');
    $ompreng       = intval($body['ompreng']       ?? 0);
    $kondisi       = trim($body['kondisi']         ?? 'baik');
    $fotoB64       = $body['foto']                 ?? null;

    $kondisiValid = ['baik', 'kotor', 'rusak'];
    if (!in_array($kondisi, $kondisiValid)) $kondisi = 'baik';

    if ($ompreng < 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'pesan' => 'Jumlah ompreng tidak valid']);
        exit;
    }

    /* --- Ambil data pengambilan untuk hitung % --- */
    $stmtCek = $pdo->prepare("SELECT jumlah FROM pengambilan WHERE id = :id");
    $stmtCek->execute([':id' => $idPengambilan]);
    $pengambilan = $stmtCek->fetch(PDO::FETCH_ASSOC);

    $jumlahDiambil  = $pengambilan ? intval($pengambilan['jumlah']) : 1;
    $persenKembali  = round($ompreng / max(1, $jumlahDiambil) * 100, 1);

    /* --- Simpan foto pengembalian --- */
    $namaFoto = null;
    if ($fotoB64 && preg_match('/^data:image\/(jpeg|jpg|png|webp);base64,/', $fotoB64, $m)) {
        $ext      = ($m[1] === 'jpg') ? 'jpeg' : $m[1];
        $dataOnly = preg_replace('/^data:image\/[a-z]+;base64,/', '', $fotoB64);
        $decoded  = base64_decode($dataOnly);
        if ($decoded !== false) {
            $namaFoto  = 'ret_' . date('Ymd_His') . '_' . uniqid() . '.' . $ext;
            $uploadDir = __DIR__ . '/../uploads/foto/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            file_put_contents($uploadDir . $namaFoto, $decoded);
        }
    }

    /* --- Insert pengembalian --- */
    $stmt = $pdo->prepare("
        INSERT INTO pengembalian
            (id_pengambilan, kelas, jumlah_kembali, kondisi, foto, persen_kembali, created_at)
        VALUES
            (:idp, :kelas, :jml, :kondisi, :foto, :persen, NOW())
    ");
    $stmt->execute([
        ':idp'     => $idPengambilan,
        ':kelas'   => $kelas,
        ':jml'     => $ompreng,
        ':kondisi' => $kondisi,
        ':foto'    => $namaFoto,
        ':persen'  => $persenKembali,
    ]);

    /* --- Update status di tabel pengambilan --- */
    if ($idPengambilan > 0) {
        $pdo->prepare("UPDATE pengambilan SET status_kembali = 1 WHERE id = :id")
            ->execute([':id' => $idPengambilan]);
    }

    echo json_encode([
        'status'         => 'ok',
        'id'             => $pdo->lastInsertId(),
        'pesan'          => "Pengembalian {$kelas} berhasil dicatat",
        'persen_kembali' => $persenKembali,
        'formula'        => "{$ompreng} / {$jumlahDiambil} × 100% = {$persenKembali}%",
        'foto'           => $namaFoto,
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['status' => 'error', 'pesan' => 'Method tidak diizinkan']);
