@echo off
setlocal EnableExtensions
title Sentinelle XDR - installation
rem ==========================================================================
rem  Sentinelle XDR - installation ou mise a jour sur Windows 11 (IIS + PHP)
rem  Double-cliquez sur ce fichier : il demande les droits administrateur,
rem  puis lance tools\Installer-XDR.ps1. Le script analyse d'abord, affiche
rem  ce qu'il va changer et attend votre accord (O) avant de modifier quoi
rem  que ce soit. Options avancees : voir LISEZ-MOI.txt.
rem  Pas de bloc entre parentheses contenant le chemin du dossier : un nom
rem  comme "sentinelle-xdr (1)" casserait la lecture du fichier par cmd.exe.
rem ==========================================================================

fltmc >nul 2>&1
if not errorlevel 1 goto :admin
if /i "%~1"=="--eleve" goto :refus

echo Demande des droits administrateur...
set "XDR_CMD=%~f0"
powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "try { Start-Process -FilePath $env:XDR_CMD -ArgumentList '--eleve' -Verb RunAs -ErrorAction Stop } catch { exit 1 }"
if errorlevel 1 goto :refus
exit /b 0

:refus
echo.
echo Les droits administrateur sont necessaires pour installer Sentinelle XDR.
echo Faites un clic droit sur INSTALLER.cmd puis "Executer en tant qu'administrateur".
echo.
pause
exit /b 1

:admin
if exist "%~dp0tools\Installer-XDR.ps1" goto :lancer
echo Fichier introuvable : tools\Installer-XDR.ps1
echo Decompressez TOUT le fichier ZIP (clic droit, Extraire tout) avant de lancer l'installation.
echo.
pause
exit /b 1

:lancer
if /i "%~1"=="--eleve" goto :sansoptions
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0tools\Installer-XDR.ps1" %*
goto :fin

:sansoptions
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0tools\Installer-XDR.ps1"

:fin
set "CODE=%errorlevel%"
echo.
if "%CODE%"=="0" goto :reussi
echo L'installation s'est arretee : lisez les messages ci-dessus, corrigez puis relancez.
echo Le journal complet est dans ce dossier (journal-installation-sentinelle-....txt).
goto :attendre
:reussi
echo Termine. Vous pouvez fermer cette fenetre.
:attendre
echo.
pause
exit /b %CODE%
