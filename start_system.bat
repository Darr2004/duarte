@echo off
title Duarte System - Background Services
echo ========================================================
echo         Duarte System - Starting Services
echo ========================================================
echo.

powershell -NoProfile -ExecutionPolicy Bypass -Command ^
  "if (-not (Get-Process mysqld -ErrorAction SilentlyContinue)) { Start-Process -FilePath 'C:\xampp\mysql\bin\mysqld.exe' -ArgumentList '--defaults-file=C:\xampp\mysql\bin\my.ini --standalone' -WorkingDirectory 'C:\xampp' -WindowStyle Hidden; Write-Host '[+] MySQL started.' } else { Write-Host '[OK] MySQL already running.' };" ^
  "if (-not (Get-Process httpd -ErrorAction SilentlyContinue)) { Start-Process -FilePath 'C:\xampp\apache\bin\httpd.exe' -ArgumentList '-d C:/xampp/apache' -WorkingDirectory 'C:\xampp' -WindowStyle Hidden; Write-Host '[+] Apache started.' } else { Write-Host '[OK] Apache already running.' };" ^
  "if (-not (Get-Process ngrok -ErrorAction SilentlyContinue)) { Start-Process -FilePath 'ngrok' -ArgumentList 'http --url=https://spruce-trapdoor-unsorted.ngrok-free.dev 127.0.0.1:80' -WindowStyle Hidden; Write-Host '[+] Ngrok started.' } else { Write-Host '[OK] Ngrok already running.' };"

echo.
echo ========================================================
echo [ONLINE] All Duarte services are running in background!
echo Web:   https://spruce-trapdoor-unsorted.ngrok-free.dev/duarte/
echo Mobile: https://spruce-trapdoor-unsorted.ngrok-free.dev/duarte/api
echo ========================================================
