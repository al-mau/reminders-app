<?php
/**
 * Potongan tampilan yang dipakai bersama oleh dashboard.php, users.php, login.php, daftar.php:
 *  - kepalaHalaman()   -> isi <head> (Bootstrap, ikon, font IBM Plex, tema.css)
 *  - sidebarAplikasi() -> menu samping: Hari Jadi, Penerima, Riwayat Kirim, User
 *  - merekAuth()       -> nama aplikasi di atas form Login & Daftar
 *  - kakiHalaman()     -> skrip Bootstrap + tombol mata, sebelum </body>
 * Fungsi e() berasal dari koneksi.php.
 */

/** Versi tema.css: naikkan angka ini setelah mengubah tema.css agar browser memuat versi baru */
const VERSI_TEMA = 1;

/** Isi <head> bersama. $judul = judul tab browser, contoh "Hari Jadi" */
function kepalaHalaman(string $judul): string
{
    return '<meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . e($judul) . ' · Pengingat Hari Jadi</title>
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#0f3d34">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="tema.css?v=' . VERSI_TEMA . '">';
}

/**
 * Menu samping (di HP menjadi bilah menu di atas).
 * @param string $aktif  'hari_jadi', 'penerima', 'riwayat', atau 'pengguna'
 * @param array  $angka  angka kecil di samping menu, contoh ['hari_jadi' => 12]
 */
function sidebarAplikasi(string $aktif, array $angka = []): string
{
    $peran = function_exists('peranSaya') ? peranSaya() : 'admin';
    $menu = [
        'hari_jadi' => ['Hari Jadi', 'dashboard.php'],
        'penerima'  => ['Penerima', 'dashboard.php?menu=penerima'],
        'riwayat'   => ['Riwayat Kirim', 'dashboard.php?menu=riwayat'],
        'pengguna'  => [$peran === 'admin' ? 'User' : 'Akun saya', 'users.php'],
    ];
    $html = '<aside class="side"><div class="side-isi">'
        . '<a class="brand" href="dashboard.php">'
        . '<span class="brand-logo"><img src="logo-brk-syariah.webp" alt="BRK Syariah" width="160" height="33"></span>'
        . '<span class="brand-teks">Pengingat Hari Jadi<small>Daerah Pemegang Saham</small></span></a>'
        . '<nav class="nav-side">';
    foreach ($menu as $kunci => [$label, $url]) {
        $html .= '<a href="' . $url . '"' . ($kunci === $aktif ? ' class="on" aria-current="page"' : '') . '>'
            . e($label) . (isset($angka[$kunci]) ? '<span>' . (int) $angka[$kunci] . '</span>' : '') . '</a>';
    }
    return $html . '</nav>'
        . '<div class="side-bawah"><b>' . e($_SESSION['username'] ?? '') . '</b>'
        . (function_exists('labelPeran') && $peran !== '' ? '<span class="peran-saya">' . e(labelPeran($peran)) . '</span>' : '')
        . '<a class="btn-keluar" href="logout.php"><i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i> Keluar</a></div>'
        . '</div></aside>';
}

/** Nama aplikasi di atas kotak form halaman Login & Daftar */
function merekAuth(): string
{
    return '<div class="auth-merek">'
        . '<img src="logo-brk-syariah.webp" alt="BRK Syariah" width="180" height="37">'
        . '<div class="auth-judul">Pengingat Hari Jadi<small>Daerah Pemegang Saham</small></div></div>';
}

/** Skrip penutup halaman (Bootstrap + tombol mata password) */
function kakiHalaman(): string
{
    return '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>' . skrip_mata();
}

/** Pesan singkat setelah aksi (disimpan di sesi, tampil sekali setelah redirect) */
function flash(string $tipe, string $pesan): void
{
    $_SESSION['flash'][] = [$tipe, $pesan];
}

/** Tampilkan & hapus semua pesan flash */
function tampilkanFlash(): string
{
    $html = '';
    foreach ($_SESSION['flash'] ?? [] as [$tipe, $pesan]) {
        $html .= '<div class="alert alert-' . e($tipe) . ' alert-dismissible fade show" role="alert">' . nl2br(e($pesan))
            . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button></div>';
    }
    unset($_SESSION['flash']);
    return $html;
}
