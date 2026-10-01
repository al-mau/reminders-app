<?php
/**
 * Koneksi database (PDO) + helper konfigurasi.
 *
 * Kredensial TIDAK boleh ditulis di kode. Isi lewat Environment Variables:
 *  - Vercel : Project Settings -> Environment Variables
 *  - Lokal  : file .env di root project (lihat .env.example, jangan di-commit)
 */

if (!function_exists('env')) {
    // Muat file .env (hanya untuk development lokal, misal XAMPP)
    $envFile = dirname(__DIR__) . '/.env';
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

    function env(string $key, $default = null)
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        }
        return ($value === null || $value === '') ? $default : $value;
    }
}

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
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        if (!$pakaiSsl) {
            return $options;
        }

        $sslAttrCa     = defined('Pdo\Mysql::ATTR_SSL_CA') ? Pdo\Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA;
        $sslAttrVerify = defined('Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT')
            ? Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT
            : PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT;

        if ($caFile && !is_file($caFile) && is_file(dirname(__DIR__) . '/' . ltrim($caFile, '/'))) {
            $caFile = dirname(__DIR__) . '/' . ltrim($caFile, '/');
        }

        if ($caFile && is_file($caFile)) {
            $options[$sslAttrCa]     = $caFile;
            $options[$sslAttrVerify] = true;
            return $options;
        }

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

if (!isset($pdo)) {
    $host     = env('DB_HOST');
    $port     = (int) env('DB_PORT', 3306);
    $dbname   = env('DB_NAME', 'defaultdb');
    $user     = env('DB_USER');
    // Password boleh kosong (misal user root bawaan XAMPP)
    $password = (string) env('DB_PASSWORD', '');

    if (!$host || !$user) {
        http_response_code(500);
        error_log('Konfigurasi database belum lengkap (DB_HOST / DB_USER).');
        die('Konfigurasi database belum diatur. Set DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD di Environment Variables.');
    }

    $options = opsiPdoMysql(
        filter_var(env('DB_SSL', 'true'), FILTER_VALIDATE_BOOLEAN),
        env('DB_SSL_CA')
    );

    try {
        $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $password, $options);
        $pdo->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");
    } catch (PDOException $e) {
        http_response_code(500);
        error_log('Koneksi Database Gagal: ' . $e->getMessage());
        die('Koneksi database gagal. Silakan cek konfigurasi atau coba lagi nanti.');
    }
}

// ---------------------------------------------------------------
// Helper umum
// ---------------------------------------------------------------

/** Escape output HTML */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Token CSRF untuk form (butuh session aktif) */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

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

/** Validasi format tanggal Y-m-d */
function tanggal_valid(string $tanggal): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $tanggal);
    return $d && $d->format('Y-m-d') === $tanggal;
}
