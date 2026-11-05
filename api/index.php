<?php
/**
 * Halaman utama: arahkan ke dashboard jika sudah login, ke login jika belum.
 */
require_once __DIR__ . '/lib/sesi.php';

header('Location: ' . (!empty($_SESSION['login']) ? 'dashboard.php' : 'login.php'));
exit;
