@echo off
rem ==========================================================
rem  Kirim pengingat WA/Telegram otomatis (versi Laravel Herd).
rem  Sama dengan yang dilakukan cron-job.org pada versi online:
rem  mengirim unit yang deadline-nya HARI INI dan BESOK, maks 1x per hari.
rem
rem  Agar berjalan otomatis setiap hari jam 20.00, daftarkan file ini
rem  di Windows Task Scheduler (lihat CARA_PAKAI_HERD.txt).
rem  Hasil setiap pengiriman dicatat di pengingat_log.txt.
rem ==========================================================
title Kirim Pengingat

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
call "%PHP%" "%~dp0cron_lokal.php" > "%~dp0pengingat_terakhir.txt" 2>&1
type "%~dp0pengingat_terakhir.txt"
echo [%date% %time%] >> "%~dp0pengingat_log.txt"
type "%~dp0pengingat_terakhir.txt" >> "%~dp0pengingat_log.txt"
echo.
echo "terkirim":N = jumlah unit yang dikirimi pengingat. "total":0 = tidak ada unit yang jatuh tempo hari ini/besok.

:selesai
if /i not "%~1"=="auto" pause
