<?php
/**
 * Struktur tabel + fungsi bantu riwayat kirim dan pembatasan login.
 * Tabel dibuat otomatis saat pertama kali dibutuhkan (isinya sama dengan database/schema.sql).
 *
 * Isi file ini:
 *  - pastikanSkema()     -> membuat tabel users, hari_jadi, wa_penerima, telegram_penerima,
 *                           email_penerima, kirim_log, login_gagal (jika belum ada)
 *  - RIWAYAT PENGIRIMAN  -> catatPengiriman() mencatat setiap pengiriman WhatsApp/Telegram/Email
 *  - PEMBATASAN LOGIN    -> kunci login 15 menit setelah salah password berkali-kali
 */
require_once __DIR__ . '/koneksi.php';

/**
 * Buat tabel jika belum ada (aman dipanggil berulang kali).
 * Pengecekan hanya sekali per request (static) dan sekali per sesi login ($_SESSION).
 */
function pastikanSkema(PDO $pdo): void
{
    static $sudah = false;
    if ($sudah || !empty($_SESSION['skema_v2'])) {
        return;
    }
    $opsi = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        username       VARCHAR(50)  NOT NULL UNIQUE,
        nama           VARCHAR(100) NOT NULL DEFAULT '',
        password       VARCHAR(255) NOT NULL,
        peran          VARCHAR(20)  NOT NULL DEFAULT 'pemantau',
        dibuat_tanggal DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) $opsi");

    // Data hari jadi: cukup diisi sekali, pengingat berulang setiap tahun pada tanggal & bulan yang sama.
    // tanggal             = tanggal hari jadi (yang dipakai hanya tanggal & bulannya, tahunnya bebas)
    // terakhir_diingatkan = tanggal hari jadi (tahun berjalan) yang pengingatnya sudah terkirim,
    //                       agar pengingat tahun yang sama tidak terkirim dua kali
    $pdo->exec("CREATE TABLE IF NOT EXISTS hari_jadi (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        nama_daerah         VARCHAR(150) NOT NULL UNIQUE,
        tanggal             DATE         NOT NULL,
        terakhir_diingatkan DATE         NULL,
        dibuat_oleh         VARCHAR(50)  NULL,
        dibuat_tanggal      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) $opsi");

    $pdo->exec("CREATE TABLE IF NOT EXISTS wa_penerima (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        nama           VARCHAR(100) NOT NULL,
        nomor          VARCHAR(20)  NOT NULL UNIQUE,
        aktif          TINYINT(1)   NOT NULL DEFAULT 1,
        dibuat_tanggal DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) $opsi");

    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_penerima (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        nama           VARCHAR(100) NOT NULL,
        chat_id        VARCHAR(25)  NOT NULL UNIQUE,
        aktif          TINYINT(1)   NOT NULL DEFAULT 1,
        dibuat_tanggal DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) $opsi");

    $pdo->exec("CREATE TABLE IF NOT EXISTS email_penerima (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        nama           VARCHAR(100) NOT NULL,
        email          VARCHAR(150) NOT NULL UNIQUE,
        aktif          TINYINT(1)   NOT NULL DEFAULT 1,
        dibuat_tanggal DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) $opsi");

    // Riwayat pengiriman: satu baris per saluran per pengiriman
    $pdo->exec("CREATE TABLE IF NOT EXISTS kirim_log (
        id        INT AUTO_INCREMENT PRIMARY KEY,
        waktu     DATETIME     NOT NULL,
        jenis     VARCHAR(20)  NOT NULL,
        saluran   VARCHAR(20)  NOT NULL,
        ringkasan TEXT         NOT NULL,
        penerima  TEXT         NOT NULL,
        berhasil  TINYINT(1)   NOT NULL,
        keterangan VARCHAR(500) NOT NULL DEFAULT '',
        INDEX idx_waktu (waktu)
    ) $opsi");

    $pdo->exec("CREATE TABLE IF NOT EXISTS login_gagal (
        id       INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL,
        ip       VARCHAR(45) NOT NULL,
        waktu    DATETIME    NOT NULL,
        INDEX idx_username (username, waktu),
        INDEX idx_ip (ip, waktu)
    ) $opsi");

    $sudah = true;
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['skema_v2'] = true;
    }
}

/**
 * Alamat IP pengunjung (untuk pembatasan login).
 * Di Vercel semua permintaan datang lewat proxy Vercel, IP asli ada di header X-Forwarded-For
 * yang diisi oleh Vercel. Di XAMPP header itu tidak dipercaya (bisa dipalsukan), pakai REMOTE_ADDR.
 */
function ipKlien(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');
    if (getenv('VERCEL') && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    }
    return substr($ip, 0, 45);
}

/** Potong teks agar muat di kolom database */
function potong(string $teks, int $maks): string
{
    return mb_strlen($teks) > $maks ? mb_substr($teks, 0, $maks - 1) . '…' : $teks;
}

// ---------------------------------------------------------------
// RIWAYAT PENGIRIMAN
// ---------------------------------------------------------------

/** Nama saluran untuk ditampilkan */
function namaSaluran(string $saluran): string
{
    return ['wa' => 'WhatsApp', 'telegram' => 'Telegram', 'email' => 'Email'][$saluran] ?? $saluran;
}

/**
 * Catat hasil pengiriman ke kirim_log (satu baris per saluran).
 * @param string $jenis 'otomatis' (jadwal), 'manual' (tombol di dashboard), atau 'percobaan'
 * @param array  $hasil daftar ['saluran' => 'wa', 'penerima' => '...', 'ok' => bool, 'pesan' => '...']
 */
function catatPengiriman(PDO $pdo, string $jenis, string $ringkasan, array $hasil): void
{
    try {
        $stmt = $pdo->prepare("INSERT INTO kirim_log (waktu, jenis, saluran, ringkasan, penerima, berhasil, keterangan)
                               VALUES (?, ?, ?, ?, ?, ?, ?)");
        foreach ($hasil as $h) {
            $stmt->execute([
                date('Y-m-d H:i:s'), $jenis, $h['saluran'], $ringkasan,
                (string) $h['penerima'], $h['ok'] ? 1 : 0, potong((string) $h['pesan'], 500),
            ]);
        }
    } catch (PDOException $e) {
        error_log('Gagal mencatat riwayat kirim: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------
// PEMBATASAN LOGIN: 5x salah per username atau 20x per IP dalam 15 menit -> dikunci 15 menit
// ---------------------------------------------------------------
const LOGIN_MAKS_USERNAME = 5;
const LOGIN_MAKS_IP       = 20;
const LOGIN_KUNCI_MENIT   = 15;

/** Sisa waktu kunci login (detik); 0 = boleh mencoba login */
function sisaKunciLogin(PDO $pdo, string $username, string $ip): int
{
    $batas = date('Y-m-d H:i:s', time() - LOGIN_KUNCI_MENIT * 60);
    $cek = static function (string $kolom, string $nilai, int $maks) use ($pdo, $batas): int {
        $stmt = $pdo->prepare("SELECT COUNT(*) AS n, MIN(waktu) AS awal FROM (
                                   SELECT waktu FROM login_gagal WHERE $kolom = ? AND waktu > ? ORDER BY waktu DESC LIMIT $maks
                               ) t");
        $stmt->execute([$nilai, $batas]);
        $r = $stmt->fetch();
        if ((int) $r['n'] < $maks) {
            return 0;
        }
        return max(0, strtotime($r['awal']) + LOGIN_KUNCI_MENIT * 60 - time());
    };
    return max($cek('username', $username, LOGIN_MAKS_USERNAME), $cek('ip', $ip, LOGIN_MAKS_IP));
}

function catatLoginGagal(PDO $pdo, string $username, string $ip): void
{
    $pdo->prepare("INSERT INTO login_gagal (username, ip, waktu) VALUES (?, ?, ?)")
        ->execute([substr($username, 0, 50), $ip, date('Y-m-d H:i:s')]);
    // Bersihkan catatan lama agar tabel tidak membengkak
    $pdo->prepare("DELETE FROM login_gagal WHERE waktu < ?")->execute([date('Y-m-d H:i:s', strtotime('-1 day'))]);
}

function hapusLoginGagal(PDO $pdo, string $username, string $ip): void
{
    // Hanya catatan username ini dari IP ini; catatan IP lain tetap dihitung
    $pdo->prepare("DELETE FROM login_gagal WHERE username = ? AND ip = ?")->execute([$username, $ip]);
}
