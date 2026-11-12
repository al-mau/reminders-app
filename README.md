# Aplikasi Pengingat Jadwal

Aplikasi pengingat deadline unit (PHP + MySQL Aiven) dengan notifikasi WhatsApp via Fonnte, di-deploy ke Vercel (`vercel-php`).

## Struktur

| File | Fungsi |
|---|---|
| `api/index.php` | Redirect `/` ke login / dashboard |
| `api/login.php`, `api/register.php`, `api/logout.php` | Autentikasi (halaman Daftar publik default ditutup, buka dengan `ALLOW_REGISTER=true`) |
| `api/users.php` | Menu User: tambah user, atur hak akses, ubah akun (username, nama, password), hapus akun |
| `api/dashboard.php` | CRUD deadline, statistik, filter, kirim WA manual, lampiran, penerima WA, riwayat WA |
| `api/lampiran.php` | Unduh / lihat lampiran (khusus user yang sudah login) |
| `api/cron_wa_reminder.php` | Pengingat otomatis H-1 & hari-H (dipanggil cron-job.org) |
| `api/lib/koneksi.php` | Koneksi PDO + helper (CSRF, escape) |
| `api/lib/session_handler.php` | Session disimpan di tabel `sessions` (wajib di serverless) |
| `api/lib/fonnte.php` | Helper kirim WhatsApp |
| `api/lib/skema.php` | Tabel tambahan + helper lampiran, riwayat WA, pembatasan login |
| `api/lib/peran.php` | Hak akses user (Akses Penuh, Akses Kerja, Akses Lihat) dan pengecekannya (lihat [Hak akses user](#hak-akses-user)) |
| `api/setup.php` | Membuat tabel database otomatis (sekali jalan) |
| `database/schema.sql` | Skema tabel `users`, `deadline`, `sessions` |
| `database/sync_dari_aiven.*` | Salin data Aiven ke MySQL XAMPP (backup lokal) |
| `vercel.json` | Pengaturan Vercel (lihat penjelasan di bawah) |
| `sw.js`, `manifest.json` | PWA: aplikasi bisa di-"Install" di HP/laptop |
| `xampp/` | Bahan versi XAMPP: `bash xampp/build.sh` membuat `reminders-app-xampp.zip` (lihat `xampp/CARA_PAKAI_XAMPP.txt`) |
| `herd/` | Bahan versi Laravel Herd: `bash herd/build.sh` membuat `reminders-app-herd.zip` (halaman di `public/`, database tetap MySQL XAMPP; lihat `herd/CARA_PAKAI_HERD.txt`) |
| `laragon/` | Bahan versi Laragon: `bash laragon/build.sh` membuat `reminders-app-laragon.zip` (susunan sama dengan XAMPP, file `.bat` mencari PHP & MySQL di `laragon\bin`; lihat `laragon/CARA_PAKAI_LARAGON.txt`) |
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
   `https://<domain-vercel>/cron_wa_reminder.php?key=<CRON_SECRET>` (misal setiap hari 07:00 WIB).
4. **Lokal (XAMPP)** — import database lewat `database/import_ke_xampp.bat` (atau phpMyAdmin → Import `database/reminders_db.sql`), salin `.env.xampp.example` menjadi `.env`, lalu buka `http://localhost/reminders-app/api/login.php`. Detail: `database/CARA_PAKAI.txt`.

> Kredensial tidak boleh ditulis di kode. File `.env` sudah masuk `.gitignore`.

> **Batas Vercel Hobby: maksimal 12 Serverless Functions per deployment.** Setiap file `.php` langsung di `api/` dihitung 1 function. File pembantu (bukan halaman) simpan di `api/lib/` agar tidak ikut dihitung.

## Hak akses user

Setiap akun punya satu hak akses. Hak akses diatur oleh akun **Akses Penuh** di menu **User** (saat menambah user atau lewat **Ubah akun**) dan langsung berlaku tanpa login ulang.

| Fitur | Akses Penuh | Akses Kerja | Akses Lihat |
|---|:---:|:---:|:---:|
| Lihat Daftar Unit, lampiran, Penerima, Riwayat Kirim | ✓ | ✓ | ✓ |
| Tambah & edit unit (termasuk perpanjang deadline) | ✓ | ✓ | – |
| Tandai selesai / batalkan | ✓ | ✓ | – |
| Kirim pengingat sekarang (WhatsApp / Telegram) | ✓ | ✓ | – |
| Unggah lampiran | ✓ | ✓ | – |
| Hapus unit & lampiran | ✓ | – | – |
| Kelola penerima WhatsApp & Telegram | ✓ | – | – |
| Kelola user & atur hak akses | ✓ | – | – |
| Ubah nama, username, password akun sendiri | ✓ | ✓ | ✓ |

- **Akses Penuh** — untuk pengelola aplikasi. Memegang hak yang berisiko besar: menghapus data (tidak bisa dibatalkan), menentukan ke mana pengingat dikirim, dan mengatur siapa yang boleh masuk.
- **Akses Kerja** — untuk pemantauan harian. Cukup untuk memantau dan menindaklanjuti unit, tetapi tidak bisa menghapus data, mengubah penerima, atau mengelola user.
- **Akses Lihat** — untuk yang cukup melihat, misalnya atasan atau auditor. Tidak bisa mengubah apa pun.

Catatan:
- Hak dicek di server untuk setiap aksi (bukan hanya tombol yang disembunyikan); aksi yang belum terdaftar di `api/lib/peran.php` hanya untuk Akses Penuh.
- Akses Kerja & Akses Lihat melihat menu **Akun saya** (bukan User) dan tidak bisa mengubah hak aksesnya sendiri.
- Selalu ada minimal satu akun Akses Penuh: akun Akses Penuh terakhir tidak bisa dihapus atau diturunkan.
- Saat pertama kali dipasang, akun yang sudah ada otomatis mendapat Akses Penuh. Pendaftaran publik (`ALLOW_REGISTER=true`) memberi Akses Lihat, kecuali akun pertama yang mendapat Akses Penuh.
- Di database (kolom `users.peran`) kodenya tetap `admin` (Akses Penuh), `staf` (Akses Kerja), dan `pemantau` (Akses Lihat).

## Notifikasi Telegram (opsional, gratis)

Selain WhatsApp (Fonnte), pengingat bisa dikirim lewat Telegram Bot:

| Variabel | Isi |
|---|---|
| `NOTIF_VIA` | Saluran dipisah koma: `wa` (default), `telegram`, `email`. Contoh `wa,telegram,email` |
| `TELEGRAM_BOT_TOKEN` | Token bot dari @BotFather |
| `TELEGRAM_CHAT_ID` | Opsional. Chat id cadangan jika belum ada penerima Telegram di dashboard (kartu **Penerima Telegram**); grup diawali `-`, bisa lebih dari 1 dipisah koma |

Riwayat pengiriman Telegram tampil di kartu Riwayat Pengiriman WA dengan ikon Telegram.

## Notifikasi Email (opsional, SMTP + PHPMailer)

Pengingat juga bisa dikirim lewat email memakai library [PHPMailer](https://github.com/PHPMailer/PHPMailer) 6.12.0
(disalin di `api/lib/phpmailer`, tanpa Composer). Penerima email diatur di menu **Penerima → Email**,
sama seperti WhatsApp dan Telegram. Tombol **Kirim email percobaan** di panel itu dipakai untuk mengecek pengaturan SMTP.

| Variabel | Isi |
|---|---|
| `SMTP_HOST` | Server SMTP, contoh `smtp.gmail.com` |
| `SMTP_PORT` | `587` (STARTTLS, default) atau `465` (SSL) |
| `SMTP_USER` | Username SMTP. Gmail: alamat Gmail lengkap |
| `SMTP_PASS` | Password SMTP. Gmail: **App Password** (wajib Verifikasi 2 Langkah), bukan password Gmail biasa |
| `EMAIL_PENGIRIM` | Opsional. Alamat pengirim, default sama dengan `SMTP_USER` |
| `EMAIL_NAMA_PENGIRIM` | Opsional. Nama pengirim, default `Pengingat Deadline` |
| `EMAIL_TARGET` | Opsional. Alamat cadangan jika belum ada penerima Email di dashboard, bisa lebih dari 1 dipisah koma |

Tambahkan `email` di `NOTIF_VIA` agar pengingat otomatis jam 07.00 juga dikirim lewat email.
Batas Gmail sekitar 500 email/hari; untuk pemakaian resmi sebaiknya memakai SMTP kantor agar tidak masuk Spam.

## Folder OneDrive untuk upload manual (opsional)

Menu **Penerima → Penyimpanan OneDrive** (Akses Penuh): tempel link folder OneDrive/SharePoint
(*Bagikan → Salin link*) → **Simpan link**. Di jendela **Lampiran** setiap unit muncul tombol **Buka folder OneDrive**
beserta saran nama subfolder (`<kode unit> - <nama unit>`). Pengguna mengunggah file sendiri di OneDrive (tombol
*Unggah* atau seret file). Aplikasi tidak mengirim file apa pun; yang membuka folder harus punya izin di OneDrive.
Link juga bisa diisi lewat Environment Variable `ONEDRIVE_FOLDER_LINK`.

## Arsip lampiran lewat Power Automate (opsional, tanpa pendaftaran aplikasi)

Cara termudah agar setiap lampiran otomatis masuk ke folder OneDrive kantor tanpa meminta Client ID ke tim IT.

**Cara kerja**

```
Upload lampiran di aplikasi
  → aplikasi mengirim email (SMTP yang sama dengan pengingat) ke alamat arsip, contoh corsec@brksyariah.co.id
      Subjek : [ARSIP-LAMPIRAN] <kode unit> - <nama unit>
      Lampiran: file yang diunggah (satu email per file)
  → alur Power Automate milik akun tersebut menangkap email bersubjek [ARSIP-LAMPIRAN]
  → attachment disimpan ke <folder tujuan>/<kode unit> - <nama unit>/<tanggal> - <nama file>
```

Status tiap file tampil di jendela Lampiran: *Dikirim ke arsip OneDrive* atau *Belum masuk arsip · dicoba lagi otomatis*
(bila SMTP gagal; cron jam 07.00 mengulang maks. 5 kali). Aplikasi hanya tahu email sudah terkirim — riwayat penyimpanan
ada di Power Automate (*My flows → nama alur → Run history*).

**1. Aktifkan di aplikasi** — menu **Penerima → Penyimpanan OneDrive** (Akses Penuh): isi alamat email arsip → **Simpan**.
Pengaturan SMTP (bagian Notifikasi Email) harus sudah benar. Alamat juga bisa diisi lewat Environment Variable `ARSIP_EMAIL`.
Lampiran yang diunggah sebelum fitur aktif tidak dikirim otomatis; klik **Kirim lampiran yang belum diarsipkan** bila ingin
mengarsipkannya juga (15 file per klik).

**2. Buat alur di Power Automate** (sekali, ±10 menit, login [make.powerautomate.com](https://make.powerautomate.com) dengan
akun alamat arsip, contoh akun Corsec)

1. **+ Create → Automated cloud flow**. Nama: `Arsip Lampiran Pengingat Deadline`. Pemicu: **When a new email arrives (V3)**
   (Office 365 Outlook) → **Create**.
2. Klik pemicu, buka *Advanced parameters / Show all*, isi:
   - *Folder*: `Inbox`
   - *From*: alamat pengirim aplikasi (`EMAIL_PENGIRIM` atau `SMTP_USER`) — agar hanya email dari aplikasi yang diproses
   - *Subject Filter*: `[ARSIP-LAMPIRAN]`
   - *Include Attachments*: `Yes` · *Only with Attachments*: `Yes`
3. **+ New step / +** → **Create file** (OneDrive for Business). Power Automate otomatis membungkusnya dengan
   **Apply to each** atas *Attachments* (jika tidak, tambahkan *Apply to each* dengan isi *Attachments* lalu pindahkan
   *Create file* ke dalamnya). Isi *Create file*:
   - *Folder Path*: klik ikon folder, pilih folder tujuan (folder dari link berbagi, contoh `/Arsip Lampiran Unit`),
     lalu di belakangnya tambahkan `/` dan **Expression**:
     `trim(replace(triggerOutputs()?['body/subject'], '[ARSIP-LAMPIRAN]', ''))`
     → hasilnya `/Arsip Lampiran Unit/KC001 - KC Pekanbaru` (subfolder dibuat otomatis).
   - *File Name*: **Expression**
     `concat(formatDateTime(convertFromUtc(utcNow(), 'SE Asia Standard Time'), 'yyyyMMdd-HHmmss'), ' - ', items('Apply_to_each')?['name'])`
     (tanggal di depan mencegah file bernama sama saling bentrok; ganti `Apply_to_each` bila nama perulangan berbeda,
     contoh `For_each`).
   - *File Content*: dari *Dynamic content* pilih **Attachments Content**.
4. Opsional, agar kotak masuk tetap rapi: setelah *Apply to each* tambahkan **Move email (V2)** (*Message Id* dari pemicu,
   *Folder*: buat folder `Arsip Lampiran` di Outlook).
5. **Save**. Kembali ke aplikasi → **Kirim email uji**. Dalam 1–5 menit harus muncul subfolder `UJI - Email Uji Arsip`
   berisi `uji-arsip.txt` (folder uji boleh dihapus). Bila tidak muncul: cek folder *Junk* Outlook (tandai pengirim
   sebagai *Safe sender*) dan *Run history* alur.

Konektor Office 365 Outlook dan OneDrive for Business termasuk konektor standar Microsoft 365 (tanpa lisensi Premium).
Ikuti kebijakan TI kantor bila pemakaian Power Automate dibatasi. Bila akun Microsoft dihubungkan langsung
(bagian berikut), cara langsung itu yang dipakai dan email arsip tidak dikirim.

## Salinan lampiran ke OneDrive (opsional)

Setiap lampiran yang diunggah otomatis disalin ke folder OneDrive/SharePoint:
`<folder tujuan>/<kode unit> - <nama unit>/<nama file>`. Lampiran tetap tersimpan di aplikasi; OneDrive adalah arsip.
Bila penyalinan gagal (jaringan, token kedaluwarsa), status lampiran menjadi *gagal* dan dicoba lagi otomatis oleh cron
jam 07.00 (maks. 5 kali), atau lewat tombol **Salin lampiran yang belum tersimpan**. Menghapus lampiran di aplikasi
tidak menghapus salinannya di OneDrive.

**1. Daftarkan aplikasi (sekali, di [entra.microsoft.com](https://entra.microsoft.com) — untuk akun kantor biasanya oleh tim IT)**

1. *Identity → Applications → App registrations → New registration*. Nama bebas, contoh "Aplikasi Pengingat Deadline".
   *Supported account types*: **Accounts in this organizational directory only** (akun kantor). Redirect URI dikosongkan.
2. *Authentication* → **Allow public client flows = Yes** → Save.
3. *API permissions → Add a permission → Microsoft Graph → Delegated*: `Files.ReadWrite`, `User.Read`, `offline_access`.
   Bila tenant kantor mewajibkan, admin klik **Grant admin consent**.
4. Salin **Application (client) ID** dan **Directory (tenant) ID** / domain kantor.

Tidak ada *client secret* yang dibutuhkan.

**2. Isi Environment Variables** (Vercel: *Settings → Environment Variables*, XAMPP: file `.env`)

| Variabel | Isi |
|---|---|
| `ONEDRIVE_CLIENT_ID` | Application (client) ID dari langkah 1 |
| `ONEDRIVE_TENANT` | Domain kantor (contoh `brksyariah.co.id`) atau Directory (tenant) ID. Akun pribadi: `consumers` |
| `ONEDRIVE_FOLDER_LINK` | Opsional. Link folder tujuan; bisa juga ditempel di dashboard |
| `ONEDRIVE_IZIN` | Kosongkan. Isi `semua` hanya bila akun yang login **bukan** pemilik folder (meminta `Files.ReadWrite.All`) |
| `ONEDRIVE_FOLDER` | Opsional. Folder di OneDrive akun yang login bila belum ada link, default `Aplikasi Pengingat Deadline/Lampiran` |

**3. Hubungkan (sekali, akun Akses Penuh → menu Penerima → kartu Penyimpanan OneDrive)**

1. Klik **Hubungkan OneDrive** → buka `microsoft.com/devicelogin` → masukkan kode yang tampil → login dengan akun
   pemilik folder (contoh akun Corsec) → *Accept*.
2. Klik **Saya sudah login**.
3. Di OneDrive buka folder tujuan → *Bagikan → Salin link* → tempel di kolom **Tempel link folder OneDrive tujuan** →
   **Simpan folder**. Aplikasi mengubah link menjadi ID drive + ID folder dan menampilkan nama foldernya.

Password Microsoft tidak pernah dimasukkan ke aplikasi; yang disimpan hanya *refresh token* terenkripsi (AES-256-GCM,
kunci dari `APP_KEY`/`CRON_SECRET`) di tabel `pengaturan`. Akses bisa dicabut dengan tombol **Putuskan** atau dari
halaman keamanan akun Microsoft. Untuk dokumen dinas, gunakan akun Microsoft 365 kantor dengan persetujuan tim IT.
