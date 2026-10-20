@echo off
rem ==========================================================
rem  Kirim pengingat WA otomatis (versi XAMPP).
rem  Sama dengan yang dilakukan cron-job.org pada versi online:
rem  mengirim unit yang deadline-nya HARI INI dan/atau BESOK, maks 1x per hari.
rem
rem  Argumen (boleh digabung, urutan bebas):
rem    hari_ini = hanya unit yang deadline-nya HARI INI  -> jadwalkan jam 08.00
rem    besok    = hanya unit yang deadline-nya BESOK (H-1) -> jadwalkan jam 20.00
rem    auto     = tanpa "Press any key" (untuk Task Scheduler)
rem  Tanpa hari_ini/besok = keduanya sekaligus (klik 2x untuk coba manual).
rem
rem  Daftarkan dua jadwal di Windows Task Scheduler (lihat CARA_PAKAI_XAMPP.txt):
rem    jam 08.00 -> argumen: auto hari_ini
rem    jam 20.00 -> argumen: auto besok
rem  Hasil setiap pengiriman dicatat di pengingat_log.txt.
rem ==========================================================
title Kirim Pengingat WA

set "JENIS="
set "AUTO="
for %%A in (%*) do (
    if /i "%%~A"=="auto" set "AUTO=1"
    if /i "%%~A"=="hari_ini" set "JENIS=hari_ini"
    if /i "%%~A"=="besok" set "JENIS=besok"
)

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
"%XAMPP%\php\php.exe" "%~dp0cron_lokal.php" %JENIS% > "%~dp0pengingat_terakhir.txt" 2>&1
type "%~dp0pengingat_terakhir.txt"
echo [%date% %time%] jenis=%JENIS% >> "%~dp0pengingat_log.txt"
type "%~dp0pengingat_terakhir.txt" >> "%~dp0pengingat_log.txt"
echo.
echo "terkirim":N = jumlah unit yang dikirimi pengingat. "total":0 = tidak ada unit yang perlu diingatkan saat ini.

:selesai
if not defined AUTO pause
