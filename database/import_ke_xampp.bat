@echo off
rem ==========================================================
rem  Klik 2x file ini untuk MEMBUAT database "reminders_db" di MySQL XAMPP
rem  dari file reminders_db.sql (struktur tabel + contoh data).
rem  Cukup dijalankan sekali di awal. Untuk mengisi data asli dari Aiven,
rem  pakai sync_dari_aiven.bat.
rem ==========================================================
title Import Database Aplikasi Pengingat Jadwal ke XAMPP
echo ==========================================================
echo   IMPORT DATABASE APLIKASI PENGINGAT JADWAL KE XAMPP
echo ==========================================================
echo.
echo Pastikan MySQL di XAMPP Control Panel sudah di-START.
echo.
pause

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
set "MYSQL=%XAMPP%\mysql\bin\mysql.exe"

"%MYSQL%" -u root --default-character-set=utf8mb4 < "%~dp0reminders_db.sql"
if errorlevel 1 (
    echo.
    echo [GAGAL] Import gagal. Cek apakah MySQL XAMPP sudah berjalan.
    echo Jika user root memakai password, jalankan manual:
    echo   "%MYSQL%" -u root -p ^< "%~dp0reminders_db.sql"
    goto selesai
)

echo.
echo [BERHASIL] Database "reminders_db" sudah dibuat.
echo Buka http://localhost/phpmyadmin untuk melihatnya.

:selesai
echo.
pause
