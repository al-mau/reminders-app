<?php
require_once __DIR__ . '/lib/koneksi.php';
require_once __DIR__ . '/lib/session_handler.php';

header('Location: ' . (!empty($_SESSION['login']) ? 'dashboard.php' : 'login.php'));
exit;
