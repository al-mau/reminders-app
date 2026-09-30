# Reminders App

Aplikasi pengingat deadline unit (PHP + MySQL Aiven) dengan notifikasi WhatsApp via Fonnte, di-deploy ke Vercel (`vercel-php`).

## Struktur

| File | Fungsi |
|---|---|
| `api/index.php` | Redirect `/` ke login / dashboard |
| `api/login.php`, `api/register.php`, `api/logout.php` | Autentikasi |
| `api/dashboard.php` | CRUD deadline, statistik, filter, kirim WA manual |
| `api/cron_wa_reminder.php` | Pengingat otomatis H-1 & hari-H (dipanggil cron-job.org) |
| `api/koneksi.php` | Koneksi PDO + helper (CSRF, escape) |
| `api/session_handler.php` | Session disimpan di tabel `sessions` (wajib di serverless) |
| `api/fonnte.php` | Helper kirim WhatsApp |
| `database/schema.sql` | Skema tabel `users`, `deadline`, `sessions` |

## Setup

1. **Database** — jalankan `database/schema.sql` di Aiven (`defaultdb`), misalnya lewat DBeaver/HeidiSQL/MySQL Workbench.
2. **Environment Variables** — di Vercel: *Project → Settings → Environment Variables*, isi semua variabel dari `.env.example`
   (`DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `FONNTE_TOKEN`, `WA_TARGET`, `CRON_SECRET`). Lalu **Redeploy**.
3. **Cron** — di cron-job.org set URL:
   `https://<domain-vercel>/cron_wa_reminder.php?key=<CRON_SECRET>` (misal setiap hari 08:00 WIB).
4. **Lokal (XAMPP)** — salin `.env.example` menjadi `.env`, isi nilainya, lalu buka `http://localhost/reminders-app/api/login.php`.

> Kredensial tidak boleh ditulis di kode. File `.env` sudah masuk `.gitignore`.
