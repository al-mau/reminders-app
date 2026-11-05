@echo off
rem ==========================================================
rem  Klik 2x file ini SEKALI di awal untuk:
rem   1. Membuat database "pengingat_hari_jadi" + semua tabel di MySQL XAMPP
rem   2. Membuat file .env (pengaturan) dari .env.example bila belum ada
rem  Aman dijalankan ulang: data yang sudah ada TIDAK dihapus.
rem ==========================================================
title Siapkan Database Pengingat Hari Jadi
echo ==========================================================
echo   SIAPKAN DATABASE APLIKASI PENGINGAT HARI JADI (XAMPP)
echo ==========================================================
echo.
echo Pastikan MySQL di XAMPP Control Panel sudah di-START.
echo.
pause

rem --- Lokasi XAMPP ---
rem Kosongkan untuk deteksi otomatis. Isi manual jika XAMPP di folder lain, contoh: set "XAMPP_MANUAL=D:\xampp"
set "XAMPP_MANUAL="
set "XAMPP=%XAMPP_MANUAL%"
rem Deteksi otomatis: 1) project ada di xampp\htdocs, 2) C:\xampp, D:\xampp, E:\xampp, F:\xampp
if not defined XAMPP for %%I in ("%~dp0..\..\..") do if exist "%%~fI\mysql\bin\mysql.exe" set "XAMPP=%%~fI"
if not defined XAMPP for %%D in (C D E F) do if not defined XAMPP if exist "%%D:\xampp\mysql\bin\mysql.exe" set "XAMPP=%%D:\xampp"
if not defined XAMPP (
    echo [GAGAL] Folder XAMPP tidak ditemukan.
    echo Buka file ini dengan Notepad, isi baris set "XAMPP_MANUAL=" sesuai lokasi XAMPP, contoh D:\xampp
    goto selesai
)
echo XAMPP ditemukan di: %XAMPP%

"%XAMPP%\mysql\bin\mysql.exe" -u root --default-character-set=utf8mb4 < "%~dp0schema.sql"
if errorlevel 1 (
    echo.
    echo [GAGAL] Membuat database gagal. Cek apakah MySQL XAMPP sudah berjalan.
    echo Jika user root memakai password, jalankan manual:
    echo   "%XAMPP%\mysql\bin\mysql.exe" -u root -p ^< "%~dp0schema.sql"
    goto selesai
)
echo [BERHASIL] Database "pengingat_hari_jadi" siap.

rem --- File pengaturan .env ---
set "ENVFILE=%~dp0..\.env"
if exist "%ENVFILE%" (
    echo File .env sudah ada, tidak diubah.
) else (
    copy "%~dp0..\.env.example" "%ENVFILE%" >nul
    rem Isi CRON_SECRET dengan kode acak
    powershell -NoProfile -Command "$f='%ENVFILE%'; (Get-Content $f) -replace '^CRON_SECRET=$', ('CRON_SECRET=' + [guid]::NewGuid().ToString('N')) | Set-Content $f"
    echo [BERHASIL] File .env dibuat. Buka dengan Notepad untuk mengisi token WA, Telegram, dan Email.
)
echo.
echo Selanjutnya buka browser: http://localhost/pengingat-hari-jadi/

:selesai
echo.
pause
