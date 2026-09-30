<?php
/**
 * Setup database sekali jalan (membuat tabel jika belum ada).
 * Buka: https://<domain-vercel>/setup.php?key=<CRON_SECRET>
 * Aman dijalankan berulang kali. Isi SQL sama dengan database/schema.sql.
 */
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/skema.php';

header('Content-Type: text/plain; charset=utf-8');

$secret = env('CRON_SECRET');
$key    = $_GET['key'] ?? '';
if (!$secret || !is_string($key) || !hash_equals($secret, $key)) {
    http_response_code(401);
    exit("Unauthorized. Tambahkan ?key=<CRON_SECRET> di URL.\n");
}

$queries = [
    "CREATE TABLE IF NOT EXISTS users (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        username       VARCHAR(50)  NOT NULL UNIQUE,
        password       VARCHAR(255) NOT NULL,
        nama_lengkap   VARCHAR(100) NOT NULL,
        dibuat_tanggal DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS deadline (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        kode_unit        VARCHAR(50)  NOT NULL,
        nama_unit        VARCHAR(150) NOT NULL,
        tanggal_awal     DATE         NOT NULL,
        tanggal_akhir    DATE         NOT NULL,
        pengingat        VARCHAR(20)  NOT NULL DEFAULT 'pending',
        terakhir_dikirim DATE         NULL,
        INDEX idx_tanggal_akhir (tanggal_akhir)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS sessions (
        id            VARCHAR(128) NOT NULL PRIMARY KEY,
        data          MEDIUMTEXT   NOT NULL,
        last_accessed INT UNSIGNED NOT NULL,
        INDEX idx_last_accessed (last_accessed)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

try {
    foreach ($queries as $sql) {
        $pdo->exec($sql);
    }
    pastikanTabelTambahan($pdo); // wa_penerima & lampiran

    // Tabel lama mungkin belum punya kolom-kolom untuk cron
    $kolom = $pdo->query("SHOW COLUMNS FROM deadline")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('pengingat', $kolom, true)) {
        $pdo->exec("ALTER TABLE deadline ADD COLUMN pengingat VARCHAR(20) NOT NULL DEFAULT 'pending'");
        echo "+ Kolom deadline.pengingat ditambahkan\n";
    }
    if (!in_array('terakhir_dikirim', $kolom, true)) {
        $pdo->exec("ALTER TABLE deadline ADD COLUMN terakhir_dikirim DATE NULL");
        echo "+ Kolom deadline.terakhir_dikirim ditambahkan\n";
    }

    echo "SETUP BERHASIL\n\nTabel di database:\n";
    foreach ($pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $jumlah = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        echo "- $t ($jumlah baris)\n";
    }
    echo "\nSilakan buka /login.php\n";
} catch (PDOException $e) {
    http_response_code(500);
    error_log('Setup error: ' . $e->getMessage());
    echo "SETUP GAGAL: " . $e->getMessage() . "\n";
}
