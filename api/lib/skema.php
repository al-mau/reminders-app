<?php
/**
 * Tabel tambahan (lampiran dokumen & penerima WA) + helper lampiran.
 * Tabel dibuat otomatis saat pertama kali dibutuhkan, tidak perlu setup manual.
 */
require_once __DIR__ . '/koneksi.php';

const LAMPIRAN_MAKS_BYTE = 2 * 1024 * 1024; // 2 MB per file (batas upload PHP & Vercel)

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

function pastikanTabelTambahan(PDO $pdo): void
{
    static $sudah = false;
    if ($sudah || !empty($_SESSION['skema_tambahan_v2'])) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS wa_penerima (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        nama           VARCHAR(100) NOT NULL,
        nomor          VARCHAR(20)  NOT NULL UNIQUE,
        aktif          TINYINT(1)   NOT NULL DEFAULT 1,
        dibuat_tanggal DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS lampiran (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        deadline_id    INT          NOT NULL,
        nama_file      VARCHAR(255) NOT NULL,
        ekstensi       VARCHAR(10)  NOT NULL,
        ukuran         INT UNSIGNED NOT NULL,
        isi            MEDIUMBLOB   NOT NULL,
        diunggah_oleh  VARCHAR(50)  NULL,
        dibuat_tanggal DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_deadline_id (deadline_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Riwayat pengiriman WA (otomatis & manual)
    $pdo->exec("CREATE TABLE IF NOT EXISTS wa_log (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        waktu      DATETIME     NOT NULL,
        jenis      VARCHAR(10)  NOT NULL,
        ringkasan  VARCHAR(500) NOT NULL,
        penerima   VARCHAR(500) NOT NULL,
        status     VARCHAR(10)  NOT NULL,
        keterangan VARCHAR(255) NULL,
        INDEX idx_waktu (waktu)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Riwayat aktivitas user (siapa mengubah apa)
    $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (
        id       INT AUTO_INCREMENT PRIMARY KEY,
        waktu    DATETIME     NOT NULL,
        username VARCHAR(50)  NULL,
        aksi     VARCHAR(50)  NOT NULL,
        detail   VARCHAR(500) NULL,
        ip       VARCHAR(45)  NULL,
        INDEX idx_waktu (waktu)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

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
        $_SESSION['skema_tambahan_v2'] = true;
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

function formatUkuran(int $byte): string
{
    if ($byte >= 1048576) {
        return number_format($byte / 1048576, 1, ',', '.') . ' MB';
    }
    return max(1, (int) round($byte / 1024)) . ' KB';
}

/**
 * Simpan file upload sebagai lampiran sebuah unit.
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
// RIWAYAT AKTIVITAS (AUDIT LOG)
// ===================================================================

/** Alamat IP pengunjung (Vercel meneruskan IP asli lewat X-Forwarded-For) */
function ipKlien(): string
{
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    $ip = trim(explode(',', $ip)[0]);
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function potong(string $teks, int $maks): string
{
    return function_exists('mb_substr') ? mb_substr($teks, 0, $maks) : substr($teks, 0, $maks);
}

/** Catat aktivitas user. Kegagalan mencatat tidak boleh menggagalkan aksi utama. */
function catatAudit(PDO $pdo, string $aksi, string $detail = '', ?string $username = null): void
{
    try {
        pastikanTabelTambahan($pdo);
        $pdo->prepare("INSERT INTO audit_log (waktu, username, aksi, detail, ip) VALUES (?, ?, ?, ?, ?)")
            ->execute([
                date('Y-m-d H:i:s'),
                $username ?? ($_SESSION['username'] ?? null),
                potong($aksi, 50),
                potong($detail, 500),
                ipKlien(),
            ]);
    } catch (PDOException $e) {
        error_log('Gagal mencatat audit: ' . $e->getMessage());
    }
}

/** Catat pengiriman WA (berhasil maupun gagal) */
function catatWa(PDO $pdo, string $jenis, string $ringkasan, string $penerima, bool $ok, string $keterangan): void
{
    try {
        pastikanTabelTambahan($pdo);
        $pdo->prepare("INSERT INTO wa_log (waktu, jenis, ringkasan, penerima, status, keterangan) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([
                date('Y-m-d H:i:s'),
                $jenis,
                potong($ringkasan, 500),
                potong($penerima, 500),
                $ok ? 'berhasil' : 'gagal',
                potong($keterangan, 255),
            ]);
    } catch (PDOException $e) {
        error_log('Gagal mencatat wa_log: ' . $e->getMessage());
    }
}

// ===================================================================
// PEMBATASAN PERCOBAAN LOGIN
// ===================================================================
const LOGIN_MAKS_GAGAL     = 5;   // per username + IP
const LOGIN_MAKS_GAGAL_IP  = 20;  // per IP (mencegah tebak banyak username)
const LOGIN_KUNCI_MENIT    = 15;

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

function catatLoginGagal(PDO $pdo, string $username, string $ip): void
{
    $pdo->prepare("INSERT INTO login_gagal (username, ip, waktu) VALUES (?, ?, ?)")
        ->execute([potong(strtolower($username), 50), $ip, date('Y-m-d H:i:s')]);

    // Bersihkan catatan lama sesekali agar tabel tetap kecil
    if (random_int(1, 20) === 1) {
        $pdo->exec("DELETE FROM login_gagal WHERE waktu < '" . date('Y-m-d H:i:s', time() - 86400) . "'");
    }
}

function hapusLoginGagal(PDO $pdo, string $username, string $ip): void
{
    $pdo->prepare("DELETE FROM login_gagal WHERE username = ? AND ip = ?")->execute([strtolower($username), $ip]);
}
