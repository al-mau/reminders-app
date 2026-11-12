<?php
/**
 * SALINAN LAMPIRAN KE ONEDRIVE (Microsoft Graph)
 *
 * Setiap lampiran yang selesai diunggah ke aplikasi otomatis disalin ke OneDrive:
 *   <ONEDRIVE_FOLDER>/<kode unit> - <nama unit>/<nama file>
 * Lampiran tetap tersimpan di database aplikasi; OneDrive hanya salinan arsip.
 * Menghapus lampiran di aplikasi TIDAK menghapus salinannya di OneDrive.
 *
 * Cara menghubungkan (sekali saja, oleh Akses Penuh di menu Penerima → kartu OneDrive):
 *   1. Daftarkan aplikasi di portal Microsoft Entra (Azure) → salin Application (client) ID
 *      ke Environment Variable ONEDRIVE_CLIENT_ID, aktifkan "Allow public client flows".
 *   2. Klik "Hubungkan OneDrive" → buka microsoft.com/devicelogin → masukkan kode → login.
 *   Aplikasi tidak pernah menyimpan password Microsoft; yang disimpan hanya token akses
 *   (refresh token) dalam keadaan terenkripsi di tabel pengaturan.
 *
 * Environment Variables:
 *   ONEDRIVE_CLIENT_ID  Application (client) ID dari Microsoft Entra (wajib)
 *   ONEDRIVE_TENANT     common (bawaan: akun pribadi & kantor), organizations, consumers, atau ID tenant kantor
 *   ONEDRIVE_FOLDER     folder tujuan, bawaan "Aplikasi Pengingat Deadline/Lampiran"
 *
 * Bila upload gagal (jaringan, token kedaluwarsa), lampiran ditandai "gagal" dan dicoba lagi
 * otomatis oleh cron pengingat setiap pagi (maks. ONEDRIVE_MAKS_COBA kali).
 */

require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/skema.php';

const ONEDRIVE_SCOPE      = 'offline_access Files.ReadWrite User.Read';
const ONEDRIVE_MAKS_COBA  = 5;
const ONEDRIVE_BATAS_SATU = 4 * 1024 * 1024;   // di atas ini pakai upload session (aturan Microsoft Graph)
const ONEDRIVE_POTONGAN   = 320 * 1024 * 10;   // potongan upload session harus kelipatan 320 KiB

/** Sudah ada ONEDRIVE_CLIENT_ID? (fitur bisa diaktifkan) */
function onedriveDiatur(): bool
{
    return trim((string) env('ONEDRIVE_CLIENT_ID', '')) !== '';
}

/** Sudah terhubung ke akun Microsoft (ada refresh token)? */
function onedriveSiap(PDO $pdo): bool
{
    return onedriveDiatur() && ambilPengaturan($pdo, 'onedrive_refresh') !== null;
}

function onedriveFolder(): string
{
    $folder = trim((string) env('ONEDRIVE_FOLDER', 'Aplikasi Pengingat Deadline/Lampiran'), " /");
    return $folder !== '' ? $folder : 'Aplikasi Pengingat Deadline/Lampiran';
}

// -------------------------------------------------------------------
// Penyimpanan pengaturan (token disimpan terenkripsi)
// -------------------------------------------------------------------

/** Kunci enkripsi dari APP_KEY / CRON_SECRET (rahasia server, tidak ada di database) */
function kunciRahasia(): string
{
    $rahasia = (string) (env('APP_KEY') ?: env('CRON_SECRET', ''));
    if ($rahasia === '') {
        throw new RuntimeException('Isi APP_KEY atau CRON_SECRET di Environment Variables agar token OneDrive bisa disimpan terenkripsi.');
    }
    return hash('sha256', 'pengingat-onedrive|' . $rahasia, true);
}

function sandikan(string $teks): string
{
    $iv  = random_bytes(12);
    $tag = '';
    $isi = openssl_encrypt($teks, 'aes-256-gcm', kunciRahasia(), OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . $isi);
}

function bukaSandi(string $data): ?string
{
    $raw = base64_decode($data, true);
    if ($raw === false || strlen($raw) < 29) {
        return null;
    }
    $teks = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', kunciRahasia(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $teks === false ? null : $teks;
}

function ambilPengaturan(PDO $pdo, string $kunci, bool $rahasia = false): ?string
{
    $stmt = $pdo->prepare("SELECT nilai FROM pengaturan WHERE kunci = ?");
    $stmt->execute([$kunci]);
    $nilai = $stmt->fetchColumn();
    if ($nilai === false || $nilai === null) {
        return null;
    }
    return $rahasia ? bukaSandi((string) $nilai) : (string) $nilai;
}

function simpanPengaturan(PDO $pdo, string $kunci, ?string $nilai, bool $rahasia = false): void
{
    if ($nilai === null) {
        $pdo->prepare("DELETE FROM pengaturan WHERE kunci = ?")->execute([$kunci]);
        return;
    }
    $pdo->prepare("REPLACE INTO pengaturan (kunci, nilai, diubah) VALUES (?, ?, ?)")
        ->execute([$kunci, $rahasia ? sandikan($nilai) : $nilai, date('Y-m-d H:i:s')]);
}

// -------------------------------------------------------------------
// HTTP ke Microsoft
// -------------------------------------------------------------------

function urlLoginMicrosoft(string $jalur): string
{
    $tenant = trim((string) env('ONEDRIVE_TENANT', 'common')) ?: 'common';
    return rtrim((string) env('ONEDRIVE_LOGIN_URL', 'https://login.microsoftonline.com'), '/') . '/' . rawurlencode($tenant) . '/oauth2/v2.0/' . $jalur;
}

function urlGraph(string $jalur): string
{
    return rtrim((string) env('ONEDRIVE_GRAPH_URL', 'https://graph.microsoft.com/v1.0'), '/') . $jalur;
}

/** @return array{0: int, 1: array|null, 2: string} [kode HTTP, JSON, teks mentah/error] */
function httpMicrosoft(string $metode, string $url, array $header = [], $isi = null, int $batasDetik = 25): array
{
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST  => $metode,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => $batasDetik,
        CURLOPT_HTTPHEADER     => $header,
    ]);
    if ($isi !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $isi);
    }
    if (function_exists('pasangSertifikatXampp')) {
        pasangSertifikatXampp($curl);
    }
    $res  = curl_exec($curl);
    $kode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($curl);
    curl_close($curl);
    if ($res === false) {
        return [0, null, 'Tidak bisa menghubungi Microsoft: ' . $err];
    }
    $json = json_decode((string) $res, true);
    return [$kode, is_array($json) ? $json : null, (string) $res];
}

/** Pesan error singkat dari balasan Microsoft */
function pesanMicrosoft(?array $json, string $cadangan): string
{
    $pesan = $json['error_description'] ?? ($json['error']['message'] ?? ($json['error'] ?? $cadangan));
    $pesan = is_string($pesan) ? $pesan : $cadangan;
    return potong(trim(preg_replace('/\s+/', ' ', explode("\n", $pesan)[0])), 250);
}

// -------------------------------------------------------------------
// Menghubungkan akun (device code flow)
// -------------------------------------------------------------------

/** Langkah 1: minta kode masuk. @return array{kode: string, url: string, berlaku: int} */
function onedriveMulaiHubung(PDO $pdo): array
{
    [$kode, $json, $mentah] = httpMicrosoft('POST', urlLoginMicrosoft('devicecode'),
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query(['client_id' => env('ONEDRIVE_CLIENT_ID'), 'scope' => ONEDRIVE_SCOPE]));
    if ($kode !== 200 || empty($json['device_code'])) {
        throw new RuntimeException(pesanMicrosoft($json, $kode ? "Microsoft menolak permintaan (HTTP $kode)." : $mentah));
    }
    $berlaku = time() + (int) ($json['expires_in'] ?? 900);
    simpanPengaturan($pdo, 'onedrive_device', json_encode([
        'device_code' => $json['device_code'], 'kode' => $json['user_code'],
        'url' => $json['verification_uri'] ?? 'https://microsoft.com/devicelogin', 'berlaku' => $berlaku,
    ]), true);
    return ['kode' => $json['user_code'], 'url' => $json['verification_uri'] ?? 'https://microsoft.com/devicelogin', 'berlaku' => $berlaku];
}

/** Kode masuk yang sedang menunggu (jika ada & belum kedaluwarsa) */
function onedriveKodeMenunggu(PDO $pdo): ?array
{
    $data = json_decode((string) ambilPengaturan($pdo, 'onedrive_device', true), true);
    if (!is_array($data) || ($data['berlaku'] ?? 0) < time()) {
        return null;
    }
    return $data;
}

/** Langkah 2: cek apakah admin sudah login. @return string 'terhubung' | 'menunggu' */
function onedriveCekHubung(PDO $pdo): string
{
    $data = onedriveKodeMenunggu($pdo);
    if (!$data) {
        simpanPengaturan($pdo, 'onedrive_device', null);
        throw new RuntimeException('Kode masuk sudah kedaluwarsa. Klik "Hubungkan OneDrive" lagi.');
    }
    [$kode, $json, $mentah] = httpMicrosoft('POST', urlLoginMicrosoft('token'),
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
            'client_id' => env('ONEDRIVE_CLIENT_ID'), 'device_code' => $data['device_code']]));

    if ($kode === 200 && !empty($json['refresh_token'])) {
        simpanPengaturan($pdo, 'onedrive_refresh', $json['refresh_token'], true);
        simpanPengaturan($pdo, 'onedrive_device', null);
        // Nama akun untuk ditampilkan di kartu OneDrive
        [$k2, $me] = httpMicrosoft('GET', urlGraph('/me'), ['Authorization: Bearer ' . $json['access_token']]);
        $akun = $k2 === 200 ? trim(($me['displayName'] ?? '') . ' (' . ($me['userPrincipalName'] ?? $me['mail'] ?? '') . ')', ' ()') : '';
        simpanPengaturan($pdo, 'onedrive_akun', $akun !== '' ? $akun : 'akun Microsoft');
        return 'terhubung';
    }
    $err = $json['error'] ?? '';
    if ($err === 'authorization_pending' || $err === 'slow_down') {
        return 'menunggu';
    }
    simpanPengaturan($pdo, 'onedrive_device', null);
    throw new RuntimeException(pesanMicrosoft($json, $kode ? "Login Microsoft gagal (HTTP $kode)." : $mentah));
}

function onedrivePutuskan(PDO $pdo): void
{
    foreach (['onedrive_refresh', 'onedrive_device', 'onedrive_akun'] as $k) {
        simpanPengaturan($pdo, $k, null);
    }
}

/** Token akses (berlaku ±1 jam) dari refresh token; refresh token baru langsung disimpan */
function onedriveTokenAkses(PDO $pdo): string
{
    static $token = null;
    if ($token !== null) {
        return $token;
    }
    $refresh = ambilPengaturan($pdo, 'onedrive_refresh', true);
    if ($refresh === null) {
        throw new RuntimeException('OneDrive belum terhubung.');
    }
    [$kode, $json, $mentah] = httpMicrosoft('POST', urlLoginMicrosoft('token'),
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query(['grant_type' => 'refresh_token', 'client_id' => env('ONEDRIVE_CLIENT_ID'),
            'refresh_token' => $refresh, 'scope' => ONEDRIVE_SCOPE]));
    if ($kode !== 200 || empty($json['access_token'])) {
        throw new RuntimeException('Token OneDrive tidak berlaku, hubungkan ulang: ' . pesanMicrosoft($json, $kode ? "HTTP $kode" : $mentah));
    }
    if (!empty($json['refresh_token']) && $json['refresh_token'] !== $refresh) {
        simpanPengaturan($pdo, 'onedrive_refresh', $json['refresh_token'], true);
    }
    return $token = $json['access_token'];
}

// -------------------------------------------------------------------
// Upload lampiran
// -------------------------------------------------------------------

/** Nama file/folder yang aman untuk OneDrive (tanpa " * : < > ? / \ | dan tanpa titik/spasi di ujung) */
function namaAmanOneDrive(string $nama): string
{
    $nama = trim(preg_replace('/["*:<>?\/\\\\|\x00-\x1F]+/u', '-', $nama), " .");
    return $nama !== '' ? potong($nama, 120) : 'lampiran';
}

/** Isi lengkap file lampiran (utuh atau gabungan potongan) */
function isiLampiran(PDO $pdo, array $lamp): string
{
    if ((int) $lamp['jumlah_bagian'] === 0) {
        $stmt = $pdo->prepare("SELECT isi FROM lampiran WHERE id = ?");
        $stmt->execute([(int) $lamp['id']]);
        return (string) $stmt->fetchColumn();
    }
    $stmt = $pdo->prepare("SELECT isi FROM lampiran_bagian WHERE lampiran_id = ? ORDER BY urutan");
    $stmt->execute([(int) $lamp['id']]);
    return implode('', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Salin satu lampiran ke OneDrive dan catat hasilnya di tabel lampiran.
 * Tidak pernah melempar error: hasil dikembalikan agar upload ke aplikasi tetap dianggap berhasil.
 * @return array{ok: bool, pesan: string}
 */
function onedriveUnggah(PDO $pdo, int $lampiranId): array
{
    $stmt = $pdo->prepare(
        "SELECT l.id, l.nama_file, l.ukuran, l.jumlah_bagian, d.kode_unit, d.nama_unit
         FROM lampiran l JOIN deadline d ON d.id = l.deadline_id WHERE l.id = ? AND l.selesai = 1"
    );
    $stmt->execute([$lampiranId]);
    $lamp = $stmt->fetch();
    if (!$lamp) {
        return ['ok' => false, 'pesan' => 'Lampiran tidak ditemukan'];
    }

    try {
        $token = onedriveTokenAkses($pdo);
        $isi   = isiLampiran($pdo, $lamp);
        $jalur = onedriveFolder() . '/' . namaAmanOneDrive($lamp['kode_unit'] . ' - ' . $lamp['nama_unit']) . '/' . namaAmanOneDrive($lamp['nama_file']);
        $jalurUrl = implode('/', array_map('rawurlencode', explode('/', $jalur)));

        if (strlen($isi) <= ONEDRIVE_BATAS_SATU) {
            [$kode, $json, $mentah] = httpMicrosoft('PUT', urlGraph('/me/drive/root:/' . $jalurUrl . ':/content?@microsoft.graph.conflictBehavior=rename'),
                ['Authorization: Bearer ' . $token, 'Content-Type: application/octet-stream'], $isi, 60);
        } else {
            // File besar: buat sesi upload, lalu kirim per potongan
            [$kode, $sesi, $mentah] = httpMicrosoft('POST', urlGraph('/me/drive/root:/' . $jalurUrl . ':/createUploadSession'),
                ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
                json_encode(['item' => ['@microsoft.graph.conflictBehavior' => 'rename']]));
            if ($kode !== 200 || empty($sesi['uploadUrl'])) {
                throw new RuntimeException(pesanMicrosoft($sesi, "Gagal membuat sesi upload (HTTP $kode)"));
            }
            $total = strlen($isi);
            for ($mulai = 0; $mulai < $total; $mulai += ONEDRIVE_POTONGAN) {
                $bagian = substr($isi, $mulai, ONEDRIVE_POTONGAN);
                $akhir  = $mulai + strlen($bagian) - 1;
                // URL sesi upload sudah berisi otorisasi; jangan kirim header Authorization
                [$kode, $json, $mentah] = httpMicrosoft('PUT', $sesi['uploadUrl'],
                    ['Content-Length: ' . strlen($bagian), "Content-Range: bytes $mulai-$akhir/$total"], $bagian, 60);
                if ($kode !== 202 && $kode !== 200 && $kode !== 201) {
                    break;
                }
            }
        }

        if (($kode === 200 || $kode === 201) && !empty($json['id'])) {
            $pdo->prepare("UPDATE lampiran SET onedrive_status = 'ok', onedrive_url = ?, onedrive_pesan = NULL, onedrive_coba = onedrive_coba + 1 WHERE id = ?")
                ->execute([potong((string) ($json['webUrl'] ?? ''), 1000), $lampiranId]);
            return ['ok' => true, 'pesan' => 'Tersimpan di OneDrive'];
        }
        throw new RuntimeException(pesanMicrosoft($json, $kode ? "Upload ditolak (HTTP $kode)" : $mentah));
    } catch (Throwable $e) {
        $pesan = potong($e->getMessage(), 250);
        error_log("OneDrive gagal (lampiran $lampiranId): $pesan");
        $pdo->prepare("UPDATE lampiran SET onedrive_status = 'gagal', onedrive_pesan = ?, onedrive_coba = onedrive_coba + 1 WHERE id = ?")
            ->execute([$pesan, $lampiranId]);
        return ['ok' => false, 'pesan' => $pesan];
    }
}

/** Salin lampiran yang baru disimpan (dipanggil setelah upload di dashboard); diam bila OneDrive belum terhubung */
function onedriveSalinBaru(PDO $pdo, array $lampiranIds): ?array
{
    if (!$lampiranIds || !onedriveSiap($pdo)) {
        return null;
    }
    $ok = 0;
    $alasan = '';
    foreach ($lampiranIds as $id) {
        $hasil = onedriveUnggah($pdo, (int) $id);
        if ($hasil['ok']) {
            $ok++;
        } else {
            $alasan = $hasil['pesan'];
        }
    }
    return ['ok' => $ok, 'gagal' => count($lampiranIds) - $ok, 'alasan' => $alasan];
}

/**
 * Coba lagi lampiran yang belum tersalin (belum pernah / gagal), terlama dulu.
 * Dipanggil cron pengingat setiap pagi dan tombol "Salin lampiran yang belum tersimpan".
 * @return array{ok: int, gagal: int}
 */
function onedriveSinkronTertunda(PDO $pdo, int $batas = 10): array
{
    if (!onedriveSiap($pdo)) {
        return ['ok' => 0, 'gagal' => 0];
    }
    $stmt = $pdo->prepare(
        "SELECT id FROM lampiran WHERE selesai = 1 AND (onedrive_status IS NULL OR onedrive_status = 'gagal')
           AND onedrive_coba < ? ORDER BY id LIMIT " . max(1, $batas)
    );
    $stmt->execute([ONEDRIVE_MAKS_COBA]);
    $hasil = ['ok' => 0, 'gagal' => 0];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $hasil[onedriveUnggah($pdo, (int) $id)['ok'] ? 'ok' : 'gagal']++;
    }
    return $hasil;
}

/** Jumlah lampiran per status OneDrive untuk kartu di menu Penerima */
function onedriveRingkasan(PDO $pdo): array
{
    $r = $pdo->query(
        "SELECT COALESCE(SUM(onedrive_status = 'ok'), 0) AS ok,
                COALESCE(SUM(onedrive_status = 'gagal'), 0) AS gagal,
                COALESCE(SUM(onedrive_status IS NULL), 0) AS belum
         FROM lampiran WHERE selesai = 1"
    )->fetch();
    return array_map('intval', $r ?: ['ok' => 0, 'gagal' => 0, 'belum' => 0]);
}
