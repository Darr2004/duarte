@echo off
title Duarte System - Stop All Services
echo ========================================================
echo       Duarte System - Stopping Services...
echo ========================================================
echo Stopping Ngrok tunnel...
taskkill /F /IM ngrok.exe >nul 2>&1
echo Stopping Apache web server...
taskkill /F /IM httpd.exe >nul 2>&1
echo Stopping MySQL database...
taskkill /F /IM mysqld.exe >nul 2>&1
echo.
echo [OK] All Duarte services have been stopped.
echo ========================================================
timeout /t 3 >nul
