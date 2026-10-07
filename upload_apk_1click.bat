@echo off
echo ===================================================
echo   DuaRTE Mobile APK - 1-Click Cloud Uploader
echo ===================================================
echo.
echo Uploading duarte-app.apk to Cloud CDN (GitHub Releases)...
C:\xampp\php\php.exe "%~dp0scratch\upload_release.php"
echo.
pause
