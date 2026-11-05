<?php
/**
 * PENGINGAT OTOMATIS H-7 (dipakai oleh cron_pengingat.php dan tombol "Jalankan pengingat" di dashboard).
 *
 * Alur jalankanPengingat():
 *  1. Ambil semua data hari jadi, hitung hari jadi berikutnya (berulang setiap tahun)
 *  2. Pilih yang tinggal 0..7 hari lagi DAN pengingat tahun itu belum terkirim
 *     (rentang 0..7, bukan tepat 7, agar pengingat tetap terkirim walau laptop mati di hari H-7)
 *  3. Gabungkan menjadi 1 pesan -> kirim ke semua saluran aktif (WhatsApp, Telegram, Email)
 *  4. Jika berhasil: simpan tanggal hari jadi itu di terakhir_diingatkan -> tidak dikirim ulang tahun ini,
 *     tahun depan otomatis terkirim lagi karena tanggal hari jadinya berbeda
 */
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/skema.php';
require_once __DIR__ . '/hari_jadi.php';
require_once __DIR__ . '/notifikasi.php';

/**
 * Susun isi pesan (format WhatsApp: *teks* = tebal; otomatis diubah untuk Telegram & Email).
 * @param array $daftar baris hari_jadi yang sudah ditambah kunci 'info' (hasil infoHariJadi)
 */
function susunPesanHariJadi(array $daftar): string
{
    $pesan  = "*PENGINGAT HARI JADI DAERAH PEMEGANG SAHAM*\n";
    $pesan .= count($daftar) === 1
        ? "Halo, berikut hari jadi daerah pemegang saham yang akan datang:\n"
        : 'Halo, berikut ' . count($daftar) . " hari jadi daerah pemegang saham yang akan datang:\n";

    foreach ($daftar as $row) {
        $info   = $row['info'];
        $pesan .= "\n*Daerah:* " . $row['nama_daerah'] . "\n";
        $pesan .= '*Hari Jadi:* ' . tanggalIndonesia($info['berikutnya']) . "\n";
        $pesan .= '*Sisa Waktu:* ' . keteranganSisaHari($info['sisa']) . "\n";
    }

    return $pesan . "\nMohon dipersiapkan ucapan selamat hari jadi untuk daerah di atas.";
}

/** Ringkasan untuk riwayat & judul email: satu daerah per baris, contoh "Kota Pekanbaru (23 Juni)" */
function ringkasanHariJadi(array $daftar): string
{
    return implode("\n", array_map(static fn($r) => $r['nama_daerah'] . ' (' . tanggalIndonesia($r['info']['berikutnya'], false) . ')', $daftar));
}

/**
 * Cari data yang perlu diingatkan hari ini lalu kirim. Aman dijalankan berkali-kali sehari:
 * data yang sudah terkirim tidak dikirim ulang.
 * @return array laporan untuk ditampilkan (JSON di cron / pesan di dashboard)
 */
function jalankanPengingat(PDO $pdo): array
{
    pastikanSkema($pdo);

    $hariIni = new DateTimeImmutable('today');
    $batas   = hariPengingat();
    $perlu   = [];

    foreach ($pdo->query("SELECT * FROM hari_jadi") as $row) {
        $row['info'] = infoHariJadi($row, $hariIni);
        if ($row['info']['sisa'] <= $batas && !$row['info']['sudah_diingatkan']) {
            $perlu[] = $row;
        }
    }

    $laporan = ['status' => true, 'tanggal' => $hariIni->format('Y-m-d'), 'h_min' => $batas, 'total' => count($perlu), 'terkirim' => 0];
    if (!$perlu) {
        return $laporan;
    }

    // Terdekat dulu, lalu menurut nama daerah
    usort($perlu, static fn($a, $b) => [$a['info']['sisa'], $a['nama_daerah']] <=> [$b['info']['sisa'], $b['nama_daerah']]);

    $ringkasan = ringkasanHariJadi($perlu);
    $hasil     = kirimNotifikasi(susunPesanHariJadi($perlu), 'otomatis', $ringkasan);
    $laporan['daerah'] = array_column($perlu, 'nama_daerah');
    $laporan['hasil']  = $hasil['pesan'];

    if ($hasil['ok']) {
        // Tandai per baris dengan tanggal hari jadinya (tiap daerah bisa beda tahun: Desember vs Januari)
        $stmt = $pdo->prepare("UPDATE hari_jadi SET terakhir_diingatkan = ? WHERE id = ?");
        foreach ($perlu as $row) {
            $stmt->execute([$row['info']['berikutnya']->format('Y-m-d'), (int) $row['id']]);
        }
        $laporan['terkirim'] = count($perlu);
    } else {
        // Gagal kirim: tanda tidak diubah, sehingga jadwal berikutnya akan mencoba lagi
        $laporan['status'] = false;
    }

    return $laporan;
}
