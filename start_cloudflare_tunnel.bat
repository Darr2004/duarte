@echo off
title Duarte System - Cloudflare Free Tunnel (Unlimited Bandwidth)
cls
echo ========================================================
echo       Duarte System - Cloudflare Free Tunnel
echo             (WALANG BANDWIDTH LIMIT)
echo ========================================================
echo.
netstat -ano | findstr ":80 " >nul 2>&1
if "%ERRORLEVEL%" NEQ "0" (
    echo [BABALA] Siguraduhing naka-START ang Apache sa port 80 sa XAMPP!
    echo.
)

echo Kumokonekta sa Cloudflare Network...
echo Maghintay ng ilang segundo... lalabas ang iyong https://*.trycloudflare.com URL!
echo ========================================================
echo.

"C:\Program Files (x86)\cloudflared\cloudflared.exe" tunnel --url http://127.0.0.1:80
pause
