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
    if ($sudah || !empty($_SESSION['skema_tambahan_v1'])) {
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

    $sudah = true;
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['skema_tambahan_v1'] = true;
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

    $stmt = $pdo->prepare(
        "INSERT INTO lampiran (deadline_id, nama_file, ekstensi, ukuran, isi, diunggah_oleh) VALUES (?, ?, ?, ?, ?, ?)"
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
        $stmt->execute();
        $berhasil++;
    }

    return ['berhasil' => $berhasil, 'gagal' => $gagal];
}
