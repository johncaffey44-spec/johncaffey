@echo off
rem D8 Espace SI - active PHP (FastCGI) pour ce dossier sous IIS. A lancer une fois sur le serveur.
rem Double-cliquez : le script se relance en administrateur si besoin.
rem Options (depuis une invite de commandes administrateur) :
rem   Activer-PHP.cmd -PhpCgi "C:\PHP\php-cgi.exe"     imposer un PHP precis
rem   Activer-PHP.cmd -Annuler                          retirer le mappage ajoute
net session >nul 2>&1
if errorlevel 1 (
  if not "%~1"=="" (
    echo Pour utiliser des options, lancez cette commande depuis une invite de commandes en administrateur.
    pause
    exit /b 1
  )
  powershell -NoProfile -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
  exit /b
)
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0Activer-PHP.ps1" %*
echo.
pause
