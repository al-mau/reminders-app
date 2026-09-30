<?php
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/session_handler.php';

// Jika sudah login, redirect ke dashboard
if (!empty($_SESSION['login'])) {
    header("Location: dashboard.php");
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
    } else {
        try {
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
                    $upd = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $upd->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
                }
            }

            if ($password_valid) {
                session_regenerate_id(true);
                $_SESSION['login']    = true;
                $_SESSION['user_id']  = $user['id'];
                $_SESSION['username'] = $user['username'];

                header("Location: dashboard.php");
                exit;
            }

            $pesan_error = 'Username atau password salah!';
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
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Reminders App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #1a1e29;
            color: #ffffff;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .card-login {
            background-color: #242a38;
            border-radius: 12px;
            padding: 30px;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
        }
        .btn-primary {
            background-color: #2563eb;
            border: none;
        }
        .btn-primary:hover {
            background-color: #1d4ed8;
        }
    </style>
</head>
<body>

<div class="card-login">
    <div class="text-center mb-4">
        <i class="fa-solid fa-bell fa-2x text-primary mb-2"></i>
        <h4>Reminders App</h4>
        <p class="text-secondary small">Masukkan username & password untuk masuk</p>
    </div>

    <?php if (!empty($pesan_error)): ?>
        <div class="alert alert-danger text-center py-2 mb-3" role="alert">
            <small><?= e($pesan_error); ?></small>
        </div>
    <?php endif; ?>

    <form method="POST" action="login.php">
        <?= csrf_field(); ?>
        <div class="mb-3">
            <label class="form-label text-secondary small">Username</label>
            <div class="input-group">
                <span class="input-group-text bg-dark border-0 text-secondary"><i class="fa-solid fa-user"></i></span>
                <input type="text" name="username" class="form-control bg-dark text-white border-0" placeholder="username" value="<?= e($username); ?>" autocomplete="username" required autofocus>
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label text-secondary small">Password</label>
            <div class="input-group">
                <span class="input-group-text bg-dark border-0 text-secondary"><i class="fa-solid fa-lock"></i></span>
                <input type="password" name="password" class="form-control bg-dark text-white border-0" placeholder="••••••••" autocomplete="current-password" required>
            </div>
        </div>

        <button type="submit" name="login" class="btn btn-primary w-100 py-2 mt-2">
            <i class="fa-solid fa-right-to-bracket me-1"></i> Login
        </button>
    </form>

    <div class="text-center mt-4">
        <small class="text-secondary">Belum punya akun? <a href="register.php" class="text-primary text-decoration-none fw-bold">Daftar sekarang</a></small>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
