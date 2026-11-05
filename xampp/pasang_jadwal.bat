@echo off
rem ==========================================================
rem  Klik 2x SEKALI untuk mendaftarkan pengingat otomatis di Windows Task Scheduler:
rem   - berjalan setiap hari jam 07.00
rem   - jika laptop mati / tidur pada jam itu, dijalankan begitu laptop menyala lagi
rem   - juga dijalankan setiap kali login ke Windows (berjaga-jaga)
rem  Untuk menghapus jadwal: jalankan file ini dengan argumen "hapus" (pasang_jadwal.bat hapus).
rem ==========================================================
title Pasang Jadwal Pengingat Hari Jadi
set "NAMA_TASK=Pengingat Hari Jadi"
set "BAT=%~dp0kirim_pengingat.bat"

if /i "%~1"=="hapus" (
    schtasks /delete /tn "%NAMA_TASK%" /f
    goto selesai
)

powershell -NoProfile -ExecutionPolicy Bypass -Command ^
  "$aksi = New-ScheduledTaskAction -Execute '%BAT%' -Argument 'auto' -WorkingDirectory '%~dp0';" ^
  "$pemicu = @((New-ScheduledTaskTrigger -Daily -At '07:00'), (New-ScheduledTaskTrigger -AtLogOn -User $env:USERNAME));" ^
  "$atur = New-ScheduledTaskSettingsSet -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -ExecutionTimeLimit (New-TimeSpan -Minutes 10);" ^
  "Register-ScheduledTask -TaskName '%NAMA_TASK%' -Action $aksi -Trigger $pemicu -Settings $atur -Force | Out-Null"
if errorlevel 1 (
    echo.
    echo [GAGAL] Jadwal tidak bisa dibuat. Klik kanan file ini -^> Run as administrator, lalu coba lagi.
    echo Atau buat manual lewat Task Scheduler, lihat CARA_PAKAI.txt bagian 4.
    goto selesai
)
echo.
echo [BERHASIL] Jadwal "%NAMA_TASK%" dibuat: setiap hari jam 07.00 dan setiap login Windows.
echo Cek di: tombol Windows -^> ketik Task Scheduler -^> Task Scheduler Library.

:selesai
echo.
pause
