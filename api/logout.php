<?php
require_once __DIR__ . '/lib/koneksi.php';
require_once __DIR__ . '/lib/session_handler.php';

// Hapus semua data di session
$_SESSION = array();

// Hapus cookie session dari browser
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Hapus session dari database melalui handler
session_destroy();

// Redirect balik ke login
header("Location: login.php");
exit;
