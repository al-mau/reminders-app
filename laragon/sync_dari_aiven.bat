@echo off
rem ==========================================================
rem  Klik 2x file ini untuk MENYALIN data dari database online (Aiven)
rem  ke MySQL Laragon di laptop (backup lokal). Yang dijalankan:
rem  database\sync_dari_aiven.php memakai PHP bawaan Laragon.
rem  Hasil tiap salinan dicatat di sync_log.txt (folder ini).
rem  Untuk otomatis harian: Task Scheduler -> jalankan file ini dengan argumen auto
rem ==========================================================
title Salin Data Aiven ke Laragon

rem --- Lokasi Laragon ---
rem Kosongkan untuk deteksi otomatis. Isi manual jika Laragon di folder lain,
rem contoh: set "LARAGON_MANUAL=D:\laragon"
set "LARAGON_MANUAL="

set "LARAGON=%LARAGON_MANUAL%"
rem Deteksi otomatis: 1) project ada di laragon\www, 2) C:\laragon, D:\laragon, E:\laragon, F:\laragon
if not defined LARAGON for %%I in ("%~dp0..\..\..") do if exist "%%~fI\bin\php" set "LARAGON=%%~fI"
if not defined LARAGON for %%D in (C D E F) do if not defined LARAGON if exist "%%D:\laragon\bin\php" set "LARAGON=%%D:\laragon"
if not defined LARAGON (
    echo [GAGAL] Folder Laragon tidak ditemukan di C:\laragon, D:\laragon, E:\laragon, atau F:\laragon.
    echo Buka file ini dengan Notepad, isi baris set "LARAGON_MANUAL=" sesuai lokasi Laragon kamu.
    goto selesai
)
echo Laragon ditemukan di: %LARAGON%

set "PHP="
for /d %%P in ("%LARAGON%\bin\php\php-*") do if exist "%%~fP\php.exe" set "PHP=%%~fP\php.exe"
if not defined PHP (
    echo [GAGAL] php.exe tidak ditemukan di %LARAGON%\bin\php
    goto selesai
)

echo ==========================================================
echo   SALIN DATA DARI AIVEN (ONLINE) KE MYSQL LARAGON (LOKAL)
echo ==========================================================
echo Pastikan Laragon sudah di-START (tombol Start All).
echo Data di Laragon (users, deadline, penerima, lampiran, riwayat) akan diganti dengan data Aiven.
echo.

rem Jalankan script salin; hasilnya ditampilkan di layar dan ditambahkan ke sync_log.txt
"%PHP%" "%~dp0sync_dari_aiven.php" > "%~dp0sync_terakhir.txt" 2>&1
set "HASIL=%errorlevel%"
type "%~dp0sync_terakhir.txt"
type "%~dp0sync_terakhir.txt" >> "%~dp0sync_log.txt"

echo.
if "%HASIL%"=="0" (echo [BERHASIL] Data sudah tersalin.) else (echo [GAGAL] Lihat pesan di atas.)

:selesai
rem Jika dijalankan otomatis oleh Task Scheduler (argumen "auto"), jangan menunggu tombol.
if /i not "%~1"=="auto" pause
