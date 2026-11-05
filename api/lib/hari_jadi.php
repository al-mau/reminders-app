<?php
/**
 * Logika tanggal hari jadi yang BERULANG SETIAP TAHUN.
 * Data cukup diisi sekali: nama daerah + tanggal hari jadi (yang dipakai hanya tanggal & bulannya).
 * Aplikasi menghitung sendiri kapan hari jadi berikutnya dan berapa hari lagi.
 *
 *  - hariJadiBerikutnya()  -> tanggal hari jadi terdekat mulai hari ini (tahun ini atau tahun depan)
 *  - infoHariJadi()        -> semua keterangan siap tampil untuk satu baris data
 *  - tanggalIndonesia()    -> "Selasa, 23 Juni 2026"
 */

/** Jumlah hari sebelum hari jadi saat pengingat dikirim (default H-7, ubah lewat PENGINGAT_HARI di .env) */
function hariPengingat(): int
{
    return max(1, min(60, (int) env('PENGINGAT_HARI', 7)));
}

const NAMA_BULAN = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
const NAMA_HARI  = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];

/**
 * Tanggal hari jadi pada tahun tertentu. 29 Februari di tahun bukan kabisat -> 28 Februari.
 */
function hariJadiPadaTahun(string $tanggal, int $tahun): DateTimeImmutable
{
    $bulan = (int) substr($tanggal, 5, 2);
    $hari  = (int) substr($tanggal, 8, 2);
    if ($bulan === 2 && $hari === 29 && !checkdate(2, 29, $tahun)) {
        $hari = 28;
    }
    return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $tahun, $bulan, $hari));
}

/** Hari jadi terdekat yang belum lewat (hari ini juga dihitung), contoh hari ini 2026-10-30 & tanggal 2026-06-23 -> 2027-06-23 */
function hariJadiBerikutnya(string $tanggal, ?DateTimeImmutable $hariIni = null): DateTimeImmutable
{
    $hariIni = $hariIni ?? new DateTimeImmutable('today');
    $tahun   = (int) $hariIni->format('Y');
    $hasil   = hariJadiPadaTahun($tanggal, $tahun);
    return $hasil < $hariIni ? hariJadiPadaTahun($tanggal, $tahun + 1) : $hasil;
}

/** "Selasa, 23 Juni 2026" (dengan nama hari) atau "23 Juni 2026" */
function tanggalIndonesia(DateTimeInterface $tgl, bool $denganHari = true): string
{
    $teks = (int) $tgl->format('j') . ' ' . NAMA_BULAN[(int) $tgl->format('n')] . ' ' . $tgl->format('Y');
    return $denganHari ? NAMA_HARI[(int) $tgl->format('w')] . ', ' . $teks : $teks;
}

/** "23 Juni" (tanpa tahun) */
function tanggalBulan(string $tanggal): string
{
    return (int) substr($tanggal, 8, 2) . ' ' . NAMA_BULAN[(int) substr($tanggal, 5, 2)];
}

/** "Hari Ini", "Besok", "7 hari lagi" */
function keteranganSisaHari(int $sisa): string
{
    if ($sisa === 0) {
        return 'Hari Ini';
    }
    return $sisa === 1 ? 'Besok' : "$sisa hari lagi";
}

/**
 * Semua keterangan untuk satu baris data hari_jadi, contoh tanggal 2026-06-23 (hari ini 2027-06-16):
 *  berikutnya = 2027-06-23, sisa = 7, sudah_diingatkan = true/false
 */
function infoHariJadi(array $row, ?DateTimeImmutable $hariIni = null): array
{
    $hariIni    = $hariIni ?? new DateTimeImmutable('today');
    $berikutnya = hariJadiBerikutnya($row['tanggal'], $hariIni);

    return [
        'berikutnya'       => $berikutnya,
        'sisa'             => (int) $hariIni->diff($berikutnya)->days,
        'sudah_diingatkan' => ($row['terakhir_diingatkan'] ?? null) === $berikutnya->format('Y-m-d'),
    ];
}

/**
 * Baca tanggal yang diketik admin. Menerima format input tanggal browser (2026-06-23)
 * maupun format Indonesia (23-06-2026 atau 23/06/2026). Hasil: 'Y-m-d' atau null jika tidak valid.
 */
function bacaTanggal(string $teks): ?string
{
    $teks = trim($teks);
    if (preg_match('#^(\d{1,2})[-/.](\d{1,2})[-/.](\d{4})$#', $teks, $m)) {
        $teks = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    }
    if (!tanggal_valid($teks) || (int) substr($teks, 0, 4) < 1000 || $teks > date('Y-m-d', strtotime('+5 years'))) {
        return null;
    }
    return $teks;
}
