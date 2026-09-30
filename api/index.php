<?php
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/session_handler.php';

header('Location: ' . (!empty($_SESSION['login']) ? 'dashboard.php' : 'login.php'));
exit;
