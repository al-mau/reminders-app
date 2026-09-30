<?php
$host     = "mysql-1f97f1c1-alifmaulanarangkuti-7650.j.aivencloud.com";
$user     = "avnadmin";
$password = "AVNS_k7QBEewzSPdzhVp8CAg";
$dbname   = "defaultdb";
$port     = 22147;

try {
    $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
    
    $options = [
        PDO::ATTR_ERRMODE                  => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE       => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES         => false,
        // Konfigurasi wajib SSL untuk Aiven
        PDO::MYSQL_ATTR_SSL_CA             => true,
        PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
    ];

    $pdo = new PDO($dsn, $user, $password, $options);
} catch (PDOException $e) {
    die("Koneksi Database Gagal: " . $e->getMessage());
}
?>
