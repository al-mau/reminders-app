@echo off
rem ==========================================================
rem  Klik 2x file ini untuk MEMBUAT database "reminders_db" di MySQL Laragon
rem  dari file reminders_db.sql (struktur tabel + contoh data).
rem  Cukup dijalankan sekali di awal. Untuk mengisi data asli dari Aiven,
rem  pakai sync_dari_aiven.bat.
rem ==========================================================
title Import Database Aplikasi Pengingat Jadwal ke Laragon
echo ==========================================================
echo   IMPORT DATABASE APLIKASI PENGINGAT JADWAL KE LARAGON
echo ==========================================================
echo.
echo Pastikan Laragon sudah di-START (tombol Start All).
echo.
pause

rem --- Lokasi Laragon ---
rem Kosongkan untuk deteksi otomatis. Isi manual jika Laragon di folder lain,
rem contoh: set "LARAGON_MANUAL=D:\laragon"
set "LARAGON_MANUAL="

set "LARAGON=%LARAGON_MANUAL%"
rem Deteksi otomatis: 1) project ada di laragon\www, 2) C:\laragon, D:\laragon, E:\laragon, F:\laragon
if not defined LARAGON for %%I in ("%~dp0..\..\..") do if exist "%%~fI\bin\mysql" set "LARAGON=%%~fI"
if not defined LARAGON for %%I in ("%~dp0..\..\..") do if exist "%%~fI\bin\mariadb" set "LARAGON=%%~fI"
if not defined LARAGON for %%D in (C D E F) do if not defined LARAGON if exist "%%D:\laragon\bin\php" set "LARAGON=%%D:\laragon"
if not defined LARAGON (
    echo [GAGAL] Folder Laragon tidak ditemukan di C:\laragon, D:\laragon, E:\laragon, atau F:\laragon.
    echo Buka file ini dengan Notepad, isi baris set "LARAGON_MANUAL=" sesuai lokasi Laragon kamu.
    goto selesai
)
echo Laragon ditemukan di: %LARAGON%

rem mysql.exe ada di bin\mysql\mysql-8.x...\bin (atau bin\mariadb\mariadb-...\bin jika memakai MariaDB)
set "MYSQL="
for /d %%M in ("%LARAGON%\bin\mysql\mysql-*") do if exist "%%~fM\bin\mysql.exe" set "MYSQL=%%~fM\bin\mysql.exe"
if not defined MYSQL for /d %%M in ("%LARAGON%\bin\mariadb\mariadb-*") do if exist "%%~fM\bin\mysql.exe" set "MYSQL=%%~fM\bin\mysql.exe"
if not defined MYSQL (
    echo [GAGAL] mysql.exe tidak ditemukan di %LARAGON%\bin\mysql
    goto selesai
)

"%MYSQL%" -h 127.0.0.1 -u root --default-character-set=utf8mb4 < "%~dp0reminders_db.sql"
if errorlevel 1 (
    echo.
    echo [GAGAL] Import gagal. Cek apakah MySQL Laragon sudah berjalan ^(Start All^).
    echo Jika user root memakai password, jalankan manual:
    echo   "%MYSQL%" -h 127.0.0.1 -u root -p ^< "%~dp0reminders_db.sql"
    goto selesai
)

echo.
echo [BERHASIL] Database "reminders_db" sudah dibuat.
echo Lihat isinya lewat tombol Database di Laragon (HeidiSQL).

:selesai
echo.
pause
