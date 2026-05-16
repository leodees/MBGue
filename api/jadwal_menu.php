<?php
/**
 * Jadwal menu Sen–Jum (untuk feedback setelah quiz & dikelola petugas)
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/bootstrap_session.php';

function ensure_jadwal_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS jadwal_menu_mbg (
            id INT AUTO_INCREMENT PRIMARY KEY,
            minggu_mulai DATE NOT NULL COMMENT 'Hari Senin',
            senin VARCHAR(240) NOT NULL DEFAULT '',
            selasa VARCHAR(240) NOT NULL DEFAULT '',
            rabu VARCHAR(240) NOT NULL DEFAULT '',
            kamis VARCHAR(240) NOT NULL DEFAULT '',
            jumat VARCHAR(240) NOT NULL DEFAULT '',
            pesan_petugas VARCHAR(500) DEFAULT '',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_minggu (minggu_mulai)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
}

ensure_jadwal_table($pdo);

/** Senin ISO untuk tanggal tertentu */
function senin_minggu_ini(?string $tanggal = null): string
{
    $ts = $tanggal ? strtotime($tanggal . ' 12:00:00') : time();
    $d = date('w', $ts);
    $delta = $d == 0 ? -6 : 1 - (int) $d;
    return date('Y-m-d', strtotime("$delta days", $ts));
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = siap_current_user($pdo);
    if (!$user) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'pesan' => 'Silakan masuk']);
        exit;
    }

    $minggu_mulai = senin_minggu_ini();
    $stmt = $pdo->prepare(
        'SELECT * FROM jadwal_menu_mbg WHERE minggu_mulai = ? LIMIT 1'
    );
    $stmt->execute([$minggu_mulai]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode([
            'status' => 'ok',
            'minggu_mulai' => $minggu_mulai,
            'hari' => [
                'Senin' => '(Belum diisi petugas)',
                'Selasa' => '(Belum diisi petugas)',
                'Rabu' => '(Belum diisi petugas)',
                'Kamis' => '(Belum diisi petugas)',
                'Jumat' => '(Belum diisi petugas)',
            ],
            'pesan_petugas' =>
                'Petugas MBG akan segera melengkapi menu minggu ini.',
        ]);
        exit;
    }

    echo json_encode([
        'status' => 'ok',
        'minggu_mulai' => $row['minggu_mulai'],
        'hari' => [
            'Senin' => $row['senin'],
            'Selasa' => $row['selasa'],
            'Rabu' => $row['rabu'],
            'Kamis' => $row['kamis'],
            'Jumat' => $row['jumat'],
        ],
        'pesan_petugas' => $row['pesan_petugas'] ?? '',
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

siap_require_petugas($pdo);

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$minggu_mulai = trim($body['minggu_mulai'] ?? senin_minggu_ini());

$sanitize = static fn ($v) => mb_substr(trim((string) $v), 0, 240);

$pdo->prepare(
    'INSERT INTO jadwal_menu_mbg (minggu_mulai, senin, selasa, rabu, kamis, jumat, pesan_petugas)
     VALUES (?,?,?,?,?,?,?)
     ON DUPLICATE KEY UPDATE
       senin = VALUES(senin),
       selasa = VALUES(selasa),
       rabu = VALUES(rabu),
       kamis = VALUES(kamis),
       jumat = VALUES(jumat),
       pesan_petugas = VALUES(pesan_petugas)'
)->execute([
    $minggu_mulai,
    $sanitize($body['senin'] ?? ''),
    $sanitize($body['selasa'] ?? ''),
    $sanitize($body['rabu'] ?? ''),
    $sanitize($body['kamis'] ?? ''),
    $sanitize($body['jumat'] ?? ''),
    mb_substr(trim((string) ($body['pesan_petugas'] ?? '')), 0, 500),
]);

echo json_encode(['status' => 'ok', 'pesan' => 'Jadwal menu disimpan']);
