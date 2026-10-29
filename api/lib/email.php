<?php
/**
 * Pengiriman pengingat lewat Email (SMTP) memakai library PHPMailer (lib/phpmailer).
 *  - SMTP_HOST           : server SMTP, contoh smtp.gmail.com
 *  - SMTP_PORT           : 587 (STARTTLS, default) atau 465 (SSL)
 *  - SMTP_USER           : username SMTP (Gmail: alamat Gmail lengkap)
 *  - SMTP_PASS           : password SMTP (Gmail: App Password 16 huruf, BUKAN password Gmail biasa)
 *  - EMAIL_PENGIRIM      : alamat pengirim (default = SMTP_USER)
 *  - EMAIL_NAMA_PENGIRIM : nama pengirim yang tampil di kotak masuk (default "Pengingat Deadline")
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
        pastikanTabelTambahan($pdo);
        $alamat = $pdo->query("SELECT email FROM email_penerima WHERE aktif = 1 ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        error_log('Gagal membaca email_penerima: ' . $e->getMessage());
    }

    if (!$alamat) {
        $alamat = preg_split('/[\s,;]+/', (string) env('EMAIL_TARGET', ''));
    }
    return array_values(array_unique(array_filter($alamat, static fn($a) => (bool) filter_var($a, FILTER_VALIDATE_EMAIL))));
}

/** Judul email dari ringkasan unit: 1 unit -> nama unitnya, lebih dari 1 -> jumlah unit */
function subjekEmail(string $ringkasan): string
{
    $unit = array_values(array_filter(array_map('trim', explode("\n", $ringkasan)), static fn($u) => $u !== '' && $u !== '-'));
    if (count($unit) === 1) {
        return 'Pengingat Deadline: ' . $unit[0];
    }
    return 'Pengingat Deadline: ' . ($unit ? count($unit) . ' unit perlu di-update' : 'Aplikasi Pengingat Jadwal');
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
        . '<div style="background:#0f3d34;color:#ffffff;padding:16px 20px;font-size:16px;font-weight:bold;border-bottom:4px solid #f0a818">Pengingat Deadline</div>'
        . '<div style="padding:20px;font-size:14px;line-height:1.6">' . $isi . '</div>'
        . '<div style="padding:12px 20px;border-top:1px solid #e7eeeb;font-size:12px;color:#5c6b66">'
        . 'Email ini dikirim otomatis oleh Aplikasi Pengingat Jadwal. Mohon tidak membalas email ini.</div>'
        . '</div></body></html>';
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
        return ['ok' => false, 'pesan' => 'Belum ada alamat email penerima (tambahkan di dashboard atau isi EMAIL_TARGET).'];
    }

    $mail = new PHPMailer(true);
    try {
        $port = (int) env('SMTP_PORT', 587);
        $mail->setLanguage('id', __DIR__ . '/phpmailer/language/');
        $mail->isSMTP();
        $mail->Host       = (string) env('SMTP_HOST');
        $mail->Port       = $port;
        $mail->SMTPAuth   = true;
        $mail->Username   = (string) env('SMTP_USER');
        $mail->Password   = (string) env('SMTP_PASS');
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
        $mail->setFrom((string) (env('EMAIL_PENGIRIM') ?: env('SMTP_USER')), (string) env('EMAIL_NAMA_PENGIRIM', 'Pengingat Deadline'));
        $mail->isHTML(true);
        $mail->Subject = $subjek;
        $mail->Body    = pesanKeHtmlEmail($pesan, $subjek);
        $mail->AltBody = str_replace('*', '', $pesan);
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
            $alasan = trim(preg_replace('/\s+/', ' ', $mail->ErrorInfo ?: $e->getMessage()));
            $gagal[] = $a;
            error_log("Email gagal ($a): $alasan");
        }
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
