<?php
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/session_handler.php';
require_once __DIR__ . '/fonnte.php';

// Cek status login
if (empty($_SESSION['login'])) {
    header("Location: login.php");
    exit;
}

header('Cache-Control: no-cache, no-store, must-revalidate');

pastikanTabelTambahan($pdo);

/** Simpan pesan detail untuk ditampilkan setelah redirect */
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

    if (!csrf_valid()) {
        header("Location: dashboard.php?status=csrf");
        exit;
    }

    $aksi = $_POST['aksi'] ?? '';

    // --- 1. TAMBAH / 2. EDIT DATA ---
    if ($aksi === 'simpan' || $aksi === 'update') {
        $id            = (int) ($_POST['id'] ?? 0);
        $kode_unit     = trim($_POST['kode_unit'] ?? '');
        $nama_unit     = trim($_POST['nama_unit'] ?? '');
        $tanggal_awal  = $_POST['tanggal_awal'] ?? '';
        $tanggal_akhir = $_POST['tanggal_akhir'] ?? '';

        if ($kode_unit === '' || $nama_unit === '' || !tanggal_valid($tanggal_awal) || !tanggal_valid($tanggal_akhir)) {
            header("Location: dashboard.php?status=invalid");
            exit;
        }
        if ($tanggal_akhir < $tanggal_awal) {
            header("Location: dashboard.php?status=invalid_tanggal");
            exit;
        }

        if ($aksi === 'simpan') {
            $stmt = $pdo->prepare("INSERT INTO deadline (kode_unit, nama_unit, tanggal_awal, tanggal_akhir, pengingat) VALUES (?, ?, ?, ?, 'pending')");
            $stmt->execute([$kode_unit, $nama_unit, $tanggal_awal, $tanggal_akhir]);

            $files = daftarFileUpload('lampiran');
            if ($files) {
                $hasil = simpanLampiran($pdo, (int) $pdo->lastInsertId(), $files, $_SESSION['username'] ?? null);
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
        $pdo->prepare("DELETE FROM lampiran WHERE deadline_id = ?")->execute([$id]);
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

        $sisa_hari = hitungSisaHari($data_wa['tanggal_akhir']);

        if ($sisa_hari === 0) {
            $ket_sisa = "Hari Ini (Jatuh Tempo)";
        } elseif ($sisa_hari === 1) {
            $ket_sisa = "1 hari lagi (Besok / H-1)";
        } elseif ($sisa_hari > 1) {
            $ket_sisa = "$sisa_hari hari lagi";
        } else {
            $ket_sisa = "Sudah Expired (Lewat " . abs($sisa_hari) . " hari)";
        }

        $pesan  = "*NOTIFIKASI PENGINGAT DEADLINE*\n\n";
        $pesan .= "Halo Admin, berikut detail unit:\n\n";
        $pesan .= "*Kode Unit:* " . $data_wa['kode_unit'] . "\n";
        $pesan .= "*Nama Unit:* " . $data_wa['nama_unit'] . "\n";
        $pesan .= "*Tanggal Awal:* " . date('d-m-Y', strtotime($data_wa['tanggal_awal'])) . "\n";
        $pesan .= "*Tanggal Akhir:* " . date('d-m-Y', strtotime($data_wa['tanggal_akhir'])) . "\n";
        $pesan .= "*Sisa Waktu:* " . $ket_sisa . "\n\n";
        $pesan .= "Pesan ini dikirim secara manual dari dashboard admin.";

        $hasil = kirimWhatsApp($pesan);
        if (!$hasil['ok']) {
            $_SESSION['flash_wa_error'] = $hasil['pesan'];
        } else {
            flash('success', $hasil['pesan'] . '.');
        }
        header("Location: dashboard.php?status=" . ($hasil['ok'] ? 'wa_sent' : 'wa_failed'));
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
        $stmt = $pdo->prepare("SELECT deadline_id FROM lampiran WHERE id = ?");
        $stmt->execute([(int) ($_POST['id'] ?? 0)]);
        $deadlineId = (int) $stmt->fetchColumn();

        $pdo->prepare("DELETE FROM lampiran WHERE id = ?")->execute([(int) ($_POST['id'] ?? 0)]);
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
                    $pdo->prepare("INSERT INTO wa_penerima (nama, nomor) VALUES (?, ?)")
                        ->execute([function_exists('mb_substr') ? mb_substr($nama, 0, 100) : substr($nama, 0, 100), $nomor]);
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
        $pdo->prepare("UPDATE wa_penerima SET aktif = 1 - aktif WHERE id = ?")->execute([(int) ($_POST['id'] ?? 0)]);
        header("Location: dashboard.php#penerima");
        exit;
    }

    if ($aksi === 'hapus_penerima') {
        $pdo->prepare("DELETE FROM wa_penerima WHERE id = ?")->execute([(int) ($_POST['id'] ?? 0)]);
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
         FROM lampiran WHERE deadline_id IN (" . implode(',', $ids) . ") ORDER BY id"
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

$accept_lampiran = '.' . implode(',.', array_keys(LAMPIRAN_TIPE));
$info_lampiran   = 'PDF, gambar, Word, Excel, CSV, TXT. Maks ' . formatUkuran(LAMPIRAN_MAKS_BYTE) . ' per file.';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Reminders</title>

    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#1e293b">
    <link rel="apple-touch-icon" href="https://cdn-icons-png.flaticon.com/512/3602/3602145.png">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f8f9fa; }
        .navbar { background: linear-gradient(135deg, #1e293b, #0f172a); }
        .card { border: none; border-radius: 12px; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05); }
        .card-header { background-color: #ffffff; border-bottom: 1px solid #e2e8f0; border-radius: 12px 12px 0 0 !important; font-weight: 600; }
        .btn-primary { background-color: #2563eb; border: none; border-radius: 8px; padding: 10px 20px; font-weight: 500; }
        .btn-primary:hover { background-color: #1d4ed8; }
        .table thead { background-color: #f1f5f9; color: #475569; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px; }
        .badge-status { padding: 6px 12px; border-radius: 20px; font-size: 0.8rem; font-weight: 600; }
        .stat-card { border-left: 4px solid; transition: transform 0.2s; }
        .stat-card:hover { transform: translateY(-2px); }
        .stat-total { border-color: #3b82f6; }
        .stat-hari-ini { border-color: #ef4444; }
        .stat-h1 { border-color: #f59e0b; }
        .stat-mendatang { border-color: #6b7280; }
        .stat-expired { border-color: #111827; }
        .btn-aksi { width: 32px; height: 32px; padding: 0; display: inline-flex; align-items: center; justify-content: center; }
    </style>
</head>
<body>

    <nav class="navbar navbar-expand-lg navbar-dark mb-4 py-3 shadow-sm">
        <div class="container">
            <a class="navbar-brand fw-bold" href="dashboard.php">
                <i class="fa-solid fa-bell me-2 text-warning"></i> Reminders App
            </a>
            <div class="d-flex align-items-center gap-3">
                <span class="text-white-50 d-none d-sm-inline"><i class="fa-solid fa-user me-1"></i> Halo, <strong class="text-white"><?= e($_SESSION['username'] ?? 'Admin'); ?></strong></span>
                <a href="logout.php" class="btn btn-outline-light btn-sm rounded-pill px-3">
                    <i class="fa-solid fa-right-from-bracket me-1"></i> Logout
                </a>
            </div>
        </div>
    </nav>

    <div class="container mb-5">

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

        <div class="row row-cols-2 row-cols-md-5 g-3 mb-4">
            <div class="col"><div class="card stat-card stat-total p-3"><div class="text-muted small fw-semibold">Total Unit</div><div class="h3 fw-bold text-dark mb-0"><?= $stat_total; ?></div></div></div>
            <div class="col"><div class="card stat-card stat-hari-ini p-3"><div class="text-muted small fw-semibold">Hari Ini</div><div class="h3 fw-bold text-danger mb-0"><?= $stat_hari_ini; ?></div></div></div>
            <div class="col"><div class="card stat-card stat-h1 p-3"><div class="text-muted small fw-semibold">H-1 (Besok)</div><div class="h3 fw-bold text-warning mb-0"><?= $stat_h1; ?></div></div></div>
            <div class="col"><div class="card stat-card stat-mendatang p-3"><div class="text-muted small fw-semibold">Mendatang</div><div class="h3 fw-bold text-secondary mb-0"><?= $stat_mendatang; ?></div></div></div>
            <div class="col"><div class="card stat-card stat-expired p-3"><div class="text-muted small fw-semibold">Expired</div><div class="h3 fw-bold text-dark mb-0"><?= $stat_expired; ?></div></div></div>
        </div>

        <div class="row g-4">

            <div class="col-lg-4">
                <div class="card p-3">
                    <div class="card-header bg-transparent mb-2">
                        <i class="fa-solid fa-square-plus text-primary me-2"></i> Input Unit & Deadline
                    </div>
                    <div class="card-body">
                        <form method="POST" action="dashboard.php" enctype="multipart/form-data">
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
                            </div>
                            <button type="submit" class="btn btn-primary w-100 mt-2">
                                <i class="fa-solid fa-floppy-disk me-1"></i> Simpan Data
                            </button>
                        </form>
                    </div>
                </div>

                <div class="card p-3 mt-4" id="penerima">
                    <div class="card-header bg-transparent mb-2 d-flex justify-content-between align-items-center">
                        <span><i class="fa-brands fa-whatsapp text-success me-2"></i> Penerima Notifikasi WA</span>
                        <span class="badge bg-success-subtle text-success"><?= $penerima_aktif; ?> aktif</span>
                    </div>
                    <div class="card-body pt-1">
                        <?php if (!$penerima_list): ?>
                            <p class="small text-muted mb-3">
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
                                    <div class="<?= $aktif ? '' : 'text-muted text-decoration-line-through'; ?>">
                                        <div class="fw-semibold small"><?= e($p['nama']); ?></div>
                                        <div class="small text-secondary">+<?= e($p['nomor']); ?></div>
                                    </div>
                                    <div class="d-flex gap-1">
                                        <form method="POST" action="dashboard.php">
                                            <?= csrf_field(); ?>
                                            <input type="hidden" name="aksi" value="toggle_penerima">
                                            <input type="hidden" name="id" value="<?= (int) $p['id']; ?>">
                                            <button type="submit" class="btn btn-sm <?= $aktif ? 'btn-outline-secondary' : 'btn-outline-success'; ?>" title="<?= $aktif ? 'Nonaktifkan' : 'Aktifkan'; ?>">
                                                <i class="fa-solid <?= $aktif ? 'fa-pause' : 'fa-play'; ?>"></i>
                                            </button>
                                        </form>
                                        <form method="POST" action="dashboard.php" onsubmit="return confirm('Hapus nomor ini dari penerima WA?')">
                                            <?= csrf_field(); ?>
                                            <input type="hidden" name="aksi" value="hapus_penerima">
                                            <input type="hidden" name="id" value="<?= (int) $p['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus"><i class="fa-solid fa-trash"></i></button>
                                        </form>
                                    </div>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>

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
                <div class="card p-3">
                    <div class="card-header bg-transparent mb-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <span><i class="fa-solid fa-list-check text-primary me-2"></i> Daftar Unit & Deadline</span>
                    </div>

                    <form method="GET" action="dashboard.php" class="row g-2 mb-3">
                        <div class="col-md-6">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-white"><i class="fa-solid fa-magnifying-glass text-secondary"></i></span>
                                <input type="text" name="search" class="form-control" placeholder="Cari Kode / Nama Unit..." value="<?= e($search); ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <select name="filter" class="form-select form-select-sm">
                                <option value="semua" <?= $filter === 'semua' ? 'selected' : ''; ?>>Semua Status</option>
                                <option value="hari_ini" <?= $filter === 'hari_ini' ? 'selected' : ''; ?>>Hari Ini</option>
                                <option value="h1" <?= $filter === 'h1' ? 'selected' : ''; ?>>H-1</option>
                                <option value="mendatang" <?= $filter === 'mendatang' ? 'selected' : ''; ?>>Mendatang</option>
                                <option value="expired" <?= $filter === 'expired' ? 'selected' : ''; ?>>Expired</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-sm btn-primary w-100">Tampilkan</button>
                        </div>
                    </form>

                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th class="text-center" width="5%">NO</th>
                                        <th>KODE UNIT</th>
                                        <th>NAMA UNIT</th>
                                        <th>TANGGAL AWAL</th>
                                        <th>TANGGAL AKHIR</th>
                                        <th class="text-center">DEADLINE</th>
                                        <th class="text-center">AKSI</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $no = $offset + 1; ?>
                                    <?php if (count($data_tampil) > 0): ?>
                                        <?php foreach ($data_tampil as $row):
                                            $sisa_hari = $row['sisa_hari'];

                                            if ($sisa_hari === 0) {
                                                $badge_deadline = '<span class="badge bg-danger badge-status"><i class="fa-solid fa-circle-exclamation me-1"></i> Hari Ini</span>';
                                            } elseif ($sisa_hari === 1) {
                                                $badge_deadline = '<span class="badge bg-warning text-dark badge-status"><i class="fa-solid fa-clock me-1"></i> H-1</span>';
                                            } elseif ($sisa_hari > 1) {
                                                $badge_deadline = '<span class="badge bg-secondary badge-status">' . $sisa_hari . ' hari lagi</span>';
                                            } else {
                                                $badge_deadline = '<span class="badge bg-dark badge-status">Expired</span>';
                                            }
                                        ?>
                                        <tr>
                                            <td class="text-center fw-bold text-secondary"><?= $no++; ?></td>
                                            <td><span class="badge bg-light text-dark border"><?= e($row['kode_unit']); ?></span></td>
                                            <td class="fw-semibold text-dark"><?= e($row['nama_unit']); ?></td>
                                            <td class="text-secondary small"><?= date('d M Y', strtotime($row['tanggal_awal'])); ?></td>
                                            <td class="text-secondary small"><?= date('d M Y', strtotime($row['tanggal_akhir'])); ?></td>
                                            <td class="text-center">
                                                <?= $badge_deadline; ?>
                                                <?php if (!empty($row['terakhir_dikirim'])): ?>
                                                    <div class="small text-success mt-1" title="Pengingat WA otomatis terakhir terkirim">
                                                        <i class="fa-brands fa-whatsapp"></i> <?= date('d M Y', strtotime($row['terakhir_dikirim'])); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <div class="d-flex justify-content-center gap-1">
                                                    <button type="button" class="btn btn-sm btn-warning text-dark rounded-circle btn-aksi"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#modalEdit<?= (int) $row['id']; ?>"
                                                            title="Edit Data">
                                                        <i class="fa-solid fa-pen-to-square"></i>
                                                    </button>

                                                    <?php $jml_lampiran = count($lampiran_per_unit[(int) $row['id']] ?? []); ?>
                                                    <button type="button" class="btn btn-sm btn-info text-white rounded-circle btn-aksi position-relative"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#modalLampiran<?= (int) $row['id']; ?>"
                                                            title="Lampiran Dokumen">
                                                        <i class="fa-solid fa-paperclip"></i>
                                                        <?php if ($jml_lampiran > 0): ?>
                                                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-primary" style="font-size:.6rem"><?= $jml_lampiran; ?></span>
                                                        <?php endif; ?>
                                                    </button>

                                                    <form method="POST" action="dashboard.php" class="d-inline"
                                                          onsubmit="return confirm('Apakah Anda yakin ingin menghapus data unit ini beserta lampirannya?')">
                                                        <?= csrf_field(); ?>
                                                        <input type="hidden" name="aksi" value="hapus">
                                                        <input type="hidden" name="id" value="<?= (int) $row['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-danger rounded-circle btn-aksi" title="Hapus Data">
                                                            <i class="fa-solid fa-trash"></i>
                                                        </button>
                                                    </form>

                                                    <form method="POST" action="dashboard.php" class="d-inline"
                                                          onsubmit="return confirm(this.dataset.konfirmasi)"
                                                          data-konfirmasi="Kirim notifikasi WhatsApp sekarang untuk unit <?= e($row['nama_unit']); ?>?">
                                                        <?= csrf_field(); ?>
                                                        <input type="hidden" name="aksi" value="kirim_wa">
                                                        <input type="hidden" name="id" value="<?= (int) $row['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-success rounded-circle btn-aksi" title="Kirim WhatsApp">
                                                            <i class="fa-brands fa-whatsapp"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-4 text-muted">Data tidak ditemukan.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($total_pages > 1): ?>
                        <div class="d-flex justify-content-between align-items-center p-3 border-top flex-wrap gap-2">
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
                                $bisa_dilihat = in_array($l['ekstensi'], ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true); ?>
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
                                        <a href="lampiran.php?id=<?= (int) $l['id']; ?>&lihat=1" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary" title="Lihat"><i class="fa-solid fa-eye"></i></a>
                                    <?php endif; ?>
                                    <a href="lampiran.php?id=<?= (int) $l['id']; ?>" class="btn btn-sm btn-outline-primary" title="Unduh"><i class="fa-solid fa-download"></i></a>
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

                    <form method="POST" action="dashboard.php" enctype="multipart/form-data" class="border-top pt-3">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="aksi" value="upload_lampiran">
                        <input type="hidden" name="deadline_id" value="<?= (int) $row['id']; ?>">
                        <label class="form-label text-secondary small fw-bold">Tambah Lampiran</label>
                        <input type="file" name="lampiran[]" class="form-control mb-1" multiple required accept="<?= e($accept_lampiran); ?>">
                        <div class="form-text mb-2"><?= e($info_lampiran); ?></div>
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

        // Tolak file > batas sebelum dikirim (hemat kuota & waktu)
        document.querySelectorAll('input[type=file][name="lampiran[]"]').forEach((input) => {
            input.addEventListener('change', () => {
                const besar = [...input.files].filter((f) => f.size > <?= LAMPIRAN_MAKS_BYTE; ?>);
                if (besar.length) {
                    alert('File berikut melebihi <?= formatUkuran(LAMPIRAN_MAKS_BYTE); ?>:\n' + besar.map((f) => f.name).join('\n'));
                    input.value = '';
                }
            });
        });

        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
        }
    </script>
</body>
</html>
