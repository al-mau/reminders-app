<?php
/**
 * DASHBOARD (halaman utama setelah login)
 *
 * Susunan file ini:
 *  1. AKSI             -> proses tombol/form (tambah, edit, hapus unit, kirim WA manual,
 *                         reset pengingat, tandai selesai, lampiran, penerima WA). Semua lewat POST + CSRF.
 *  2. DATA TAMPILAN    -> statistik (tab status), pencarian/filter/halaman tabel, lampiran,
 *                         penerima, riwayat kirim, pengingat otomatis terakhir
 *  3. HTML             -> sidebar (lib/tampilan.php) + satu menu sesuai ?menu=:
 *                         Daftar Unit (bawaan), Penerima, Riwayat Kirim; popup tambah/edit/lampiran
 *  4. JavaScript       -> upload lampiran per potongan + progress bar
 */
require_once __DIR__ . '/lib/koneksi.php';
require_once __DIR__ . '/lib/session_handler.php';
require_once __DIR__ . '/lib/fonnte.php';
require_once __DIR__ . '/lib/tampilan.php';

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

/** Tanggal pendek bahasa Indonesia untuk tabel, contoh: "2026-10-05" -> "05 Okt 2026" (tanpa tahun: "05 Okt") */
function tglPendek(string $tanggal, bool $tahun = true): string
{
    static $bulan = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    $t = strtotime($tanggal);
    return date('d', $t) . ' ' . $bulan[(int) date('n', $t)] . ($tahun ? ' ' . date('Y', $t) : '');
}

/**
 * Tombol pindah halaman: [‹ Sebelumnya] [2 / 5] [Berikutnya ›].
 * Parameter lain di URL (pencarian, tanggal, halaman tabel lain) tetap dipertahankan.
 * Tidak tampil jika hanya ada 1 halaman.
 */
function tombolHalaman(int $hal, int $jumlah, string $param, string $jangkar): string
{
    if ($jumlah <= 1) {
        return '';
    }
    $url = static function (int $ke) use ($param, $jangkar): string {
        $q = array_filter(array_merge($_GET, [$param => $ke]), static fn($v) => $v !== '' && $v !== null);
        unset($q['status']);
        return 'dashboard.php?' . e(http_build_query($q)) . '#' . $jangkar;
    };
    $sebelum = $hal > 1
        ? '<a class="btn btn-garis btn-kecil" href="' . $url($hal - 1) . '">&lsaquo; Sebelumnya</a>'
        : '<span class="btn btn-garis btn-kecil disabled">&lsaquo; Sebelumnya</span>';
    $sesudah = $hal < $jumlah
        ? '<a class="btn btn-garis btn-kecil" href="' . $url($hal + 1) . '">Berikutnya &rsaquo;</a>'
        : '<span class="btn btn-garis btn-kecil disabled">Berikutnya &rsaquo;</span>';
    return '<div class="halaman">' . $sebelum . '<span class="mono">' . $hal . ' / ' . $jumlah . '</span>' . $sesudah . '</div>';
}

/**
 * Pecah kolom ringkasan riwayat WA menjadi daftar unit "(kode) nama".
 * Format baru: satu unit per baris. Format lama: "kode nama, kode nama" -> diubah ke "(kode) nama".
 */
function daftarUnitRiwayat(string $ringkasan): array
{
    $ringkasan = trim($ringkasan);
    if ($ringkasan === '' || $ringkasan === '-') {
        return ['-'];
    }
    if (str_contains($ringkasan, "\n")) {
        return array_values(array_filter(array_map('trim', explode("\n", $ringkasan)), 'strlen'));
    }
    $hasil = [];
    foreach (array_filter(array_map('trim', explode(', ', $ringkasan)), 'strlen') as $unit) {
        if ($unit[0] !== '(' && str_contains($unit, ' ')) {
            [$kode, $nama] = explode(' ', $unit, 2);
            $unit = "($kode) $nama";
        }
        $hasil[] = $unit;
    }
    return $hasil;
}

/**
 * Alamat kembali setelah aksi POST: halaman menu yang sama, dengan pencarian / filter / halaman
 * tabel yang sedang dibuka (diambil dari halaman asal form). Pesan & popup lama dibuang.
 * @param string $menu   'unit', 'penerima', atau 'riwayat'
 * @param array  $tambah parameter tambahan, contoh ['status' => 'success_add'] atau ['lampiran' => 5]
 */
function kembaliKe(string $menu, array $tambah = [], string $jangkar = ''): string
{
    $q = [];
    $asal = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    if ($asal !== '' && basename((string) parse_url($asal, PHP_URL_PATH)) === 'dashboard.php') {
        parse_str((string) parse_url($asal, PHP_URL_QUERY), $q);
        if (($q['menu'] ?? 'unit') !== $menu) {
            $q = []; // dari menu lain: jangan bawa filter menu tersebut
        }
        $q = array_intersect_key($q, array_flip(['filter', 'search', 'dari', 'sampai', 'hal', 'cari_wa', 'status_wa', 'tgl_wa', 'hal_wa']));
        $q = array_filter($q, static fn($v) => is_string($v) && $v !== '');
    }
    $q = ($menu === 'unit' ? [] : ['menu' => $menu]) + $q + $tambah;
    return 'dashboard.php' . ($q ? '?' . http_build_query($q) : '') . ($jangkar !== '' ? '#' . $jangkar : '');
}

/** Menu halaman asal form (dari alamat halaman sebelumnya), untuk aksi yang bisa datang dari menu mana saja */
function menuAsal(): string
{
    parse_str((string) parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_QUERY), $q);
    return in_array($q['menu'] ?? '', ['penerima', 'riwayat'], true) ? $q['menu'] : 'unit';
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
        header('Location: ' . kembaliKe('unit'));
        exit;
    }

    $ajax = !empty($_POST['ajax']);

    if (!csrf_valid()) {
        if ($ajax) {
            jsonKeluar(['ok' => false, 'pesan' => 'Sesi formulir kedaluwarsa, muat ulang halaman.'], 403);
        }
        header('Location: ' . kembaliKe(menuAsal(), ['status' => 'csrf']));
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
            header('Location: ' . kembaliKe('unit', ['status' => 'invalid']));
            exit;
        }
        if ($tanggal_akhir < $tanggal_awal) {
            if ($ajax) {
                jsonKeluar(['ok' => false, 'pesan' => 'Tanggal akhir tidak boleh lebih awal dari tanggal awal.'], 422);
            }
            header('Location: ' . kembaliKe('unit', ['status' => 'invalid_tanggal']));
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
            header('Location: ' . kembaliKe('unit', ['status' => 'success_add']));
        } else {
            // Jika tanggal akhir diubah (deadline diperpanjang), reset status pengingat & status selesai
            // agar cron kembali mengingatkan menjelang deadline yang baru.
            // tanggal_akhir diisi paling akhir: MySQL memproses SET berurutan, jadi IF() di atasnya
            // masih membandingkan dengan tanggal akhir yang lama.
            $stmt = $pdo->prepare(
                "UPDATE deadline
                 SET kode_unit = ?, nama_unit = ?, tanggal_awal = ?,
                     pengingat = IF(tanggal_akhir <> ?, 'pending', pengingat),
                     terakhir_dikirim = IF(tanggal_akhir <> ?, NULL, terakhir_dikirim),
                     selesai_oleh = IF(tanggal_akhir <> ?, NULL, selesai_oleh),
                     selesai_tanggal = IF(tanggal_akhir <> ?, NULL, selesai_tanggal),
                     tanggal_akhir = ?
                 WHERE id = ?"
            );
            $stmt->execute([$kode_unit, $nama_unit, $tanggal_awal, $tanggal_akhir, $tanggal_akhir, $tanggal_akhir, $tanggal_akhir, $tanggal_akhir, $id]);
            header('Location: ' . kembaliKe('unit', ['status' => 'success_update']));
        }
        exit;
    }

    // --- 3. HAPUS DATA ---
    if ($aksi === 'hapus') {
        $id = (int) ($_POST['id'] ?? 0);
        hapusLampiranUnit($pdo, $id);
        $pdo->prepare("DELETE FROM deadline WHERE id = ?")->execute([$id]);
        header('Location: ' . kembaliKe('unit', ['status' => 'success_delete']));
        exit;
    }

    // --- 4. KIRIM WA MANUAL ---
    if ($aksi === 'kirim_wa') {
        $stmt = $pdo->prepare("SELECT * FROM deadline WHERE id = ?");
        $stmt->execute([(int) ($_POST['id'] ?? 0)]);
        $data_wa = $stmt->fetch();

        if (!$data_wa) {
            header('Location: ' . kembaliKe('unit', ['status' => 'not_found']));
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

        $label = '(' . $data_wa['kode_unit'] . ') ' . $data_wa['nama_unit'];
        // Saluran dipilih dari menu tombol kirim: WhatsApp, Telegram, atau keduanya
        $via = [
            'wa'       => ['wa'],
            'telegram' => ['telegram'],
            'semua'    => ['wa', 'telegram'],
        ][(string) ($_POST['via'] ?? '')] ?? null;

        // Kirim manual tidak mengubah status pengingat unit (pengingat otomatis tetap berjalan sesuai jadwal)
        $hasil = kirimWhatsApp($pesan, 'manual', $label, $via);
        if (!$hasil['ok']) {
            $_SESSION['flash_wa_error'] = $hasil['pesan'];
        } else {
            flash('success', $hasil['pesan'] . '.');
        }
        header('Location: ' . kembaliKe('unit', ['status' => $hasil['ok'] ? 'wa_sent' : 'wa_failed']));
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
            flash('danger', 'Unit ini sudah terlewat, pengingat otomatis tidak bisa dikirim ulang. Ubah tanggal akhirnya jika masih perlu diingatkan.');
            header('Location: ' . kembaliKe('unit'));
            exit;
        }
        // Status selesai ikut dihapus: cron tidak mengirim pengingat untuk unit yang sudah selesai
        $pdo->prepare("UPDATE deadline SET pengingat = 'pending', terakhir_dikirim = NULL, selesai_tanggal = NULL, selesai_oleh = NULL WHERE id = ?")->execute([$id]);
        $label = labelUnit($pdo, $id);
        flash('success', "Pengingat $label di-reset. Akan dikirim ulang otomatis pada jadwal cron berikutnya jika deadline-nya hari ini atau besok.");
        header('Location: ' . kembaliKe('unit'));
        exit;
    }

    // --- 4c. TANDAI SELESAI / BATALKAN ---
    // Unit selesai = user unit sudah di-update. Pengingat otomatis berhenti dan unit tidak
    // dihitung sebagai Terlewat. Dicatat siapa yang menandai dan kapan.
    if ($aksi === 'tandai_selesai' || $aksi === 'batal_selesai') {
        $id    = (int) ($_POST['id'] ?? 0);
        $label = labelUnit($pdo, $id);
        if ($aksi === 'tandai_selesai') {
            $oleh = potong((string) ($_SESSION['username'] ?? 'admin'), 100);
            $pdo->prepare("UPDATE deadline SET selesai_tanggal = ?, selesai_oleh = ? WHERE id = ? AND selesai_tanggal IS NULL")
                ->execute([date('Y-m-d H:i:s'), $oleh, $id]);
            flash('success', "Unit $label ditandai selesai. Pengingat otomatis untuk unit ini dihentikan.");
        } else {
            $pdo->prepare("UPDATE deadline SET selesai_tanggal = NULL, selesai_oleh = NULL WHERE id = ?")->execute([$id]);
            flash('info', "Status selesai unit $label dibatalkan.");
        }
        header('Location: ' . kembaliKe('unit'));
        exit;
    }

    // --- 5. UPLOAD LAMPIRAN KE UNIT YANG SUDAH ADA ---
    if ($aksi === 'upload_lampiran') {
        $deadlineId = (int) ($_POST['deadline_id'] ?? 0);
        $cek = $pdo->prepare("SELECT 1 FROM deadline WHERE id = ?");
        $cek->execute([$deadlineId]);

        if (!$cek->fetchColumn()) {
            header('Location: ' . kembaliKe('unit', ['status' => 'not_found']));
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
        header('Location: ' . kembaliKe('unit', ['lampiran' => $deadlineId]));
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
        header('Location: ' . kembaliKe('unit', $deadlineId ? ['lampiran' => $deadlineId] : []));
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
        header('Location: ' . kembaliKe('penerima', [], 'penerima'));
        exit;
    }

    // Edit nama penerima WA / Telegram (nomor & chat id tidak berubah)
    if ($aksi === 'ubah_nama_penerima' || $aksi === 'ubah_nama_telegram') {
        $tabel = $aksi === 'ubah_nama_penerima' ? 'wa_penerima' : 'telegram_penerima';
        $jangkar = $aksi === 'ubah_nama_penerima' ? 'penerima' : 'telegram';
        $nama  = trim((string) ($_POST['nama'] ?? ''));
        if ($nama === '') {
            flash('danger', 'Nama penerima tidak boleh kosong.');
        } else {
            $nama = function_exists('mb_substr') ? mb_substr($nama, 0, 100) : substr($nama, 0, 100);
            $pdo->prepare("UPDATE $tabel SET nama = ? WHERE id = ?")->execute([$nama, (int) ($_POST['id'] ?? 0)]);
            flash('success', "Nama penerima diubah menjadi \"$nama\".");
        }
        header('Location: ' . kembaliKe('penerima', [], $jangkar));
        exit;
    }

    if ($aksi === 'toggle_penerima') {
        $pid = (int) ($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE wa_penerima SET aktif = 1 - aktif WHERE id = ?")->execute([$pid]);
        header('Location: ' . kembaliKe('penerima', [], 'penerima'));
        exit;
    }

    if ($aksi === 'hapus_penerima') {
        $pid = (int) ($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM wa_penerima WHERE id = ?")->execute([$pid]);
        flash('warning', 'Nomor penerima dihapus.');
        header('Location: ' . kembaliKe('penerima', [], 'penerima'));
        exit;
    }

    // --- 8. KELOLA PENERIMA TELEGRAM ---
    if ($aksi === 'tambah_telegram') {
        $nama   = trim((string) ($_POST['nama'] ?? ''));
        $chatId = preg_replace('/\s+/', '', (string) ($_POST['chat_id'] ?? ''));

        if ($nama === '' || !chatIdTelegramValid($chatId)) {
            flash('danger', 'Nama wajib diisi dan Chat ID Telegram harus berupa angka (grup diawali tanda minus, contoh: -1001234567890).');
        } else {
            try {
                $cek = $pdo->prepare("SELECT nama, aktif FROM telegram_penerima WHERE chat_id = ?");
                $cek->execute([$chatId]);
                $ada = $cek->fetch();

                if ($ada) {
                    flash('warning', "Chat ID $chatId sudah terdaftar atas nama \"{$ada['nama']}\" (status: "
                        . ((int) $ada['aktif'] === 1 ? 'aktif' : 'nonaktif — klik tombol ▶ untuk mengaktifkan') . ').');
                } else {
                    $pdo->prepare("INSERT INTO telegram_penerima (nama, chat_id, dibuat_tanggal) VALUES (?, ?, ?)")
                        ->execute([function_exists('mb_substr') ? mb_substr($nama, 0, 100) : substr($nama, 0, 100), $chatId, date('Y-m-d H:i:s')]);
                    flash('success', "Chat Telegram $nama ($chatId) ditambahkan sebagai penerima.");
                }
            } catch (PDOException $e) {
                error_log('Tambah penerima Telegram gagal: ' . $e->getMessage());
                flash('danger', 'Gagal menyimpan Chat ID: ' . $e->getMessage());
            }
        }
        header('Location: ' . kembaliKe('penerima', [], 'telegram'));
        exit;
    }

    if ($aksi === 'toggle_telegram') {
        $pdo->prepare("UPDATE telegram_penerima SET aktif = 1 - aktif WHERE id = ?")->execute([(int) ($_POST['id'] ?? 0)]);
        header('Location: ' . kembaliKe('penerima', [], 'telegram'));
        exit;
    }

    if ($aksi === 'hapus_telegram') {
        $pdo->prepare("DELETE FROM telegram_penerima WHERE id = ?")->execute([(int) ($_POST['id'] ?? 0)]);
        flash('warning', 'Chat Telegram penerima dihapus.');
        header('Location: ' . kembaliKe('penerima', [], 'telegram'));
        exit;
    }

    // Cari chat yang baru mengirim pesan ke bot -> ditampilkan sekali di panel Telegram (tombol "Tambah")
    if ($aksi === 'cari_telegram') {
        $hasil = cariChatTelegram();
        if (!$hasil['ok']) {
            flash('danger', 'Gagal mencari chat Telegram: ' . $hasil['pesan']);
        } elseif (!$hasil['chat']) {
            flash('warning', 'Belum ada chat baru. Minta penerima membuka bot lalu klik Start (di grup: ketik /start@nama_bot), kemudian klik Cari lagi.');
        } else {
            $_SESSION['tg_ditemukan'] = $hasil['chat'];
        }
        header('Location: ' . kembaliKe('penerima', [], 'telegram'));
        exit;
    }

    header('Location: ' . kembaliKe('unit'));
    exit;
}

// ===================================================================
// DATA UNTUK TAMPILAN
// ===================================================================
$today    = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));

// --- STATISTIK RINGKAS (dihitung langsung di database) ---
// Hari Ini / H-1 / Mendatang / Terlewat hanya menghitung unit yang BELUM selesai
$stmtStat = $pdo->prepare(
    "SELECT
        COUNT(*)                                                         AS total,
        COALESCE(SUM(selesai_tanggal IS NULL AND tanggal_akhir = :t1), 0) AS hari_ini,
        COALESCE(SUM(selesai_tanggal IS NULL AND tanggal_akhir = :b1), 0) AS h1,
        COALESCE(SUM(selesai_tanggal IS NULL AND tanggal_akhir > :b2), 0) AS mendatang,
        COALESCE(SUM(selesai_tanggal IS NULL AND tanggal_akhir < :t2), 0) AS terlewat,
        COALESCE(SUM(selesai_tanggal IS NOT NULL), 0)                    AS selesai
     FROM deadline"
);
$stmtStat->execute([':t1' => $today, ':b1' => $tomorrow, ':b2' => $tomorrow, ':t2' => $today]);
$stat = $stmtStat->fetch();

$stat_total     = (int) $stat['total'];
$stat_hari_ini  = (int) $stat['hari_ini'];
$stat_h1        = (int) $stat['h1'];
$stat_mendatang = (int) $stat['mendatang'];
$stat_terlewat  = (int) $stat['terlewat'];
$stat_selesai   = (int) $stat['selesai'];

// --- SEARCH, FILTER, DAN PAGINATION ---
$search = trim((string) ($_GET['search'] ?? ''));
// Rentang tanggal pencarian (opsional): unit dengan tanggal awal >= dari, dan tanggal akhir <= sampai
$tgl_dari   = (string) ($_GET['dari'] ?? '');
$tgl_sampai = (string) ($_GET['sampai'] ?? '');
$tgl_dari   = tanggal_valid($tgl_dari) ? $tgl_dari : '';
$tgl_sampai = tanggal_valid($tgl_sampai) ? $tgl_sampai : '';
$filter = (string) ($_GET['filter'] ?? 'semua');
$filter = $filter === 'expired' ? 'terlewat' : $filter; // tautan lama ?filter=expired
$filter_valid = ['semua', 'hari_ini', 'h1', 'mendatang', 'terlewat', 'selesai'];
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

if ($tgl_dari !== '') {
    $where[]         = "tanggal_awal >= :dari";
    $params[':dari'] = $tgl_dari;
}
if ($tgl_sampai !== '') {
    $where[]           = "tanggal_akhir <= :sampai";
    $params[':sampai'] = $tgl_sampai;
}

// Filter status dari tab status (Hari Ini, H-1, Mendatang, Terlewat = belum selesai; Selesai)
switch ($filter) {
    case 'hari_ini':
        $where[] = "selesai_tanggal IS NULL AND tanggal_akhir = :f";
        $params[':f'] = $today;
        break;
    case 'h1':
        $where[] = "selesai_tanggal IS NULL AND tanggal_akhir = :f";
        $params[':f'] = $tomorrow;
        break;
    case 'mendatang':
        $where[] = "selesai_tanggal IS NULL AND tanggal_akhir > :f";
        $params[':f'] = $tomorrow;
        break;
    case 'terlewat':
        $where[] = "selesai_tanggal IS NULL AND tanggal_akhir < :f";
        $params[':f'] = $today;
        break;
    case 'selesai':
        $where[] = "selesai_tanggal IS NOT NULL";
        break;
}

$where_sql = $where ? "WHERE " . implode(' AND ', $where) : "";

$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM deadline $where_sql");
$stmtCount->execute($params);
$total_rows = (int) $stmtCount->fetchColumn();

// Daftar unit ditampilkan 5 per halaman (?hal=2, ?hal=3, ...)
const DATA_PER_HALAMAN = 5;
$jumlah_hal = max(1, (int) ceil($total_rows / DATA_PER_HALAMAN));
$hal        = min($jumlah_hal, max(1, (int) ($_GET['hal'] ?? 1)));
$offset     = ($hal - 1) * DATA_PER_HALAMAN;

// LIMIT/OFFSET berupa integer -> aman disisipkan langsung
// Urutan: unit yang belum selesai dulu, deadline terdekat di atas (terlewat paling atas); unit selesai di bawah
$stmtData = $pdo->prepare(
    "SELECT * FROM deadline $where_sql
     ORDER BY (selesai_tanggal IS NOT NULL), tanggal_akhir, id DESC
     LIMIT " . DATA_PER_HALAMAN . " OFFSET $offset"
);
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

// --- BUKTI PENGINGAT untuk unit yang sudah selesai: pengiriman terakhir di riwayat ---
// Riwayat mencatat unit sebagai "(kode) nama" (lihat cron_wa_reminder.php & kirim manual)
$bukti_pengingat = [];
$cariBukti = $pdo->prepare("SELECT waktu, status FROM wa_log WHERE ringkasan LIKE ? ORDER BY id DESC LIMIT 1");
foreach ($data_tampil as $row) {
    if ($row['selesai_tanggal'] !== null) {
        $cariBukti->execute(['%' . addcslashes('(' . $row['kode_unit'] . ') ' . $row['nama_unit'], '%_\\') . '%']);
        $bukti_pengingat[(int) $row['id']] = $cariBukti->fetch() ?: null;
    }
}

// --- PENERIMA WA ---
$penerima_list   = $pdo->query("SELECT * FROM wa_penerima ORDER BY id")->fetchAll();
$penerima_aktif  = count(array_filter($penerima_list, static fn($p) => (int) $p['aktif'] === 1));
$wa_target_env   = normalisasiNomorWa((string) env('WA_TARGET', ''));
$telegram_list   = $pdo->query("SELECT * FROM telegram_penerima ORDER BY id")->fetchAll();
$telegram_aktif  = count(array_filter($telegram_list, static fn($t) => (int) $t['aktif'] === 1));
$telegram_env    = $telegram_list ? [] : array_filter(preg_split('/[\s,;]+/', (string) env('TELEGRAM_CHAT_ID', '')), 'chatIdTelegramValid');
// Hasil tombol "Cari chat" (sekali tampil). Chat yang sudah terdaftar tidak ditampilkan lagi.
$tg_ditemukan    = array_diff_key($_SESSION['tg_ditemukan'] ?? [], array_flip(array_column($telegram_list, 'chat_id')));
unset($_SESSION['tg_ditemukan']);

// --- RIWAYAT PENGIRIMAN WA: pencarian + 5 per halaman (?hal_wa=2, ...), terbaru di atas ---
$cari_wa   = trim((string) ($_GET['cari_wa'] ?? ''));
$status_wa = in_array($_GET['status_wa'] ?? '', ['berhasil', 'sebagian', 'gagal'], true) ? $_GET['status_wa'] : '';
$tgl_wa    = tanggal_valid((string) ($_GET['tgl_wa'] ?? '')) ? (string) $_GET['tgl_wa'] : '';

$where_wa  = [];
$params_wa = [];
if ($cari_wa !== '') {
    // Cocokkan unit, nomor penerima, keterangan error, dan NAMA penerima (nama -> nomor dari daftar penerima)
    $kolom = ["ringkasan LIKE :q1", "penerima LIKE :q2", "keterangan LIKE :q3"];
    $params_wa += [':q1' => "%$cari_wa%", ':q2' => "%$cari_wa%", ':q3' => "%$cari_wa%"];
    foreach ($penerima_list as $i => $p) {
        if (stripos($p['nama'], $cari_wa) !== false) {
            $kolom[] = "penerima LIKE :n$i";
            $params_wa[":n$i"] = '%' . $p['nomor'] . '%';
        }
    }
    $where_wa[] = '(' . implode(' OR ', $kolom) . ')';
}
if ($status_wa !== '') {
    $where_wa[] = "status = :st";
    $params_wa[':st'] = $status_wa;
}
if ($tgl_wa !== '') {
    $where_wa[] = "waktu >= :t1 AND waktu < :t2";
    $params_wa[':t1'] = "$tgl_wa 00:00:00";
    $params_wa[':t2'] = date('Y-m-d', strtotime("$tgl_wa +1 day")) . ' 00:00:00';
}
$where_wa_sql      = $where_wa ? 'WHERE ' . implode(' AND ', $where_wa) : '';
$menyaring_riwayat = (bool) $where_wa;

$stmtWa = $pdo->prepare("SELECT COUNT(*) FROM wa_log $where_wa_sql");
$stmtWa->execute($params_wa);
$total_wa      = (int) $stmtWa->fetchColumn();
$jumlah_hal_wa = max(1, (int) ceil($total_wa / DATA_PER_HALAMAN));
$hal_wa        = min($jumlah_hal_wa, max(1, (int) ($_GET['hal_wa'] ?? 1)));
$stmtWa = $pdo->prepare("SELECT * FROM wa_log $where_wa_sql ORDER BY id DESC LIMIT " . DATA_PER_HALAMAN . " OFFSET " . (($hal_wa - 1) * DATA_PER_HALAMAN));
$stmtWa->execute($params_wa);
$riwayat_wa = $stmtWa->fetchAll();
// Nomor -> nama penerima, agar kolom Penerima di riwayat menampilkan nama (bukan hanya nomor).
// Nomor yang sudah dihapus dari daftar penerima tetap tampil sebagai nomor saja.
$nama_penerima = array_column($penerima_list, 'nama', 'nomor');
$nama_telegram = array_column($telegram_list, 'nama', 'chat_id');
$saluran_notif = saluranNotifikasi(); // ['wa'], ['telegram'], atau keduanya (NOTIF_VIA)
// Saluran yang bisa dipilih di tombol kirim manual (token sudah diisi)
$bisa_wa = (string) env('FONNTE_TOKEN', '') !== '';
$bisa_tg = (string) env('TELEGRAM_BOT_TOKEN', '') !== '';

// --- PESAN NOTIFIKASI ---
$alerts = [
    'success_add'     => ['success', 'Data berhasil disimpan!'],
    'success_update'  => ['info', 'Data berhasil diperbarui!'],
    'success_delete'  => ['warning', 'Data berhasil dihapus!'],
    'wa_sent'         => ['success', 'Pesan pengingat berhasil dikirim!'],
    'wa_failed'       => ['danger', 'Gagal mengirim pesan. Cek token & penerima WhatsApp/Telegram.'],
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


// --- MENU YANG DIBUKA: Daftar Unit (bawaan), Penerima, Riwayat Kirim ---
$menu = in_array($_GET['menu'] ?? '', ['penerima', 'riwayat'], true) ? $_GET['menu'] : 'unit';

// --- TAB STATUS di atas tabel unit: [filter, label, jumlah, perlu perhatian?] ---
$tab_status = [
    ['semua', 'Semua', $stat_total, false],
    ['hari_ini', 'Hari ini', $stat_hari_ini, $stat_hari_ini > 0],
    ['h1', 'Besok', $stat_h1, false],
    ['mendatang', 'Mendatang', $stat_mendatang, false],
    ['terlewat', 'Terlewat', $stat_terlewat, $stat_terlewat > 0],
    ['selesai', 'Selesai', $stat_selesai, false],
];

// --- PENGINGAT OTOMATIS TERAKHIR (bilah info di bawah tabel unit) ---
$pengingat_terakhir = $pdo->query("SELECT waktu, ringkasan, penerima, status FROM wa_log WHERE jenis = 'otomatis' ORDER BY id DESC LIMIT 1")->fetch() ?: null;

// --- TANGGAL HARI INI dalam bahasa Indonesia, contoh: Kamis, 02 Oktober 2026 ---
$nama_hari  = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
$nama_bulan = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$tanggal_hari_ini = $nama_hari[(int) date('w')] . ', ' . date('d') . ' ' . $nama_bulan[(int) date('n')] . ' ' . date('Y');
// Ada pencarian / filter aktif? (untuk keterangan & tombol hapus filter)
$sedang_menyaring = $search !== '' || $filter !== 'semua' || $tgl_dari !== '' || $tgl_sampai !== '';

$accept_lampiran = '.' . implode(',.', array_keys(LAMPIRAN_TIPE));
$info_lampiran   = 'PDF, gambar, Word, Excel, CSV, TXT. Maks ' . formatUkuran(LAMPIRAN_MAKS_BYTE) . ' per file.';

/** Kolom Deadline: titik warna + keterangan, contoh "● Terlewat 9 hari". Unit selesai: siapa/kapan + bukti pengingat. */
function selStatus(array $row, ?array $bukti): string
{
    if ($row['selesai_tanggal'] !== null) {
        $oleh = e((string) $row['selesai_oleh']);
        $html = '<div class="st hijau">Selesai</div>'
            . '<div class="st-sub" title="Ditandai selesai oleh ' . $oleh . ' pada ' . tglPendek($row['selesai_tanggal']) . '">oleh ' . $oleh . ', ' . tglPendek($row['selesai_tanggal'], false) . '</div>';
        $html .= $bukti
            ? '<div class="st-sub" title="Bukti: pengingat terakhir unit ini di Riwayat Kirim">pengingat ' . tglPendek($bukti['waktu'], false) . ', '
                . '<span class="' . ($bukti['status'] === 'gagal' ? 'teks-merah' : '') . '">' . e($bukti['status']) . '</span></div>'
            : '<div class="st-sub">belum ada pengingat</div>';
        return $html;
    }
    $sisa = (int) $row['sisa_hari'];
    if ($sisa < 0) {
        return '<div class="st merah">Terlewat ' . abs($sisa) . ' hari</div><div class="st-sub">belum ditandai selesai</div>';
    }
    if ($sisa === 0) {
        return '<div class="st merah">Jatuh tempo hari ini</div>';
    }
    if ($sisa === 1) {
        return '<div class="st amber">Besok</div>';
    }
    return '<div class="st abu">' . $sisa . ' hari lagi</div>';
}

/** Tombol aksi satu unit: "Tandai selesai" / "Batalkan" + menu ⋯ (edit, lampiran, kirim sekarang, hapus) */
function aksiUnit(array $row, int $jmlLampiran, bool $bisaWa, bool $bisaTg): string
{
    $id      = (int) $row['id'];
    $selesai = $row['selesai_tanggal'] !== null;
    $tanya   = $selesai ? 'Batalkan status selesai unit ini?' : 'Tandai unit ini selesai (user sudah di-update)? Pengingat otomatis untuk unit ini dihentikan.';
    $kirim   = static fn(string $via, string $label, bool $bisa) => '<button type="submit" name="via" value="' . $via . '" data-label="' . $label . '" class="dropdown-item"' . ($bisa ? '' : ' disabled') . '>'
        . $label . ($bisa ? '' : ' <small>(token belum diatur)</small>') . '</button>';
    ob_start(); ?>
    <div class="aksi">
        <form method="POST" action="dashboard.php" onsubmit="return confirm('<?= $tanya; ?>')">
            <?= csrf_field(); ?>
            <input type="hidden" name="aksi" value="<?= $selesai ? 'batal_selesai' : 'tandai_selesai'; ?>">
            <input type="hidden" name="id" value="<?= $id; ?>">
            <button type="submit" class="btn btn-garis btn-kecil"><?= $selesai ? 'Batalkan' : 'Tandai selesai'; ?></button>
        </form>
        <div class="dropdown">
            <button type="button" class="btn btn-garis btn-kecil btn-titik" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}' aria-expanded="false" aria-label="Aksi lain" title="Aksi lain">&#8943;</button>
            <ul class="dropdown-menu dropdown-menu-end menu-aksi">
                <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#modalEdit<?= $id; ?>">Edit / perpanjang deadline</button></li>
                <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#modalLampiran<?= $id; ?>">Lampiran<?= $jmlLampiran ? ' <span class="angka">' . $jmlLampiran . '</span>' : ''; ?></button></li>
                <li><hr class="dropdown-divider"></li>
                <li><h6 class="dropdown-header">Kirim pengingat sekarang</h6></li>
                <li>
                    <form method="POST" action="dashboard.php" data-unit="<?= e($row['nama_unit']); ?>"
                          onsubmit="return confirm('Kirim pengingat lewat ' + (event.submitter ? event.submitter.dataset.label : 'saluran pilihan') + ' sekarang untuk unit ' + this.dataset.unit + '?')">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="aksi" value="kirim_wa">
                        <input type="hidden" name="id" value="<?= $id; ?>">
                        <?= $kirim('wa', 'WhatsApp', $bisaWa) . $kirim('telegram', 'Telegram', $bisaTg) . $kirim('semua', 'WhatsApp dan Telegram', $bisaWa && $bisaTg); ?>
                    </form>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <form method="POST" action="dashboard.php" onsubmit="return confirm('Hapus unit ini beserta lampirannya?')">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="aksi" value="hapus">
                        <input type="hidden" name="id" value="<?= $id; ?>">
                        <button type="submit" class="dropdown-item teks-merah">Hapus unit</button>
                    </form>
                </li>
            </ul>
        </div>
    </div>
    <?php return (string) ob_get_clean();
}

$judul_menu = ['unit' => 'Daftar Unit', 'penerima' => 'Penerima', 'riwayat' => 'Riwayat Kirim'][$menu];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <?= kepalaHalaman($judul_menu); ?>
    <link rel="apple-touch-icon" href="https://cdn-icons-png.flaticon.com/512/3602/3602145.png">
    <style>
        /* Daftar file yang dipilih (sebelum disimpan) */
        .daftar-pilihan .list-group-item { padding: 6px 10px; font-size: .85rem; }
        /* Popup pratinjau file sebelum disimpan (di atas popup Bootstrap) */
        #pratinjauLokal { position: fixed; inset: 0; z-index: 2000; background: rgba(29, 37, 40, .75); display: none; padding: 16px; }
        #pratinjauLokal.tampil { display: flex; }
        #pratinjauLokal .kotak { background: #fff; border-radius: 6px; width: 100%; max-width: 1000px; margin: auto; height: 100%; display: flex; flex-direction: column; overflow: hidden; }
        #pratinjauLokal .bilah { display: flex; align-items: center; gap: 8px; padding: 10px 14px; border-bottom: 1px solid #dedbd3; }
        #pratinjauLokal .wadah { flex: 1; min-height: 0; background: #f3f2ee; }
        #pratinjauLokal iframe { width: 100%; height: 100%; border: 0; background: #fff; display: block; }
        #pratinjauLokal img { max-width: 100%; max-height: 100%; display: block; margin: auto; }
    </style>
</head>
<body class="app">

<?= sidebarAplikasi($menu, ['unit' => $stat_total, 'penerima' => $penerima_aktif + $telegram_aktif]); ?>

<main class="isi">

    <!-- Judul halaman -->
    <div class="kepala">
        <div>
            <h1><?= e($judul_menu); ?></h1>
            <div class="kepala-sub">
                <?= e($tanggal_hari_ini); ?>
                <?php if ($menu === 'unit'): ?> · pengingat otomatis setiap hari pukul 08.00 WIB<?php endif; ?>
                <?php if ($menu === 'penerima'): ?> · nomor WhatsApp dan chat Telegram yang menerima pengingat<?php endif; ?>
                <?php if ($menu === 'riwayat'): ?> · <?= $total_wa; ?> pengiriman<?= $menyaring_riwayat ? ' sesuai pencarian' : ''; ?><?php endif; ?>
            </div>
        </div>
        <?php if ($menu === 'unit'): ?>
            <button type="button" class="btn btn-gelap" data-bs-toggle="modal" data-bs-target="#modalTambah">+ Tambah unit</button>
        <?php endif; ?>
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
        <div class="alert alert-<?= e($tipe); ?> alert-dismissible fade show" role="alert">
            <?= e($pesan); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endforeach; ?>

<?php if ($menu === 'unit'): ?>
    <!-- ================= DAFTAR UNIT ================= -->
    <section class="panel" id="daftar">
        <?php // Tab status: klik untuk menampilkan unit dengan status tersebut ?>
        <nav class="tabs" aria-label="Filter status">
            <?php foreach ($tab_status as [$kunci, $label, $nilai, $penting]): ?>
                <a href="dashboard.php<?= $kunci === 'semua' ? '' : '?filter=' . $kunci; ?>" class="<?= $filter === $kunci ? 'on' : ''; ?> <?= $penting ? 'penting' : ''; ?>"<?= $filter === $kunci ? ' aria-current="page"' : ''; ?>>
                    <?= e($label); ?> <i><?= (int) $nilai; ?></i>
                </a>
            <?php endforeach; ?>
        </nav>

        <?php // Pencarian: nama / kode unit + rentang tanggal ?>
        <form method="GET" action="dashboard.php" class="bar">
            <?php if ($filter !== 'semua'): ?><input type="hidden" name="filter" value="<?= e($filter); ?>"><?php endif; ?>
            <input type="text" name="search" class="form-control bar-cari" placeholder="Cari nama atau kode unit" value="<?= e($search); ?>" aria-label="Cari nama atau kode unit">
            <label class="bar-tgl" title="Tampilkan unit dengan tanggal awal mulai tanggal ini">
                <span>Awal</span><input type="date" name="dari" class="form-control" value="<?= e($tgl_dari); ?>" aria-label="Tanggal awal">
            </label>
            <label class="bar-tgl" title="Tampilkan unit dengan tanggal akhir sampai tanggal ini">
                <span>Akhir</span><input type="date" name="sampai" class="form-control" value="<?= e($tgl_sampai); ?>" aria-label="Tanggal akhir">
            </label>
            <button type="submit" class="btn btn-garis">Cari</button>
            <?php if ($sedang_menyaring): ?>
                <a href="dashboard.php" class="btn btn-teks">Hapus filter</a>
            <?php endif; ?>
            <span class="bar-ket"><?= (int) $total_rows; ?> unit · deadline terdekat di atas</span>
        </form>

        <div class="table-responsive">
            <table class="tabel-a tabel-unit">
                <thead>
                    <tr>
                        <th style="width:30%">Unit</th>
                        <th style="width:17%">Periode</th>
                        <th style="width:21%">Deadline</th>
                        <th style="width:14%">Terkirim Tgl</th>
                        <th class="text-end"><span class="visually-hidden">Aksi</span></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$data_tampil): ?>
                    <tr><td colspan="5"><div class="kosong"><?= $sedang_menyaring ? 'Tidak ada unit yang cocok dengan filter ini.' : 'Belum ada unit. Klik “+ Tambah unit” untuk mulai.'; ?></div></td></tr>
                <?php endif; ?>
                <?php foreach ($data_tampil as $row): $id = (int) $row['id']; ?>
                    <tr class="<?= $row['selesai_tanggal'] !== null ? 'baris-selesai' : ''; ?>">
                        <td data-label="Unit">
                            <div class="nama"><?= e($row['nama_unit']); ?></div>
                            <div class="kode"><?= e($row['kode_unit']); ?></div>
                        </td>
                        <td data-label="Periode" class="mono" title="Tanggal awal s/d tanggal akhir">
                            <?= tglPendek($row['tanggal_awal']); ?> &rarr; <br><b><?= tglPendek($row['tanggal_akhir']); ?></b>
                        </td>
                        <td data-label="Deadline"><?= selStatus($row, $bukti_pengingat[$id] ?? null); ?></td>
                        <td data-label="Terkirim Tgl" class="mono">
                            <?php if (empty($row['terakhir_dikirim'])): ?>
                                <span class="redup">&mdash;</span>
                            <?php else: ?>
                                <span title="Pengingat otomatis terakhir terkirim"><?= tglPendek($row['terakhir_dikirim']); ?></span>
                                <?php // "Kirim ulang": izinkan cron mengirim lagi (tidak untuk unit terlewat) ?>
                                <?php if ($row['sisa_hari'] >= 0): ?>
                                    <form method="POST" action="dashboard.php" onsubmit="return confirm('Reset status pengingat unit ini agar dikirim ulang otomatis pada jadwal berikutnya?')">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="aksi" value="reset_pengingat">
                                        <input type="hidden" name="id" value="<?= $id; ?>">
                                        <button type="submit" class="btn-tautan">kirim ulang</button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td class="text-end"><?= aksiUnit($row, count($lampiran_per_unit[$id] ?? []), $bisa_wa, $bisa_tg); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="panel-kaki">
            <span>Menampilkan <?= $total_rows ? ($offset + 1) . '–' . ($offset + count($data_tampil)) : 0; ?> dari <?= (int) $total_rows; ?> unit</span>
            <?= tombolHalaman($hal, $jumlah_hal, 'hal', 'daftar'); ?>
        </div>
    </section>

    <?php // Bilah info: pengingat otomatis terakhir + saluran yang aktif ?>
    <section class="panel info">
        <span>Pengingat otomatis terakhir:
            <?php if ($pengingat_terakhir): ?>
                <b><?= tglPendek($pengingat_terakhir['waktu']) . ', ' . date('H.i', strtotime($pengingat_terakhir['waktu'])); ?></b>
                · <?= count(daftarUnitRiwayat($pengingat_terakhir['ringkasan'])); ?> unit
                · <span class="<?= $pengingat_terakhir['status'] === 'berhasil' ? '' : 'teks-merah'; ?>"><?= e($pengingat_terakhir['status']); ?></span>
            <?php else: ?>
                <b>belum pernah</b>
            <?php endif; ?>
        </span>
        <span>Saluran aktif: <b><?= e(implode(' dan ', array_map('namaSaluran', $saluran_notif))); ?></b></span>
        <a href="dashboard.php?menu=riwayat">Lihat riwayat &rarr;</a>
    </section>

<?php elseif ($menu === 'penerima'): ?>
    <!-- ================= PENERIMA ================= -->
    <div class="dua-kolom">
        <section class="panel" id="penerima">
            <div class="panel-judul">
                <h2>WhatsApp</h2>
                <span><?= $penerima_aktif; ?> aktif<?= in_array('wa', $saluran_notif, true) ? '' : ' · tidak dipakai'; ?></span>
            </div>
            <?php if (!in_array('wa', $saluran_notif, true)): ?>
                <p class="catatan">WhatsApp sedang tidak dipakai (<code>NOTIF_VIA=telegram</code>). Pengingat hanya dikirim ke Telegram.</p>
            <?php endif; ?>
            <?php if (!$penerima_list): ?>
                <p class="catatan">Belum ada nomor.<?php if ($wa_target_env !== ''): ?> Saat ini pengingat dikirim ke nomor dari pengaturan server (<b><?= e($wa_target_env); ?></b>).<?php endif; ?></p>
            <?php endif; ?>
            <?php foreach ($penerima_list as $p): $aktif = (int) $p['aktif'] === 1; ?>
                <div class="baris-penerima <?= $aktif ? '' : 'nonaktif'; ?>">
                    <div>
                        <div class="nama"><?= e($p['nama']); ?><?= $aktif ? '' : ' <span class="tanda">nonaktif</span>'; ?></div>
                        <div class="kode">+<?= e($p['nomor']); ?></div>
                    </div>
                    <div class="aksi">
                        <button type="button" class="btn btn-teks btn-kecil" data-bs-toggle="modal" data-bs-target="#modalNamaPenerima"
                                data-aksi="ubah_nama_penerima" data-id="<?= (int) $p['id']; ?>" data-nama="<?= e($p['nama']); ?>" data-info="WhatsApp +<?= e($p['nomor']); ?>">Ubah nama</button>
                        <form method="POST" action="dashboard.php">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="aksi" value="toggle_penerima">
                            <input type="hidden" name="id" value="<?= (int) $p['id']; ?>">
                            <button type="submit" class="btn btn-teks btn-kecil"><?= $aktif ? 'Nonaktifkan' : 'Aktifkan'; ?></button>
                        </form>
                        <form method="POST" action="dashboard.php" onsubmit="return confirm('Hapus nomor ini dari penerima WhatsApp?')">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="aksi" value="hapus_penerima">
                            <input type="hidden" name="id" value="<?= (int) $p['id']; ?>">
                            <button type="submit" class="btn btn-teks btn-kecil teks-merah">Hapus</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
            <form method="POST" action="dashboard.php" class="form-tambah">
                <?= csrf_field(); ?>
                <input type="hidden" name="aksi" value="tambah_penerima">
                <div class="label-kecil">Tambah nomor</div>
                <div class="baris-input">
                    <input type="text" name="nama" class="form-control" placeholder="Nama, contoh: Admin Kantor" maxlength="100" required>
                    <input type="tel" name="nomor" class="form-control" placeholder="08xxxxxxxxxx" inputmode="numeric" required>
                    <button type="submit" class="btn btn-gelap">Tambah</button>
                </div>
            </form>
        </section>

        <?php $telegram_dipakai = in_array('telegram', $saluran_notif, true); ?>
        <section class="panel" id="telegram">
            <div class="panel-judul">
                <h2>Telegram</h2>
                <span><?= $telegram_aktif; ?> aktif<?= $telegram_dipakai ? '' : ' · tidak dipakai'; ?></span>
            </div>
            <?php if (!$telegram_dipakai): ?>
                <p class="catatan">Telegram belum dipakai. Isi <code>NOTIF_VIA=wa,telegram</code> dan <code>TELEGRAM_BOT_TOKEN</code> di pengaturan server agar pengingat juga dikirim ke chat di bawah.</p>
            <?php endif; ?>
            <?php if (!$telegram_list): ?>
                <p class="catatan">Belum ada chat.<?php if ($telegram_env): ?> Saat ini pengingat dikirim ke Chat ID dari pengaturan server (<b><?= e(implode(', ', $telegram_env)); ?></b>).<?php endif; ?></p>
            <?php endif; ?>
            <?php foreach ($telegram_list as $t): $aktif = (int) $t['aktif'] === 1; ?>
                <div class="baris-penerima <?= $aktif ? '' : 'nonaktif'; ?>">
                    <div>
                        <div class="nama"><?= e($t['nama']); ?><?= $aktif ? '' : ' <span class="tanda">nonaktif</span>'; ?></div>
                        <div class="kode">ID <?= e($t['chat_id']); ?><?= strncmp($t['chat_id'], '-', 1) === 0 ? ' · grup' : ''; ?></div>
                    </div>
                    <div class="aksi">
                        <button type="button" class="btn btn-teks btn-kecil" data-bs-toggle="modal" data-bs-target="#modalNamaPenerima"
                                data-aksi="ubah_nama_telegram" data-id="<?= (int) $t['id']; ?>" data-nama="<?= e($t['nama']); ?>" data-info="Telegram ID <?= e($t['chat_id']); ?>">Ubah nama</button>
                        <form method="POST" action="dashboard.php">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="aksi" value="toggle_telegram">
                            <input type="hidden" name="id" value="<?= (int) $t['id']; ?>">
                            <button type="submit" class="btn btn-teks btn-kecil"><?= $aktif ? 'Nonaktifkan' : 'Aktifkan'; ?></button>
                        </form>
                        <form method="POST" action="dashboard.php" onsubmit="return confirm('Hapus chat ini dari penerima Telegram?')">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="aksi" value="hapus_telegram">
                            <input type="hidden" name="id" value="<?= (int) $t['id']; ?>">
                            <button type="submit" class="btn btn-teks btn-kecil teks-merah">Hapus</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php // Hasil tombol "Cari chat": klik Tambah untuk langsung menyimpan ?>
            <?php if ($tg_ditemukan): ?>
                <div class="label-kecil mt-3">Chat ditemukan</div>
                <?php foreach ($tg_ditemukan as $cid => $c): ?>
                    <div class="baris-penerima ditemukan">
                        <div>
                            <div class="nama"><?= e($c['nama']); ?> <span class="tanda"><?= e($c['jenis']); ?></span></div>
                            <div class="kode">ID <?= e((string) $cid); ?></div>
                        </div>
                        <form method="POST" action="dashboard.php">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="aksi" value="tambah_telegram">
                            <input type="hidden" name="nama" value="<?= e($c['nama']); ?>">
                            <input type="hidden" name="chat_id" value="<?= e((string) $cid); ?>">
                            <button type="submit" class="btn btn-garis btn-kecil">Tambah</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <div class="form-tambah">
                <div class="label-kecil">Tambah chat</div>
                <form method="POST" action="dashboard.php" class="mb-2">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="aksi" value="cari_telegram">
                    <button type="submit" class="btn btn-garis w-100" <?= env('TELEGRAM_BOT_TOKEN') ? '' : 'disabled title="Isi TELEGRAM_BOT_TOKEN dulu"'; ?>>Cari chat yang sudah klik Start di bot</button>
                </form>
                <form method="POST" action="dashboard.php" class="baris-input">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="aksi" value="tambah_telegram">
                    <input type="text" name="nama" class="form-control" placeholder="Nama, contoh: Grup Admin" maxlength="100" required>
                    <input type="text" name="chat_id" class="form-control" placeholder="Chat ID" inputmode="numeric" pattern="-?[0-9]{3,20}" title="Angka; grup diawali tanda minus" required>
                    <button type="submit" class="btn btn-gelap">Tambah</button>
                </form>
            </div>
        </section>
    </div>

<?php else: ?>
    <!-- ================= RIWAYAT KIRIM ================= -->
    <section class="panel" id="riwayat">
        <form method="GET" action="dashboard.php" class="bar">
            <input type="hidden" name="menu" value="riwayat">
            <input type="text" name="cari_wa" class="form-control bar-cari" placeholder="Cari unit atau penerima" value="<?= e($cari_wa); ?>" aria-label="Cari unit atau penerima">
            <select name="status_wa" class="form-select bar-pilih" aria-label="Status pengiriman">
                <option value="">Semua status</option>
                <option value="berhasil" <?= $status_wa === 'berhasil' ? 'selected' : ''; ?>>Berhasil</option>
                <option value="sebagian" <?= $status_wa === 'sebagian' ? 'selected' : ''; ?>>Sebagian gagal</option>
                <option value="gagal" <?= $status_wa === 'gagal' ? 'selected' : ''; ?>>Gagal</option>
            </select>
            <label class="bar-tgl" title="Tanggal kirim"><span>Tanggal</span><input type="date" name="tgl_wa" class="form-control" value="<?= e($tgl_wa); ?>" aria-label="Tanggal kirim"></label>
            <button type="submit" class="btn btn-garis">Cari</button>
            <?php if ($menyaring_riwayat): ?>
                <a href="dashboard.php?menu=riwayat" class="btn btn-teks">Hapus filter</a>
            <?php endif; ?>
        </form>
        <div class="table-responsive">
            <table class="tabel-a tabel-riwayat">
                <thead><tr><th style="width:15%">Waktu</th><th style="width:10%">Jenis</th><th style="width:28%">Unit</th><th>Penerima</th><th style="width:17%">Status</th></tr></thead>
                <tbody>
                <?php if (!$riwayat_wa): ?>
                    <tr><td colspan="5"><div class="kosong"><?= $menyaring_riwayat ? 'Tidak ada riwayat yang cocok dengan pencarian.' : 'Belum ada riwayat pengiriman.'; ?></div></td></tr>
                <?php endif; ?>
                <?php foreach ($riwayat_wa as $w):
                    $ok = $w['status'] === 'berhasil';
                    // Hasil per saluran ['wa' => [...], 'telegram' => [...]] jika dikirim lewat WA & Telegram sekaligus
                    $per_saluran = array_column(!empty($w['detail']) ? (json_decode($w['detail'], true) ?: []) : [], null, 'saluran');
                ?>
                    <tr>
                        <td data-label="Waktu" class="mono"><?= tglPendek($w['waktu']); ?><br><span class="redup"><?= date('H.i', strtotime($w['waktu'])); ?></span></td>
                        <td data-label="Jenis"><?= e(ucfirst($w['jenis'])); ?></td>
                        <td data-label="Unit">
                            <?php foreach (daftarUnitRiwayat($w['ringkasan']) as $unit): ?>
                                <div><?= e($unit); ?></div>
                            <?php endforeach; ?>
                        </td>
                        <td data-label="Penerima">
                            <?php foreach (array_filter(array_map('trim', explode(',', $w['penerima'])), static fn($n) => $n !== '' && $n !== '-') as $no): ?>
                                <?php $sal = strncmp($no, 'tg:', 3) === 0 ? 'telegram' : 'wa'; ?>
                                <div class="penerima-riwayat">
                                    <?php if (isset($per_saluran[$sal])): $ok_sal = !empty($per_saluran[$sal]['ok']); ?>
                                        <span class="<?= $ok_sal ? 'teks-hijau' : 'teks-merah'; ?>" title="<?= $ok_sal ? 'Terkirim' : 'Gagal'; ?>"><?= $ok_sal ? '✓' : '✗'; ?></span>
                                    <?php endif; ?>
                                    <?php if ($sal === 'telegram'): $cid = substr($no, 3); ?>
                                        <?= e($nama_telegram[$cid] ?? 'Telegram'); ?> <span class="kode">Telegram <?= e($cid); ?></span>
                                    <?php elseif (isset($nama_penerima[$no])): ?>
                                        <?= e($nama_penerima[$no]); ?> <span class="kode">+<?= e($no); ?></span>
                                    <?php else: ?>
                                        <span class="kode">+<?= e($no); ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                            <?php if (trim($w['penerima']) === '-'): ?><span class="redup">&mdash;</span><?php endif; ?>
                        </td>
                        <td data-label="Status">
                            <?php if ($w['status'] === 'berhasil'): ?>
                                <div class="st hijau">Berhasil</div>
                            <?php elseif ($w['status'] === 'sebagian'): ?>
                                <div class="st amber">Sebagian gagal</div>
                            <?php else: ?>
                                <div class="st merah">Gagal</div>
                            <?php endif; ?>
                            <?php // Alasan gagal: per saluran (pengiriman WA & Telegram sekaligus) atau keterangan biasa ?>
                            <?php foreach ($per_saluran as $sal => $h): if (!empty($h['ok']) || empty($h['pesan'])) continue; ?>
                                <div class="st-sub teks-merah"><?= e(namaSaluran($sal) . ': ' . $h['pesan']); ?></div>
                            <?php endforeach; ?>
                            <?php if (!$per_saluran && !$ok && $w['keterangan']): ?><div class="st-sub teks-merah"><?= e($w['keterangan']); ?></div><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="panel-kaki">
            <span><?= $total_wa; ?> pengiriman</span>
            <?= tombolHalaman($hal_wa, $jumlah_hal_wa, 'hal_wa', 'riwayat'); ?>
        </div>
    </section>
<?php endif; ?>

    <div class="kaki">&copy; <?= date('Y'); ?> Aplikasi Pengingat Jadwal</div>
</main>

<?php if ($menu === 'penerima'): ?>
    <!-- Popup Ubah Nama Penerima (WA & Telegram). Isinya diisi dari tombol "Ubah nama" yang diklik. -->
    <div class="modal fade" id="modalNamaPenerima" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="dashboard.php">
                    <div class="modal-header">
                        <h5 class="modal-title">Ubah nama penerima</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                    </div>
                    <div class="modal-body">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="aksi" value="ubah_nama_penerima">
                        <input type="hidden" name="id" value="">
                        <div class="kode mb-2" data-info></div>
                        <label class="form-label">Nama</label>
                        <input type="text" name="nama" class="form-control" maxlength="100" required>
                        <div class="form-text">Nama ini tampil di daftar penerima dan di Riwayat Kirim.</div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-teks" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-gelap">Simpan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <script>
    // Isi popup Ubah Nama sesuai penerima (WA / Telegram) yang tombolnya diklik
    document.getElementById('modalNamaPenerima').addEventListener('show.bs.modal', (ev) => {
        const tombol = ev.relatedTarget;
        const form = ev.target.querySelector('form');
        if (!tombol) return;
        // form.elements[...] dipakai karena form.id berarti atribut id milik form, bukan input bernama "id"
        form.elements['aksi'].value = tombol.dataset.aksi;
        form.elements['id'].value = tombol.dataset.id;
        form.elements['nama'].value = tombol.dataset.nama;
        ev.target.querySelector('[data-info]').textContent = tombol.dataset.info;
    });
    document.getElementById('modalNamaPenerima').addEventListener('shown.bs.modal', (ev) => {
        const input = ev.target.querySelector('input[name=nama]');
        input.focus();
        input.select();
    });
    </script>
<?php endif; ?>

<?php if ($menu === 'unit'): ?>
    <!-- Popup Tambah Unit (lampiran diunggah per potongan oleh JavaScript di bawah) -->
    <div class="modal fade" id="modalTambah" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="dashboard.php" enctype="multipart/form-data" data-upload="unit-baru">
                    <div class="modal-header">
                        <h5 class="modal-title">Tambah unit</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                    </div>
                    <div class="modal-body">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="aksi" value="simpan">
                        <div class="mb-3">
                            <label class="form-label">Nama unit</label>
                            <input type="text" name="nama_unit" class="form-control" placeholder="contoh: BRKS Cabang Pekanbaru" maxlength="150" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Kode unit</label>
                            <input type="text" name="kode_unit" class="form-control" placeholder="contoh: 105BRKS2026" maxlength="50" required>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <label class="form-label">Tanggal awal</label>
                                <input type="date" name="tanggal_awal" class="form-control" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label">Deadline (tanggal akhir)</label>
                                <input type="date" name="tanggal_akhir" class="form-control" required>
                            </div>
                        </div>
                        <label class="form-label">Lampiran <span class="redup fw-normal">(opsional)</span></label>
                        <input type="file" name="lampiran[]" class="form-control" multiple accept="<?= e($accept_lampiran); ?>">
                        <div class="form-text"><?= e($info_lampiran); ?></div>
                        <div class="progress mt-2 d-none" style="height: 6px;" data-progres><div class="progress-bar" role="progressbar" style="width: 0%"></div></div>
                        <div class="small redup mt-1 d-none" data-progres-teks></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-teks" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-gelap">Simpan unit</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Popup Edit (diletakkan di luar tabel agar HTML valid) -->
    <?php foreach ($data_tampil as $row): ?>
    <div class="modal fade" id="modalEdit<?= (int) $row['id']; ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="dashboard.php">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit unit</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                    </div>
                    <div class="modal-body">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="aksi" value="update">
                        <input type="hidden" name="id" value="<?= (int) $row['id']; ?>">
                        <div class="mb-3">
                            <label class="form-label">Nama unit</label>
                            <input type="text" name="nama_unit" class="form-control" value="<?= e($row['nama_unit']); ?>" maxlength="150" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Kode unit</label>
                            <input type="text" name="kode_unit" class="form-control" value="<?= e($row['kode_unit']); ?>" maxlength="50" required>
                        </div>
                        <div class="row g-3">
                            <div class="col-6">
                                <label class="form-label">Tanggal awal</label>
                                <input type="date" name="tanggal_awal" class="form-control" value="<?= e($row['tanggal_awal']); ?>" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label">Deadline (tanggal akhir)</label>
                                <input type="date" name="tanggal_akhir" class="form-control" value="<?= e($row['tanggal_akhir']); ?>" required>
                            </div>
                        </div>
                        <div class="form-text mt-2">Mengubah deadline (memperpanjang) akan mengaktifkan lagi pengingat otomatis dan menghapus status selesai.</div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-teks" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-gelap">Simpan perubahan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- Popup Lampiran -->
    <?php foreach ($data_tampil as $row): $lampiran_unit = $lampiran_per_unit[(int) $row['id']] ?? []; ?>
    <div class="modal fade" id="modalLampiran<?= (int) $row['id']; ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Lampiran · <?= e($row['nama_unit']); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body">
                    <?php if (!$lampiran_unit): ?>
                        <p class="redup small my-2">Belum ada lampiran untuk unit ini.</p>
                    <?php endif; ?>
                    <?php foreach ($lampiran_unit as $l):
                        // Tombol "Lihat" untuk PDF, gambar, Word (.docx), Excel, CSV, TXT (lihat LAMPIRAN_BISA_DILIHAT)
                        $bisa_dilihat = in_array(strtolower($l['ekstensi']), LAMPIRAN_BISA_DILIHAT, true); ?>
                        <div class="baris-penerima">
                            <div class="min-w-0">
                                <div class="nama text-truncate" title="<?= e($l['nama_file']); ?>"><?= e($l['nama_file']); ?></div>
                                <div class="kode">
                                    <?= formatUkuran((int) $l['ukuran']); ?> · <?= tglPendek($l['dibuat_tanggal']) . ' ' . date('H.i', strtotime($l['dibuat_tanggal'])); ?>
                                    <?= $l['diunggah_oleh'] ? '· ' . e($l['diunggah_oleh']) : ''; ?>
                                </div>
                            </div>
                            <div class="aksi">
                                <?php if ($bisa_dilihat): ?>
                                    <a href="lampiran.php?id=<?= (int) $l['id']; ?>&lihat=1" target="_blank" rel="noopener" class="btn btn-teks btn-kecil">Lihat</a>
                                <?php endif; ?>
                                <a href="lampiran.php?id=<?= (int) $l['id']; ?>" target="_blank" rel="noopener" class="btn btn-teks btn-kecil">Unduh</a>
                                <form method="POST" action="dashboard.php" onsubmit="return confirm('Hapus lampiran ini?')">
                                    <?= csrf_field(); ?>
                                    <input type="hidden" name="aksi" value="hapus_lampiran">
                                    <input type="hidden" name="id" value="<?= (int) $l['id']; ?>">
                                    <button type="submit" class="btn btn-teks btn-kecil teks-merah">Hapus</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <form method="POST" action="dashboard.php" enctype="multipart/form-data" class="form-tambah" data-upload="unit">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="aksi" value="upload_lampiran">
                        <input type="hidden" name="deadline_id" value="<?= (int) $row['id']; ?>">
                        <label class="form-label">Tambah lampiran</label>
                        <input type="file" name="lampiran[]" class="form-control mb-1" multiple required accept="<?= e($accept_lampiran); ?>">
                        <div class="form-text mb-2"><?= e($info_lampiran); ?></div>
                        <div class="progress mb-2 d-none" style="height: 6px;" data-progres><div class="progress-bar" role="progressbar" style="width: 0%"></div></div>
                        <div class="small redup mb-2 d-none" data-progres-teks></div>
                        <button type="submit" class="btn btn-gelap w-100">Unggah</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
<?php endif; ?>

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

        // Alamat halaman ini (filter & halaman tabel tetap) + parameter baru, tanpa pesan / popup lama
        function alamatKembali(tambah) {
            const u = new URL(window.location.href);
            ['status', 'lampiran'].forEach((k) => u.searchParams.delete(k));
            Object.entries(tambah).forEach(([k, v]) => u.searchParams.set(k, v));
            u.hash = '';
            return u.toString();
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
                        tujuan = alamatKembali({ status: 'success_add' });
                    } else {
                        deadlineId = form.querySelector('[name=deadline_id]').value;
                        tujuan = alamatKembali({ lampiran: deadlineId });
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
