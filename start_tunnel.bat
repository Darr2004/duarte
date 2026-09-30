@echo off
title Duarte System - Ngrok Static Tunnel
cls
echo ========================================================
echo         Duarte System - Permanent Static Tunnel
echo ========================================================
echo.

tasklist /FI "IMAGENAME eq ngrok.exe" 2>NUL | find /I /N "ngrok.exe">NUL
if "%ERRORLEVEL%"=="0" (
    echo [OK] BUHAY AT ONLINE NA PO ANG TUNNEL MO!
    echo.
    echo Permanent Web URL:
    echo   https://outshine-aroma-angles.ngrok-free.dev/duarte/
    echo.
    echo Mobile API URL:
    echo   https://outshine-aroma-angles.ngrok-free.dev/duarte/api
    echo.
    echo ========================================================
    echo Gumagana na ito sa background! Pwede mo na itong gamitin agad.
    echo.
    set /p choice="Gusto mo ba itong i-restart? (Y/N): "
    if /I "%choice%"=="Y" (
        echo Pinapatay ang lumang ngrok session...
        taskkill /F /IM ngrok.exe >nul 2>&1
        timeout /t 2 >nul
        cls
        goto start_tunnel
    ) else (
        exit /b 0
    )
)

:start_tunnel
netstat -ano | findstr ":80 " >nul 2>&1
if "%ERRORLEVEL%" NEQ "0" (
    echo [BABALA] Hindi pa tumatakbo ang Apache sa port 80 ng XAMPP!
    echo Siguraduhing naka-START ang Apache at MySQL sa XAMPP Control Panel.
    echo.
)
echo Nagsisimula ang bagong tunnel...
echo Permanent Web URL: https://outshine-aroma-angles.ngrok-free.dev/duarte/
echo Mobile API URL:   https://outshine-aroma-angles.ngrok-free.dev/duarte/api
echo.
echo Huwag isara ang window na ito habang ginagamit ang system sa labas.
echo ========================================================
echo.
ngrok http --url=https://outshine-aroma-angles.ngrok-free.dev 127.0.0.1:80
pause
