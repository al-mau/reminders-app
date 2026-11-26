<?php
/**
 * Kelola User: tambah akun, atur peran, ubah akun (username, nama, password), hapus akun.
 * Akses Penuh juga mengisi "Pengaturan Environment Variable" (token Fonnte/Telegram, SMTP, saluran)
 * langsung dari aplikasi; nilainya mengalahkan .env / Vercel (lib/setelan.php).
 * Admin melihat & mengelola semua akun; Staf dan Pemantau hanya melihat & mengubah akunnya sendiri
 * (menu "Akun saya"). Peran dijelaskan di lib/peran.php.
 * Username dipakai sebagai kunci karena kolom ID tabel users bisa berbeda (id / id_user).
 */
require_once __DIR__ . '/lib/koneksi.php';
require_once __DIR__ . '/lib/session_handler.php';
require_once __DIR__ . '/lib/tampilan.php';
require_once __DIR__ . '/lib/peran.php';
require_once __DIR__ . '/lib/setelan.php';   // Pengaturan Environment Variable

if (empty($_SESSION['login'])) {
    header("Location: login.php");
    exit;
}
wajibAkunAktif(); // juga memastikan kolom peran sudah ada

header('Cache-Control: no-cache, no-store, must-revalidate');

$saya  = (string) ($_SESSION['username'] ?? '');
$admin = boleh('kelola_pengguna');

// Kolom opsional yang mungkin berbeda antar database
$kolomUsers  = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
$adaNama     = in_array('nama_lengkap', $kolomUsers, true);
$adaTanggal  = in_array('dibuat_tanggal', $kolomUsers, true);

/** Simpan pesan notifikasi (sukses/gagal) untuk ditampilkan setelah halaman dimuat ulang */
function flashUser(string $tipe, string $pesan): void
{
    $_SESSION['flash_user'][] = [$tipe, $pesan];
}

/**
 * Keluarkan semua sesi login milik username tertentu (kecuali sesi saat ini).
 * Dipakai setelah password diganti / user dihapus, agar perangkat lain otomatis logout.
 */
function keluarkanSesiUser(PDO $pdo, string $username): void
{
    $cari = addcslashes('username|' . serialize($username), '\\%_'); // escape karakter khusus LIKE
    $pola = '%' . $cari . '%';
    $pdo->prepare("DELETE FROM sessions WHERE data LIKE ? AND id <> ?")->execute([$pola, session_id()]);
}

/** Cek password minimal 6 karakter & sama dengan konfirmasi. null = valid, string = pesan error */
function validasiPassword(string $password, string $konfirmasi): ?string
{
    if (strlen($password) < 6) {
        return 'Password minimal 6 karakter.';
    }
    if ($password !== $konfirmasi) {
        return 'Konfirmasi password tidak sesuai.';
    }
    return null;
}

/** Jumlah akun Admin; dipakai agar Admin terakhir tidak dihapus atau diturunkan perannya */
function jumlahAdmin(PDO $pdo): int
{
    return (int) $pdo->query("SELECT COUNT(*) FROM users WHERE peran = 'admin'")->fetchColumn();
}

/** Peran akun tertentu, atau null bila akunnya tidak ada */
function peranAkun(PDO $pdo, string $username): ?string
{
    $stmt = $pdo->prepare("SELECT peran FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $hasil = $stmt->fetchColumn();
    return $hasil === false ? null : (string) $hasil;
}

// ===================================================================
// AKSI (dijalankan saat tombol di halaman ditekan / form dikirim)
// Setiap aksi diakhiri redirect ke users.php agar form tidak terkirim
// ulang saat halaman di-refresh (pola Post/Redirect/Get).
// ===================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        flashUser('danger', 'Sesi formulir kedaluwarsa, silakan ulangi.');
        header("Location: users.php");
        exit;
    }

    $aksi     = $_POST['aksi'] ?? '';
    $username = trim((string) ($_POST['username'] ?? ''));

    // Selain Admin hanya boleh mengubah akunnya sendiri
    if (!$admin && !($aksi === 'ubah_akun' && $username === $saya)) {
        flashUser('danger', 'Akun Anda (' . labelPeran(peranSaya()) . ') hanya bisa mengubah akun sendiri.');
        header("Location: users.php");
        exit;
    }

    // --- TAMBAH USER ---
    if ($aksi === 'tambah') {
        $nama  = trim((string) ($_POST['nama_lengkap'] ?? ''));
        $peran = (string) ($_POST['peran'] ?? '');
        $error = validasiPassword((string) ($_POST['password'] ?? ''), (string) ($_POST['konfirmasi'] ?? ''));

        if (!preg_match('/^[A-Za-z0-9_.]{3,50}$/', $username)) {
            $error = 'Username 3-50 karakter, hanya huruf, angka, titik, dan underscore.';
        } elseif ($adaNama && $nama === '') {
            $error = 'Nama lengkap wajib diisi.';
        } elseif (!isset(PERAN[$peran])) {
            $error = 'Pilih peran pengguna.';
        }

        if (!$error) {
            $cek = $pdo->prepare("SELECT 1 FROM users WHERE username = ?");
            $cek->execute([$username]);
            if ($cek->fetchColumn()) {
                $error = "Username \"$username\" sudah dipakai.";
            }
        }

        if ($error) {
            flashUser('danger', $error);
        } else {
            $kolom = ['username', 'password', 'peran'];
            $nilai = [$username, password_hash((string) $_POST['password'], PASSWORD_DEFAULT), $peran];
            if ($adaNama) {
                $kolom[] = 'nama_lengkap';
                $nilai[] = potong($nama, 100);
            }
            if ($adaTanggal) {
                $kolom[] = 'dibuat_tanggal';
                $nilai[] = date('Y-m-d H:i:s');
            }
            $pdo->prepare(sprintf(
                'INSERT INTO users (%s) VALUES (%s)',
                implode(', ', $kolom),
                implode(', ', array_fill(0, count($kolom), '?'))
            ))->execute($nilai);
            flashUser('success', "User \"$username\" berhasil dibuat dengan " . labelPeran($peran) . '. Berikan username & password-nya kepada yang bersangkutan.');
        }
        header("Location: users.php");
        exit;
    }

    // --- UBAH AKUN: username, nama lengkap, dan (opsional) password dalam satu form ---
    if ($aksi === 'ubah_akun') {
        $baru     = trim((string) ($_POST['username_baru'] ?? ''));
        $nama     = trim((string) ($_POST['nama_lengkap'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $gantiPw  = $password !== '' || (string) ($_POST['konfirmasi'] ?? '') !== ''; // kosong = password tidak diubah
        $error    = null;
        // Peran hanya bisa diubah Admin; Admin terakhir tidak boleh diturunkan
        $peranLama = peranAkun($pdo, $username);
        $peranBaru = $admin && isset(PERAN[$_POST['peran'] ?? '']) ? (string) $_POST['peran'] : $peranLama;
        $gantiPeran = $peranLama !== null && $peranBaru !== $peranLama;

        if (!preg_match('/^[A-Za-z0-9_.]{3,50}$/', $baru)) {
            $error = 'Username 3-50 karakter, hanya huruf, angka, titik, dan underscore.';
        } elseif ($adaNama && $nama === '') {
            $error = 'Nama lengkap wajib diisi.';
        } elseif ($gantiPw && ($e = validasiPassword($password, (string) ($_POST['konfirmasi'] ?? '')))) {
            $error = $e;
        } elseif ($gantiPeran && $peranLama === 'admin' && jumlahAdmin($pdo) <= 1) {
            $error = 'Harus ada minimal satu akun dengan Akses Penuh. Berikan Akses Penuh ke akun lain terlebih dahulu.';
        } else {
            // Username lain yang sudah dipakai (selain milik user ini sendiri)
            $cek = $pdo->prepare("SELECT 1 FROM users WHERE username = ? AND username <> ?");
            $cek->execute([$baru, $username]);
            if ($cek->fetchColumn()) {
                $error = "Username \"$baru\" sudah dipakai.";
            }
        }

        if ($error) {
            flashUser('danger', $error);
            header("Location: users.php");
            exit;
        }

        $cek = $pdo->prepare("SELECT 1 FROM users WHERE username = ?");
        $cek->execute([$username]);
        if (!$cek->fetchColumn()) {
            flashUser('danger', 'User tidak ditemukan.');
            header("Location: users.php");
            exit;
        }

        $set   = ['username = ?'];
        $nilai = [$baru];
        if ($adaNama) {
            $set[]   = 'nama_lengkap = ?';
            $nilai[] = potong($nama, 100);
        }
        if ($gantiPw) {
            $set[]   = 'password = ?';
            $nilai[] = password_hash($password, PASSWORD_DEFAULT);
        }
        if ($gantiPeran) {
            $set[]   = 'peran = ?';
            $nilai[] = $peranBaru;
        }
        $nilai[] = $username;
        $pdo->prepare('UPDATE users SET ' . implode(', ', $set) . ' WHERE username = ?')->execute($nilai);

        $gantiNama = $baru !== $username;
        if ($gantiNama || $gantiPw) {
            // Paksa login ulang di perangkat lain (sesi lama menyimpan username lama / password lama)
            keluarkanSesiUser($pdo, $username);
            if ($username === $saya) {
                $_SESSION['username'] = $baru;
            }
        }

        $diubah = array_merge($gantiNama ? ["username menjadi \"$baru\""] : [], $gantiPw ? ['password baru'] : [],
            $gantiPeran ? ['hak akses menjadi ' . labelPeran($peranBaru)] : []);
        if (!$diubah) {
            flashUser('success', "Data \"$username\" berhasil disimpan.");
        } elseif ($username === $saya) {
            flashUser('success', 'Akun Anda diperbarui: ' . implode(', ', $diubah) . '.' . ($gantiNama || $gantiPw ? ' Pakai data baru ini untuk login berikutnya.' : ''));
        } else {
            flashUser('success', "Akun \"$username\" diperbarui: " . implode(', ', $diubah) . '.' . ($gantiNama || $gantiPw ? ' User ini harus login ulang.' : ' Berlaku langsung.'));
        }
        header("Location: users.php");
        exit;
    }

    // --- HAPUS USER ---
    if ($aksi === 'hapus') {
        $jumlah = (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();

        if ($username === $saya) {
            flashUser('danger', 'Tidak bisa menghapus akun yang sedang dipakai login.');
        } elseif ($jumlah <= 1) {
            flashUser('danger', 'Tidak bisa menghapus user terakhir.');
        } elseif (peranAkun($pdo, $username) === 'admin' && jumlahAdmin($pdo) <= 1) {
            flashUser('danger', 'Tidak bisa menghapus akun Akses Penuh yang terakhir.');
        } else {
            $stmt = $pdo->prepare("DELETE FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->rowCount() > 0) {
                keluarkanSesiUser($pdo, $username);
                flashUser('warning', "User \"$username\" dihapus dan otomatis keluar dari semua perangkat.");
            }
        }
        header("Location: users.php");
        exit;
    }

    // --- PENGATURAN ENVIRONMENT VARIABLE (hanya Akses Penuh, sudah dicek di atas) ---
    if ($aksi === 'simpan_setelan' || $aksi === 'hapus_setelan') {
        $nama = (string) ($_POST['nama'] ?? '');
        if (!isset(SETELAN_APP[$nama])) {
            flashUser('danger', 'Pengaturan tidak dikenal.');
        } elseif ($aksi === 'hapus_setelan') {
            simpanSetelan($pdo, $nama, null, $saya);
            flashUser('warning', "$nama kembali memakai nilai dari .env / Vercel" . (env($nama) ? '.' : ' (di sana belum diisi).'));
        } else {
            [$nilai, $error] = periksaSetelan($nama, $_POST['nilai'] ?? '');
            if ($error) {
                flashUser('danger', "$nama: $error");
            } else {
                try {
                    simpanSetelan($pdo, $nama, $nilai, $saya);
                    flashUser('success', "$nama disimpan dan langsung berlaku" . (SETELAN_APP[$nama]['rahasia'] ? ' (tersimpan terenkripsi).' : '.')
                        . ' Coba dengan tombol Kirim percobaan di menu Penerima.');
                } catch (RuntimeException $e) {
                    flashUser('danger', $e->getMessage());
                }
            }
        }
        header("Location: users.php#setelan");
        exit;
    }

    header("Location: users.php");
    exit;
}

// ===================================================================
// DATA TAMPILAN
// ===================================================================
$select = 'username, peran' . ($adaNama ? ', nama_lengkap' : '') . ($adaTanggal ? ', dibuat_tanggal' : '');
$users  = $pdo->query("SELECT $select FROM users ORDER BY username")->fetchAll();
if (!$admin) {
    // Staf & Pemantau hanya melihat akunnya sendiri
    $users = array_values(array_filter($users, static fn($u) => $u['username'] === $saya));
}
$judul = $admin ? 'User' : 'Akun saya';
$setelanApp = $admin ? setelanTersimpan() : [];

$flash = $_SESSION['flash_user'] ?? [];
unset($_SESSION['flash_user']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <?= kepalaHalaman($judul); ?>
</head>
<body class="app">

<?= sidebarAplikasi('pengguna'); ?>

<main class="isi">
    <div class="kepala d-flex align-items-end justify-content-between gap-3 flex-wrap">
        <div>
            <h1><?= e($judul); ?></h1>
            <div class="kepala-sub"><?= $admin
                ? 'Akun yang bisa login ke aplikasi · tambah akun, atur hak akses, ubah akun, atau hapus akun'
                : 'Hak akses Anda: ' . e(labelPeran(peranSaya())) . ' · ubah nama, username, atau password akun Anda'; ?></div>
        </div>
    </div>
    <?php foreach ($flash as [$tipe, $pesan]): ?>
        <div class="alert alert-<?= e($tipe); ?> alert-dismissible fade show" role="alert">
            <?= e($pesan); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endforeach; ?>

    <?php // Akses Penuh: Daftar user, Tambah user, lalu Pengaturan Environment Variable (bersusun ke bawah).
          // Akses Kerja & Akses Lihat: hanya akun sendiri. Lihat README.md ?>
        <section class="card">
            <div class="card-header d-flex justify-content-between align-items-baseline"><h2><?= $admin ? 'Daftar user' : 'Akun Anda'; ?></h2><?php if ($admin): ?><span><?= count($users); ?> akun</span><?php endif; ?></div>
            <div class="table-responsive">
                <table class="tabel-a">
                    <thead>
                        <tr>
                            <th>Username</th>
                            <?php if ($adaNama): ?><th>Nama lengkap</th><?php endif; ?>
                            <th>Hak akses</th>
                            <?php if ($adaTanggal): ?><th>Dibuat</th><?php endif; ?>
                            <th><span class="visually-hidden">Aksi</span></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($users as $i => $u): $isSaya = $u['username'] === $saya; ?>
                        <tr>
                            <td data-label="Username"><span class="nama"><?= e($u['username']); ?></span><?php if ($isSaya): ?> <span class="badge tanda">Anda</span><?php endif; ?></td>
                            <?php if ($adaNama): ?><td data-label="Nama lengkap"><?= e($u['nama_lengkap'] ?? ''); ?></td><?php endif; ?>
                            <td data-label="Hak akses"><span class="badge peran peran-<?= e($u['peran']); ?>"><?= e(labelPeran($u['peran'])); ?></span></td>
                            <?php if ($adaTanggal): ?>
                                <td data-label="Dibuat" class="mono"><?= !empty($u['dibuat_tanggal']) ? date('d.m.Y', strtotime($u['dibuat_tanggal'])) : '&mdash;'; ?></td>
                            <?php endif; ?>
                            <td>
                                <div class="aksi">
                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#modalAkun<?= $i; ?>">Ubah akun</button>
                                    <?php if ($admin && !$isSaya && count($users) > 1): ?>
                                    <form method="POST" action="users.php" onsubmit="return confirm(this.dataset.konfirmasi)"
                                          data-konfirmasi="Hapus user <?= e($u['username']); ?>? User ini tidak akan bisa login lagi.">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="aksi" value="hapus">
                                        <input type="hidden" name="username" value="<?= e($u['username']); ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm">Hapus</button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <?php if ($admin): ?>
        <section class="card mt-3" id="tambah">
            <div class="card-header d-flex justify-content-between align-items-baseline"><h2>Tambah user</h2></div>
            <form method="POST" action="users.php" autocomplete="off" class="form-tambah">
                <?= csrf_field(); ?>
                <input type="hidden" name="aksi" value="tambah">
                <div class="row g-3">
                <?php if ($adaNama): ?>
                <div class="col-md-6 col-xl-4">
                    <label class="form-label">Nama lengkap</label>
                    <input type="text" name="nama_lengkap" class="form-control" maxlength="100" required>
                </div>
                <?php endif; ?>
                <div class="col-md-6 col-xl-4">
                    <label class="form-label">Username</label>
                    <input type="text" name="username" class="form-control" maxlength="50" pattern="[A-Za-z0-9_.]{3,50}" title="3-50 karakter: huruf, angka, titik, underscore" required>
                </div>
                <div class="col-md-6 col-xl-4">
                    <label class="form-label" for="peranBaru">Hak akses</label>
                    <select id="peranBaru" name="peran" class="form-select" required>
                        <?php foreach (PERAN as $kode => $label): ?>
                            <option value="<?= $kode; ?>" <?= $kode === 'staf' ? 'selected' : ''; ?>><?= e($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 col-xl-4">
                    <label class="form-label">Password</label>
                    <div class="input-group">
                        <input type="password" name="password" class="form-control" minlength="6" autocomplete="new-password" required>
                        <?= tombol_mata(); ?>
                    </div>
                </div>
                <div class="col-md-6 col-xl-4">
                    <label class="form-label">Konfirmasi password</label>
                    <div class="input-group">
                        <input type="password" name="konfirmasi" class="form-control" minlength="6" autocomplete="new-password" required>
                        <?= tombol_mata(); ?>
                    </div>
                </div>
                <div class="col-md-6 col-xl-4 d-flex align-items-end"><button type="submit" class="btn btn-primary w-100">Simpan user</button></div>
                </div>
            </form>
            <p class="catatan mb-2">
                Halaman Daftar publik: <b class="<?= pendaftaranDibuka() ? 'teks-merah' : 'teks-hijau'; ?>"><?= pendaftaranDibuka() ? 'terbuka' : 'ditutup'; ?></b>.
                <?php if (pendaftaranDibuka()): ?>
                    Siapa pun yang tahu link bisa membuat akun. Tutup dengan menghapus <code>ALLOW_REGISTER</code> atau mengisinya <code>false</code> di Vercel.
                <?php else: ?>
                    Akun baru hanya bisa dibuat dari halaman ini.
                <?php endif; ?>
            </p>
        </section>
        <?php endif; ?>

        <?php if ($admin): ?>
        <!-- Pengaturan Environment Variable: diisi dari aplikasi, mengalahkan .env / Vercel (lib/setelan.php) -->
        <section class="card mt-3" id="setelan">
            <div class="card-header d-flex justify-content-between align-items-baseline"><h2>Pengaturan Environment Variable</h2><span><?= count($setelanApp); ?> dari <?= count(SETELAN_APP); ?> diisi di sini</span></div>
            <div class="table-responsive">
                <table class="tabel-a">
                    <thead>
                        <tr>
                            <th>Variabel</th>
                            <th>Untuk</th>
                            <th>Nilai yang dipakai</th>
                            <th><span class="visually-hidden">Aksi</span></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach (SETELAN_APP as $nama => $info): $isi = $setelanApp[$nama] ?? null; ?>
                        <tr>
                            <td data-label="Variabel"><span class="nama mono"><?= e($nama); ?></span><div class="form-text mt-0"><?= e($info['ket']); ?></div></td>
                            <td data-label="Untuk"><?= e($info['untuk']); ?></td>
                            <td data-label="Nilai yang dipakai">
                                <?php if ($isi && $isi['rusak']): ?>
                                    <span class="teks-merah">Tidak bisa dibuka (APP_KEY / CRON_SECRET berubah), simpan ulang</span>
                                <?php elseif ($isi): ?>
                                    <span class="mono text-nowrap"><?= e(tampilanSetelan($nama, (string) $isi['nilai'])); ?></span>
                                    <div class="form-text mt-0">Dari menu ini · <?= e($isi['oleh'] ?: '-'); ?>, <?= e(date('d.m.Y H:i', strtotime((string) $isi['diubah']))); ?></div>
                                <?php elseif (env($nama) !== null && env($nama) !== ''): ?>
                                    <span class="text-body-secondary">Dari .env / Vercel</span>
                                <?php elseif (in_array($nama, ['EMAIL_PENGIRIM', 'EMAIL_NAMA_PENGIRIM'], true)): ?>
                                    <span class="text-body-secondary">Kosong (opsional)</span>
                                <?php else: ?>
                                    <span class="teks-merah">Belum diisi</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="aksi">
                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#modalSetelan<?= e($nama); ?>">Ubah</button>
                                    <?php if ($isi): ?>
                                    <form method="POST" action="users.php" onsubmit="return confirm(this.dataset.konfirmasi)"
                                          data-konfirmasi="Hapus <?= e($nama); ?> dari menu ini dan kembali memakai nilai dari .env / Vercel?">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="aksi" value="hapus_setelan">
                                        <input type="hidden" name="nama" value="<?= e($nama); ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm">Pakai .env</button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="catatan mb-2">
                Nilai di sini langsung berlaku tanpa mengubah file <code>.env</code> atau Redeploy, dan mengalahkan nilai di <code>.env</code> / Vercel.
                Token dan password disimpan terenkripsi dan tidak ditampilkan lagi. <code>DB_*</code>, <code>CRON_SECRET</code>, dan <code>APP_KEY</code> tetap diatur di <code>.env</code> / Vercel.
            </p>
        </section>
        <?php endif; ?>

    <?= kakiHalaman(); ?>
</main>

<!-- Popup "Ubah akun": username, nama lengkap, dan password (satu popup untuk setiap user) -->
<?php foreach ($users as $i => $u): ?>
<div class="modal fade" id="modalAkun<?= $i; ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="users.php" autocomplete="off">
                <div class="modal-header">
                    <h5 class="modal-title">Ubah akun · <?= e($u['username']); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="aksi" value="ubah_akun">
                    <input type="hidden" name="username" value="<?= e($u['username']); ?>">
                    <?php if ($adaNama): ?>
                    <div class="mb-3">
                        <label class="form-label" for="namaLengkap<?= $i; ?>">Nama lengkap</label>
                        <input type="text" id="namaLengkap<?= $i; ?>" name="nama_lengkap" class="form-control" value="<?= e($u['nama_lengkap'] ?? ''); ?>" maxlength="100" required>
                    </div>
                    <?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label" for="usernameBaru<?= $i; ?>">Username</label>
                        <input type="text" id="usernameBaru<?= $i; ?>" name="username_baru" class="form-control" value="<?= e($u['username']); ?>"
                               maxlength="50" pattern="[A-Za-z0-9_.]{3,50}" title="3-50 karakter: huruf, angka, titik, underscore" required>
                    </div>
                    <?php if ($admin): ?>
                    <div class="mb-3">
                        <label class="form-label" for="peran<?= $i; ?>">Hak akses</label>
                        <select id="peran<?= $i; ?>" name="peran" class="form-select">
                            <?php foreach (PERAN as $kode => $label): ?>
                                <option value="<?= $kode; ?>" <?= $kode === $u['peran'] ? 'selected' : ''; ?>><?= e($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="label-kecil mt-4">Ganti password</div>
                    <div class="mb-3">
                        <label class="form-label" for="passwordBaru<?= $i; ?>">Password baru</label>
                        <div class="input-group">
                            <input type="password" id="passwordBaru<?= $i; ?>" name="password" class="form-control" minlength="6" autocomplete="new-password" placeholder="Kosongkan jika tidak diganti">
                            <?= tombol_mata(); ?>
                        </div>
                    </div>
                    <div class="mb-1">
                        <label class="form-label" for="konfirmasi<?= $i; ?>">Konfirmasi password baru</label>
                        <div class="input-group">
                            <input type="password" id="konfirmasi<?= $i; ?>" name="konfirmasi" class="form-control" minlength="6" autocomplete="new-password" placeholder="Kosongkan jika tidak diganti">
                            <?= tombol_mata(); ?>
                        </div>
                    </div>
                    <div class="form-text">
                        <?= $u['username'] === $saya
                            ? 'Ini akun Anda. Jika username atau password diganti, login berikutnya memakai data baru.'
                            : 'Jika username atau password diganti, user ini otomatis keluar dari semua perangkat dan harus login ulang.'; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-link" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php // Popup "Ubah" untuk setiap Environment Variable (hanya Akses Penuh) ?>
<?php foreach ($admin ? SETELAN_APP : [] as $nama => $info): $isi = $setelanApp[$nama] ?? null; ?>
<div class="modal fade" id="modalSetelan<?= e($nama); ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="users.php" autocomplete="off">
                <div class="modal-header">
                    <h5 class="modal-title">Ubah · <span class="mono"><?= e($nama); ?></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="aksi" value="simpan_setelan">
                    <input type="hidden" name="nama" value="<?= e($nama); ?>">
                    <?php if ($nama === 'NOTIF_VIA'): $aktif = explode(',', strtolower((string) setelan('NOTIF_VIA', 'wa'))); ?>
                        <div class="form-label">Saluran yang dipakai</div>
                        <?php foreach (['wa' => 'WhatsApp', 'telegram' => 'Telegram', 'email' => 'Email'] as $kode => $label): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="nilai[]" value="<?= $kode; ?>" id="via<?= $kode; ?>" <?= in_array($kode, array_map('trim', $aktif), true) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="via<?= $kode; ?>"><?= $label; ?></label>
                        </div>
                        <?php endforeach; ?>
                    <?php elseif ($info['rahasia']): ?>
                        <label class="form-label" for="nilai<?= e($nama); ?>">Nilai baru</label>
                        <div class="input-group">
                            <input type="password" id="nilai<?= e($nama); ?>" name="nilai" class="form-control mono" maxlength="255" autocomplete="new-password" required
                                   placeholder="<?= $isi ? 'Isi untuk mengganti nilai yang tersimpan' : 'Tempel nilainya di sini'; ?>">
                            <?= tombol_mata(); ?>
                        </div>
                    <?php else: ?>
                        <label class="form-label" for="nilai<?= e($nama); ?>">Nilai</label>
                        <input type="<?= $nama === 'SMTP_PORT' ? 'number' : 'text'; ?>" id="nilai<?= e($nama); ?>" name="nilai" class="form-control mono" maxlength="255" required
                               value="<?= e((string) setelan($nama, '')); ?>">
                    <?php endif; ?>
                    <div class="form-text"><?= e($info['ket']); ?>. Berlaku langsung setelah disimpan.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-link" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?= skripBootstrap(); ?>
<?= skrip_mata(); ?>
</body>
</html>
