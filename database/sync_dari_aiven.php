<?php
/**
 * Salin data dari database ONLINE (Aiven) ke database LOKAL (MySQL XAMPP).
 *
 * Arah salinan satu arah: Aiven -> XAMPP. Data di XAMPP akan DITIMPA agar
 * sama persis dengan Aiven (tabel users & deadline). Tabel sessions tidak disalin.
 *
 * Jalankan lewat: database/sync_dari_aiven.bat (klik 2x), atau
 *   C:\xampp\php\php.exe database\sync_dari_aiven.php
 *
 * Konfigurasi dibaca dari file .env di root project:
 *   DB_*        -> database lokal XAMPP (tujuan)
 *   AIVEN_DB_*  -> database Aiven (sumber)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/api/koneksi.php'; // $pdo = koneksi lokal (tujuan)

$tabelDisalin = ['users', 'deadline'];

function tulis(string $pesan): void
{
    echo '[' . date('H:i:s') . "] $pesan" . PHP_EOL;
}

// ------------------------------------------------------------------
// 1. Koneksi ke Aiven (sumber)
// ------------------------------------------------------------------
$aivenHost = env('AIVEN_DB_HOST');
$aivenUser = env('AIVEN_DB_USER');
if (!$aivenHost || !$aivenUser) {
    tulis('GAGAL: AIVEN_DB_HOST / AIVEN_DB_USER belum diisi di file .env');
    exit(1);
}

try {
    $aiven = new PDO(
        sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $aivenHost,
            (int) env('AIVEN_DB_PORT', 3306),
            env('AIVEN_DB_NAME', 'defaultdb')
        ),
        $aivenUser,
        (string) env('AIVEN_DB_PASSWORD', ''),
        opsiPdoMysql(true, env('AIVEN_DB_SSL_CA'))
    );
    tulis('Terhubung ke Aiven: ' . $aivenHost);
} catch (PDOException $e) {
    tulis('GAGAL terhubung ke Aiven: ' . $e->getMessage());
    tulis('Cek AIVEN_DB_* di .env, koneksi internet, dan pastikan service Aiven tidak Powered off.');
    exit(1);
}

tulis('Terhubung ke database lokal: ' . env('DB_NAME') . ' @ ' . env('DB_HOST'));

// ------------------------------------------------------------------
// 2. Salin struktur + data tiap tabel
// ------------------------------------------------------------------
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

$total = 0;
foreach ($tabelDisalin as $tabel) {
    $ada = $aiven->query("SHOW TABLES LIKE " . $aiven->quote($tabel))->fetchColumn();
    if (!$ada) {
        tulis("Lewati `$tabel`: tidak ada di Aiven.");
        continue;
    }

    // Struktur tabel dari Aiven (MySQL 8) disesuaikan agar cocok dengan MariaDB XAMPP
    $create = $aiven->query("SHOW CREATE TABLE `$tabel`")->fetch(PDO::FETCH_NUM)[1];
    $create = preg_replace('/utf8mb4_0900_\w+/', 'utf8mb4_unicode_ci', $create);
    $create = preg_replace('#/\*!80\d{3}.*?\*/#s', '', $create);

    $rows = $aiven->query("SELECT * FROM `$tabel`")->fetchAll(PDO::FETCH_ASSOC);

    try {
        $pdo->exec("DROP TABLE IF EXISTS `$tabel`");
        $pdo->exec($create);

        if ($rows) {
            $kolom = array_keys($rows[0]);
            $sql   = sprintf(
                'INSERT INTO `%s` (`%s`) VALUES (%s)',
                $tabel,
                implode('`, `', $kolom),
                implode(', ', array_fill(0, count($kolom), '?'))
            );

            $pdo->beginTransaction();
            $stmt = $pdo->prepare($sql);
            foreach ($rows as $row) {
                $stmt->execute(array_values($row));
            }
            $pdo->commit();
        }

        tulis(sprintf('OK  `%s`: %d baris disalin', $tabel, count($rows)));
        $total += count($rows);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        tulis("GAGAL menyalin `$tabel`: " . $e->getMessage());
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        exit(1);
    }
}

$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

// Tabel sessions tetap dibutuhkan untuk login di localhost (isinya tidak disalin)
$pdo->exec("CREATE TABLE IF NOT EXISTS sessions (
    id            VARCHAR(128) NOT NULL PRIMARY KEY,
    data          MEDIUMTEXT   NOT NULL,
    last_accessed INT UNSIGNED NOT NULL,
    INDEX idx_last_accessed (last_accessed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

tulis("SELESAI. Total $total baris disalin dari Aiven ke XAMPP.");
