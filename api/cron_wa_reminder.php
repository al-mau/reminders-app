<?php
/**
 * Cron pengingat deadline via WhatsApp.
 * Dipanggil oleh cron-job.org, contoh URL:
 *   https://<domain-vercel>/cron_wa_reminder.php?key=<CRON_SECRET>
 */
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/fonnte.php';

header('Content-Type: application/json; charset=utf-8');

// Proteksi endpoint: wajib menyertakan key yang sama dengan CRON_SECRET
$cronSecret = env('CRON_SECRET');
$keyDikirim = $_GET['key'] ?? ($_SERVER['HTTP_X_CRON_KEY'] ?? '');
if (!$cronSecret || !is_string($keyDikirim) || !hash_equals($cronSecret, $keyDikirim)) {
    http_response_code(401);
    echo json_encode(['status' => false, 'pesan' => 'Unauthorized']);
    exit;
}

$hari_ini = date('Y-m-d');
$besok    = date('Y-m-d', strtotime('+1 day'));

// CARI DATA: Tanggal akhir BESOK atau HARI INI, DAN belum dikirim HARI INI
$stmt = $pdo->prepare(
    "SELECT * FROM deadline
     WHERE tanggal_akhir IN (:besok, :hari_ini)
       AND (terakhir_dikirim IS NULL OR terakhir_dikirim <> :hari_ini2)"
);
$stmt->execute([':besok' => $besok, ':hari_ini' => $hari_ini, ':hari_ini2' => $hari_ini]);
$rows = $stmt->fetchAll();

$update  = $pdo->prepare("UPDATE deadline SET pengingat = 'sent', terakhir_dikirim = ? WHERE id = ?");
$laporan = ['status' => true, 'tanggal' => $hari_ini, 'total' => count($rows), 'terkirim' => 0, 'gagal' => []];

foreach ($rows as $row) {
    $tgl_awal  = date('d-m-Y', strtotime($row['tanggal_awal']));
    $tgl_akhir = date('d-m-Y', strtotime($row['tanggal_akhir']));

    if ($row['tanggal_akhir'] === $hari_ini) {
        $status_label = "*PENGINGAT DEADLINE (HARI INI)*";
        $keterangan   = "memasuki batas waktu *HARI INI*";
    } else {
        $status_label = "*PENGINGAT DEADLINE (H-1)*";
        $keterangan   = "memasuki batas waktu *H-1 (BESOK)*";
    }

    $pesan  = "$status_label\n\n";
    $pesan .= "Halo Admin, unit berikut $keterangan:\n\n";
    $pesan .= "*Kode Unit:* {$row['kode_unit']}\n";
    $pesan .= "*Nama Unit:* {$row['nama_unit']}\n";
    $pesan .= "*Tanggal Awal:* $tgl_awal\n";
    $pesan .= "*Tanggal Akhir:* $tgl_akhir\n\n";
    $pesan .= "Mohon segera update kembali usernya secepatnya. Terima kasih!";

    $hasil = kirimWhatsApp($pesan);

    // Tandai sudah dikirim agar tidak terkirim ganda hari ini
    if ($hasil['ok']) {
        $update->execute([$hari_ini, $row['id']]);
        $laporan['terkirim']++;
    } else {
        $laporan['gagal'][] = ['id' => (int) $row['id'], 'alasan' => $hasil['pesan']];
    }
}

echo json_encode($laporan);
