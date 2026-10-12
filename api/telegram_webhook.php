<?php
/**
 * WEBHOOK TELEGRAM (dipanggil server Telegram, bukan dibuka manual).
 * Diaktifkan dari dashboard (kartu Penerima Telegram -> "Aktifkan tombol Selesai"),
 * yang mendaftarkan URL https://<domain>/telegram_webhook.php ke bot.
 *
 * Yang ditangani:
 *  1. Tombol "✅ Selesai" di bawah pesan pengingat (callback_query "selesai:<id unit>")
 *     -> hanya chat / pengguna yang terdaftar sebagai penerima Telegram aktif yang boleh
 *     -> unit ditandai selesai (selesai_tanggal, selesai_oleh) sehingga cron berhenti mengingatkan
 *     -> tombol unit itu dihapus dari pesan + balasan "ditandai selesai oleh ..." di chat
 *  2. Pesan /start atau /id -> bot membalas Chat ID pengirim (untuk didaftarkan admin)
 *  3. Setiap chat yang menghubungi bot dicatat di tabel telegram_chat_masuk
 *     (dipakai tombol "Cari chat" di dashboard, karena getUpdates tidak bisa dipakai saat webhook aktif)
 *
 * Keamanan: setiap permintaan wajib membawa header X-Telegram-Bot-Api-Secret-Token
 * yang cocok dengan rahasiaWebhookTelegram(). Tanpa itu permintaan ditolak (401).
 */
require_once __DIR__ . '/lib/koneksi.php';
require_once __DIR__ . '/lib/fonnte.php';

header('Content-Type: application/json; charset=utf-8');

$rahasia = (string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !env('TELEGRAM_BOT_TOKEN') || !hash_equals(rahasiaWebhookTelegram(), $rahasia)) {
    http_response_code(401);
    echo json_encode(['ok' => false]);
    exit;
}

$update = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($update)) {
    echo json_encode(['ok' => true]);
    exit;
}

pastikanTabelTambahan($pdo);

/** Escape teks untuk pesan Telegram berformat HTML */
function htmlTg(string $teks): string
{
    return htmlspecialchars($teks, ENT_NOQUOTES, 'UTF-8');
}

// --- 1. TOMBOL "SELESAI" ---
if (isset($update['callback_query'])) {
    $cq     = $update['callback_query'];
    $data   = (string) ($cq['data'] ?? '');
    $pesan  = $cq['message'] ?? [];
    $chatId = (string) ($pesan['chat']['id'] ?? '');
    $dariId = (string) ($cq['from']['id'] ?? '');
    $jawab  = static fn(string $teks, bool $popup = false) => panggilTelegram('answerCallbackQuery', [
        'callback_query_id' => $cq['id'] ?? '',
        'text'              => $teks,
        'show_alert'        => $popup,
    ]);

    if (!preg_match('/^selesai:(\d+)$/', $data, $m)) {
        $jawab('');
        echo json_encode(['ok' => true]);
        exit;
    }

    // Hanya chat yang terdaftar (aktif) di dashboard: pesan berasal dari chat itu, atau
    // yang menekan tombol adalah pengguna yang chat pribadinya terdaftar.
    $terdaftar = daftarChatTelegram();
    if (!in_array($chatId, $terdaftar, true) && !in_array($dariId, $terdaftar, true)) {
        $jawab('Anda tidak terdaftar sebagai penerima pengingat, jadi tidak bisa menandai unit selesai.', true);
        echo json_encode(['ok' => true]);
        exit;
    }

    $unitId = (int) $m[1];
    $stmt   = $pdo->prepare("SELECT kode_unit, nama_unit, selesai_tanggal, selesai_oleh FROM deadline WHERE id = ?");
    $stmt->execute([$unitId]);
    $unit = $stmt->fetch();

    if (!$unit) {
        $jawab('Unit ini sudah dihapus dari aplikasi.', true);
    } elseif ($unit['selesai_tanggal'] !== null) {
        $jawab('Unit ini sudah ditandai selesai oleh ' . $unit['selesai_oleh'] . ' pada '
            . date('d-m-Y H:i', strtotime($unit['selesai_tanggal'])) . '.', true);
    } else {
        $nama = infoChatTelegram($cq['from'] ?? [])['nama'];
        $oleh = potong($nama, 88) . ' (Telegram)';
        // "AND selesai_tanggal IS NULL" -> bila dua orang menekan bersamaan, hanya yang pertama tercatat
        $pdo->prepare("UPDATE deadline SET selesai_tanggal = ?, selesai_oleh = ? WHERE id = ? AND selesai_tanggal IS NULL")
            ->execute([date('Y-m-d H:i:s'), $oleh, $unitId]);

        $label = '(' . $unit['kode_unit'] . ') ' . $unit['nama_unit'];
        $jawab("Unit $label ditandai selesai.");

        // Pengumuman di chat, membalas pesan pengingatnya (terlihat semua anggota grup)
        panggilTelegram('sendMessage', [
            'chat_id'          => $chatId,
            'text'             => '✅ <b>' . htmlTg($label) . '</b> ditandai selesai oleh ' . htmlTg($nama) . '.',
            'parse_mode'       => 'HTML',
            'reply_parameters' => ['message_id' => (int) ($pesan['message_id'] ?? 0), 'allow_sending_without_reply' => true],
        ]);
    }

    // Hapus tombol unit ini dari pesan (unit lain di pesan yang sama tetap punya tombol)
    if ($unit && isset($pesan['message_id'])) {
        $sisa = [];
        foreach ($pesan['reply_markup']['inline_keyboard'] ?? [] as $baris) {
            $baris = array_values(array_filter($baris, static fn($t) => ($t['callback_data'] ?? '') !== $data));
            if ($baris) {
                $sisa[] = $baris;
            }
        }
        panggilTelegram('editMessageReplyMarkup', [
            'chat_id'      => $chatId,
            'message_id'   => (int) $pesan['message_id'],
            'reply_markup' => ['inline_keyboard' => $sisa],
        ]);
    }

    echo json_encode(['ok' => true]);
    exit;
}

// --- 2 & 3. PESAN BIASA / BOT DITAMBAHKAN KE GRUP ---
$chat = $update['message']['chat'] ?? $update['channel_post']['chat'] ?? $update['my_chat_member']['chat'] ?? null;
$keluar = in_array($update['my_chat_member']['new_chat_member']['status'] ?? '', ['left', 'kicked'], true);

if (is_array($chat) && isset($chat['id']) && !$keluar) {
    $info = infoChatTelegram($chat);
    $pdo->prepare(
        "INSERT INTO telegram_chat_masuk (chat_id, nama, jenis, waktu) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE nama = VALUES(nama), jenis = VALUES(jenis), waktu = VALUES(waktu)"
    )->execute([(string) $chat['id'], $info['nama'], $info['jenis'], date('Y-m-d H:i:s')]);

    // /start atau /id (di grup bisa /start@nama_bot) -> balas dengan Chat ID
    $teks = (string) ($update['message']['text'] ?? '');
    if (preg_match('#^/(start|id)(@\w+)?(\s|$)#i', $teks)) {
        panggilTelegram('sendMessage', [
            'chat_id'    => $chat['id'],
            'text'       => "Chat ID " . ($info['jenis'] === 'Grup' ? 'grup ini' : 'Anda') . ": <code>" . htmlTg((string) $chat['id']) . "</code>\n\n"
                . "Berikan Chat ID ini ke admin aplikasi agar didaftarkan sebagai penerima pengingat deadline.",
            'parse_mode' => 'HTML',
        ]);
    }
}

echo json_encode(['ok' => true]);
