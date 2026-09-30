<?php
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/session_handler.php';

// Jika sudah login, redirect ke dashboard
if (isset($_SESSION['login'])) {
    header("Location: dashboard.php");
    exit;
}

$pesan_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_POST['login'])) {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!empty($username) && !empty($password)) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username");
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                // Verifikasi password (password_verify untuk hash, atau cek langsung jika plain text)
                $password_valid = false;
                if (password_verify($password, $user['password'])) {
                    $password_valid = true;
                } elseif ($password === $user['password']) {
                    $password_valid = true;
                }

                if ($password_valid) {
                    $_SESSION['login'] = true;
                    $_SESSION['username'] = $user['username'];

                    header("Location: dashboard.php");
                    exit;
                } else {
                    $pesan_error = 'Password salah!';
                }
            } else {
                $pesan_error = 'Username tidak ditemukan!';
            }
        } catch (Exception $e) {
            $pesan_error = 'Terjadi kesalahan sistem: ' . $e->getMessage();
        }
    } else {
        $pesan_error = 'Username dan password wajib diisi!';
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
            <small><?= htmlspecialchars($pesan_error); ?></small>
        </div>
    <?php endif; ?>

    <form method="POST" action="login.php">
        <div class="mb-3">
            <label class="form-label text-secondary small">Username</label>
            <div class="input-group">
                <span class="input-group-text bg-dark border-0 text-secondary"><i class="fa-solid fa-user"></i></span>
                <input type="text" name="username" class="form-control bg-dark text-white border-0" placeholder="username" required>
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label text-secondary small">Password</label>
            <div class="input-group">
                <span class="input-group-text bg-dark border-0 text-secondary"><i class="fa-solid fa-lock"></i></span>
                <input type="password" name="password" class="form-control bg-dark text-white border-0" placeholder="••••••••" required>
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
