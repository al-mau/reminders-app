@echo off
rem ==========================================================
rem  Kirim pengingat hari jadi otomatis (H-7) lewat WhatsApp, Telegram, dan Email.
rem  Dijalankan setiap hari oleh Windows Task Scheduler (daftarkan dengan pasang_jadwal.bat).
rem  Boleh juga diklik 2x untuk mencoba. Aman dijalankan berkali-kali:
rem  daerah yang sudah diingatkan tahun ini tidak dikirim ulang.
rem  Hasil setiap pengiriman dicatat di pengingat_log.txt.
rem ==========================================================
title Kirim Pengingat Hari Jadi
cd /d "%~dp0"

set "XAMPP_MANUAL="
set "XAMPP=%XAMPP_MANUAL%"
rem Deteksi otomatis: 1) project ada di xampp\htdocs, 2) C:\xampp, D:\xampp, E:\xampp, F:\xampp
if not defined XAMPP for %%I in ("%~dp0..\..") do if exist "%%~fI\php\php.exe" set "XAMPP=%%~fI"
if not defined XAMPP for %%D in (C D E F) do if not defined XAMPP if exist "%%D:\xampp\php\php.exe" set "XAMPP=%%D:\xampp"
if not defined XAMPP (
    echo [GAGAL] Folder XAMPP tidak ditemukan. Isi baris set "XAMPP_MANUAL=" dengan lokasi XAMPP, contoh D:\xampp
    echo [%date% %time%] GAGAL: folder XAMPP tidak ditemukan >> "%~dp0pengingat_log.txt"
    goto selesai
)

rem MySQL belum jalan (XAMPP Control Panel belum dibuka)? Nyalakan otomatis lalu tunggu siap.
tasklist /fi "imagename eq mysqld.exe" 2>nul | find /i "mysqld.exe" >nul
if errorlevel 1 (
    echo Menyalakan MySQL XAMPP...
    start "MySQL XAMPP" /min "%XAMPP%\mysql\bin\mysqld.exe" --defaults-file="%XAMPP%\mysql\bin\my.ini" --standalone
    for /l %%N in (1,1,30) do (
        "%XAMPP%\mysql\bin\mysqladmin.exe" -u root ping >nul 2>&1 && goto mysql_siap
        ping -n 2 127.0.0.1 >nul
    )
)
:mysql_siap

rem Jalankan pengingat; hasil ditampilkan di layar dan ditambahkan ke pengingat_log.txt
"%XAMPP%\php\php.exe" "%~dp0cron_pengingat.php" > "%~dp0pengingat_terakhir.txt" 2>&1
type "%~dp0pengingat_terakhir.txt"
echo [%date% %time%] >> "%~dp0pengingat_log.txt"
type "%~dp0pengingat_terakhir.txt" >> "%~dp0pengingat_log.txt"
echo.
echo "terkirim":N = jumlah daerah yang dikirimi pengingat. "total":0 = tidak ada hari jadi dalam 7 hari ke depan yang belum diingatkan.

:selesai
if /i not "%~1"=="auto" pause
