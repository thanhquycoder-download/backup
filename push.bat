@echo off
title Auto Push GitHub
:loop
for /f %%i in ('git status --porcelain') do (
    echo [Phat hien thay doi] Dang day len GitHub...
    git add .
    git commit -m "Auto backup code"
    git push origin main
    echo [Hoan tat] Da dong bo!
    goto wait
)

:wait
timeout /t 5 /nobreak >nul
goto loop