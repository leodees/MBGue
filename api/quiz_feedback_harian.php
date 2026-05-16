<?php
/**
 * Feedback quiz khusus per hari kerja (Sen–Jum), diisi petugas MBG
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/bootstrap_session.php';

$pdo->exec("
    CREATE TABLE IF NOT EXISTS quiz_feedback_harian (
        hari ENUM('senin','selasa','rabu','kamis','jumat') NOT NULL PRIMARY KEY,
        menu_hari VARCHAR(280) NOT NULL DEFAULT '',
        pesan_feedback TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/** PHP date('N'): 1=Senin … 7=Minggu */
function hari_kerja_key(): ?string
{
    $n = (int) date('N');
    $map = [
        1 => 'senin',
        2 => 'selasa',
        3 => 'rabu',
        4 => 'kamis',
        5 => 'jumat',
    ];
    return $map[$n] ?? null;
}

function label_hari_id(string $key): string
{
    $u = [
        'senin' => 'Senin',
        'selasa' => 'Selasa',
        'rabu' => 'Rabu',
        'kamis' => 'Kamis',
        'jumat' => 'Jumat',
    ];
    return $u[$key] ?? $key;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    siap_require_login($pdo);
    $key = $_GET['hari'] ?? 'auto';
    if ($key === 'auto') {
        $key = hari_kerja_key();
    }
    if (!$key) {
        echo json_encode([
            'status' => 'ok',
            'hari_key' => null,
            'hari_label' => 'Akhir pekan',
            'menu_hari' => '',
            'pesan_feedback' =>
                'Quiz & feedback khusus hari Senin–Jumat. Sampai jumpa di hari sekolah berikutnya!',
        ]);
        exit;
    }

    $stmt = $pdo->prepare(
        'SELECT menu_hari, pesan_feedback FROM quiz_feedback_harian WHERE hari = ? LIMIT 1'
    );
    $stmt->execute([$key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'ok',
        'hari_key' => $key,
        'hari_label' => label_hari_id($key),
        'menu_hari' => $row['menu_hari'] ?? '',
        'pesan_feedback' => $row['pesan_feedback'] ?? '',
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

siap_require_petugas($pdo);

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? '';

if ($action === 'save_batch') {
    $items = $body['items'] ?? [];
    if (!is_array($items)) {
        echo json_encode(['status' => 'error', 'pesan' => 'Format tidak valid']);
        exit;
    }
    $allowed = ['senin', 'selasa', 'rabu', 'kamis', 'jumat'];
    $stmt = $pdo->prepare(
        'INSERT INTO quiz_feedback_harian (hari, menu_hari, pesan_feedback)
         VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE menu_hari = VALUES(menu_hari), pesan_feedback = VALUES(pesan_feedback)'
    );
    foreach ($items as $it) {
        $h = strtolower(trim((string) ($it['hari'] ?? '')));
        if (!in_array($h, $allowed, true)) {
            continue;
        }
        $menu = mb_substr(trim((string) ($it['menu_hari'] ?? '')), 0, 280);
        $pesan = trim((string) ($it['pesan_feedback'] ?? ''));
        $stmt->execute([$h, $menu, $pesan]);
    }
    echo json_encode(['status' => 'ok', 'pesan' => 'Feedback harian disimpan']);
    exit;
}

if ($action === 'list_all') {
    $rows = $pdo->query(
        'SELECT hari, menu_hari, pesan_feedback FROM quiz_feedback_harian ORDER BY FIELD(hari,\'senin\',\'selasa\',\'rabu\',\'kamis\',\'jumat\')'
    )->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['status' => 'ok', 'data' => $rows]);
    exit;
}

echo json_encode(['status' => 'error', 'pesan' => 'Aksi tidak dikenal']);
