@echo off
title Salin Data Aiven ke XAMPP
cd /d "%~dp0.."

set "PHP=C:\xampp\php\php.exe"
if not exist "%PHP%" (
    echo [GAGAL] php.exe tidak ditemukan di %PHP%
    echo Jika XAMPP tidak terpasang di C:\xampp, ubah baris "set PHP=" di file ini.
    goto selesai
)

echo ==========================================================
echo   SALIN DATA DARI AIVEN (ONLINE) KE MYSQL XAMPP (LOKAL)
echo ==========================================================
echo Pastikan MySQL di XAMPP Control Panel sudah di-START.
echo Data users dan deadline di XAMPP akan diganti dengan data Aiven.
echo.

"%PHP%" "database\sync_dari_aiven.php" >> "database\sync_log.txt" 2>&1
set "HASIL=%errorlevel%"
powershell -NoProfile -Command "Get-Content 'database\sync_log.txt' -Tail 8"

echo.
if "%HASIL%"=="0" (echo [BERHASIL] Data sudah tersalin.) else (echo [GAGAL] Lihat pesan di atas.)

:selesai
rem Jika dijalankan otomatis oleh Task Scheduler (argumen "auto"), jangan menunggu tombol.
if /i not "%~1"=="auto" pause
