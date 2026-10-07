# Aplikasi Pengingat Jadwal

Aplikasi pengingat deadline unit (PHP + MySQL Aiven) dengan notifikasi WhatsApp via Fonnte, di-deploy ke Vercel (`vercel-php`).

## Struktur

| File | Fungsi |
|---|---|
| `api/index.php` | Redirect `/` ke login / dashboard |
| `api/login.php`, `api/register.php`, `api/logout.php` | Autentikasi (halaman Daftar publik default ditutup, buka dengan `ALLOW_REGISTER=true`) |
| `api/users.php` | Kelola User: tambah admin, ganti password, hapus akun |
| `api/dashboard.php` | CRUD deadline, statistik, filter, kirim WA manual, lampiran, penerima WA, riwayat WA |
| `api/lampiran.php` | Unduh / lihat lampiran (khusus user yang sudah login) |
| `api/cron_wa_reminder.php` | Pengingat otomatis H-1 & hari-H (dipanggil cron-job.org) |
| `api/lib/koneksi.php` | Koneksi PDO + helper (CSRF, escape) |
| `api/lib/session_handler.php` | Session disimpan di tabel `sessions` (wajib di serverless) |
| `api/lib/fonnte.php` | Helper kirim WhatsApp |
| `api/lib/skema.php` | Tabel tambahan + helper lampiran, riwayat WA, pembatasan login |
| `api/setup.php` | Membuat tabel database otomatis (sekali jalan) |
| `database/schema.sql` | Skema tabel `users`, `deadline`, `sessions` |
| `database/sync_dari_aiven.*` | Salin data Aiven ke MySQL XAMPP (backup lokal) |
| `vercel.json` | Pengaturan Vercel (lihat penjelasan di bawah) |
| `sw.js`, `manifest.json` | PWA: aplikasi bisa di-"Install" di HP/laptop |
| `xampp/` | Bahan versi XAMPP: `bash xampp/build.sh` membuat `reminders-app-xampp.zip` (lihat `xampp/CARA_PAKAI_XAMPP.txt`) |
| `herd/` | Bahan versi Laravel Herd: `bash herd/build.sh` membuat `reminders-app-herd.zip` (halaman di `public/`, database tetap MySQL XAMPP; lihat `herd/CARA_PAKAI_HERD.txt`) |
| `vendor/` | Pratinjau lampiran tanpa unduh: `pratinjau.html` + library Word (mammoth) & Excel (SheetJS) |

### Penjelasan `vercel.json`

File JSON tidak bisa diberi komentar, jadi penjelasannya ditulis di sini:

- `functions` -> semua `api/*.php` dijalankan dengan PHP (`vercel-php`). Paket Hobby
  maksimal 12 file di `api/`, karena itu file bantu ditaruh di `api/lib/` (tidak dihitung).
- `routes` (dibaca berurutan dari atas):
  1. File bantu di `api/lib/` -> **404** (tidak boleh dibuka langsung dari browser).
  2. `.env` dan folder `database/` -> **404** (berisi password / data).
  3. `filesystem` -> file statis (`sw.js`, `manifest.json`) dilayani apa adanya.
  4. `/` -> `api/index.php`.
  5. `/nama.php` -> `api/nama.php`, sehingga URL cukup `/login.php` tanpa `/api/`.

## Setup

1. **Database** — setelah deploy, buka `https://<domain-vercel>/setup.php?key=<CRON_SECRET>` sekali (atau jalankan `database/schema.sql` manual).
2. **Environment Variables** — di Vercel: *Project → Settings → Environment Variables*, isi semua variabel dari `.env.example`
   (`DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `FONNTE_TOKEN`, `WA_TARGET`, `CRON_SECRET`). Lalu **Redeploy**.
3. **Cron** — di cron-job.org set URL:
   `https://<domain-vercel>/cron_wa_reminder.php?key=<CRON_SECRET>` (misal setiap hari 08:00 WIB).
4. **Lokal (XAMPP)** — import database lewat `database/import_ke_xampp.bat` (atau phpMyAdmin → Import `database/reminders_db.sql`), salin `.env.xampp.example` menjadi `.env`, lalu buka `http://localhost/reminders-app/api/login.php`. Detail: `database/CARA_PAKAI.txt`.

> Kredensial tidak boleh ditulis di kode. File `.env` sudah masuk `.gitignore`.

> **Batas Vercel Hobby: maksimal 12 Serverless Functions per deployment.** Setiap file `.php` langsung di `api/` dihitung 1 function. File pembantu (bukan halaman) simpan di `api/lib/` agar tidak ikut dihitung.

## Notifikasi Telegram (opsional, gratis)

Selain WhatsApp (Fonnte), pengingat bisa dikirim lewat Telegram Bot:

| Variabel | Isi |
|---|---|
| `NOTIF_VIA` | `wa` (default), `telegram`, atau `wa,telegram` |
| `TELEGRAM_BOT_TOKEN` | Token bot dari @BotFather |
| `TELEGRAM_CHAT_ID` | Opsional. Chat id cadangan jika belum ada penerima Telegram di dashboard (kartu **Penerima Telegram**); grup diawali `-`, bisa lebih dari 1 dipisah koma |

Riwayat pengiriman Telegram tampil di kartu Riwayat Pengiriman WA dengan ikon Telegram.
