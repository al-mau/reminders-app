<?php
/**
 * DASHBOARD (halaman utama setelah login)
 *
 * Susunan file ini:
 *  1. AKSI             -> proses tombol/form (tambah, edit, hapus unit, kirim WA manual,
 *                         reset pengingat, lampiran, penerima WA). Semua lewat POST + CSRF.
 *  2. DATA TAMPILAN    -> statistik, pencarian/filter/halaman tabel, lampiran, penerima, riwayat WA
 *  3. HTML             -> kartu statistik, form input, daftar penerima, tabel unit,
 *                         riwayat WA, popup edit & popup lampiran
 *  4. JavaScript       -> upload lampiran per potongan + progress bar
 */
require_once __DIR__ . '/lib/koneksi.php';
require_once __DIR__ . '/lib/session_handler.php';
require_once __DIR__ . '/lib/fonnte.php';

// Cek status login: belum login -> lempar ke halaman login
if (empty($_SESSION['login'])) {
    header("Location: login.php");
    exit;
}

// Jangan simpan halaman di cache browser (data harus selalu terbaru)
header('Cache-Control: no-cache, no-store, must-revalidate');

pastikanTabelTambahan($pdo); // buat tabel tambahan jika belum ada (lihat lib/skema.php)

/** Label unit untuk pesan notifikasi, contoh: "01 adrian group" */
function labelUnit(PDO $pdo, int $id): string
{
    $stmt = $pdo->prepare("SELECT kode_unit, nama_unit FROM deadline WHERE id = ?");
    $stmt->execute([$id]);
    $u = $stmt->fetch();
    return $u ? "{$u['kode_unit']} {$u['nama_unit']}" : "#$id";
}

/** Balasan JSON untuk permintaan dari JavaScript (upload lampiran berpotongan) */
function jsonKeluar(array $data, int $kode = 200): void
{
    http_response_code($kode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

/** Huruf pertama nama (untuk lingkaran avatar penerima WA), contoh: "Alif" -> "A" */
function inisial(string $nama): string
{
    $nama = trim($nama);
    if ($nama === '') {
        return '?';
    }
    return function_exists('mb_substr') ? mb_strtoupper(mb_substr($nama, 0, 1)) : strtoupper(substr($nama, 0, 1));
}

/** Simpan pesan notifikasi (hijau/merah) untuk ditampilkan setelah halaman dimuat ulang */
function flash(string $tipe, string $pesan): void
{
    $_SESSION['flash'][] = [$tipe, $pesan];
}

// ===================================================================
// AKSI (semua perubahan data wajib POST + token CSRF)
// ===================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Upload melebihi post_max_size -> PHP mengosongkan $_POST
    if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        flash('danger', 'Ukuran upload terlalu besar. Maksimal ' . formatUkuran(LAMPIRAN_MAKS_BYTE) . ' per file.');
        header("Location: dashboard.php");
        exit;
    }

    $ajax = !empty($_POST['ajax']);

    if (!csrf_valid()) {
        if ($ajax) {
            jsonKeluar(['ok' => false, 'pesan' => 'Sesi formulir kedaluwarsa, muat ulang halaman.'], 403);
        }
        header("Location: dashboard.php?status=csrf");
        exit;
    }

    $aksi = $_POST['aksi'] ?? '';

    // --- UPLOAD LAMPIRAN BERPOTONGAN (dipanggil JavaScript) ---
    if (in_array($aksi, ['lampiran_mulai', 'lampiran_bagian', 'lampiran_selesai'], true)) {
        try {
            if ($aksi === 'lampiran_mulai') {
                $id = mulaiLampiran($pdo, (int) ($_POST['deadline_id'] ?? 0), (string) ($_POST['nama'] ?? ''),
                    (int) ($_POST['ukuran'] ?? 0), $_SESSION['username'] ?? null);
                jsonKeluar(['ok' => true, 'id' => $id, 'ukuran_bagian' => LAMPIRAN_BAGIAN_BYTE]);
            }
            if ($aksi === 'lampiran_bagian') {
                simpanBagianLampiran($pdo, (int) ($_POST['id'] ?? 0), (int) ($_POST['urutan'] ?? -1), $_FILES['bagian'] ?? []);
                jsonKeluar(['ok' => true]);
            }
            selesaikanLampiran($pdo, (int) ($_POST['id'] ?? 0));
            jsonKeluar(['ok' => true]);
        } catch (InvalidArgumentException $e) {
            jsonKeluar(['ok' => false, 'pesan' => $e->getMessage()], 422);
        }
    }

    // --- 1. TAMBAH / 2. EDIT DATA ---
    if ($aksi === 'simpan' || $aksi === 'update') {
        $id            = (int) ($_POST['id'] ?? 0);
        $kode_unit     = trim($_POST['kode_unit'] ?? '');
        $nama_unit     = trim($_POST['nama_unit'] ?? '');
        $tanggal_awal  = $_POST['tanggal_awal'] ?? '';
        $tanggal_akhir = $_POST['tanggal_akhir'] ?? '';

        if ($kode_unit === '' || $nama_unit === '' || !tanggal_valid($tanggal_awal) || !tanggal_valid($tanggal_akhir)) {
            if ($ajax) {
                jsonKeluar(['ok' => false, 'pesan' => 'Data tidak valid. Pastikan semua kolom terisi dengan benar.'], 422);
            }
            header("Location: dashboard.php?status=invalid");
            exit;
        }
        if ($tanggal_akhir < $tanggal_awal) {
            if ($ajax) {
                jsonKeluar(['ok' => false, 'pesan' => 'Tanggal akhir tidak boleh lebih awal dari tanggal awal.'], 422);
            }
            header("Location: dashboard.php?status=invalid_tanggal");
            exit;
        }

        if ($aksi === 'simpan') {
            $stmt = $pdo->prepare("INSERT INTO deadline (kode_unit, nama_unit, tanggal_awal, tanggal_akhir, pengingat) VALUES (?, ?, ?, ?, 'pending')");
            $stmt->execute([$kode_unit, $nama_unit, $tanggal_awal, $tanggal_akhir]);
            $idBaru = (int) $pdo->lastInsertId();

            // Dari JavaScript: lampiran diunggah per potongan setelah unit dibuat
            if ($ajax) {
                jsonKeluar(['ok' => true, 'id' => $idBaru]);
            }

            $files = daftarFileUpload('lampiran');
            if ($files) {
                $hasil = simpanLampiran($pdo, $idBaru, $files, $_SESSION['username'] ?? null);
                foreach ($hasil['gagal'] as $pesan) {
                    flash('warning', 'Lampiran tidak disimpan — ' . $pesan);
                }
            }
            header("Location: dashboard.php?status=success_add");
        } else {
            // Jika tanggal akhir diubah, reset status pengingat agar cron mengirim ulang
            $stmt = $pdo->prepare(
                "UPDATE deadline
                 SET kode_unit = ?, nama_unit = ?, tanggal_awal = ?,
                     pengingat = IF(tanggal_akhir <> ?, 'pending', pengingat),
                     terakhir_dikirim = IF(tanggal_akhir <> ?, NULL, terakhir_dikirim),
                     tanggal_akhir = ?
                 WHERE id = ?"
            );
            $stmt->execute([$kode_unit, $nama_unit, $tanggal_awal, $tanggal_akhir, $tanggal_akhir, $tanggal_akhir, $id]);
            header("Location: dashboard.php?status=success_update");
        }
        exit;
    }

    // --- 3. HAPUS DATA ---
    if ($aksi === 'hapus') {
        $id = (int) ($_POST['id'] ?? 0);
        hapusLampiranUnit($pdo, $id);
        $pdo->prepare("DELETE FROM deadline WHERE id = ?")->execute([$id]);
        header("Location: dashboard.php?status=success_delete");
        exit;
    }

    // --- 4. KIRIM WA MANUAL ---
    if ($aksi === 'kirim_wa') {
        $stmt = $pdo->prepare("SELECT * FROM deadline WHERE id = ?");
        $stmt->execute([(int) ($_POST['id'] ?? 0)]);
        $data_wa = $stmt->fetch();

        if (!$data_wa) {
            header("Location: dashboard.php?status=not_found");
            exit;
        }

        // Hitung sisa hari untuk ditulis di pesan (0 = hari ini, negatif = sudah lewat)
        $ket_sisa = keteranganSisaHari(hitungSisaHari($data_wa['tanggal_akhir']));

        $pesan  = "*NOTIFIKASI PENGINGAT DEADLINE*\n\n";
        $pesan .= "Halo Admin, berikut detail unit:\n\n";
        $pesan .= "*Kode Unit:* " . $data_wa['kode_unit'] . "\n";
        $pesan .= "*Nama Unit:* " . $data_wa['nama_unit'] . "\n";
        $pesan .= "*Tanggal Awal:* " . date('d-m-Y', strtotime($data_wa['tanggal_awal'])) . "\n";
        $pesan .= "*Tanggal Akhir:* " . date('d-m-Y', strtotime($data_wa['tanggal_akhir'])) . "\n";
        $pesan .= "*Sisa Waktu:* " . $ket_sisa . "\n\n";
        // Kalimat penutup sama dengan pesan otomatis (cron_wa_reminder.php)
        $pesan .= "Mohon segera update kembali usernya sebelum tanggal " . date('d-m-Y', strtotime($data_wa['tanggal_akhir']));

        $label = $data_wa['kode_unit'] . ' ' . $data_wa['nama_unit'];
        // Kirim manual tidak mengubah status pengingat unit (pengingat otomatis tetap berjalan sesuai jadwal)
        $hasil = kirimWhatsApp($pesan, 'manual', $label);
        if (!$hasil['ok']) {
            $_SESSION['flash_wa_error'] = $hasil['pesan'];
        } else {
            flash('success', $hasil['pesan'] . '.');
        }
        header("Location: dashboard.php?status=" . ($hasil['ok'] ? 'wa_sent' : 'wa_failed'));
        exit;
    }

    // --- 4b. RESET PENGINGAT: izinkan cron mengirim ulang unit ini ---
    // (tombol "Kirim ulang" di tabel: pengingat -> pending, terakhir_dikirim -> kosong)
    if ($aksi === 'reset_pengingat') {
        $id = (int) ($_POST['id'] ?? 0);
        // Unit yang sudah expired tidak akan dikirim lagi oleh cron, jadi reset ditolak
        $cek = $pdo->prepare("SELECT tanggal_akhir FROM deadline WHERE id = ?");
        $cek->execute([$id]);
        $tgl = $cek->fetchColumn();
        if ($tgl === false || hitungSisaHari($tgl) < 0) {
            flash('danger', 'Unit ini sudah expired, pengingat otomatis tidak bisa dikirim ulang. Ubah tanggal akhirnya jika masih perlu diingatkan.');
            header("Location: dashboard.php");
            exit;
        }
        $pdo->prepare("UPDATE deadline SET pengingat = 'pending', terakhir_dikirim = NULL WHERE id = ?")->execute([$id]);
        $label = labelUnit($pdo, $id);
        flash('success', "Pengingat $label di-reset. Akan dikirim ulang otomatis pada jadwal cron berikutnya jika deadline-nya hari ini atau besok.");
        header("Location: dashboard.php");
        exit;
    }

    // --- 5. UPLOAD LAMPIRAN KE UNIT YANG SUDAH ADA ---
    if ($aksi === 'upload_lampiran') {
        $deadlineId = (int) ($_POST['deadline_id'] ?? 0);
        $cek = $pdo->prepare("SELECT 1 FROM deadline WHERE id = ?");
        $cek->execute([$deadlineId]);

        if (!$cek->fetchColumn()) {
            header("Location: dashboard.php?status=not_found");
            exit;
        }

        $files = daftarFileUpload('lampiran');
        if (!$files) {
            flash('warning', 'Pilih file yang akan diunggah terlebih dahulu.');
        } else {
            $hasil = simpanLampiran($pdo, $deadlineId, $files, $_SESSION['username'] ?? null);
            if ($hasil['berhasil'] > 0) {
                flash('success', $hasil['berhasil'] . ' lampiran berhasil diunggah.');
            }
            foreach ($hasil['gagal'] as $pesan) {
                flash('danger', 'Lampiran gagal — ' . $pesan);
            }
        }
        header("Location: dashboard.php?lampiran=$deadlineId");
        exit;
    }

    // --- 6. HAPUS LAMPIRAN ---
    if ($aksi === 'hapus_lampiran') {
        $stmt = $pdo->prepare("SELECT deadline_id, nama_file FROM lampiran WHERE id = ?");
        $stmt->execute([(int) ($_POST['id'] ?? 0)]);
        $lamp = $stmt->fetch() ?: ['deadline_id' => 0, 'nama_file' => '?'];
        $deadlineId = (int) $lamp['deadline_id'];

        hapusLampiranLengkap($pdo, (int) ($_POST['id'] ?? 0));
        flash('warning', 'Lampiran dihapus.');
        header("Location: dashboard.php" . ($deadlineId ? "?lampiran=$deadlineId" : ''));
        exit;
    }

    // --- 7. KELOLA PENERIMA WA ---
    if ($aksi === 'tambah_penerima') {
        $nama  = trim((string) ($_POST['nama'] ?? ''));
        $nomor = normalisasiNomorWa((string) ($_POST['nomor'] ?? ''));

        if ($nama === '' || strpos($nomor, ',') !== false || !nomorWaValid($nomor)) {
            flash('danger', 'Nama wajib diisi dan nomor WA harus valid (contoh: 081234567890).');
        } else {
            try {
                $cek = $pdo->prepare("SELECT nama, aktif FROM wa_penerima WHERE nomor = ?");
                $cek->execute([$nomor]);
                $ada = $cek->fetch();

                if ($ada) {
                    flash('warning', "Nomor $nomor sudah terdaftar atas nama \"{$ada['nama']}\" (status: "
                        . ((int) $ada['aktif'] === 1 ? 'aktif' : 'nonaktif — klik tombol ▶ untuk mengaktifkan') . ').');
                } else {
                    $pdo->prepare("INSERT INTO wa_penerima (nama, nomor, dibuat_tanggal) VALUES (?, ?, ?)")
                        ->execute([function_exists('mb_substr') ? mb_substr($nama, 0, 100) : substr($nama, 0, 100), $nomor, date('Y-m-d H:i:s')]);
                    flash('success', "Nomor $nomor ($nama) ditambahkan sebagai penerima WA.");
                }
            } catch (PDOException $e) {
                // Tampilkan penyebab asli agar mudah ditelusuri (hanya terlihat oleh admin yang login)
                error_log('Tambah penerima WA gagal: ' . $e->getMessage());
                flash('danger', 'Gagal menyimpan nomor: ' . $e->getMessage());
            }
        }
        header("Location: dashboard.php#penerima");
        exit;
    }

    if ($aksi === 'toggle_penerima') {
        $pid = (int) ($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE wa_penerima SET aktif = 1 - aktif WHERE id = ?")->execute([$pid]);
        header("Location: dashboard.php#penerima");
        exit;
    }

    if ($aksi === 'hapus_penerima') {
        $pid = (int) ($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM wa_penerima WHERE id = ?")->execute([$pid]);
        flash('warning', 'Nomor penerima dihapus.');
        header("Location: dashboard.php#penerima");
        exit;
    }

    header("Location: dashboard.php");
    exit;
}

// ===================================================================
// DATA UNTUK TAMPILAN
// ===================================================================
$today    = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));

// --- STATISTIK RINGKAS (dihitung langsung di database) ---
$stmtStat = $pdo->prepare(
    "SELECT
        COUNT(*)                                        AS total,
        COALESCE(SUM(tanggal_akhir = :t1), 0)           AS hari_ini,
        COALESCE(SUM(tanggal_akhir = :b1), 0)           AS h1,
        COALESCE(SUM(tanggal_akhir > :b2), 0)           AS mendatang,
        COALESCE(SUM(tanggal_akhir < :t2), 0)           AS expired
     FROM deadline"
);
$stmtStat->execute([':t1' => $today, ':b1' => $tomorrow, ':b2' => $tomorrow, ':t2' => $today]);
$stat = $stmtStat->fetch();

$stat_total     = (int) $stat['total'];
$stat_hari_ini  = (int) $stat['hari_ini'];
$stat_h1        = (int) $stat['h1'];
$stat_mendatang = (int) $stat['mendatang'];
$stat_expired   = (int) $stat['expired'];

// --- SEARCH, FILTER, DAN PAGINATION ---
$search = trim((string) ($_GET['search'] ?? ''));
$filter = (string) ($_GET['filter'] ?? 'semua');
$filter_valid = ['semua', 'hari_ini', 'h1', 'mendatang', 'expired'];
if (!in_array($filter, $filter_valid, true)) {
    $filter = 'semua';
}

$where  = [];
$params = [];

if ($search !== '') {
    $where[]            = "(kode_unit LIKE :s1 OR nama_unit LIKE :s2)";
    $params[':s1']      = "%$search%";
    $params[':s2']      = "%$search%";
}

switch ($filter) {
    case 'hari_ini':
        $where[] = "tanggal_akhir = :f";
        $params[':f'] = $today;
        break;
    case 'h1':
        $where[] = "tanggal_akhir = :f";
        $params[':f'] = $tomorrow;
        break;
    case 'mendatang':
        $where[] = "tanggal_akhir > :f";
        $params[':f'] = $tomorrow;
        break;
    case 'expired':
        $where[] = "tanggal_akhir < :f";
        $params[':f'] = $today;
        break;
}

$where_sql = $where ? "WHERE " . implode(' AND ', $where) : "";

$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM deadline $where_sql");
$stmtCount->execute($params);
$total_rows = (int) $stmtCount->fetchColumn();

$limit       = 10;
$total_pages = (int) ceil($total_rows / $limit);
$page        = max(1, (int) ($_GET['page'] ?? 1));
if ($total_pages > 0 && $page > $total_pages) {
    $page = $total_pages;
}
$offset = ($page - 1) * $limit;

// LIMIT/OFFSET berupa integer hasil casting -> aman disisipkan langsung
$stmtData = $pdo->prepare("SELECT * FROM deadline $where_sql ORDER BY id DESC LIMIT $limit OFFSET $offset");
$stmtData->execute($params);

$data_tampil = [];
foreach ($stmtData->fetchAll() as $row) {
    $row['sisa_hari'] = hitungSisaHari($row['tanggal_akhir']);
    $data_tampil[]    = $row;
}

// --- LAMPIRAN UNTUK UNIT YANG TAMPIL (tanpa isi file) ---
$lampiran_per_unit = [];
if ($data_tampil) {
    $ids = array_map(static fn($r) => (int) $r['id'], $data_tampil);
    $stmtLamp = $pdo->query(
        "SELECT id, deadline_id, nama_file, ekstensi, ukuran, diunggah_oleh, dibuat_tanggal
         FROM lampiran WHERE selesai = 1 AND deadline_id IN (" . implode(',', $ids) . ") ORDER BY id"
    );
    foreach ($stmtLamp->fetchAll() as $l) {
        $lampiran_per_unit[(int) $l['deadline_id']][] = $l;
    }
}
$buka_lampiran = (int) ($_GET['lampiran'] ?? 0);

// --- PENERIMA WA ---
$penerima_list   = $pdo->query("SELECT * FROM wa_penerima ORDER BY id")->fetchAll();
$penerima_aktif  = count(array_filter($penerima_list, static fn($p) => (int) $p['aktif'] === 1));
$wa_target_env   = normalisasiNomorWa((string) env('WA_TARGET', ''));

// --- RIWAYAT PENGIRIMAN WA (30 terakhir) ---
$riwayat_wa    = $pdo->query("SELECT * FROM wa_log ORDER BY id DESC LIMIT 30")->fetchAll();
// Nomor -> nama penerima, agar kolom Penerima di riwayat menampilkan nama (bukan hanya nomor).
// Nomor yang sudah dihapus dari daftar penerima tetap tampil sebagai nomor saja.
$nama_penerima = array_column($penerima_list, 'nama', 'nomor');

// --- PESAN NOTIFIKASI ---
$alerts = [
    'success_add'     => ['success', 'Data berhasil disimpan!'],
    'success_update'  => ['info', 'Data berhasil diperbarui!'],
    'success_delete'  => ['warning', 'Data berhasil dihapus!'],
    'wa_sent'         => ['success', 'Pesan WhatsApp berhasil dikirim!'],
    'wa_failed'       => ['danger', 'Gagal mengirim pesan WhatsApp. Cek token/nomor tujuan.'],
    'invalid'         => ['danger', 'Data tidak valid. Pastikan semua kolom terisi dengan benar.'],
    'invalid_tanggal' => ['danger', 'Tanggal akhir tidak boleh lebih awal dari tanggal awal.'],
    'not_found'       => ['danger', 'Data tidak ditemukan.'],
    'csrf'            => ['danger', 'Sesi formulir kedaluwarsa, silakan ulangi.'],
];
$status_key = (string) ($_GET['status'] ?? '');
$alert      = $alerts[$status_key] ?? null;
$wa_error   = $_SESSION['flash_wa_error'] ?? null;
$flash_list = $_SESSION['flash'] ?? [];
unset($_SESSION['flash_wa_error'], $_SESSION['flash']);

$qs_base = 'search=' . urlencode($search) . '&filter=' . urlencode($filter);

// --- KARTU STATISTIK: [filter, label, jumlah, ikon, warna] ---
$kartu_statistik = [
    ['semua', 'Total Unit', $stat_total, 'fa-layer-group', 'biru'],
    ['hari_ini', 'Hari Ini', $stat_hari_ini, 'fa-circle-exclamation', 'merah'],
    ['h1', 'H-1 (Besok)', $stat_h1, 'fa-clock', 'kuning'],
    ['mendatang', 'Mendatang', $stat_mendatang, 'fa-calendar-days', 'abu'],
    ['expired', 'Expired', $stat_expired, 'fa-calendar-xmark', 'gelap'],
];

// --- TANGGAL HARI INI dalam bahasa Indonesia, contoh: Kamis, 02 Oktober 2026 ---
$nama_hari  = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
$nama_bulan = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$tanggal_hari_ini = $nama_hari[(int) date('w')] . ', ' . date('d') . ' ' . $nama_bulan[(int) date('n')] . ' ' . date('Y');

$accept_lampiran = '.' . implode(',.', array_keys(LAMPIRAN_TIPE));
$info_lampiran   = 'PDF, gambar, Word, Excel, CSV, TXT. Maks ' . formatUkuran(LAMPIRAN_MAKS_BYTE) . ' per file.';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Aplikasi Pengingat Jadwal</title>

    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#1e293b">
    <link rel="apple-touch-icon" href="https://cdn-icons-png.flaticon.com/512/3602/3602145.png">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="tema.css?v=1">
    <style>
        /* Daftar file yang dipilih (sebelum disimpan) */
        .daftar-pilihan .list-group-item { padding: 6px 10px; font-size: .85rem; }
        /* Popup pratinjau file sebelum disimpan (di atas popup Bootstrap) */
        #pratinjauLokal { position: fixed; inset: 0; z-index: 2000; background: rgba(15, 23, 42, .75); display: none; padding: 16px; }
        #pratinjauLokal.tampil { display: flex; }
        #pratinjauLokal .kotak { background: #fff; border-radius: 12px; width: 100%; max-width: 1000px; margin: auto; height: 100%; display: flex; flex-direction: column; overflow: hidden; }
        #pratinjauLokal .bilah { display: flex; align-items: center; gap: 8px; padding: 10px 14px; border-bottom: 1px solid #e2e8f0; }
        #pratinjauLokal .wadah { flex: 1; min-height: 0; background: #f1f5f9; }
        #pratinjauLokal iframe { width: 100%; height: 100%; border: 0; background: #fff; display: block; }
        #pratinjauLokal img { max-width: 100%; max-height: 100%; display: block; margin: auto; }
    </style>
</head>
<body>

    <nav class="navbar navbar-expand-lg navbar-dark mb-4 py-3 shadow-sm">
        <div class="container">
            <a class="navbar-brand fw-bold" href="dashboard.php">
                <i class="fa-solid fa-bell me-2 text-warning"></i> Aplikasi Pengingat Jadwal
            </a>
            <div class="d-flex align-items-center gap-3">
                <span class="text-white-50 d-none d-sm-inline"><i class="fa-solid fa-user me-1"></i> Halo, <strong class="text-white"><?= e($_SESSION['username'] ?? 'Admin'); ?></strong></span>
                <a href="users.php" class="btn btn-outline-light btn-sm rounded-pill px-3" title="Kelola User">
                    <i class="fa-solid fa-users-gear"></i><span class="d-none d-md-inline ms-1">Kelola User</span>
                </a>
                <a href="logout.php" class="btn btn-outline-light btn-sm rounded-pill px-3">
                    <i class="fa-solid fa-right-from-bracket me-1"></i> Logout
                </a>
            </div>
        </div>
    </nav>

    <div class="container">

        <!-- Judul halaman + tanggal hari ini -->
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-4">
            <div>
                <h1 class="judul-halaman">Dashboard</h1>
                <div class="text-redup small">Pantau deadline unit dan pengingat WhatsApp otomatis</div>
            </div>
            <div class="text-redup small"><i class="fa-regular fa-calendar me-1"></i> <?= e($tanggal_hari_ini); ?></div>
        </div>

        <?php if ($alert): ?>
            <div class="alert alert-<?= $alert[0]; ?> alert-dismissible fade show" role="alert">
                <?= e($alert[1]); ?>
                <?php if ($status_key === 'wa_failed' && $wa_error): ?>
                    <br><small>Detail: <?= e($wa_error); ?></small>
                <?php endif; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php foreach ($flash_list as [$tipe, $pesan]): ?>
            <div class="alert alert-<?= e($tipe); ?> alert-dismissible fade show py-2" role="alert">
                <?= e($pesan); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endforeach; ?>

        <!-- Kartu statistik: klik untuk menampilkan unit dengan status tersebut -->
        <div class="row row-cols-2 row-cols-md-3 row-cols-xl-5 g-3 mb-4">
            <?php foreach ($kartu_statistik as [$kunci, $label, $nilai, $ikon, $warna]): ?>
                <div class="col">
                    <a href="dashboard.php?filter=<?= $kunci; ?>#daftar" class="stat stat-<?= $warna; ?> <?= $filter === $kunci ? 'aktif' : ''; ?>" title="Tampilkan: <?= e($label); ?>">
                        <span class="stat-ikon"><i class="fa-solid <?= $ikon; ?>"></i></span>
                        <span><span class="stat-label"><?= e($label); ?></span><span class="stat-angka"><?= (int) $nilai; ?></span></span>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Baris 1: input unit baru (kiri) + daftar unit (kanan) -->
        <div class="row g-4">
            <div class="col-lg-4">
                <div class="card kartu kartu-input h-100">
                    <div class="card-header">
                        <span class="ikon-judul"><i class="fa-solid fa-square-plus"></i></span>
                        <div><h2 class="kartu-judul">Input Unit & Deadline</h2><div class="kartu-sub">Tambah unit baru beserta lampirannya</div></div>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="dashboard.php" enctype="multipart/form-data" data-upload="unit-baru">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="aksi" value="simpan">
                            <div class="mb-3">
                                <label class="form-label text-secondary small fw-bold">Kode Unit</label>
                                <input type="text" name="kode_unit" class="form-control" placeholder="Contoh: 01" maxlength="50" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label text-secondary small fw-bold">Nama Unit</label>
                                <input type="text" name="nama_unit" class="form-control" placeholder="Contoh: adrian group" maxlength="150" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label text-secondary small fw-bold">Tanggal Awal</label>
                                <input type="date" name="tanggal_awal" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label text-secondary small fw-bold">Tanggal Akhir</label>
                                <input type="date" name="tanggal_akhir" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label text-secondary small fw-bold">Lampiran Dokumen <span class="fw-normal">(opsional)</span></label>
                                <input type="file" name="lampiran[]" class="form-control" multiple accept="<?= e($accept_lampiran); ?>">
                                <div class="form-text"><?= e($info_lampiran); ?></div>
                                <div class="progress mt-2 d-none" style="height: 8px;" data-progres><div class="progress-bar" role="progressbar" style="width: 0%"></div></div>
                                <div class="small text-muted mt-1 d-none" data-progres-teks></div>
                            </div>
                            <button type="submit" class="btn btn-primary w-100 mt-2">
                                <i class="fa-solid fa-floppy-disk me-1"></i> Simpan Data
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Di HP daftar unit tampil lebih dulu, form input di bawahnya -->
            <div class="col-lg-8 order-first order-lg-0">
                <div class="card kartu kartu-daftar h-100" id="daftar">
                    <div class="card-header">
                        <span class="ikon-judul"><i class="fa-solid fa-list-check"></i></span>
                        <div class="flex-grow-1"><h2 class="kartu-judul">Daftar Unit & Deadline</h2><div class="kartu-sub"><?= (int) $total_rows; ?> unit<?= ($search !== '' || $filter !== 'semua') ? ' sesuai pencarian / filter' : ''; ?></div></div>
                    </div>
                    <div class="card-body pb-0 flex-grow-0">
                        <form method="GET" action="dashboard.php" class="row g-2 mb-3">
                            <div class="col-md-6">
                                <div class="input-group">
                                    <span class="input-group-text bg-white"><i class="fa-solid fa-magnifying-glass text-secondary"></i></span>
                                    <input type="text" name="search" class="form-control" placeholder="Cari kode / nama unit..." value="<?= e($search); ?>">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <!-- Pilihan status langsung menampilkan hasil tanpa menekan tombol -->
                                <select name="filter" class="form-select" onchange="this.form.submit()">
                                    <option value="semua" <?= $filter === 'semua' ? 'selected' : ''; ?>>Semua Status</option>
                                    <option value="hari_ini" <?= $filter === 'hari_ini' ? 'selected' : ''; ?>>Hari Ini</option>
                                    <option value="h1" <?= $filter === 'h1' ? 'selected' : ''; ?>>H-1 (Besok)</option>
                                    <option value="mendatang" <?= $filter === 'mendatang' ? 'selected' : ''; ?>>Mendatang</option>
                                    <option value="expired" <?= $filter === 'expired' ? 'selected' : ''; ?>>Expired</option>
                                </select>
                            </div>
                            <div class="col-md-2 d-flex gap-2">
                                <button type="submit" class="btn btn-primary w-100" title="Cari"><i class="fa-solid fa-magnifying-glass"></i><span class="d-md-none ms-1">Cari</span></button>
                                <?php if ($search !== '' || $filter !== 'semua'): ?>
                                    <a href="dashboard.php#daftar" class="btn btn-light border" title="Hapus pencarian & filter"><i class="fa-solid fa-xmark"></i></a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle tabel">
                                <thead>
                                    <tr>
                                        <th class="text-center" width="5%">No</th>
                                        <th>Kode</th>
                                        <th>Nama Unit</th>
                                        <th>Tgl Awal</th>
                                        <th>Tgl Akhir</th>
                                        <th class="text-center">Status</th>
                                        <th class="text-center">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $no = $offset + 1; ?>
                                    <?php if (count($data_tampil) > 0): ?>
                                        <?php foreach ($data_tampil as $row):
                                            $sisa_hari = $row['sisa_hari'];

                                            if ($sisa_hari === 0) {
                                                $badge_deadline = '<span class="lencana lencana-merah"><i class="fa-solid fa-circle-exclamation"></i> Hari Ini</span>';
                                            } elseif ($sisa_hari === 1) {
                                                $badge_deadline = '<span class="lencana lencana-kuning"><i class="fa-solid fa-clock"></i> H-1 (Besok)</span>';
                                            } elseif ($sisa_hari > 1) {
                                                $badge_deadline = '<span class="lencana lencana-abu">' . $sisa_hari . ' hari lagi</span>';
                                            } else {
                                                $badge_deadline = '<span class="lencana lencana-gelap"><i class="fa-solid fa-calendar-xmark"></i> Expired</span>';
                                            }
                                        ?>
                                        <tr>
                                            <td class="text-center text-redup"><?= $no++; ?></td>
                                            <td><span class="kode"><?= e($row['kode_unit']); ?></span></td>
                                            <td class="fw-semibold text-dark"><?= e($row['nama_unit']); ?></td>
                                            <td class="text-redup small text-nowrap"><?= date('d M Y', strtotime($row['tanggal_awal'])); ?></td>
                                            <td class="small text-nowrap fw-semibold"><?= date('d M Y', strtotime($row['tanggal_akhir'])); ?></td>
                                            <td class="text-center">
                                                <?= $badge_deadline; ?>
                                                <?php if (!empty($row['terakhir_dikirim'])): ?>
                                                    <div class="info-terkirim" title="Pengingat WA otomatis terakhir terkirim">
                                                        <i class="fa-brands fa-whatsapp"></i> <?= date('d M Y', strtotime($row['terakhir_dikirim'])); ?>
                                                    </div>
                                                    <?php // Tombol "Kirim ulang" disembunyikan untuk unit expired: cron hanya mengirim unit yang deadline-nya hari ini/besok ?>
                                                    <?php if ($sisa_hari >= 0): ?>
                                                    <form method="POST" action="dashboard.php" class="d-inline"
                                                          onsubmit="return confirm('Reset status pengingat unit ini agar dikirim ulang otomatis pada jadwal berikutnya?')">
                                                        <?= csrf_field(); ?>
                                                        <input type="hidden" name="aksi" value="reset_pengingat">
                                                        <input type="hidden" name="id" value="<?= (int) $row['id']; ?>">
                                                        <button type="submit" class="btn btn-link btn-sm p-0 small text-decoration-none" style="font-size:.75rem" title="Kirim ulang otomatis">
                                                            <i class="fa-solid fa-rotate-left"></i> Kirim ulang
                                                        </button>
                                                    </form>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <div class="d-flex justify-content-center gap-1">
                                                    <button type="button" class="btn-ikon btn-ikon-kuning"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#modalEdit<?= (int) $row['id']; ?>"
                                                            title="Edit Data">
                                                        <i class="fa-solid fa-pen-to-square"></i>
                                                    </button>

                                                    <?php $jml_lampiran = count($lampiran_per_unit[(int) $row['id']] ?? []); ?>
                                                    <button type="button" class="btn-ikon btn-ikon-biru"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#modalLampiran<?= (int) $row['id']; ?>"
                                                            title="Lampiran Dokumen">
                                                        <i class="fa-solid fa-paperclip"></i>
                                                        <?php if ($jml_lampiran > 0): ?>
                                                            <span class="jumlah"><?= $jml_lampiran; ?></span>
                                                        <?php endif; ?>
                                                    </button>

                                                    <form method="POST" action="dashboard.php" class="d-inline"
                                                          onsubmit="return confirm('Apakah Anda yakin ingin menghapus data unit ini beserta lampirannya?')">
                                                        <?= csrf_field(); ?>
                                                        <input type="hidden" name="aksi" value="hapus">
                                                        <input type="hidden" name="id" value="<?= (int) $row['id']; ?>">
                                                        <button type="submit" class="btn-ikon btn-ikon-merah" title="Hapus Data">
                                                            <i class="fa-solid fa-trash"></i>
                                                        </button>
                                                    </form>

                                                    <form method="POST" action="dashboard.php" class="d-inline"
                                                          onsubmit="return confirm(this.dataset.konfirmasi)"
                                                          data-konfirmasi="Kirim notifikasi WhatsApp sekarang untuk unit <?= e($row['nama_unit']); ?>?">
                                                        <?= csrf_field(); ?>
                                                        <input type="hidden" name="aksi" value="kirim_wa">
                                                        <input type="hidden" name="id" value="<?= (int) $row['id']; ?>">
                                                        <button type="submit" class="btn-ikon btn-ikon-hijau" title="Kirim WhatsApp">
                                                            <i class="fa-brands fa-whatsapp"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7"><div class="kosong"><i class="fa-regular fa-folder-open"></i>Belum ada unit yang cocok. Tambahkan unit lewat form di samping atau ubah pencarian.</div></td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($total_pages > 1): ?>
                        <div class="d-flex justify-content-between align-items-center px-3 py-3 border-top flex-wrap gap-2">
                            <span class="small text-muted">Menampilkan halaman <?= $page; ?> dari <?= $total_pages; ?> (Total: <?= $total_rows; ?> data)</span>
                            <nav>
                                <ul class="pagination pagination-sm mb-0">
                                    <li class="page-item <?= ($page <= 1) ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?<?= $qs_base; ?>&page=<?= $page - 1; ?>">Prev</a>
                                    </li>
                                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                        <li class="page-item <?= ($page == $i) ? 'active' : ''; ?>">
                                            <a class="page-link" href="?<?= $qs_base; ?>&page=<?= $i; ?>"><?= $i; ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    <li class="page-item <?= ($page >= $total_pages) ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?<?= $qs_base; ?>&page=<?= $page + 1; ?>">Next</a>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Baris 2: bagian WhatsApp (penerima + riwayat), satu tema warna -->
        <div class="row g-4 mt-0">
            <div class="col-lg-4">
                <div class="card kartu kartu-wa h-100" id="penerima">
                    <div class="card-header">
                        <span class="ikon-judul"><i class="fa-brands fa-whatsapp"></i></span>
                        <div class="flex-grow-1"><h2 class="kartu-judul">Penerima Notifikasi WA</h2><div class="kartu-sub">Nomor yang menerima pengingat</div></div>
                        <span class="lencana lencana-hijau"><?= $penerima_aktif; ?> aktif</span>
                    </div>
                    <div class="card-body">
                        <?php if (!$penerima_list): ?>
                            <p class="small text-redup mb-3">
                                Belum ada nomor di sini.
                                <?php if ($wa_target_env !== ''): ?>
                                    Saat ini notifikasi dikirim ke nomor dari pengaturan server (<strong><?= e($wa_target_env); ?></strong>).
                                <?php endif; ?>
                                Tambahkan nomor di bawah agar bisa dikirim ke lebih dari 1 penerima.
                            </p>
                        <?php else: ?>
                            <ul class="list-group list-group-flush mb-3">
                                <?php foreach ($penerima_list as $p): $aktif = (int) $p['aktif'] === 1; ?>
                                <li class="list-group-item px-0 d-flex justify-content-between align-items-center gap-2">
                                    <div class="d-flex align-items-center gap-2 min-w-0 <?= $aktif ? '' : 'opacity-50'; ?>">
                                        <span class="avatar"><?= e(inisial($p['nama'])); ?></span>
                                        <div class="min-w-0">
                                            <div class="fw-semibold small text-truncate"><?= e($p['nama']); ?><?php if (!$aktif): ?> <span class="lencana lencana-abu ms-1">Nonaktif</span><?php endif; ?></div>
                                            <div class="small text-redup">+<?= e($p['nomor']); ?></div>
                                        </div>
                                    </div>
                                    <div class="d-flex gap-1">
                                        <form method="POST" action="dashboard.php">
                                            <?= csrf_field(); ?>
                                            <input type="hidden" name="aksi" value="toggle_penerima">
                                            <input type="hidden" name="id" value="<?= (int) $p['id']; ?>">
                                            <button type="submit" class="btn-ikon <?= $aktif ? 'btn-ikon-abu' : 'btn-ikon-hijau'; ?>" title="<?= $aktif ? 'Nonaktifkan' : 'Aktifkan'; ?>">
                                                <i class="fa-solid <?= $aktif ? 'fa-pause' : 'fa-play'; ?>"></i>
                                            </button>
                                        </form>
                                        <form method="POST" action="dashboard.php" onsubmit="return confirm('Hapus nomor ini dari penerima WA?')">
                                            <?= csrf_field(); ?>
                                            <input type="hidden" name="aksi" value="hapus_penerima">
                                            <input type="hidden" name="id" value="<?= (int) $p['id']; ?>">
                                            <button type="submit" class="btn-ikon btn-ikon-merah" title="Hapus"><i class="fa-solid fa-trash"></i></button>
                                        </form>
                                    </div>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>

                        <div class="subjudul mt-2">Tambah penerima</div>
                        <form method="POST" action="dashboard.php" class="row g-2">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="aksi" value="tambah_penerima">
                            <div class="col-12">
                                <input type="text" name="nama" class="form-control form-control-sm" placeholder="Nama (contoh: Admin Kantor)" maxlength="100" required>
                            </div>
                            <div class="col-8">
                                <input type="tel" name="nomor" class="form-control form-control-sm" placeholder="08xxxxxxxxxx" inputmode="numeric" required>
                            </div>
                            <div class="col-4">
                                <button type="submit" class="btn btn-sm btn-success w-100"><i class="fa-solid fa-plus"></i> Tambah</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-8">
                <div class="card kartu kartu-wa h-100" id="riwayat">
                    <div class="card-header">
                        <span class="ikon-judul"><i class="fa-solid fa-clock-rotate-left"></i></span>
                        <div><h2 class="kartu-judul">Riwayat Pengiriman WA</h2><div class="kartu-sub">Pesan otomatis & manual yang sudah dikirim</div></div>
                    </div>
                    <div class="table-responsive" style="max-height: 360px;">
                <table class="table table-sm table-hover align-middle tabel small">
                    <thead class="sticky-top"><tr><th>Waktu</th><th>Jenis</th><th>Unit</th><th>Penerima</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php if (!$riwayat_wa): ?>
                        <tr><td colspan="5"><div class="kosong"><i class="fa-regular fa-comment-dots"></i>Belum ada riwayat pengiriman.</div></td></tr>
                    <?php endif; ?>
                    <?php foreach ($riwayat_wa as $w): $ok = $w['status'] === 'berhasil'; ?>
                        <tr>
                            <td class="text-nowrap"><?= date('d M Y H:i', strtotime($w['waktu'])); ?></td>
                            <td><span class="lencana <?= $w['jenis'] === 'otomatis' ? 'lencana-biru' : 'lencana-abu'; ?>"><?= e(ucfirst($w['jenis'])); ?></span></td>
                            <td style="min-width: 180px;"><?= e($w['ringkasan']); ?></td>
                            <td style="min-width: 160px;">
                                <?php foreach (array_filter(array_map('trim', explode(',', $w['penerima'])), static fn($n) => $n !== '' && $n !== '-') as $no): ?>
                                    <div class="text-nowrap">
                                        <?php if (isset($nama_penerima[$no])): ?>
                                            <span class="fw-semibold"><?= e($nama_penerima[$no]); ?></span> <span class="text-secondary">+<?= e($no); ?></span>
                                        <?php else: ?>
                                            <span class="text-secondary">+<?= e($no); ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (trim($w['penerima']) === '-'): ?><span class="text-muted">-</span><?php endif; ?>
                            </td>
                            <td>
                                <span class="lencana <?= $ok ? 'lencana-hijau' : 'lencana-merah'; ?>"><i class="fa-solid <?= $ok ? 'fa-check' : 'fa-xmark'; ?>"></i> <?= $ok ? 'Berhasil' : 'Gagal'; ?></span>
                                <?php if (!$ok && $w['keterangan']): ?><div class="text-danger" style="font-size:.75rem"><?= e($w['keterangan']); ?></div><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="form-text px-3 py-2 border-top m-0">Menampilkan 30 catatan terakhir.</div>
                </div>
            </div>
        </div>

        <div class="kaki">&copy; <?= date('Y'); ?> Aplikasi Pengingat Jadwal</div>
    </div>

    <!-- Modal Edit (diletakkan di luar tabel agar HTML valid) -->
    <?php foreach ($data_tampil as $row): ?>
    <div class="modal fade" id="modalEdit<?= (int) $row['id']; ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-pen-to-square text-warning me-2"></i> Edit Data Unit</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="dashboard.php">
                    <div class="modal-body">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="aksi" value="update">
                        <input type="hidden" name="id" value="<?= (int) $row['id']; ?>">

                        <div class="mb-3">
                            <label class="form-label text-secondary small fw-bold">Kode Unit</label>
                            <input type="text" name="kode_unit" class="form-control" value="<?= e($row['kode_unit']); ?>" maxlength="50" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-secondary small fw-bold">Nama Unit</label>
                            <input type="text" name="nama_unit" class="form-control" value="<?= e($row['nama_unit']); ?>" maxlength="150" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-secondary small fw-bold">Tanggal Awal</label>
                            <input type="date" name="tanggal_awal" class="form-control" value="<?= e($row['tanggal_awal']); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-secondary small fw-bold">Tanggal Akhir</label>
                            <input type="date" name="tanggal_akhir" class="form-control" value="<?= e($row['tanggal_akhir']); ?>" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- Modal Lampiran -->
    <?php foreach ($data_tampil as $row): $lampiran_unit = $lampiran_per_unit[(int) $row['id']] ?? []; ?>
    <div class="modal fade" id="modalLampiran<?= (int) $row['id']; ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-paperclip text-info me-2"></i> Lampiran: <?= e($row['nama_unit']); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?php if (!$lampiran_unit): ?>
                        <p class="text-muted small text-center my-3">Belum ada lampiran untuk unit ini.</p>
                    <?php else: ?>
                        <ul class="list-group mb-3">
                            <?php foreach ($lampiran_unit as $l):
                                // Tombol "Lihat" untuk PDF, gambar, Word (.docx), Excel, CSV, TXT (lihat LAMPIRAN_BISA_DILIHAT)
                                $bisa_dilihat = in_array(strtolower($l['ekstensi']), LAMPIRAN_BISA_DILIHAT, true); ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                                <div class="text-truncate">
                                    <div class="fw-semibold small text-truncate" title="<?= e($l['nama_file']); ?>"><?= e($l['nama_file']); ?></div>
                                    <div class="text-secondary" style="font-size:.75rem">
                                        <?= formatUkuran((int) $l['ukuran']); ?> · <?= date('d M Y H:i', strtotime($l['dibuat_tanggal'])); ?>
                                        <?= $l['diunggah_oleh'] ? '· ' . e($l['diunggah_oleh']) : ''; ?>
                                    </div>
                                </div>
                                <div class="d-flex gap-1 flex-shrink-0">
                                    <?php if ($bisa_dilihat): ?>
                                        <a href="lampiran.php?id=<?= (int) $l['id']; ?>&lihat=1" target="_blank" rel="noopener" class="btn btn-sm btn-outline-success" title="Lihat tanpa mengunduh"><i class="fa-solid fa-eye me-1"></i>Lihat</a>
                                    <?php endif; ?>
                                    <a href="lampiran.php?id=<?= (int) $l['id']; ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary" title="Unduh"><i class="fa-solid fa-download"></i></a>
                                    <form method="POST" action="dashboard.php" onsubmit="return confirm('Hapus lampiran ini?')">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="aksi" value="hapus_lampiran">
                                        <input type="hidden" name="id" value="<?= (int) $l['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus"><i class="fa-solid fa-trash"></i></button>
                                    </form>
                                </div>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <form method="POST" action="dashboard.php" enctype="multipart/form-data" class="border-top pt-3" data-upload="unit">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="aksi" value="upload_lampiran">
                        <input type="hidden" name="deadline_id" value="<?= (int) $row['id']; ?>">
                        <label class="form-label text-secondary small fw-bold">Tambah Lampiran</label>
                        <input type="file" name="lampiran[]" class="form-control mb-1" multiple required accept="<?= e($accept_lampiran); ?>">
                        <div class="form-text mb-2"><?= e($info_lampiran); ?></div>
                        <div class="progress mb-2 d-none" style="height: 8px;" data-progres><div class="progress-bar" role="progressbar" style="width: 0%"></div></div>
                                <div class="small text-muted mb-2 d-none" data-progres-teks></div>
                        <button type="submit" class="btn btn-primary w-100"><i class="fa-solid fa-upload me-1"></i> Unggah</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Buka kembali modal lampiran setelah upload / hapus
        <?php if ($buka_lampiran > 0): ?>
        document.addEventListener('DOMContentLoaded', () => {
            const el = document.getElementById('modalLampiran<?= $buka_lampiran; ?>');
            if (el) bootstrap.Modal.getOrCreateInstance(el).show();
        });
        <?php endif; ?>

        // ---------------------------------------------------------------
        // Upload lampiran per potongan (melewati batas 4,5 MB per request Vercel)
        // Alur: lampiran_mulai -> lampiran_bagian (berulang, 768 KB) -> lampiran_selesai
        // ---------------------------------------------------------------
        const CSRF_TOKEN = <?= json_encode(csrf_token()); ?>;
        let sedangUpload = false;
        window.addEventListener('beforeunload', (e) => { if (sedangUpload) { e.preventDefault(); e.returnValue = ''; } });

        // Buat FormData berisi token CSRF + penanda ajax + data yang diberikan
        function dataForm(isi) {
            const f = new FormData();
            f.append('csrf_token', CSRF_TOKEN);
            f.append('ajax', '1');
            Object.entries(isi).forEach(([k, v]) => f.append(k, v));
            return f;
        }

        // Kirim data ke dashboard.php dan baca balasan JSON; coba ulang s/d 3x bila jaringan putus
        async function kirim(form, ulang = 3) {
            for (let coba = 1; ; coba++) {
                try {
                    const res = await fetch('dashboard.php', { method: 'POST', body: form, credentials: 'same-origin' });
                    let json;
                    try { json = await res.json(); } catch (_) { throw new Error('Respon server tidak valid (HTTP ' + res.status + ')'); }
                    if (!json.ok) { const err = new Error(json.pesan || 'Gagal'); err.final = true; throw err; }
                    return json;
                } catch (err) {
                    if (err.final || coba >= ulang) throw err;
                    await new Promise((r) => setTimeout(r, 1000 * coba)); // jaringan putus: coba lagi
                }
            }
        }

        // Unggah 1 file: daftarkan -> kirim potongan satu per satu -> tandai selesai
        async function unggahLampiran(deadlineId, file, onProgres) {
            const mulai = await kirim(dataForm({ aksi: 'lampiran_mulai', deadline_id: deadlineId, nama: file.name, ukuran: file.size }));
            const ukuran = mulai.ukuran_bagian;
            const jumlah = Math.ceil(file.size / ukuran);
            for (let i = 0; i < jumlah; i++) {
                const f = dataForm({ aksi: 'lampiran_bagian', id: mulai.id, urutan: i });
                f.append('bagian', file.slice(i * ukuran, (i + 1) * ukuran), 'bagian.bin');
                await kirim(f);
                onProgres((i + 1) / jumlah);
            }
            await kirim(dataForm({ aksi: 'lampiran_selesai', id: mulai.id }));
        }

        // Unggah semua file yang dipilih secara berurutan sambil memperbarui progress bar
        async function unggahSemua(form, deadlineId, files) {
            const bar = form.querySelector('[data-progres]');
            const teks = form.querySelector('[data-progres-teks]');
            bar.classList.remove('d-none'); teks.classList.remove('d-none');
            const gagal = [];
            for (let n = 0; n < files.length; n++) {
                const file = files[n];
                try {
                    await unggahLampiran(deadlineId, file, (p) => {
                        const total = (n + p) / files.length;
                        bar.firstElementChild.style.width = Math.round(total * 100) + '%';
                        teks.textContent = `Mengunggah ${file.name} (${n + 1}/${files.length}) — ${Math.round(p * 100)}%`;
                    });
                } catch (err) {
                    gagal.push(err.message.includes(file.name) ? err.message : `${file.name}: ${err.message}`);
                }
            }
            return gagal;
        }

        // Form yang berisi lampiran diambil alih JavaScript (unit baru disimpan dulu, lalu file diunggah)
        document.querySelectorAll('form[data-upload]').forEach((form) => {
            form.addEventListener('submit', async (e) => {
                const input = form.querySelector('input[type=file][name="lampiran[]"]');
                const files = input ? [...input.files] : [];
                if (form.dataset.upload === 'unit-baru' && files.length === 0) return; // tanpa lampiran: kirim biasa
                e.preventDefault();

                const tombol = form.querySelector('button[type=submit]');
                tombol.disabled = true;
                sedangUpload = true;
                try {
                    let deadlineId, tujuan;
                    if (form.dataset.upload === 'unit-baru') {
                        const data = new FormData(form);
                        data.delete('lampiran[]');
                        data.append('ajax', '1');
                        deadlineId = (await kirim(data, 1)).id;
                        tujuan = 'dashboard.php?status=success_add';
                    } else {
                        deadlineId = form.querySelector('[name=deadline_id]').value;
                        tujuan = 'dashboard.php?lampiran=' + deadlineId;
                    }
                    const gagal = await unggahSemua(form, deadlineId, files);
                    sedangUpload = false;
                    if (gagal.length) alert('Sebagian lampiran gagal diunggah:\n\n' + gagal.join('\n'));
                    window.location.href = tujuan;
                } catch (err) {
                    sedangUpload = false;
                    tombol.disabled = false;
                    alert(err.message);
                }
            });
        });

        // ---------------------------------------------------------------
        // Daftar file yang dipilih + tombol Lihat & ✕ SEBELUM data disimpan.
        // File belum dikirim ke server: pratinjau dibuka langsung dari laptop/HP user.
        // ---------------------------------------------------------------
        const MAKS_BYTE = <?= LAMPIRAN_MAKS_BYTE; ?>;
        const TIPE_BOLEH = <?= json_encode(array_keys(LAMPIRAN_TIPE)); ?>;
        const TIPE_LIHAT = <?= json_encode(LAMPIRAN_BISA_DILIHAT); ?>;
        const ekstensi = (nama) => (nama.includes('.') ? nama.split('.').pop() : '').toLowerCase();
        const ukuranTeks = (b) => b >= 1048576 ? (b / 1048576).toFixed(1).replace('.', ',') + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB';

        document.querySelectorAll('input[type=file][name="lampiran[]"]').forEach((input) => {
            let terpilih = [];   // file yang akan diunggah
            let dilewati = [];   // file yang ditolak (terlalu besar / tipe tidak diizinkan)
            const daftar = document.createElement('ul');
            daftar.className = 'list-group daftar-pilihan mt-2';
            input.insertAdjacentElement('afterend', daftar);

            // Samakan isi <input type=file> dengan daftar (dipakai saat tombol Simpan/Unggah ditekan)
            const sinkron = () => {
                const dt = new DataTransfer();
                terpilih.forEach((f) => dt.items.add(f));
                input.files = dt.files;
            };

            const gambar = () => {
                daftar.innerHTML = '';
                terpilih.forEach((file, i) => {
                    const li = document.createElement('li');
                    li.className = 'list-group-item d-flex align-items-center gap-2';
                    const nama = Object.assign(document.createElement('span'), { className: 'text-truncate flex-grow-1', textContent: file.name, title: file.name });
                    const ukuran = Object.assign(document.createElement('span'), { className: 'text-secondary small text-nowrap', textContent: ukuranTeks(file.size) });
                    li.append(nama, ukuran);
                    if (TIPE_LIHAT.includes(ekstensi(file.name))) {
                        const lihat = Object.assign(document.createElement('button'), { type: 'button', className: 'btn btn-sm btn-outline-success py-0', title: 'Lihat sebelum disimpan' });
                        lihat.innerHTML = '<i class="fa-solid fa-eye me-1"></i>Lihat';
                        lihat.onclick = () => bukaPratinjauLokal(file);
                        li.appendChild(lihat);
                    }
                    const hapus = Object.assign(document.createElement('button'), { type: 'button', className: 'btn btn-sm btn-outline-danger py-0', title: 'Batalkan file ini', textContent: '✕' });
                    hapus.onclick = () => { terpilih.splice(i, 1); sinkron(); gambar(); };
                    li.appendChild(hapus);
                    daftar.appendChild(li);
                });
                dilewati.forEach(([file, alasan]) => {
                    const li = Object.assign(document.createElement('li'), { className: 'list-group-item list-group-item-danger small' });
                    li.textContent = '✕ ' + file.name + ' — ' + alasan + ' (tidak ikut diunggah)';
                    daftar.appendChild(li);
                });
            };

            // Memilih file lagi = MENAMBAH ke daftar (bukan mengganti); file yang sama tidak dobel
            input.addEventListener('change', () => {
                dilewati = [];
                [...input.files].forEach((f) => {
                    if (!TIPE_BOLEH.includes(ekstensi(f.name))) dilewati.push([f, 'tipe file tidak diizinkan']);
                    else if (f.size > MAKS_BYTE) dilewati.push([f, 'melebihi <?= formatUkuran(LAMPIRAN_MAKS_BYTE); ?>']);
                    else if (f.size === 0) dilewati.push([f, 'file kosong']);
                    else if (!terpilih.some((t) => t.name === f.name && t.size === f.size)) terpilih.push(f);
                });
                sinkron();
                gambar();
            });

            // Form dikosongkan (reset) -> kosongkan juga daftarnya
            input.form && input.form.addEventListener('reset', () => { terpilih = []; dilewati = []; gambar(); });
        });

        // Popup pratinjau file lokal: PDF & gambar ditampilkan browser,
        // Word/Excel/CSV/TXT lewat vendor/pratinjau.html di iframe sandbox (sama seperti tombol Lihat lampiran)
        const popup = document.createElement('div');
        popup.id = 'pratinjauLokal';
        popup.innerHTML = '<div class="kotak"><div class="bilah"><strong class="text-truncate flex-grow-1"></strong>'
            + '<span class="badge bg-warning text-dark">Belum disimpan</span>'
            + '<button type="button" class="btn btn-sm btn-secondary">Tutup</button></div><div class="wadah"></div></div>';
        document.body.appendChild(popup);
        let urlLokal = null;
        const tutupPratinjau = () => {
            popup.classList.remove('tampil');
            popup.querySelector('.wadah').innerHTML = '';
            if (urlLokal) { URL.revokeObjectURL(urlLokal); urlLokal = null; }
        };
        popup.querySelector('.bilah button').onclick = tutupPratinjau;
        popup.addEventListener('click', (e) => { if (e.target === popup) tutupPratinjau(); });
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && popup.classList.contains('tampil')) { e.stopPropagation(); tutupPratinjau(); } }, true);

        function bukaPratinjauLokal(file) {
            tutupPratinjau();
            const ext = ekstensi(file.name);
            const wadah = popup.querySelector('.wadah');
            popup.querySelector('.bilah strong').textContent = file.name;
            if (['jpg', 'jpeg', 'png', 'webp'].includes(ext)) {
                urlLokal = URL.createObjectURL(file);
                wadah.appendChild(Object.assign(document.createElement('img'), { src: urlLokal, alt: file.name }));
            } else if (ext === 'pdf') {
                urlLokal = URL.createObjectURL(new Blob([file], { type: 'application/pdf' }));
                wadah.appendChild(Object.assign(document.createElement('iframe'), { src: urlLokal, title: file.name }));
            } else {
                const frame = document.createElement('iframe');
                frame.setAttribute('sandbox', 'allow-scripts allow-popups allow-popups-to-escape-sandbox');
                frame.title = file.name;
                frame.addEventListener('load', async () => {
                    const buf = await file.arrayBuffer();
                    frame.contentWindow.postMessage({ ext, buf }, '*', [buf]);
                }, { once: true });
                frame.src = 'vendor/pratinjau.html';
                wadah.appendChild(frame);
            }
            popup.classList.add('tampil');
        }

        // Daftarkan service worker (sw.js) agar aplikasi bisa di-"Install" seperti aplikasi HP (PWA)
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => navigator.serviceWorker.register('sw.js').catch(() => {}));
        }
    </script>
</body>
</html>
