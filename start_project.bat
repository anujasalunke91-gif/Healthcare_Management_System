@echo off
title MedCore Health - Healthcare Management Platform
color 0b

echo ======================================================================
echo           MEDCORE HEALTH - HEALTHCARE PLATFORM LAUNCHER
echo ======================================================================
echo.
echo  [1/2] Starting Multi-Cancer ML inference service on http://127.0.0.1:8000 ...
start "" /b "%~dp0healthcare_platform\ai_service\venv\Scripts\python.exe" -m uvicorn app:app --app-dir "%~dp0healthcare_platform\ai_service" --host 127.0.0.1 --port 8000

echo  [2/2] Starting web server on http://localhost:8080 ...
echo.
echo  * The website will open in your default browser automatically.
echo  * Keep this window OPEN while using the website.
echo  * To stop the server, simply close this window.
echo.

:: Automatically open browser after 2 seconds in the background
start "" /b cmd /c "timeout /t 2 /nobreak >nul && start http://localhost:8080"

:: Start PHP Built-in Server
"%~dp0php\php.exe" -c "%~dp0php\php.ini" -S localhost:8080 -t "%~dp0healthcare_platform"

pause
