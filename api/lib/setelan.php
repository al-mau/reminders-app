<?php
/**
 * PENGATURAN YANG BISA DIUBAH ADMIN DARI APLIKASI (menu Pengaturan, pengaturan.php)
 *
 * Token Fonnte, token bot Telegram, SMTP, dan saluran aktif bisa diisi Akses Penuh langsung
 * dari aplikasi, tanpa membuka file .env (XAMPP) atau Redeploy di Vercel.
 *
 *  - setelan('FONNTE_TOKEN')  -> nilai dari menu Pengaturan bila diisi, selain itu dari .env / Vercel.
 *  - Nilai rahasia (token, password SMTP) disimpan terenkripsi AES-256-GCM di tabel `pengaturan`;
 *    kuncinya dari APP_KEY / CRON_SECRET yang hanya ada di server, bukan di database.
 *  - DB_* dan CRON_SECRET / APP_KEY TETAP di .env / Vercel: aplikasi harus terhubung ke database
 *    dulu untuk membaca pengaturan, dan kunci enkripsi tidak boleh ikut tersimpan di database.
 *
 * File ini juga berisi penyimpanan pengaturan umum (ambilPengaturan / simpanPengaturan),
 * yang dipakai juga oleh fitur arsip lampiran (lib/onedrive.php).
 */
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/skema.php';   // potong()

/**
 * Daftar Environment Variable yang bisa diatur dari aplikasi, urut seperti tampil di tabel.
 * rahasia = disimpan terenkripsi dan tidak pernah ditampilkan lagi (hanya 4 karakter terakhir).
 * wajib / sumber = isi popup ringkasan (tombol ? di judul tabel): perlu diisi atau tidak, dan dari mana didapat.
 */
const SETELAN_APP = [
    'FONNTE_TOKEN'        => ['untuk' => 'WhatsApp', 'ket' => 'Token device dari dashboard fonnte.com', 'rahasia' => true,
                              'wajib' => 'Wajib bila pakai WA', 'sumber' => 'fonnte.com → Device → Token'],
    'TELEGRAM_BOT_TOKEN'  => ['untuk' => 'Telegram', 'ket' => 'Token bot dari @BotFather, contoh 123456:ABC-xyz', 'rahasia' => true,
                              'wajib' => 'Wajib bila pakai Telegram', 'sumber' => '@BotFather → /newbot'],
    'SMTP_HOST'           => ['untuk' => 'Email', 'ket' => 'Server SMTP, contoh smtp.gmail.com', 'rahasia' => false,
                              'wajib' => 'Wajib bila pakai email', 'sumber' => 'smtp.gmail.com (Gmail)'],
    'SMTP_PORT'           => ['untuk' => 'Email', 'ket' => '587 (STARTTLS) atau 465 (SSL)', 'rahasia' => false,
                              'wajib' => 'Tidak (bawaan 587)', 'sumber' => '587 atau 465'],
    'SMTP_USER'           => ['untuk' => 'Email', 'ket' => 'Alamat email untuk login SMTP', 'rahasia' => false,
                              'wajib' => 'Wajib bila pakai email', 'sumber' => 'Alamat Gmail pengirim'],
    'SMTP_PASS'           => ['untuk' => 'Email', 'ket' => 'App Password Gmail (16 huruf), bukan password biasa', 'rahasia' => true,
                              'wajib' => 'Wajib bila pakai email', 'sumber' => 'myaccount.google.com/apppasswords'],
    'EMAIL_PENGIRIM'      => ['untuk' => 'Email', 'ket' => 'Alamat pengirim; kosong = sama dengan SMTP_USER', 'rahasia' => false,
                              'wajib' => 'Tidak', 'sumber' => 'Kosongkan (= SMTP_USER)'],
    'EMAIL_NAMA_PENGIRIM' => ['untuk' => 'Email', 'ket' => 'Nama pengirim yang tampil di kotak masuk', 'rahasia' => false,
                              'wajib' => 'Tidak', 'sumber' => 'Bebas, bawaan "Pengingat Deadline"'],
    'NOTIF_VIA'           => ['untuk' => 'Saluran', 'ket' => 'Saluran yang dipakai: wa, telegram, email', 'rahasia' => false,
                              'wajib' => 'Tidak (bawaan WA)', 'sumber' => 'Centang WA / Telegram / Email'],
];

/** Nama baris di tabel pengaturan untuk satu Environment Variable */
function kunciSetelan(string $nama): string
{
    return 'env_' . $nama;
}

/**
 * Nilai pengaturan: dari menu Pengaturan bila diisi, selain itu dari .env / Environment Variables.
 * Dibaca dari database sekali per request. Database tidak bisa dibaca -> tetap memakai .env.
 */
function setelan(string $nama, $default = null)
{
    if (!isset(SETELAN_APP[$nama])) {
        return env($nama, $default);
    }
    $nilai = setelanTersimpan()[$nama]['nilai'] ?? null;
    return ($nilai !== null && $nilai !== '') ? $nilai : env($nama, $default);
}

/**
 * Semua pengaturan yang disimpan dari menu Pengaturan: nama => [nilai, diubah, oleh, rusak].
 * rusak = true bila nilai rahasia tidak bisa dibuka (misalnya CRON_SECRET / APP_KEY diganti).
 */
function setelanTersimpan(bool $muatUlang = false): array
{
    static $cache = null;
    if ($cache !== null && !$muatUlang) {
        return $cache;
    }
    $cache = [];
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) {
        return $cache;
    }
    try {
        $kunci = array_map('kunciSetelan', array_keys(SETELAN_APP));
        $stmt  = $pdo->prepare("SELECT * FROM pengaturan WHERE kunci IN (" . implode(',', array_fill(0, count($kunci), '?')) . ")");
        $stmt->execute($kunci);
        foreach ($stmt->fetchAll() as $baris) {
            $nama = substr($baris['kunci'], 4);
            $nilai = (string) $baris['nilai'];
            $rusak = false;
            if (SETELAN_APP[$nama]['rahasia']) {
                try {
                    $nilai = bukaSandi($nilai);
                } catch (RuntimeException $e) {
                    $nilai = null;
                }
                $rusak = $nilai === null;
            }
            $cache[$nama] = ['nilai' => $nilai, 'diubah' => $baris['diubah'], 'oleh' => $baris['diubah_oleh'] ?? null, 'rusak' => $rusak];
        }
    } catch (PDOException $e) {
        // Tabel belum ada / database bermasalah: aplikasi tetap jalan dengan nilai dari .env
        error_log('Membaca pengaturan aplikasi gagal: ' . $e->getMessage());
    }
    return $cache;
}

/**
 * Periksa nilai baru dari form. @return array{0: ?string, 1: ?string} [nilai bersih, pesan salah]
 * NOTIF_VIA datang sebagai daftar saluran yang dicentang.
 */
function periksaSetelan(string $nama, $masukan): array
{
    if ($nama === 'NOTIF_VIA') {
        $pilih = array_values(array_intersect(['wa', 'telegram', 'email'], (array) $masukan));
        return $pilih ? [implode(',', $pilih), null] : [null, 'Centang minimal satu saluran.'];
    }
    $nilai = trim((string) $masukan);
    if ($nilai === '') {
        return [null, 'Nilai tidak boleh kosong. Untuk memakai nilai dari .env / Vercel lagi, klik "Pakai .env".'];
    }
    if (strlen($nilai) > 255) {
        return [null, 'Nilai terlalu panjang (maksimal 255 karakter).'];
    }
    switch ($nama) {
        case 'FONNTE_TOKEN':
            return preg_match('/^\S+$/', $nilai) ? [$nilai, null] : [null, 'Token Fonnte tidak boleh mengandung spasi.'];
        case 'TELEGRAM_BOT_TOKEN':
            return preg_match('/^\d{5,}:[A-Za-z0-9_-]{20,}$/', $nilai) ? [$nilai, null] : [null, 'Format token bot Telegram: angka, titik dua, lalu kode (contoh 123456:ABC-xyz...).'];
        case 'SMTP_HOST':
            return preg_match('/^[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/', $nilai) ? [strtolower($nilai), null] : [null, 'Server SMTP tidak valid, contoh smtp.gmail.com.'];
        case 'SMTP_PORT':
            return ctype_digit($nilai) && (int) $nilai >= 1 && (int) $nilai <= 65535 ? [(string) (int) $nilai, null] : [null, 'Port harus angka 1–65535, biasanya 587 atau 465.'];
        case 'SMTP_USER':
        case 'EMAIL_PENGIRIM':
            return filter_var($nilai, FILTER_VALIDATE_EMAIL) ? [$nilai, null] : [null, 'Format email tidak valid (contoh: nama@gmail.com).'];
        case 'SMTP_PASS':
            return [preg_replace('/\s+/', '', $nilai), null];   // App Password Gmail ditampilkan berspasi
        case 'EMAIL_NAMA_PENGIRIM':
            return [potong($nilai, 100), null];
    }
    return [null, 'Pengaturan tidak dikenal.'];
}

/** Simpan / hapus (null) satu pengaturan dari menu Pengaturan, dicatat siapa yang mengubah */
function simpanSetelan(PDO $pdo, string $nama, ?string $nilai, string $oleh): void
{
    simpanPengaturan($pdo, kunciSetelan($nama), $nilai, SETELAN_APP[$nama]['rahasia']);
    if ($nilai !== null) {
        $pdo->prepare("UPDATE pengaturan SET diubah_oleh = ? WHERE kunci = ?")->execute([potong($oleh, 50), kunciSetelan($nama)]);
    }
    setelanTersimpan(true);
}

/** Teks status untuk tabel: nilai disamarkan bila rahasia */
function tampilanSetelan(string $nama, string $nilai): string
{
    if (!SETELAN_APP[$nama]['rahasia']) {
        return $nilai;
    }
    return 'tersimpan …' . (strlen($nilai) > 8 ? substr($nilai, -4) : '••••');
}

// -------------------------------------------------------------------
// Penyimpanan pengaturan umum (tabel `pengaturan`), nilai rahasia terenkripsi
// -------------------------------------------------------------------

/** Kunci enkripsi dari APP_KEY / CRON_SECRET (rahasia server, tidak ada di database) */
function kunciRahasia(): string
{
    $rahasia = (string) (env('APP_KEY') ?: env('CRON_SECRET', ''));
    if ($rahasia === '') {
        throw new RuntimeException('Isi APP_KEY atau CRON_SECRET di Environment Variables / file .env agar nilai rahasia bisa disimpan terenkripsi.');
    }
    // Awalan tetap 'pengingat-onedrive|' agar token yang sudah tersimpan tetap bisa dibuka
    return hash('sha256', 'pengingat-onedrive|' . $rahasia, true);
}

function sandikan(string $teks): string
{
    $iv  = random_bytes(12);
    $tag = '';
    $isi = openssl_encrypt($teks, 'aes-256-gcm', kunciRahasia(), OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . $isi);
}

function bukaSandi(string $data): ?string
{
    $raw = base64_decode($data, true);
    if ($raw === false || strlen($raw) < 29) {
        return null;
    }
    $teks = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', kunciRahasia(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $teks === false ? null : $teks;
}

function ambilPengaturan(PDO $pdo, string $kunci, bool $rahasia = false): ?string
{
    $stmt = $pdo->prepare("SELECT nilai FROM pengaturan WHERE kunci = ?");
    $stmt->execute([$kunci]);
    $nilai = $stmt->fetchColumn();
    if ($nilai === false || $nilai === null) {
        return null;
    }
    return $rahasia ? bukaSandi((string) $nilai) : (string) $nilai;
}

function simpanPengaturan(PDO $pdo, string $kunci, ?string $nilai, bool $rahasia = false): void
{
    if ($nilai === null) {
        $pdo->prepare("DELETE FROM pengaturan WHERE kunci = ?")->execute([$kunci]);
        return;
    }
    $pdo->prepare("REPLACE INTO pengaturan (kunci, nilai, diubah) VALUES (?, ?, ?)")
        ->execute([$kunci, $rahasia ? sandikan($nilai) : $nilai, date('Y-m-d H:i:s')]);
}
