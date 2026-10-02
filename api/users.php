<?php
/**
 * Kelola User: tambah admin, reset password, hapus akun.
 * Hanya bisa diakses oleh user yang sudah login.
 * Username dipakai sebagai kunci karena kolom ID tabel users bisa berbeda (id / id_user).
 */
require_once __DIR__ . '/lib/koneksi.php';
require_once __DIR__ . '/lib/session_handler.php';

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

    // --- RESET / GANTI PASSWORD ---
    if ($aksi === 'reset_password') {
        $error = validasiPassword((string) ($_POST['password'] ?? ''), (string) ($_POST['konfirmasi'] ?? ''));
        if ($error) {
            flashUser('danger', $error);
        } else {
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE username = ?");
            $stmt->execute([password_hash((string) $_POST['password'], PASSWORD_DEFAULT), $username]);

            if ($stmt->rowCount() > 0) {
                keluarkanSesiUser($pdo, $username); // paksa login ulang di perangkat lain
                flashUser('success', "Password \"$username\" berhasil diganti.");
            } else {
                flashUser('danger', 'User tidak ditemukan.');
            }
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
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola User - Aplikasi Pengingat Jadwal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="tema.css?v=3">
</head>
<body>

<nav class="navbar navbar-dark mb-4 py-3 shadow-sm">
    <div class="container">
        <a class="navbar-brand fw-bold" href="dashboard.php"><i class="fa-solid fa-bell me-2 text-warning"></i> Aplikasi Pengingat Jadwal</a>
        <div class="d-flex align-items-center gap-2">
            <a href="dashboard.php" class="btn btn-outline-light btn-sm rounded-pill px-3"><i class="fa-solid fa-arrow-left me-1"></i> Dashboard</a>
            <a href="logout.php" class="btn btn-outline-light btn-sm rounded-pill px-3"><i class="fa-solid fa-right-from-bracket me-1"></i> Logout</a>
        </div>
    </div>
</nav>

<div class="container">
    <div class="mb-4">
        <h1 class="judul-halaman">Kelola User</h1>
        <div class="text-redup small">Tambah akun admin, ganti password, atau hapus akun</div>
    </div>
    <?php foreach ($flash as [$tipe, $pesan]): ?>
        <div class="alert alert-<?= e($tipe); ?> alert-dismissible fade show py-2" role="alert">
            <?= e($pesan); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endforeach; ?>

    <div class="row g-4">
        <!-- Kolom kiri: form tambah user -->
        <div class="col-lg-4">
            <div class="card kartu kartu-input">
                <div class="card-header">
                    <span class="ikon-judul"><i class="fa-solid fa-user-plus"></i></span>
                    <div><h2 class="kartu-judul">Tambah User</h2><div class="kartu-sub">Buat akun untuk admin baru</div></div>
                </div>
                <div class="card-body">
                    <form method="POST" action="users.php" autocomplete="off">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="aksi" value="tambah">
                        <?php if ($adaNama): ?>
                        <div class="mb-3">
                            <label class="form-label text-secondary small fw-bold">Nama Lengkap</label>
                            <input type="text" name="nama_lengkap" class="form-control" maxlength="100" required>
                        </div>
                        <?php endif; ?>
                        <div class="mb-3">
                            <label class="form-label text-secondary small fw-bold">Username</label>
                            <input type="text" name="username" class="form-control" maxlength="50" pattern="[A-Za-z0-9_.]{3,50}" title="3-50 karakter: huruf, angka, titik, underscore" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-secondary small fw-bold">Password</label>
                            <input type="password" name="password" class="form-control" minlength="6" autocomplete="new-password" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-secondary small fw-bold">Konfirmasi Password</label>
                            <input type="password" name="konfirmasi" class="form-control" minlength="6" autocomplete="new-password" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100"><i class="fa-solid fa-floppy-disk me-1"></i> Simpan User</button>
                    </form>
                </div>
            </div>

            <div class="card kartu mt-4 p-3">
                <div class="small text-redup">
                    <i class="fa-solid fa-lock me-1"></i>
                    Halaman Daftar publik: <strong class="<?= pendaftaranDibuka() ? 'text-warning' : 'text-success'; ?>"><?= pendaftaranDibuka() ? 'TERBUKA' : 'DITUTUP'; ?></strong>.
                    <?php if (pendaftaranDibuka()): ?>
                        Siapa pun yang tahu link bisa membuat akun. Tutup dengan menghapus <code>ALLOW_REGISTER</code> atau mengisinya <code>false</code> di Vercel.
                    <?php else: ?>
                        Akun baru hanya bisa dibuat dari halaman ini.
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card kartu kartu-daftar">
                <div class="card-header">
                    <span class="ikon-judul"><i class="fa-solid fa-users"></i></span>
                    <div class="flex-grow-1"><h2 class="kartu-judul">Daftar User</h2><div class="kartu-sub">Akun yang bisa login ke aplikasi</div></div>
                    <span class="lencana lencana-biru"><?= count($users); ?> user</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle tabel">
                        <thead>
                            <tr>
                                <th>Username</th>
                                <?php if ($adaNama): ?><th>Nama Lengkap</th><?php endif; ?>
                                <?php if ($adaTanggal): ?><th>Dibuat</th><?php endif; ?>
                                <th class="text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($users as $i => $u): $isSaya = $u['username'] === $saya; ?>
                            <tr>
                                <td class="fw-semibold">
                                    <?= e($u['username']); ?>
                                    <?php if ($isSaya): ?><span class="lencana lencana-hijau ms-1">Anda</span><?php endif; ?>
                                </td>
                                <?php if ($adaNama): ?><td><?= e($u['nama_lengkap'] ?? ''); ?></td><?php endif; ?>
                                <?php if ($adaTanggal): ?>
                                    <td class="small text-secondary"><?= !empty($u['dibuat_tanggal']) ? date('d M Y', strtotime($u['dibuat_tanggal'])) : '-'; ?></td>
                                <?php endif; ?>
                                <td class="text-center">
                                    <div class="d-flex justify-content-center gap-1">
                                        <button type="button" class="btn-ikon btn-ikon-kuning" data-bs-toggle="modal" data-bs-target="#modalPassword<?= $i; ?>" title="Ganti Password">
                                            <i class="fa-solid fa-key"></i>
                                        </button>
                                        <?php if (!$isSaya && count($users) > 1): ?>
                                        <form method="POST" action="users.php" onsubmit="return confirm(this.dataset.konfirmasi)"
                                              data-konfirmasi="Hapus user <?= e($u['username']); ?>? User ini tidak akan bisa login lagi.">
                                            <?= csrf_field(); ?>
                                            <input type="hidden" name="aksi" value="hapus">
                                            <input type="hidden" name="username" value="<?= e($u['username']); ?>">
                                            <button type="submit" class="btn-ikon btn-ikon-merah" title="Hapus User"><i class="fa-solid fa-trash"></i></button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="kaki">&copy; <?= date('Y'); ?> Aplikasi Pengingat Jadwal</div>

<!-- Popup "Ganti Password" (satu popup untuk setiap user) -->
<?php foreach ($users as $i => $u): ?>
<div class="modal fade" id="modalPassword<?= $i; ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="users.php" autocomplete="off">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-key text-warning me-2"></i> Ganti Password: <?= e($u['username']); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="aksi" value="reset_password">
                    <input type="hidden" name="username" value="<?= e($u['username']); ?>">
                    <div class="mb-3">
                        <label class="form-label text-secondary small fw-bold">Password Baru</label>
                        <input type="password" name="password" class="form-control" minlength="6" autocomplete="new-password" required>
                    </div>
                    <div class="mb-1">
                        <label class="form-label text-secondary small fw-bold">Konfirmasi Password Baru</label>
                        <input type="password" name="konfirmasi" class="form-control" minlength="6" autocomplete="new-password" required>
                    </div>
                    <div class="form-text">User ini akan otomatis keluar dari perangkat lain dan harus login ulang dengan password baru.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan Password</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
