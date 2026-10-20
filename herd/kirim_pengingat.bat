@echo off
rem ==========================================================
rem  Kirim pengingat WA/Telegram otomatis (versi Laravel Herd).
rem  Sama dengan yang dilakukan cron-job.org pada versi online:
rem  mengirim unit yang deadline-nya HARI INI dan/atau BESOK, maks 1x per hari.
rem
rem  Argumen (boleh digabung, urutan bebas):
rem    hari_ini = hanya unit yang deadline-nya HARI INI  -> jadwalkan jam 08.00
rem    besok    = hanya unit yang deadline-nya BESOK (H-1) -> jadwalkan jam 20.00
rem    auto     = tanpa "Press any key" (untuk Task Scheduler)
rem  Tanpa hari_ini/besok = keduanya sekaligus (klik 2x untuk coba manual).
rem
rem  Daftarkan dua jadwal di Windows Task Scheduler (lihat CARA_PAKAI_HERD.txt):
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

rem Kosongkan untuk deteksi otomatis. Isi manual jika PHP tidak ditemukan,
rem contoh: set "PHP_MANUAL=C:\Users\NamaKamu\.config\herd\bin\php84\php.exe"
set "PHP_MANUAL="

set "PHP=%PHP_MANUAL%"
rem Deteksi otomatis: 1) PHP bawaan Herd, 2) perintah php di PATH
if not defined PHP for /d %%D in ("%USERPROFILE%\.config\herd\bin\php*") do if exist "%%~fD\php.exe" set "PHP=%%~fD\php.exe"
if not defined PHP for /f "delims=" %%P in ('where php 2^>nul') do if not defined PHP set "PHP=%%P"
if not defined PHP (
    echo [GAGAL] PHP Herd tidak ditemukan. Buka Herd, lalu isi baris set "PHP_MANUAL=" di file ini.
    goto selesai
)

rem Jalankan pengingat; hasil ditampilkan di layar dan ditambahkan ke pengingat_log.txt
call "%PHP%" "%~dp0cron_lokal.php" %JENIS% > "%~dp0pengingat_terakhir.txt" 2>&1
type "%~dp0pengingat_terakhir.txt"
echo [%date% %time%] jenis=%JENIS% >> "%~dp0pengingat_log.txt"
type "%~dp0pengingat_terakhir.txt" >> "%~dp0pengingat_log.txt"
echo.
echo "terkirim":N = jumlah unit yang dikirimi pengingat. "total":0 = tidak ada unit yang perlu diingatkan saat ini.

:selesai
if not defined AUTO pause
