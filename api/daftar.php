<?php
/**
 * MEMBUAT AKUN ADMIN PERTAMA (hanya terbuka selama tabel users masih kosong).
 * Sesudah ada akun, halaman ini tertutup; akun lain dibuat admin lewat menu User (users.php).
 */
require_once __DIR__ . '/lib/sesi.php';
require_once __DIR__ . '/lib/skema.php';
require_once __DIR__ . '/lib/tampilan.php';

pastikanSkema($pdo);
if ((int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() > 0) {
    header('Location: login.php');
    exit;
}

$pesan_error = '';
$nama        = '';
$username    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nama     = input_teks('nama', 100);
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (!csrf_valid()) {
        $pesan_error = 'Sesi formulir kedaluwarsa, silakan coba lagi.';
    } elseif ($nama === '' || $username === '' || $password === '') {
        $pesan_error = 'Semua kolom wajib diisi!';
    } elseif (!preg_match('/^[A-Za-z0-9_.]{3,50}$/', $username)) {
        $pesan_error = 'Username 3-50 karakter, hanya huruf, angka, titik, dan underscore.';
    } elseif (strlen($password) < 8) {
        $pesan_error = 'Password minimal 8 karakter.';
    } elseif ($password !== (string) ($_POST['konfirmasi'] ?? '')) {
        $pesan_error = 'Konfirmasi password tidak sesuai!';
    } else {
        try {
            $pdo->beginTransaction();
            // Kunci tabel agar dua orang yang mendaftar bersamaan tidak sama-sama menjadi admin
            $ada = (int) $pdo->query("SELECT COUNT(*) FROM users FOR UPDATE")->fetchColumn();
            if ($ada === 0) {
                $pdo->prepare("INSERT INTO users (username, nama, password, peran) VALUES (?, ?, ?, 'admin')")
                    ->execute([$username, $nama, password_hash($password, PASSWORD_DEFAULT)]);
            }
            $pdo->commit();
            flash('success', $ada === 0 ? 'Akun admin berhasil dibuat. Silakan masuk.' : 'Akun admin sudah dibuat sebelumnya. Silakan masuk.');
            header('Location: login.php');
            exit;
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('Daftar admin gagal: ' . $e->getMessage());
            $pesan_error = 'Gagal membuat akun, terjadi kesalahan sistem.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <?= kepalaHalaman('Buat Akun Admin'); ?>
</head>
<body class="auth">

<?= merekAuth(); ?>
<main class="auth-kotak">
    <h1>Buat Akun Admin</h1>
    <div class="kepala-sub">Belum ada akun. Akun pertama ini menjadi admin (Akses Penuh).</div>

    <?php if ($pesan_error): ?>
        <div class="alert alert-danger" role="alert"><?= e($pesan_error); ?></div>
    <?php endif; ?>

    <form method="POST" action="daftar.php">
        <?= csrf_field(); ?>
        <div class="mb-3">
            <label class="form-label" for="nama">Nama</label>
            <input type="text" id="nama" name="nama" class="form-control" value="<?= e($nama); ?>" maxlength="100" required autofocus>
        </div>
        <div class="mb-3">
            <label class="form-label" for="username">Username</label>
            <input type="text" id="username" name="username" class="form-control" value="<?= e($username); ?>" pattern="[A-Za-z0-9_.]{3,50}" title="3-50 karakter: huruf, angka, titik, underscore" autocomplete="username" required>
        </div>
        <div class="mb-3">
            <label class="form-label" for="password">Password</label>
            <div class="input-group">
                <input type="password" id="password" name="password" class="form-control" minlength="8" autocomplete="new-password" required>
                <?= tombol_mata(); ?>
            </div>
            <div class="form-text">Minimal 8 karakter.</div>
        </div>
        <div class="mb-4">
            <label class="form-label" for="konfirmasi">Ulangi password</label>
            <div class="input-group">
                <input type="password" id="konfirmasi" name="konfirmasi" class="form-control" minlength="8" autocomplete="new-password" required>
                <?= tombol_mata(); ?>
            </div>
        </div>
        <button type="submit" class="btn btn-gelap w-100">Buat akun</button>
    </form>
</main>
<div class="kaki">&copy; <?= date('Y'); ?> Aplikasi Pengingat Hari Jadi</div>

<?= kakiHalaman(); ?>
</body>
</html>
