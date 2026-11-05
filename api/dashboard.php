<?php
/**
 * DASHBOARD: tiga menu dalam satu halaman (?menu=...)
 *  - (default)  Hari Jadi     -> daftar daerah + tanggal hari jadi, tambah (satu / banyak sekaligus), edit, hapus,
 *                                kirim pengingat manual, jalankan pengingat otomatis sekarang
 *  - penerima   Penerima      -> nomor WhatsApp, chat Telegram, alamat email tujuan pengingat + kirim percobaan
 *  - riwayat    Riwayat Kirim -> catatan setiap pengiriman
 *
 * Semua aksi dikirim lewat POST (dengan token CSRF), lalu dialihkan kembali (pola Post/Redirect/Get)
 * dengan pesan singkat (flash) agar tombol Refresh browser tidak mengirim ulang form.
 */
require_once __DIR__ . '/lib/sesi.php';
require_once __DIR__ . '/lib/skema.php';
require_once __DIR__ . '/lib/peran.php';
require_once __DIR__ . '/lib/hari_jadi.php';
require_once __DIR__ . '/lib/notifikasi.php';
require_once __DIR__ . '/lib/pengingat.php';
require_once __DIR__ . '/lib/tampilan.php';

wajibLogin();
pastikanSkema($pdo);
wajibAkunAktif();

/** Jenis penerima => [tabel, kolom alamat, nama untuk pesan]. Dipakai sebagai daftar putih nama tabel. */
const JENIS_PENERIMA = [
    'wa'       => ['wa_penerima', 'nomor', 'Nomor WhatsApp'],
    'telegram' => ['telegram_penerima', 'chat_id', 'Chat Telegram'],
    'email'    => ['email_penerima', 'email', 'Alamat email'],
];

/** Alihkan kembali ke menu tertentu setelah aksi POST */
function kembali(string $menu = '', string $jangkar = ''): void
{
    header('Location: dashboard.php' . ($menu !== '' ? '?menu=' . $menu : '') . ($jangkar !== '' ? '#' . $jangkar : ''));
    exit;
}

/** Hentikan aksi bila akun tidak punya hak (tombolnya memang tidak ditampilkan, ini pengaman tambahan) */
function wajibHak(string $hak, string $menu = ''): void
{
    if (!boleh($hak)) {
        flash('danger', 'Akun Anda tidak punya hak untuk aksi ini.');
        kembali($menu);
    }
}

/**
 * Pecah satu baris "Tambah banyak" menjadi [nama, tanggal]. Pemisah: titik koma, tab (tempel dari Excel), atau koma.
 * Contoh: "Kota Pekanbaru;23-06-2026", "Kabupaten Kampar<TAB>06-02-2026", "Kota Dumai, 20-04-2026".
 * Kolom ketiga (angka "hari jadi ke-" dari format lama) diabaikan agar data lama tetap bisa ditempel.
 */
function pecahBarisData(string $baris): ?array
{
    if (!preg_match('/^(.+?)\s*[;\t,]\s*([0-9]{1,4}[-\/.][0-9]{1,2}[-\/.][0-9]{1,4})(?:\s*[;\t,]\s*(?:ke\s*-?\s*)?[0-9]{1,4})?\s*$/iu', trim($baris), $m)) {
        return null;
    }
    return [trim(preg_replace('/\s+/u', ' ', $m[1])), $m[2]];
}

// =====================================================================
// PROSES AKSI (POST)
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = (string) ($_POST['aksi'] ?? '');
    $menuAsal = in_array($_POST['menu'] ?? '', ['penerima', 'riwayat'], true) ? $_POST['menu'] : '';

    if (!csrf_valid()) {
        flash('danger', 'Sesi formulir kedaluwarsa, silakan coba lagi.');
        kembali($menuAsal);
    }

    switch ($aksi) {
        // ---------------- HARI JADI ----------------
        case 'tambah':
        case 'edit':
            wajibHak('kelola_data');
            $nama    = input_teks('nama_daerah', 150);
            $tanggal = bacaTanggal((string) ($_POST['tanggal'] ?? ''));
            if ($nama === '' || $tanggal === null) {
                flash('danger', 'Nama daerah wajib diisi dan tanggal hari jadi harus valid (contoh 23-06-2026).');
                kembali();
            }
            try {
                if ($aksi === 'tambah') {
                    $pdo->prepare("INSERT INTO hari_jadi (nama_daerah, tanggal, dibuat_oleh) VALUES (?, ?, ?)")
                        ->execute([$nama, $tanggal, $_SESSION['username'] ?? null]);
                    flash('success', "Hari jadi $nama ditambahkan. Pengingat akan dikirim otomatis setiap tahun.");
                } else {
                    $id   = (int) ($_POST['id'] ?? 0);
                    $lama = $pdo->prepare("SELECT tanggal FROM hari_jadi WHERE id = ?");
                    $lama->execute([$id]);
                    $tglLama = $lama->fetchColumn();
                    if ($tglLama === false) {
                        flash('danger', 'Data tidak ditemukan (mungkin sudah dihapus).');
                        kembali();
                    }
                    // Tanggal & bulan berubah -> tanda "sudah diingatkan" dihapus agar tanggal baru ikut diingatkan
                    $reset = substr($tglLama, 5) !== substr($tanggal, 5);
                    $pdo->prepare("UPDATE hari_jadi SET nama_daerah = ?, tanggal = ?" . ($reset ? ', terakhir_diingatkan = NULL' : '') . " WHERE id = ?")
                        ->execute([$nama, $tanggal, $id]);
                    flash('success', "Data $nama disimpan.");
                }
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    flash('danger', "Daerah \"$nama\" sudah ada di daftar.");
                } else {
                    error_log('Simpan hari jadi gagal: ' . $e->getMessage());
                    flash('danger', 'Gagal menyimpan data, terjadi kesalahan sistem.');
                }
            }
            kembali();

        case 'tambah_banyak':
            wajibHak('kelola_data');
            $baris = preg_split('/\r\n|\r|\n/', (string) ($_POST['data'] ?? ''));
            $stmt  = $pdo->prepare("INSERT IGNORE INTO hari_jadi (nama_daerah, tanggal, dibuat_oleh) VALUES (?, ?, ?)");
            $masuk = 0;
            $ganda = [];
            $salah = [];
            foreach ($baris as $i => $teks) {
                if (trim($teks) === '') {
                    continue;
                }
                $data    = pecahBarisData($teks);
                $tanggal = $data ? bacaTanggal($data[1]) : null;
                if (!$data || $data[0] === '' || $tanggal === null) {
                    $salah[] = 'baris ' . ($i + 1);
                    continue;
                }
                $stmt->execute([mb_substr($data[0], 0, 150), $tanggal, $_SESSION['username'] ?? null]);
                if ($stmt->rowCount() === 1) {
                    $masuk++;
                } else {
                    $ganda[] = $data[0];
                }
            }
            $pesan = "$masuk data ditambahkan.";
            if ($ganda) {
                $pesan .= "\nSudah ada (dilewati): " . implode(', ', $ganda) . '.';
            }
            if ($salah) {
                $pesan .= "\nFormat tidak dikenali: " . implode(', ', $salah) . '. Gunakan format: Nama Daerah;23-06-2026';
            }
            flash($salah || !$masuk ? 'warning' : 'success', $pesan);
            kembali();

        case 'hapus':
            wajibHak('hapus_data');
            $stmt = $pdo->prepare("DELETE FROM hari_jadi WHERE id = ?");
            $stmt->execute([(int) ($_POST['id'] ?? 0)]);
            flash($stmt->rowCount() ? 'success' : 'warning', $stmt->rowCount() ? 'Data dihapus.' : 'Data tidak ditemukan.');
            kembali();

        case 'kirim_manual':
            // Kirim pengingat satu daerah sekarang juga (tidak mengubah jadwal pengingat otomatis)
            wajibHak('kelola_data');
            $stmt = $pdo->prepare("SELECT * FROM hari_jadi WHERE id = ?");
            $stmt->execute([(int) ($_POST['id'] ?? 0)]);
            $row = $stmt->fetch();
            if (!$row) {
                flash('danger', 'Data tidak ditemukan.');
                kembali();
            }
            // Saluran dipilih di menu: 'semua' (saluran aktif yang sudah diatur) atau satu saluran saja
            $pilihan = (string) ($_POST['saluran'] ?? 'semua');
            if ($pilihan === 'semua') {
                $tujuan = array_values(array_intersect(saluranNotifikasi(), saluranSiap())) ?: saluranNotifikasi();
            } elseif (in_array($pilihan, ['wa', 'telegram', 'email'], true)) {
                $tujuan = [$pilihan];
            } else {
                flash('danger', 'Saluran pengiriman tidak dikenal.');
                kembali();
            }
            $row['info'] = infoHariJadi($row);
            $hasil = kirimNotifikasi(susunPesanHariJadi([$row]), 'manual', ringkasanHariJadi([$row]), $tujuan);
            $lewat = count($tujuan) === 1 ? ' lewat ' . namaSaluran($tujuan[0]) : '';
            flash($hasil['ok'] ? 'success' : 'danger', ($hasil['ok'] ? "Pengingat terkirim$lewat. " : "Pengingat gagal dikirim$lewat. ") . $hasil['pesan']);
            kembali();

        case 'jalankan_pengingat':
            wajibHak('kelola_data');
            $lap = jalankanPengingat($pdo);
            if ($lap['total'] === 0) {
                flash('info', 'Tidak ada hari jadi dalam ' . $lap['h_min'] . ' hari ke depan yang belum diingatkan.');
            } else {
                flash($lap['status'] ? 'success' : 'danger', ($lap['status'] ? 'Pengingat terkirim untuk: ' : 'Pengingat gagal untuk: ')
                    . implode(', ', $lap['daerah']) . '. ' . $lap['hasil']);
            }
            kembali();

        // ---------------- PENERIMA ----------------
        case 'tambah_penerima':
            wajibHak('kelola_penerima', 'penerima');
            $jenis = (string) ($_POST['jenis'] ?? '');
            if (!isset(JENIS_PENERIMA[$jenis])) {
                kembali('penerima');
            }
            [$tabel, $kolom, $label] = JENIS_PENERIMA[$jenis];
            $nama  = input_teks('nama', 100);
            $input = trim((string) ($_POST['alamat'] ?? ''));
            if ($jenis === 'wa') {
                [$alamat, $galat] = periksaNomorWaBaru($input);
            } elseif ($jenis === 'email') {
                [$alamat, $galat] = periksaEmailBaru($input);
            } else {
                [$alamat, $galat] = chatIdTelegramValid($input) ? [$input, null] : [null, 'Chat id Telegram berupa angka (grup diawali tanda minus, contoh -1001234567890).'];
            }
            if ($nama === '' || $galat) {
                flash('danger', $galat ?: 'Nama penerima wajib diisi.');
                kembali('penerima', $jenis);
            }
            try {
                $pdo->prepare("INSERT INTO $tabel (nama, $kolom) VALUES (?, ?)")->execute([$nama, $alamat]);
                flash('success', "$label untuk $nama ditambahkan.");
            } catch (PDOException $e) {
                flash('danger', $e->getCode() === '23000' ? "$label tersebut sudah terdaftar." : 'Gagal menyimpan penerima.');
            }
            kembali('penerima', $jenis);

        case 'toggle_penerima':
        case 'hapus_penerima':
            wajibHak('kelola_penerima', 'penerima');
            $jenis = (string) ($_POST['jenis'] ?? '');
            if (isset(JENIS_PENERIMA[$jenis])) {
                $tabel = JENIS_PENERIMA[$jenis][0];
                $sql   = $aksi === 'hapus_penerima' ? "DELETE FROM $tabel WHERE id = ?" : "UPDATE $tabel SET aktif = 1 - aktif WHERE id = ?";
                $pdo->prepare($sql)->execute([(int) ($_POST['id'] ?? 0)]);
                flash('success', $aksi === 'hapus_penerima' ? 'Penerima dihapus.' : 'Status penerima diubah.');
            }
            kembali('penerima', $jenis);

        case 'tes_saluran':
            wajibHak('kelola_penerima', 'penerima');
            $jenis = (string) ($_POST['jenis'] ?? '');
            if (isset(JENIS_PENERIMA[$jenis])) {
                $pesan = "*PESAN PERCOBAAN*\nIni pesan percobaan dari Aplikasi Pengingat Hari Jadi.\n"
                    . 'Jika pesan ini diterima, pengingat H-' . hariPengingat() . ' lewat ' . namaSaluran($jenis) . ' sudah siap.';
                $hasil = kirimNotifikasi($pesan, 'percobaan', 'Pesan percobaan', [$jenis]);
                flash($hasil['ok'] ? 'success' : 'danger', namaSaluran($jenis) . ': ' . $hasil['pesan']);
            }
            kembali('penerima', $jenis);
    }

    kembali($menuAsal);
}

// =====================================================================
// DATA UNTUK TAMPILAN (GET)
// =====================================================================
// Ekspor data ke CSV (titik koma) dengan format yang sama seperti "Tambah banyak":
// "Nama Daerah;23-06-2027". Dipakai untuk cadangan atau memindahkan data XAMPP <-> online.
if (isset($_GET['ekspor'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="hari-jadi-' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM agar huruf terbaca benar di Excel
    foreach ($pdo->query("SELECT * FROM hari_jadi ORDER BY nama_daerah") as $row) {
        echo str_replace(';', ',', $row['nama_daerah']), ';', infoHariJadi($row)['berikutnya']->format('d-m-Y'), "\r\n";
    }
    exit;
}

$menu    = in_array($_GET['menu'] ?? '', ['penerima', 'riwayat'], true) ? $_GET['menu'] : 'hari_jadi';
$hariIni = new DateTimeImmutable('today');
$hMin    = hariPengingat();
$saluran = saluranNotifikasi();
$siap    = saluranSiap();
// Pilihan saluran untuk "Kirim pengingat sekarang": saluran => keterangan bila belum bisa dipakai ('' = siap)
$pilihan_kirim = [];
foreach (['wa', 'telegram', 'email'] as $s) {
    $pilihan_kirim[$s] = in_array($s, $siap, true) ? '' : 'belum diatur di pengaturan server';
}
$nama_semua = implode(', ', array_map('namaSaluran', array_intersect($saluran, $siap) ?: $saluran));

$total_data = (int) $pdo->query("SELECT COUNT(*) FROM hari_jadi")->fetchColumn();
$jumlah_penerima = 0;
foreach (JENIS_PENERIMA as [$tabel]) {
    $jumlah_penerima += (int) $pdo->query("SELECT COUNT(*) FROM $tabel WHERE aktif = 1")->fetchColumn();
}

if ($menu === 'hari_jadi') {
    $cari   = trim((string) ($_GET['cari'] ?? ''));
    $filter = in_array($_GET['filter'] ?? '', ['dekat', 'bulan'], true) ? $_GET['filter'] : 'semua';

    $semua = [];
    foreach ($pdo->query("SELECT * FROM hari_jadi") as $row) {
        $row['info'] = infoHariJadi($row, $hariIni);
        $semua[] = $row;
    }
    // Hari jadi terdekat di atas
    usort($semua, static fn($a, $b) => [$a['info']['sisa'], $a['nama_daerah']] <=> [$b['info']['sisa'], $b['nama_daerah']]);

    $tab = [
        'semua' => ['Semua', count($semua)],
        'dekat' => [$hMin . ' hari ke depan', count(array_filter($semua, static fn($r) => $r['info']['sisa'] <= $hMin))],
        'bulan' => ['30 hari ke depan', count(array_filter($semua, static fn($r) => $r['info']['sisa'] <= 30))],
    ];
    $daftar = array_values(array_filter($semua, static function ($r) use ($filter, $cari, $hMin) {
        if ($filter === 'dekat' && $r['info']['sisa'] > $hMin) {
            return false;
        }
        if ($filter === 'bulan' && $r['info']['sisa'] > 30) {
            return false;
        }
        return $cari === '' || mb_stripos($r['nama_daerah'], $cari) !== false;
    }));
}

if ($menu === 'penerima') {
    $penerima = [];
    foreach (JENIS_PENERIMA as $jenis => [$tabel, $kolom]) {
        $penerima[$jenis] = $pdo->query("SELECT id, nama, $kolom AS alamat, aktif FROM $tabel ORDER BY aktif DESC, nama")->fetchAll();
    }
    // Bantu mengisi chat id: tampilkan chat yang baru mengirim pesan ke bot
    $cari_chat = boleh('kelola_penerima') && isset($_GET['cari_chat']) ? cariChatTelegram() : null;
}

if ($menu === 'riwayat') {
    $per_halaman   = 50;
    $total_riwayat = (int) $pdo->query("SELECT COUNT(*) FROM kirim_log")->fetchColumn();
    $jumlah_hal    = max(1, (int) ceil($total_riwayat / $per_halaman));
    $hal           = min($jumlah_hal, max(1, (int) ($_GET['hal'] ?? 1)));
    $stmt = $pdo->prepare("SELECT * FROM kirim_log ORDER BY waktu DESC, id DESC LIMIT ? OFFSET ?");
    $stmt->bindValue(1, $per_halaman, PDO::PARAM_INT);
    $stmt->bindValue(2, ($hal - 1) * $per_halaman, PDO::PARAM_INT);
    $stmt->execute();
    $riwayat = $stmt->fetchAll();
}

$judul = ['hari_jadi' => 'Hari Jadi', 'penerima' => 'Penerima', 'riwayat' => 'Riwayat Kirim'][$menu];
$nama_saluran = implode(', ', array_map('namaSaluran', $saluran));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <?= kepalaHalaman($judul); ?>
    <style>
        .tabel-hj td.no { width: 48px; color: var(--tinta3); font-family: 'IBM Plex Mono', monospace; font-size: 13.5px; }
        .contoh-data { font-family: 'IBM Plex Mono', monospace; font-size: 13px; }
    </style>
</head>
<body class="app">

<?= sidebarAplikasi($menu, ['hari_jadi' => $total_data, 'penerima' => $jumlah_penerima]); ?>

<main class="isi">

    <div class="kepala">
        <div>
            <h1><?= e($judul); ?></h1>
            <div class="kepala-sub">
                <?= e(tanggalIndonesia($hariIni)); ?>
                <?php if ($menu === 'hari_jadi'): ?> · pengingat otomatis H-<?= $hMin; ?> lewat <?= e($nama_saluran); ?><?php endif; ?>
                <?php if ($menu === 'penerima'): ?> · nomor WhatsApp, chat Telegram, dan alamat email yang menerima pengingat<?php endif; ?>
                <?php if ($menu === 'riwayat'): ?> · <?= $total_riwayat; ?> catatan pengiriman<?php endif; ?>
            </div>
        </div>
        <?php if ($menu === 'hari_jadi' && boleh('kelola_data')): ?>
            <div class="d-flex gap-2 flex-wrap">
                <button type="button" class="btn btn-garis" data-bs-toggle="modal" data-bs-target="#modalBanyak">Tambah banyak</button>
                <button type="button" class="btn btn-gelap" data-bs-toggle="modal" data-bs-target="#modalData" data-aksi="tambah">+ Tambah hari jadi</button>
            </div>
        <?php endif; ?>
    </div>

    <?= tampilkanFlash(); ?>

<?php if ($menu === 'hari_jadi'): ?>
    <!-- ================= DAFTAR HARI JADI ================= -->
    <section class="panel" id="daftar">
        <nav class="tabs" aria-label="Filter waktu">
            <?php foreach ($tab as $kunci => [$label, $nilai]): ?>
                <a href="dashboard.php<?= $kunci === 'semua' ? '' : '?filter=' . $kunci; ?>" class="<?= $filter === $kunci ? 'on' : ''; ?> <?= $kunci === 'dekat' && $nilai ? 'penting' : ''; ?>"<?= $filter === $kunci ? ' aria-current="page"' : ''; ?>>
                    <?= e($label); ?> <i><?= (int) $nilai; ?></i>
                </a>
            <?php endforeach; ?>
        </nav>

        <form method="GET" action="dashboard.php" class="bar">
            <?php if ($filter !== 'semua'): ?><input type="hidden" name="filter" value="<?= e($filter); ?>"><?php endif; ?>
            <input type="text" name="cari" class="form-control bar-cari" placeholder="Cari nama daerah" value="<?= e($cari); ?>" aria-label="Cari nama daerah">
            <button type="submit" class="btn btn-garis">Cari</button>
            <?php if ($cari !== '' || $filter !== 'semua'): ?>
                <a href="dashboard.php" class="btn btn-teks">Hapus filter</a>
            <?php endif; ?>
            <span class="bar-ket"><?= count($daftar); ?> daerah · hari jadi terdekat di atas
                <?php if ($total_data): ?> · <a href="dashboard.php?ekspor=1" title="Unduh semua data (format sama dengan Tambah banyak)">Ekspor CSV</a><?php endif; ?></span>
        </form>

        <div class="table-responsive">
            <table class="tabel-a tabel-hj">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Nama Daerah</th>
                        <th>Hari Jadi Berikutnya</th>
                        <th>Sisa Waktu</th>
                        <th>Pengingat</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$daftar): ?>
                    <tr><td colspan="6" class="kosong">
                        <?= $total_data ? 'Tidak ada data yang cocok dengan filter.' : 'Belum ada data. Klik "+ Tambah hari jadi" atau "Tambah banyak" untuk mengisi.'; ?>
                    </td></tr>
                <?php endif; ?>
                <?php foreach ($daftar as $i => $row): $info = $row['info']; ?>
                    <tr>
                        <td class="no"><?= $i + 1; ?></td>
                        <td>
                            <div class="nama"><?= e($row['nama_daerah']); ?></div>
                            <div class="kode">Setiap <?= e(tanggalBulan($row['tanggal'])); ?></div>
                        </td>
                        <td class="mono">
                            <b><?= e(tanggalIndonesia($info['berikutnya'])); ?></b>
                        </td>
                        <td>
                            <?php $warna = $info['sisa'] <= $hMin ? 'merah' : ($info['sisa'] <= 30 ? 'amber' : 'abu'); ?>
                            <div class="st <?= $warna; ?>"><?= e(keteranganSisaHari($info['sisa'])); ?></div>
                        </td>
                        <td>
                            <?php if ($info['sudah_diingatkan']): ?>
                                <div class="st hijau">Terkirim</div>
                                <div class="st-sub">tahun <?= e($info['berikutnya']->format('Y')); ?></div>
                            <?php elseif ($info['sisa'] <= $hMin): ?>
                                <div class="st amber">Menunggu kirim</div>
                                <div class="st-sub">pada jadwal berikutnya</div>
                            <?php else: ?>
                                <div class="st abu">Dijadwalkan</div>
                                <div class="st-sub"><?= e(tanggalIndonesia($info['berikutnya']->modify("-$hMin days"), false)); ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (boleh('kelola_data')): ?>
                            <div class="aksi">
                                <button type="button" class="btn btn-teks btn-kecil" data-bs-toggle="modal" data-bs-target="#modalData" data-aksi="edit"
                                        data-id="<?= (int) $row['id']; ?>" data-nama="<?= e($row['nama_daerah']); ?>"
                                        data-tanggal="<?= e($row['tanggal']); ?>">Edit</button>
                                <div class="dropdown">
                                    <?php // strategy fixed: menu melayang di atas tabel, tidak terpotong oleh area tabel yang bisa digulir ?>
                                    <button type="button" class="btn btn-teks btn-kecil btn-titik" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}' aria-expanded="false" aria-label="Aksi lain untuk <?= e($row['nama_daerah']); ?>">⋯</button>
                                    <div class="dropdown-menu dropdown-menu-end menu-aksi">
                                        <?php // Satu form, tiap tombol mengirim nilai "saluran" berbeda; konfirmasi menyebut saluran yang dipilih ?>
                                        <form method="POST" action="dashboard.php" data-daerah="<?= e($row['nama_daerah']); ?>"
                                              onsubmit="return confirm('Kirim pengingat ' + this.dataset.daerah + ' sekarang ' + event.submitter.dataset.ke + '?')">
                                            <?= csrf_field(); ?>
                                            <input type="hidden" name="aksi" value="kirim_manual">
                                            <input type="hidden" name="id" value="<?= (int) $row['id']; ?>">
                                            <h6 class="dropdown-header">Kirim pengingat sekarang</h6>
                                            <button type="submit" name="saluran" value="semua" class="dropdown-item" data-ke="ke semua saluran (<?= e($nama_semua); ?>)">
                                                Semua saluran<br><small><?= e($nama_semua); ?></small></button>
                                            <?php foreach ($pilihan_kirim as $s => $alasan): ?>
                                                <button type="submit" name="saluran" value="<?= $s; ?>" class="dropdown-item" data-ke="lewat <?= e(namaSaluran($s)); ?>"<?= $alasan ? ' disabled' : ''; ?>>
                                                    <?= e(namaSaluran($s)); ?><?php if ($alasan): ?><br><small><?= e($alasan); ?></small><?php endif; ?></button>
                                            <?php endforeach; ?>
                                            <div class="px-3 pb-1"><small class="redup">Tidak mengubah jadwal otomatis</small></div>
                                        </form>
                                        <?php if (boleh('hapus_data')): ?>
                                        <div class="dropdown-divider"></div>
                                        <form method="POST" action="dashboard.php" onsubmit="return confirm('Hapus hari jadi <?= e(addslashes($row['nama_daerah'])); ?>? Pengingatnya tidak akan dikirim lagi.')">
                                            <?= csrf_field(); ?>
                                            <input type="hidden" name="aksi" value="hapus">
                                            <input type="hidden" name="id" value="<?= (int) $row['id']; ?>">
                                            <button type="submit" class="dropdown-item teks-merah">Hapus</button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel info">
        <span>Pengingat dikirim <b>H-<?= $hMin; ?></b> (<?= $hMin; ?> hari sebelum hari jadi) dan berulang <b>setiap tahun</b>.</span>
        <span>Saluran: <b><?= e($nama_saluran); ?></b></span>
        <?php if (boleh('kelola_data')): ?>
            <form method="POST" action="dashboard.php" class="ms-auto">
                <?= csrf_field(); ?>
                <input type="hidden" name="aksi" value="jalankan_pengingat">
                <button type="submit" class="btn btn-garis btn-kecil" title="Kirim sekarang semua pengingat yang sudah waktunya tetapi belum terkirim">Jalankan pengingat sekarang</button>
            </form>
        <?php endif; ?>
    </section>

<?php elseif ($menu === 'penerima'): ?>
    <!-- ================= PENERIMA ================= -->
    <?php $kelola = boleh('kelola_penerima'); ?>
    <?php if ($cari_chat !== null): ?>
        <section class="panel">
            <div class="panel-judul"><h2>Chat Telegram yang ditemukan</h2><span><?= count($cari_chat['chat']); ?> chat</span></div>
            <?php if (!$cari_chat['ok']): ?>
                <p class="catatan"><?= e($cari_chat['pesan']); ?></p>
            <?php elseif (!$cari_chat['chat']): ?>
                <p class="catatan">Belum ada chat. Minta penerima membuka bot lalu klik <b>Start</b> (di grup: kirim <code>/start@nama_bot</code>), kemudian klik "Cari chat" lagi.</p>
            <?php endif; ?>
            <?php foreach ($cari_chat['chat'] as $id => $c): ?>
                <form method="POST" action="dashboard.php" class="baris-penerima ditemukan">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="menu" value="penerima">
                    <input type="hidden" name="aksi" value="tambah_penerima">
                    <input type="hidden" name="jenis" value="telegram">
                    <input type="hidden" name="alamat" value="<?= e($id); ?>">
                    <input type="hidden" name="nama" value="<?= e($c['nama']); ?>">
                    <div><div class="nama"><?= e($c['nama']); ?> <span class="tanda"><?= e($c['jenis']); ?></span></div><div class="kode"><?= e($id); ?></div></div>
                    <button type="submit" class="btn btn-gelap btn-kecil">Tambah</button>
                </form>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <div class="dua-kolom">
    <?php foreach (JENIS_PENERIMA as $jenis => [$tabel, $kolom, $label]): $dipakai = in_array($jenis, $saluran, true); ?>
        <section class="panel" id="<?= $jenis; ?>">
            <div class="panel-judul">
                <h2><?= e(namaSaluran($jenis)); ?></h2>
                <span><?= count(array_filter($penerima[$jenis], static fn($p) => (int) $p['aktif'] === 1)); ?> aktif<?= $dipakai ? '' : ' · tidak dipakai'; ?></span>
            </div>
            <?php if (!$dipakai): ?>
                <p class="catatan"><?= e(namaSaluran($jenis)); ?> belum dipakai. Tambahkan <code><?= $jenis; ?></code> di <code>NOTIF_VIA</code> pada file .env (contoh <code>NOTIF_VIA=wa,telegram,email</code>).</p>
            <?php elseif (!in_array($jenis, $siap, true)): ?>
                <p class="catatan">Pengaturan <?= e(namaSaluran($jenis)); ?> di file .env belum lengkap
                    (<?= ['wa' => '<code>FONNTE_TOKEN</code>', 'telegram' => '<code>TELEGRAM_BOT_TOKEN</code>', 'email' => '<code>SMTP_HOST</code>, <code>SMTP_USER</code>, <code>SMTP_PASS</code>'][$jenis]; ?>).</p>
            <?php endif; ?>
            <?php if (!$penerima[$jenis]): ?>
                <p class="catatan">Belum ada penerima.</p>
            <?php endif; ?>
            <?php foreach ($penerima[$jenis] as $p): $aktif = (int) $p['aktif'] === 1; ?>
                <div class="baris-penerima <?= $aktif ? '' : 'nonaktif'; ?>">
                    <div class="min-w-0">
                        <div class="nama"><?= e($p['nama']); ?><?= $aktif ? '' : ' <span class="tanda">nonaktif</span>'; ?></div>
                        <div class="kode"><?= $jenis === 'wa' ? '+' : ''; ?><?= e($p['alamat']); ?></div>
                    </div>
                    <?php if ($kelola): ?>
                    <div class="aksi">
                        <form method="POST" action="dashboard.php">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="menu" value="penerima">
                            <input type="hidden" name="aksi" value="toggle_penerima">
                            <input type="hidden" name="jenis" value="<?= $jenis; ?>">
                            <input type="hidden" name="id" value="<?= (int) $p['id']; ?>">
                            <button type="submit" class="btn btn-teks btn-kecil"><?= $aktif ? 'Nonaktifkan' : 'Aktifkan'; ?></button>
                        </form>
                        <form method="POST" action="dashboard.php" onsubmit="return confirm('Hapus penerima ini?')">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="menu" value="penerima">
                            <input type="hidden" name="aksi" value="hapus_penerima">
                            <input type="hidden" name="jenis" value="<?= $jenis; ?>">
                            <input type="hidden" name="id" value="<?= (int) $p['id']; ?>">
                            <button type="submit" class="btn btn-teks btn-kecil teks-merah">Hapus</button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if ($kelola): ?>
            <form method="POST" action="dashboard.php" class="form-tambah">
                <?= csrf_field(); ?>
                <input type="hidden" name="menu" value="penerima">
                <input type="hidden" name="aksi" value="tambah_penerima">
                <input type="hidden" name="jenis" value="<?= $jenis; ?>">
                <div class="label-kecil">Tambah <?= e(strtolower($label)); ?></div>
                <div class="baris-input">
                    <input type="text" name="nama" class="form-control" placeholder="Nama, contoh: Sekretariat" maxlength="100" required aria-label="Nama penerima">
                    <?php if ($jenis === 'wa'): ?>
                        <input type="tel" name="alamat" class="form-control" placeholder="08xxxxxxxxxx" inputmode="numeric" maxlength="20" required aria-label="Nomor WhatsApp">
                    <?php elseif ($jenis === 'telegram'): ?>
                        <input type="text" name="alamat" class="form-control" placeholder="Chat id (angka)" maxlength="25" required aria-label="Chat id Telegram">
                    <?php else: ?>
                        <input type="email" name="alamat" class="form-control" placeholder="nama@gmail.com" maxlength="150" required aria-label="Alamat email">
                    <?php endif; ?>
                    <button type="submit" class="btn btn-gelap">Tambah</button>
                </div>
            </form>
            <div class="panel-kaki">
                <?php if ($jenis === 'telegram'): ?>
                    <a href="dashboard.php?menu=penerima&amp;cari_chat=1" class="btn btn-teks btn-kecil">Cari chat yang sudah Start bot</a>
                <?php else: ?><span></span><?php endif; ?>
                <form method="POST" action="dashboard.php">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="menu" value="penerima">
                    <input type="hidden" name="aksi" value="tes_saluran">
                    <input type="hidden" name="jenis" value="<?= $jenis; ?>">
                    <button type="submit" class="btn btn-garis btn-kecil">Kirim percobaan</button>
                </form>
            </div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
    </div>

<?php else: ?>
    <!-- ================= RIWAYAT KIRIM ================= -->
    <section class="panel" id="riwayat">
        <div class="table-responsive">
            <table class="tabel-a tabel-riwayat">
                <thead>
                    <tr><th>Waktu</th><th>Jenis</th><th>Saluran</th><th>Daerah</th><th>Penerima</th><th>Status</th></tr>
                </thead>
                <tbody>
                <?php if (!$riwayat): ?>
                    <tr><td colspan="6" class="kosong">Belum ada pengiriman.</td></tr>
                <?php endif; ?>
                <?php foreach ($riwayat as $r): ?>
                    <tr>
                        <td class="mono"><?= e(date('d-m-Y H:i', strtotime($r['waktu']))); ?></td>
                        <td><?= e(ucfirst($r['jenis'])); ?></td>
                        <td><?= e(namaSaluran($r['saluran'])); ?></td>
                        <td class="kontak"><?= nl2br(e($r['ringkasan'])); ?></td>
                        <td class="kontak"><?= e(str_replace([',tg:', ',em:', 'tg:', 'em:', ','], [', ', ', ', '', '', ', '], $r['penerima'])); ?></td>
                        <td>
                            <div class="st <?= $r['berhasil'] ? 'hijau' : 'merah'; ?>"><?= $r['berhasil'] ? 'Berhasil' : 'Gagal'; ?></div>
                            <?php if ($r['keterangan'] !== ''): ?><div class="st-sub"><?= e($r['keterangan']); ?></div><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($jumlah_hal > 1): ?>
            <div class="panel-kaki">
                <span>Halaman <?= $hal; ?> dari <?= $jumlah_hal; ?></span>
                <div class="halaman">
                    <?php if ($hal > 1): ?><a class="btn btn-garis btn-kecil" href="dashboard.php?menu=riwayat&amp;hal=<?= $hal - 1; ?>">Sebelumnya</a><?php endif; ?>
                    <?php if ($hal < $jumlah_hal): ?><a class="btn btn-garis btn-kecil" href="dashboard.php?menu=riwayat&amp;hal=<?= $hal + 1; ?>">Berikutnya</a><?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

    <div class="kaki">&copy; <?= date('Y'); ?> Aplikasi Pengingat Hari Jadi</div>
</main>

<?php if ($menu === 'hari_jadi' && boleh('kelola_data')): ?>
<!-- Popup tambah / edit satu data (diisi oleh skrip di bawah sesuai tombol yang diklik) -->
<div class="modal fade" id="modalData" tabindex="-1" aria-labelledby="judulModalData" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="dashboard.php" class="modal-content">
            <?= csrf_field(); ?>
            <input type="hidden" name="aksi" value="tambah">
            <input type="hidden" name="id" value="">
            <div class="modal-header">
                <h2 class="modal-title" id="judulModalData">Tambah hari jadi</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label" for="nama_daerah">Nama daerah pemegang saham</label>
                    <input type="text" id="nama_daerah" name="nama_daerah" class="form-control" maxlength="150" placeholder="Contoh: Kota Pekanbaru" required>
                </div>
                <div>
                    <label class="form-label" for="tanggal">Tanggal hari jadi</label>
                    <input type="date" id="tanggal" name="tanggal" class="form-control" min="1000-01-01" required>
                    <div class="form-text">Isi tanggal dan bulan hari jadi (tahunnya bebas, misalnya tahun ini).
                        Cukup sekali: pengingat dikirim H-<?= $hMin; ?> setiap tahun pada tanggal yang sama.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-teks" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-gelap">Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- Popup tambah banyak data sekaligus (tempel dari Excel / ketik per baris) -->
<div class="modal fade" id="modalBanyak" tabindex="-1" aria-labelledby="judulModalBanyak" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form method="POST" action="dashboard.php" class="modal-content">
            <?= csrf_field(); ?>
            <input type="hidden" name="aksi" value="tambah_banyak">
            <div class="modal-header">
                <h2 class="modal-title" id="judulModalBanyak">Tambah banyak sekaligus</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">Satu daerah per baris: <b>nama daerah</b>, lalu titik koma, lalu <b>tanggal hari jadi</b> (hari-bulan-tahun, tahunnya bebas).
                    Bisa juga menempel dua kolom langsung dari Excel. Daerah yang sudah ada dilewati.</p>
                <div class="catatan mx-0 mb-3 contoh-data">Kota Pekanbaru;23-06-2026<br>Kabupaten Kampar;06-02-2026<br>Kota Dumai;20-04-2026</div>
                <label class="form-label" for="data_banyak">Data</label>
                <textarea id="data_banyak" name="data" class="form-control contoh-data" rows="10" required></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-teks" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-gelap">Simpan semua</button>
            </div>
        </form>
    </div>
</div>
<script>
// Isi popup sesuai tombol: "+ Tambah" (kosong) atau "Edit" (data baris tersebut)
document.getElementById('modalData').addEventListener('show.bs.modal', (ev) => {
    const tombol = ev.relatedTarget;
    const isian = ev.target.querySelector('form').elements;
    const edit = tombol && tombol.dataset.aksi === 'edit';
    isian.aksi.value = edit ? 'edit' : 'tambah';
    isian['id'].value = edit ? tombol.dataset.id : '';
    isian.nama_daerah.value = edit ? tombol.dataset.nama : '';
    isian.tanggal.value = edit ? tombol.dataset.tanggal : '';
    ev.target.querySelector('.modal-title').textContent = edit ? 'Edit hari jadi' : 'Tambah hari jadi';
});
</script>
<?php endif; ?>

<?= kakiHalaman(); ?>
</body>
</html>
