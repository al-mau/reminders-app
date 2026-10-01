<?php
/**
 * Unduh / lihat lampiran dokumen. Hanya untuk user yang sudah login.
 *   lampiran.php?id=12          -> unduh
 *   lampiran.php?id=12&lihat=1  -> buka di browser (hanya PDF & gambar)
 *
 * Lampiran besar disimpan per potongan (lampiran_bagian) karena Vercel menolak
 * response > 4,5 MB. Untuk file seperti itu halaman ini mengirim pemuat kecil
 * yang mengambil potongan satu per satu (?bagian=N) lalu menyatukannya di browser.
 */
require_once __DIR__ . '/lib/koneksi.php';
require_once __DIR__ . '/lib/session_handler.php';
require_once __DIR__ . '/lib/skema.php';

if (empty($_SESSION['login'])) {
    header("Location: login.php");
    exit;
}

pastikanTabelTambahan($pdo);
session_write_close(); // tidak mengubah sesi; lepaskan agar unduhan paralel tidak saling menunggu

$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT id, nama_file, ekstensi, ukuran, jumlah_bagian FROM lampiran WHERE id = ? AND selesai = 1");
$stmt->execute([$id]);
$file = $stmt->fetch();

if (!$file) {
    http_response_code(404);
    exit('Lampiran tidak ditemukan.');
}

$ext         = strtolower($file['ekstensi']);
$mime        = LAMPIRAN_TIPE[$ext] ?? 'application/octet-stream';
$bisaDilihat = in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true);
$inline      = !empty($_GET['lihat']) && $bisaDilihat;
$berpotongan = (int) $file['jumlah_bagian'] > 0;

header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');

// --- Satu potongan file (dipanggil oleh pemuat di bawah) ---
if ($berpotongan && isset($_GET['bagian'])) {
    $bagian = $pdo->prepare("SELECT isi FROM lampiran_bagian WHERE lampiran_id = ? AND urutan = ?");
    $bagian->execute([$id, (int) $_GET['bagian']]);
    $isi = $bagian->fetchColumn();
    if ($isi === false) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . strlen($isi));
    echo $isi;
    exit;
}

// --- File lama (disimpan utuh di satu baris) ---
if (!$berpotongan) {
    $isi = $pdo->prepare("SELECT isi FROM lampiran WHERE id = ?");
    $isi->execute([$id]);
    $isi = (string) $isi->fetchColumn();

    // Nama file aman untuk header (ASCII fallback + UTF-8)
    $namaAscii = preg_replace('/[^A-Za-z0-9._ -]/', '_', $file['nama_file']);

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . strlen($isi));
    header(sprintf(
        'Content-Disposition: %s; filename="%s"; filename*=UTF-8\'\'%s',
        $inline ? 'inline' : 'attachment',
        $namaAscii,
        rawurlencode($file['nama_file'])
    ));
    header('Content-Security-Policy: sandbox');
    echo $isi;
    exit;
}

// --- File berpotongan: halaman pemuat yang menyatukan potongan di browser ---
$konfig = [
    'url'    => 'lampiran.php?id=' . $id . '&bagian=',
    'jumlah' => (int) $file['jumlah_bagian'],
    'ukuran' => (int) $file['ukuran'],
    'nama'   => $file['nama_file'],
    'mime'   => $mime,
    'lihat'  => $inline,
];
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($file['nama_file']); ?></title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f8f9fa; color: #1e293b; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .kotak { background: #fff; padding: 24px 28px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,.08); width: min(420px, 90vw); }
        .bar { height: 8px; background: #e2e8f0; border-radius: 4px; overflow: hidden; margin: 12px 0; }
        .isi { height: 100%; width: 0; background: #2563eb; transition: width .2s; }
        .kecil { font-size: .85rem; color: #64748b; word-break: break-all; }
        a { color: #2563eb; }
    </style>
</head>
<body>
<div class="kotak">
    <strong id="judul">Menyiapkan file…</strong>
    <div class="kecil"><?= e($file['nama_file']); ?> · <?= formatUkuran((int) $file['ukuran']); ?></div>
    <div class="bar"><div class="isi" id="isi"></div></div>
    <div class="kecil" id="status"></div>
</div>
<script>
(async () => {
    const k = <?= json_encode($konfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const isi = document.getElementById('isi'), status = document.getElementById('status'), judul = document.getElementById('judul');
    try {
        const bagian = [];
        for (let i = 0; i < k.jumlah; i++) {
            let res;
            for (let coba = 1; coba <= 3; coba++) {
                try { res = await fetch(k.url + i, { credentials: 'same-origin' }); if (res.ok) break; } catch (_) {}
                await new Promise((r) => setTimeout(r, 1000 * coba));
            }
            if (!res || !res.ok) throw new Error('Gagal mengambil potongan ' + (i + 1) + ' dari ' + k.jumlah);
            bagian.push(await res.arrayBuffer());
            isi.style.width = Math.round((i + 1) / k.jumlah * 100) + '%';
        }
        const blob = new Blob(bagian, { type: k.mime });
        if (blob.size !== k.ukuran) throw new Error('Ukuran file tidak cocok, coba lagi.');
        const url = URL.createObjectURL(blob);

        if (k.lihat) {
            window.location.replace(url); // tampilkan PDF/gambar di tab ini
            return;
        }
        const a = Object.assign(document.createElement('a'), { href: url, download: k.nama });
        document.body.appendChild(a);
        a.click();
        judul.textContent = 'File terunduh ✓';
        status.innerHTML = 'Jika unduhan tidak mulai, <a href="' + url + '" download>klik di sini</a>. Tab ini boleh ditutup.';
    } catch (err) {
        judul.textContent = 'Gagal memuat file';
        status.textContent = err.message;
    }
})();
</script>
</body>
</html>
