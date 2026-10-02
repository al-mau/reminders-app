#!/usr/bin/env bash
# Membuat ZIP versi XAMPP dari kode yang sama dengan versi online (Vercel).
# Pakai: bash xampp/build.sh [folder_output]   -> menghasilkan reminders-app-xampp.zip
#
# Bedanya dengan versi Vercel:
#  - file api/*.php dipindah ke folder utama, api/lib -> lib (tanpa vercel.json)
#  - .htaccess memblokir lib/, database/, .env dari browser
#  - .env sudah terisi untuk MySQL XAMPP (CRON_SECRET dibuat acak)
#  - kirim_pengingat.bat + cron_lokal.php menggantikan cron-job.org
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${1:-$ROOT}"
KERJA="$(mktemp -d)"
APP="$KERJA/reminders-app"
mkdir -p "$APP/lib" "$APP/database" "$APP/vendor"

cp "$ROOT"/api/*.php "$APP/"
cp "$ROOT"/api/lib/*.php "$APP/lib/"
cp "$ROOT"/vendor/* "$APP/vendor/"
cp "$ROOT"/sw.js "$ROOT"/manifest.json "$APP/"
cp "$ROOT"/database/{reminders_db.sql,schema.sql,import_ke_xampp.bat,sync_dari_aiven.bat,sync_dari_aiven.php,CARA_PAKAI.txt} "$APP/database/"
cp "$ROOT"/xampp/cron_lokal.php "$ROOT"/xampp/kirim_pengingat.bat "$ROOT"/xampp/CARA_PAKAI_XAMPP.txt "$APP/"
cp "$ROOT"/xampp/htaccess-root.txt "$APP/.htaccess"
cp "$ROOT"/xampp/htaccess-tertutup.txt "$APP/lib/.htaccess"
cp "$ROOT"/xampp/htaccess-tertutup.txt "$APP/database/.htaccess"

# .env siap pakai untuk XAMPP
RAHASIA="$(head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 24)"
sed -e "s/^CRON_SECRET=.*/CRON_SECRET=$RAHASIA/" -e "s/^APP_DEBUG=.*/APP_DEBUG=false/" \
    -e "s/^# Copy file ini lalu ganti namanya menjadi .env/# File ini sudah siap pakai. Isi FONNTE_TOKEN agar bisa kirim WA./" \
    "$ROOT/.env.xampp.example" > "$APP/.env"

# File Windows (bat, txt, .env) memakai baris CRLF agar rapi dibuka di Notepad
for f in "$APP"/*.bat "$APP"/*.txt "$APP"/database/*.bat "$APP"/database/*.txt "$APP/.env"; do
    sed -i 's/\r$//; s/$/\r/' "$f"
done

rm -f "$OUT/reminders-app-xampp.zip"
(cd "$KERJA" && zip -qr "$OUT/reminders-app-xampp.zip" reminders-app)
rm -rf "$KERJA"
echo "Selesai: $OUT/reminders-app-xampp.zip"
