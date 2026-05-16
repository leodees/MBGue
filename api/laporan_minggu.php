<?php
/**
 * SIAP-MBG — Rekap mingguan untuk ekspor Excel (detail harian + ringkasan)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../config/db.php';

$mulai = $_GET['mulai'] ?? '';
$selesai = $_GET['selesai'] ?? '';

if (!$mulai || !$selesai) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'pesan' => 'Parameter mulai dan selesai (YYYY-MM-DD) wajib']);
    exit;
}

/* Detail pengambilan mingguan */
$stmt = $pdo->prepare(
    'SELECT DATE(p.created_at) AS tanggal,
            TIME(p.created_at) AS jam,
            p.kelas, p.jenjang, p.jumlah AS porsi_ambil, p.waktu_ambil, p.catatan
     FROM pengambilan p
     WHERE DATE(p.created_at) BETWEEN :d1 AND :d2
     ORDER BY p.created_at ASC'
);
$stmt->execute([':d1' => $mulai, ':d2' => $selesai]);
$detailAmbil = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Pengembalian di minggu yang sama */
$stmt2 = $pdo->prepare(
    'SELECT DATE(r.created_at) AS tanggal,
            r.kelas, r.jumlah_kembali, r.kondisi, r.persen_kembali,
            p.jenjang
     FROM pengembalian r
     JOIN pengambilan p ON p.id = r.id_pengambilan
     WHERE DATE(r.created_at) BETWEEN :d1 AND :d2
     ORDER BY r.created_at ASC'
);
$stmt2->execute([':d1' => $mulai, ':d2' => $selesai]);
$detailKembali = $stmt2->fetchAll(PDO::FETCH_ASSOC);

$totalPorsi = array_sum(array_column($detailAmbil, 'porsi_ambil'));
$totalKelasAmbil = count($detailAmbil);
$totalOmprengKembali = array_sum(array_column($detailKembali, 'jumlah_kembali'));
$persen = $totalPorsi > 0 ? round(($totalOmprengKembali / $totalPorsi) * 100, 1) : 0;

echo json_encode([
    'status' => 'ok',
    'periode' => ['mulai' => $mulai, 'selesai' => $selesai],
    'ringkasan' => [
        'total_porsi_distribusi' => (int) $totalPorsi,
        'catatan_kelas_ambil' => $totalKelasAmbil,
        'total_ompreng_kembali' => (int) $totalOmprengKembali,
        'persen_kembali_vs_ambil' => $persen,
    ],
    'detail_pengambilan' => $detailAmbil,
    'detail_pengembalian' => $detailKembali,
]);
