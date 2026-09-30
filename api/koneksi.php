<?php
$host = "mysql-1f97f1c1-alifmaulanarangkuti-7650.j.aivencloud.com";
$user = "avnadmin";
$pass = "AVNS_k7QBEewzSPdzhVp8CAg"; // Isi dengan password Aiven Anda
$db   = "defaultdb";
$port = 22147;

$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";
$options = [
    PDO::MYSQL_ATTR_SSL_CA => true,
    PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
];

try {
    $conn = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    die("Koneksi gagal: " . $e->getMessage());
}
