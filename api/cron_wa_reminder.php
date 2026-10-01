<?php
/**
 * CRON PENGINGAT DEADLINE via WhatsApp (dijalankan otomatis, bukan dibuka manual).
 * Dipanggil oleh cron-job.org setiap hari jam 20.00 WIB, contoh URL:
 *   https://<domain-vercel>/cron_wa_reminder.php?key=<CRON_SECRET>
 *
 * Alur:
 *  1. Cek key == CRON_SECRET (agar orang lain tidak bisa memicu kirim WA)
 *  2. Ambil unit yang tanggal_akhir-nya HARI INI atau BESOK dan belum dikirim hari ini
 *  3. Gabungkan semuanya menjadi 1 pesan WA -> kirim ke semua nomor penerima aktif
 *  4. Jika berhasil: tandai pengingat = 'sent' & terakhir_dikirim = hari ini
 *  5. Tampilkan laporan JSON (terlihat di riwayat cron-job.org)
 */
require_once __DIR__ . '/lib/koneksi.php';
require_once __DIR__ . '/lib/fonnte.php';

header('Content-Type: application/json; charset=utf-8');

// Proteksi endpoint: wajib menyertakan key yang sama dengan CRON_SECRET
$cronSecret = env('CRON_SECRET');
$keyDikirim = $_GET['key'] ?? ($_SERVER['HTTP_X_CRON_KEY'] ?? '');
if (!$cronSecret || !is_string($keyDikirim) || !hash_equals($cronSecret, $keyDikirim)) {
    http_response_code(401);
    echo json_encode(['status' => false, 'pesan' => 'Unauthorized']);
    exit;
}

// Tanggal dihitung menurut APP_TIMEZONE (Asia/Jakarta), lihat lib/koneksi.php
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

$laporan = ['status' => true, 'tanggal' => $hari_ini, 'total' => count($rows), 'terkirim' => 0, 'gagal' => []];

if ($rows) {
    // Semua unit digabung dalam 1 pesan agar hemat kuota Fonnte & tidak terlihat spam
    $grup = ['hari_ini' => [], 'besok' => []];
    foreach ($rows as $row) {
        $grup[$row['tanggal_akhir'] === $hari_ini ? 'hari_ini' : 'besok'][] = $row;
    }

    // Fungsi kecil untuk menyusun daftar unit bernomor: "1. *KODE* - Nama  (tgl awal s/d tgl akhir)"
    $baris = static function (array $daftar): string {
        $teks = '';
        foreach ($daftar as $i => $row) {
            $teks .= ($i + 1) . ". *{$row['kode_unit']}* - {$row['nama_unit']}\n"
                . "    " . date('d-m-Y', strtotime($row['tanggal_awal']))
                . " s/d " . date('d-m-Y', strtotime($row['tanggal_akhir'])) . "\n";
        }
        return $teks;
    };

    // Susun isi pesan WA (*teks* = huruf tebal di WhatsApp)
    $pesan  = "*PENGINGAT DEADLINE UNIT*\n\n";
    $pesan .= "Halo Admin, berikut " . count($rows) . " unit yang perlu segera di-update:\n";
    if ($grup['hari_ini']) {
        $pesan .= "\n*HARI INI - " . date('d-m-Y', strtotime($hari_ini)) . "* (" . count($grup['hari_ini']) . " unit)\n";
        $pesan .= $baris($grup['hari_ini']);
    }
    if ($grup['besok']) {
        $pesan .= "\n*H-1 / BESOK - " . date('d-m-Y', strtotime($besok)) . "* (" . count($grup['besok']) . " unit)\n";
        $pesan .= $baris($grup['besok']);
    }
    $pesan .= "\nMohon segera update kembali usernya secepatnya. Terima kasih!";

    // Ringkasan nama unit untuk dicatat di Riwayat Pengiriman WA
    $ringkasan = implode(', ', array_map(static fn($r) => $r['kode_unit'] . ' ' . $r['nama_unit'], $rows));
    $hasil     = kirimWhatsApp($pesan, 'otomatis', $ringkasan);

    // Tandai sudah dikirim agar tidak terkirim ganda hari ini
    if ($hasil['ok']) {
        $ids = array_map(static fn($r) => (int) $r['id'], $rows);
        $pdo->prepare("UPDATE deadline SET pengingat = 'sent', terakhir_dikirim = ? WHERE id IN (" . implode(',', $ids) . ")")
            ->execute([$hari_ini]);
        $laporan['terkirim'] = count($rows);
    } else {
        // Gagal kirim: status tidak diubah, sehingga cron berikutnya akan mencoba lagi
        $laporan['gagal'] = ['alasan' => $hasil['pesan'], 'unit' => $ringkasan];
    }
}

echo json_encode($laporan);
