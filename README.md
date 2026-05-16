# SIAP-MBG
## Sistem Informasi Administrasi Program Makan Bergizi Gratis

---

## Struktur File

```
siap-mbg/
├── index.html              ← Halaman utama (buka ini di browser)
├── assets/
│   ├── style.css           ← Seluruh tampilan & responsif
│   └── app.js              ← Logic JavaScript (sidebar, kamera, foto, rekap)
├── api/
│   ├── pengambilan.php     ← Backend: simpan data pengambilan MBG
│   ├── pengembalian.php    ← Backend: simpan pengembalian ompreng
│   └── penerima_luar.php  ← Backend: kelola ibu hamil, balita, dll
├── config/
│   ├── db.php              ← Koneksi database (edit username & password)
│   └── schema.sql          ← Struktur tabel MySQL
└── uploads/
    └── foto/               ← Folder penyimpanan foto (auto-dibuat)
```

---

## Cara Instalasi

### 1. Tanpa Backend (Demo langsung)
Buka `index.html` di browser — semua fitur frontend berjalan tanpa server.
Data tersimpan di memori browser (hilang jika halaman di-refresh).

### 2. Dengan Backend PHP + MySQL (Full)
1. Copy folder `siap-mbg/` ke `htdocs/` (XAMPP) atau `www/` (Laragon)
2. Buat database di phpMyAdmin:
   - Nama database: `siap_mbg`
   - Import file: `config/schema.sql`
3. Edit `config/db.php`:
   - Ganti `DB_USER` dan `DB_PASS` sesuai MySQL kamu
4. Buka browser: `http://localhost/siap-mbg/`

---

## Fitur Utama

| Fitur | Keterangan |
|---|---|
| Dashboard real-time | Statistik porsi, progress kelas, aktivitas terbaru |
| Pengambilan MBG | Form per kelas (TK–SMK), counter siswa, foto bukti |
| Foto bukti | Kamera langsung → kompres otomatis → stempel kelas + porsi + waktu |
| Pengembalian ompreng | Catat kondisi, hitung % pengembalian |
| Ibu Hamil & Balita | Cek syarat MBG, daftarkan penerima luar sekolah |
| Rekap & Laporan | Tabel lengkap + galeri foto + export .txt ke SPPG |
| Sidebar + hamburger | Animasi smooth, glass header saat scroll |
| Responsif | Mobile, tablet, desktop |

---

## Logika Matematika Terintegrasi

- **Total Porsi** = Σ(Siswa per Kelas) + Ibu Hamil + Balita
- **Estimasi Berat** = Total Porsi × 0,5 kg
- **Estimasi Anggaran** = Total Porsi × Rp 5.000
- **% Pengembalian** = (Ompreng Kembali ÷ Total Diambil) × 100%

---

## Teknologi

- **Frontend**: HTML5, CSS3, Vanilla JavaScript (ES6+)
- **Backend**: PHP 8+ (PDO)
- **Database**: MySQL / MariaDB
- **Font**: Plus Jakarta Sans (Google Fonts)
- **Tidak ada framework** — mudah dipahami dan dipresentasikan
