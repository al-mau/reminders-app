@echo off
rem ==========================================================
rem  Kirim pengingat WA otomatis (versi XAMPP).
rem  Sama dengan yang dilakukan cron-job.org pada versi online:
rem  mengirim unit yang deadline-nya HARI INI dan BESOK, maks 1x per hari.
rem
rem  Agar berjalan otomatis setiap hari jam 20.00, daftarkan file ini
rem  di Windows Task Scheduler (lihat CARA_PAKAI_XAMPP.txt).
rem  Hasil setiap pengiriman dicatat di pengingat_log.txt.
rem ==========================================================
title Kirim Pengingat WA

set "XAMPP_MANUAL="
set "XAMPP=%XAMPP_MANUAL%"
rem Deteksi otomatis: 1) project ada di xampp\htdocs, 2) D:\xampp, C:\xampp, E:\xampp, F:\xampp
if not defined XAMPP for %%I in ("%~dp0..\..") do if exist "%%~fI\php\php.exe" set "XAMPP=%%~fI"
if not defined XAMPP for %%D in (D C E F) do if not defined XAMPP if exist "%%D:\xampp\php\php.exe" set "XAMPP=%%D:\xampp"
if not defined XAMPP (
    echo [GAGAL] Folder XAMPP tidak ditemukan. Isi baris set "XAMPP_MANUAL=" dengan lokasi XAMPP, contoh D:\xampp
    goto selesai
)

rem Jalankan pengingat; hasil ditampilkan di layar dan ditambahkan ke pengingat_log.txt
"%XAMPP%\php\php.exe" "%~dp0cron_lokal.php" > "%~dp0pengingat_terakhir.txt" 2>&1
type "%~dp0pengingat_terakhir.txt"
echo [%date% %time%] >> "%~dp0pengingat_log.txt"
type "%~dp0pengingat_terakhir.txt" >> "%~dp0pengingat_log.txt"
echo.
echo "terkirim":N = jumlah unit yang dikirimi pengingat. "total":0 = tidak ada unit yang jatuh tempo hari ini/besok.

:selesai
if /i not "%~1"=="auto" pause
