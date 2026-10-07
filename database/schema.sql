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

-- Daftar nomor penerima notifikasi WA (dikelola dari dashboard)
CREATE TABLE IF NOT EXISTS wa_penerima (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    nama           VARCHAR(100) NOT NULL,
    nomor          VARCHAR(20)  NOT NULL UNIQUE,
    aktif          TINYINT(1)   NOT NULL DEFAULT 1,
    dibuat_tanggal DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Daftar chat Telegram penerima notifikasi (dikelola dari dashboard).
-- chat_id: angka dari Telegram; grup diawali tanda minus (contoh -1001234567890)
CREATE TABLE IF NOT EXISTS telegram_penerima (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    nama           VARCHAR(100) NOT NULL,
    chat_id        VARCHAR(25)  NOT NULL UNIQUE,
    aktif          TINYINT(1)   NOT NULL DEFAULT 1,
    dibuat_tanggal DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lampiran dokumen per unit (file disimpan di database, maks 5 MB per file)
CREATE TABLE IF NOT EXISTS lampiran (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    deadline_id    INT          NOT NULL,
    nama_file      VARCHAR(255) NOT NULL,
    ekstensi       VARCHAR(10)  NOT NULL,
    ukuran         INT UNSIGNED NOT NULL,
    isi            MEDIUMBLOB   NOT NULL,
    jumlah_bagian  INT          NOT NULL DEFAULT 0,
    selesai        TINYINT(1)   NOT NULL DEFAULT 1,
    diunggah_oleh  VARCHAR(50)  NULL,
    dibuat_tanggal DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_deadline_id (deadline_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Potongan isi lampiran (file besar disimpan per 768 KB)
CREATE TABLE IF NOT EXISTS lampiran_bagian (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    lampiran_id INT        NOT NULL,
    urutan      INT        NOT NULL,
    isi         MEDIUMBLOB NOT NULL,
    UNIQUE KEY uk_lampiran_urutan (lampiran_id, urutan)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Riwayat pengiriman WA
CREATE TABLE IF NOT EXISTS wa_log (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    waktu      DATETIME     NOT NULL,
    jenis      VARCHAR(10)  NOT NULL,
    ringkasan  VARCHAR(500) NOT NULL,
    penerima   VARCHAR(500) NOT NULL,
    status     VARCHAR(10)  NOT NULL,
    keterangan VARCHAR(255) NULL,
    INDEX idx_waktu (waktu)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Percobaan login gagal (pembatasan brute force)
CREATE TABLE IF NOT EXISTS login_gagal (
    id       INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    ip       VARCHAR(45) NOT NULL,
    waktu    DATETIME    NOT NULL,
    INDEX idx_ip_waktu (ip, waktu),
    INDEX idx_user_waktu (username, waktu)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Jika tabel sudah ada tapi kolom terakhir_dikirim belum ada, jalankan:
-- ALTER TABLE deadline ADD COLUMN terakhir_dikirim DATE NULL;
