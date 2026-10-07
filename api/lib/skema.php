<?php
/**
 * Struktur tabel tambahan + fungsi bantu fitur-fitur aplikasi.
 * Tabel dibuat otomatis saat pertama kali dibutuhkan, tidak perlu setup manual.
 *
 * Isi file ini:
 *  - pastikanTabelTambahan() -> membuat tabel wa_penerima, lampiran, lampiran_bagian,
 *                               wa_log, login_gagal (jika belum ada)
 *  - LAMPIRAN ...            -> simpan / hapus dokumen per unit (upload per potongan)
 *  - RIWAYAT PENGIRIMAN      -> catatPengiriman() mencatat setiap pengiriman WhatsApp/Telegram
 *  - PEMBATASAN LOGIN        -> kunci login 15 menit setelah salah password berkali-kali
 */
require_once __DIR__ . '/koneksi.php';

// Batas ukuran 1 file lampiran. Ubah angka 5 bila ingin batas lain (perhatikan kuota database).
const LAMPIRAN_MAKS_BYTE   = 5 * 1024 * 1024; // 5 MB per file
// Vercel menolak request/response > 4,5 MB, jadi file dikirim & disimpan per potongan kecil.
// 768 KB juga aman untuk max_allowed_packet MySQL bawaan XAMPP (1 MB).
const LAMPIRAN_BAGIAN_BYTE = 768 * 1024;

// Ekstensi yang bisa dilihat langsung (tombol "Lihat") tanpa diunduh.
// .doc (Word lama) tidak bisa dipratinjau di browser, jadi hanya bisa diunduh.
const LAMPIRAN_BISA_DILIHAT = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'docx', 'xls', 'xlsx', 'csv', 'txt'];

/** Ekstensi yang diizinkan => MIME type yang dikirim saat diunduh */
const LAMPIRAN_TIPE = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls'  => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'csv'  => 'text/csv',
    'txt'  => 'text/plain',
];

/**
 * Buat tabel tambahan jika belum ada (aman dipanggil berulang kali).
 * Agar tidak memperlambat, pengecekan hanya dilakukan sekali per sesi login
 * (penanda disimpan di $_SESSION) dan sekali per request (variabel static).
 */
function pastikanTabelTambahan(PDO $pdo): void
{
    static $sudah = false;
    if ($sudah || !empty($_SESSION['skema_tambahan_v6'])) {
        return;
    }

    // Daftar nomor WhatsApp penerima pengingat (diatur dari dashboard)
    $pdo->exec("CREATE TABLE IF NOT EXISTS wa_penerima (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        nama           VARCHAR(100) NOT NULL,
        nomor          VARCHAR(20)  NOT NULL UNIQUE,
        aktif          TINYINT(1)   NOT NULL DEFAULT 1,
        dibuat_tanggal DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Daftar chat Telegram penerima pengingat (diatur dari dashboard)
    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_penerima (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        nama           VARCHAR(100) NOT NULL,
        chat_id        VARCHAR(25)  NOT NULL UNIQUE,
        aktif          TINYINT(1)   NOT NULL DEFAULT 1,
        dibuat_tanggal DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Data lampiran per unit (nama file, ukuran, dll). Isi file ada di lampiran_bagian.
    // Kolom isi hanya dipakai file lama (sebelum upload per potongan).
    // selesai = 0 berarti upload sedang berjalan / terputus.
    $pdo->exec("CREATE TABLE IF NOT EXISTS lampiran (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        deadline_id    INT          NOT NULL,
        nama_file      VARCHAR(255) NOT NULL,
        ekstensi       VARCHAR(10)  NOT NULL,
        ukuran         INT UNSIGNED NOT NULL,
        isi            MEDIUMBLOB   NOT NULL,
        jumlah_bagian  INT          NOT NULL DEFAULT 0,
        selesai        TINYINT(1)   NOT NULL DEFAULT 1,
        diunggah_oleh  VARCHAR(50)  NULL,
        dibuat_tanggal DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_deadline_id (deadline_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Database lama: tambah kolom untuk lampiran berpotongan
    $kolomLampiran = $pdo->query("SHOW COLUMNS FROM lampiran")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('jumlah_bagian', $kolomLampiran, true)) {
        $pdo->exec("ALTER TABLE lampiran ADD COLUMN jumlah_bagian INT NOT NULL DEFAULT 0");
    }
    if (!in_array('selesai', $kolomLampiran, true)) {
        $pdo->exec("ALTER TABLE lampiran ADD COLUMN selesai TINYINT(1) NOT NULL DEFAULT 1");
    }

    // Potongan isi file (lampiran > 768 KB disimpan beberapa baris)
    $pdo->exec("CREATE TABLE IF NOT EXISTS lampiran_bagian (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        lampiran_id INT        NOT NULL,
        urutan      INT        NOT NULL,
        isi         MEDIUMBLOB NOT NULL,
        UNIQUE KEY uk_lampiran_urutan (lampiran_id, urutan)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Riwayat pengiriman WA/Telegram (otomatis & manual).
    // Satu pengiriman = satu baris; detail = hasil per saluran (JSON) bila lewat WA & Telegram sekaligus.
    // status: berhasil (semua saluran berhasil), sebagian (ada yang gagal), gagal (semua gagal)
    $pdo->exec("CREATE TABLE IF NOT EXISTS wa_log (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        waktu      DATETIME     NOT NULL,
        jenis      VARCHAR(10)  NOT NULL,
        ringkasan  VARCHAR(500) NOT NULL,
        penerima   VARCHAR(500) NOT NULL,
        status     VARCHAR(10)  NOT NULL,
        keterangan VARCHAR(255) NULL,
        detail     VARCHAR(1000) NULL,
        INDEX idx_waktu (waktu)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Database lama: tambah kolom detail, lalu satukan riwayat lama yang terpisah WA & Telegram
    $kolomLog = $pdo->query("SHOW COLUMNS FROM wa_log")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('detail', $kolomLog, true)) {
        $pdo->exec("ALTER TABLE wa_log ADD COLUMN detail VARCHAR(1000) NULL AFTER keterangan");
        gabungRiwayatLama($pdo);
    }

    // Percobaan login gagal (pembatasan brute force)
    $pdo->exec("CREATE TABLE IF NOT EXISTS login_gagal (
        id       INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL,
        ip       VARCHAR(45) NOT NULL,
        waktu    DATETIME    NOT NULL,
        INDEX idx_ip_waktu (ip, waktu),
        INDEX idx_user_waktu (username, waktu)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $sudah = true;
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['skema_tambahan_v6'] = true;
    }
}

/** Ubah struktur $_FILES['x'] (single / multiple) menjadi daftar file */
function daftarFileUpload(string $field): array
{
    if (empty($_FILES[$field]) || !isset($_FILES[$field]['name'])) {
        return [];
    }
    $f = $_FILES[$field];
    if (!is_array($f['name'])) {
        return $f['error'] === UPLOAD_ERR_NO_FILE ? [] : [$f];
    }

    $hasil = [];
    foreach ($f['name'] as $i => $nama) {
        if ($f['error'][$i] === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $hasil[] = [
            'name'     => $nama,
            'tmp_name' => $f['tmp_name'][$i],
            'size'     => $f['size'][$i],
            'error'    => $f['error'][$i],
        ];
    }
    return $hasil;
}

/** Ubah ukuran byte menjadi teks mudah dibaca, contoh 1572864 -> "1,5 MB" */
function formatUkuran(int $byte): string
{
    if ($byte >= 1048576) {
        return number_format($byte / 1048576, 1, ',', '.') . ' MB';
    }
    return max(1, (int) round($byte / 1024)) . ' KB';
}

/**
 * Simpan file upload biasa (form tanpa JavaScript) sebagai lampiran sebuah unit.
 * File disimpan utuh dalam satu baris; jalur utama sekarang memakai upload per potongan di bawah.
 * @return array{berhasil: int, gagal: string[]}
 */
function simpanLampiran(PDO $pdo, int $deadlineId, array $files, ?string $pengunggah): array
{
    $berhasil = 0;
    $gagal    = [];

    // Native prepare untuk data biner (isi file) agar terkirim apa adanya
    $stmt = $pdo->prepare(
        "INSERT INTO lampiran (deadline_id, nama_file, ekstensi, ukuran, isi, diunggah_oleh, dibuat_tanggal) VALUES (?, ?, ?, ?, ?, ?, ?)",
        [PDO::ATTR_EMULATE_PREPARES => false]
    );

    foreach ($files as $file) {
        // Nama file dibersihkan dari path & karakter kontrol
        $nama = trim(preg_replace('/[\x00-\x1F\x7F\/\\\\]/u', '', basename((string) $file['name'])));
        $nama = $nama !== '' ? $nama : 'lampiran';
        $nama = function_exists('mb_substr') ? mb_substr($nama, 0, 200) : substr($nama, 0, 200);
        $ext  = strtolower(pathinfo($nama, PATHINFO_EXTENSION));

        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE || $file['size'] > LAMPIRAN_MAKS_BYTE) {
            $gagal[] = "$nama: melebihi batas " . formatUkuran(LAMPIRAN_MAKS_BYTE);
            continue;
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            $gagal[] = "$nama: gagal diunggah (kode {$file['error']})";
            continue;
        }
        if (!isset(LAMPIRAN_TIPE[$ext])) {
            $gagal[] = "$nama: tipe file tidak diizinkan";
            continue;
        }

        $isi = file_get_contents($file['tmp_name']);
        if ($isi === false || $isi === '') {
            $gagal[] = "$nama: file kosong atau tidak terbaca";
            continue;
        }

        $stmt->bindValue(1, $deadlineId, PDO::PARAM_INT);
        $stmt->bindValue(2, $nama);
        $stmt->bindValue(3, $ext);
        $stmt->bindValue(4, strlen($isi), PDO::PARAM_INT);
        $stmt->bindValue(5, $isi, PDO::PARAM_LOB);
        $stmt->bindValue(6, $pengunggah);
        $stmt->bindValue(7, date('Y-m-d H:i:s'));
        $stmt->execute();
        $berhasil++;
    }

    return ['berhasil' => $berhasil, 'gagal' => $gagal];
}

// ===================================================================
// LAMPIRAN BERPOTONGAN (upload/download per potongan dari browser)
// Alur upload dari dashboard:
//   1. mulaiLampiran()         -> daftarkan file, dapat id (selesai = 0)
//   2. simpanBagianLampiran()  -> kirim potongan 768 KB satu per satu
//   3. selesaikanLampiran()    -> cek semua potongan lengkap, tandai selesai = 1
// ===================================================================

/** Bersihkan nama file (buang path & karakter aneh) dan cek ekstensinya diizinkan. @return array{0: string, 1: string} [nama, ekstensi] */
function validasiNamaLampiran(string $nama): array
{
    $nama = trim(preg_replace('/[\x00-\x1F\x7F\/\\\\]/u', '', basename($nama)));
    $nama = $nama !== '' ? $nama : 'lampiran';
    $nama = function_exists('mb_substr') ? mb_substr($nama, 0, 200) : substr($nama, 0, 200);
    $ext  = strtolower(pathinfo($nama, PATHINFO_EXTENSION));
    if (!isset(LAMPIRAN_TIPE[$ext])) {
        throw new InvalidArgumentException("$nama: tipe file tidak diizinkan");
    }
    return [$nama, $ext];
}

/** Daftarkan lampiran baru (belum selesai) dan kembalikan id-nya */
function mulaiLampiran(PDO $pdo, int $deadlineId, string $nama, int $ukuran, ?string $pengunggah): int
{
    [$nama, $ext] = validasiNamaLampiran($nama);
    if ($ukuran <= 0) {
        throw new InvalidArgumentException("$nama: file kosong");
    }
    if ($ukuran > LAMPIRAN_MAKS_BYTE) {
        throw new InvalidArgumentException("$nama: melebihi batas " . formatUkuran(LAMPIRAN_MAKS_BYTE));
    }

    $cek = $pdo->prepare("SELECT 1 FROM deadline WHERE id = ?");
    $cek->execute([$deadlineId]);
    if (!$cek->fetchColumn()) {
        throw new InvalidArgumentException('Unit tidak ditemukan');
    }

    // Upload yang tidak pernah selesai (> 1 hari) dibersihkan sesekali
    if (random_int(1, 10) === 1) {
        bersihkanLampiranGantung($pdo);
    }

    $pdo->prepare(
        "INSERT INTO lampiran (deadline_id, nama_file, ekstensi, ukuran, isi, jumlah_bagian, selesai, diunggah_oleh, dibuat_tanggal)
         VALUES (?, ?, ?, ?, '', ?, 0, ?, ?)"
    )->execute([$deadlineId, $nama, $ext, $ukuran, (int) ceil($ukuran / LAMPIRAN_BAGIAN_BYTE), $pengunggah, date('Y-m-d H:i:s')]);

    return (int) $pdo->lastInsertId();
}

/** Simpan satu potongan file dari upload browser */
function simpanBagianLampiran(PDO $pdo, int $lampiranId, int $urutan, array $file): void
{
    $stmt = $pdo->prepare("SELECT jumlah_bagian, selesai FROM lampiran WHERE id = ?");
    $stmt->execute([$lampiranId]);
    $lamp = $stmt->fetch();

    if (!$lamp || (int) $lamp['selesai'] === 1) {
        throw new InvalidArgumentException('Upload tidak ditemukan atau sudah selesai');
    }
    if ($urutan < 0 || $urutan >= (int) $lamp['jumlah_bagian']) {
        throw new InvalidArgumentException('Urutan potongan tidak valid');
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])
        || $file['size'] > LAMPIRAN_BAGIAN_BYTE) {
        throw new InvalidArgumentException('Potongan file tidak valid');
    }

    $isi = file_get_contents($file['tmp_name']);
    $simpan = $pdo->prepare(
        "REPLACE INTO lampiran_bagian (lampiran_id, urutan, isi) VALUES (?, ?, ?)",
        [PDO::ATTR_EMULATE_PREPARES => false] // data biner dikirim apa adanya
    );
    $simpan->bindValue(1, $lampiranId, PDO::PARAM_INT);
    $simpan->bindValue(2, $urutan, PDO::PARAM_INT);
    $simpan->bindValue(3, $isi, PDO::PARAM_LOB);
    $simpan->execute();
}

/** Tandai lampiran selesai jika semua potongan lengkap & ukurannya cocok */
function selesaikanLampiran(PDO $pdo, int $lampiranId): array
{
    $stmt = $pdo->prepare("SELECT * FROM lampiran WHERE id = ? AND selesai = 0");
    $stmt->execute([$lampiranId]);
    $lamp = $stmt->fetch();
    if (!$lamp) {
        throw new InvalidArgumentException('Upload tidak ditemukan atau sudah selesai');
    }

    $cek = $pdo->prepare("SELECT COUNT(*) AS jumlah, COALESCE(SUM(LENGTH(isi)), 0) AS total FROM lampiran_bagian WHERE lampiran_id = ?");
    $cek->execute([$lampiranId]);
    $hasil = $cek->fetch();

    if ((int) $hasil['jumlah'] !== (int) $lamp['jumlah_bagian'] || (int) $hasil['total'] !== (int) $lamp['ukuran']) {
        hapusLampiranLengkap($pdo, $lampiranId);
        throw new InvalidArgumentException($lamp['nama_file'] . ': file tidak lengkap terkirim, silakan unggah ulang');
    }

    $pdo->prepare("UPDATE lampiran SET selesai = 1 WHERE id = ?")->execute([$lampiranId]);
    return $lamp;
}

/** Hapus lampiran beserta semua potongannya */
function hapusLampiranLengkap(PDO $pdo, int $lampiranId): void
{
    $pdo->prepare("DELETE FROM lampiran_bagian WHERE lampiran_id = ?")->execute([$lampiranId]);
    $pdo->prepare("DELETE FROM lampiran WHERE id = ?")->execute([$lampiranId]);
}

/** Hapus semua lampiran milik satu unit */
function hapusLampiranUnit(PDO $pdo, int $deadlineId): void
{
    $pdo->prepare("DELETE FROM lampiran_bagian WHERE lampiran_id IN (SELECT id FROM lampiran WHERE deadline_id = ?)")
        ->execute([$deadlineId]);
    $pdo->prepare("DELETE FROM lampiran WHERE deadline_id = ?")->execute([$deadlineId]);
}

/** Hapus upload yang terputus / tidak selesai lebih dari 1 hari (agar database tidak penuh sampah) */
function bersihkanLampiranGantung(PDO $pdo): void
{
    $batas = date('Y-m-d H:i:s', time() - 86400);
    $pdo->prepare("DELETE FROM lampiran_bagian WHERE lampiran_id IN (SELECT id FROM lampiran WHERE selesai = 0 AND dibuat_tanggal < ?)")
        ->execute([$batas]);
    $pdo->prepare("DELETE FROM lampiran WHERE selesai = 0 AND dibuat_tanggal < ?")->execute([$batas]);
}

// ===================================================================
// RIWAYAT PENGIRIMAN WA
// ===================================================================

/** Alamat IP pengunjung (Vercel meneruskan IP asli lewat X-Forwarded-For) */
function ipKlien(): string
{
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    $ip = trim(explode(',', $ip)[0]);
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

/** Potong teks agar muat di kolom database (aman untuk huruf non-latin / emoji) */
function potong(string $teks, int $maks): string
{
    return function_exists('mb_substr') ? mb_substr($teks, 0, $maks) : substr($teks, 0, $maks);
}

/** Nama saluran untuk ditampilkan: wa -> WhatsApp, telegram -> Telegram */
function namaSaluran(string $saluran): string
{
    return $saluran === 'telegram' ? 'Telegram' : 'WhatsApp';
}

/**
 * Catat satu pengiriman (berhasil maupun gagal) ke tabel wa_log -> kartu "Riwayat Pengiriman".
 * Pengiriman lewat WhatsApp & Telegram sekaligus dicatat sebagai SATU baris:
 * penerima digabung, hasil tiap saluran disimpan di kolom detail.
 * Jika pencatatan gagal, pengiriman tetap dianggap jalan (error hanya masuk log).
 *
 * @param array $hasil daftar ['saluran' => 'wa'|'telegram', 'penerima' => '628..,tg:-100..', 'ok' => bool, 'pesan' => string]
 */
function catatPengiriman(PDO $pdo, string $jenis, string $ringkasan, array $hasil): void
{
    if (!$hasil) {
        return;
    }
    $jumlahOk = count(array_filter($hasil, static fn($h) => $h['ok']));
    $status   = $jumlahOk === count($hasil) ? 'berhasil' : ($jumlahOk === 0 ? 'gagal' : 'sebagian');

    $penerima = array_filter(array_column($hasil, 'penerima'), static fn($p) => $p !== '' && $p !== '-');
    $keterangan = count($hasil) === 1
        ? $hasil[0]['pesan']
        : implode('; ', array_map(static fn($h) => namaSaluran($h['saluran']) . ': ' . $h['pesan'], $hasil));
    $detail = count($hasil) === 1 ? null : json_encode(array_map(static fn($h) => [
        'saluran' => $h['saluran'],
        'ok'      => (bool) $h['ok'],
        'pesan'   => potong((string) $h['pesan'], 200),
    ], array_values($hasil)), JSON_UNESCAPED_UNICODE);

    try {
        pastikanTabelTambahan($pdo);
        $pdo->prepare("INSERT INTO wa_log (waktu, jenis, ringkasan, penerima, status, keterangan, detail) VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([
                date('Y-m-d H:i:s'),
                $jenis,
                potong($ringkasan, 500),
                potong($penerima ? implode(',', $penerima) : '-', 500),
                $status,
                potong($keterangan, 255),
                $detail,
            ]);
    } catch (PDOException $e) {
        error_log('Gagal mencatat wa_log: ' . $e->getMessage());
    }
}

/**
 * Sekali jalan (saat kolom detail baru ditambahkan): satukan riwayat lama yang tercatat 2 baris,
 * yaitu baris WhatsApp lalu baris Telegram (id berurutan) dengan jenis & unit yang sama.
 */
function gabungRiwayatLama(PDO $pdo): void
{
    $pasangan = $pdo->query(
        "SELECT w.id AS id_wa, t.id AS id_tg,
                w.penerima AS penerima_wa, t.penerima AS penerima_tg,
                w.status AS status_wa, t.status AS status_tg,
                w.keterangan AS ket_wa, t.keterangan AS ket_tg
         FROM wa_log w
         JOIN wa_log t ON t.id = w.id + 1
         WHERE t.penerima LIKE 'tg:%' AND w.penerima NOT LIKE 'tg:%'
           AND t.jenis = w.jenis AND t.ringkasan = w.ringkasan
           AND ABS(TIMESTAMPDIFF(SECOND, w.waktu, t.waktu)) <= 120"
    )->fetchAll();

    $ubah  = $pdo->prepare("UPDATE wa_log SET penerima = ?, status = ?, keterangan = ?, detail = ? WHERE id = ?");
    $hapus = $pdo->prepare("DELETE FROM wa_log WHERE id = ?");
    foreach ($pasangan as $p) {
        $hasil = [
            ['saluran' => 'wa', 'ok' => $p['status_wa'] === 'berhasil', 'pesan' => (string) $p['ket_wa']],
            ['saluran' => 'telegram', 'ok' => $p['status_tg'] === 'berhasil', 'pesan' => (string) $p['ket_tg']],
        ];
        $jumlahOk = count(array_filter($hasil, static fn($h) => $h['ok']));
        $penerima = array_filter([$p['penerima_wa'], $p['penerima_tg']], static fn($x) => $x !== '' && $x !== '-');
        $ubah->execute([
            potong(implode(',', $penerima) ?: '-', 500),
            $jumlahOk === 2 ? 'berhasil' : ($jumlahOk === 0 ? 'gagal' : 'sebagian'),
            potong('WhatsApp: ' . $p['ket_wa'] . '; Telegram: ' . $p['ket_tg'], 255),
            json_encode(array_map(static fn($h) => array_merge($h, ['pesan' => potong($h['pesan'], 200)]), $hasil), JSON_UNESCAPED_UNICODE),
            $p['id_wa'],
        ]);
        $hapus->execute([$p['id_tg']]);
    }
}

// ===================================================================
// PEMBATASAN PERCOBAAN LOGIN
// ===================================================================
const LOGIN_MAKS_GAGAL     = 5;   // per username + IP
const LOGIN_MAKS_GAGAL_IP  = 20;  // per IP (mencegah tebak banyak username)
const LOGIN_KUNCI_MENIT    = 15;  // lama penguncian setelah batas di atas tercapai

/** Sisa detik penguncian login; 0 jika boleh mencoba */
function sisaKunciLogin(PDO $pdo, string $username, string $ip): int
{
    pastikanTabelTambahan($pdo);
    $batas = date('Y-m-d H:i:s', time() - LOGIN_KUNCI_MENIT * 60);

    $cek = static function (string $sql, array $param, int $maks) use ($pdo): int {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($param);
        $row = $stmt->fetch();
        if ((int) $row['jumlah'] < $maks) {
            return 0;
        }
        // Terkunci sampai percobaan ke-N terlama "kedaluwarsa"
        return max(0, strtotime($row['terakhir']) + LOGIN_KUNCI_MENIT * 60 - time());
    };

    return max(
        $cek("SELECT COUNT(*) AS jumlah, MAX(waktu) AS terakhir FROM login_gagal WHERE username = ? AND ip = ? AND waktu > ?",
            [strtolower($username), $ip, $batas], LOGIN_MAKS_GAGAL),
        $cek("SELECT COUNT(*) AS jumlah, MAX(waktu) AS terakhir FROM login_gagal WHERE ip = ? AND waktu > ?",
            [$ip, $batas], LOGIN_MAKS_GAGAL_IP)
    );
}

/** Catat 1x percobaan login gagal (password salah / username tidak ada) */
function catatLoginGagal(PDO $pdo, string $username, string $ip): void
{
    $pdo->prepare("INSERT INTO login_gagal (username, ip, waktu) VALUES (?, ?, ?)")
        ->execute([potong(strtolower($username), 50), $ip, date('Y-m-d H:i:s')]);

    // Bersihkan catatan lama sesekali agar tabel tetap kecil
    if (random_int(1, 20) === 1) {
        $pdo->exec("DELETE FROM login_gagal WHERE waktu < '" . date('Y-m-d H:i:s', time() - 86400) . "'");
    }
}

/** Login berhasil -> hapus catatan gagal sebelumnya agar hitungan mulai dari nol */
function hapusLoginGagal(PDO $pdo, string $username, string $ip): void
{
    $pdo->prepare("DELETE FROM login_gagal WHERE username = ? AND ip = ?")->execute([strtolower($username), $ip]);
}
