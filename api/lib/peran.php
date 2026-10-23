<?php
/**
 * Peran (hak akses) pengguna. Dipakai oleh dashboard.php, users.php, dan sidebar.
 *
 *  admin    -> semua: kelola unit, hapus data, kelola penerima, kelola pengguna
 *  staf     -> kerja harian: tambah/edit unit, tandai selesai, kirim pengingat, unggah lampiran
 *  pemantau -> hanya melihat (Daftar Unit, lampiran, Penerima, Riwayat Kirim)
 * Semua peran boleh mengubah nama, username, dan password akunnya sendiri.
 * Penjelasan lengkap untuk pengguna ada di README.md (bagian "Peran pengguna").
 *
 * Peran dibaca dari database setiap halaman dibuka (bukan dari sesi), sehingga
 * perubahan peran oleh Admin langsung berlaku tanpa perlu login ulang.
 */
require_once __DIR__ . '/skema.php';

const PERAN = [
    'admin'    => 'Admin',
    'staf'     => 'Staf',
    'pemantau' => 'Pemantau',
];

/** Hak -> peran yang boleh. Hak yang tidak terdaftar di sini tidak diberikan ke siapa pun. */
const HAK_PERAN = [
    'kelola_unit'     => ['admin', 'staf'],   // tambah/edit unit, tandai selesai, kirim pengingat, unggah lampiran
    'hapus_data'      => ['admin'],           // hapus unit dan lampiran (tidak bisa dibatalkan)
    'kelola_penerima' => ['admin'],           // nomor WhatsApp & chat Telegram tujuan pengingat
    'kelola_pengguna' => ['admin'],           // tambah, ubah, hapus akun dan mengatur peran
];

/** Peran akun yang sedang login: 'admin' / 'staf' / 'pemantau', atau '' bila akunnya sudah tidak ada */
function peranSaya(): string
{
    global $pdo;
    static $peran = null;
    if ($peran !== null) {
        return $peran;
    }
    pastikanTabelTambahan($pdo);
    $stmt = $pdo->prepare("SELECT peran FROM users WHERE username = ?");
    $stmt->execute([(string) ($_SESSION['username'] ?? '')]);
    $hasil = $stmt->fetchColumn();
    // Nilai tak dikenal diperlakukan sebagai pemantau (hak paling sedikit)
    $peran = $hasil === false ? '' : (isset(PERAN[$hasil]) ? $hasil : 'pemantau');
    return $peran;
}

/** Apakah akun yang login punya hak tertentu (lihat HAK_PERAN) */
function boleh(string $hak): bool
{
    return in_array(peranSaya(), HAK_PERAN[$hak] ?? [], true);
}

/** Nama peran untuk ditampilkan, contoh "Staf" */
function labelPeran(string $peran): string
{
    return PERAN[$peran] ?? 'Pemantau';
}

/** Akun yang sedang login sudah dihapus Admin: akhiri sesi dan kembali ke halaman login */
function wajibAkunAktif(): void
{
    if (peranSaya() === '') {
        header('Location: logout.php');
        exit;
    }
}
