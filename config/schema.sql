-- ============================================================
-- SIAP-MBG — Database Schema
-- Jalankan file ini di phpMyAdmin atau MySQL CLI:
--   mysql -u root -p < config/schema.sql
-- ============================================================

CREATE DATABASE IF NOT EXISTS siapp_mbg
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE siapp_mbg;

-- ── Tabel Pengambilan MBG ─────────────────────────────────
CREATE TABLE IF NOT EXISTS pengambilan (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    kelas              VARCHAR(30)    NOT NULL COMMENT 'Contoh: SMP 7-A',
    jenjang            VARCHAR(10)    NOT NULL COMMENT 'TK/SD/SMP/SMA/SMK',
    jumlah             INT            NOT NULL COMMENT 'Jumlah porsi diambil',
    waktu_ambil        TIME           NOT NULL COMMENT 'Jam pengambilan',
    catatan            TEXT                    COMMENT 'Catatan tambahan',
    foto               VARCHAR(255)            COMMENT 'Nama file foto bukti',
    berat_kg           DECIMAL(6,1)            COMMENT 'Estimasi berat (jumlah x 0.5 kg)',
    estimasi_anggaran  INT                     COMMENT 'Estimasi biaya (jumlah x Rp 5.000)',
    status_kembali     TINYINT(1) DEFAULT 0    COMMENT '0=belum kembali, 1=sudah kembali',
    created_at         DATETIME   DEFAULT NOW(),
    updated_at         DATETIME   DEFAULT NOW() ON UPDATE NOW()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Tabel Pengembalian Ompreng ────────────────────────────
CREATE TABLE IF NOT EXISTS pengembalian (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    id_pengambilan    INT            NOT NULL COMMENT 'FK ke tabel pengambilan',
    kelas             VARCHAR(30)    NOT NULL,
    jumlah_kembali    INT            NOT NULL COMMENT 'Jumlah ompreng dikembalikan',
    kondisi           ENUM('baik','kotor','rusak') DEFAULT 'baik',
    foto              VARCHAR(255)            COMMENT 'Foto bukti pengembalian',
    persen_kembali    DECIMAL(5,1)            COMMENT '(kembali/diambil)*100',
    created_at        DATETIME  DEFAULT NOW(),
    FOREIGN KEY (id_pengambilan) REFERENCES pengambilan(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Tabel Penerima Luar Sekolah ───────────────────────────
CREATE TABLE IF NOT EXISTS penerima_luar (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    nama               VARCHAR(100) NOT NULL,
    nik                VARCHAR(20)  NOT NULL UNIQUE,
    usia_info          VARCHAR(50)           COMMENT 'Usia kandungan atau usia bayi',
    kategori           ENUM('hamil','menyusui','balita','lansia') NOT NULL,
    syarat_terpenuhi   TINYINT DEFAULT 0     COMMENT 'Jumlah syarat yang terpenuhi (0-5)',
    status             ENUM('aktif','nonaktif') DEFAULT 'aktif',
    created_at         DATETIME DEFAULT NOW(),
    updated_at         DATETIME DEFAULT NOW() ON UPDATE NOW()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Index untuk performa query ────────────────────────────
CREATE INDEX idx_pengambilan_tanggal ON pengambilan(created_at);
CREATE INDEX idx_pengambilan_jenjang ON pengambilan(jenjang);
CREATE INDEX idx_pengembalian_tanggal ON pengembalian(created_at);
CREATE INDEX idx_penerima_kategori ON penerima_luar(kategori);

-- ── Contoh data awal (opsional, hapus jika tidak perlu) ───
-- INSERT INTO pengambilan (kelas, jenjang, jumlah, waktu_ambil, berat_kg, estimasi_anggaran)
-- VALUES ('SMP 7-A', 'SMP', 32, '07:30:00', 16.0, 160000);
