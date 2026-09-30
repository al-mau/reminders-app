<?php
/**
 * Helper pengiriman WhatsApp via Fonnte.
 * Token & nomor tujuan diambil dari Environment Variables:
 *  - FONNTE_TOKEN : token device dari dashboard fonnte.com
 *  - WA_TARGET    : nomor WA penerima (boleh diawali 0 / 62, pisahkan koma untuk banyak nomor)
 */
require_once __DIR__ . '/koneksi.php';

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

/**
 * Kirim pesan WhatsApp.
 * @return array{ok: bool, pesan: string}
 */
function kirimWhatsApp(string $pesan): array
{
    $token  = env('FONNTE_TOKEN');
    $target = normalisasiNomorWa((string) env('WA_TARGET', ''));

    if (!$token || $target === '') {
        return ['ok' => false, 'pesan' => 'FONNTE_TOKEN / WA_TARGET belum diatur.'];
    }

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL            => 'https://api.fonnte.com/send',
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
        return ['ok' => true, 'pesan' => 'Terkirim'];
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
