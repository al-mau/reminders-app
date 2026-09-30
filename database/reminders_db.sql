-- =====================================================================
-- Database Reminders App untuk XAMPP (MySQL/MariaDB lokal)
-- Cara pakai: lihat CARA_PAKAI.txt di folder ini.
-- Aman dijalankan berulang kali (tidak menghapus data yang sudah ada).
-- =====================================================================

CREATE DATABASE IF NOT EXISTS reminders_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE reminders_db;

-- Tabel akun login
CREATE TABLE IF NOT EXISTS users (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    username       VARCHAR(50)  NOT NULL UNIQUE,
    password       VARCHAR(255) NOT NULL,
    nama_lengkap   VARCHAR(100) NOT NULL,
    dibuat_tanggal DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabel data unit & deadline
CREATE TABLE IF NOT EXISTS deadline (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    kode_unit        VARCHAR(50)  NOT NULL,
    nama_unit        VARCHAR(150) NOT NULL,
    tanggal_awal     DATE         NOT NULL,
    tanggal_akhir    DATE         NOT NULL,
    pengingat        VARCHAR(20)  NOT NULL DEFAULT 'pending',
    terakhir_dikirim DATE         NULL,
    INDEX idx_tanggal_akhir (tanggal_akhir)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabel sesi login (session disimpan di database)
CREATE TABLE IF NOT EXISTS sessions (
    id            VARCHAR(128) NOT NULL PRIMARY KEY,
    data          MEDIUMTEXT   NOT NULL,
    last_accessed INT UNSIGNED NOT NULL,
    INDEX idx_last_accessed (last_accessed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contoh data unit (hanya ditambahkan jika tabel deadline masih kosong)
INSERT INTO deadline (kode_unit, nama_unit, tanggal_awal, tanggal_akhir, pengingat)
SELECT * FROM (
    SELECT '01' AS kode_unit, 'Contoh Unit A' AS nama_unit, CURDATE() - INTERVAL 7 DAY AS tanggal_awal, CURDATE()                  AS tanggal_akhir, 'pending' AS pengingat
    UNION ALL
    SELECT '02', 'Contoh Unit B', CURDATE() - INTERVAL 7 DAY, CURDATE() + INTERVAL 1 DAY,  'pending'
    UNION ALL
    SELECT '03', 'Contoh Unit C', CURDATE() - INTERVAL 7 DAY, CURDATE() + INTERVAL 10 DAY, 'pending'
) AS contoh
WHERE NOT EXISTS (SELECT 1 FROM deadline);
