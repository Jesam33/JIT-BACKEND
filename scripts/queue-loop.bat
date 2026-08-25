@echo off
REM Loop calling artisan queue:work --once to avoid long-running worker timeouts on Windows
:loop
php artisan queue:work --once --tries=3
timeout /t 3 >nul
goto loop
