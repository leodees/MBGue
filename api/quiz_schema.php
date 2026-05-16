<?php

declare(strict_types=1);

function ensure_quiz_tables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS quiz_soal (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nama_menu VARCHAR(160) NOT NULL,
            petunjuk VARCHAR(380) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS quiz_selesai (
            user_id INT NOT NULL,
            tanggal DATE NOT NULL,
            benar TINYINT UNSIGNED NOT NULL DEFAULT 0,
            total_soal TINYINT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, tanggal),
            CONSTRAINT fk_quiz_selesai_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    $n = (int) $pdo->query('SELECT COUNT(*) FROM quiz_soal')->fetchColumn();
    if ($n < 5) {
        $demo = [
            ['Nasi goreng sayur', 'Menu vegetarian dengan nasi dan sayuran tumis'],
            ['Ayam goreng tepung', 'Krispi di luar, biasanya dengan saus tomat'],
            ['Sayur lodeh', 'Kuah santan dengan labu dan tempe'],
            ['Tempe bacem', 'Manis gurih, sering dijadikan lauk pendamping'],
            ['Buah potong', 'Segar, berwarna-warni, sebagai cemilan'],
            ['Mie kuah telur', 'Kuah hangat dengan mie kuning dan telur'],
        ];
        $ins = $pdo->prepare(
            'INSERT INTO quiz_soal (nama_menu, petunjuk) VALUES (?,?)'
        );
        foreach ($demo as [$nm, $pt]) {
            $ins->execute([$nm, $pt]);
        }
    }
}
