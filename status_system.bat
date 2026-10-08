@echo off
title Duarte System - Live Status
cls
echo ========================================================
echo               Duarte System Status
echo ========================================================
echo.

powershell -NoProfile -ExecutionPolicy Bypass -Command ^
  "if (Get-Process mysqld -ErrorAction SilentlyContinue) { Write-Host '[MySQL]   ONLINE  (Database ready)' -ForegroundColor Green } else { Write-Host '[MySQL]   OFFLINE' -ForegroundColor Red };" ^
  "if (Get-Process httpd -ErrorAction SilentlyContinue) { Write-Host '[Apache]  ONLINE  (Web server ready)' -ForegroundColor Green } else { Write-Host '[Apache]  OFFLINE' -ForegroundColor Red };" ^
  "if (Get-Process ngrok -ErrorAction SilentlyContinue) { Write-Host '[Tunnel]  ONLINE  (Accessible globally)' -ForegroundColor Green } else { Write-Host '[Tunnel]  OFFLINE' -ForegroundColor Red };"

echo.
echo ========================================================
echo Web Admin:  https://spruce-trapdoor-unsorted.ngrok-free.dev/duarte/
echo Mobile API: https://spruce-trapdoor-unsorted.ngrok-free.dev/duarte/api
echo ========================================================
echo.
pause
