<?php
require __DIR__ . '/koneksi.php';
require_once __DIR__ . '/session_handler.php';

date_default_timezone_set('Asia/Jakarta');

// Cek status login
if (!isset($_SESSION['login'])) {
    header("Location: login.php");
    exit;
}

// --- FUNGSI BANTU HITUNG SELISIH HARI ---
function hitungSisaHari($tanggal_akhir) {
    if (empty($tanggal_akhir)) {
        return 0;
    }

    $today  = new DateTime('today');
    $target = new DateTime($tanggal_akhir);
    $target->setTime(0, 0, 0);

    $diff = $today->diff($target);

    return ($diff->invert == 1) ? -$diff->days : $diff->days;
}

// --- LOGIKA 1. TAMBAH DATA ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tambah_tugas'])) {
    $judul       = trim($_POST['judul'] ?? '');
    $deskripsi   = trim($_POST['deskripsi'] ?? '');
    $deadline    = $_POST['deadline'] ?? '';
    $id_kategori = !empty($_POST['id_kategori']) ? $_POST['id_kategori'] : null;

    if (!empty($judul) && !empty($deadline)) {
        $stmt = $pdo->prepare("INSERT INTO tugas (judul, deskripsi, deadline, id_kategori, status) VALUES (?, ?, ?, ?, 'Belum Selesai')");
        $stmt->execute([$judul, $deskripsi, $deadline, $id_kategori]);
        
        header("Location: dashboard.php");
        exit;
    }
}

// --- LOGIKA 2. UPDATE STATUS TUGAS ---
if (isset($_GET['toggle_status']) && isset($_GET['id'])) {
    $id_tugas = (int)$_GET['id'];
    $status_baru = $_GET['toggle_status'] === 'Selesai' ? 'Selesai' : 'Belum Selesai';

    $stmt = $pdo->prepare("UPDATE tugas SET status = ? WHERE id = ?");
    $stmt->execute([$status_baru, $id_tugas]);

    header("Location: dashboard.php");
    exit;
}

// --- LOGIKA 3. HAPUS TUGAS ---
if (isset($_GET['hapus']) && isset($_GET['id'])) {
    $id_tugas = (int)$_GET['id'];

    $stmt = $pdo->prepare("DELETE FROM tugas WHERE id = ?");
    $stmt->execute([$id_tugas]);

    header("Location: dashboard.php");
    exit;
}

// --- AMBIL DATA KATEGORI UNTUK DROPDOWN ---
$stmtKategori = $pdo->query("SELECT * FROM kategori ORDER BY nama_kategori ASC");
$kategori_list = $stmtKategori->fetchAll();

// --- AMBIL DATA TUGAS UNTUK TABEL/DASHBOARD ---
$queryTugas = "
    SELECT t.*, k.nama_kategori 
    FROM tugas t 
    LEFT JOIN kategori k ON t.id_kategori = k.id 
    ORDER BY t.deadline ASC
";
$stmtTugas = $pdo->query($queryTugas);
$tugas_list = $stmtTugas->fetchAll();
?>
