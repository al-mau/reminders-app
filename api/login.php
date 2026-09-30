<?php
session_start();

require 'koneksi.php';

// Jika sudah login, langsung alihkan ke dashboard
if (isset($_SESSION['login'])) {
    header("Location: dashboard.php");
    exit;
}

$error = false;

if (isset($_POST['login'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    try {
        // Menggunakan Prepared Statement PDO (Aman dari SQL Injection & Cocok dengan koneksi.php)
        $stmt = $conn->prepare("SELECT * FROM users WHERE username = :username");
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            // Verifikasi password (cocok untuk password ter-hash maupun plain text)
            if (password_verify($password, $user['password']) || $password === $user['password']) {
                $_SESSION['login']    = true;
                $_SESSION['username'] = $user['username'];
                
                header("Location: dashboard.php");
                exit;
            }
        }
        
        $error = true;
    } catch (PDOException $e) {
        $error = true;
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #0f172a, #1e293b);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .card-login {
            border: none;
            border-radius: 16px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
            width: 100%;
            max-width: 400px;
            background-color: #ffffff;
        }
        .btn-primary {
            background-color: #2563eb;
            border: none;
            border-radius: 8px;
            padding: 12px;
            font-weight: 600;
        }
        .btn-primary:hover {
            background-color: #1d4ed8;
        }
        .form-control {
            border-radius: 8px;
            padding: 10px 14px;
        }
        .form-control:focus {
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.2);
        }
        .login-icon {
            width: 60px;
            height: 60px;
            background-color: #eff6ff;
            color: #2563eb;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin: 0 auto 15px auto;
        }
    </style>
</head>
<body>

    <div class="card card-login p-4 m-3">
        <div class="card-body text-center p-2">
            <div class="login-icon">
                <i class="fa-solid fa-bell"></i>
            </div>
            
            <h4 class="fw-bold text-dark mb-1">Reminders App</h4>
            <p class="text-muted small mb-4">Masukkan username & password untuk masuk</p>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show text-start small py-2 px-3 mb-3" role="alert">
                    <i class="fa-solid fa-circle-exclamation me-1"></i> Username atau password salah!
                    <button type="button" class="btn-close py-2" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <form action="login.php" method="POST" class="text-start">
                <div class="mb-3">
                    <label class="form-label text-secondary small fw-bold">Username</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light text-secondary"><i class="fa-solid fa-user"></i></span>
                        <input type="text" name="username" class="form-control" placeholder="Masukkan username" required autofocus>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label text-secondary small fw-bold">Password</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light text-secondary"><i class="fa-solid fa-lock"></i></span>
                        <input type="password" name="password" class="form-control" placeholder="Masukkan password" required>
                    </div>
                </div>

                <button type="submit" name="login" class="btn btn-primary w-100 mb-2">
                    <i class="fa-solid fa-right-to-bracket me-1"></i> Login
                </button>
            </form>

            <div class="text-center mt-3 pt-2 border-top">
                <p class="text-muted small mb-0">
                    Belum punya akun? <a href="register.php" class="text-primary text-decoration-none fw-bold">Daftar sekarang</a>
                </p>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
