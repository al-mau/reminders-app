<?php
/**
 * SETUP DATABASE (membuat tabel jika belum ada) + pembersihan data tidak terpakai.
 * Buka: https://<domain-vercel>/setup.php?key=<CRON_SECRET>
 * Aman dijalankan berulang kali (data yang sudah ada TIDAK dihapus).
 * Isi SQL sama dengan database/schema.sql.
 *
 * Opsi tambahan di akhir URL:
 *   &bersihkan=1              -> hapus sesi login lama, catatan login gagal lama,
 *                                upload lampiran yang terputus, tabel audit_log lama,
 *                                kolom lampiran.thumbnail lama
 *   &bersihkan=1&hapus=a,b    -> hapus tabel a & b (hanya tabel yang TIDAK dipakai aplikasi)
 */
require_once __DIR__ . '/lib/koneksi.php';
require_once __DIR__ . '/lib/skema.php';

header('Content-Type: text/plain; charset=utf-8');

// Hanya bisa dibuka oleh yang tahu CRON_SECRET (sama dengan key di URL cron-job.org)
$secret = env('CRON_SECRET');
$key    = $_GET['key'] ?? '';
if (!$secret || !is_string($key) || !hash_equals($secret, $key)) {
    http_response_code(401);
    exit("Unauthorized. Tambahkan ?key=<CRON_SECRET> di URL.\n");
}

// Tabel utama: users (akun login), deadline (data unit), sessions (sesi login)
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
    pastikanTabelTambahan($pdo); // wa_penerima, telegram_penerima & lampiran

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

    echo "SETUP BERHASIL\n";

    // ---------------------------------------------------------------
    // Pembersihan data yang tidak dipakai aplikasi: tambahkan &bersihkan=1
    // ---------------------------------------------------------------
    // Daftar tabel yang DIPAKAI aplikasi -> tidak akan pernah dihapus oleh mode pembersihan
    $tabelAplikasi = ['users', 'deadline', 'sessions', 'wa_penerima', 'telegram_penerima', 'lampiran', 'lampiran_bagian', 'wa_log', 'login_gagal'];

    if (!empty($_GET['bersihkan'])) {
        echo "\n=== PEMBERSIHAN ===\n";

        // Sisa fitur Aktivitas User yang sudah dihapus
        if ($pdo->query("SHOW TABLES LIKE 'audit_log'")->fetchColumn()) {
            $pdo->exec("DROP TABLE audit_log");
            echo "- Tabel audit_log dihapus\n";
        }

        // Sisa fitur thumbnail lampiran yang sudah dihapus
        $kolomLampiran = $pdo->query("SHOW COLUMNS FROM lampiran")->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('thumbnail', $kolomLampiran, true)) {
            $pdo->exec("ALTER TABLE lampiran DROP COLUMN thumbnail");
            echo "- Kolom lampiran.thumbnail dihapus\n";
        }

        // Sesi login yang tidak dipakai > 1 hari
        $n = $pdo->exec("DELETE FROM sessions WHERE last_accessed < " . (time() - 86400));
        echo "- $n sesi login lama dihapus\n";

        // Data sementara (biasanya sudah dibersihkan otomatis)
        $n = $pdo->exec("DELETE FROM login_gagal WHERE waktu < '" . date('Y-m-d H:i:s', time() - 86400) . "'");
        echo "- $n catatan login gagal lama dihapus\n";
        $n = bersihkanLampiranGantung($pdo);
        echo "- Sisa lampiran tidak terpakai dibersihkan ($n potongan file dihapus)\n";

        // Tabel lain hanya dihapus jika namanya disebut: &hapus=nama1,nama2
        $minta = array_filter(array_map('trim', explode(',', (string) ($_GET['hapus'] ?? ''))));
        $semua = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($minta as $t) {
            if (!preg_match('/^\w+$/', $t) || !in_array($t, $semua, true)) {
                echo "- Lewati \"$t\": tabel tidak ditemukan\n";
            } elseif (in_array($t, $tabelAplikasi, true)) {
                echo "- Lewati \"$t\": tabel ini DIPAKAI aplikasi, tidak boleh dihapus\n";
            } else {
                $pdo->exec("DROP TABLE `$t`");
                echo "- Tabel $t dihapus\n";
            }
        }
    }

    // Tampilkan semua tabel + jumlah barisnya, beri tanda pada tabel yang tidak dipakai
    echo "\nTabel di database:\n";
    $tidakDipakai = [];
    foreach ($pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $jumlah = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        $dipakai = in_array($t, $tabelAplikasi, true);
        if (!$dipakai) {
            $tidakDipakai[] = $t;
        }
        echo "- $t ($jumlah baris)" . ($dipakai ? '' : '  <-- TIDAK dipakai aplikasi') . "\n";
    }

    if ($tidakDipakai) {
        echo "\nAda " . count($tidakDipakai) . " tabel yang tidak dipakai aplikasi. Jika yakin tidak diperlukan,\n"
            . "hapus dengan menambahkan di akhir URL ini:\n  &bersihkan=1&hapus=" . implode(',', $tidakDipakai) . "\n";
    } elseif (empty($_GET['bersihkan'])) {
        echo "\nTidak ada tabel yang tidak dipakai. Untuk membersihkan sesi login lama, tambahkan &bersihkan=1 di akhir URL.\n";
    }
    echo "\nSilakan buka /login.php\n";
} catch (PDOException $e) {
    http_response_code(500);
    error_log('Setup error: ' . $e->getMessage());
    echo "SETUP GAGAL: " . $e->getMessage() . "\n";
}
