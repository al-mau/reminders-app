<?php
$host     = "mysql-1f97f1c1-alifmaulanarangkuti-7650.j.aivencloud.com";
$user     = "avnadmin";
$pass     = "AVNS_k7QBEewzSPdzhVp8CAg"; // ganti dengan password Aiven Anda
$db       = "defaultdb";
$port     = 22147;

// Lakukan koneksi
$koneksi = mysqli_connect($host, $user, $pass, $db, $port);

if (!$koneksi) {
    die("Koneksi gagal: " . mysqli_connect_error());
}
?>
