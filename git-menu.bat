@echo off
chcp 65001 >nul
cd /d F:\wamp64\www\NIJAC

:MENU
cls
echo ============================================================
echo   NIJAC — Menu Git
echo   Repertoire : %CD%
echo ============================================================
echo.
echo   1) git add .
echo   2) git commit -m "..."
echo   3) git push -u origin main
echo   4) git show --name-only
echo   5) Tout faire : add + commit + push
echo   0) Quitter
echo.
set CHOIX=
set /p CHOIX=Votre choix :

if "%CHOIX%"=="1" goto ADD
if "%CHOIX%"=="2" goto COMMIT
if "%CHOIX%"=="3" goto PUSH
if "%CHOIX%"=="4" goto SHOW
if "%CHOIX%"=="5" goto TOUT
if "%CHOIX%"=="0" goto FIN
echo Choix invalide.
pause
goto MENU

:ADD
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
    echo Message vide — commit annule.
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
    echo Message vide — operation annulee.
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

:FIN
exit
