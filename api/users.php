<?php
/**
 * Kelola User: tambah admin, ubah akun (username, nama, password), hapus akun.
 * Hanya bisa diakses oleh user yang sudah login.
 * Username dipakai sebagai kunci karena kolom ID tabel users bisa berbeda (id / id_user).
 */
require_once __DIR__ . '/lib/koneksi.php';
require_once __DIR__ . '/lib/session_handler.php';
require_once __DIR__ . '/lib/tampilan.php';

if (empty($_SESSION['login'])) {
    header("Location: login.php");
    exit;
}

header('Cache-Control: no-cache, no-store, must-revalidate');

$saya = (string) ($_SESSION['username'] ?? '');

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

    // --- TAMBAH USER ---
    if ($aksi === 'tambah') {
        $nama  = trim((string) ($_POST['nama_lengkap'] ?? ''));
        $error = validasiPassword((string) ($_POST['password'] ?? ''), (string) ($_POST['konfirmasi'] ?? ''));

        if (!preg_match('/^[A-Za-z0-9_.]{3,50}$/', $username)) {
            $error = 'Username 3-50 karakter, hanya huruf, angka, titik, dan underscore.';
        } elseif ($adaNama && $nama === '') {
            $error = 'Nama lengkap wajib diisi.';
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
            $kolom = ['username', 'password'];
            $nilai = [$username, password_hash((string) $_POST['password'], PASSWORD_DEFAULT)];
            if ($adaNama) {
                $kolom[] = 'nama_lengkap';
                $nilai[] = function_exists('mb_substr') ? mb_substr($nama, 0, 100) : substr($nama, 0, 100);
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
            flashUser('success', "User \"$username\" berhasil dibuat. Berikan username & password-nya kepada yang bersangkutan.");
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

        if (!preg_match('/^[A-Za-z0-9_.]{3,50}$/', $baru)) {
            $error = 'Username 3-50 karakter, hanya huruf, angka, titik, dan underscore.';
        } elseif ($adaNama && $nama === '') {
            $error = 'Nama lengkap wajib diisi.';
        } elseif ($gantiPw && ($e = validasiPassword($password, (string) ($_POST['konfirmasi'] ?? '')))) {
            $error = $e;
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
            $nilai[] = function_exists('mb_substr') ? mb_substr($nama, 0, 100) : substr($nama, 0, 100);
        }
        if ($gantiPw) {
            $set[]   = 'password = ?';
            $nilai[] = password_hash($password, PASSWORD_DEFAULT);
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

        $diubah = array_merge($gantiNama ? ["username menjadi \"$baru\""] : [], $gantiPw ? ['password baru'] : []);
        if (!$diubah) {
            flashUser('success', "Data \"$username\" berhasil disimpan.");
        } elseif ($username === $saya) {
            flashUser('success', 'Akun Anda diperbarui: ' . implode(' dan ', $diubah) . '. Pakai data baru ini untuk login berikutnya.');
        } else {
            flashUser('success', "Akun \"$username\" diperbarui: " . implode(' dan ', $diubah) . '. User ini harus login ulang.');
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

    header("Location: users.php");
    exit;
}

// ===================================================================
// DATA TAMPILAN
// ===================================================================
$select = 'username' . ($adaNama ? ', nama_lengkap' : '') . ($adaTanggal ? ', dibuat_tanggal' : '');
$users  = $pdo->query("SELECT $select FROM users ORDER BY username")->fetchAll();

$flash = $_SESSION['flash_user'] ?? [];
unset($_SESSION['flash_user']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <?= kepalaHalaman('Pengguna'); ?>
</head>
<body class="app">

<?= sidebarAplikasi('pengguna'); ?>

<main class="isi">
    <div class="kepala">
        <div>
            <h1>Pengguna</h1>
            <div class="kepala-sub">Akun yang bisa login ke aplikasi · tambah akun, ubah akun (username, nama, password), atau hapus akun</div>
        </div>
    </div>
    <?php foreach ($flash as [$tipe, $pesan]): ?>
        <div class="alert alert-<?= e($tipe); ?> alert-dismissible fade show" role="alert">
            <?= e($pesan); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endforeach; ?>

    <div class="dua-kolom kolom-pengguna">
        <section class="panel">
            <div class="panel-judul"><h2>Daftar pengguna</h2><span><?= count($users); ?> akun</span></div>
            <div class="table-responsive">
                <table class="tabel-a">
                    <thead>
                        <tr>
                            <th>Username</th>
                            <?php if ($adaNama): ?><th>Nama lengkap</th><?php endif; ?>
                            <?php if ($adaTanggal): ?><th>Dibuat</th><?php endif; ?>
                            <th><span class="visually-hidden">Aksi</span></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($users as $i => $u): $isSaya = $u['username'] === $saya; ?>
                        <tr>
                            <td data-label="Username"><span class="nama"><?= e($u['username']); ?></span><?php if ($isSaya): ?> <span class="tanda">Anda</span><?php endif; ?></td>
                            <?php if ($adaNama): ?><td data-label="Nama lengkap"><?= e($u['nama_lengkap'] ?? ''); ?></td><?php endif; ?>
                            <?php if ($adaTanggal): ?>
                                <td data-label="Dibuat" class="mono"><?= !empty($u['dibuat_tanggal']) ? date('d.m.Y', strtotime($u['dibuat_tanggal'])) : '&mdash;'; ?></td>
                            <?php endif; ?>
                            <td>
                                <div class="aksi">
                                    <button type="button" class="btn btn-garis btn-kecil" data-bs-toggle="modal" data-bs-target="#modalAkun<?= $i; ?>">Ubah akun</button>
                                    <?php if (!$isSaya && count($users) > 1): ?>
                                    <form method="POST" action="users.php" onsubmit="return confirm(this.dataset.konfirmasi)"
                                          data-konfirmasi="Hapus user <?= e($u['username']); ?>? User ini tidak akan bisa login lagi.">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="aksi" value="hapus">
                                        <input type="hidden" name="username" value="<?= e($u['username']); ?>">
                                        <button type="submit" class="btn btn-teks btn-kecil teks-merah">Hapus</button>
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

        <section class="panel">
            <div class="panel-judul"><h2>Tambah pengguna</h2></div>
            <form method="POST" action="users.php" autocomplete="off" class="form-tambah">
                <?= csrf_field(); ?>
                <input type="hidden" name="aksi" value="tambah">
                <?php if ($adaNama): ?>
                <div class="mb-3">
                    <label class="form-label">Nama lengkap</label>
                    <input type="text" name="nama_lengkap" class="form-control" maxlength="100" required>
                </div>
                <?php endif; ?>
                <div class="mb-3">
                    <label class="form-label">Username</label>
                    <input type="text" name="username" class="form-control" maxlength="50" pattern="[A-Za-z0-9_.]{3,50}" title="3-50 karakter: huruf, angka, titik, underscore" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Password</label>
                    <div class="input-group">
                        <input type="password" name="password" class="form-control" minlength="6" autocomplete="new-password" required>
                        <?= tombol_mata(); ?>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Konfirmasi password</label>
                    <div class="input-group">
                        <input type="password" name="konfirmasi" class="form-control" minlength="6" autocomplete="new-password" required>
                        <?= tombol_mata(); ?>
                    </div>
                </div>
                <button type="submit" class="btn btn-gelap w-100">Simpan pengguna</button>
            </form>
            <p class="catatan mb-3">
                Halaman Daftar publik: <b class="<?= pendaftaranDibuka() ? 'teks-merah' : 'teks-hijau'; ?>"><?= pendaftaranDibuka() ? 'terbuka' : 'ditutup'; ?></b>.
                <?php if (pendaftaranDibuka()): ?>
                    Siapa pun yang tahu link bisa membuat akun. Tutup dengan menghapus <code>ALLOW_REGISTER</code> atau mengisinya <code>false</code> di Vercel.
                <?php else: ?>
                    Akun baru hanya bisa dibuat dari halaman ini.
                <?php endif; ?>
            </p>
        </section>
    </div>

    <div class="kaki">&copy; <?= date('Y'); ?> Aplikasi Pengingat Jadwal</div>
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
                    <button type="button" class="btn btn-teks" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-gelap">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?= skrip_mata(); ?>
</body>
</html>
