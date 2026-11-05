<?php
/**
 * HALAMAN LOGIN
 * Alur: cek token CSRF -> cek apakah sedang dikunci (terlalu sering salah) ->
 * cocokkan password -> buat sesi login -> masuk ke dashboard.
 * Belum ada akun sama sekali (instalasi baru) -> diarahkan ke daftar.php untuk membuat admin pertama.
 */
require_once __DIR__ . '/lib/sesi.php';
require_once __DIR__ . '/lib/skema.php';
require_once __DIR__ . '/lib/tampilan.php';

if (!empty($_SESSION['login'])) {
    header('Location: dashboard.php');
    exit;
}

pastikanSkema($pdo);
if ((int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() === 0) {
    header('Location: daftar.php');
    exit;
}

$pesan_error = '';
$username    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (!csrf_valid()) {
        $pesan_error = 'Sesi formulir kedaluwarsa, silakan coba lagi.';
    } elseif ($username === '' || $password === '') {
        $pesan_error = 'Username dan password wajib diisi!';
    } elseif (($sisaKunci = sisaKunciLogin($pdo, $username, ipKlien())) > 0) {
        // Terlalu banyak percobaan gagal: tolak tanpa memeriksa password
        $pesan_error = 'Terlalu banyak percobaan login gagal. Coba lagi dalam ' . (int) ceil($sisaKunci / 60) . ' menit.';
    } else {
        $stmt = $pdo->prepare("SELECT id, username, password FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
                $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
            }
            // Ganti ID sesi setelah login (mencegah session fixation)
            session_regenerate_id(true);
            $_SESSION['login']    = true;
            $_SESSION['user_id']  = (int) $user['id'];
            $_SESSION['username'] = $user['username'];
            hapusLoginGagal($pdo, $username, ipKlien());

            header('Location: dashboard.php');
            exit;
        }

        // Password salah: catat, lalu cek apakah sekarang harus dikunci 15 menit
        catatLoginGagal($pdo, $username, ipKlien());
        $pesan_error = sisaKunciLogin($pdo, $username, ipKlien()) > 0
            ? 'Terlalu banyak percobaan login gagal. Coba lagi dalam ' . LOGIN_KUNCI_MENIT . ' menit.'
            : 'Username atau password salah!';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <?= kepalaHalaman('Masuk'); ?>
</head>
<body class="auth">

<?= merekAuth(); ?>
<main class="auth-kotak">
    <h1>Masuk</h1>
    <div class="kepala-sub">Gunakan username dan password akun Anda.</div>
    <?= tampilkanFlash(); ?>

    <?php if ($pesan_error): ?>
        <div class="alert alert-danger" role="alert"><?= e($pesan_error); ?></div>
    <?php endif; ?>

    <form method="POST" action="login.php">
        <?= csrf_field(); ?>
        <div class="mb-3">
            <label class="form-label" for="username">Username</label>
            <input type="text" id="username" name="username" class="form-control" value="<?= e($username); ?>" autocomplete="username" required autofocus>
        </div>
        <div class="mb-4">
            <label class="form-label" for="password">Password</label>
            <div class="input-group">
                <input type="password" id="password" name="password" class="form-control" autocomplete="current-password" required>
                <?= tombol_mata(); ?>
            </div>
        </div>
        <button type="submit" class="btn btn-gelap w-100">Masuk</button>
    </form>

    <div class="auth-bawah">Belum punya akun? Hubungi admin untuk dibuatkan akun.</div>
</main>
<div class="kaki">&copy; <?= date('Y'); ?> Aplikasi Pengingat Hari Jadi</div>

<?= kakiHalaman(); ?>
</body>
</html>
