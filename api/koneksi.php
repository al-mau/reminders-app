<?php
$host     = 'mysql-1f97f1c1-alifmaulanarangkuti-7650.aivencloud.com'; // Isi dengan Host dari Aiven
$user     = 'avnadmin';                       // User default Aiven
$password = getenv('DB_PASSWORD') ?: 'password_lama_anda';           // Password dari Aiven
$database = 'defaultdb';                      // Database default Aiven
$port     = 22147;                            // Port khusus dari Aiven

// Koneksi ke MySQL Aiven dengan Port Khusus
$koneksi = mysqli_connect($host, $user, $password, $database, $port);

if (!$koneksi) {
    die("Koneksi ke Aiven Gagal: " . mysqli_connect_error());
}
?>
