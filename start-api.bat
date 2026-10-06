@echo off
title CODEKATHAX API - localhost:8000
cd /d "%~dp0"
echo ================================================
echo   CODEKATHAX API
echo   http://localhost:8000
echo ================================================
echo Keep this window OPEN while developing.
echo Make sure Laragon (MySQL) is running too.
echo Press Ctrl+C to stop.
echo.
php -S localhost:8000 -t public
pause
