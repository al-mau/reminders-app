-- Skema database Reminders App (MySQL 8 / Aiven)
-- Jalankan sekali di database `defaultdb`. Aman dijalankan ulang (IF NOT EXISTS).

CREATE TABLE IF NOT EXISTS users (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    username       VARCHAR(50)  NOT NULL UNIQUE,
    password       VARCHAR(255) NOT NULL,
    nama_lengkap   VARCHAR(100) NOT NULL,
    dibuat_tanggal DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- Session disimpan di database karena Vercel (serverless) tidak menyimpan file session
CREATE TABLE IF NOT EXISTS sessions (
    id            VARCHAR(128) NOT NULL PRIMARY KEY,
    data          MEDIUMTEXT   NOT NULL,
    last_accessed INT UNSIGNED NOT NULL,
    INDEX idx_last_accessed (last_accessed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Jika tabel sudah ada tapi kolom terakhir_dikirim belum ada, jalankan:
-- ALTER TABLE deadline ADD COLUMN terakhir_dikirim DATE NULL;
