<?php
/**
 * FILE INTI: koneksi database (PDO) + fungsi bantu yang dipakai SEMUA halaman.
 * Di-include paling awal oleh setiap file di folder api/ (require_once).
 *
 * Isi file ini (urut dari atas):
 *  1. env()            -> membaca pengaturan dari Environment Variables / file .env
 *  2. Zona waktu & mode error
 *  3. opsiPdoMysql()   -> opsi koneksi MySQL + SSL (Aiven wajib SSL)
 *  4. $pdo             -> objek koneksi database yang dipakai di semua halaman
 *  5. Helper umum      -> e(), csrf_*(), pendaftaranDibuka(), tanggal_valid()
 *
 * Kredensial TIDAK boleh ditulis di kode. Isi lewat Environment Variables:
 *  - Vercel : Project Settings -> Environment Variables
 *  - Lokal  : file .env di root project (lihat .env.example, jangan di-commit)
 */

if (!function_exists('env')) {
    // Muat file .env (hanya untuk development lokal, misal XAMPP).
    // Di Vercel file .env tidak ada; nilainya diambil dari Environment Variables.
    // Nilai yang sudah ada di Environment Variables TIDAK ditimpa oleh .env.
    // Dicari di folder project: versi GitHub (api/lib/) maupun versi XAMPP (lib/).
    $envFile = is_readable(dirname(__DIR__) . '/.env') ? dirname(__DIR__) . '/.env' : dirname(__DIR__, 2) . '/.env';
    if (is_readable($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            $value = trim($value, "\"'");
            if (getenv($key) === false) {
                putenv("$key=$value");
                $_ENV[$key] = $value;
            }
        }
    }

    /**
     * Ambil nilai pengaturan, contoh: env('DB_HOST') atau env('APP_TIMEZONE', 'Asia/Jakarta').
     * Jika kosong / tidak ada -> kembalikan $default.
     */
    function env(string $key, $default = null)
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        }
        return ($value === null || $value === '') ? $default : $value;
    }
}

// Zona waktu semua fungsi tanggal PHP (date(), DateTime). Penting untuk cron:
// "hari ini" dan "besok" dihitung menurut jam Indonesia (WIB), bukan UTC server.
date_default_timezone_set(env('APP_TIMEZONE', 'Asia/Jakarta'));

// Jangan tampilkan warning/error PHP ke pengunjung (tetap dicatat di log).
// Set APP_DEBUG=true di .env lokal bila ingin melihat error saat development.
if (!filter_var(env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN)) {
    ini_set('display_errors', '0');
}
ini_set('log_errors', '1');

if (!function_exists('opsiPdoMysql')) {
    /**
     * Opsi PDO standar + SSL (wajib untuk Aiven).
     * MYSQL_ATTR_SSL_CA harus berupa PATH file sertifikat:
     *  - $caFile diisi path ca.pem dari Aiven -> sertifikat server diverifikasi penuh.
     *  - Kosong -> pakai CA bundle sistem hanya untuk mengaktifkan TLS (tanpa verifikasi).
     */
    function opsiPdoMysql(bool $pakaiSsl, ?string $caFile = null): array
    {
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Emulasi prepare: 1x bolak-balik ke DB per query (native = 2x: prepare + execute).
            // Tetap aman dari SQL injection karena nilai di-escape oleh driver.
            PDO::ATTR_EMULATE_PREPARES   => true,
        ];

        if (!$pakaiSsl) {
            return $options;
        }

        // PHP 8.4 memakai konstanta baru Pdo\Mysql::*, versi lama memakai PDO::MYSQL_*
        $sslAttrCa     = defined('Pdo\Mysql::ATTR_SSL_CA') ? Pdo\Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA;
        $sslAttrVerify = defined('Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT')
            ? Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT
            : PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT;

        // DB_SSL_CA boleh ditulis relatif terhadap folder project (misal "ca.pem")
        if ($caFile && !is_file($caFile) && is_file(dirname(__DIR__, 2) . '/' . ltrim($caFile, '/'))) {
            $caFile = dirname(__DIR__, 2) . '/' . ltrim($caFile, '/');
        }

        if ($caFile && is_file($caFile)) {
            $options[$sslAttrCa]     = $caFile;
            $options[$sslAttrVerify] = true;
            return $options;
        }

        // Tidak ada ca.pem -> cari CA bundle bawaan sistem agar koneksi tetap terenkripsi
        $candidates = [
            '/etc/pki/tls/certs/ca-bundle.crt',      // Amazon Linux (Vercel)
            '/etc/ssl/certs/ca-certificates.crt',    // Debian / Ubuntu
            '/etc/ssl/cert.pem',                     // macOS / Alpine
            ini_get('openssl.cafile') ?: '',
            ini_get('curl.cainfo') ?: '',            // XAMPP biasanya mengisi ini
        ];
        foreach ($candidates as $candidate) {
            if ($candidate && is_file($candidate)) {
                $options[$sslAttrCa] = $candidate;
                break;
            }
        }
        $options[$sslAttrVerify] = false;

        return $options;
    }
}

// ---------------------------------------------------------------
// Membuat koneksi database ($pdo). Dibuat sekali per request;
// "if (!isset($pdo))" mencegah koneksi ganda bila file ini ter-include 2x.
// ---------------------------------------------------------------
if (!isset($pdo)) {
    $host     = env('DB_HOST');
    $port     = (int) env('DB_PORT', 3306);
    $dbname   = env('DB_NAME', 'defaultdb');
    $user     = env('DB_USER');
    // Password boleh kosong (misal user root bawaan XAMPP)
    $password = (string) env('DB_PASSWORD', '');

    // Hentikan dengan pesan jelas jika Environment Variables database belum diisi
    if (!$host || !$user) {
        http_response_code(500);
        error_log('Konfigurasi database belum lengkap (DB_HOST / DB_USER).');
        die('Konfigurasi database belum diatur. Set DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD di Environment Variables.');
    }

    // DB_SSL=true untuk Aiven (default), false untuk MySQL XAMPP lokal
    $options = opsiPdoMysql(
        filter_var(env('DB_SSL', 'true'), FILTER_VALIDATE_BOOLEAN),
        env('DB_SSL_CA')
    );

    try {
        $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
        // Koneksi persisten: server yang masih "hangat" memakai ulang koneksi SSL ke database
        // sehingga tidak perlu handshake ulang di setiap request. Matikan dengan DB_PERSISTENT=false.
        $options[PDO::ATTR_PERSISTENT] = filter_var(env('DB_PERSISTENT', 'true'), FILTER_VALIDATE_BOOLEAN);
        $pdo = new PDO($dsn, $user, $password, $options);
        // Catatan: tidak ada "SET time_zone" (hemat 1x bolak-balik). Semua tanggal & waktu
        // dibuat oleh PHP (Asia/Jakarta) lalu dikirim ke database sebagai nilai biasa.
    } catch (PDOException $e) {
        http_response_code(500);
        error_log('Koneksi Database Gagal: ' . $e->getMessage());
        die('Koneksi database gagal. Silakan cek konfigurasi atau coba lagi nanti.');
    }
}

// ---------------------------------------------------------------
// Helper umum
// ---------------------------------------------------------------

/**
 * Escape output HTML. WAJIB dipakai setiap menampilkan data dari database/user
 * ke halaman, contoh: <?= e($row['nama_unit']); ?> -> mencegah serangan XSS.
 */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Token CSRF: kode acak per sesi login untuk memastikan form benar-benar dikirim
 * dari halaman aplikasi ini (bukan dari situs lain). Butuh session aktif.
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Input tersembunyi berisi token CSRF; taruh di dalam setiap <form method="POST"> */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** Cek token CSRF dari form yang dikirim. Dipanggil di awal setiap proses POST. */
function csrf_valid(): bool
{
    $token = $_POST['csrf_token'] ?? '';
    return is_string($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Pendaftaran akun publik (register.php). Default DITUTUP; buka dengan ALLOW_REGISTER=true.
 * Pengecualian: jika belum ada user sama sekali (instalasi baru), pendaftaran dibuka
 * agar admin pertama bisa dibuat.
 */
function pendaftaranDibuka(): bool
{
    global $pdo;

    if (filter_var(env('ALLOW_REGISTER', 'false'), FILTER_VALIDATE_BOOLEAN)) {
        return true;
    }
    try {
        return (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() === 0;
    } catch (PDOException $e) {
        return false;
    }
}

/** Validasi format tanggal Y-m-d (contoh 2026-10-01); menolak tanggal mustahil seperti 2026-02-30 */
function tanggal_valid(string $tanggal): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $tanggal);
    return $d && $d->format('Y-m-d') === $tanggal;
}
