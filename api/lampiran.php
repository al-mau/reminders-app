<?php
/**
 * Unduh / lihat lampiran dokumen. Hanya untuk user yang sudah login.
 *   lampiran.php?id=12          -> unduh
 *   lampiran.php?id=12&lihat=1  -> buka di browser (hanya PDF & gambar)
 */
require_once __DIR__ . '/lib/koneksi.php';
require_once __DIR__ . '/lib/session_handler.php';
require_once __DIR__ . '/lib/skema.php';

if (empty($_SESSION['login'])) {
    header("Location: login.php");
    exit;
}

pastikanTabelTambahan($pdo);

$stmt = $pdo->prepare("SELECT nama_file, ekstensi, ukuran, isi FROM lampiran WHERE id = ?");
$stmt->execute([(int) ($_GET['id'] ?? 0)]);
$file = $stmt->fetch();

if (!$file) {
    http_response_code(404);
    exit('Lampiran tidak ditemukan.');
}

$ext         = strtolower($file['ekstensi']);
$bisaDilihat = in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true);
$inline      = !empty($_GET['lihat']) && $bisaDilihat;

// Nama file aman untuk header (ASCII fallback + UTF-8)
$namaAscii = preg_replace('/[^A-Za-z0-9._ -]/', '_', $file['nama_file']);

header('Content-Type: ' . (LAMPIRAN_TIPE[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . strlen($file['isi']));
header(sprintf(
    'Content-Disposition: %s; filename="%s"; filename*=UTF-8\'\'%s',
    $inline ? 'inline' : 'attachment',
    $namaAscii,
    rawurlencode($file['nama_file'])
));
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: sandbox');
header('Cache-Control: private, no-store');

echo $file['isi'];
