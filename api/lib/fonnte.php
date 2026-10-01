<?php
/**
 * Helper pengiriman WhatsApp via Fonnte.
 *  - FONNTE_TOKEN (Environment Variable) : token device dari dashboard fonnte.com
 *  - Nomor penerima diatur dari dashboard (tabel wa_penerima, bisa lebih dari 1 nomor).
 *    Jika belum ada nomor aktif di dashboard, dipakai WA_TARGET dari Environment Variables.
 */
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/skema.php';

function normalisasiNomorWa(string $nomor): string
{
    $hasil = [];
    foreach (explode(',', $nomor) as $n) {
        $n = preg_replace('/\D/', '', $n);
        if ($n === '') {
            continue;
        }
        if (substr($n, 0, 1) === '0') {
            $n = '62' . substr($n, 1);
        }
        $hasil[] = $n;
    }
    return implode(',', $hasil);
}

/** Validasi 1 nomor WA hasil normalisasi (62xxxxxxxxx, 10-15 digit) */
function nomorWaValid(string $nomor): bool
{
    return (bool) preg_match('/^62\d{8,13}$/', $nomor);
}

/**
 * Daftar nomor penerima aktif: dari dashboard (tabel wa_penerima),
 * atau WA_TARGET jika belum ada nomor aktif.
 */
function daftarNomorPenerima(): array
{
    global $pdo;

    $nomor = [];
    try {
        pastikanTabelTambahan($pdo);
        $nomor = $pdo->query("SELECT nomor FROM wa_penerima WHERE aktif = 1 ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        error_log('Gagal membaca wa_penerima: ' . $e->getMessage());
    }

    if (!$nomor) {
        $env   = normalisasiNomorWa((string) env('WA_TARGET', ''));
        $nomor = $env === '' ? [] : explode(',', $env);
    }

    return array_values(array_unique(array_filter($nomor, 'nomorWaValid')));
}

/**
 * Kirim pesan WhatsApp ke semua nomor penerima aktif, lalu catat di riwayat (wa_log).
 * @param string $jenis     'otomatis' (cron) atau 'manual' (tombol di dashboard)
 * @param string $ringkasan Daftar unit yang diingatkan, untuk riwayat
 * @return array{ok: bool, pesan: string}
 */
function kirimWhatsApp(string $pesan, string $jenis = 'manual', string $ringkasan = ''): array
{
    global $pdo;

    $nomor  = daftarNomorPenerima();
    $target = implode(',', $nomor);
    $hasil  = kirimKeFonnte($pesan, $target);

    catatWa($pdo, $jenis, $ringkasan !== '' ? $ringkasan : '-', $target !== '' ? $target : '-', $hasil['ok'], $hasil['pesan']);

    return $hasil;
}

/** @return array{ok: bool, pesan: string} */
function kirimKeFonnte(string $pesan, string $target): array
{
    $token = env('FONNTE_TOKEN');

    if (!$token) {
        return ['ok' => false, 'pesan' => 'FONNTE_TOKEN belum diatur.'];
    }
    if ($target === '') {
        return ['ok' => false, 'pesan' => 'Belum ada nomor penerima WA yang aktif. Tambahkan di dashboard.'];
    }

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL            => env('FONNTE_URL', 'https://api.fonnte.com/send'),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_POSTFIELDS     => [
            'target'      => $target,
            'message'     => $pesan,
            'countryCode' => '62',
        ],
        CURLOPT_HTTPHEADER     => ['Authorization: ' . $token],
    ]);

    $response = curl_exec($curl);
    $error    = curl_error($curl);
    curl_close($curl);

    if ($response === false) {
        error_log('Fonnte cURL error: ' . $error);
        return ['ok' => false, 'pesan' => 'Gagal menghubungi server Fonnte.'];
    }

    $res = json_decode($response, true);
    if (!empty($res['status'])) {
        return ['ok' => true, 'pesan' => 'Terkirim ke ' . count(explode(',', $target)) . ' nomor'];
    }

    $alasan = is_array($res) ? ($res['reason'] ?? $res['detail'] ?? 'Tidak diketahui') : 'Respon tidak valid';
    error_log('Fonnte gagal: ' . $response);
    return ['ok' => false, 'pesan' => (string) $alasan];
}

/** Hitung selisih hari dari hari ini ke tanggal target (negatif = sudah lewat) */
function hitungSisaHari(?string $tanggal_akhir): int
{
    if (empty($tanggal_akhir)) {
        return 0;
    }

    $today  = new DateTime('today');
    $target = new DateTime($tanggal_akhir);
    $target->setTime(0, 0, 0);

    $diff = $today->diff($target);

    return ($diff->invert == 1) ? -$diff->days : $diff->days;
}
