<?php
require 'koneksi.php';

// Jika sudah login, langsung alihkan ke dashboard
if (isset($_SESSION['login'])) {
    header("Location: dashboard.php");
    exit;
}

$error = false;
$errorMessage = "";
$success = false;

if (isset($_POST['register'])) {
    $nama_lengkap = mysqli_real_escape_string($koneksi, trim($_POST['nama_lengkap']));
    $username     = mysqli_real_escape_string($koneksi, trim($_POST['username']));
    $password     = $_POST['password'];
    $confirm_pwd  = $_POST['confirm_password'];

    // 1. Validasi konfirmasi password
    if ($password !== $confirm_pwd) {
        $error = true;
        $errorMessage = "Konfirmasi password tidak sesuai!";
    } else {
        // 2. Cek apakah username sudah digunakan
        $checkUser = mysqli_query($koneksi, "SELECT username FROM users WHERE username = '$username'");
        if (mysqli_num_rows($checkUser) > 0) {
            $error = true;
            $errorMessage = "Username sudah terdaftar! Gunakan username lain.";
        } else {
            // 3. Enkripsi password menggunakan Bcrypt (password_hash)
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $tanggal = date('Y-m-d H:i:s');

            // 4. Simpan data ke tabel users
            $query = "INSERT INTO users (username, password, nama_lengkap, dibuat_tanggal) 
                      VALUES ('$username', '$passwordHash', '$nama_lengkap', '$tanggal')";
            
            if (mysqli_query($koneksi, $query)) {
                $success = true;
            } else {
                $error = true;
                $errorMessage = "Gagal mendaftar, terjadi kesalahan sistem.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Reminders App</title>
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
        .card-register {
            border: none;
            border-radius: 16px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
            width: 100%;
            max-width: 450px;
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
        .register-icon {
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

    <div class="card card-register p-4 m-3">
        <div class="card-body text-center p-2">
            <div class="register-icon">
                <i class="fa-solid fa-user-plus"></i>
            </div>
            
            <h4 class="fw-bold text-dark mb-1">Buat Akun Baru</h4>
            <p class="text-muted small mb-4">Lengkapi data di bawah untuk mendaftar</p>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show text-start small py-2 px-3 mb-3" role="alert">
                    <i class="fa-solid fa-circle-exclamation me-1"></i> <?= $errorMessage; ?>
                    <button type="button" class="btn-close py-2" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success text-start small py-2 px-3 mb-3" role="alert">
                    <i class="fa-solid fa-circle-check me-1"></i> Pendaftaran berhasil! Silakan <a href="login.php" class="alert-link">Login di sini</a>.
                </div>
            <?php else: ?>

            <form method="POST" class="text-start">
                <div class="mb-3">
                    <label class="form-label text-secondary small fw-bold">Nama Lengkap</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light text-secondary"><i class="fa-solid fa-id-card"></i></span>
                        <input type="text" name="nama_lengkap" class="form-control" placeholder="Masukkan nama lengkap" required autofocus>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label text-secondary small fw-bold">Username</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light text-secondary"><i class="fa-solid fa-user"></i></span>
                        <input type="text" name="username" class="form-control" placeholder="Masukkan username" required>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label text-secondary small fw-bold">Password</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light text-secondary"><i class="fa-solid fa-lock"></i></span>
                        <input type="password" name="password" class="form-control" placeholder="Buat password" required>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label text-secondary small fw-bold">Konfirmasi Password</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light text-secondary"><i class="fa-solid fa-key"></i></span>
                        <input type="password" name="confirm_password" class="form-control" placeholder="Ulangi password" required>
                    </div>
                </div>

                <button type="submit" name="register" class="btn btn-primary w-100 mb-3">
                    <i class="fa-solid fa-user-plus me-1"></i> Daftar Sekarang
                </button>
            </form>
            <?php endif; ?>

            <div class="text-center mt-2">
                <p class="text-muted small mb-0">Sudah punya akun? <a href="login.php" class="text-primary text-decoration-none fw-bold">Login</a></p>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.bundle.min.js"></script>
</body>
</html>