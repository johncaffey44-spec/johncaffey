@echo off
rem Sentinelle XDR - installation sur Windows 11 Pro + IIS + PHP.
rem Double-cliquez : le script se relance en administrateur puis lance Installer-XDR.ps1.
chcp 65001 >nul
net session >nul 2>&1
if %errorlevel% neq 0 (
  echo Demande des droits administrateur...
  powershell -NoProfile -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
  exit /b
)
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0Installer-XDR.ps1" %*
echo.
pause
