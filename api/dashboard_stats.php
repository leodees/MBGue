<?php
/**
 * SIAP-MBG — Statistik untuk grafik dashboard (tren harian & agregat jenjang)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../config/db.php';

$today = date('Y-m-d');
$start = $_GET['dari'] ?? date('Y-m-d', strtotime('-6 days'));
$end = $_GET['sampai'] ?? $today;

/* Pengambilan per hari di rentang */
$stmt = $pdo->prepare(
    'SELECT DATE(created_at) AS tanggal, SUM(jumlah) AS total_porsi, COUNT(*) AS jumlah_kelas
     FROM pengambilan
     WHERE DATE(created_at) BETWEEN :d1 AND :d2
     GROUP BY DATE(created_at)
     ORDER BY tanggal ASC'
);
$stmt->execute([':d1' => $start, ':d2' => $end]);
$ambilPerHari = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Pengembalian per hari */
$stmt2 = $pdo->prepare(
    'SELECT DATE(r.created_at) AS tanggal, SUM(r.jumlah_kembali) AS total_kembali
     FROM pengembalian r
     WHERE DATE(r.created_at) BETWEEN :d1 AND :d2
     GROUP BY DATE(r.created_at)
     ORDER BY tanggal ASC'
);
$stmt2->execute([':d1' => $start, ':d2' => $end]);
$kembaliPerHari = $stmt2->fetchAll(PDO::FETCH_ASSOC);

$mapKembali = [];
foreach ($kembaliPerHari as $r) {
    $mapKembali[$r['tanggal']] = (int) $r['total_kembali'];
}

$trend = [];
foreach ($ambilPerHari as $r) {
    $tgl = $r['tanggal'];
    $trend[] = [
        'tanggal' => $tgl,
        'porsi_ambil' => (int) $r['total_porsi'],
        'kelas_ambil' => (int) $r['jumlah_kelas'],
        'porsi_kembali' => $mapKembali[$tgl] ?? 0,
    ];
}

/* Hari yang hanya ada pengembalian */
foreach ($mapKembali as $tgl => $tk) {
    $found = false;
    foreach ($trend as $row) {
        if ($row['tanggal'] === $tgl) {
            $found = true;
            break;
        }
    }
    if (!$found) {
        $trend[] = [
            'tanggal' => $tgl,
            'porsi_ambil' => 0,
            'kelas_ambil' => 0,
            'porsi_kembali' => $tk,
        ];
    }
}
usort($trend, static fn ($a, $b) => strcmp($a['tanggal'], $b['tanggal']));

/* Jenjang hari ini */
$stmt3 = $pdo->prepare(
    'SELECT jenjang, SUM(jumlah) AS total FROM pengambilan WHERE DATE(created_at) = :t GROUP BY jenjang'
);
$stmt3->execute([':t' => $today]);
$jenjangHariIni = [];
foreach ($stmt3->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $jenjangHariIni[$row['jenjang']] = (int) $row['total'];
}

echo json_encode([
    'status' => 'ok',
    'periode' => compact('start', 'end'),
    'trend_harian' => $trend,
    'jenjang_hari_ini' => $jenjangHariIni,
]);
