#!/usr/bin/env bash
# Faux « adb » pour tester fluide.sh sans téléphone.
# État simulé dans $FAKE_DIR : réglages (settings/<espace>/<clé>), paquets actifs / désactivés /
# retirés, permission accordée. Chaque appel est journalisé dans $FAKE_DIR/log.
# Variables : FAKE_STATE=device|unauthorized|none   FAKE_MIUI=1 (refuse disable-user)
#             FAKE_SDK=35 (version d'Android simulée)
#             FAKE_DEXOPT_FAIL=1 (bg-dexopt-job indisponible)
#             FAKE_BG_CANCELLED=1 (bg-dexopt-job interrompu, code 0 : cas réel Android 14+)
#             FAKE_ART_MISSING=1 (pm art dexopt-packages inconnu)
#             FAKE_ART_CANCELLED=1 (dexopt-packages interrompu)
set -u
D="${FAKE_DIR:?FAKE_DIR non défini}"
mkdir -p "$D/settings"
touch "$D/enabled" "$D/disabled" "$D/removed"
echo "$*" >> "$D/log"

move() { # move <paquet> <depuis> <vers>
  grep -vx "$1" "$D/$2" > "$D/$2.tmp" || true
  mv "$D/$2.tmp" "$D/$2"
  echo "$1" >> "$D/$3"
}

case "${1:-}" in
  start-server) exit 0 ;;
  get-state)
    case "${FAKE_STATE:-device}" in
      device) echo device ;;
      unauthorized) echo "error: device unauthorized." >&2; exit 1 ;;
      *) echo "error: no devices/emulators found" >&2; exit 1 ;;
    esac
    exit 0 ;;
  install)
    shift
    [ "${1:-}" = "-r" ] && shift
    if [ -f "${1:-}" ]; then
      echo "Performing Streamed Install"; echo "Success"
      grep -qx app.fluide "$D/enabled" || echo app.fluide >> "$D/enabled"
      exit 0
    fi
    echo "adb: failed to stat ${1:-}: No such file or directory" >&2; exit 1 ;;
  shell) shift ;;
  *) echo "fake adb: sous-commande inconnue: $*" >&2; exit 2 ;;
esac

cmd="$*"
case "$cmd" in
  "getprop ro.product.manufacturer") echo Google ;;
  "getprop ro.product.model") echo "Pixel Test" ;;
  "getprop ro.build.version.release") echo 15 ;;
  "getprop ro.build.version.sdk") echo "${FAKE_SDK:-35}" ;;
  "grep MemTotal /proc/meminfo") echo "MemTotal:        7812345 kB" ;;
  "df -h /data | tail -n 1") echo "/dev/block/dm-48   110G   80G   30G  73% /data" ;;
  "settings get "*)
    set -- $cmd
    if [ -f "$D/settings/$3/$4" ]; then cat "$D/settings/$3/$4"; else echo null; fi ;;
  "settings put "*)
    set -- $cmd
    mkdir -p "$D/settings/$3"; echo "$5" > "$D/settings/$3/$4" ;;
  "settings delete "*)
    set -- $cmd
    if [ -f "$D/settings/$3/$4" ]; then rm -f "$D/settings/$3/$4"; echo "Deleted 1 rows"; else echo "Deleted 0 rows"; fi ;;
  "dumpsys display")
    cat <<'EOF'
DISPLAY MANAGER (dumpsys display)
  mSupportedModes=[{id=1, width=1080, height=2400, fps=60.000004, vsync=60.000004, synthetic=false, alternativeRefreshRates=[90.0, 119.99998]}, {id=2, width=1080, height=2400, fps=90.0, vsync=90.0}, {id=3, width=1080, height=2400, fps=119.99998, vsync=119.99998}]
  mActiveSfDisplayMode={id=1, width=1080, height=2400, fps=60.000004}
EOF
    ;;
  "dumpsys thermalservice 2>/dev/null | grep -m 1 'Thermal Status'") echo "Thermal Status: 0" ;;
  "dumpsys battery | grep -m 1 temperature") echo "  temperature: 312" ;;
  "dumpsys package app.fluide | grep -m 1 WRITE_SECURE_SETTINGS")
    [ -f "$D/granted" ] && echo "      android.permission.WRITE_SECURE_SETTINGS: granted=true" ;;
  "pm path app.fluide")
    if grep -qx app.fluide "$D/enabled"; then echo "package:/data/app/~~abc/app.fluide-1/base.apk"; else exit 1; fi ;;
  "pm grant app.fluide android.permission.WRITE_SECURE_SETTINGS") touch "$D/granted" ;;
  "pm list packages -e") sed 's/^/package:/' "$D/enabled" ;;
  "pm list packages -e "*)
    # Comme le vrai pm : filtre par sous-chaîne (d'où la comparaison exacte dans les scripts).
    set -- $cmd
    grep -F "$4" "$D/enabled" | sed 's/^/package:/' ;;
  "pm disable-user --user 0 "*)
    pkg="${cmd##* }"
    if [ "${FAKE_MIUI:-}" = "1" ]; then
      echo "Exception occurred while executing 'disable-user':"
      echo "java.lang.SecurityException: Shell cannot change component state for $pkg to 3"
      exit 255
    fi
    move "$pkg" enabled disabled
    echo "Package $pkg new state: disabled-user" ;;
  "pm uninstall -k --user 0 "*)
    pkg="${cmd##* }"; move "$pkg" enabled removed; echo "Success" ;;
  "pm enable "*)
    pkg="${cmd##* }"; move "$pkg" disabled enabled; echo "Package $pkg new state: enabled" ;;
  "cmd package install-existing "*)
    pkg="${cmd##* }"; move "$pkg" removed enabled; echo "Package $pkg installed for user: 0" ;;
  "cmd package bg-dexopt-job")
    if [ "${FAKE_DEXOPT_FAIL:-}" = "1" ]; then echo "Unknown command: bg-dexopt-job"; exit 1; fi
    if [ "${FAKE_BG_CANCELLED:-}" = "1" ]; then
      echo "Job running...  To cancel it, run 'pm bg-dexopt-job --cancel'. in a separate shell."
      echo "Job cancelled. See logs for details"
      exit 0
    fi
    echo "Success" ;;
  "pm art dexopt-packages -r bg-dexopt")
    if [ "${FAKE_ART_MISSING:-}" = "1" ]; then echo "Unknown command: dexopt-packages"; exit 255; fi
    echo "Job running. To cancel it, run 'pm art cancel 3f1c9a2e' in a separate shell."
    echo "  com.example.notes [status=PERFORMED] [reason=bg-dexopt]"
    if [ "${FAKE_ART_CANCELLED:-}" = "1" ]; then
      echo "  com.example.jeu [status=CANCELLED] [reason=bg-dexopt]"
    else
      echo "  com.example.jeu [status=PERFORMED] [reason=bg-dexopt]"
    fi ;;
  "cmd package compile -m speed-profile -a") echo "Success" ;;
  "sm idle-maint run") ;;
  "pm trim-caches 999G") ;;
  *) echo "fake adb: commande inconnue: $cmd" >&2; exit 2 ;;
esac
