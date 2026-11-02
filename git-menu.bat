@echo off
cd /d E:\wamp64\www\NIJAC

:MENU
cls
echo ============================================================
echo   NIJAC - Menu Git
echo   Repertoire : %CD%
echo ============================================================
echo.
echo   1) git add .
echo   2) git commit -m "..."
echo   3) git push -u origin main
echo   4) git show --name-only
echo   6) Tout faire : add + commit + push
echo   7) Commits locaux non pousses sur origin/main
echo   8) Fichiers modifies sur origin non encore recuperes
echo   9) git pull
echo   0) Quitter
echo.
set CHOIX=
set /p CHOIX=Votre choix :

if "%CHOIX%"=="1" goto ADD
if "%CHOIX%"=="2" goto COMMIT
if "%CHOIX%"=="3" goto PUSH
if "%CHOIX%"=="4" goto SHOW
if "%CHOIX%"=="6" goto TOUT
if "%CHOIX%"=="7" goto DIFF
if "%CHOIX%"=="8" goto PULL_DIFF
if "%CHOIX%"=="9" goto PULL
if "%CHOIX%"=="0" goto FIN
echo Choix invalide.
pause
goto MENU

:ADD
echo.
echo --- Incrementation du numero de version ---
php increment_version.php
echo.
echo --- git add . ---
git add .
echo.
git status
echo.
pause
goto MENU

:COMMIT
echo.
set MSG=
set /p MSG=Message du commit :
if "%MSG%"=="" (
    echo Message vide - commit annule.
    pause
    goto MENU
)
echo.
echo --- git commit ---
git commit -m "%MSG%"
echo.
pause
goto MENU

:PUSH
echo.
echo --- git push -u origin main ---
git push -u origin main
echo.
pause
goto MENU

:SHOW
echo.
echo --- git show --name-only ---
git show --name-only
echo.
pause
goto MENU

:PUTLINE
set "REL=%~1"
call :PARENT "%REL%"
if defined DIR >> "%SCRIPT%" echo mkdir -pf "/nijac/%DIR%"
>> "%SCRIPT%" echo put "%LOCAL%/%REL%" -o "/nijac/%REL%"
goto :eof

:PARENT
set "P=%~1"
set "DIR="
:PARENT_LOOP
for /f "tokens=1* delims=/" %%x in ("%P%") do (
    if "%%y"=="" goto :eof
    if defined DIR (set "DIR=%DIR%/%%x") else (set "DIR=%%x")
    set "P=%%y"
)
goto PARENT_LOOP

:TOUT
echo.
echo --- git add . ---
git add .
echo.
git status
echo.
set MSG=
set /p MSG=Message du commit :
if "%MSG%"=="" (
    echo Message vide - operation annulee.
    pause
    goto MENU
)
echo.
echo --- git commit ---
git commit -m "%MSG%"
echo.
echo --- git push -u origin main ---
git push -u origin main
echo.
echo --- git show --name-only ---
git show --name-only
echo.
pause
goto MENU

:DIFF
echo.
echo --- Commits locaux non encore pousses sur origin/main ---
git fetch origin
echo.
git log origin/main..main --oneline
echo.
echo --- Fichiers modifies localement par rapport a origin/main ---
git diff --name-status origin/main..main
echo.
pause
goto MENU

:PULL_DIFF
echo.
echo --- Recuperation des infos distantes (sans modifier les fichiers) ---
git fetch origin
echo.
echo --- Commits sur origin/main non encore recuperes en local ---
git log main..origin/main --oneline
echo.
echo --- Fichiers modifies sur origin/main par rapport a main local ---
git diff --name-status main..origin/main
echo.
pause
goto MENU

:PULL
echo.
echo --- git pull ---
git pull origin main
echo.
pause
goto MENU

:FIN
exit
