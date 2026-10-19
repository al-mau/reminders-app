<?php
/**
 * HALAMAN LOGIN (login.php)
 * Alur: cek token CSRF -> cek apakah sedang dikunci (terlalu sering salah) ->
 * cocokkan password -> buat sesi login -> masuk ke dashboard.
 */
require_once __DIR__ . '/lib/koneksi.php';
require_once __DIR__ . '/lib/session_handler.php';
require_once __DIR__ . '/lib/skema.php';
require_once __DIR__ . '/lib/tampilan.php';

// Jika sudah login, redirect ke dashboard
if (!empty($_SESSION['login'])) {
    header("Location: dashboard.php");
    exit;
}

$pesan_error = '';
$username    = '';

// Proses saat tombol Login ditekan (form dikirim dengan method POST)
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
        try {
            // Cari user berdasarkan username (prepared statement -> aman dari SQL injection)
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username LIMIT 1");
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch();

            $password_valid = false;
            if ($user) {
                $stored   = (string) $user['password'];
                $isHashed = !empty(password_get_info($stored)['algo']);

                if ($isHashed) {
                    $password_valid = password_verify($password, $stored);
                } else {
                    // Akun lama yang password-nya masih plain text
                    $password_valid = hash_equals($stored, $password);
                }

                // Upgrade otomatis ke hash terbaru (termasuk akun plain text)
                if ($password_valid && (!$isHashed || password_needs_rehash($stored, PASSWORD_DEFAULT))) {
                    // Pakai username sebagai kunci (struktur tabel users bisa berbeda-beda)
                    $upd = $pdo->prepare("UPDATE users SET password = ? WHERE username = ?");
                    $upd->execute([password_hash($password, PASSWORD_DEFAULT), $user['username']]);
                }
            }

            if ($password_valid) {
                // Ganti ID sesi setelah login (mencegah session fixation)
                session_regenerate_id(true);
                $_SESSION['login']    = true;
                $_SESSION['user_id']  = $user['id'] ?? $user['id_user'] ?? null;
                $_SESSION['username'] = $user['username'];

                hapusLoginGagal($pdo, $username, ipKlien());

                header("Location: dashboard.php");
                exit;
            }

            // Password salah: catat, lalu cek apakah sekarang harus dikunci 15 menit
            catatLoginGagal($pdo, $username, ipKlien());
            if (sisaKunciLogin($pdo, $username, ipKlien()) > 0) {
                $pesan_error = 'Terlalu banyak percobaan login gagal. Coba lagi dalam ' . LOGIN_KUNCI_MENIT . ' menit.';
            } else {
                $pesan_error = 'Username atau password salah!';
            }
        } catch (Exception $e) {
            error_log('Login error: ' . $e->getMessage());
            $pesan_error = 'Terjadi kesalahan sistem, silakan coba lagi.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <?= kepalaHalaman('Masuk'); ?>
</head>
<body class="auth">

<?= sisiAuth(); ?>

<main class="auth-isi">
    <div class="auth-kotak">
        <h1>Masuk</h1>
        <div class="kepala-sub">Gunakan username dan password akun Anda.</div>

        <?php if (!empty($pesan_error)): ?>
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
            <button type="submit" name="login" class="btn btn-gelap w-100">Masuk</button>
        </form>

        <div class="auth-bawah">
            <?php // Link "Daftar" hanya muncul bila ALLOW_REGISTER=true (atau belum ada user sama sekali) ?>
            <?php if (pendaftaranDibuka()): ?>
                Belum punya akun? <a href="register.php">Daftar</a>
            <?php else: ?>
                Belum punya akun? Hubungi admin untuk dibuatkan akun.
            <?php endif; ?>
        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?= skrip_mata(); ?>
</body>
</html>
