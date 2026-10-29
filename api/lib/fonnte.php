<?php
/**
 * Helper pengiriman notifikasi: WhatsApp (via Fonnte), Telegram Bot, dan/atau Email (lib/email.php).
 *  - NOTIF_VIA          : saluran yang dipakai, dipisah koma: "wa" (default), "telegram", "email",
 *                         contoh "wa,telegram,email" untuk ketiganya
 *  - TELEGRAM_BOT_TOKEN : token bot dari @BotFather
 *  - TELEGRAM_CHAT_ID   : chat id penerima (orang atau grup), bisa lebih dari 1 dipisah koma
 *  - FONNTE_TOKEN (Environment Variable) : token device dari dashboard fonnte.com
 *  - Nomor penerima diatur dari dashboard (tabel wa_penerima, bisa lebih dari 1 nomor).
 *    Jika belum ada nomor aktif di dashboard, dipakai WA_TARGET dari Environment Variables.
 */
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/skema.php';
require_once __DIR__ . '/email.php';

/**
 * Rapikan nomor WA ke format internasional tanpa simbol.
 * Contoh: "0812-3456-7890" -> "6281234567890". Bisa banyak nomor dipisah koma.
 */
function normalisasiNomorWa(string $nomor): string
{
    $hasil = [];
    foreach (explode(',', $nomor) as $n) {
        $n = preg_replace('/\D/', '', $n);
        if ($n === '') {
            continue;
        }
        if (substr($n, 0, 1) === '0') {
            $n = '62' . substr($n, 1);
        }
        $hasil[] = $n;
    }
    return implode(',', $hasil);
}

/** Validasi 1 nomor WA hasil normalisasi (62xxxxxxxxx, 10-15 digit) */
function nomorWaValid(string $nomor): bool
{
    return (bool) preg_match('/^62\d{8,13}$/', $nomor);
}

/**
 * Periksa nomor WA yang diketik admin saat menambah/mengedit penerima (aturan lebih ketat
 * dari nomorWaValid agar salah ketik tidak tersimpan; nomor lama tetap bisa dikirimi).
 * - Hanya angka, spasi, dan tanda + - ( ) . yang boleh; huruf ditolak, tidak dibuang diam-diam.
 * - Harus nomor HP Indonesia: 08 + 8-11 digit (10-13 digit), atau 628 + 8-11 digit.
 * @return array{0: ?string, 1: ?string} [nomor 62xxx, null] jika valid, atau [null, pesan kesalahan]
 */
function periksaNomorWaBaru(string $input): array
{
    $input = trim($input);
    if (preg_match('/[^\d\s+\-().]/', $input)) {
        return [null, 'Nomor WA hanya boleh berisi angka (boleh memakai spasi, tanda - atau +).'];
    }
    $nomor = normalisasiNomorWa($input);
    if (!preg_match('/^628\d{8,11}$/', $nomor)) {
        return [null, 'Nomor WA harus nomor HP Indonesia 10–13 digit yang diawali 08 (contoh: 081234567890).'];
    }
    return [$nomor, null];
}

/**
 * Daftar nomor penerima aktif: dari dashboard (tabel wa_penerima),
 * atau WA_TARGET jika belum ada nomor aktif.
 */
function daftarNomorPenerima(): array
{
    global $pdo;

    $nomor = [];
    try {
        pastikanTabelTambahan($pdo);
        $nomor = $pdo->query("SELECT nomor FROM wa_penerima WHERE aktif = 1 ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        error_log('Gagal membaca wa_penerima: ' . $e->getMessage());
    }

    if (!$nomor) {
        $env   = normalisasiNomorWa((string) env('WA_TARGET', ''));
        $nomor = $env === '' ? [] : explode(',', $env);
    }

    return array_values(array_unique(array_filter($nomor, 'nomorWaValid')));
}

/** Saluran notifikasi yang aktif menurut NOTIF_VIA, contoh: ['wa'], ['wa', 'telegram'], ['wa', 'telegram', 'email'] */
function saluranNotifikasi(): array
{
    $pilihan = array_map('trim', preg_split('/[,;+ ]+/', strtolower((string) env('NOTIF_VIA', 'wa'))));
    $saluran = array_values(array_intersect(['wa', 'telegram', 'email'], $pilihan));
    return $saluran ?: ['wa'];
}

/** Saluran yang pengaturannya sudah lengkap (token / SMTP terisi) -> bisa dipilih di tombol kirim manual */
function saluranSiap(): array
{
    return array_keys(array_filter([
        'wa'       => (string) env('FONNTE_TOKEN', '') !== '',
        'telegram' => (string) env('TELEGRAM_BOT_TOKEN', '') !== '',
        'email'    => emailSiap(),
    ]));
}

/** Validasi chat id Telegram: angka; grup/channel diawali tanda minus (contoh -1001234567890) */
function chatIdTelegramValid(string $id): bool
{
    return (bool) preg_match('/^-?\d{3,20}$/', $id);
}

/**
 * Daftar chat id Telegram penerima aktif: dari dashboard (tabel telegram_penerima),
 * atau TELEGRAM_CHAT_ID jika belum ada chat aktif (sama seperti nomor WA & WA_TARGET).
 */
function daftarChatTelegram(): array
{
    global $pdo;

    $ids = [];
    try {
        pastikanTabelTambahan($pdo);
        $ids = $pdo->query("SELECT chat_id FROM telegram_penerima WHERE aktif = 1 ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        error_log('Gagal membaca telegram_penerima: ' . $e->getMessage());
    }

    if (!$ids) {
        $ids = preg_split('/[\s,;]+/', (string) env('TELEGRAM_CHAT_ID', ''));
    }
    return array_values(array_unique(array_filter($ids, static fn($id) => chatIdTelegramValid((string) $id))));
}

/**
 * Cari chat yang baru mengirim pesan ke bot (Telegram getUpdates), untuk membantu mengisi chat id.
 * Orang/grup harus mengirim pesan ke bot dulu (klik Start, atau /start@nama_bot di grup).
 * @return array{ok: bool, pesan: string, chat: array<string, array{nama: string, jenis: string}>}
 */
function cariChatTelegram(): array
{
    $token = env('TELEGRAM_BOT_TOKEN');
    if (!$token) {
        return ['ok' => false, 'pesan' => 'TELEGRAM_BOT_TOKEN belum diatur.', 'chat' => []];
    }

    $url  = rtrim((string) env('TELEGRAM_API', 'https://api.telegram.org'), '/') . '/bot' . $token . '/getUpdates';
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 20,
    ]);
    pasangSertifikatXampp($curl);
    $response = curl_exec($curl);
    curl_close($curl);

    $res = $response === false ? null : json_decode($response, true);
    if (empty($res['ok'])) {
        $alasan = $response === false ? 'Gagal menghubungi server Telegram.' : (string) ($res['description'] ?? 'Respon tidak valid');
        return ['ok' => false, 'pesan' => $alasan, 'chat' => []];
    }

    $chat = [];
    foreach ($res['result'] ?? [] as $update) {
        // Pesan biasa, pesan di channel, atau bot ditambahkan ke grup
        $c = $update['message']['chat'] ?? $update['channel_post']['chat'] ?? $update['my_chat_member']['chat'] ?? null;
        if (!$c || !isset($c['id'])) {
            continue;
        }
        $nama = trim((string) ($c['title'] ?? (($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''))));
        if ($nama === '' && !empty($c['username'])) {
            $nama = '@' . $c['username'];
        }
        $jenis = ['private' => 'Pribadi', 'group' => 'Grup', 'supergroup' => 'Grup', 'channel' => 'Channel'][$c['type'] ?? ''] ?? 'Chat';
        $chat[(string) $c['id']] = ['nama' => $nama !== '' ? $nama : 'Tanpa nama', 'jenis' => $jenis];
    }

    return ['ok' => true, 'pesan' => '', 'chat' => $chat];
}

/**
 * Kirim notifikasi ke semua saluran aktif (WhatsApp, Telegram, dan/atau Email), lalu catat di riwayat (wa_log).
 * Nama fungsi tetap "kirimWhatsApp" agar pemanggil lama (cron & dashboard) tidak perlu diubah.
 * @param string $jenis     'otomatis' (cron) atau 'manual' (tombol di dashboard)
 * @param string $ringkasan Daftar unit yang diingatkan, untuk riwayat
 * @param array|null $saluran daftar saluran ('wa', 'telegram', 'email'); null = ikut NOTIF_VIA
 *                            (dipakai tombol kirim manual yang memilih saluran sendiri)
 * @return array{ok: bool, pesan: string}  ok = minimal satu saluran berhasil
 */
function kirimWhatsApp(string $pesan, string $jenis = 'manual', string $ringkasan = '', ?array $saluran = null): array
{
    global $pdo;

    $ringkasan = $ringkasan !== '' ? $ringkasan : '-';
    $saluran   = $saluran ?: saluranNotifikasi();
    $semua     = [];

    $catatan   = [];

    if (in_array('wa', $saluran, true)) {
        $target = implode(',', daftarNomorPenerima());
        $hasil  = kirimKeFonnte($pesan, $target);
        $catatan[] = ['saluran' => 'wa', 'penerima' => $target !== '' ? $target : '-'] + $hasil;
        $semua['WhatsApp'] = $hasil;
    }

    if (in_array('telegram', $saluran, true)) {
        $chat  = daftarChatTelegram();
        $hasil = kirimKeTelegram($pesan, $chat);
        // Penerima Telegram dicatat dengan awalan "tg:" agar riwayat bisa membedakannya dari nomor WA
        $catatan[] = ['saluran' => 'telegram', 'penerima' => $chat ? 'tg:' . implode(',tg:', $chat) : '-'] + $hasil;
        $semua['Telegram'] = $hasil;
    }

    if (in_array('email', $saluran, true)) {
        $alamat = daftarEmailPenerima();
        $hasil  = kirimKeEmail($pesan, $alamat, subjekEmail($ringkasan));
        // Penerima Email dicatat dengan awalan "em:" agar riwayat bisa membedakannya dari nomor WA
        $catatan[] = ['saluran' => 'email', 'penerima' => $alamat ? 'em:' . implode(',em:', $alamat) : '-'] + $hasil;
        $semua['Email'] = $hasil;
    }

    // Semua saluran dari satu pengiriman dicatat sebagai satu baris riwayat
    catatPengiriman($pdo, $jenis, $ringkasan, $catatan);

    if (count($semua) === 1) {
        return reset($semua);
    }
    $ok    = (bool) array_filter($semua, static fn($h) => $h['ok']);
    $pesan = implode('; ', array_map(static fn($nama, $h) => "$nama: {$h['pesan']}", array_keys($semua), $semua));
    return ['ok' => $ok, 'pesan' => $pesan];
}

/**
 * XAMPP / Laragon / Laravel Herd (Windows) kadang belum mengatur sertifikat HTTPS untuk cURL
 * -> pakai file sertifikat bawaan PHP/XAMPP yang ditemukan.
 */
function pasangSertifikatXampp($curl): void
{
    if (ini_get('curl.cainfo')) {
        return;
    }
    $ca = fileSertifikatCa();
    if ($ca !== '') {
        curl_setopt($curl, CURLOPT_CAINFO, $ca);
    }
}

/** File sertifikat CA bawaan PHP/XAMPP/Laragon yang ditemukan ('' jika tidak ada). Dipakai cURL & SMTP. */
function fileSertifikatCa(): string
{
    $kandidat = [
        ini_get('openssl.cafile') ?: '',
        dirname(PHP_BINARY, 2) . '/apache/bin/curl-ca-bundle.crt',
        dirname(PHP_BINARY, 2) . '/php/extras/ssl/cacert.pem',
        dirname(PHP_BINARY) . '/extras/ssl/cacert.pem',
        // Laragon: laragon\bin\php\php-8.x\php.exe -> laragon\etc\ssl\cacert.pem
        dirname(PHP_BINARY, 4) . '/etc/ssl/cacert.pem',
        // Herd memakai PHP sendiri; jika XAMPP juga terpasang, pinjam sertifikatnya
        'C:/xampp/apache/bin/curl-ca-bundle.crt',
        'D:/xampp/apache/bin/curl-ca-bundle.crt',
    ];
    foreach ($kandidat as $ca) {
        if ($ca !== '' && is_file($ca)) {
            return $ca;
        }
    }
    return '';
}

/**
 * Ubah format pesan WhatsApp ke HTML Telegram: *tebal* -> <b>tebal</b>.
 * Teks lain di-escape agar karakter seperti < & > di nama unit tidak merusak pesan.
 */
function pesanKeHtmlTelegram(string $pesan): string
{
    $html = htmlspecialchars($pesan, ENT_NOQUOTES, 'UTF-8');
    return preg_replace('/\*([^*\n]+)\*/u', '<b>$1</b>', $html);
}

/**
 * Kirim pesan ke Telegram Bot (gratis, tanpa kuota untuk pemakaian seperti ini).
 * Satu permintaan per chat id; berhasil jika minimal satu chat menerima.
 * @return array{ok: bool, pesan: string}
 */
function kirimKeTelegram(string $pesan, array $chatIds): array
{
    $token = env('TELEGRAM_BOT_TOKEN');
    if (!$token) {
        return ['ok' => false, 'pesan' => 'TELEGRAM_BOT_TOKEN belum diatur.'];
    }
    if (!$chatIds) {
        return ['ok' => false, 'pesan' => 'Belum ada chat Telegram penerima (tambahkan di dashboard atau isi TELEGRAM_CHAT_ID).'];
    }

    $url      = rtrim((string) env('TELEGRAM_API', 'https://api.telegram.org'), '/') . '/bot' . $token . '/sendMessage';
    $html     = pesanKeHtmlTelegram($pesan);
    $berhasil = 0;
    $alasan   = '';

    foreach ($chatIds as $chatId) {
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_POSTFIELDS     => [
                'chat_id'                  => $chatId,
                'text'                     => $html,
                'parse_mode'               => 'HTML',
                'disable_web_page_preview' => 'true',
            ],
        ]);
        pasangSertifikatXampp($curl);
        $response = curl_exec($curl);
        $error    = curl_error($curl);
        curl_close($curl);

        // Telegram membalas {"ok": true, ...} atau {"ok": false, "description": "..."}
        $res = $response === false ? null : json_decode($response, true);
        if (!empty($res['ok'])) {
            $berhasil++;
        } else {
            $alasan = $response === false ? 'Gagal menghubungi server Telegram.' : (string) ($res['description'] ?? 'Respon tidak valid');
            error_log("Telegram gagal ($chatId): " . ($response === false ? $error : $response));
        }
    }

    if ($berhasil === 0) {
        return ['ok' => false, 'pesan' => $alasan];
    }
    $pesanHasil = "Terkirim ke $berhasil chat Telegram";
    if ($berhasil < count($chatIds)) {
        $pesanHasil .= ' (' . (count($chatIds) - $berhasil) . ' gagal: ' . $alasan . ')';
    }
    return ['ok' => true, 'pesan' => $pesanHasil];
}

/**
 * Kirim pesan langsung ke API Fonnte (dipanggil oleh kirimWhatsApp).
 * $target berisi satu atau beberapa nomor dipisah koma; Fonnte mengirim ke semuanya.
 * Pesan dikirim dari HP/nomor yang terhubung (device) di dashboard fonnte.com.
 * @return array{ok: bool, pesan: string}
 */
function kirimKeFonnte(string $pesan, string $target): array
{
    $token = env('FONNTE_TOKEN');

    if (!$token) {
        return ['ok' => false, 'pesan' => 'FONNTE_TOKEN belum diatur.'];
    }
    if ($target === '') {
        return ['ok' => false, 'pesan' => 'Belum ada nomor penerima WA yang aktif. Tambahkan di dashboard.'];
    }

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL            => env('FONNTE_URL', 'https://api.fonnte.com/send'),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        // Batas waktu agar halaman/cron tidak menggantung bila Fonnte lambat
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_POSTFIELDS     => [
            'target'      => $target,
            'message'     => $pesan,
            'countryCode' => '62',
        ],
        CURLOPT_HTTPHEADER     => ['Authorization: ' . $token],
    ]);

    pasangSertifikatXampp($curl);

    $response = curl_exec($curl);
    $error    = curl_error($curl);
    curl_close($curl);

    if ($response === false) {
        error_log('Fonnte cURL error: ' . $error);
        return ['ok' => false, 'pesan' => 'Gagal menghubungi server Fonnte.'];
    }

    // Fonnte membalas JSON: {"status": true, ...} jika berhasil,
    // atau {"status": false, "reason": "..."} jika gagal (token salah, device offline, dll)
    $res = json_decode($response, true);
    if (!empty($res['status'])) {
        return ['ok' => true, 'pesan' => 'Terkirim ke ' . count(explode(',', $target)) . ' nomor'];
    }

    $alasan = is_array($res) ? ($res['reason'] ?? $res['detail'] ?? 'Tidak diketahui') : 'Respon tidak valid';
    error_log('Fonnte gagal: ' . $response);
    return ['ok' => false, 'pesan' => (string) $alasan];
}

/**
 * Teks "Sisa Waktu" untuk pesan WA (dipakai pesan otomatis & manual), contoh:
 * "Hari Ini", "Besok / H-1", "5 hari lagi", "Sudah Expired (Lewat 2 hari)".
 */
function keteranganSisaHari(int $sisa): string
{
    if ($sisa === 0) {
        return 'Hari Ini';
    }
    if ($sisa === 1) {
        return 'Besok / H-1';
    }
    if ($sisa > 1) {
        return "$sisa hari lagi";
    }
    return 'Sudah Expired (Lewat ' . abs($sisa) . ' hari)';
}

/**
 * Hitung selisih hari dari hari ini ke tanggal target.
 * Contoh: 0 = hari ini, 1 = besok (H-1), negatif = sudah lewat.
 */
function hitungSisaHari(?string $tanggal_akhir): int
{
    if (empty($tanggal_akhir)) {
        return 0;
    }

    $today  = new DateTime('today');
    $target = new DateTime($tanggal_akhir);
    $target->setTime(0, 0, 0);

    $diff = $today->diff($target);

    return ($diff->invert == 1) ? -$diff->days : $diff->days;
}
