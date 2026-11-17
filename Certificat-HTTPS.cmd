@echo off
REM ============================================================
REM  Gardien - genere le certificat HTTPS (port 8443) pour IIS
REM  Double-cliquez. Se relance en administrateur puis execute
REM  Certificat-HTTPS.ps1 (depose a cote).
REM ============================================================
setlocal
cd /d "%~dp0"

whoami /groups | find "S-1-16-12288" >nul 2>&1
if errorlevel 1 (
  echo Elevation des privileges administrateur...
  powershell -NoProfile -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
  exit /b
)

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0Certificat-HTTPS.ps1"
