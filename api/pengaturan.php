<?php
/**
 * PENGATURAN ENVIRONMENT VARIABLE (menu "Pengaturan", hanya Akses Penuh)
 *
 * Token Fonnte/Telegram, SMTP, dan saluran pengingat (NOTIF_VIA) diisi langsung dari aplikasi.
 * Nilai di sini langsung berlaku dan mengalahkan .env / Vercel; tombol "Pakai .env" mengembalikan
 * ke nilai .env. Token & password disimpan terenkripsi. Logika penyimpanan: lib/setelan.php.
 * DB_*, CRON_SECRET, dan APP_KEY tetap di .env / Vercel.
 */
require_once __DIR__ . '/lib/koneksi.php';
require_once __DIR__ . '/lib/session_handler.php';
require_once __DIR__ . '/lib/tampilan.php';
require_once __DIR__ . '/lib/peran.php';
require_once __DIR__ . '/lib/setelan.php';

if (empty($_SESSION['login'])) {
    header("Location: login.php");
    exit;
}
wajibAkunAktif();
header('Cache-Control: no-cache, no-store, must-revalidate');

// Halaman ini hanya untuk Akses Penuh; peran lain diarahkan ke Beranda
if (!boleh('atur_setelan')) {
    header("Location: dashboard.php?menu=beranda");
    exit;
}

$saya = (string) ($_SESSION['username'] ?? '');

/** Simpan pesan notifikasi untuk ditampilkan setelah halaman dimuat ulang */
function flashSetelan(string $tipe, string $pesan): void
{
    $_SESSION['flash_setelan'][] = [$tipe, $pesan];
}

// ===================================================================
// AKSI: simpan / hapus satu pengaturan, lalu redirect (pola Post/Redirect/Get)
// ===================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        flashSetelan('danger', 'Sesi formulir kedaluwarsa, silakan ulangi.');
        header("Location: pengaturan.php");
        exit;
    }
    $aksi = $_POST['aksi'] ?? '';
if ($aksi === 'simpan_setelan' || $aksi === 'hapus_setelan') {
    $nama = (string) ($_POST['nama'] ?? '');
    if (!isset(SETELAN_APP[$nama])) {
        flashSetelan('danger', 'Pengaturan tidak dikenal.');
    } elseif ($aksi === 'hapus_setelan') {
        simpanSetelan($pdo, $nama, null, $saya);
        flashSetelan('warning', "$nama kembali memakai nilai dari .env / Vercel" . (env($nama) ? '.' : ' (di sana belum diisi).'));
    } else {
        [$nilai, $error] = periksaSetelan($nama, $_POST['nilai'] ?? '');
        if ($error) {
            flashSetelan('danger', "$nama: $error");
        } else {
            try {
                simpanSetelan($pdo, $nama, $nilai, $saya);
                flashSetelan('success', "$nama disimpan dan langsung berlaku" . (SETELAN_APP[$nama]['rahasia'] ? ' (tersimpan terenkripsi).' : '.')
                    . ' Coba dengan tombol Kirim percobaan di menu Penerima.');
            } catch (RuntimeException $e) {
                flashSetelan('danger', $e->getMessage());
            }
        }
    }
    header("Location: pengaturan.php");
    exit;
}

    header("Location: pengaturan.php");
    exit;
}

// ===================================================================
// DATA TAMPILAN
// ===================================================================
$setelanApp = setelanTersimpan();
$flash = $_SESSION['flash_setelan'] ?? [];
unset($_SESSION['flash_setelan']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <?= kepalaHalaman('Pengaturan'); ?>
</head>
<body class="app">

<?= sidebarAplikasi('pengaturan'); ?>

<main class="isi">
    <div class="kepala d-flex align-items-end justify-content-between gap-3 flex-wrap">
        <div>
            <h1>Pengaturan</h1>
            <div class="kepala-sub">Token WhatsApp &amp; Telegram, pengaturan email (SMTP), dan saluran pengingat · hanya untuk Akses Penuh</div>
        </div>
    </div>
    <?php foreach ($flash as [$tipe, $pesan]): ?>
        <div class="alert alert-<?= e($tipe); ?> alert-dismissible fade show" role="alert">
            <?= e($pesan); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endforeach; ?>

    <!-- Pengaturan Environment Variable: diisi dari aplikasi, mengalahkan .env / Vercel (lib/setelan.php) -->
    <section class="card" id="setelan">
        <div class="card-header d-flex justify-content-between align-items-baseline"><h2>Environment Variable
            <button type="button" class="btn btn-link btn-sm p-0 ms-1 align-baseline" data-bs-toggle="modal" data-bs-target="#modalBantuanSetelan"
                    title="Ringkasan variabel" aria-label="Ringkasan variabel"><i class="fa-regular fa-circle-question" aria-hidden="true"></i></button></h2><span><?= count($setelanApp); ?> dari <?= count(SETELAN_APP); ?> diisi di sini</span></div>
        <div class="table-responsive">
            <table class="tabel-a">
                <thead>
                    <tr>
                        <th>Variabel</th>
                        <th>Untuk</th>
                        <th>Nilai yang dipakai</th>
                        <th><span class="visually-hidden">Aksi</span></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach (SETELAN_APP as $nama => $info): $isi = $setelanApp[$nama] ?? null; ?>
                    <tr>
                        <td data-label="Variabel"><span class="nama mono"><?= e($nama); ?></span><div class="form-text mt-0"><?= e($info['ket']); ?></div></td>
                        <td data-label="Untuk"><?= e($info['untuk']); ?></td>
                        <td data-label="Nilai yang dipakai">
                            <?php if ($isi && $isi['rusak']): ?>
                                <span class="teks-merah">Tidak bisa dibuka (APP_KEY / CRON_SECRET berubah), simpan ulang</span>
                            <?php elseif ($isi): ?>
                                <span class="mono text-nowrap"><?= e(tampilanSetelan($nama, (string) $isi['nilai'])); ?></span>
                                <div class="form-text mt-0">Dari menu ini · <?= e($isi['oleh'] ?: '-'); ?>, <?= e(date('d.m.Y H:i', strtotime((string) $isi['diubah']))); ?></div>
                            <?php elseif (env($nama) !== null && env($nama) !== ''): ?>
                                <span class="text-body-secondary">Dari .env / Vercel</span>
                            <?php elseif (in_array($nama, ['EMAIL_PENGIRIM', 'EMAIL_NAMA_PENGIRIM'], true)): ?>
                                <span class="text-body-secondary">Kosong (opsional)</span>
                            <?php else: ?>
                                <span class="teks-merah">Belum diisi</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="aksi">
                                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#modalSetelan<?= e($nama); ?>">Ubah</button>
                                <?php if ($isi): ?>
                                <form method="POST" action="pengaturan.php" onsubmit="return confirm(this.dataset.konfirmasi)"
                                      data-konfirmasi="Hapus <?= e($nama); ?> dari menu ini dan kembali memakai nilai dari .env / Vercel?">
                                    <?= csrf_field(); ?>
                                    <input type="hidden" name="aksi" value="hapus_setelan">
                                    <input type="hidden" name="nama" value="<?= e($nama); ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-sm">Pakai .env</button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="catatan mb-2">
            Nilai di sini langsung berlaku tanpa mengubah file <code>.env</code> atau Redeploy, dan mengalahkan nilai di <code>.env</code> / Vercel.
            Token dan password disimpan terenkripsi dan tidak ditampilkan lagi. <code>DB_*</code>, <code>CRON_SECRET</code>, dan <code>APP_KEY</code> tetap diatur di <code>.env</code> / Vercel.
        </p>
    </section>

    <?= kakiHalaman(); ?>
</main>

<!-- Popup ringkasan (tombol ? di judul Pengaturan Environment Variable) -->
<div class="modal fade" id="modalBantuanSetelan" tabindex="-1" aria-labelledby="judulBantuanSetelan" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="judulBantuanSetelan">Ringkasan Environment Variable</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-3 tabel-ringkas">
                        <thead class="d-none d-sm-table-header-group"><tr><th>Variabel</th><th>Didapat dari</th></tr></thead>
                        <tbody>
                        <?php foreach (SETELAN_APP as $nama => $info): ?>
                            <tr>
                                <?php // Di HP kedua sel bersusun (d-block) agar nama variabel tidak terpotong ?>
                                <td class="d-block d-sm-table-cell"><span class="mono"><?= e($nama); ?></span><div class="small text-body-secondary"><?= e($info['untuk']); ?></div></td>
                                <td class="d-block d-sm-table-cell"><?= e($info['sumber']); ?><div class="small text-body-secondary"><?= e($info['wajib']); ?></div></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="mb-2"><b>Setelah mengisi:</b> buka menu <b>Penerima</b>, lalu tekan <b>Kirim percobaan ke penerima aktif</b> di setiap saluran. Bila gagal, alasannya tampil di pesan merah atau di <b>Riwayat Kirim</b>.</p>
                <p class="mb-0 text-body-secondary">Token dan password bersifat rahasia; jangan dibagikan lewat chat. Aplikasi menyimpannya terenkripsi dan hanya menampilkan 4 karakter terakhir.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Mengerti</button>
            </div>
        </div>
    </div>
</div>
<?php // Popup "Ubah" untuk setiap Environment Variable (hanya Akses Penuh) ?>
<?php foreach (SETELAN_APP as $nama => $info): $isi = $setelanApp[$nama] ?? null; ?>
<div class="modal fade" id="modalSetelan<?= e($nama); ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="pengaturan.php" autocomplete="off">
                <div class="modal-header">
                    <h5 class="modal-title">Ubah · <span class="mono"><?= e($nama); ?></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="aksi" value="simpan_setelan">
                    <input type="hidden" name="nama" value="<?= e($nama); ?>">
                    <?php if ($nama === 'NOTIF_VIA'): $aktif = explode(',', strtolower((string) setelan('NOTIF_VIA', 'wa'))); ?>
                        <div class="form-label">Saluran yang dipakai</div>
                        <?php foreach (['wa' => 'WhatsApp', 'telegram' => 'Telegram', 'email' => 'Email'] as $kode => $label): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="nilai[]" value="<?= $kode; ?>" id="via<?= $kode; ?>" <?= in_array($kode, array_map('trim', $aktif), true) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="via<?= $kode; ?>"><?= $label; ?></label>
                        </div>
                        <?php endforeach; ?>
                    <?php elseif ($info['rahasia']): ?>
                        <label class="form-label" for="nilai<?= e($nama); ?>">Nilai baru</label>
                        <div class="input-group">
                            <input type="password" id="nilai<?= e($nama); ?>" name="nilai" class="form-control mono" maxlength="255" autocomplete="new-password" required
                                   placeholder="<?= $isi ? 'Isi untuk mengganti nilai yang tersimpan' : 'Tempel nilainya di sini'; ?>">
                            <?= tombol_mata(); ?>
                        </div>
                    <?php else: ?>
                        <label class="form-label" for="nilai<?= e($nama); ?>">Nilai</label>
                        <input type="<?= $nama === 'SMTP_PORT' ? 'number' : 'text'; ?>" id="nilai<?= e($nama); ?>" name="nilai" class="form-control mono" maxlength="255" required
                               value="<?= e((string) setelan($nama, '')); ?>">
                    <?php endif; ?>
                    <div class="form-text"><?= e($info['ket']); ?>. Berlaku langsung setelah disimpan.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-link" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?= skripBootstrap(); ?>
<?= skrip_mata(); ?>
</body>
</html>
