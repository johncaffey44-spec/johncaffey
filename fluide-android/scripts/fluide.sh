#!/usr/bin/env bash
# Fluide : optimisations Android par ADB (Linux et macOS).
#
# Chaque action est expliquée, demande confirmation quand elle change quelque chose,
# et peut être annulée (les valeurs d'origine sont sauvegardées à côté du script).
#
# Utilisation : ./fluide.sh          (menu)
#               ./fluide.sh 1 3      (lance directement les options 1 puis 3)
# Variables   : ADB=/chemin/adb      FLUIDE_OUI=1 (répond « oui » à toutes les confirmations)

set -u

PKG="app.fluide"
HERE="$(cd "$(dirname "$0")" && pwd)"
SAVE_SCREEN="$HERE/fluide-sauvegarde-ecran.txt"
SAVE_APPS="$HERE/fluide-applis-desactivees.txt"

# Liste sûre : applis préinstallées sans risque pour le démarrage (même liste que l'appli).
BLOAT=(
  "com.facebook.appmanager|Facebook App Manager"
  "com.facebook.services|Facebook Services"
  "com.facebook.system|Facebook App Installer"
  "com.aura.oobe.samsung.gl|AppCloud - applis sponsorisees"
  "com.aura.oobe.samsung|AppCloud - applis sponsorisees"
  "com.samsung.android.app.spage|Samsung Free"
  "com.samsung.android.bixby.agent|Bixby Voice"
  "com.samsung.android.app.tips|Astuces Samsung"
  "com.samsung.android.ardrawing|AR Doodle"
  "com.miui.analytics|Analytics Xiaomi"
  "com.miui.msa.global|MSA Xiaomi - publicite systeme"
  "com.xiaomi.mipicks|GetApps Xiaomi"
  "com.netflix.partner.activation|Netflix Partner Activation"
)

ADB="${ADB:-adb}"
if ! command -v "$ADB" >/dev/null 2>&1; then
  if [ -x "$HERE/adb" ]; then
    ADB="$HERE/adb"
  else
    echo "adb est introuvable. Installez les « SDK Platform-Tools » de Google :"
    echo "  https://developer.android.com/tools/releases/platform-tools"
    echo "puis placez ce script dans le dossier platform-tools, ou ajoutez adb au PATH."
    exit 1
  fi
fi

"$ADB" start-server </dev/null >/dev/null 2>&1

# adb shell lit l'entrée standard : sans </dev/null, il avalerait les lignes d'une boucle « while read ».
sh_() { "$ADB" shell "$@" </dev/null 2>&1 | tr -d '\r'; }

confirm() {
  if [ "${FLUIDE_OUI:-}" = "1" ]; then return 0; fi
  local r
  read -r -p "$1 [o/N] " r || return 1
  case "$r" in [oOyY]*) return 0 ;; *) return 1 ;; esac
}

check_device() {
  local state
  # Dernière ligne seulement : au premier lancement, adb affiche d'abord « daemon started ».
  state="$("$ADB" get-state </dev/null 2>&1 | tr -d '\r' | tail -n 1)"
  case "$state" in
    device) return 0 ;;
    *unauthorized*) echo "Téléphone non autorisé : déverrouillez-le et acceptez « Autoriser le débogage USB »." ;;
    *"more than one"*) echo "Plusieurs appareils connectés : n'en gardez qu'un (ou définissez ANDROID_SERIAL)." ;;
    *) echo "Aucun téléphone détecté. Vérifiez le câble (données, pas seulement charge) et le « Débogage USB »." ;;
  esac
  return 1
}

is_installed() { sh_ pm path "$PKG" | grep -q '^package:'; }

# Fréquence maximale de l'écran, arrondie (119.99998 -> 120).
max_hz() {
  local hz
  hz="$(sh_ dumpsys display | grep -oE 'fps=[0-9.]+' | cut -d= -f2 | awk '{printf "%d\n", $1 + 0.5}' | sort -n | tail -n 1)"
  echo "${hz:-60}"
}

diagnostic() {
  check_device || return 1
  echo "== Appareil =="
  echo "Modèle     : $(sh_ getprop ro.product.manufacturer) $(sh_ getprop ro.product.model)"
  echo "Android    : $(sh_ getprop ro.build.version.release) (API $(sh_ getprop ro.build.version.sdk))"
  echo "RAM        : $(sh_ "grep MemTotal /proc/meminfo" | awk '{printf "%.1f Gio\n", $2 / 1048576}')"
  echo "Stockage   : $(sh_ "df -h /data | tail -n 1" | awk '{print $4 " libres sur " $2 " (" $5 " utilisés)"}')"
  echo "Animations : fenêtres $(sh_ settings get global window_animation_scale)," \
       "transitions $(sh_ settings get global transition_animation_scale)," \
       "durée $(sh_ settings get global animator_duration_scale)  (null = 1 par défaut)"
  echo "Écran      : jusqu'à $(max_hz) Hz ; réglages peak=$(sh_ settings get system peak_refresh_rate)" \
       "min=$(sh_ settings get system min_refresh_rate)"
  echo "Économie   : $(sh_ settings get global low_power)  (1 = économie d'énergie activée)"
  local th
  th="$(sh_ "dumpsys thermalservice 2>/dev/null | grep -m 1 'Thermal Status'")"
  [ -n "$th" ] && echo "Thermique  : $th  (0 = normal, 2 et plus = bridage)"
  echo "Batterie   : $(sh_ "dumpsys battery | grep -m 1 temperature" | awk '{printf "%.1f °C\n", $2 / 10}')"
  if is_installed; then
    echo "Fluide     : installée ; $(sh_ "dumpsys package $PKG | grep -m 1 WRITE_SECURE_SETTINGS" | sed 's/^ *//')"
  else
    echo "Fluide     : non installée (option I)"
  fi
}

install_app() {
  check_device || return 1
  local apk
  apk="$(ls -t "$HERE"/Fluide*.apk 2>/dev/null | head -n 1)"
  if [ -z "$apk" ]; then
    echo "Aucun fichier Fluide*.apk à côté du script : copiez-y l'APK puis recommencez."
    return 1
  fi
  echo "Installation de $apk…"
  "$ADB" install -r "$apk" </dev/null
}

grant() {
  check_device || return 1
  if ! is_installed; then
    echo "L'appli Fluide n'est pas installée : utilisez d'abord l'option I."
    return 1
  fi
  local out
  out="$(sh_ pm grant "$PKG" android.permission.WRITE_SECURE_SETTINGS)"
  if [ -z "$out" ]; then
    echo "OK : Fluide peut maintenant régler la vitesse des animations."
  else
    echo "Échec : $out"
    echo "Xiaomi, Redmi, POCO : activez « Débogage USB (paramètres de sécurité) » dans les options pour les développeurs."
    echo "Oppo, Realme, OnePlus : activez « Désactiver la surveillance des autorisations »."
    return 1
  fi
}

set_anim() {
  check_device || return 1
  local k
  for k in window_animation_scale transition_animation_scale animator_duration_scale; do
    sh_ settings put global "$k" "$1" >/dev/null
  done
  echo "Animations réglées à $1x."
}

compile_apps() {
  check_device || return 1
  echo "Compilation optimisée des applis (ART) : 5 à 30 minutes, téléphone branché de préférence."
  echo "Le téléphone reste utilisable mais sera plus lent pendant l'opération."
  confirm "Lancer maintenant ?" || return 0
  local start sdk log rc
  start=$(date +%s)
  sdk="$(sh_ getprop ro.build.version.sdk)"
  log="$(mktemp)"
  if [ "${sdk:-0}" -ge 34 ] 2>/dev/null; then
    # Android 14+ : « bg-dexopt-job » lance la tâche de nuit, que le système interrompt quand le
    # téléphone n'est pas inactif et en charge. Même optimisation, mais au premier plan :
    "$ADB" shell pm art dexopt-packages -r bg-dexopt </dev/null 2>&1 | tr -d '\r' | tee "$log" | tail -n 5
  else
    "$ADB" shell cmd package bg-dexopt-job </dev/null 2>&1 | tr -d '\r' | tee "$log" | tail -n 5
  fi
  rc=${PIPESTATUS[0]}
  if ! compile_ok "$rc" "$log"; then
    echo "Méthode principale interrompue ou indisponible : compilation de secours (plus longue)…"
    "$ADB" shell cmd package compile -m speed-profile -a </dev/null 2>&1 | tr -d '\r' | tee "$log" | tail -n 3
    rc=${PIPESTATUS[0]}
    if ! compile_ok "$rc" "$log"; then
      rm -f "$log"
      echo "Compilation interrompue par le téléphone. Réessayez branché, sans l'utiliser pendant l'opération."
      return 1
    fi
  fi
  rm -f "$log"
  echo "Terminé en $(( ($(date +%s) - start) / 60 )) min : applis optimisées."
}

# Réussite = code de retour nul ET journal sans annulation ni erreur.
# (« To cancel it, run … » est une simple consigne : on cherche « cancelled », pas « cancel ».)
compile_ok() {
  [ "$1" -eq 0 ] && ! grep -qiE 'cancelled|unknown command|exception|^error' "$2"
}

trim_storage() {
  check_device || return 1
  local out
  out="$(sh_ sm idle-maint run)"
  if [ -z "$out" ]; then
    echo "Maintenance du stockage lancée (TRIM) : elle se poursuit quelques minutes sur le téléphone."
  else
    echo "$out"
  fi
}

force_refresh() {
  check_device || return 1
  local hz
  hz="$(max_hz)"
  if [ "$hz" -le 61 ]; then
    echo "Écran limité à 60 Hz : rien à forcer."
    return 0
  fi
  echo "Fréquence maximale détectée : $hz Hz. La forcer en permanence coûte de l'autonomie."
  echo "Sans effet sur certaines marques (dont Samsung) : utilisez alors Paramètres › Affichage."
  confirm "Forcer $hz Hz ?" || return 0
  if [ ! -f "$SAVE_SCREEN" ]; then
    printf 'peak_refresh_rate=%s\nmin_refresh_rate=%s\n' \
      "$(sh_ settings get system peak_refresh_rate)" "$(sh_ settings get system min_refresh_rate)" > "$SAVE_SCREEN"
  fi
  sh_ settings put system peak_refresh_rate "$hz.0" >/dev/null
  sh_ settings put system min_refresh_rate "$hz.0" >/dev/null
  echo "Fait. Valeurs d'origine sauvegardées dans $SAVE_SCREEN (option 8 pour revenir)."
}

restore_refresh() {
  check_device || return 1
  if [ -f "$SAVE_SCREEN" ]; then
    local k v
    while IFS='=' read -r k v; do
      [ -z "$k" ] && continue
      if [ -z "$v" ] || [ "$v" = "null" ]; then
        sh_ settings delete system "$k" >/dev/null
      else
        sh_ settings put system "$k" "$v" >/dev/null
      fi
    done < "$SAVE_SCREEN"
    rm -f "$SAVE_SCREEN"
    echo "Réglage d'écran d'origine rétabli."
  else
    sh_ settings delete system min_refresh_rate >/dev/null
    sh_ settings delete system peak_refresh_rate >/dev/null
    echo "Aucune sauvegarde : réglage du constructeur rétabli. Ajustez si besoin dans Paramètres › Affichage."
  fi
}

disable_bloat() {
  check_device || return 1
  local enabled entry pkg name out found=0
  enabled="$(sh_ pm list packages -e)"
  for entry in "${BLOAT[@]}"; do
    pkg="${entry%%|*}"
    name="${entry#*|}"
    grep -qx "package:$pkg" <<< "$enabled" || continue
    found=1
    confirm "Désactiver $name ($pkg) ?" || continue
    out="$(sh_ pm disable-user --user 0 "$pkg")"
    if grep -q "disabled-user" <<< "$out"; then
      echo "disable $pkg" >> "$SAVE_APPS"
      echo "  → désactivée"
    elif grep -q "Success" <<< "$(sh_ pm uninstall -k --user 0 "$pkg")"; then
      # Certaines surcouches (Xiaomi) refusent la désactivation : retrait pour l'utilisateur, réversible.
      echo "uninstall $pkg" >> "$SAVE_APPS"
      echo "  → retirée pour votre utilisateur (réversible avec l'option 10)"
    else
      echo "  → impossible : $out"
    fi
  done
  if [ "$found" -eq 0 ]; then echo "Aucune appli de la liste n'est active sur ce téléphone."; fi
}

restore_bloat() {
  check_device || return 1
  if [ ! -f "$SAVE_APPS" ]; then
    echo "Aucune appli désactivée par ce script."
    return 0
  fi
  local action pkg
  while read -r action pkg; do
    case "$action" in
      disable) sh_ pm enable "$pkg" ;;
      uninstall) sh_ cmd package install-existing "$pkg" ;;
    esac
  done < "$SAVE_APPS"
  mv -f "$SAVE_APPS" "$SAVE_APPS.restaure"
  echo "Applis réactivées."
}

trim_caches() {
  check_device || return 1
  echo "Le cache accélère les applis : le vider ne sert que si le stockage est presque plein."
  echo "Stockage : $(sh_ "df -h /data | tail -n 1" | awk '{print $4 " libres sur " $2 " (" $5 " utilisés)"}')"
  confirm "Vider le cache de toutes les applis ?" || return 0
  local out
  out="$(sh_ pm trim-caches 999G)"
  if [ -z "$out" ]; then echo "Cache vidé."; else echo "$out"; fi
}

recommended() {
  check_device || return 1
  if is_installed; then grant; fi
  set_anim 0.5
  trim_storage
  compile_apps
}

menu() {
  cat <<'EOF'
==================================================
  Fluide : optimisations Android par ADB
==================================================
   1. Diagnostic (lecture seule)
   2. Autoriser l'appli Fluide à régler les animations
   3. Animations 0,5x (plus réactif)
   4. Animations 1x (par défaut)
   5. Optimiser la compilation des applis (5 à 30 min)
   6. Maintenance du stockage (TRIM)
   7. Écran : forcer la fréquence maximale
   8. Écran : revenir au réglage d'origine
   9. Désactiver des applis préinstallées (liste sûre)
  10. Réactiver les applis désactivées par ce script
  11. Vider le cache de toutes les applis (si stockage saturé)
   I. Installer ou mettre à jour l'appli Fluide
   A. Tout le recommandé (2 + 3 + 6 + 5)
   Q. Quitter
EOF
}

run_choice() {
  case "$1" in
    1) diagnostic ;;
    2) grant ;;
    3) set_anim 0.5 ;;
    4) set_anim 1 ;;
    5) compile_apps ;;
    6) trim_storage ;;
    7) force_refresh ;;
    8) restore_refresh ;;
    9) disable_bloat ;;
    10) restore_bloat ;;
    11) trim_caches ;;
    [iI]) install_app ;;
    [aA]) recommended ;;
    [qQ]) exit 0 ;;
    *) echo "Choix inconnu : $1" ; return 1 ;;
  esac
}

if [ "$#" -gt 0 ]; then
  status=0
  for c in "$@"; do run_choice "$c" || status=1; done
  exit "$status"
fi

while true; do
  echo
  menu
  read -r -p "Votre choix : " choice || exit 0
  echo
  run_choice "$choice"
  echo
  read -r -p "Entrée pour revenir au menu…" _ || exit 0
done
