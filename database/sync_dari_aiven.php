<?php
/**
 * Salin data dari database ONLINE (Aiven) ke database LOKAL (MySQL XAMPP).
 *
 * Arah salinan satu arah: Aiven -> XAMPP. Data di XAMPP akan DITIMPA agar
 * sama persis dengan Aiven (users, deadline, wa_penerima, lampiran). Tabel sessions tidak disalin.
 *
 * Script ini berdiri sendiri (tidak butuh folder api/), jadi bisa dijalankan
 * dari folder project maupun dari folder hasil extract zip.
 *
 * Jalankan lewat: database/sync_dari_aiven.bat (klik 2x), atau
 *   D:\xampp\php\php.exe database\sync_dari_aiven.php
 *
 * Konfigurasi dibaca dari file .env (dicari di folder ini lalu folder di atasnya):
 *   DB_*        -> database lokal XAMPP (tujuan)
 *   AIVEN_DB_*  -> database Aiven (sumber)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

date_default_timezone_set('Asia/Jakarta');

$tabelDisalin = ['users', 'deadline', 'wa_penerima', 'lampiran', 'lampiran_bagian', 'wa_log'];

function tulis(string $pesan): void
{
    echo '[' . date('H:i:s') . "] $pesan" . PHP_EOL;
}

// ------------------------------------------------------------------
// 1. Baca file .env
// ------------------------------------------------------------------
$envFile = null;
foreach ([__DIR__ . '/.env', dirname(__DIR__) . '/.env'] as $kandidat) {
    if (is_readable($kandidat)) {
        $envFile = $kandidat;
        break;
    }
}
if (!$envFile) {
    tulis('GAGAL: file .env tidak ditemukan.');
    tulis('Copy file .env.xampp.example menjadi .env (di folder project atau folder database), lalu isi AIVEN_DB_PASSWORD.');
    exit(1);
}

$env = [];
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
        continue;
    }
    [$key, $value] = array_map('trim', explode('=', $line, 2));
    $env[$key] = trim($value, "\"'");
}
tulis('Konfigurasi dibaca dari: ' . realpath($envFile));

$cfg = static fn(string $key, $default = null) => ($env[$key] ?? '') !== '' ? $env[$key] : $default;

// ------------------------------------------------------------------
// 2. Opsi koneksi PDO (+ SSL untuk Aiven)
// ------------------------------------------------------------------
function opsiPdo(bool $pakaiSsl, ?string $caFile = null): array
{
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    if (!$pakaiSsl) {
        return $options;
    }

    $attrCa     = defined('Pdo\Mysql::ATTR_SSL_CA') ? Pdo\Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA;
    $attrVerify = defined('Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT')
        ? Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT
        : PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT;

    if ($caFile && is_file($caFile)) {
        $options[$attrCa]     = $caFile;
        $options[$attrVerify] = true;
        return $options;
    }

    // Sertifikat CA bawaan XAMPP / sistem (hanya untuk mengaktifkan TLS)
    $xampp = dirname(PHP_BINARY, 2);
    $candidates = [
        ini_get('curl.cainfo') ?: '',
        ini_get('openssl.cafile') ?: '',
        $xampp . '/apache/bin/curl-ca-bundle.crt',
        $xampp . '/php/extras/ssl/cacert.pem',
        '/etc/ssl/certs/ca-certificates.crt',
        '/etc/pki/tls/certs/ca-bundle.crt',
        '/etc/ssl/cert.pem',
    ];
    foreach ($candidates as $candidate) {
        if ($candidate && is_file($candidate)) {
            $options[$attrCa] = $candidate;
            break;
        }
    }
    $options[$attrVerify] = false;

    return $options;
}

function sambung(string $host, int $port, string $db, string $user, string $pass, array $opsi): PDO
{
    return new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $pass, $opsi);
}

// ------------------------------------------------------------------
// 3. Koneksi ke Aiven (sumber)
// ------------------------------------------------------------------
if (!$cfg('AIVEN_DB_HOST') || !$cfg('AIVEN_DB_USER') || !$cfg('AIVEN_DB_PASSWORD')) {
    tulis('GAGAL: AIVEN_DB_HOST / AIVEN_DB_USER / AIVEN_DB_PASSWORD belum diisi di file .env');
    exit(1);
}

try {
    $aiven = sambung(
        $cfg('AIVEN_DB_HOST'),
        (int) $cfg('AIVEN_DB_PORT', 3306),
        $cfg('AIVEN_DB_NAME', 'defaultdb'),
        $cfg('AIVEN_DB_USER'),
        $cfg('AIVEN_DB_PASSWORD'),
        opsiPdo(true, $cfg('AIVEN_DB_SSL_CA'))
    );
    // Aiven memakai sql_mode ANSI_QUOTES -> SHOW CREATE TABLE memakai "kutip ganda"
    // yang tidak dipahami MariaDB XAMPP. Matikan untuk sesi ini agar hasilnya memakai `backtick`.
    // Sesi ini hanya membaca data, jadi aman mengosongkan sql_mode.
    $aiven->exec("SET SESSION sql_mode = ''");
    tulis('Terhubung ke Aiven: ' . $cfg('AIVEN_DB_HOST'));
} catch (PDOException $e) {
    tulis('GAGAL terhubung ke Aiven: ' . $e->getMessage());
    tulis('Cek AIVEN_DB_* di .env, koneksi internet, dan pastikan service Aiven tidak Powered off.');
    exit(1);
}

// ------------------------------------------------------------------
// 4. Koneksi ke MySQL XAMPP (tujuan). Database dibuat jika belum ada.
// ------------------------------------------------------------------
$lokalDb = $cfg('DB_NAME', 'reminders_db');
if (!preg_match('/^\w+$/', $lokalDb)) {
    tulis("GAGAL: nama database lokal tidak valid: $lokalDb");
    exit(1);
}

try {
    $lokalHost = $cfg('DB_HOST', '127.0.0.1');
    $lokalPort = (int) $cfg('DB_PORT', 3306);
    $lokalUser = $cfg('DB_USER', 'root');
    $lokalPass = (string) $cfg('DB_PASSWORD', '');
    $lokalSsl  = filter_var($cfg('DB_SSL', 'false'), FILTER_VALIDATE_BOOLEAN);

    $server = new PDO("mysql:host=$lokalHost;port=$lokalPort;charset=utf8mb4", $lokalUser, $lokalPass, opsiPdo($lokalSsl));
    $server->exec("CREATE DATABASE IF NOT EXISTS `$lokalDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $lokal = sambung($lokalHost, $lokalPort, $lokalDb, $lokalUser, $lokalPass, opsiPdo($lokalSsl));
    tulis("Terhubung ke database lokal: $lokalDb @ $lokalHost");
} catch (PDOException $e) {
    tulis('GAGAL terhubung ke MySQL XAMPP: ' . $e->getMessage());
    tulis('Pastikan MySQL sudah di-START di XAMPP Control Panel.');
    exit(1);
}

// ------------------------------------------------------------------
// 5. Salin struktur + data tiap tabel
// ------------------------------------------------------------------
$lokal->exec('SET FOREIGN_KEY_CHECKS = 0');

$total = 0;
foreach ($tabelDisalin as $tabel) {
    $ada = $aiven->query('SHOW TABLES LIKE ' . $aiven->quote($tabel))->fetchColumn();
    if (!$ada) {
        tulis("Lewati `$tabel`: tidak ada di Aiven.");
        continue;
    }

    // Struktur tabel dari Aiven (MySQL 8) disesuaikan agar cocok dengan MariaDB XAMPP
    $create = $aiven->query("SHOW CREATE TABLE `$tabel`")->fetch(PDO::FETCH_NUM)[1];
    $create = preg_replace('/utf8mb4_0900_\w+/', 'utf8mb4_unicode_ci', $create);
    $create = preg_replace('#/\*!80\d{3}.*?\*/#s', '', $create);

    $rows = $aiven->query("SELECT * FROM `$tabel`")->fetchAll(PDO::FETCH_ASSOC);

    // Pengaman: jangan timpa backup lokal yang berisi data dengan tabel Aiven yang kosong
    // (misal service Aiven baru dibuat ulang setelah trial habis).
    if (!$rows) {
        $adaLokal = $lokal->query('SHOW TABLES LIKE ' . $lokal->quote($tabel))->fetchColumn();
        $jumlahLokal = $adaLokal ? (int) $lokal->query("SELECT COUNT(*) FROM `$tabel`")->fetchColumn() : 0;
        if ($jumlahLokal > 0) {
            tulis("Lewati `$tabel`: tabel di Aiven KOSONG, data lokal ($jumlahLokal baris) tidak ditimpa.");
            continue;
        }
    }

    try {
        $lokal->exec("DROP TABLE IF EXISTS `$tabel`");
        $lokal->exec($create);

        if ($rows) {
            $kolom = array_keys($rows[0]);
            $sql   = sprintf(
                'INSERT INTO `%s` (`%s`) VALUES (%s)',
                $tabel,
                implode('`, `', $kolom),
                implode(', ', array_fill(0, count($kolom), '?'))
            );

            $lokal->beginTransaction();
            $stmt = $lokal->prepare($sql);
            foreach ($rows as $row) {
                $stmt->execute(array_values($row));
            }
            $lokal->commit();
        }

        tulis(sprintf('OK  `%s`: %d baris disalin', $tabel, count($rows)));
        $total += count($rows);
    } catch (PDOException $e) {
        if ($lokal->inTransaction()) {
            $lokal->rollBack();
        }
        tulis("GAGAL menyalin `$tabel`: " . $e->getMessage());
        $lokal->exec('SET FOREIGN_KEY_CHECKS = 1');
        exit(1);
    }
}

$lokal->exec('SET FOREIGN_KEY_CHECKS = 1');

// Tabel sessions tetap dibutuhkan untuk login di localhost (isinya tidak disalin)
$lokal->exec("CREATE TABLE IF NOT EXISTS sessions (
    id            VARCHAR(128) NOT NULL PRIMARY KEY,
    data          MEDIUMTEXT   NOT NULL,
    last_accessed INT UNSIGNED NOT NULL,
    INDEX idx_last_accessed (last_accessed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

tulis("SELESAI. Total $total baris disalin dari Aiven ke database lokal `$lokalDb`.");
