<?php
/**
 * Autentikasi (sesi PHP), registrasi, login, logout
 */

require_once __DIR__ . '/../config/session_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/db.php';

function ensure_auth_tables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(190) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            nama VARCHAR(120) NOT NULL,
            role ENUM('perwakilan_kelas','petugas_mbg','dapur_sppg') NOT NULL,
            kelas_info VARCHAR(120) NULL COMMENT 'Misal nama kelas untuk perwakilan',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    $pdo->exec('DROP TABLE IF EXISTS user_riwayat');
}

ensure_auth_tables($pdo);

/* ── GET: profil ───────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'me';
    if ($action !== 'me') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'pesan' => 'Parameter tidak valid']);
        exit;
    }
    if (empty($_SESSION['user_id'])) {
        echo json_encode(['status' => 'guest', 'user' => null]);
        exit;
    }
    $uid = (int) $_SESSION['user_id'];
    $stmt = $pdo->prepare(
        'SELECT id, email, nama, role, kelas_info, created_at FROM users WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$uid]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        unset($_SESSION['user_id']);
        echo json_encode(['status' => 'guest', 'user' => null]);
        exit;
    }
    echo json_encode([
        'status' => 'ok',
        'user' => $user,
    ]);
    exit;
}

/* ── POST ─────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'pesan' => 'Method tidak diizinkan']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? '';

/* ----- REGISTER ----- */
if ($action === 'register') {
    $email = strtolower(trim($body['email'] ?? ''));
    $password = (string) ($body['password'] ?? '');
    $nama = trim($body['nama'] ?? '');
    $role = $body['role'] ?? '';
    $kelasInfo = trim($body['kelas_info'] ?? '');

    $rolesValid = ['perwakilan_kelas', 'petugas_mbg', 'dapur_sppg'];
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'pesan' => 'Email tidak valid']);
        exit;
    }
    if (strlen($password) < 8) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'pesan' => 'Kata sandi minimal 8 karakter']);
        exit;
    }
    if (strlen($nama) < 2) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'pesan' => 'Nama lengkap wajib diisi']);
        exit;
    }
    if (!in_array($role, $rolesValid, true)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'pesan' => 'Peran tidak valid']);
        exit;
    }
    if ($role === 'perwakilan_kelas' && strlen($kelasInfo) < 2) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'pesan' => 'Isi kelas/jurusan Anda sebagai perwakilan']);
        exit;
    }
    if ($role !== 'perwakilan_kelas') {
        $kelasInfo = null;
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO users (email, password_hash, nama, role, kelas_info) VALUES (?,?,?,?,?)'
        );
        $stmt->execute([$email, $hash, $nama, $role, $kelasInfo]);
        $newId = (int) $pdo->lastInsertId();
    } catch (PDOException $e) {
        error_log("Register DB error: " . $e->getMessage());
        if ((int) $e->getCode() === 23000) {
            http_response_code(409);
            echo json_encode(['status' => 'error', 'pesan' => 'Email sudah terdaftar']);
            exit;
        }
        http_response_code(500);
        echo json_encode(['status' => 'error', 'pesan' => 'Gagal menyimpan data: ' . $e->getMessage()]);
        exit;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = $newId;
    session_write_close();

    echo json_encode([
        'status' => 'ok',
        'pesan' => 'Akun berhasil dibuat',
        'user' => [
            'id' => $newId,
            'email' => $email,
            'nama' => $nama,
            'role' => $role,
            'kelas_info' => $kelasInfo,
        ],
    ]);
    exit;
}

/* ----- LOGIN ----- */
if ($action === 'login') {
    $email = strtolower(trim($body['email'] ?? ''));
    $password = (string) ($body['password'] ?? '');
    if (!$email || $password === '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'pesan' => 'Email dan kata sandi wajib diisi']);
        exit;
    }
    $stmt = $pdo->prepare(
        'SELECT id, email, password_hash, nama, role, kelas_info FROM users WHERE email = ? LIMIT 1'
    );
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !password_verify($password, $row['password_hash'])) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'pesan' => 'Email atau kata sandi salah']);
        exit;
    }
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $row['id'];
    session_write_close();

    unset($row['password_hash']);
    echo json_encode([
        'status' => 'ok',
        'pesan' => 'Selamat datang kembali',
        'user' => $row,
    ]);
    exit;
}

/* ----- LOGOUT ----- */
if ($action === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    echo json_encode(['status' => 'ok', 'pesan' => 'Anda telah keluar']);
    exit;
}

http_response_code(400);
echo json_encode(['status' => 'error', 'pesan' => 'Aksi tidak dikenal']);
