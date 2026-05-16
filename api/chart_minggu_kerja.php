<?php
/**
 * Grafik Sen–Jum: pengambilan ditumpuk per tingkat & jurusan (10–12 + PPLG, TKJ, …)
 * + ringkasan harian ambil vs kembali (porsi kembali mengikuti skala pengambilan)
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';

/** Senin minggu ISO untuk tanggal referensi */
function iso_week_dates(?string $ref = null): array
{
    $ts = $ref ? strtotime($ref . ' 12:00:00') : time();
    $n = (int) date('N', $ts);
    $monTs = strtotime('-' . ($n - 1) . ' days', $ts);
    $dates = [];
    $labels = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'];
    for ($i = 0; $i < 5; $i++) {
        $dates[] = date('Y-m-d', strtotime("+{$i} days", $monTs));
    }

    return [$dates, $labels];
}

/**
 * Contoh: "SMK 10-PPLG 1", "SMK 11-TKJ 2" → "10 PPLG", "11 TKJ"
 */
function normalisasi_tingkat_jurusan(string $kelas): string
{
    $k = trim($kelas);
    if ($k === '') {
        return '—';
    }
    if (preg_match('/^(?:SMK|smk)\s+(\d{1,2})\s*-\s*(.+)$/u', $k, $m)) {
        $tingkat = (int) $m[1];
        $rest = trim($m[2]);
        $jur = $rest;
        if (preg_match('/^([A-Za-z]{2,12})/u', $rest, $jm)) {
            $jur = strtoupper($jm[1]);
        }

        return $tingkat . ' ' . $jur;
    }

    return $k;
}

/**
 * Gabungkan baris dengan tanggal + kelas sama setelah normalisasi (beberapa rombel → satu jurusan per hari).
 *
 * @param array<int,array{d:string,kelas:string,tot:int}> $rows
 * @return array<int,array{d:string,kelas:string,tot:int}>
 */
function merge_rows_by_day_kelas(array $rows): array
{
    $acc = [];
    foreach ($rows as $r) {
        $key = $r['d'] . "\0" . $r['kelas'];
        if (!isset($acc[$key])) {
            $acc[$key] = ['d' => $r['d'], 'kelas' => $r['kelas'], 'tot' => $r['tot']];
        } else {
            $acc[$key]['tot'] += $r['tot'];
        }
    }

    return array_values($acc);
}

/**
 * @param array<int,string> $dates
 * @param array<int,array{d:string,kelas:string,tot:int}> $rows
 * @return list<array{label:string,data:list<int>}>
 */
function pivot_kelas_stacked(array $dates, array $rows, int $topN = 16): array
{
    $kelasTotals = [];
    foreach ($rows as $r) {
        $k = $r['kelas'];
        $kelasTotals[$k] = ($kelasTotals[$k] ?? 0) + $r['tot'];
    }
    arsort($kelasTotals);
    $top = array_slice(array_keys($kelasTotals), 0, $topN);

    $matrix = [];
    foreach ($top as $k) {
        $matrix[$k] = array_fill(0, 5, 0);
    }
    $lain = array_fill(0, 5, 0);

    foreach ($rows as $r) {
        $idx = array_search($r['d'], $dates, true);
        if ($idx === false) {
            continue;
        }
        $kelas = $r['kelas'];
        $v = $r['tot'];
        if (in_array($kelas, $top, true)) {
            $matrix[$kelas][$idx] += $v;
        } else {
            $lain[$idx] += $v;
        }
    }

    $out = [];
    foreach ($matrix as $label => $data) {
        $out[] = ['label' => $label, 'data' => $data];
    }
    if (array_sum($lain) > 0) {
        $out[] = ['label' => 'Lainnya', 'data' => $lain];
    }
    if (!$out) {
        $out[] = ['label' => 'Belum ada data', 'data' => array_fill(0, 5, 0)];
    }

    return $out;
}

/**
 * Urut: kelas 10 → 12, lalu nama jurusan.
 *
 * @param list<array{label:string,data:list<int>}> $datasets
 * @return list<array{label:string,data:list<int>}>
 */
function sort_stack_datasets(array $datasets): array
{
    usort($datasets, function ($a, $b) {
        $la = $a['label'];
        $lb = $b['label'];
        if (preg_match('/^(\d+)\s+(.+)$/u', $la, $ma) && preg_match('/^(\d+)\s+(.+)$/u', $lb, $mb)) {
            $c = (int) $ma[1] <=> (int) $mb[1];
            if ($c !== 0) {
                return $c;
            }

            return strcasecmp($ma[2], $mb[2]);
        }

        return strcasecmp($la, $lb);
    });

    return $datasets;
}

[$dates, $hariLabels] = iso_week_dates();
$d0 = $dates[0];
$d4 = $dates[4];

$stmtA = $pdo->prepare(
    'SELECT DATE(created_at) AS d, kelas, SUM(jumlah) AS tot
     FROM pengambilan
     WHERE DATE(created_at) BETWEEN :d0 AND :d4
     GROUP BY DATE(created_at), kelas'
);
$stmtA->execute([':d0' => $d0, ':d4' => $d4]);

$rowsAmbil = [];
foreach ($stmtA->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $rowsAmbil[] = [
        'd' => $r['d'],
        'kelas' => normalisasi_tingkat_jurusan((string) $r['kelas']),
        'tot' => (int) $r['tot'],
    ];
}
$rowsAmbil = merge_rows_by_day_kelas($rowsAmbil);

$stmtK = $pdo->prepare(
    'SELECT DATE(r.created_at) AS d, r.kelas, SUM(r.jumlah_kembali) AS tot
     FROM pengembalian r
     WHERE DATE(r.created_at) BETWEEN :d0 AND :d4
     GROUP BY DATE(r.created_at), r.kelas'
);
$stmtK->execute([':d0' => $d0, ':d4' => $d4]);

$rowsKembali = [];
foreach ($stmtK->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $rowsKembali[] = [
        'd' => $r['d'],
        'kelas' => normalisasi_tingkat_jurusan((string) $r['kelas']),
        'tot' => (int) $r['tot'],
    ];
}
$rowsKembali = merge_rows_by_day_kelas($rowsKembali);

$xLabels = [];
foreach ($dates as $i => $ymd) {
    $parts = explode('-', $ymd);
    $xLabels[] = $hariLabels[$i] . ' ' . $parts[2] . '/' . $parts[1];
}

$palette = [
    '#0ea5e9',
    '#22d3ee',
    '#818cf8',
    '#34d399',
    '#fbbf24',
    '#f472b6',
    '#a78bfa',
    '#fb923c',
    '#4ade80',
    '#2dd4bf',
    '#94a3b8',
    '#64748b',
    '#38bdf8',
    '#c084fc',
    '#f87171',
    '#fcd34d',
    '#5eead4',
];

$datasetsAmbil = sort_stack_datasets(pivot_kelas_stacked($dates, $rowsAmbil, 16));

$i = 0;
foreach ($datasetsAmbil as &$ds) {
    $ds['backgroundColor'] = $palette[$i % count($palette)];
    $i++;
}
unset($ds);

$hari_ringkas = [];
foreach ($dates as $ymd) {
    $sumA = 0;
    foreach ($rowsAmbil as $r) {
        if ($r['d'] === $ymd) {
            $sumA += $r['tot'];
        }
    }
    $sumK = 0;
    foreach ($rowsKembali as $r) {
        if ($r['d'] === $ymd) {
            $sumK += $r['tot'];
        }
    }
    $hari_ringkas[] = [
        'total_ambil' => $sumA,
        'total_kembali' => $sumK,
        'persen_kembali' => $sumA > 0 ? round(($sumK / $sumA) * 100, 1) : 0.0,
    ];
}

echo json_encode([
    'status' => 'ok',
    'minggu_mulai' => $d0,
    'minggu_selesai' => $d4,
    'labels' => $xLabels,
    'pengambilan_stack' => $datasetsAmbil,
    'hari_ringkas' => $hari_ringkas,
], JSON_UNESCAPED_UNICODE);
