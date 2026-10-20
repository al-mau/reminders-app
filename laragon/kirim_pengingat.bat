@echo off
rem ==========================================================
rem  Kirim pengingat WA/Telegram otomatis (versi LARAGON).
rem  Sama dengan yang dilakukan cron-job.org pada versi online:
rem  mengirim unit yang deadline-nya HARI INI dan/atau BESOK, maks 1x per hari.
rem
rem  Argumen (boleh digabung, urutan bebas):
rem    hari_ini = hanya unit yang deadline-nya HARI INI  -> jadwalkan jam 08.00
rem    besok    = hanya unit yang deadline-nya BESOK (H-1) -> jadwalkan jam 20.00
rem    auto     = tanpa "Press any key" (untuk Task Scheduler)
rem  Tanpa hari_ini/besok = keduanya sekaligus (klik 2x untuk coba manual).
rem
rem  Daftarkan dua jadwal di Windows Task Scheduler (lihat CARA_PAKAI_LARAGON.txt):
rem    jam 08.00 -> argumen: auto hari_ini
rem    jam 20.00 -> argumen: auto besok
rem  Hasil setiap pengiriman dicatat di pengingat_log.txt.
rem ==========================================================
title Kirim Pengingat

set "JENIS="
set "AUTO="
for %%A in (%*) do (
    if /i "%%~A"=="auto" set "AUTO=1"
    if /i "%%~A"=="hari_ini" set "JENIS=hari_ini"
    if /i "%%~A"=="besok" set "JENIS=besok"
)

rem --- Lokasi Laragon ---
rem Kosongkan untuk deteksi otomatis. Isi manual jika Laragon di folder lain,
rem contoh: set "LARAGON_MANUAL=D:\laragon"
set "LARAGON_MANUAL="

set "LARAGON=%LARAGON_MANUAL%"
rem Deteksi otomatis: 1) project ada di laragon\www, 2) C:\laragon, D:\laragon, E:\laragon, F:\laragon
if not defined LARAGON for %%I in ("%~dp0..\..") do if exist "%%~fI\bin\php" set "LARAGON=%%~fI"
if not defined LARAGON for %%D in (C D E F) do if not defined LARAGON if exist "%%D:\laragon\bin\php" set "LARAGON=%%D:\laragon"
if not defined LARAGON (
    echo [GAGAL] Folder Laragon tidak ditemukan. Isi baris set "LARAGON_MANUAL=" dengan lokasi Laragon, contoh C:\laragon
    goto selesai
)

rem PHP Laragon ada di bin\php\php-8.x.x-...\php.exe (jika ada beberapa versi, dipakai yang terakhir/terbaru)
set "PHP="
for /d %%P in ("%LARAGON%\bin\php\php-*") do if exist "%%~fP\php.exe" set "PHP=%%~fP\php.exe"
if not defined PHP (
    echo [GAGAL] php.exe tidak ditemukan di %LARAGON%\bin\php
    goto selesai
)

rem Jalankan pengingat; hasil ditampilkan di layar dan ditambahkan ke pengingat_log.txt
"%PHP%" "%~dp0cron_lokal.php" %JENIS% > "%~dp0pengingat_terakhir.txt" 2>&1
type "%~dp0pengingat_terakhir.txt"
echo [%date% %time%] jenis=%JENIS% >> "%~dp0pengingat_log.txt"
type "%~dp0pengingat_terakhir.txt" >> "%~dp0pengingat_log.txt"
echo.
echo "terkirim":N = jumlah unit yang dikirimi pengingat. "total":0 = tidak ada unit yang perlu diingatkan saat ini.

:selesai
if not defined AUTO pause
