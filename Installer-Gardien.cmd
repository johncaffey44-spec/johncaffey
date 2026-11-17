@echo off
REM ============================================================
REM  Gardien - antivol : lanceur d'installation IIS (Windows)
REM  Double-cliquez ce fichier. Il se relance en administrateur
REM  puis execute Installer-Gardien.ps1 (depose a cote).
REM ============================================================
setlocal
cd /d "%~dp0"

REM Deja en administrateur ? (niveau d'integrite High = S-1-16-12288)
whoami /groups | find "S-1-16-12288" >nul 2>&1
if errorlevel 1 (
  echo Elevation des privileges administrateur...
  powershell -NoProfile -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
  exit /b
)

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0Installer-Gardien.ps1"
