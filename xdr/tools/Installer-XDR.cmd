@echo off
rem Sentinelle XDR - installation sur Windows 11 Pro + IIS + PHP.
rem Double-cliquez : le script se relance en administrateur puis lance Installer-XDR.ps1.
chcp 65001 >nul
fltmc >nul 2>&1
if errorlevel 1 (
  if /i "%~1"=="--eleve" (
    echo Impossible d'obtenir les droits administrateur.
    echo Clic droit sur INSTALLER.cmd puis Executer en tant qu'administrateur.
    pause
    exit /b 1
  )
  echo Demande des droits administrateur...
  set "XDR_SELF=%~f0"
  powershell -NoProfile -Command "Start-Process -FilePath $env:XDR_SELF -ArgumentList '--eleve' -Verb RunAs"
  exit /b
)
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0Installer-XDR.ps1"
echo.
pause
