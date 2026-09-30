@echo off
title Import Database Reminders App ke XAMPP
echo ==========================================================
echo   IMPORT DATABASE REMINDERS APP KE XAMPP
echo ==========================================================
echo.
echo Pastikan MySQL di XAMPP Control Panel sudah di-START.
echo.
pause

set "MYSQL=C:\xampp\mysql\bin\mysql.exe"
if not exist "%MYSQL%" (
    echo [GAGAL] mysql.exe tidak ditemukan di %MYSQL%
    echo Jika XAMPP tidak terpasang di C:\xampp, ubah baris "set MYSQL=" di file ini.
    echo.
    pause
    exit /b 1
)

"%MYSQL%" -u root --default-character-set=utf8mb4 < "%~dp0reminders_db.sql"
if errorlevel 1 (
    echo.
    echo [GAGAL] Import gagal. Cek apakah MySQL XAMPP sudah berjalan.
    echo Jika user root memakai password, jalankan manual:
    echo   "%MYSQL%" -u root -p ^< "%~dp0reminders_db.sql"
    echo.
    pause
    exit /b 1
)

echo.
echo [BERHASIL] Database "reminders_db" sudah dibuat.
echo Buka http://localhost/phpmyadmin untuk melihatnya.
echo.
pause
