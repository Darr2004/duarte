@echo off
setlocal enabledelayedexpansion

:: Duarte Logistics - Windows Scheduled Maintenance Runner
:: Can be run manually or registered in Windows Task Scheduler.

set "SCRIPT_DIR=%~dp0"
set "PHP_BIN=C:\xampp\php\php.exe"
set "LOG_FILE=%SCRIPT_DIR%duarte_maintenance.log"

if not exist "%PHP_BIN%" (
    echo [ERROR] PHP binary not found at %PHP_BIN% >> "%LOG_FILE%"
    echo [ERROR] PHP binary not found at %PHP_BIN%
    exit /b 1
)

"%PHP_BIN%" "%SCRIPT_DIR%run_all.php" >> "%LOG_FILE%" 2>&1
exit /b %ERRORLEVEL%
