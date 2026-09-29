<?php
require_once __DIR__ . '/koneksi.php';

date_default_timezone_set('Asia/Jakarta');

$hari_ini = date('Y-m-d');
$besok    = date('Y-m-d', strtotime('+1 day'));

// Token dan Nomor WA Tujuan
$token_fonnte = 'pgVks6HbMNA3zbQvh2vr'; #sesuaikan dengan token dari akun fonnte baru kalau token habis ganti dengan yg lain
$target_wa    = '082285755442'; #berdasarkan no wa yg akan menerima pesan wa

if (substr($target_wa, 0, 1) === '0') { 
    $target_wa = '62' . substr($target_wa, 1);
}

// CARI DATA: Tanggal akhir BESOK atau HARI INI, DAN belum dikirim HARI INI
$query  = "SELECT * FROM deadline 
           WHERE (tanggal_akhir = '$besok' OR tanggal_akhir = '$hari_ini') 
           AND (terakhir_dikirim IS NULL OR terakhir_dikirim != '$hari_ini')";

$result = mysqli_query($koneksi, $query);

if ($result && mysqli_num_rows($result) > 0) {
    while ($row = mysqli_fetch_assoc($result)) {
        $id        = $row['id'];
        $kode_unit = $row['kode_unit'];
        $nama_unit = $row['nama_unit'];
        $tgl_awal  = date('d-m-Y', strtotime($row['tanggal_awal']));
        $tgl_akhir = date('d-m-Y', strtotime($row['tanggal_akhir']));

        // Tentukan Label
        if ($row['tanggal_akhir'] === $hari_ini) {
            $status_label = "*PENGINGAT DEADLINE (HARI INI)*";
            $keterangan   = "memasuki batas waktu *HARI INI*";
        } else {
            $status_label = "*PENGINGAT DEADLINE (H-1)*";
            $keterangan   = "memasuki batas waktu *H-1 (BESOK)*";
        }

        $pesan  = "$status_label\n\n";
        $pesan .= "Halo Admin, unit berikut $keterangan:\n\n";
        $pesan .= "*Kode Unit:* $kode_unit\n";
        $pesan .= "*Nama Unit:* $nama_unit\n";
        $pesan .= "*Tanggal Awal:* $tgl_awal\n";
        $pesan .= "*Tanggal Akhir:* $tgl_akhir\n\n";
        $pesan .= "Mohon segera update kembali usernya secepatnya. Terima kasih!";

        // Kirim via Fonnte
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://api.fonnte.com/send',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_SSL_VERIFYPEER => false, // Bypass SSL lokal
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_POSTFIELDS => array(
                'target' => $target_wa,
                'message' => $pesan,
                'countryCode' => '62',
            ),
            CURLOPT_HTTPHEADER => array(
                "Authorization: " . $token_fonnte
            ),
        ));

        $response = curl_exec($curl);
        curl_close($curl);

        $res = json_decode($response, true);

        // Update tanggal pengiriman terakhir agar tidak terkirim ganda hari ini
        if (isset($res['status']) && $res['status'] == true) {
            mysqli_query($koneksi, "UPDATE deadline SET pengingat = 'sent', terakhir_dikirim = '$hari_ini' WHERE id = $id");
        }
    }
}
?>
