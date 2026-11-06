@echo off
setlocal EnableExtensions
title Boussole - installation
rem ==========================================================================
rem  Boussole - installation ou mise a jour sur Windows 11 (IIS + PHP)
rem  Double-cliquez sur ce fichier : il demande les droits administrateur,
rem  puis lance Installer-Boussole.ps1 (dans le meme dossier).
rem  Options avancees (dossiers, heures, pare-feu...) : voir WINDOWS.md.
rem  Pas de bloc entre parentheses contenant le chemin du dossier : un nom
rem  comme "johncaffey-main (1)" casserait la lecture du fichier par cmd.exe.
rem ==========================================================================

fltmc >nul 2>&1
if not errorlevel 1 goto :admin
if /i "%~1"=="--eleve" goto :refus

echo Demande des droits administrateur...
set "BOUSSOLE_CMD=%~f0"
powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "try { Start-Process -FilePath $env:BOUSSOLE_CMD -ArgumentList '--eleve' -Verb RunAs -ErrorAction Stop } catch { exit 1 }"
if errorlevel 1 goto :refus
exit /b 0

:refus
echo.
echo Les droits administrateur sont necessaires pour installer Boussole.
echo Faites un clic droit sur Installer-Boussole.cmd puis "Executer en tant qu'administrateur".
echo.
pause
exit /b 1

:admin
if exist "%~dp0Installer-Boussole.ps1" goto :lancer
echo Fichier introuvable : Installer-Boussole.ps1
echo Decompressez tout le fichier ZIP avant de lancer l'installation.
echo.
pause
exit /b 1

:lancer
if /i "%~1"=="--eleve" goto :sansoptions
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0Installer-Boussole.ps1" %*
goto :fin

:sansoptions
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0Installer-Boussole.ps1"

:fin
set "CODE=%errorlevel%"
echo.
if "%CODE%"=="0" goto :reussi
echo L'installation s'est arretee : lisez les messages ci-dessus, corrigez puis relancez.
goto :attendre
:reussi
echo Termine. Vous pouvez fermer cette fenetre.
:attendre
echo.
pause
exit /b %CODE%
