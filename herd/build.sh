#!/usr/bin/env bash
# Membuat ZIP versi Laravel Herd dari kode yang sama dengan versi online (Vercel).
# Pakai: bash herd/build.sh [folder_output]   -> menghasilkan reminders-app-herd.zip
#
# Herd memakai nginx (bukan Apache), jadi .htaccess TIDAK berlaku.
# Agar .env, lib/, dan database/ tidak bisa dibuka dari browser, hanya isi folder
# public/ yang dilayani Herd (Herd otomatis memakai public/ bila ada public/index.php):
#   reminders-app/
#     public/    -> halaman aplikasi (*.php), tema.css, sw.js, manifest.json, vendor/
#     lib/       -> kode bantu (koneksi, fonnte, sesi, skema)
#     database/  -> file SQL + bat import/sync (memakai MySQL XAMPP)
#     .env, cron_lokal.php, kirim_pengingat.bat, CARA_PAKAI_HERD.txt
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${1:-$ROOT}"
KERJA="$(mktemp -d)"
APP="$KERJA/reminders-app"
mkdir -p "$APP/public/vendor" "$APP/lib" "$APP/database"

cp "$ROOT"/api/*.php "$APP/public/"
cp "$ROOT"/vendor/* "$APP/public/vendor/"
cp "$ROOT"/sw.js "$ROOT"/manifest.json "$ROOT"/tema.css "$APP/public/"
cp "$ROOT"/api/lib/*.php "$APP/lib/"
cp "$ROOT"/database/{reminders_db.sql,schema.sql,import_ke_xampp.bat,sync_dari_aiven.bat,sync_dari_aiven.php,CARA_PAKAI.txt} "$APP/database/"
cp "$ROOT"/herd/kirim_pengingat.bat "$ROOT"/herd/CARA_PAKAI_HERD.txt "$APP/"

# Halaman di public/ memanggil lib/ yang sekarang satu folder di atasnya
sed -i "s#__DIR__ . '/lib/#__DIR__ . '/../lib/#g" "$APP"/public/*.php
# Pengingat lokal: lib/ di folder yang sama, cron_wa_reminder.php ada di public/
sed -e "s#__DIR__ . '/cron_wa_reminder.php'#__DIR__ . '/public/cron_wa_reminder.php'#" \
    -e "s#VERSI XAMPP#VERSI HERD#; s#lewat PHP XAMPP#lewat PHP Herd#" \
    "$ROOT/xampp/cron_lokal.php" > "$APP/cron_lokal.php"

# .env siap pakai (database = MySQL XAMPP standar: root tanpa password)
RAHASIA="$(head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 24)"
sed -e "s/^CRON_SECRET=.*/CRON_SECRET=$RAHASIA/" -e "s/^APP_DEBUG=.*/APP_DEBUG=false/" \
    -e "s/^# Konfigurasi untuk menjalankan aplikasi di XAMPP (localhost)./# Konfigurasi untuk menjalankan aplikasi di Laravel Herd (http:\/\/reminders-app.test)./" \
    -e "s/^# Copy file ini lalu ganti namanya menjadi .env/# File ini sudah siap pakai. Isi FONNTE_TOKEN agar bisa kirim WA./" \
    "$ROOT/.env.xampp.example" > "$APP/.env"

# File Windows (bat, txt, .env) memakai baris CRLF agar rapi dibuka di Notepad
for f in "$APP"/*.bat "$APP"/*.txt "$APP"/database/*.bat "$APP"/database/*.txt "$APP/.env"; do
    sed -i 's/\r$//; s/$/\r/' "$f"
done

rm -f "$OUT/reminders-app-herd.zip"
(cd "$KERJA" && zip -qr "$OUT/reminders-app-herd.zip" reminders-app)
rm -rf "$KERJA"
echo "Selesai: $OUT/reminders-app-herd.zip"
