<?php
/**
 * PENGINGAT OTOMATIS H-7 (dijalankan terjadwal, bukan dibuka manual).
 *  - Di XAMPP: dijalankan oleh kirim_pengingat.bat lewat Windows Task Scheduler (tanpa browser)
 *  - Lewat URL (opsional, misal cron-job.org bila aplikasi bisa diakses dari internet):
 *      http://<alamat>/pengingat-hari-jadi/cron_pengingat.php?key=<CRON_SECRET>
 * Aman dijalankan berkali-kali sehari: daerah yang sudah diingatkan tahun ini tidak dikirim ulang.
 * Logika lengkapnya ada di lib/pengingat.php.
 */
require_once __DIR__ . '/lib/koneksi.php';
require_once __DIR__ . '/lib/pengingat.php';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: application/json; charset=utf-8');
    // Lewat browser/URL wajib menyertakan key yang sama dengan CRON_SECRET
    $rahasia = (string) env('CRON_SECRET', '');
    $key     = $_GET['key'] ?? ($_SERVER['HTTP_X_CRON_KEY'] ?? '');
    if (strlen($rahasia) < 16 || !is_string($key) || !hash_equals($rahasia, $key)) {
        http_response_code(401);
        echo json_encode(['status' => false, 'pesan' => 'Unauthorized']);
        exit;
    }
}

$laporan = jalankanPengingat($pdo);
echo json_encode($laporan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($laporan['status'] ? 0 : 1);
