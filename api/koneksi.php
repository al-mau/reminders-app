<?php
$host = "mysql-1f97f1c1-alifmaulanarangkuti-7650.j.aivencloud.com";
$user = "avnadmin";
$pass = "AVNS_k7QBEewzSPdzhVp8CAg"; // Isi dengan password Aiven Anda
$db   = "defaultdb";
$port = 22147;

// Inisialisasi koneksi MySQLi
$koneksi = mysqli_init();

// Matikan verifikasi sertifikat SSL agar dapat terhubung dari Vercel
mysqli_ssl_set($koneksi, NULL, NULL, NULL, NULL, NULL);
mysqli_options($koneksi, MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, false);

// Lakukan koneksi menggunakan mysqli_real_connect
$success = mysqli_real_connect($koneksi, $host, $user, $pass, $db, $port, NULL, MYSQLI_CLIENT_SSL);

if (!$success) {
    die("Koneksi gagal: " . mysqli_connect_error());
}
?>
