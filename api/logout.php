<?php
/**
 * LOGOUT: hapus sesi login (file sesi & cookie browser), lalu kembali ke halaman login.
 */
require_once __DIR__ . '/lib/sesi.php';

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();

header('Location: login.php');
exit;
