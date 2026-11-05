# Aplikasi Pengingat Hari Jadi

Pengingat otomatis hari jadi daerah pemegang saham (PHP + MySQL). Pengingat dikirim **H-7** lewat **WhatsApp (Fonnte), Telegram Bot, dan Email (SMTP)**.
Satu kode untuk dua versi: **online** (Vercel + Aiven + cron-job.org) dan **XAMPP** (laptop/PC + Task Scheduler).

- Data cukup diisi **sekali**: nama daerah + tanggal hari jadi (yang dipakai tanggal & bulannya, tahunnya bebas).
  Pengingat berulang setiap tahun pada tanggal yang sama.
- Data baru bisa ditambah kapan saja, satu per satu atau banyak sekaligus (tempel 2 kolom dari Excel). "Ekspor CSV" menghasilkan format yang sama.
- Jadwal terlewat (server/komputer mati di hari H-7)? Pengingat dikirim saat jadwal berikutnya jalan (rentang H-7 sampai hari H), tetap hanya sekali per tahun.

Panduan pemasangan: versi online [`PANDUAN_ONLINE.txt`](PANDUAN_ONLINE.txt), versi XAMPP [`xampp/CARA_PAKAI.txt`](xampp/CARA_PAKAI.txt).

## Struktur

| File | Fungsi |
|---|---|
| `api/index.php` | Arahkan ke login / dashboard |
| `api/login.php`, `api/logout.php` | Masuk & keluar (login dikunci 15 menit setelah 5x salah password) |
| `api/daftar.php` | Membuat akun admin pertama (hanya terbuka selama belum ada akun) |
| `api/dashboard.php` | Menu Hari Jadi (tambah, tambah banyak, edit, hapus, kirim manual ke semua saluran atau satu saluran saja, ekspor CSV), Penerima, Riwayat Kirim |
| `api/users.php` | Menu User: tambah akun, atur hak akses, setel password, hapus; "Akun saya" |
| `api/cron_pengingat.php` | Pengingat otomatis: URL `?key=CRON_SECRET` (cron-job.org) atau CLI (Task Scheduler / crontab) |
| `api/lib/koneksi.php` | Pembaca Environment Variables / `.env`, koneksi PDO (+ SSL Aiven), helper CSRF & escape |
| `api/lib/sesi.php` | Session login di tabel `sessions` (wajib di Vercel), cookie HttpOnly/SameSite/Secure, header keamanan |
| `api/lib/skema.php` | Pembuatan tabel otomatis + migrasi, riwayat kirim, pembatasan login |
| `api/lib/hari_jadi.php` | Logika tanggal tahunan (hari jadi berikutnya, sisa hari, 29 Februari) |
| `api/lib/pengingat.php` | Pemilihan data H-7, penyusunan pesan, penandaan sudah terkirim |
| `api/lib/notifikasi.php`, `api/lib/email.php` | Kirim WhatsApp (Fonnte), Telegram, Email (PHPMailer di `api/lib/phpmailer`) |
| `api/lib/peran.php`, `api/lib/tampilan.php` | Hak akses user; potongan tampilan bersama |
| `vercel.json` | Runtime `vercel-php`, blokir `lib/`, `database/`, `xampp/`, `.env`; `/nama.php` -> `api/nama.php` |
| `.env.example` | Daftar Environment Variables versi online |
| `database/schema.sql` | Struktur tabel (versi XAMPP; versi online membuat tabel otomatis) |
| `xampp/` | Bahan versi XAMPP: `bash xampp/build.sh` membuat `pengingat-hari-jadi-xampp.zip` (halaman di folder utama, `.bat` untuk database & Task Scheduler) |

Batas Vercel Hobby: maksimal 12 Serverless Functions; setiap `api/*.php` dihitung 1 (saat ini 7). File pembantu disimpan di `api/lib/` agar tidak dihitung.

## Cara kerja pengingat

Tabel `hari_jadi` menyimpan `tanggal` (yang dipakai tanggal & bulannya) dan `terakhir_diingatkan`.
Database yang dibuat versi sebelumnya masih punya kolom `hari_jadi_ke`; kolom itu tidak dipakai lagi dan boleh dibiarkan.
Setiap kali `cron_pengingat.php` jalan:

1. Untuk tiap daerah dihitung hari jadi berikutnya (tahun ini, atau tahun depan bila sudah lewat; 29 Februari di tahun biasa menjadi 28 Februari).
2. Daerah dipilih bila sisa harinya `0..PENGINGAT_HARI` **dan** `terakhir_diingatkan` belum sama dengan tanggal hari jadi tersebut.
3. Semua daerah terpilih digabung dalam satu pesan dan dikirim ke saluran di `NOTIF_VIA`.
4. Bila minimal satu saluran berhasil, `terakhir_diingatkan` diisi tanggal hari jadi itu. Tahun depan tanggalnya berbeda, sehingga pengingat terkirim lagi tanpa perlu input ulang.

Mengubah tanggal & bulan sebuah daerah menghapus tanda `terakhir_diingatkan`, sehingga tanggal baru ikut diingatkan.

## Hak akses user

| Hak akses | Bisa |
|---|---|
| Akses Penuh (`admin`) | Semua, termasuk hapus data, kelola penerima, kelola user |
| Akses Kerja (`staf`) | Tambah/edit hari jadi, kirim pengingat manual, jalankan pengingat |
| Akses Lihat (`pemantau`) | Hanya melihat |

## Pengaturan `.env`

Lihat [`.env.example`](.env.example). Yang wajib untuk mengirim: `FONNTE_TOKEN`, `TELEGRAM_BOT_TOKEN`, `SMTP_USER` + `SMTP_PASS` (App Password Gmail). `PENGINGAT_HARI` mengatur H-berapa (default 7).
