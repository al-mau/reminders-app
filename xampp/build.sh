#!/usr/bin/env bash
# Membuat ZIP versi XAMPP dari kode yang sama dengan versi online (Vercel).
# Pakai: bash xampp/build.sh [folder_output]   -> menghasilkan pengingat-hari-jadi-xampp.zip
#
# Bedanya dengan versi online:
#  - file api/*.php dipindah ke folder utama, api/lib -> lib (tanpa vercel.json)
#  - .htaccess memblokir lib/, database/, .env dari browser
#  - .env.example berisi pengaturan MySQL XAMPP
#  - kirim_pengingat.bat + pasang_jadwal.bat (Task Scheduler) menggantikan cron-job.org
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${1:-$ROOT}"
KERJA="$(mktemp -d)"
APP="$KERJA/pengingat-hari-jadi"
mkdir -p "$APP/lib" "$APP/database"

cp "$ROOT"/api/*.php "$APP/"
cp "$ROOT"/api/lib/*.php "$APP/lib/"
cp -r "$ROOT"/api/lib/phpmailer "$APP/lib/"
cp "$ROOT"/tema.css "$ROOT"/manifest.json "$ROOT"/logo-brk-syariah.webp "$APP/"
cp "$ROOT"/database/schema.sql "$ROOT"/xampp/import_database.bat "$APP/database/"
cp "$ROOT"/xampp/kirim_pengingat.bat "$ROOT"/xampp/pasang_jadwal.bat "$ROOT"/xampp/CARA_PAKAI.txt "$APP/"
cp "$ROOT"/xampp/env.xampp.example "$APP/.env.example"
cp "$ROOT"/xampp/htaccess-root.txt "$APP/.htaccess"
cp "$ROOT"/xampp/htaccess-tertutup.txt "$APP/lib/.htaccess"
cp "$ROOT"/xampp/htaccess-tertutup.txt "$APP/database/.htaccess"

# File Windows (bat, txt, .env.example) memakai baris CRLF agar rapi dibuka di Notepad
for f in "$APP"/*.bat "$APP"/*.txt "$APP"/database/*.bat "$APP/.env.example"; do
    sed -i 's/\r$//; s/$/\r/' "$f"
done

rm -f "$OUT/pengingat-hari-jadi-xampp.zip"
(cd "$KERJA" && zip -qr "$OUT/pengingat-hari-jadi-xampp.zip" pengingat-hari-jadi)
rm -rf "$KERJA"
echo "Selesai: $OUT/pengingat-hari-jadi-xampp.zip"
