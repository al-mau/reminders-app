<?php
/**
 * FILE INTI: koneksi database (PDO) + fungsi bantu yang dipakai SEMUA halaman.
 * Di-include paling awal oleh setiap halaman (require_once).
 *
 * Isi file ini (urut dari atas):
 *  1. env()          -> membaca pengaturan dari Environment Variables (Vercel) atau file .env (XAMPP)
 *  2. Zona waktu & mode error
 *  3. opsiPdoMysql()  -> opsi koneksi MySQL + SSL (Aiven & TiDB Cloud wajib SSL)
 *  4. $pdo           -> objek koneksi database yang dipakai di semua halaman
 *  5. Helper umum    -> e(), csrf_*(), tanggal_valid(), tombol_mata()
 *
 * Kredensial TIDAK boleh ditulis di kode. Isi lewat:
 *  - Online (Vercel): Project Settings -> Environment Variables
 *  - XAMPP          : file .env di folder aplikasi (jangan di-commit)
 */

if (!function_exists('env')) {
    // Muat file .env (versi XAMPP). Di Vercel file .env tidak ada; nilainya dari Environment Variables.
    // Nilai yang sudah terisi di Environment Variables tidak ditimpa.
    // Dicari di folder utama: versi XAMPP (lib/ -> ../.env) maupun susunan GitHub (api/lib/ -> ../../.env).
    $envFile = is_readable(dirname(__DIR__) . '/.env') ? dirname(__DIR__) . '/.env' : dirname(__DIR__, 2) . '/.env';
    if (is_readable($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            $value = trim($value, "\"'");
            // Baris kosong (contoh "FONNTE_TOKEN=") tidak menghalangi baris berisi di bawahnya
            if (getenv($key) === false || getenv($key) === '') {
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

// Zona waktu semua fungsi tanggal PHP. Penting untuk pengingat:
// "hari ini" dan "7 hari lagi" dihitung menurut jam Indonesia (WIB).
date_default_timezone_set(env('APP_TIMEZONE', 'Asia/Jakarta'));

// Jangan tampilkan warning/error PHP ke pengunjung (tetap dicatat di log Apache).
// Set APP_DEBUG=true di .env bila ingin melihat error saat development.
if (!filter_var(env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN)) {
    ini_set('display_errors', '0');
}
ini_set('log_errors', '1');

if (!function_exists('opsiPdoMysql')) {
    /**
     * Opsi PDO standar + SSL (wajib untuk Aiven & TiDB Cloud).
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

        // PHP 8.4 memakai konstanta baru Pdo\Mysql::*, versi lama memakai PDO::MYSQL_*
        $sslAttrCa     = defined('Pdo\Mysql::ATTR_SSL_CA') ? Pdo\Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA;
        $sslAttrVerify = defined('Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT')
            ? Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT
            : PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT;

        // DB_SSL_CA boleh ditulis relatif terhadap folder project (misal "ca.pem")
        foreach ([dirname(__DIR__), dirname(__DIR__, 2)] as $dasar) {
            if ($caFile && !is_file($caFile) && is_file($dasar . '/' . ltrim($caFile, '/'))) {
                $caFile = $dasar . '/' . ltrim($caFile, '/');
            }
        }
        if ($caFile && is_file($caFile)) {
            $options[$sslAttrCa]     = $caFile;
            $options[$sslAttrVerify] = true;
            return $options;
        }

        // Tidak ada ca.pem -> cari CA bundle bawaan sistem agar koneksi tetap terenkripsi
        foreach ([
            '/etc/pki/tls/certs/ca-bundle.crt',      // Amazon Linux (Vercel)
            '/etc/ssl/certs/ca-certificates.crt',    // Debian / Ubuntu
            '/etc/ssl/cert.pem',                     // macOS / Alpine
            ini_get('openssl.cafile') ?: '',
            ini_get('curl.cainfo') ?: '',            // XAMPP biasanya mengisi ini
        ] as $kandidat) {
            if ($kandidat && is_file($kandidat)) {
                $options[$sslAttrCa] = $kandidat;
                break;
            }
        }
        $options[$sslAttrVerify] = false;
        return $options;
    }
}

// ---------------------------------------------------------------
// Koneksi database ($pdo). Default sesuai MySQL bawaan XAMPP (root tanpa password).
// "if (!isset($pdo))" mencegah koneksi ganda bila file ini ter-include 2x.
// ---------------------------------------------------------------
if (!isset($pdo)) {
    $host     = env('DB_HOST', '127.0.0.1');
    $port     = (int) env('DB_PORT', 3306);
    $dbname   = env('DB_NAME', 'pengingat_hari_jadi');
    $user     = env('DB_USER', 'root');
    $password = (string) env('DB_PASSWORD', '');

    // SSL: default aktif untuk database online (Aiven / TiDB Cloud), mati untuk MySQL lokal (localhost / 127.0.0.1)
    $lokal   = in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
    $options = opsiPdoMysql(filter_var(env('DB_SSL', $lokal ? 'false' : 'true'), FILTER_VALIDATE_BOOLEAN), env('DB_SSL_CA'));
    // Koneksi persisten: server Vercel yang masih "hangat" memakai ulang koneksi SSL (lebih cepat)
    $options[PDO::ATTR_PERSISTENT] = filter_var(env('DB_PERSISTENT', $lokal ? 'false' : 'true'), FILTER_VALIDATE_BOOLEAN);

    try {
        $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4", $user, $password, $options);
    } catch (PDOException $e) {
        http_response_code(500);
        error_log('Koneksi Database Gagal: ' . $e->getMessage());
        $pesan = $lokal
            ? 'Koneksi database gagal. Pastikan MySQL di XAMPP Control Panel sudah START dan database "' . $dbname
                . '" sudah dibuat (jalankan database/import_database.bat).'
            : 'Koneksi database gagal. Periksa DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD di Environment Variables '
                . 'dan pastikan database "' . $dbname . '" sudah dibuat (Aiven / TiDB Cloud).';
        die(PHP_SAPI === 'cli' ? json_encode(['status' => false, 'pesan' => $pesan]) . PHP_EOL : htmlspecialchars($pesan));
    }
}

// ---------------------------------------------------------------
// Helper umum
// ---------------------------------------------------------------

/** Escape output HTML. WAJIB dipakai setiap menampilkan data ke halaman (mencegah XSS). */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Token CSRF: kode acak per sesi untuk memastikan form dikirim dari aplikasi ini. Butuh session aktif. */
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

/** Validasi format tanggal Y-m-d (contoh 1784-06-23); menolak tanggal mustahil seperti 2026-02-30 */
function tanggal_valid(string $tanggal): bool
{
    $d = DateTime::createFromFormat('!Y-m-d', $tanggal);
    return $d && $d->format('Y-m-d') === $tanggal;
}

/** Ambil teks dari $_POST, dirapikan (spasi ganda dibuang) dan dipotong maksimal $maks karakter */
function input_teks(string $nama, int $maks = 150): string
{
    $teks = trim(preg_replace('/\s+/u', ' ', (string) ($_POST[$nama] ?? '')));
    return mb_substr($teks, 0, $maks);
}

/**
 * Tombol mata di samping kolom password (taruh di dalam .input-group setelah <input type="password">).
 * Klik untuk menampilkan isi password. Butuh skrip_mata() di halaman.
 */
function tombol_mata(string $kelas = 'btn-outline-secondary'): string
{
    return '<button type="button" class="btn ' . e($kelas) . '" data-mata aria-label="Tampilkan password" aria-pressed="false" title="Tampilkan password">'
        . '<i class="fa-solid fa-eye"></i></button>';
}

/** Skrip tombol mata (sekali per halaman, sebelum </body>) */
function skrip_mata(): string
{
    return <<<'HTML'
<script>
(() => {
    const atur = (tombol, lihat) => {
        const input = tombol.parentElement.querySelector('input');
        input.type = lihat ? 'text' : 'password';
        tombol.querySelector('i').className = 'fa-solid ' + (lihat ? 'fa-eye-slash' : 'fa-eye');
        tombol.title = lihat ? 'Sembunyikan password' : 'Tampilkan password';
        tombol.setAttribute('aria-label', tombol.title);
        tombol.setAttribute('aria-pressed', lihat ? 'true' : 'false');
    };
    const sembunyikan = (wadah) => wadah.querySelectorAll('[data-mata]').forEach((t) => atur(t, false));
    document.addEventListener('click', (ev) => {
        const tombol = ev.target.closest('[data-mata]');
        if (!tombol) return;
        const input = tombol.parentElement.querySelector('input');
        atur(tombol, input.type === 'password');
        input.focus();
    });
    document.addEventListener('submit', (ev) => sembunyikan(ev.target), true);
    document.addEventListener('hidden.bs.modal', (ev) => sembunyikan(ev.target));
})();
</script>
HTML;
}
