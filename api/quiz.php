<?php
/**
 * Quiz tebak menu MBG — bundle soal & kirim jawaban
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/bootstrap_session.php';
require_once __DIR__ . '/quiz_schema.php';

ensure_quiz_tables($pdo);
$user = siap_require_login($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'bundle';
    if ($action !== 'bundle') {
        echo json_encode(['status' => 'error', 'pesan' => 'Parameter tidak valid']);
        exit;
    }

    $rows = $pdo->query(
        'SELECT id, nama_menu, petunjuk FROM quiz_soal ORDER BY RAND() LIMIT 5'
    )->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) < 5) {
        echo json_encode([
            'status' => 'error',
            'pesan' => 'Soal quiz belum cukup. Minta petugas MBG menambah menu quiz.',
        ]);
        exit;
    }

    $allNames = $pdo->query('SELECT nama_menu FROM quiz_soal')->fetchAll(PDO::FETCH_COLUMN);
    $bundle = [];
    $answers = [];

    foreach ($rows as $row) {
        $correct = $row['nama_menu'];
        $wrongPool = array_values(
            array_filter($allNames, static fn ($n) => strcasecmp((string) $n, $correct) !== 0),
        );
        shuffle($wrongPool);
        $wrongPick = array_slice($wrongPool, 0, 3);
        while (count($wrongPick) < 3) {
            $wrongPick[] = 'Lauk lain #' . random_int(1, 99);
        }
        $opts = array_merge([$correct], array_slice($wrongPick, 0, 3));
        shuffle($opts);
        $bundle[] = [
            'id' => (int) $row['id'],
            'petunjuk' => $row['petunjuk'],
            'opsi' => $opts,
        ];
        $answers[$row['id']] = $correct;
    }

    $_SESSION['quiz_jawaban'] = $answers;
    $_SESSION['quiz_started_at'] = time();

    echo json_encode(['status' => 'ok', 'bundle' => $bundle]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'pesan' => 'Method tidak diizinkan']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? '';

if ($action !== 'submit') {
    echo json_encode(['status' => 'error', 'pesan' => 'Aksi tidak dikenal']);
    exit;
}

$expected = $_SESSION['quiz_jawaban'] ?? null;
if (!$expected || !is_array($expected)) {
    echo json_encode([
        'status' => 'error',
        'pesan' => 'Sesi quiz habis. Mulai lagi dari menu Quiz.',
    ]);
    exit;
}

$jawaban = $body['jawaban'] ?? [];
if (!is_array($jawaban)) {
    echo json_encode(['status' => 'error', 'pesan' => 'Format jawaban tidak valid']);
    exit;
}

$answerMap = [];
foreach ($jawaban as $j) {
    $id = isset($j['id']) ? (string) $j['id'] : '';
    if ($id !== '') {
        $answerMap[$id] = trim((string) ($j['pilihan'] ?? ''));
    }
}

$missing = 0;
$benar = 0;
$total = count($expected);
foreach ($expected as $id => $namaBenar) {
    $idStr = (string) $id;
    if (!array_key_exists($idStr, $answerMap) || $answerMap[$idStr] === '') {
        $missing++;
        continue;
    }
    if (strcasecmp($answerMap[$idStr], (string) $namaBenar) === 0) {
        $benar++;
    }
}

if ($missing > 0) {
    echo json_encode(['status' => 'error', 'pesan' => 'Harap jawab semua soal sebelum mengirim.']);
    exit;
}

unset($_SESSION['quiz_jawaban'], $_SESSION['quiz_started_at']);

$today = date('Y-m-d');
$pdo->prepare(
    'REPLACE INTO quiz_selesai (user_id, tanggal, benar, total_soal) VALUES (?,?,?,?)'
)->execute([(int) $user['id'], $today, $benar, $total]);

echo json_encode([
    'status' => 'ok',
    'pesan' => $benar === $total
        ? 'Luar biasa! Semua benar — lihat jadwal menu minggu ini dari petugas MBG.'
        : 'Terima kasih sudah bermain! Tetap semangat — berikut jadwal menu minggu ini.',
    'benar' => $benar,
    'total' => $total,
]);
