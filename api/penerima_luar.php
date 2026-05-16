<?php
/**
 * SIAP-MBG — api/penerima_luar.php
 * Endpoint: POST/GET /api/penerima_luar.php
 * Kelola penerima MBG luar sekolah: ibu hamil, balita, menyusui, lansia
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

/* ── GET: daftar penerima ───────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $kategori = $_GET['kategori'] ?? null;

    $sql = "SELECT * FROM penerima_luar";
    $params = [];
    if ($kategori) {
        $sql    .= " WHERE kategori = :kategori";
        $params[':kategori'] = $kategori;
    }
    $sql .= " ORDER BY created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Kelompokkan per kategori
    $grouped = [];
    foreach ($rows as $r) {
        $grouped[$r['kategori']][] = $r;
    }

    echo json_encode([
        'status' => 'ok',
        'total'  => count($rows),
        'data'   => $rows,
        'per_kategori' => [
            'hamil'    => count($grouped['hamil']    ?? []),
            'menyusui' => count($grouped['menyusui'] ?? []),
            'balita'   => count($grouped['balita']   ?? []),
            'lansia'   => count($grouped['lansia']   ?? []),
        ],
    ]);
    exit;
}

/* ── POST: daftarkan penerima baru ──────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body     = json_decode(file_get_contents('php://input'), true);

    $nama     = trim($body['nama']     ?? '');
    $nik      = trim($body['nik']      ?? '');
    $usia     = trim($body['usia']     ?? '');
    $kategori = trim($body['kategori'] ?? 'hamil');
    $syarat   = intval($body['syarat'] ?? 0);

    /* --- Validasi --- */
    if (!$nama || !$nik) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'pesan' => 'Nama dan NIK wajib diisi']);
        exit;
    }

    $kategoriValid = ['hamil', 'menyusui', 'balita', 'lansia'];
    if (!in_array($kategori, $kategoriValid)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'pesan' => 'Kategori tidak valid']);
        exit;
    }

    // Minimal 4 dari 5 syarat
    if ($syarat < 4) {
        http_response_code(422);
        echo json_encode([
            'status' => 'error',
            'pesan'  => 'Minimal 4 syarat MBG harus terpenuhi',
            'syarat_terpenuhi' => $syarat,
        ]);
        exit;
    }

    // Cek NIK duplikat
    $cekNIK = $pdo->prepare("SELECT id FROM penerima_luar WHERE nik = :nik LIMIT 1");
    $cekNIK->execute([':nik' => $nik]);
    if ($cekNIK->fetch()) {
        http_response_code(409);
        echo json_encode(['status' => 'error', 'pesan' => 'NIK sudah terdaftar sebelumnya']);
        exit;
    }

    /* --- Insert --- */
    $stmt = $pdo->prepare("
        INSERT INTO penerima_luar
            (nama, nik, usia_info, kategori, syarat_terpenuhi, created_at)
        VALUES
            (:nama, :nik, :usia, :kategori, :syarat, NOW())
    ");
    $stmt->execute([
        ':nama'     => $nama,
        ':nik'      => $nik,
        ':usia'     => $usia,
        ':kategori' => $kategori,
        ':syarat'   => $syarat,
    ]);

    echo json_encode([
        'status' => 'ok',
        'id'     => $pdo->lastInsertId(),
        'pesan'  => "{$nama} berhasil didaftarkan sebagai penerima MBG ({$kategori})",
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['status' => 'error', 'pesan' => 'Method tidak diizinkan']);
