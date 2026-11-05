<?php
/**
 * Pengiriman pengingat lewat Email (SMTP) memakai library PHPMailer (lib/phpmailer).
 *  - SMTP_HOST           : server SMTP, contoh smtp.gmail.com
 *  - SMTP_PORT           : 587 (STARTTLS, default) atau 465 (SSL)
 *  - SMTP_USER           : username SMTP (Gmail: alamat Gmail lengkap)
 *  - SMTP_PASS           : password SMTP (Gmail: App Password 16 huruf, BUKAN password Gmail biasa)
 *  - EMAIL_PENGIRIM      : alamat pengirim (default = SMTP_USER)
 *  - EMAIL_NAMA_PENGIRIM : nama pengirim yang tampil di kotak masuk (default "Pengingat Hari Jadi")
 *  - Alamat penerima diatur dari dashboard (tabel email_penerima).
 *    Jika belum ada alamat aktif di dashboard, dipakai EMAIL_TARGET dari Environment Variables.
 */
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/skema.php';
require_once __DIR__ . '/phpmailer/Exception.php';
require_once __DIR__ . '/phpmailer/PHPMailer.php';
require_once __DIR__ . '/phpmailer/SMTP.php';

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

/** Pengaturan SMTP sudah lengkap (host, user, password)? */
function emailSiap(): bool
{
    return env('SMTP_HOST') && env('SMTP_USER') && env('SMTP_PASS');
}

/** Alamat tanpa "@" dianggap akun Gmail: "nama" -> "nama@gmail.com" */
function lengkapiEmail(string $email): string
{
    $email = trim($email);
    return ($email !== '' && strpos($email, '@') === false) ? $email . '@gmail.com' : $email;
}

/**
 * Periksa alamat email yang diketik admin saat menambah/mengedit penerima.
 * @return array{0: ?string, 1: ?string} [alamat, null] jika valid, atau [null, pesan kesalahan]
 */
function periksaEmailBaru(string $input): array
{
    $email = strtolower(lengkapiEmail($input));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
        return [null, 'Format email tidak valid (contoh: nama@gmail.com).'];
    }
    return [$email, null];
}

/**
 * Daftar alamat email penerima aktif: dari dashboard (tabel email_penerima),
 * atau EMAIL_TARGET jika belum ada alamat aktif (sama seperti nomor WA & WA_TARGET).
 */
function daftarEmailPenerima(): array
{
    global $pdo;

    $alamat = [];
    try {
        pastikanSkema($pdo);
        $alamat = $pdo->query("SELECT email FROM email_penerima WHERE aktif = 1 ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        error_log('Gagal membaca email_penerima: ' . $e->getMessage());
    }

    if (!$alamat) {
        $alamat = preg_split('/[\s,;]+/', (string) env('EMAIL_TARGET', ''));
    }
    return array_values(array_unique(array_filter($alamat, static fn($a) => (bool) filter_var($a, FILTER_VALIDATE_EMAIL))));
}

/** Judul email dari ringkasan daerah: 1 daerah -> nama daerahnya, lebih dari 1 -> jumlah daerah */
function subjekEmail(string $ringkasan): string
{
    $daerah = array_values(array_filter(array_map('trim', explode("\n", $ringkasan)), static fn($d) => $d !== '' && $d !== '-'));
    if (count($daerah) === 1) {
        return 'Pengingat Hari Jadi: ' . $daerah[0];
    }
    return 'Pengingat Hari Jadi: ' . ($daerah ? count($daerah) . ' daerah' : 'Aplikasi Pengingat Hari Jadi');
}

/**
 * Ubah pesan format WhatsApp (*tebal*, baris baru) menjadi email HTML berwarna aplikasi.
 * Gaya ditulis inline karena banyak aplikasi email mengabaikan tag <style>.
 */
function pesanKeHtmlEmail(string $pesan, string $judul): string
{
    $isi   = nl2br(pesanKeHtmlTelegram($pesan), false);
    $judul = htmlspecialchars($judul, ENT_QUOTES, 'UTF-8');

    return '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>' . $judul . '</title></head>'
        . '<body style="margin:0;padding:24px 12px;background:#f1f5f3;font-family:Arial,Helvetica,sans-serif;color:#1c2b27">'
        . '<div style="max-width:560px;margin:0 auto;background:#ffffff;border:1px solid #d8e2de;border-radius:6px;overflow:hidden">'
        . '<div style="background:#0f3d34;color:#ffffff;padding:16px 20px;font-size:16px;font-weight:bold;border-bottom:4px solid #f0a818">Pengingat Hari Jadi</div>'
        . '<div style="padding:20px;font-size:14px;line-height:1.6">' . $isi . '</div>'
        . '<div style="padding:12px 20px;border-top:1px solid #e7eeeb;font-size:12px;color:#5c6b66">'
        . 'Email ini dikirim otomatis oleh Aplikasi Pengingat Hari Jadi. Mohon tidak membalas email ini.</div>'
        . '</div></body></html>';
}

/**
 * Password SMTP dari pengaturan. App Password Gmail ditampilkan Google berkelompok
 * ("abcd efgh ijkl mnop") dan sering tersalin bersama spasi -> untuk Gmail semua spasi dibuang.
 */
function passwordSmtp(): string
{
    $pass = trim((string) env('SMTP_PASS'));
    if (stripos((string) env('SMTP_HOST'), 'gmail.com') !== false) {
        $pass = preg_replace('/[\s\x{00A0}]+/u', '', $pass);
    }
    return $pass;
}

/**
 * Ubah balasan penolakan dari server SMTP menjadi pesan yang jelas untuk admin.
 * $balasan = baris balasan server berkode 4xx/5xx, contoh "535-5.7.8 Username and Password not accepted".
 */
function jelaskanGagalSmtp(string $balasan, string $cadangan): string
{
    $asli = $balasan !== '' ? ' (balasan server: ' . potong($balasan, 160) . ')' : '';
    if (preg_match('/\b535\b|BadCredentials|Username and Password not accepted/i', $balasan)) {
        return 'Login email ditolak: SMTP_USER atau SMTP_PASS salah. Untuk Gmail, SMTP_PASS harus App Password 16 huruf '
            . '(bukan password Gmail biasa) yang dibuat dari akun SMTP_USER yang sama.' . $asli;
    }
    if (preg_match('/\b534\b|Application-specific password required|WebLoginRequired/i', $balasan)) {
        return 'Gmail meminta App Password: aktifkan Verifikasi 2 Langkah, buat App Password, lalu isi di SMTP_PASS.' . $asli;
    }
    return $balasan !== '' ? $cadangan . $asli : $cadangan;
}

/**
 * Kirim pesan ke setiap alamat email lewat SMTP (satu email per penerima, satu koneksi untuk semua).
 * Berhasil jika minimal satu alamat menerima.
 * @return array{ok: bool, pesan: string}
 */
function kirimKeEmail(string $pesan, array $alamat, string $subjek): array
{
    if (!emailSiap()) {
        return ['ok' => false, 'pesan' => 'SMTP_HOST, SMTP_USER, atau SMTP_PASS belum diatur.'];
    }
    if (!$alamat) {
        return ['ok' => false, 'pesan' => 'Belum ada alamat email penerima (tambahkan di menu Penerima atau isi EMAIL_TARGET).'];
    }

    $mail = new PHPMailer(true);
    try {
        $port = (int) env('SMTP_PORT', 587);
        $mail->setLanguage('id', __DIR__ . '/phpmailer/language/');
        $mail->isSMTP();
        $mail->Host       = (string) env('SMTP_HOST');
        $mail->Port       = $port;
        $mail->SMTPAuth   = true;
        $mail->Username   = trim((string) env('SMTP_USER'));
        $mail->Password   = passwordSmtp();
        // Port 465 = SSL sejak awal; port lain (587) = STARTTLS
        $mail->SMTPSecure = $port === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        // Batas waktu agar halaman/cron tidak menggantung bila server SMTP lambat
        $mail->Timeout       = 15;
        $mail->SMTPKeepAlive = true;
        $mail->CharSet       = PHPMailer::CHARSET_UTF8;
        // XAMPP / Laragon / Herd (Windows) kadang belum mengatur sertifikat HTTPS untuk PHP
        $ca = ini_get('openssl.cafile') ? '' : fileSertifikatCa();
        if ($ca !== '') {
            $mail->SMTPOptions = ['ssl' => ['cafile' => $ca]];
        }
        $mail->setFrom((string) (env('EMAIL_PENGIRIM') ?: env('SMTP_USER')), (string) env('EMAIL_NAMA_PENGIRIM', 'Pengingat Hari Jadi'));
        $mail->isHTML(true);
        $mail->Subject = $subjek;
        $mail->Body    = pesanKeHtmlEmail($pesan, $subjek);
        $mail->AltBody = str_replace('*', '', $pesan);
        // Simpan balasan penolakan server (kode 4xx/5xx) agar alasan aslinya bisa ditampilkan.
        // Hanya baris dari SERVER yang disimpan, jadi password yang dikirim aplikasi tidak ikut tercatat.
        $balasanServer = [];
        $mail->SMTPDebug   = SMTP::DEBUG_SERVER;
        $mail->Debugoutput = static function (string $baris) use (&$balasanServer): void {
            if (preg_match('/^SERVER -> CLIENT: ([45]\d\d[ -].*)$/s', trim($baris), $m)) {
                $balasanServer[] = trim(preg_replace('/\s+/', ' ', $m[1]));
            }
        };
    } catch (PHPMailerException $e) {
        return ['ok' => false, 'pesan' => 'Pengaturan email tidak valid: ' . $e->getMessage()];
    }

    $berhasil = 0;
    $alasan   = '';
    $gagal    = [];
    foreach ($alamat as $a) {
        try {
            $mail->clearAddresses();
            $mail->addAddress($a);
            $mail->send();
            $berhasil++;
        } catch (PHPMailerException $e) {
            // Pesan error PHPMailer bisa beberapa baris -> jadikan satu baris untuk riwayat
            $umum   = trim(preg_replace('/\s+/', ' ', $mail->ErrorInfo ?: $e->getMessage()));
            $alasan = jelaskanGagalSmtp(implode(' ', array_slice($balasanServer, 0, 2)), $umum);
            $gagal[] = $a;
            error_log("Email gagal ($a): $alasan");
            // Login ditolak: alamat berikutnya pasti gagal juga, tidak perlu dicoba
            if (stripos($alasan, 'Login email ditolak') === 0 || stripos($alasan, 'Gmail meminta') === 0) {
                break;
            }
        }
        $balasanServer = [];
    }
    $mail->smtpClose();

    if ($berhasil === 0) {
        return ['ok' => false, 'pesan' => $alasan];
    }
    // Sebagian gagal: cukup sebut alamatnya (penyebab lengkap ada di log server)
    $pesanHasil = "Terkirim ke $berhasil alamat email";
    if ($gagal) {
        $pesanHasil .= ', gagal ke ' . implode(', ', $gagal);
    }
    return ['ok' => true, 'pesan' => $pesanHasil];
}
