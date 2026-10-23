<?php
/**
 * Potongan tampilan yang dipakai bersama oleh dashboard.php, users.php, login.php, register.php:
 *  - kepalaHalaman()   -> isi <head> (Bootstrap, ikon, font IBM Plex, tema.css)
 *  - sidebarAplikasi() -> menu samping (logo BRK Syariah + nama aplikasi): Daftar Unit, Penerima, Riwayat Kirim, Pengguna
 *  - merekAuth()       -> nama aplikasi di atas form Login & Daftar
 * Fungsi e() berasal dari koneksi.php.
 */

/** Versi tema.css: naikkan angka ini setelah mengubah tema.css agar browser memuat versi baru */
const VERSI_TEMA = 27;

/** Isi <head> bersama. $judul = judul tab browser, contoh "Daftar Unit" */
function kepalaHalaman(string $judul): string
{
    return '<meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . e($judul) . ' · Pengingat Deadline</title>
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#1d2528">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="tema.css?v=' . VERSI_TEMA . '">';
}

/**
 * Menu samping (di HP menjadi bilah menu di atas).
 * @param string $aktif  'unit', 'penerima', 'riwayat', atau 'pengguna'
 * @param array  $angka  angka kecil di samping menu, contoh ['unit' => 12, 'penerima' => 4]
 */
function sidebarAplikasi(string $aktif, array $angka = []): string
{
    $menu = [
        'unit'     => ['Daftar Unit', 'dashboard.php'],
        'penerima' => ['Penerima', 'dashboard.php?menu=penerima'],
        'riwayat'  => ['Riwayat Kirim', 'dashboard.php?menu=riwayat'],
        'pengguna' => ['Pengguna', 'users.php'],
    ];
    $html = '<aside class="side">'
        . '<a class="brand" href="dashboard.php">'
        . '<span class="brand-logo"><img src="logo-brk-syariah.webp" alt="BRK Syariah" width="160" height="33"></span>'
        . '<span class="brand-teks">Pengingat Deadline<small>Monitoring User Aplikasi Naskah Dinas</small></span></a>'
        . '<nav class="nav-side">';
    foreach ($menu as $kunci => [$label, $url]) {
        $html .= '<a href="' . $url . '"' . ($kunci === $aktif ? ' class="on" aria-current="page"' : '') . '>'
            . e($label) . (isset($angka[$kunci]) ? '<span>' . (int) $angka[$kunci] . '</span>' : '') . '</a>';
    }
    return $html . '</nav>'
        . '<div class="side-bawah"><b>' . e($_SESSION['username'] ?? 'admin') . '</b>'
        . '<a class="btn-keluar" href="logout.php"><i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i> Keluar</a></div>'
        . '</aside>';
}

/** Nama aplikasi di atas kotak form halaman Login & Daftar */
function merekAuth(): string
{
    return '<div class="auth-merek">Pengingat Deadline<small>Monitoring User Aplikasi Naskah Dinas</small></div>';
}
