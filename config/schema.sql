-- ============================================================
-- SIAP-MBG — Database Schema
-- Jalankan file ini di phpMyAdmin atau MySQL CLI:
--   mysql -u root -p < config/schema.sql
-- ============================================================

CREATE DATABASE IF NOT EXISTS siapp_mbg
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE siapp_mbg;

-- ── Tabel Kelas ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS kelas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nama VARCHAR(30) NOT NULL,
    jenjang ENUM('TK','SD','SMP','SMA','SMK') NOT NULL,
    jumlah_siswa INT DEFAULT 0,
    created_at DATETIME DEFAULT NOW()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Tabel Pengambilan MBG ─────────────────────────────────
CREATE TABLE IF NOT EXISTS pengambilan (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kelas_id INT NOT NULL,
    tanggal DATE NOT NULL,
    jumlah_ambil INT NOT NULL,
    foto_path VARCHAR(255),
    petugas VARCHAR(100),
    catatan TEXT,
    created_at DATETIME DEFAULT NOW(),
    FOREIGN KEY (kelas_id) REFERENCES kelas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Tabel Pengembalian Ompreng ────────────────────────────
CREATE TABLE IF NOT EXISTS pengembalian (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pengambilan_id INT NOT NULL,
    kelas_id INT NOT NULL,
    tanggal DATE NOT NULL,
    jumlah_kembali INT NOT NULL,
    kondisi ENUM('baik','kotor','rusak') DEFAULT 'baik',
    foto_path VARCHAR(255),
    catatan TEXT,
    created_at DATETIME DEFAULT NOW(),
    FOREIGN KEY (pengambilan_id) REFERENCES pengambilan(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Index untuk performa query ────────────────────────────
CREATE INDEX idx_pengambilan_tanggal ON pengambilan(tanggal);
CREATE INDEX idx_pengambilan_kelas_id ON pengambilan(kelas_id);
CREATE INDEX idx_pengembalian_tanggal ON pengembalian(created_at);

-- ── Contoh data awal (opsional, hapus jika tidak perlu) ───
-- INSERT INTO pengambilan (kelas, jenjang, jumlah, waktu_ambil, berat_kg, estimasi_anggaran)
-- VALUES ('SMP 7-A', 'SMP', 32, '07:30:00', 16.0, 160000);
