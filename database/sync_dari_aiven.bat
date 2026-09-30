@echo off
title Salin Data Aiven ke XAMPP

rem --- Lokasi XAMPP ---
rem Kosongkan untuk deteksi otomatis. Isi manual jika XAMPP di folder lain,
rem contoh: set "XAMPP_MANUAL=D:\xampp"
set "XAMPP_MANUAL="

set "XAMPP=%XAMPP_MANUAL%"
rem Deteksi otomatis: 1) dari lokasi project di xampp\htdocs, 2) D:\xampp, C:\xampp, E:\xampp, F:\xampp
if not defined XAMPP for %%I in ("%~dp0..\..\..") do if exist "%%~fI\mysql\bin\mysql.exe" set "XAMPP=%%~fI"
if not defined XAMPP for %%D in (D C E F) do if not defined XAMPP if exist "%%D:\xampp\mysql\bin\mysql.exe" set "XAMPP=%%D:\xampp"
if not defined XAMPP (
    echo [GAGAL] Folder XAMPP tidak ditemukan di C:\xampp, D:\xampp, E:\xampp, atau F:\xampp.
    echo Buka file ini dengan Notepad, isi baris set "XAMPP_MANUAL=" sesuai lokasi XAMPP kamu.
    goto selesai
)
echo XAMPP ditemukan di: %XAMPP%
set "PHP=%XAMPP%\php\php.exe"

echo ==========================================================
echo   SALIN DATA DARI AIVEN (ONLINE) KE MYSQL XAMPP (LOKAL)
echo ==========================================================
echo Pastikan MySQL di XAMPP Control Panel sudah di-START.
echo Data users dan deadline di XAMPP akan diganti dengan data Aiven.
echo.

"%PHP%" "%~dp0sync_dari_aiven.php" > "%~dp0sync_terakhir.txt" 2>&1
set "HASIL=%errorlevel%"
type "%~dp0sync_terakhir.txt"
type "%~dp0sync_terakhir.txt" >> "%~dp0sync_log.txt"

echo.
if "%HASIL%"=="0" (echo [BERHASIL] Data sudah tersalin.) else (echo [GAGAL] Lihat pesan di atas.)

:selesai
rem Jika dijalankan otomatis oleh Task Scheduler (argumen "auto"), jangan menunggu tombol.
if /i not "%~1"=="auto" pause
