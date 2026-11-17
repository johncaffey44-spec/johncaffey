@echo off
REM Fluide : optimisations Android par ADB (Windows).
REM Chaque action est expliquee, demande confirmation quand elle change quelque chose,
REM et peut etre annulee : les valeurs d'origine sont sauvegardees a cote du script.
REM
REM Utilisation : double-clic (menu), ou "fluide.bat 1" pour lancer directement une option.
REM Placez ce fichier dans le dossier "platform-tools" (adb.exe), ou ajoutez adb au PATH.

setlocal EnableExtensions EnableDelayedExpansion
title Fluide - optimisations Android par ADB
set "HERE=%~dp0"
cd /d "%HERE%"

set "PKG=app.fluide"
set "SAVE_SCREEN=%HERE%fluide-sauvegarde-ecran.txt"
set "SAVE_APPS=%HERE%fluide-applis-desactivees.txt"

where adb >nul 2>&1
if errorlevel 1 (
  if not exist "%HERE%adb.exe" (
    echo adb est introuvable.
    echo Telechargez les "SDK Platform-Tools" de Google :
    echo   https://developer.android.com/tools/releases/platform-tools
    echo puis placez ce script dans le dossier platform-tools.
    pause
    exit /b 1
  )
)

adb start-server >nul 2>&1

set "ONCE="
if not "%~1"=="" (
  set "CHOIX=%~1"
  set "ONCE=1"
  goto dispatch
)

:menu
cls
echo ==================================================
echo   Fluide : optimisations Android par ADB
echo ==================================================
echo    1. Diagnostic - lecture seule
echo    2. Autoriser l'appli Fluide a regler les animations
echo    3. Animations 0,5x - plus reactif
echo    4. Animations 1x - par defaut
echo    5. Optimiser la compilation des applis - 5 a 30 min
echo    6. Maintenance du stockage - TRIM
echo    7. Ecran : forcer la frequence maximale
echo    8. Ecran : revenir au reglage d'origine
echo    9. Desactiver des applis preinstallees - liste sure
echo   10. Reactiver les applis desactivees par ce script
echo   11. Vider le cache de toutes les applis - si stockage sature
echo    I. Installer ou mettre a jour l'appli Fluide
echo    A. Tout le recommande : 2 + 3 + 6 + 5
echo    Q. Quitter
echo.
set "CHOIX="
set /p "CHOIX=Votre choix : "
echo.

:dispatch
if /i "!CHOIX!"=="q" exit /b 0
if "!CHOIX!"=="1" (
  call :diagnostic
  goto after
)
if "!CHOIX!"=="2" (
  call :grant
  goto after
)
if "!CHOIX!"=="3" (
  call :anim 0.5
  goto after
)
if "!CHOIX!"=="4" (
  call :anim 1
  goto after
)
if "!CHOIX!"=="5" (
  call :compile
  goto after
)
if "!CHOIX!"=="6" (
  call :trim
  goto after
)
if "!CHOIX!"=="7" (
  call :refresh_force
  goto after
)
if "!CHOIX!"=="8" (
  call :refresh_restore
  goto after
)
if "!CHOIX!"=="9" (
  call :bloat_disable
  goto after
)
if "!CHOIX!"=="10" (
  call :bloat_restore
  goto after
)
if "!CHOIX!"=="11" (
  call :trim_caches
  goto after
)
if /i "!CHOIX!"=="i" (
  call :install
  goto after
)
if /i "!CHOIX!"=="a" (
  call :recommended
  goto after
)
echo Choix inconnu.

:after
if defined ONCE exit /b 0
echo.
pause
goto menu

REM ======================================================================
REM  Outils
REM ======================================================================

:check_device
set "STATE="
for /f "delims=" %%S in ('adb get-state 2^>^&1') do set "STATE=%%S"
if "!STATE!"=="device" exit /b 0
if not "!STATE:unauthorized=!"=="!STATE!" (
  echo Telephone non autorise : deverrouillez-le et acceptez "Autoriser le debogage USB".
  exit /b 1
)
if not "!STATE:more than one=!"=="!STATE!" (
  echo Plusieurs appareils connectes : n'en gardez qu'un.
  exit /b 1
)
echo Aucun telephone detecte. Verifiez le cable - donnees, pas seulement charge - et le "Debogage USB".
exit /b 1

:confirm
if "%FLUIDE_OUI%"=="1" exit /b 0
set "REP="
set /p "REP=%~1 ? [o/N] "
if /i "!REP!"=="o" exit /b 0
if /i "!REP!"=="oui" exit /b 0
if /i "!REP!"=="y" exit /b 0
exit /b 1

:is_installed
set "FOUND="
for /f "delims=" %%A in ('adb shell pm path %PKG% 2^>nul') do set "FOUND=1"
if defined FOUND exit /b 0
exit /b 1

:max_hz
REM Frequence maximale de l'ecran, arrondie : 119.99998 donne 120.
set "MAXHZ=0"
for /f "tokens=2,3 delims==." %%A in ('adb shell "dumpsys display | grep -oE 'fps=[0-9.]+' | sort -u"') do (
  set "INT=%%A"
  set "DEC=%%B"
  if "!DEC!"=="" set "DEC=0"
  set "DEC=!DEC:~0,1!"
  set /a "HZ=INT"
  if !DEC! GEQ 5 set /a "HZ=INT+1"
  if !HZ! GTR !MAXHZ! set "MAXHZ=!HZ!"
)
if !MAXHZ! LSS 1 set "MAXHZ=60"
exit /b 0

REM ======================================================================
REM  Actions
REM ======================================================================

:diagnostic
call :check_device
if errorlevel 1 exit /b 1
set "MANU="
set "MODEL="
set "REL="
set "SDK="
for /f "delims=" %%A in ('adb shell getprop ro.product.manufacturer') do set "MANU=%%A"
for /f "delims=" %%A in ('adb shell getprop ro.product.model') do set "MODEL=%%A"
for /f "delims=" %%A in ('adb shell getprop ro.build.version.release') do set "REL=%%A"
for /f "delims=" %%A in ('adb shell getprop ro.build.version.sdk') do set "SDK=%%A"
echo == Appareil ==
echo Modele     : !MANU! !MODEL!
echo Android    : !REL! - API !SDK!
for /f "tokens=2" %%A in ('adb shell "grep MemTotal /proc/meminfo"') do (
  set /a "RAMMB=%%A/1024"
  echo RAM        : !RAMMB! Mo
)
for /f "tokens=2,4,5" %%A in ('adb shell "df -h /data | tail -n 1"') do echo Stockage   : %%B libres sur %%A, %%C utilises
set "A1="
set "A2="
set "A3="
for /f "delims=" %%A in ('adb shell settings get global window_animation_scale') do set "A1=%%A"
for /f "delims=" %%A in ('adb shell settings get global transition_animation_scale') do set "A2=%%A"
for /f "delims=" %%A in ('adb shell settings get global animator_duration_scale') do set "A3=%%A"
echo Animations : fenetres !A1!, transitions !A2!, duree !A3! - null = 1 par defaut
call :max_hz
set "PEAK="
set "MINR="
for /f "delims=" %%A in ('adb shell settings get system peak_refresh_rate') do set "PEAK=%%A"
for /f "delims=" %%A in ('adb shell settings get system min_refresh_rate') do set "MINR=%%A"
echo Ecran      : jusqu'a !MAXHZ! Hz, reglages peak=!PEAK! min=!MINR!
set "LOWP="
for /f "delims=" %%A in ('adb shell settings get global low_power') do set "LOWP=%%A"
echo Economie   : !LOWP! - 1 = economie d'energie activee
for /f "delims=" %%A in ('adb shell "dumpsys thermalservice 2>/dev/null | grep -m 1 'Thermal Status'"') do echo Thermique  : %%A - 0 = normal, 2 et plus = bridage
for /f "tokens=2 delims=:" %%A in ('adb shell "dumpsys battery | grep -m 1 temperature"') do (
  set /a "TEMP=%%A/10"
  echo Batterie   : !TEMP! degres
)
call :is_installed
if errorlevel 1 (
  echo Fluide     : non installee - option I
) else (
  for /f "delims=" %%A in ('adb shell "dumpsys package %PKG% | grep -m 1 WRITE_SECURE_SETTINGS"') do echo Fluide     : installee, %%A
)
exit /b 0

:install
call :check_device
if errorlevel 1 exit /b 1
set "APK="
for %%F in ("%HERE%Fluide*.apk") do set "APK=%%~fF"
if not defined APK (
  echo Aucun fichier Fluide*.apk a cote du script : copiez-y l'APK puis recommencez.
  exit /b 1
)
echo Installation de !APK! ...
adb install -r "!APK!"
exit /b 0

:grant
call :check_device
if errorlevel 1 exit /b 1
call :is_installed
if errorlevel 1 (
  echo L'appli Fluide n'est pas installee : utilisez d'abord l'option I.
  exit /b 1
)
set "OUT="
for /f "delims=" %%A in ('adb shell pm grant %PKG% android.permission.WRITE_SECURE_SETTINGS 2^>^&1') do set "OUT=!OUT! %%A"
if "!OUT!"=="" (
  echo OK : Fluide peut maintenant regler la vitesse des animations.
  exit /b 0
)
echo Echec : !OUT!
echo Xiaomi, Redmi, POCO : activez "Debogage USB - parametres de securite" dans les options pour les developpeurs.
echo Oppo, Realme, OnePlus : activez "Desactiver la surveillance des autorisations".
exit /b 1

:anim
call :check_device
if errorlevel 1 exit /b 1
adb shell settings put global window_animation_scale %1
adb shell settings put global transition_animation_scale %1
adb shell settings put global animator_duration_scale %1
echo Animations reglees a %1x.
exit /b 0

:compile
call :check_device
if errorlevel 1 exit /b 1
echo Compilation optimisee des applis - ART : 5 a 30 minutes, telephone branche de preference.
echo Le telephone reste utilisable mais sera plus lent pendant l'operation.
call :confirm "Lancer maintenant"
if errorlevel 1 exit /b 0
echo En cours, ne debranchez pas le telephone...
adb shell cmd package bg-dexopt-job
if errorlevel 1 (
  echo Methode rapide indisponible, essai de la methode alternative...
  adb shell cmd package compile -m speed-profile -a
)
echo Termine.
exit /b 0

:trim
call :check_device
if errorlevel 1 exit /b 1
adb shell sm idle-maint run
echo Maintenance du stockage lancee - TRIM : elle se poursuit quelques minutes sur le telephone.
exit /b 0

:refresh_force
call :check_device
if errorlevel 1 exit /b 1
call :max_hz
if !MAXHZ! LEQ 61 (
  echo Ecran limite a 60 Hz : rien a forcer.
  exit /b 0
)
echo Frequence maximale detectee : !MAXHZ! Hz. La forcer en permanence coute de l'autonomie.
echo Sans effet sur certaines marques, dont Samsung : utilisez alors Parametres, Affichage.
call :confirm "Forcer !MAXHZ! Hz"
if errorlevel 1 exit /b 0
if not exist "%SAVE_SCREEN%" (
  set "OLDPEAK="
  set "OLDMIN="
  for /f "delims=" %%A in ('adb shell settings get system peak_refresh_rate') do set "OLDPEAK=%%A"
  for /f "delims=" %%A in ('adb shell settings get system min_refresh_rate') do set "OLDMIN=%%A"
  > "%SAVE_SCREEN%" echo peak_refresh_rate=!OLDPEAK!
  >> "%SAVE_SCREEN%" echo min_refresh_rate=!OLDMIN!
)
adb shell settings put system peak_refresh_rate !MAXHZ!.0
adb shell settings put system min_refresh_rate !MAXHZ!.0
echo Fait. Valeurs d'origine sauvegardees : option 8 pour revenir.
exit /b 0

:refresh_restore
call :check_device
if errorlevel 1 exit /b 1
if not exist "%SAVE_SCREEN%" (
  adb shell settings delete system min_refresh_rate >nul
  adb shell settings delete system peak_refresh_rate >nul
  echo Aucune sauvegarde : reglage du constructeur retabli. Ajustez si besoin dans Parametres, Affichage.
  exit /b 0
)
for /f "usebackq tokens=1,2 delims==" %%K in ("%SAVE_SCREEN%") do call :restore_key %%K %%L
del "%SAVE_SCREEN%"
echo Reglage d'ecran d'origine retabli.
exit /b 0

:restore_key
if "%~2"=="" goto restore_key_delete
if "%~2"=="null" goto restore_key_delete
adb shell settings put system %1 %2
exit /b 0
:restore_key_delete
adb shell settings delete system %1 >nul
exit /b 0

:bloat_disable
call :check_device
if errorlevel 1 exit /b 1
set "ANY="
call :bloat_one com.facebook.appmanager "Facebook App Manager"
call :bloat_one com.facebook.services "Facebook Services"
call :bloat_one com.facebook.system "Facebook App Installer"
call :bloat_one com.aura.oobe.samsung.gl "AppCloud - applis sponsorisees"
call :bloat_one com.aura.oobe.samsung "AppCloud - applis sponsorisees"
call :bloat_one com.samsung.android.app.spage "Samsung Free"
call :bloat_one com.samsung.android.bixby.agent "Bixby Voice"
call :bloat_one com.samsung.android.app.tips "Astuces Samsung"
call :bloat_one com.samsung.android.ardrawing "AR Doodle"
call :bloat_one com.miui.analytics "Analytics Xiaomi"
call :bloat_one com.miui.msa.global "MSA Xiaomi - publicite systeme"
call :bloat_one com.xiaomi.mipicks "GetApps Xiaomi"
call :bloat_one com.netflix.partner.activation "Netflix Partner Activation"
if not defined ANY echo Aucune appli de la liste n'est active sur ce telephone.
exit /b 0

:bloat_one
REM %1 = paquet, %2 = nom affiche
set "ENABLED="
for /f "delims=" %%A in ('adb shell pm list packages -e %1') do if "%%A"=="package:%1" set "ENABLED=1"
if not defined ENABLED exit /b 0
set "ANY=1"
call :confirm "Desactiver %~2 - %1"
if errorlevel 1 exit /b 0
set "R="
for /f "delims=" %%A in ('adb shell pm disable-user --user 0 %1 2^>^&1') do set "R=!R! %%A"
if not "!R:disabled-user=!"=="!R!" (
  >> "%SAVE_APPS%" echo disable %1
  echo   - desactivee
  exit /b 0
)
REM Certaines surcouches - Xiaomi - refusent la desactivation : retrait pour l'utilisateur, reversible.
set "R2="
for /f "delims=" %%A in ('adb shell pm uninstall -k --user 0 %1 2^>^&1') do set "R2=!R2! %%A"
if not "!R2:Success=!"=="!R2!" (
  >> "%SAVE_APPS%" echo uninstall %1
  echo   - retiree pour votre utilisateur, reversible avec l'option 10
  exit /b 0
)
echo   - impossible : !R!
exit /b 0

:bloat_restore
call :check_device
if errorlevel 1 exit /b 1
if not exist "%SAVE_APPS%" (
  echo Aucune appli desactivee par ce script.
  exit /b 0
)
for /f "usebackq tokens=1,2" %%A in ("%SAVE_APPS%") do call :restore_app %%A %%B
move /y "%SAVE_APPS%" "%SAVE_APPS%.restaure" >nul
echo Applis reactivees.
exit /b 0

:restore_app
if "%1"=="disable" adb shell pm enable %2
if "%1"=="uninstall" adb shell cmd package install-existing %2
exit /b 0

:trim_caches
call :check_device
if errorlevel 1 exit /b 1
echo Le cache accelere les applis : le vider ne sert que si le stockage est presque plein.
for /f "tokens=2,4,5" %%A in ('adb shell "df -h /data | tail -n 1"') do echo Stockage : %%B libres sur %%A, %%C utilises
call :confirm "Vider le cache de toutes les applis"
if errorlevel 1 exit /b 0
adb shell pm trim-caches 999G
echo Cache vide.
exit /b 0

:recommended
call :check_device
if errorlevel 1 exit /b 1
call :is_installed
if not errorlevel 1 call :grant
call :anim 0.5
call :trim
call :compile
exit /b 0
