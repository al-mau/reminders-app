#!/usr/bin/env bash
# Membuat ZIP versi Laragon dari kode yang sama dengan versi online (Vercel).
# Pakai: bash laragon/build.sh [folder_output]   -> menghasilkan reminders-app-laragon.zip
#
# Susunannya sama dengan versi XAMPP (Laragon juga memakai Apache, jadi .htaccess berlaku).
# Bedanya hanya file .bat: mencari PHP & MySQL di laragon\bin\..., bukan xampp\.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${1:-$ROOT}"
KERJA="$(mktemp -d)"
APP="$KERJA/reminders-app"
mkdir -p "$APP/lib" "$APP/database" "$APP/vendor"

cp "$ROOT"/api/*.php "$APP/"
cp "$ROOT"/api/lib/*.php "$APP/lib/"
cp "$ROOT"/vendor/* "$APP/vendor/"
cp "$ROOT"/sw.js "$ROOT"/manifest.json "$ROOT"/tema.css "$ROOT"/logo-brk-syariah.webp "$APP/"
cp "$ROOT"/database/{reminders_db.sql,schema.sql} "$APP/database/"
cp "$ROOT"/laragon/{import_database.bat,sync_dari_aiven.bat} "$APP/database/"
cp "$ROOT"/laragon/kirim_pengingat.bat "$ROOT"/laragon/CARA_PAKAI_LARAGON.txt "$APP/"
cp "$ROOT"/xampp/htaccess-root.txt "$APP/.htaccess"
cp "$ROOT"/xampp/htaccess-tertutup.txt "$APP/lib/.htaccess"
cp "$ROOT"/xampp/htaccess-tertutup.txt "$APP/database/.htaccess"

# Script salin & pengingat lokal sama dengan versi XAMPP, hanya teks petunjuknya disesuaikan
sed -e 's/XAMPP Control Panel/Laragon (Start All)/g' -e 's/MySQL XAMPP/MySQL Laragon/g' \
    -e 's/ke XAMPP/ke Laragon/g' -e 's/-> XAMPP/-> Laragon/g' -e 's/lokal XAMPP/lokal Laragon/g' \
    "$ROOT/database/sync_dari_aiven.php" > "$APP/database/sync_dari_aiven.php"
sed -e "s#VERSI XAMPP#VERSI LARAGON#; s#lewat PHP XAMPP#lewat PHP Laragon#" \
    "$ROOT/xampp/cron_lokal.php" > "$APP/cron_lokal.php"

# .env siap pakai (database = MySQL Laragon standar: root tanpa password)
RAHASIA="$(head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 24)"
sed -e "s/^CRON_SECRET=.*/CRON_SECRET=$RAHASIA/" -e "s/^APP_DEBUG=.*/APP_DEBUG=false/" \
    -e "s/^# Konfigurasi untuk menjalankan aplikasi di XAMPP (localhost)./# Konfigurasi untuk menjalankan aplikasi di Laragon (http:\/\/reminders-app.test)./" \
    -e "s/^# Copy file ini lalu ganti namanya menjadi .env/# File ini sudah siap pakai. Isi FONNTE_TOKEN agar bisa kirim WA./" \
    -e "s/(salin Aiven -> XAMPP)/(salin Aiven -> Laragon)/" \
    "$ROOT/.env.xampp.example" > "$APP/.env"

# File Windows (bat, txt, .env) memakai baris CRLF agar rapi dibuka di Notepad
for f in "$APP"/*.bat "$APP"/*.txt "$APP"/database/*.bat "$APP/.env"; do
    sed -i 's/\r$//; s/$/\r/' "$f"
done

rm -f "$OUT/reminders-app-laragon.zip"
(cd "$KERJA" && zip -qr "$OUT/reminders-app-laragon.zip" reminders-app)
rm -rf "$KERJA"
echo "Selesai: $OUT/reminders-app-laragon.zip"
