<?php
require_once __DIR__ . '/config/session_bootstrap.php';

// ============================================================
// SIAP-MBG — Sistem Informasi Administrasi Program MBG
// Single-file PHP + HTML + CSS + JS
// ============================================================

// ---------- DATABASE CONFIG ----------
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '12345');
define('DB_NAME', 'siapp_mbg');

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
                DB_USER, DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                 PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
        } catch (PDOException $e) {
            // Jika DB belum ada, kembalikan null (mode demo)
            return null;
        }
    }
    return $pdo;
}

// ---------- AUTO INSTALL TABEL ----------
function installDB() {
    $db = getDB();
    if (!$db) return;
    $db->exec("
    CREATE TABLE IF NOT EXISTS kelas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nama VARCHAR(30) NOT NULL,
        jenjang ENUM('TK','SD','SMP','SMA','SMK') NOT NULL,
        jumlah_siswa INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE IF NOT EXISTS pengambilan (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kelas_id INT NOT NULL,
        tanggal DATE NOT NULL,
        jumlah_ambil INT NOT NULL,
        foto_path VARCHAR(255),
        petugas VARCHAR(100),
        catatan TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE IF NOT EXISTS pengembalian (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pengambilan_id INT NOT NULL,
        kelas_id INT NOT NULL,
        tanggal DATE NOT NULL,
        jumlah_kembali INT NOT NULL,
        kondisi ENUM('baik','kotor','rusak') DEFAULT 'baik',
        foto_path VARCHAR(255),
        catatan TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE IF NOT EXISTS penerima_luar (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nama VARCHAR(100) NOT NULL,
        nik VARCHAR(20),
        kategori ENUM('ibu_hamil','ibu_menyusui','balita','lansia') NOT NULL,
        usia_kandungan VARCHAR(50),
        status ENUM('aktif','nonaktif','pending') DEFAULT 'pending',
        dokumen_path VARCHAR(255),
        catatan TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
    INSERT IGNORE INTO kelas (id,nama,jenjang,jumlah_siswa) VALUES
        (1,'TK A','TK',20),(2,'TK B','TK',18),
        (3,'Kelas 1A','SD',28),(4,'Kelas 1B','SD',27),(5,'Kelas 2A','SD',30),(6,'Kelas 2B','SD',29),
        (7,'Kelas 3A','SD',31),(8,'Kelas 3B','SD',30),(9,'Kelas 4A','SD',32),(10,'Kelas 4B','SD',31),
        (11,'Kelas 5A','SD',32),(12,'Kelas 5B','SD',33),(13,'Kelas 6A','SD',30),(14,'Kelas 6B','SD',29),
        (15,'VII-A','SMP',32),(16,'VII-B','SMP',31),(17,'VII-C','SMP',33),
        (18,'VIII-A','SMP',32),(19,'VIII-B','SMP',30),(20,'VIII-C','SMP',31),
        (21,'IX-A','SMP',29),(22,'IX-B','SMP',30),(23,'IX-C','SMP',31),
        (24,'X-A','SMA',34),(25,'X-B','SMA',33),(26,'XI-A','SMA',32),(27,'XI-B','SMA',31),
        (28,'XII-A','SMA',30),(29,'XII-B','SMA',29),
        (30,'X-TKJ','SMK',34),(31,'XI-TKJ','SMK',32),(32,'XII-TKJ','SMK',30);
    ");
}

// ---------- TABEL PENGGUNA (LOGIN / REGISTER) ----------
function installAuthTables($db) {
    if (!$db) return;
    $db->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(190) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            nama VARCHAR(120) NOT NULL,
            role ENUM('perwakilan_kelas','petugas_mbg','dapur_sppg') NOT NULL,
            kelas_info VARCHAR(120) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    /* Log aktivitas dihapus — buang tabel lama jika masih ada */
    $db->exec('DROP TABLE IF EXISTS user_riwayat');
}

// ---------- UPLOAD DIREKTORI ----------
$uploadDir = __DIR__ . '/uploads/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

// ---------- MATH: Hitung Porsi ----------
function hitungTotal($pengambilan) {
    // MATEMATIKA: Σ porsi = jumlah semua kelas yang mengambil
    return array_sum(array_column($pengambilan, 'jumlah_ambil'));
}

function persenPengembalian($kembali, $ambil) {
    // MATEMATIKA: persentase = (kembali / ambil) × 100
    if ($ambil == 0) return 0;
    return round(($kembali / $ambil) * 100, 1);
}
// ============================================================
// AJAX API HANDLER
// ============================================================
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    $db = getDB();
    $api = $_GET['api'];

    // --- GET DAFTAR KELAS ---
    if ($api === 'kelas') {
        if (!$db) { echo json_encode(['data'=>getDemoKelas()]); exit; }
        $stmt = $db->query("SELECT * FROM kelas ORDER BY jenjang, nama");
        echo json_encode(['data' => $stmt->fetchAll()]);
        exit;
    }

    // --- GET DASHBOARD STATS ---
    if ($api === 'stats') {
        $today = date('Y-m-d');
        if (!$db) { echo json_encode(getDemoStats($today)); exit; }
        $totalAmbil = $db->query("SELECT COALESCE(SUM(jumlah_ambil),0) as t FROM pengambilan WHERE tanggal='$today'")->fetch()['t'];
        $kelasAmbil = $db->query("SELECT COUNT(*) as t FROM pengambilan WHERE tanggal='$today'")->fetch()['t'];
        $totalKelas = $db->query("SELECT COUNT(*) as t FROM kelas")->fetch()['t'];
        $omprengKembali = $db->query("SELECT COUNT(DISTINCT kelas_id) as t FROM pengembalian WHERE tanggal='$today'")->fetch()['t'];
        $ibuHamil = $db->query("SELECT COUNT(*) as t FROM penerima_luar WHERE status='aktif'")->fetch()['t'];
        $lastAktif = $db->query("SELECT p.*, k.nama as kelas_nama FROM pengambilan p JOIN kelas k ON p.kelas_id=k.id WHERE p.tanggal='$today' ORDER BY p.created_at DESC LIMIT 6")->fetchAll();
        echo json_encode(compact('totalAmbil','kelasAmbil','totalKelas','omprengKembali','ibuHamil','lastAktif'));
        exit;
    }

    // --- POST PENGAMBILAN ---
    if ($api === 'pengambilan' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $kelasId = (int)($_POST['kelas_id'] ?? 0);
        $jumlah  = (int)($_POST['jumlah_ambil'] ?? 0);
        $petugas = trim($_POST['petugas'] ?? '');
        $catatan = trim($_POST['catatan'] ?? '');
        $tanggal = date('Y-m-d');
        $fotoPath = '';

        // Proses foto (base64 dari canvas yang sudah distempel)
        if (!empty($_POST['foto_data'])) {
            $data = $_POST['foto_data'];
            $data = str_replace('data:image/jpeg;base64,', '', $data);
            $data = str_replace(' ', '+', $data);
            $fname = 'ambil_' . $kelasId . '_' . time() . '.jpg';
            file_put_contents($uploadDir . $fname, base64_decode($data));
            $fotoPath = 'uploads/' . $fname;
        }

        if (!$db) { echo json_encode(['ok'=>true,'demo'=>true,'foto'=>$fotoPath]); exit; }

        // Cek sudah ambil hari ini?
        $cek = $db->prepare("SELECT id FROM pengambilan WHERE kelas_id=? AND tanggal=?");
        $cek->execute([$kelasId, $tanggal]);
        if ($cek->fetch()) {
            echo json_encode(['ok'=>false,'msg'=>'Kelas ini sudah mengambil MBG hari ini.']);
            exit;
        }

        $stmt = $db->prepare("INSERT INTO pengambilan (kelas_id,tanggal,jumlah_ambil,foto_path,petugas,catatan) VALUES (?,?,?,?,?,?)");
        $stmt->execute([$kelasId, $tanggal, $jumlah, $fotoPath, $petugas, $catatan]);
        echo json_encode(['ok'=>true,'id'=>$db->lastInsertId(),'foto'=>$fotoPath]);
        exit;
    }

    // --- GET REKAP PENGAMBILAN ---
    if ($api === 'rekap') {
        $tanggal = $_GET['tanggal'] ?? date('Y-m-d');
        $jenjang = $_GET['jenjang'] ?? '';
        if (!$db) { echo json_encode(['data'=>getDemoRekap(), 'total'=>getDemoTotal()]); exit; }
        $where = "p.tanggal='$tanggal'";
        if ($jenjang) $where .= " AND k.jenjang='$jenjang'";
        $rows = $db->query("
            SELECT p.*, k.nama as kelas_nama, k.jenjang, k.jumlah_siswa,
                   COALESCE(r.jumlah_kembali,0) as kembali,
                   COALESCE(r.kondisi,'—') as kondisi,
                   r.foto_path as foto_kembali,
                   p.foto_path as foto_ambil
            FROM pengambilan p
            JOIN kelas k ON p.kelas_id=k.id
            LEFT JOIN pengembalian r ON r.pengambilan_id=p.id
            WHERE $where ORDER BY k.jenjang, k.nama
        ")->fetchAll();
        // MATEMATIKA: total dan rata-rata
        $totalAmbil = array_sum(array_column($rows,'jumlah_ambil'));
        $totalKembali = array_sum(array_column($rows,'kembali'));
        $persen = persenPengembalian($totalKembali, $totalAmbil);
        echo json_encode(['data'=>$rows,'total_ambil'=>$totalAmbil,'total_kembali'=>$totalKembali,'persen'=>$persen]);
        exit;
    }

    // --- POST PENGEMBALIAN ---
    if ($api === 'pengembalian' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $pengambilanId = (int)($_POST['pengambilan_id'] ?? 0);
        $kelasId   = (int)($_POST['kelas_id'] ?? 0);
        $jumlah    = (int)($_POST['jumlah_kembali'] ?? 0);
        $kondisi   = $_POST['kondisi'] ?? 'baik';
        $catatan   = trim($_POST['catatan'] ?? '');
        $tanggal   = date('Y-m-d');
        $fotoPath  = '';

        if (!empty($_POST['foto_data'])) {
            $data = str_replace(['data:image/jpeg;base64,', ' '], ['', '+'], $_POST['foto_data']);
            $fname = 'kembali_' . $kelasId . '_' . time() . '.jpg';
            file_put_contents($uploadDir . $fname, base64_decode($data));
            $fotoPath = 'uploads/' . $fname;
        }

        if (!$db) { echo json_encode(['ok'=>true,'demo'=>true]); exit; }
        $stmt = $db->prepare("INSERT INTO pengembalian (pengambilan_id,kelas_id,tanggal,jumlah_kembali,kondisi,foto_path,catatan) VALUES (?,?,?,?,?,?,?)");
        $stmt->execute([$pengambilanId,$kelasId,$tanggal,$jumlah,$kondisi,$fotoPath,$catatan]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    // --- GET PENERIMA LUAR ---
    if ($api === 'penerima_luar') {
        if (!$db) { echo json_encode(['data'=>getDemoPenerima()]); exit; }
        $rows = $db->query("SELECT * FROM penerima_luar ORDER BY created_at DESC")->fetchAll();
        echo json_encode(['data'=>$rows]);
        exit;
    }

    // --- POST PENERIMA LUAR ---
    if ($api === 'penerima_luar_add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $nama      = trim($_POST['nama'] ?? '');
        $nik       = trim($_POST['nik'] ?? '');
        $kategori  = $_POST['kategori'] ?? 'ibu_hamil';
        $usia      = trim($_POST['usia'] ?? '');
        $catatan   = trim($_POST['catatan'] ?? '');
        $dokPath   = '';

        if (!empty($_POST['dok_data'])) {
            $data = str_replace(['data:image/jpeg;base64,','data:application/pdf;base64,',' '], ['','','+'], $_POST['dok_data']);
            $ext  = strpos($_POST['dok_data'],'pdf') !== false ? 'pdf' : 'jpg';
            $fname = 'dok_' . time() . '.' . $ext;
            file_put_contents($uploadDir . $fname, base64_decode($data));
            $dokPath = 'uploads/' . $fname;
        }

        if (!$db) { echo json_encode(['ok'=>true,'demo'=>true]); exit; }
        $stmt = $db->prepare("INSERT INTO penerima_luar (nama,nik,kategori,usia_kandungan,catatan,dokumen_path,status) VALUES (?,?,?,?,?,?,'pending')");
        $stmt->execute([$nama,$nik,$kategori,$usia,$catatan,$dokPath]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    // --- PATCH STATUS PENERIMA LUAR ---
    if ($api === 'update_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? 'aktif';
        if (!$db) { echo json_encode(['ok'=>true]); exit; }
        $db->prepare("UPDATE penerima_luar SET status=? WHERE id=?")->execute([$status,$id]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'API tidak ditemukan']);
    exit;
}

// ============================================================
// DEMO DATA (jika tanpa database)
// ============================================================
function getDemoKelas() {
    return [
        ['id'=>1,'nama'=>'TK A','jenjang'=>'TK','jumlah_siswa'=>20],
        ['id'=>2,'nama'=>'TK B','jenjang'=>'TK','jumlah_siswa'=>18],
        ['id'=>3,'nama'=>'Kelas 1A','jenjang'=>'SD','jumlah_siswa'=>28],
        ['id'=>15,'nama'=>'VII-A','jenjang'=>'SMP','jumlah_siswa'=>32],
        ['id'=>16,'nama'=>'VII-B','jenjang'=>'SMP','jumlah_siswa'=>31],
        ['id'=>18,'nama'=>'VIII-A','jenjang'=>'SMP','jumlah_siswa'=>32],
        ['id'=>24,'nama'=>'X-A','jenjang'=>'SMA','jumlah_siswa'=>34],
        ['id'=>30,'nama'=>'X-TKJ','jenjang'=>'SMK','jumlah_siswa'=>34],
    ];
}
function getDemoStats($today) {
    return [
        'totalAmbil'=>756,'kelasAmbil'=>18,'totalKelas'=>32,
        'omprengKembali'=>12,'ibuHamil'=>23,
        'lastAktif'=>[
            ['kelas_nama'=>'VII-A','jumlah_ambil'=>32,'created_at'=>$today.' 07:42:00'],
            ['kelas_nama'=>'VIII-B','jumlah_ambil'=>30,'created_at'=>$today.' 07:51:00'],
            ['kelas_nama'=>'IX-A','jumlah_ambil'=>29,'created_at'=>$today.' 07:58:00'],
            ['kelas_nama'=>'X-A','jumlah_ambil'=>34,'created_at'=>$today.' 08:05:00'],
        ]
    ];
}
function getDemoRekap() {
    return [
        ['kelas_nama'=>'VII-A','jenjang'=>'SMP','jumlah_ambil'=>32,'kembali'=>32,'kondisi'=>'baik','foto_ambil'=>'','foto_kembali'=>''],
        ['kelas_nama'=>'VII-B','jenjang'=>'SMP','jumlah_ambil'=>31,'kembali'=>31,'kondisi'=>'baik','foto_ambil'=>'','foto_kembali'=>''],
        ['kelas_nama'=>'VIII-A','jenjang'=>'SMP','jumlah_ambil'=>32,'kembali'=>0,'kondisi'=>'—','foto_ambil'=>'','foto_kembali'=>''],
        ['kelas_nama'=>'IX-C','jenjang'=>'SMP','jumlah_ambil'=>31,'kembali'=>28,'kondisi'=>'kotor','foto_ambil'=>'','foto_kembali'=>''],
        ['kelas_nama'=>'X-A','jenjang'=>'SMA','jumlah_ambil'=>34,'kembali'=>34,'kondisi'=>'baik','foto_ambil'=>'','foto_kembali'=>''],
    ];
}
function getDemoTotal() { return 160; }
function getDemoPenerima() {
    return [
        ['id'=>1,'nama'=>'Sari Wahyuni','nik'=>'332x','kategori'=>'ibu_hamil','usia_kandungan'=>'28 minggu','status'=>'aktif','catatan'=>''],
        ['id'=>2,'nama'=>'Dewi Lestari','nik'=>'334x','kategori'=>'balita','usia_kandungan'=>'8 bulan','status'=>'aktif','catatan'=>''],
        ['id'=>3,'nama'=>'Rina Safitri','nik'=>'335x','kategori'=>'ibu_menyusui','usia_kandungan'=>'—','status'=>'pending','catatan'=>'Menunggu dokumen'],
    ];
}

// AUTO INSTALL
installDB();
installAuthTables(getDB());

$authDb = getDB();
$authRequired = ($authDb !== null);
$currentUser = null;
if ($authRequired && !empty($_SESSION['user_id'])) {
    $st = $authDb->prepare('SELECT id, email, nama, role, kelas_info FROM users WHERE id = ? LIMIT 1');
    $st->execute([(int) $_SESSION['user_id']]);
    $currentUser = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$currentUser) {
        unset($_SESSION['user_id']);
    }
}
$showApp = !$authRequired || $currentUser;

// ===== TOMBOL WHATSAPP MENGAMBANG — GANTI DI SINI =====
// File: index.php (sekitar baris 348–355)
// Ubah $wa_phone: nomor WhatsApp (kode negara + nomor, tanpa +, spasi, atau strip).
// Contoh Indonesia: 6281234567890 untuk +62 812-3456-7890
// Opsional: $wa_message = teks awal chat (kosongkan '' jika tidak perlu).
$wa_phone = '6285600186870';
$wa_message = 'Halo, saya butuh bantuan terkait MBGue Bang dims.';
$wa_phone_digits = preg_replace('/\D/', '', $wa_phone);
$wa_url = 'https://wa.me/' . $wa_phone_digits;
if ($wa_message !== '') {
    $wa_url .= '?text=' . rawurlencode($wa_message);
}
?>
<!DOCTYPE html>
<html lang="id" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MBGue — Program Makan Bergizi Gratis</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="<?php echo $showApp ? '' : 'auth-body'; ?>">
<div class="toast" id="toast"></div>

<!-- Tombol WhatsApp mengambang — URL dari konfigurasi ~baris 348 index.php -->
<a
  href="<?php echo htmlspecialchars($wa_url, ENT_QUOTES, 'UTF-8'); ?>"
  class="wa-float"
  target="_blank"
  rel="noopener noreferrer"
  aria-label="Hubungi kami via WhatsApp"
  title="Chat WhatsApp"
>
  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.435 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
</a>

<?php if (!$showApp): ?>

<div class="auth-layout" id="auth-root">

  <!-- LEFT: Hero Brand Panel -->
  <div class="auth-hero">
    <div class="auth-hero-deco" aria-hidden="true">
      <span class="auth-deco-ring r1"></span>
      <span class="auth-deco-ring r2"></span>
      <span class="auth-deco-ring r3"></span>
    </div>
    <div class="auth-hero-body">
      <div class="auth-hero-icon">
        <svg viewBox="0 0 24 24" fill="white" width="30" height="30"><path d="M18.5 8.5l-1.5-.5C16 6 14 5 12 5c-4 0-7 3-7 7s3 7 7 7c2.9 0 5.4-1.8 6.5-4.4l.5-1.1V12c0-.5-.1-1-.2-1.5zM12 17c-2.8 0-5-2.2-5-5s2.2-5 5-5c1.9 0 3.5 1 4.4 2.5L12 11V7l-4 4 4 4v-2.9l3.3-1.7c.1.5.2 1 .2 1.6 0 2.8-2.2 5-5 5z"/></svg>
      </div>
      <h1 class="auth-hero-title">Halo,<br>MBGue!</h1>
      <p class="auth-hero-desc">Catat distribusi makanan bergizi gratis dengan nyaman — untuk perwakilan kelas, petugas MBG, hingga tim dapur SPPG.</p>
    </div>
    <p class="auth-hero-copy">© 2025 MBGue · SMKN 1 Bangsri. All rights reserved.</p>
  </div>

  <!-- RIGHT: Form Panel -->
  <div class="auth-panel-wrap">
    <div class="auth-panel">

      <div class="auth-welcome" id="auth-welcome">
        <h2 class="auth-welcome-title" id="auth-welcome-title">Selamat Datang Kembali!</h2>
        <p class="auth-welcome-sub" id="auth-welcome-sub">Masuk ke akun Anda untuk melanjutkan</p>
      </div>

      <div class="auth-tabs" role="tablist">
        <button type="button" class="auth-tab active" id="tab-login" data-auth-tab="login" onclick="setAuthTab('login')">Masuk</button>
        <button type="button" class="auth-tab" id="tab-register" data-auth-tab="register" onclick="setAuthTab('register')">Daftar</button>
      </div>

      <form id="form-login" class="auth-form active" method="POST" action="api/auth.php" onsubmit="submitLogin(event); return false;" autocomplete="on">
        <input type="hidden" name="action" value="login">
        <label class="auth-field floating-field">
          <input type="email" name="email" id="login-email" required placeholder=" ">
          <span>Email sekolah / pribadi</span>
        </label>
        <label class="auth-field floating-field">
          <input type="password" name="password" id="login-password" required placeholder=" " minlength="8">
          <span>Kata sandi</span>
        </label>
        <button type="submit" class="auth-submit">
          <span>Masuk Sekarang</span>
        </button>
        <p class="auth-bottom-link">Belum punya akun? <button type="button" class="auth-switch-link" onclick="setAuthTab('register')">Daftar sekarang</button></p>
      </form>

      <form id="form-register" class="auth-form" method="POST" action="api/auth.php" onsubmit="submitRegister(event); return false;" autocomplete="on">
        <input type="hidden" name="action" value="register">
        <label class="auth-field floating-field">
          <input type="text" name="nama" id="reg-nama" required placeholder=" " minlength="2">
          <span>Nama lengkap</span>
        </label>
        <label class="auth-field floating-field">
          <input type="email" name="email" id="reg-email" required placeholder=" ">
          <span>Email aktif</span>
        </label>
        <label class="auth-field floating-field">
          <input type="password" name="password" id="reg-password" required placeholder=" " minlength="8">
          <span>Kata sandi (min. 8 karakter)</span>
        </label>
        <label class="auth-field">
          <span class="auth-select-label">Peran Anda</span>
          <select class="auth-select" id="reg-role" name="role" required onchange="toggleKelasField()">
            <option value="perwakilan_kelas">Perwakilan kelas</option>
            <option value="petugas_mbg">Petugas MBG di sekolah</option>
            <option value="dapur_sppg">Pihak dapur / SPPG</option>
          </select>
        </label>
        <label class="auth-field floating-field" id="reg-kelas-wrap">
          <input type="text" name="kelas_info" id="reg-kelas" placeholder=" " maxlength="120">
          <span>Kelas / jurusan yang diwakili</span>
        </label>
        <button type="submit" class="auth-submit accent-soft">
          <span>Buat Akun</span>
        </button>
        <p class="auth-bottom-link">Sudah punya akun? <button type="button" class="auth-switch-link" onclick="setAuthTab('login')">Masuk sekarang</button></p>
      </form>

      <?php if (!$authDb): ?>
      <p class="auth-banner-warn">Database tidak terhubung — hubungkan MySQL di <code>config/db.php</code> untuk mengaktifkan login.</p>
      <?php endif; ?>
    </div>
  </div>

</div>

<script src="assets/app.js"></script>

<?php else: ?>

<div class="shell" id="shell">

  <div class="sb-edge-trigger" id="sb-edge-trigger" aria-hidden="true"></div>

  <!-- SIDEBAR OVERLAY (mobile) -->
  <div class="overlay" id="overlay" onclick="closeSidebar()"></div>

  <!-- SIDEBAR -->
  <aside class="sidebar collapsed" id="sidebar">
    <div class="sb-brand">
      <div class="sb-brand-icon">
        <svg viewBox="0 0 24 24" fill="white"><path d="M18.5 8.5l-1.5-.5C16 6 14 5 12 5c-4 0-7 3-7 7s3 7 7 7c2.9 0 5.4-1.8 6.5-4.4l.5-1.1V12c0-.5-.1-1-.2-1.5zM12 17c-2.8 0-5-2.2-5-5s2.2-5 5-5c1.9 0 3.5 1 4.4 2.5L12 11V7l-4 4 4 4v-2.9l3.3-1.7c.1.5.2 1 .2 1.6 0 2.8-2.2 5-5 5z"/></svg>
      </div>
      <div class="sb-brand-text">
        <span class="sb-brand-name">MBGue</span>
        <span class="sb-brand-sub">Makan Bergizi Gratis</span>
      </div>
    </div>

    <div class="sb-school">
      <img class="sb-school-av" src="assets/eskasaba.png" alt="Logo Eskasaba">
      <div class="sb-school-info">
        <div class="sb-school-meta">
          <span class="sb-school-name">SMKN 1 BANGSRI</span>
        </div>
        <span class="sb-school-year">TA 2025/2026</span>
      </div>
    </div>

    <nav class="sb-nav">
      <div class="sb-section-label">Menu Utama</div>
      <button class="sb-item active" data-page="dashboard" onclick="navigate('dashboard', this)">
        <svg viewBox="0 0 24 24"><path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/></svg>
        <span>Dashboard</span>
      </button>
      <button class="sb-item" data-page="pengambilan" onclick="navigate('pengambilan', this)">
        <svg viewBox="0 0 24 24"><path d="M20 6h-2.18c.07-.44.18-.88.18-1.35C18 2.53 15.58 1 12 1S6 2.53 6 4.65c0 .47.11.91.18 1.35H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2zm-8-3.35c1.87 0 3.35.66 3.35 2C15.35 5.79 13.87 6.5 12 6.5S8.65 5.79 8.65 4.65c0-1.34 1.48-2 3.35-2zm2 10.35l-3.27-3.27c.19-.48.27-.99.27-1.53V8h2v2c0 .28.22.5.5.5S14 10.28 14 10V8h2v1.2l-2 2.15z"/></svg>
        <span>Pengambilan MBG</span>
        <span class="sb-badge" id="badge-pengambilan">0</span>
      </button>
      <button class="sb-item" data-page="pengembalian" onclick="navigate('pengembalian', this)">
        <svg viewBox="0 0 24 24"><path d="M12.5 8c-2.65 0-5.05.99-6.9 2.6L2 7v9h9l-3.62-3.62c1.39-1.16 3.16-1.88 5.12-1.88 3.54 0 6.55 2.31 7.6 5.5l2.37-.78C21.08 11.03 17.15 8 12.5 8z"/></svg>
        <span>Pengembalian</span>
        <span class="sb-badge pending" id="badge-pengembalian">0</span>
      </button>
      <div class="sb-section-label sb-section-gap">Laporan</div>
      <button class="sb-item" data-page="rekap" onclick="navigate('rekap', this)">
        <svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zM9 17H7v-7h2v7zm4 0h-2V7h2v10zm4 0h-2v-4h2v4z"/></svg>
        <span>Rekap &amp; Laporan</span>
      </button>
      <div class="sb-section-label sb-section-gap">Interaksi</div>
      <button class="sb-item" data-page="quiz" onclick="navigate('quiz', this)">
        <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 17h-2v-2h2v2zm2.07-7.75l-.9.92C13.45 12.9 13 13.5 13 15h-2v-.5c0-1.1.45-2.1 1.17-2.83l1.24-1.26c.37-.36.59-.86.59-1.41 0-1.1-.9-2-2-2s-2 .9-2 2H8c0-2.21 1.79-4 4-4s4 1.79 4 4c0 .88-.36 1.68-.93 2.25z"/></svg>
        <span>Quiz tebak menu</span>
      </button>
      <button class="sb-item" data-page="saran" onclick="navigate('saran', this)">
        <svg viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z"/></svg>
        <span>Saran &amp; kritik</span>
      </button>
      <?php if (($currentUser['role'] ?? '') === 'petugas_mbg'): ?>
      <button class="sb-item sb-item-admin" data-page="petugas" onclick="navigate('petugas', this)">
        <svg viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
        <span>Kelola (petugas)</span>
      </button>
      <?php endif; ?>
    </nav>

    <div class="sb-user-card">
      <div class="sb-user-avatar" id="sb-user-initial">?</div>
      <div class="sb-user-meta">
        <span class="sb-user-name" id="sb-user-name">Pengguna</span>
        <span class="sb-user-role" id="sb-user-role"></span>
      </div>
      <button type="button" class="sb-logout" onclick="logoutUser()" title="Keluar">Keluar</button>
    </div>

    <div class="sb-footer">
      <div class="sb-footer-info">
        <svg viewBox="0 0 24 24" fill="rgba(255,255,255,0.5)"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/></svg>
        <span id="footer-date"></span>
      </div>
    </div>
  </aside>

  <!-- MAIN CONTENT -->
  <main class="main" id="main">

    <!-- TOPBAR -->
    <header class="topbar" id="topbar">
      <div class="topbar-center">
        <h1 class="topbar-title" id="topbar-title">Dashboard</h1>
        <span class="topbar-sub" id="topbar-sub">Selamat datang di MBGue</span>
      </div>
      <div class="topbar-actions">
        <button type="button" class="theme-toggle" id="theme-toggle" onclick="toggleTheme()" title="Mode terang" aria-label="Mode terang">
          <svg class="theme-toggle-icon theme-icon-sun" viewBox="0 0 24 24" aria-hidden="true"><path d="M6.76 4.84l-1.8-1.79-1.41 1.41 1.79 1.8 1.42-1.42zM4 10.5H1v2h3v-2zm10-7.5h-2v3h2v-3zm7.44 3.13l-1.41-1.41-1.79 1.79 1.41 1.41 1.79-1.79zm-1.8 12.71l1.79 1.8 1.41-1.41-1.8-1.79-1.4 1.4zM20 10.5v2h3v-2h-3zm-8 9.5c-3.31 0-6-2.69-6-6s2.69-6 6-6 6 2.69 6 6-2.69 6-6 6zm-6.5 2.5c-1.38 0-2.5-1.12-2.5-2.5S4.12 17 5.5 17 8 18.12 8 19.5 6.88 22 5.5 22zm0-17C4.12 5 3 3.88 3 2.5S4.12 0 5.5 0 8 1.12 8 2.5 6.88 5 5.5 5z"/></svg>
          <svg class="theme-toggle-icon theme-icon-moon" viewBox="0 0 24 24" aria-hidden="true"><path d="M9.37 5.51A7 7 0 1018.63 14.49 9 9 0 119.37 5.51z"/></svg>
        </button>
        <div class="topbar-notif-slot">
          <button type="button" class="topbar-badge-wrap" id="notif-toggle" title="Notifikasi — ompreng belum kembali" onclick="toggleNotifPanel(event)" aria-expanded="false" aria-haspopup="true" aria-controls="notif-dropdown">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.64-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/></svg>
            <span class="topbar-notif" id="topbar-notif">0</span>
          </button>
          <div class="notif-dropdown" id="notif-dropdown" role="region" aria-label="Panel notifikasi" onclick="event.stopPropagation()">
            <div class="notif-dropdown-header">Notifikasi</div>
            <div class="notif-dropdown-body" id="notif-dropdown-body">
              <p class="notif-empty">Memuat…</p>
            </div>
          </div>
        </div>
      </div>
    </header>

    <div class="content-wrap" id="content-wrap">

      <!-- DASHBOARD -->
      <section class="page active" id="page-dashboard">
        <div class="page-header">
          <div>
            <h2 class="page-title" id="dashboard-greeting">Selamat Pagi</h2>
            <p class="page-desc">Pantau distribusi MBG hari ini secara real-time</p>
          </div>
          <div class="date-chip" id="date-chip"></div>
        </div>

        <div class="stats-row">
          <div class="stat-card blue">
            <div class="stat-icon"><svg viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg></div>
            <div class="stat-body">
              <span class="stat-label">Total Porsi MBG</span>
              <span class="stat-value" id="s-total">0</span>
              <span class="stat-sub">porsi terdistribusi hari ini</span>
            </div>
          </div>
          <div class="stat-card green">
            <div class="stat-icon"><svg viewBox="0 0 24 24"><path d="M20 6h-2.18c.07-.44.18-.88.18-1.35C18 2.53 15.58 1 12 1S6 2.53 6 4.65c0 .47.11.91.18 1.35H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2z"/></svg></div>
            <div class="stat-body">
              <span class="stat-label">Kelas Sudah Ambil</span>
              <span class="stat-value" id="s-kelas">0</span>
              <span class="stat-sub" id="s-kelas-sub">dari 0 kelas tercatat</span>
            </div>
          </div>
          <div class="stat-card orange">
            <div class="stat-icon"><svg viewBox="0 0 24 24"><path d="M12.5 8c-2.65 0-5.05.99-6.9 2.6L2 7v9h9l-3.62-3.62c1.39-1.16 3.16-1.88 5.12-1.88 3.54 0 6.55 2.31 7.6 5.5l2.37-.78C21.08 11.03 17.15 8 12.5 8z"/></svg></div>
            <div class="stat-body">
              <span class="stat-label">Ompreng Belum Kembali</span>
              <span class="stat-value" id="s-belum">0</span>
              <span class="stat-sub">kelas menunggu pengembalian</span>
            </div>
          </div>
        </div>

        <div class="dash-grid">
          <div class="dash-card">
            <div class="dash-card-header">
              <h3>Progress Distribusi Kelas</h3>
              <span class="pill blue" id="prog-pct">0%</span>
            </div>
            <div id="prog-list" class="prog-list">
              <div class="empty-state">Belum ada data pengambilan hari ini</div>
            </div>
          </div>
          <div class="dash-card">
            <div class="dash-card-header">
              <h3>Aktivitas Terbaru</h3>
              <button class="link-btn" onclick="navigate('rekap', document.querySelector('[data-page=rekap]'))">Lihat semua</button>
            </div>
            <div id="activity-list" class="activity-list">
              <div class="empty-state">Belum ada aktivitas hari ini</div>
            </div>
          </div>
          <div class="dash-card dash-card-charts">
            <div class="dash-card-header">
              <h3>Grafik statistik</h3>
              <span class="pill blue" id="chart-period-label">Sen–Jum minggu ini</span>
            </div>
            <div class="chart-full">
              <h4 class="chart-caption">
                Porsi pengambilan per tingkat &amp; jurusan (Sen–Jum minggu ini); garis oranye = persentase ompreng dikembalikan dibanding porsi ambil harian
              </h4>
              <div class="chart-canvas-wrap chart-bar-wrap chart-canvas-wrap-wide">
                <canvas id="chart-mbg-minggu" height="280"></canvas>
              </div>
            </div>
          </div>
        </div>

      </section>

      <!-- PENGAMBILAN MBG -->
      <section class="page" id="page-pengambilan">
        <div class="page-header">
          <div>
            <h2 class="page-title">Pengambilan MBG</h2>
            <p class="page-desc">Catat pengambilan makanan per kelas beserta foto bukti</p>
          </div>
        </div>

        <div class="two-col">
          <div class="form-card">
            <h3 class="form-section-title">Data Kelas</h3>
            <div class="field-group">
              <label class="field-label">Jenjang pendidikan</label>
              <div class="jenjang-static"><span class="jenjang-badge">SMK</span><span class="field-hint">Hanya jenjang SMK</span></div>
              <input type="hidden" id="jenjang-value" value="SMK">
            </div>
            <div class="field-group">
              <label class="field-label">Nama Kelas</label>
              <div style="display:flex;gap:8px">
                <select class="f-input" id="kelas-tingkat" style="flex:0 0 110px" onchange="updateMathInline()"></select>
                <select class="f-input" id="kelas-huruf" style="flex:1" onchange="updateMathInline()"></select>
              </div>
            </div>
            <div class="field-group">
              <label class="field-label">Jumlah Siswa <span class="field-hint">(hadir hari ini)</span></label>
              <div class="counter-wrap">
                <button class="cnt-btn" onclick="adjSiswa(-1)">
                  <svg viewBox="0 0 24 24"><path d="M19 13H5v-2h14v2z"/></svg>
                </button>
                <div class="cnt-display">
                  <span class="cnt-num" id="cnt-siswa">36</span>
                  <span class="cnt-label-small">siswa</span>
                </div>
                <button class="cnt-btn" onclick="adjSiswa(1)">
                  <svg viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
                </button>
              </div>
            </div>
            <div class="math-inline" id="math-inline">
              <div class="math-row">
                <span>Kelas <strong id="mi-kelas">7-A</strong> → <strong id="mi-siswa">36</strong> porsi MBG</span>
              </div>
              <div class="math-row">
                <span>Estimasi berat: <strong id="mi-berat">16</strong> kg</span>
              </div>
            </div>
            <div class="field-group" style="margin-top:16px">
              <label class="field-label">Waktu Pengambilan</label>
              <input type="time" class="f-input" id="waktu-ambil" value="07:30">
            </div>
            <div class="field-group">
              <label class="field-label">Catatan <span class="field-hint">(opsional)</span></label>
              <input type="text" class="f-input" id="catatan-ambil" placeholder="Contoh: ada 2 siswa tidak hadir">
            </div>
          </div>
          <div class="form-card">
            <h3 class="form-section-title">Foto Bukti</h3>
            <div class="cam-zone" id="cam-zone" onclick="openCamera()">
              <div class="cam-icon">
                <svg viewBox="0 0 24 24"><path d="M12 15.2A3.2 3.2 0 1012 8.8a3.2 3.2 0 000 6.4zM20 4h-3.17L15 2H9L7.17 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 14H4V6h4.05l1.83-2h4.24l1.83 2H20v12zM12 7c-2.76 0-5 2.24-5 5s2.24 5 5 5 5-2.24 5-5-2.24-5-5-5zm0 8c-1.65 0-3-1.35-3-3s1.35-3 3-3 3 1.35 3 3-1.35 3-3 3z"/></svg>
              </div>
              <p class="cam-label">Buka Kamera</p>
              <span class="cam-hint">Foto langsung dikompres &amp; diberi stempel</span>
            </div>
            <input type="file" id="cam-input" accept="image/*" capture="environment" class="sr-only" tabindex="-1" aria-hidden="true" onchange="handleFoto(event)">
            <div class="foto-result" id="foto-result" style="display:none">
              <img id="foto-preview" alt="Preview foto">
              <div class="foto-stamp" id="foto-stamp"></div>
            </div>
          </div>
        </div>

        <button class="btn-submit" id="btn-submit-ambil" onclick="submitPengambilan()">
          <svg viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>
          Konfirmasi Pengambilan
        </button>
      </section>

      <!-- PENGEMBALIAN -->
      <section class="page" id="page-pengembalian">
        <div class="page-header">
          <div>
            <h2 class="page-title">Pengembalian Ompreng</h2>
            <p class="page-desc">Catat pengembalian wadah MBG beserta kondisinya</p>
          </div>
        </div>

        <div class="pending-list" id="pending-list">
          <div class="empty-state" style="padding:40px 0">Belum ada kelas yang menunggu pengembalian ompreng</div>
        </div>

        <div class="form-card" style="margin-top:16px">
          <h3 class="form-section-title">Form Pengembalian Manual</h3>
          <div class="field-group">
            <label class="field-label">Pilih Kelas</label>
            <select class="f-input" id="ret-kelas" onchange="updateRetMath()">
              <option value="">— Pilih Kelas —</option>
            </select>
          </div>
          <div class="field-group">
            <label class="field-label">Kondisi Ompreng</label>
            <div class="kondisi-opts">
              <label class="kondisi-opt selected" data-kondisi="baik" onclick="selectKondisi('baik', this)">
                <div class="kondisi-check active" data-val="baik"></div>
                <div>
                  <strong>Baik &amp; Bersih</strong>
                  <span>Tidak ada kerusakan</span>
                </div>
              </label>
              <label class="kondisi-opt" data-kondisi="kotor" onclick="selectKondisi('kotor', this)">
                <div class="kondisi-check" data-val="kotor"></div>
                <div>
                  <strong>Ada Sisa Makanan</strong>
                  <span>Perlu dibersihkan</span>
                </div>
              </label>
              <label class="kondisi-opt" data-kondisi="rusak" onclick="selectKondisi('rusak', this)">
                <div class="kondisi-check" data-val="rusak"></div>
                <div>
                  <strong>Rusak / Hilang</strong>
                  <span>Perlu dilaporkan</span>
                </div>
              </label>
            </div>
          </div>
          <div class="field-group">
            <label class="field-label">Jumlah Ompreng Kembali</label>
            <div class="counter-wrap">
              <button class="cnt-btn" onclick="adjOmpreng(-1)">
                <svg viewBox="0 0 24 24"><path d="M19 13H5v-2h14v2z"/></svg>
              </button>
              <div class="cnt-display">
                <span class="cnt-num" id="cnt-ompreng">36</span>
                <span class="cnt-label-small">ompreng</span>
              </div>
              <button class="cnt-btn" onclick="adjOmpreng(1)">
                <svg viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
              </button>
            </div>
          </div>
          <button class="btn-submit" onclick="submitPengembalian()">
            <svg viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>
            Konfirmasi Pengembalian
          </button>
        </div>
      </section>

      <!-- REKAP & LAPORAN -->
      <section class="page" id="page-rekap">
        <div class="page-header">
          <div>
            <h2 class="page-title">Rekap &amp; Laporan</h2>
            <p class="page-desc">Data distribusi MBG lengkap dengan foto bukti</p>
          </div>
          <div style="display:flex;gap:8px;flex-wrap:wrap">
            <button type="button" class="btn-icon btn-excel" onclick="exportLaporanMingguan()">
              <svg viewBox="0 0 24 24"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm4 18H6V4h7v5h5v11zM8 15h8v2H8v-2zm0-4h8v2H8v-2zm2-4h4v2h-4V7z"/></svg>
              Excel mingguan
            </button>
          </div>
        </div>

        <div class="filter-bar">
          <div class="filter-item">
            <label class="field-label" style="margin:0">Minggu laporan (rekap)</label>
            <input type="week" class="f-input" id="filter-minggu" style="width:auto">
          </div>
          <div class="filter-item">
            <label class="field-label" style="margin:0">Tanggal</label>
            <input type="date" class="f-input" id="filter-tanggal" style="width:auto">
          </div>
          <div class="filter-item">
            <label class="field-label" style="margin:0">Jenis</label>
            <select class="f-input" id="filter-jenis" style="width:auto" onchange="renderRekap()">
              <option value="semua">Semua</option>
              <option value="pengambilan">Pengambilan</option>
              <option value="pengembalian">Pengembalian</option>
            </select>
          </div>
          <button class="btn-filter" onclick="renderRekap()">Tampilkan</button>
        </div>

        <div class="rekap-summary" id="rekap-summary"></div>

        <div class="rekap-table-wrap">
          <table class="rekap-table" id="rekap-table">
            <thead>
              <tr>
                <th>Waktu</th>
                <th>Jenis</th>
                <th>Kelas / Penerima</th>
                <th>Jumlah</th>
                <th>Status</th>
                <th>Foto</th>
              </tr>
            </thead>
            <tbody id="rekap-tbody">
              <tr><td colspan="6" class="empty-td">Belum ada data</td></tr>
            </tbody>
          </table>
        </div>

        <?php if (($currentUser['role'] ?? '') === 'petugas_mbg'): ?>
        <div style="margin-top: 16px; text-align: right;">
          <button type="button" class="btn-filter" onclick="clearRekapHistory()">
            Bersihkan riwayat
          </button>
        </div>
        <?php endif; ?>
      </section>

      <!-- QUIZ TEBAK MENU -->
      <section class="page" id="page-quiz">
        <div class="page-header">
          <div>
            <h2 class="page-title">Quiz tebak menu MBG</h2>
            <p class="page-desc">Tebak nama menu dari petunjuk singkat — seru dan mengenal variasi gizi</p>
          </div>
        </div>
        <div class="engage-grid">
          <div class="form-card engage-card">
            <p class="engage-lead">Selesaikan 5 soal. Setelah itu Anda melihat <strong>feedback khusus hari ini</strong> (menu &amp; pesan petugas MBG untuk hari Sen–Jumat bergilir).</p>
            <button type="button" class="btn-submit" style="margin-top:0" onclick="startQuizMBG()">Mulai quiz</button>
            <div id="quiz-area" class="quiz-area" style="display:none"></div>
          </div>
          <div class="form-card engage-card engage-result quiz-feedback-panel" id="quiz-feedback" style="display:none" tabindex="-1">
            <p class="quiz-feedback-kicker" id="quiz-feedback-kicker">Feedback hari ini</p>
            <h3 class="quiz-feedback-title" id="quiz-feedback-title"></h3>
            <div class="quiz-feedback-menu-box" id="quiz-feedback-menu-wrap" style="display:none">
              <span class="quiz-feedback-label">Menu</span>
              <p class="quiz-feedback-menu" id="quiz-feedback-menu"></p>
            </div>
            <div class="quiz-feedback-text-box">
              <span class="quiz-feedback-label">Pesan petugas</span>
              <p class="quiz-feedback-body" id="quiz-feedback-body"></p>
            </div>
          </div>
        </div>
      </section>

      <!-- SARAN & KRITIK -->
      <section class="page" id="page-saran">
        <div class="page-header">
          <div>
            <h2 class="page-title">Masukan</h2>
            <p class="page-desc">Kumpulan saran dan kritik yang dikirim oleh perwakilan kelas dan petugas MBG untuk Dapur SPPG. Daftar ini hanya dapat dilihat dan ditindaklanjuti oleh Dapur SPPG.</p>
          </div>
        </div>
      <?php if (($currentUser['role'] ?? '') !== 'dapur_sppg'): ?>
        <div class="form-card engage-card saran-form-panel">
          <p id="saran-access-note" style="margin-bottom:12px; color:#555; font-size:0.95rem;"></p>
          <div class="field-group">
            <label class="field-label">Jenis masukan</label>
            <select class="f-input" id="saran-jenis">
              <option value="saran_menu">Saran menu MBG</option>
              <option value="kritik">Kritik / perbaikan</option>
              <option value="lain">Lainnya</option>
            </select>
          </div>
          <div class="field-group">
            <label class="field-label">Isi pesan</label>
            <textarea class="f-input f-textarea" id="saran-isi" rows="5" placeholder="Tulis dengan sopan dan jelas…"></textarea>
          </div>
          <button type="button" class="btn-submit" onclick="kirimSaran()">Kirim masukan</button>
        </div>
      <?php endif; ?>

        <div class="form-card engage-card" style="margin-top:16px">
          <h3 class="form-section-title">Daftar Masukan</h3>
          <div class="admin-table-wrap" style="margin-top:12px">
            <table class="admin-table" id="tbl-masukan">
              <thead>
                <tr>
                  <th>Waktu</th>
                  <th>Pengirim</th>
                  <th>Jenis</th>
                  <th>Status</th>
                  <th>Isi</th>
                  <th>Feedback</th>
                  <th></th>
                </tr>
              </thead>
              <tbody></tbody>
            </table>
          </div>
          <div class="masukan-actions" style="margin-top:12px; padding-top:12px; border-top:1px solid var(--card-border)">
            <button type="button" class="btn-filter btn-danger" id="btn-hapus-riwayat" onclick="clearMasukanHistory()" title="Hapus semua riwayat masukan">Hapus Riwayat</button>
          </div>
        </div>
      </section>

      <?php if (($currentUser['role'] ?? '') === 'petugas_mbg'): ?>
      <section class="page" id="page-petugas">
        <div class="page-header">
          <div>
            <h2 class="page-title">Kelola — petugas MBG</h2>
            <p class="page-desc">Akun pengguna, bank soal quiz, jadwal menu, dan masukan warga sekolah</p>
          </div>
        </div>
        <div class="admin-stack">
          <div class="form-card engage-card">
            <h3 class="form-section-title">Pengguna terdaftar</h3>
            <div class="admin-table-wrap"><table class="admin-table" id="tbl-users"><thead><tr><th>Nama</th><th>Email</th><th>Peran</th><th></th></tr></thead><tbody></tbody></table></div>
          </div>
          <div class="form-card engage-card">
            <h3 class="form-section-title">Soal quiz tebak menu</h3>
            <div class="admin-inline-form">
              <input type="text" class="f-input" id="adm-quiz-nama" placeholder="Nama menu (jawaban benar)">
              <input type="text" class="f-input" id="adm-quiz-petunjuk" placeholder="Petunjuk singkat">
              <button type="button" class="btn-filter" onclick="adminQuizAdd()">Tambah</button>
            </div>
            <div class="admin-table-wrap" style="margin-top:12px"><table class="admin-table" id="tbl-quiz"><thead><tr><th>Menu</th><th>Petunjuk</th><th></th></tr></thead><tbody></tbody></table></div>
          </div>
          <div class="form-card engage-card">
            <h3 class="form-section-title">Feedback quiz per hari (Sen–Jum)</h3>
            <p class="page-desc" style="margin-bottom:12px">Setelah pengguna menyelesaikan quiz, yang tampil hanya feedback untuk <strong>hari kalender yang sama</strong> (berganti otomatis tiap hari).</p>
            <div class="feedback-harian-grid" id="feedback-harian-grid">
              <?php
              $fbhari = [
                  ['senin', 'Senin'],
                  ['selasa', 'Selasa'],
                  ['rabu', 'Rabu'],
                  ['kamis', 'Kamis'],
                  ['jumat', 'Jumat'],
              ];
              foreach ($fbhari as [$key, $lbl]): ?>
              <div class="feedback-harian-row">
                <strong class="feedback-harian-name"><?php echo htmlspecialchars($lbl); ?></strong>
                <label class="field-label">Menu singkat<input type="text" class="f-input fb-menu" data-hari="<?php echo $key; ?>" placeholder="Contoh: Nasi goreng seafood"></label>
                <label class="field-label">Pesan untuk peserta quiz<textarea class="f-input f-textarea fb-pesan" data-hari="<?php echo $key; ?>" rows="2" placeholder="Contoh: Terima kasih sudah ikut quiz — jaga kebersihan ompreng ya!"></textarea></label>
              </div>
              <?php endforeach; ?>
            </div>
            <button type="button" class="btn-submit" onclick="simpanFeedbackHarianAdmin()">Simpan feedback harian</button>
          </div>
          <div class="form-card engage-card">
            <h3 class="form-section-title">Jadwal menu Sen–Jumat (minggu berjalan)</h3>
            <p class="page-desc" style="margin-bottom:12px">Senin minggu ini = <span id="adm-minggu-label"></span></p>
            <div class="jadwal-edit-grid">
              <label class="field-label">Senin<input type="text" class="f-input" id="jm-senin" placeholder="Menu"></label>
              <label class="field-label">Selasa<input type="text" class="f-input" id="jm-selasa"></label>
              <label class="field-label">Rabu<input type="text" class="f-input" id="jm-rabu"></label>
              <label class="field-label">Kamis<input type="text" class="f-input" id="jm-kamis"></label>
              <label class="field-label">Jumat<input type="text" class="f-input" id="jm-jumat"></label>
            </div>
            <div class="field-group">
              <label class="field-label">Pesan untuk peserta quiz</label>
              <textarea class="f-input f-textarea" id="jm-pesan" rows="2" placeholder="Contoh: Tetap jaga kebersihan ompreng ya!"></textarea>
            </div>
            <button type="button" class="btn-submit" onclick="simpanJadwalPetugas()">Simpan jadwal</button>
          </div>
          <div class="form-card engage-card">
            <h3 class="form-section-title">Masukan saran &amp; kritik</h3>
            <div class="admin-table-wrap"><table class="admin-table" id="tbl-saran"><thead><tr><th>Waktu</th><th>Pengguna</th><th>Jenis</th><th>Isi</th></tr></thead><tbody></tbody></table></div>
          </div>
        </div>
      </section>
      <?php endif; ?>

    </div>
  </main>

  <!-- KAMERA LANGSUNG -->
  <div class="cam-modal" id="cam-modal" hidden aria-hidden="true">
    <div class="cam-modal-backdrop" onclick="closeCamModal()"></div>
    <div class="cam-modal-panel" role="dialog" aria-labelledby="cam-modal-title" aria-modal="true">
      <div class="cam-modal-head">
        <h3 id="cam-modal-title">Ambil Foto Bukti</h3>
        <button type="button" class="cam-modal-close" onclick="closeCamModal()" aria-label="Tutup">&times;</button>
      </div>
      <div class="cam-viewport">
        <video id="cam-video" autoplay playsinline muted></video>
        <p class="cam-live-stamp" id="cam-live-stamp"></p>
      </div>
      <p class="cam-modal-hint">Foto otomatis dikompres &amp; distempel setelah diambil</p>
      <div class="cam-modal-actions">
        <button type="button" class="cam-btn cam-btn-cancel" onclick="closeCamModal()">Batal</button>
        <button type="button" class="cam-btn cam-btn-capture" id="cam-btn-capture" onclick="captureFromCamera()">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 17.5A5.5 5.5 0 1112 6.5a5.5 5.5 0 010 11zm0-2a3.5 3.5 0 100-7 3.5 3.5 0 000 7zM20 5h-2.83L15 3H9L6.83 5H4a2 2 0 00-2 2v11a2 2 0 002 2h16a2 2 0 002-2V7a2 2 0 00-2-2z"/></svg>
          Ambil Foto
        </button>
      </div>
    </div>
  </div>

  <!-- FOTO LIGHTBOX -->
  <div class="lightbox" id="lightbox" onclick="closeLightbox()">
    <img id="lightbox-img" alt="" onclick="event.stopPropagation()">
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="https://cdn.sheetjs.com/xlsx-0.20.2/package/dist/xlsx.full.min.js"></script>
<script>
window.SIAP_USER = <?php echo json_encode($currentUser, JSON_UNESCAPED_UNICODE); ?>;

// Fallback ke localStorage jika session tidak tersedia (untuk cross-port access)
if (!window.SIAP_USER && localStorage.getItem("mbg_user_cache")) {
  try {
    window.SIAP_USER = JSON.parse(localStorage.getItem("mbg_user_cache"));
  } catch (e) {
    console.warn("Failed to parse cached user:", e);
  }
}
</script>
<script src="assets/app.js"></script>

<?php endif; ?>
</body>
</html>
