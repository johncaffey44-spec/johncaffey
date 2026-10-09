@echo off
rem D8 · Service clients — lance l'installation (le script demande les droits administrateur)
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0installer-service-clients.ps1" %*
pause
