-- =====================================================================
-- Database Aplikasi Pengingat Hari Jadi (MySQL / MariaDB XAMPP)
-- Dijalankan oleh import_database.bat (versi XAMPP). Versi online (Aiven) tidak perlu file ini:
-- semua tabel dibuat otomatis oleh aplikasi (api/lib/skema.php, api/lib/sesi.php) jika belum ada.
-- Aman dijalankan ulang.
-- =====================================================================
CREATE DATABASE IF NOT EXISTS pengingat_hari_jadi CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE pengingat_hari_jadi;

-- Session login (disimpan di database agar tetap login di Vercel)
CREATE TABLE IF NOT EXISTS sessions (
    id            VARCHAR(128) NOT NULL PRIMARY KEY,
    data          MEDIUMTEXT   NOT NULL,
    last_accessed INT UNSIGNED NOT NULL,
    INDEX idx_akses (last_accessed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    username       VARCHAR(50)  NOT NULL UNIQUE,
    nama           VARCHAR(100) NOT NULL DEFAULT '',
    password       VARCHAR(255) NOT NULL,
    peran          VARCHAR(20)  NOT NULL DEFAULT 'pemantau',
    dibuat_tanggal DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cukup diisi sekali; pengingat berulang setiap tahun pada tanggal & bulan yang sama.
-- tanggal             = tanggal hari jadi (yang dipakai hanya tanggal & bulannya, tahunnya bebas)
-- terakhir_diingatkan = tanggal hari jadi yang pengingatnya sudah terkirim (mencegah kirim ganda)
CREATE TABLE IF NOT EXISTS hari_jadi (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    nama_daerah         VARCHAR(150) NOT NULL UNIQUE,
    tanggal             DATE         NOT NULL,
    terakhir_diingatkan DATE         NULL,
    dibuat_oleh         VARCHAR(50)  NULL,
    dibuat_tanggal      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wa_penerima (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    nama           VARCHAR(100) NOT NULL,
    nomor          VARCHAR(20)  NOT NULL UNIQUE,
    aktif          TINYINT(1)   NOT NULL DEFAULT 1,
    dibuat_tanggal DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS telegram_penerima (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    nama           VARCHAR(100) NOT NULL,
    chat_id        VARCHAR(25)  NOT NULL UNIQUE,
    aktif          TINYINT(1)   NOT NULL DEFAULT 1,
    dibuat_tanggal DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_penerima (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    nama           VARCHAR(100) NOT NULL,
    email          VARCHAR(150) NOT NULL UNIQUE,
    aktif          TINYINT(1)   NOT NULL DEFAULT 1,
    dibuat_tanggal DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kirim_log (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    waktu      DATETIME     NOT NULL,
    jenis      VARCHAR(20)  NOT NULL,
    saluran    VARCHAR(20)  NOT NULL,
    ringkasan  TEXT         NOT NULL,
    penerima   TEXT         NOT NULL,
    berhasil   TINYINT(1)   NOT NULL,
    keterangan VARCHAR(500) NOT NULL DEFAULT '',
    INDEX idx_waktu (waktu)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_gagal (
    id       INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    ip       VARCHAR(45) NOT NULL,
    waktu    DATETIME    NOT NULL,
    INDEX idx_username (username, waktu),
    INDEX idx_ip (ip, waktu)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
