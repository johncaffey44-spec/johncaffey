@echo off
rem ==========================================================================
rem  D8 Support - installation (ou mise a jour) sur Windows avec IIS
rem
rem  Double-cliquez sur ce fichier, puis acceptez la demande d'autorisation
rem  de Windows. Tout le reste est automatique : voir README.md.
rem ==========================================================================
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0installer-iis.ps1" %*
if errorlevel 1 pause
