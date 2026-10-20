<?php
/**
 * PENGINGAT OTOMATIS VERSI XAMPP (pengganti cron-job.org).
 * Dijalankan oleh kirim_pengingat.bat lewat PHP XAMPP (bukan dibuka di browser).
 * Isinya sama persis dengan cron_wa_reminder.php; kunci CRON_SECRET diambil otomatis dari .env.
 * Argumen opsional (jenis pengingat): hari_ini (jam 08.00) atau besok (jam 20.00),
 * contoh: php cron_lokal.php hari_ini   -> tanpa argumen = keduanya sekaligus.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/lib/koneksi.php';

$_GET['key'] = (string) env('CRON_SECRET', '');
$_GET['jenis'] = (string) ($argv[1] ?? '');
require __DIR__ . '/cron_wa_reminder.php';
echo PHP_EOL;
