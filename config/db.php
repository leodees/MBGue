<?php
/**
 * SIAP-MBG — config/db.php
 * Koneksi database PDO
 * Ganti DB_USER, DB_PASS, dan DB_NAME sesuai server kamu
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'siapp_mbg');
define('DB_USER', 'root');       // <-- ganti
define('DB_PASS', '12345');           // <-- ganti
define('DB_CHARSET', 'utf8mb4');

try {
    // First try to connect without specifying the database
    $pdo_temp = new PDO(
        "mysql:host=" . DB_HOST . ";charset=" . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    
    // Create the database if it doesn't exist
    $pdo_temp->exec("CREATE DATABASE IF NOT EXISTS " . DB_NAME);
    
    // Now connect to the specific database
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'pesan'  => 'Koneksi database gagal. Periksa config/db.php: ' . $e->getMessage(),
    ]);
    exit;
}
