<?php
/**
 * SIAP-MBG — api/pengambilan.php
 * Endpoint: POST /api/pengambilan.php
 * Menerima data pengambilan MBG dari frontend
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

/* ── GET: ambil semua data pengambilan ─────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $tanggal = $_GET['tanggal'] ?? date('Y-m-d');

    $stmt = $pdo->prepare("
        SELECT p.*, 
               ROUND(p.jumlah * 0.5, 1) AS berat_kg,
               p.jumlah * 5000           AS estimasi_anggaran
        FROM pengambilan p
        WHERE DATE(p.created_at) = :tanggal
        ORDER BY p.created_at DESC
    ");
    $stmt->execute([':tanggal' => $tanggal]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status'  => 'ok',
        'tanggal' => $tanggal,
        'data'    => $rows,
        'total_porsi'    => array_sum(array_column($rows, 'jumlah')),
        'total_anggaran' => array_sum(array_column($rows, 'estimasi_anggaran')),
    ]);
    exit;
}

/* ── POST: simpan data pengambilan ─────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);

    /* --- Validasi input --- */
    $kelas   = trim($body['kelas']   ?? '');
    $jenjang = trim($body['jenjang'] ?? '');
    $jumlah  = intval($body['jumlah'] ?? 0);
    $waktu   = trim($body['waktu']   ?? date('H:i'));
    $catatan = trim($body['catatan'] ?? '');
    $fotoB64 = $body['foto']         ?? null;

    if (!$kelas || !$jenjang || $jumlah < 1) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'pesan' => 'Data tidak lengkap']);
        exit;
    }

    // Validasi jenjang (hanya SMK)
    $jenjangValid = ['SMK'];
    if (!in_array($jenjang, $jenjangValid)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'pesan' => 'Jenjang tidak valid']);
        exit;
    }

    /* --- Simpan foto --- */
    $namaFoto = null;
    if ($fotoB64 && preg_match('/^data:image\/(jpeg|jpg|png|webp);base64,/', $fotoB64, $m)) {
        $ext       = ($m[1] === 'jpg') ? 'jpeg' : $m[1];
        $dataOnly  = preg_replace('/^data:image\/[a-z]+;base64,/', '', $fotoB64);
        $decoded   = base64_decode($dataOnly);

        if ($decoded !== false) {
            $namaFoto  = 'foto_' . date('Ymd_His') . '_' . uniqid() . '.' . $ext;
            $uploadDir = __DIR__ . '/../uploads/foto/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            file_put_contents($uploadDir . $namaFoto, $decoded);
        }
    }

    /* --- Kalkulasi Matematika --- */
    $beratKg          = round($jumlah * 0.5, 1); // 0.5 kg per porsi
    $estimasiAnggaran = $jumlah * 5000;           // Rp 5.000 per porsi

    /* --- Insert ke database --- */
    $stmt = $pdo->prepare("
        INSERT INTO pengambilan
            (kelas, jenjang, jumlah, waktu_ambil, catatan, foto, 
             berat_kg, estimasi_anggaran, created_at)
        VALUES
            (:kelas, :jenjang, :jumlah, :waktu, :catatan, :foto,
             :berat, :anggaran, NOW())
    ");

    $stmt->execute([
        ':kelas'    => $kelas,
        ':jenjang'  => $jenjang,
        ':jumlah'   => $jumlah,
        ':waktu'    => $waktu,
        ':catatan'  => $catatan,
        ':foto'     => $namaFoto,
        ':berat'    => $beratKg,
        ':anggaran' => $estimasiAnggaran,
    ]);

    $insertId = $pdo->lastInsertId();

    echo json_encode([
        'status'           => 'ok',
        'id'               => $insertId,
        'pesan'            => "Pengambilan {$kelas} berhasil dicatat",
        'berat_kg'         => $beratKg,
        'estimasi_anggaran'=> $estimasiAnggaran,
        'foto'             => $namaFoto,
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['status' => 'error', 'pesan' => 'Method tidak diizinkan']);
