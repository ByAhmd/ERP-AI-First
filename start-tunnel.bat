@echo off
:loop
echo Starting localtunnel...
call npx localtunnel --port 3000
echo Localtunnel crashed or exited. Restarting in 3 seconds...
timeout /t 3 /nobreak >nul
goto loop
