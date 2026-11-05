<?php
/**
 * MENU USER
 *  - Semua akun : ubah nama & password akun sendiri ("Akun saya")
 *  - Akses Penuh: tambah akun, atur hak akses, setel ulang password, hapus akun
 * Pengaman: akun sendiri tidak bisa dihapus, dan admin terakhir tidak bisa diturunkan haknya.
 */
require_once __DIR__ . '/lib/sesi.php';
require_once __DIR__ . '/lib/skema.php';
require_once __DIR__ . '/lib/peran.php';
require_once __DIR__ . '/lib/tampilan.php';

wajibLogin();
pastikanSkema($pdo);
wajibAkunAktif();

$saya = (int) ($_SESSION['user_id'] ?? 0);

/** Jumlah akun admin selain $kecuali */
function jumlahAdminLain(PDO $pdo, int $kecuali): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE peran = 'admin' AND id <> ?");
    $stmt->execute([$kecuali]);
    return (int) $stmt->fetchColumn();
}

/** Pesan kesalahan password baru, atau null jika valid */
function cekPasswordBaru(string $pw, string $ulang): ?string
{
    if (strlen($pw) < 8) {
        return 'Password minimal 8 karakter.';
    }
    return $pw === $ulang ? null : 'Konfirmasi password tidak sesuai.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = (string) ($_POST['aksi'] ?? '');
    if (!csrf_valid()) {
        flash('danger', 'Sesi formulir kedaluwarsa, silakan coba lagi.');
    } elseif ($aksi === 'akun_saya') {
        $nama  = input_teks('nama', 100);
        $lama  = (string) ($_POST['password_lama'] ?? '');
        $baru  = (string) ($_POST['password_baru'] ?? '');
        $stmt  = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$saya]);
        $hash  = (string) $stmt->fetchColumn();
        if ($nama === '') {
            flash('danger', 'Nama wajib diisi.');
        } elseif ($baru !== '' && !password_verify($lama, $hash)) {
            flash('danger', 'Password lama salah.');
        } elseif ($baru !== '' && ($galat = cekPasswordBaru($baru, (string) ($_POST['ulang'] ?? '')))) {
            flash('danger', $galat);
        } else {
            $pdo->prepare("UPDATE users SET nama = ? WHERE id = ?")->execute([$nama, $saya]);
            if ($baru !== '') {
                $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([password_hash($baru, PASSWORD_DEFAULT), $saya]);
                session_regenerate_id(true);
            }
            flash('success', $baru !== '' ? 'Nama dan password disimpan.' : 'Nama disimpan.');
        }
    } elseif (!boleh('kelola_pengguna')) {
        flash('danger', 'Akun Anda tidak punya hak untuk aksi ini.');
    } elseif ($aksi === 'tambah') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $nama     = input_teks('nama', 100);
        $peran    = (string) ($_POST['peran'] ?? '');
        $galat    = cekPasswordBaru((string) ($_POST['password'] ?? ''), (string) ($_POST['ulang'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_.]{3,50}$/', $username)) {
            flash('danger', 'Username 3-50 karakter, hanya huruf, angka, titik, dan underscore.');
        } elseif ($nama === '' || !isset(PERAN[$peran])) {
            flash('danger', 'Nama dan hak akses wajib diisi.');
        } elseif ($galat) {
            flash('danger', $galat);
        } else {
            try {
                $pdo->prepare("INSERT INTO users (username, nama, password, peran) VALUES (?, ?, ?, ?)")
                    ->execute([$username, $nama, password_hash((string) $_POST['password'], PASSWORD_DEFAULT), $peran]);
                flash('success', "Akun $username dibuat dengan " . labelPeran($peran) . '.');
            } catch (PDOException $e) {
                flash('danger', $e->getCode() === '23000' ? 'Username sudah dipakai.' : 'Gagal membuat akun.');
            }
        }
    } elseif ($aksi === 'peran') {
        $id    = (int) ($_POST['id'] ?? 0);
        $peran = (string) ($_POST['peran'] ?? '');
        if (!isset(PERAN[$peran])) {
            flash('danger', 'Hak akses tidak dikenal.');
        } elseif ($peran !== 'admin' && jumlahAdminLain($pdo, $id) === 0) {
            flash('danger', 'Harus ada minimal satu akun dengan Akses Penuh.');
        } else {
            $pdo->prepare("UPDATE users SET peran = ? WHERE id = ?")->execute([$peran, $id]);
            flash('success', 'Hak akses diubah menjadi ' . labelPeran($peran) . '.');
        }
    } elseif ($aksi === 'reset_password') {
        $galat = cekPasswordBaru((string) ($_POST['password'] ?? ''), (string) ($_POST['ulang'] ?? ''));
        if ($galat) {
            flash('danger', $galat);
        } else {
            $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")
                ->execute([password_hash((string) $_POST['password'], PASSWORD_DEFAULT), (int) ($_POST['id'] ?? 0)]);
            flash('success', 'Password baru disimpan.');
        }
    } elseif ($aksi === 'hapus') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id === $saya) {
            flash('danger', 'Akun yang sedang dipakai tidak bisa dihapus.');
        } else {
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND (peran <> 'admin' OR (SELECT n FROM (SELECT COUNT(*) AS n FROM users WHERE peran = 'admin') t) > 1)");
            $stmt->execute([$id]);
            flash($stmt->rowCount() ? 'success' : 'danger', $stmt->rowCount() ? 'Akun dihapus.' : 'Akun tidak bisa dihapus.');
        }
    }
    header('Location: users.php');
    exit;
}

$stmt = $pdo->prepare("SELECT id, username, nama, peran FROM users WHERE id = ?");
$stmt->execute([$saya]);
$akun = $stmt->fetch();
$semua_user = boleh('kelola_pengguna')
    ? $pdo->query("SELECT id, username, nama, peran, dibuat_tanggal FROM users ORDER BY FIELD(peran, 'admin', 'staf', 'pemantau'), username")->fetchAll()
    : [];
$judul = boleh('kelola_pengguna') ? 'User' : 'Akun saya';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <?= kepalaHalaman($judul); ?>
</head>
<body class="app">

<?= sidebarAplikasi('pengguna'); ?>

<main class="isi">
    <div class="kepala">
        <div>
            <h1><?= e($judul); ?></h1>
            <div class="kepala-sub"><?= boleh('kelola_pengguna') ? count($semua_user) . ' akun · atur siapa yang bisa melihat dan mengubah data' : 'ubah nama dan password akun Anda'; ?></div>
        </div>
    </div>

    <?= tampilkanFlash(); ?>

    <div class="dua-kolom <?= boleh('kelola_pengguna') ? 'kolom-pengguna' : ''; ?>">
    <?php if (boleh('kelola_pengguna')): ?>
        <section class="panel">
            <div class="panel-judul"><h2>Daftar akun</h2><span><?= count($semua_user); ?> akun</span></div>
            <div class="table-responsive">
                <table class="tabel-a">
                    <thead><tr><th>Nama</th><th>Hak akses</th><th class="text-end">Aksi</th></tr></thead>
                    <tbody>
                    <?php foreach ($semua_user as $u): ?>
                        <tr>
                            <td>
                                <div class="nama"><?= e($u['nama'] !== '' ? $u['nama'] : $u['username']); ?><?= (int) $u['id'] === $saya ? ' <span class="tanda">Anda</span>' : ''; ?></div>
                                <div class="kode"><?= e($u['username']); ?></div>
                            </td>
                            <td>
                                <form method="POST" action="users.php" class="d-flex gap-2">
                                    <?= csrf_field(); ?>
                                    <input type="hidden" name="aksi" value="peran">
                                    <input type="hidden" name="id" value="<?= (int) $u['id']; ?>">
                                    <select name="peran" class="form-select form-select-sm" aria-label="Hak akses <?= e($u['username']); ?>" onchange="this.form.submit()">
                                        <?php foreach (PERAN as $kode => $label): ?>
                                            <option value="<?= $kode; ?>"<?= $u['peran'] === $kode ? ' selected' : ''; ?>><?= e($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            </td>
                            <td>
                                <div class="aksi">
                                    <button type="button" class="btn btn-teks btn-kecil" data-bs-toggle="modal" data-bs-target="#modalReset"
                                            data-id="<?= (int) $u['id']; ?>" data-username="<?= e($u['username']); ?>">Setel password</button>
                                    <?php if ((int) $u['id'] !== $saya): ?>
                                    <form method="POST" action="users.php" onsubmit="return confirm('Hapus akun <?= e(addslashes($u['username'])); ?>?')">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="aksi" value="hapus">
                                        <input type="hidden" name="id" value="<?= (int) $u['id']; ?>">
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
            <form method="POST" action="users.php" class="form-tambah">
                <?= csrf_field(); ?>
                <input type="hidden" name="aksi" value="tambah">
                <div class="label-kecil">Tambah akun</div>
                <div class="row g-2">
                    <div class="col-md-6"><input type="text" name="nama" class="form-control" placeholder="Nama" maxlength="100" required aria-label="Nama"></div>
                    <div class="col-md-6"><input type="text" name="username" class="form-control" placeholder="Username" pattern="[A-Za-z0-9_.]{3,50}" title="3-50 karakter: huruf, angka, titik, underscore" required aria-label="Username"></div>
                    <div class="col-md-6"><div class="input-group"><input type="password" name="password" class="form-control" placeholder="Password (min. 8)" minlength="8" autocomplete="new-password" required aria-label="Password"><?= tombol_mata(); ?></div></div>
                    <div class="col-md-6"><div class="input-group"><input type="password" name="ulang" class="form-control" placeholder="Ulangi password" minlength="8" autocomplete="new-password" required aria-label="Ulangi password"><?= tombol_mata(); ?></div></div>
                    <div class="col-md-6">
                        <select name="peran" class="form-select" aria-label="Hak akses">
                            <?php foreach (PERAN as $kode => $label): ?>
                                <option value="<?= $kode; ?>"<?= $kode === 'staf' ? ' selected' : ''; ?>><?= e($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6"><button type="submit" class="btn btn-gelap w-100">Tambah akun</button></div>
                </div>
                <div class="form-text mt-2"><b>Akses Penuh</b>: semua, termasuk penerima & user. <b>Akses Kerja</b>: tambah/edit hari jadi & kirim pengingat. <b>Akses Lihat</b>: hanya melihat.</div>
            </form>
        </section>
    <?php endif; ?>

        <section class="panel">
            <div class="panel-judul"><h2>Akun saya</h2><span><?= e(labelPeran(peranSaya())); ?></span></div>
            <form method="POST" action="users.php" class="form-tambah">
                <?= csrf_field(); ?>
                <input type="hidden" name="aksi" value="akun_saya">
                <div class="mb-3">
                    <label class="form-label" for="nama_saya">Nama</label>
                    <input type="text" id="nama_saya" name="nama" class="form-control" value="<?= e($akun['nama'] ?? ''); ?>" maxlength="100" required>
                    <div class="form-text">Username: <?= e($akun['username'] ?? ''); ?></div>
                </div>
                <div class="label-kecil mt-3">Ganti password (kosongkan jika tidak diganti)</div>
                <div class="mb-2"><div class="input-group"><input type="password" name="password_lama" class="form-control" placeholder="Password lama" autocomplete="current-password" aria-label="Password lama"><?= tombol_mata(); ?></div></div>
                <div class="mb-2"><div class="input-group"><input type="password" name="password_baru" class="form-control" placeholder="Password baru (min. 8)" minlength="8" autocomplete="new-password" aria-label="Password baru"><?= tombol_mata(); ?></div></div>
                <div class="mb-3"><div class="input-group"><input type="password" name="ulang" class="form-control" placeholder="Ulangi password baru" minlength="8" autocomplete="new-password" aria-label="Ulangi password baru"><?= tombol_mata(); ?></div></div>
                <button type="submit" class="btn btn-gelap">Simpan</button>
            </form>
        </section>
    </div>

    <div class="kaki">&copy; <?= date('Y'); ?> Aplikasi Pengingat Hari Jadi</div>
</main>

<?php if (boleh('kelola_pengguna')): ?>
<div class="modal fade" id="modalReset" tabindex="-1" aria-labelledby="judulReset" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="users.php" class="modal-content">
            <?= csrf_field(); ?>
            <input type="hidden" name="aksi" value="reset_password">
            <input type="hidden" name="id" value="">
            <div class="modal-header">
                <h2 class="modal-title" id="judulReset">Setel password</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3"><label class="form-label">Password baru</label><div class="input-group"><input type="password" name="password" class="form-control" minlength="8" autocomplete="new-password" required><?= tombol_mata(); ?></div></div>
                <div><label class="form-label">Ulangi password</label><div class="input-group"><input type="password" name="ulang" class="form-control" minlength="8" autocomplete="new-password" required><?= tombol_mata(); ?></div></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-teks" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-gelap">Simpan</button>
            </div>
        </form>
    </div>
</div>
<script>
document.getElementById('modalReset').addEventListener('show.bs.modal', (ev) => {
    const t = ev.relatedTarget;
    ev.target.querySelector('form').elements['id'].value = t.dataset.id;
    ev.target.querySelector('.modal-title').textContent = 'Setel password ' + t.dataset.username;
});
</script>
<?php endif; ?>

<?= kakiHalaman(); ?>
</body>
</html>
