<?php
/**
 * HALAMAN DAFTAR AKUN (register.php)
 * Default DITUTUP: hanya bisa dipakai bila ALLOW_REGISTER=true di Environment Variables,
 * atau saat tabel users masih kosong (untuk membuat admin pertama).
 * Penambahan akun sehari-hari dilakukan admin lewat menu Kelola User (users.php).
 */
require_once __DIR__ . '/lib/koneksi.php';
require_once __DIR__ . '/lib/session_handler.php';
require_once __DIR__ . '/lib/tampilan.php';

// Jika sudah login, langsung alihkan ke dashboard
if (!empty($_SESSION['login'])) {
    header("Location: dashboard.php");
    exit;
}

// Pendaftaran publik default ditutup; buka sementara dengan env ALLOW_REGISTER=true.
// Admin yang sudah login bisa menambah akun lewat halaman Kelola User (users.php).
$registerAktif = pendaftaranDibuka();

$error        = false;
$errorMessage = "";
$success      = false;
$old          = ['nama_lengkap' => '', 'username' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nama_lengkap = trim((string) ($_POST['nama_lengkap'] ?? ''));
    $username     = trim((string) ($_POST['username'] ?? ''));
    $password     = (string) ($_POST['password'] ?? '');
    $confirm_pwd  = (string) ($_POST['confirm_password'] ?? '');
    $old          = ['nama_lengkap' => $nama_lengkap, 'username' => $username];

    // Validasi berurutan; pesan error pertama yang cocok yang ditampilkan
    $error = true;
    if (!$registerAktif) {
        $errorMessage = "Pendaftaran akun baru sedang dinonaktifkan.";
    } elseif (!csrf_valid()) {
        $errorMessage = "Sesi formulir kedaluwarsa, silakan coba lagi.";
    } elseif ($nama_lengkap === '' || $username === '' || $password === '') {
        $errorMessage = "Semua kolom wajib diisi!";
    } elseif (!preg_match('/^[A-Za-z0-9_.]{3,50}$/', $username)) {
        $errorMessage = "Username 3-50 karakter, hanya huruf, angka, titik, dan underscore.";
    } elseif (strlen($password) < 6) {
        $errorMessage = "Password minimal 6 karakter.";
    } elseif ($password !== $confirm_pwd) {
        $errorMessage = "Konfirmasi password tidak sesuai!";
    } else {
        try {
            // Cek apakah username sudah digunakan
            $cek = $pdo->prepare("SELECT 1 FROM users WHERE username = ? LIMIT 1");
            $cek->execute([$username]);

            if ($cek->fetchColumn()) {
                $errorMessage = "Username sudah terdaftar! Gunakan username lain.";
            } else {
                // Password disimpan dalam bentuk hash (tidak bisa dibaca), bukan teks asli
                $stmt = $pdo->prepare("INSERT INTO users (username, password, nama_lengkap, dibuat_tanggal) VALUES (?, ?, ?, ?)");
                $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $nama_lengkap, date('Y-m-d H:i:s')]);

                $error   = false;
                $success = true;
            }
        } catch (PDOException $e) {
            error_log('Register error: ' . $e->getMessage());
            $errorMessage = "Gagal mendaftar, terjadi kesalahan sistem.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <?= kepalaHalaman('Daftar'); ?>
</head>
<body class="auth">

<?= merekAuth(); ?>
<main class="auth-kotak">
    <h1>Buat akun</h1>

    <?php if (!$registerAktif): ?>
        <div class="kepala-sub">Pendaftaran akun ditutup.</div>
        <p class="catatan mx-0 mb-4">Akun baru hanya bisa dibuatkan oleh admin melalui menu <b>Pengguna</b> di aplikasi.</p>
        <a href="login.php" class="btn btn-gelap w-100">Kembali ke halaman masuk</a>
    <?php else: ?>
        <div class="kepala-sub">Lengkapi data di bawah untuk mendaftar.</div>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= e($errorMessage); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success" role="alert">Pendaftaran berhasil. Silakan <a href="login.php" class="alert-link">masuk di sini</a>.</div>
        <?php else: ?>
        <form method="POST" action="register.php">
            <?= csrf_field(); ?>
            <div class="mb-3">
                <label class="form-label" for="nama_lengkap">Nama lengkap</label>
                <input type="text" id="nama_lengkap" name="nama_lengkap" class="form-control" value="<?= e($old['nama_lengkap']); ?>" maxlength="100" required autofocus>
            </div>
            <div class="mb-3">
                <label class="form-label" for="username">Username</label>
                <input type="text" id="username" name="username" class="form-control" value="<?= e($old['username']); ?>" maxlength="50" autocomplete="username" required>
            </div>
            <div class="mb-3">
                <label class="form-label" for="password">Password <span class="redup fw-normal">(minimal 6 karakter)</span></label>
                <div class="input-group">
                    <input type="password" id="password" name="password" class="form-control" minlength="6" autocomplete="new-password" required>
                    <?= tombol_mata(); ?>
                </div>
            </div>
            <div class="mb-4">
                <label class="form-label" for="confirm_password">Konfirmasi password</label>
                <div class="input-group">
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" minlength="6" autocomplete="new-password" required>
                    <?= tombol_mata(); ?>
                </div>
            </div>
            <button type="submit" name="register" class="btn btn-gelap w-100">Daftar</button>
        </form>
        <?php endif; ?>
    <?php endif; /* registerAktif */ ?>

    <div class="auth-bawah">Sudah punya akun? <a href="login.php">Masuk</a></div>
</main>
<div class="kaki">&copy; <?= date('Y'); ?> Aplikasi Pengingat Jadwal</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?= skrip_mata(); ?>
</body>
</html>
