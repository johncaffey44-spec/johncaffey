#!/usr/bin/env bash
# Teste fluide.sh contre le faux adb : chaque option, ses effets et sa réversibilité.
# Lancement : bash scripts/test/test-fluide-sh.sh
set -u
T="$(cd "$(dirname "$0")" && pwd)"
SCRIPT="$T/../fluide.sh"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
cp "$SCRIPT" "$WORK/fluide.sh"
export ADB="$T/fake-adb.sh" FAKE_DIR="$WORK/phone" FLUIDE_OUI=1
PASS=0
FAIL=0

reset_phone() {
  rm -rf "$FAKE_DIR"; mkdir -p "$FAKE_DIR"
  printf '%s\n' app.fluide com.facebook.appmanager com.miui.analytics com.samsung.android.app.tips \
    com.facebook.services.extra com.example.notes > "$FAKE_DIR/enabled"
  rm -f "$WORK"/fluide-*.txt "$WORK"/fluide-*.restaure
}
run() { (cd "$WORK" && bash ./fluide.sh "$@" 2>&1); }
setting() { cat "$FAKE_DIR/settings/$1/$2" 2>/dev/null || echo "(absent)"; }
check() { # check <description> <condition...>
  local d="$1"; shift
  if "$@"; then PASS=$((PASS + 1)); echo "  ok   $d"; else FAIL=$((FAIL + 1)); echo "  ÉCHEC $d"; fi
}
has() { grep -qF -- "$2" <<< "$1"; }
eq() { [ "$1" = "$2" ]; }

echo "Syntaxe"
check "bash -n" bash -n "$SCRIPT"

echo "1. Diagnostic"
reset_phone
out="$(run 1)"
check "modèle affiché" has "$out" "Google Pixel Test"
check "fréquence max arrondie à 120 Hz" has "$out" "jusqu'à 120 Hz"
check "RAM convertie" has "$out" "7.5 Gio"
check "stockage lu" has "$out" "30G libres sur 110G (73% utilisés)"
check "température batterie" has "$out" "31.2 °C"
check "aucune commande inconnue" eq "$(grep -c 'commande inconnue' <<< "$out")" "0"

echo "2. Permission"
out="$(run 2)"
check "succès annoncé" has "$out" "OK : Fluide peut"
check "permission accordée" test -f "$FAKE_DIR/granted"
grep -v app.fluide "$FAKE_DIR/enabled" > "$FAKE_DIR/e2"; mv "$FAKE_DIR/e2" "$FAKE_DIR/enabled"
out="$(run 2)"
check "appli absente : message clair" has "$out" "n'est pas installée"

echo "I. Installation"
reset_phone
grep -v app.fluide "$FAKE_DIR/enabled" > "$FAKE_DIR/e2"; mv "$FAKE_DIR/e2" "$FAKE_DIR/enabled"
out="$(run i)"
check "sans APK : message clair" has "$out" "Aucun fichier Fluide*.apk"
touch "$WORK/Fluide-1.0.apk"
out="$(run i)"
check "APK installé" has "$out" "Success"
check "appli désormais présente" grep -qx app.fluide "$FAKE_DIR/enabled"
rm -f "$WORK/Fluide-1.0.apk"

echo "3/4. Animations"
reset_phone
run 3 >/dev/null
check "fenêtres 0.5" eq "$(setting global window_animation_scale)" "0.5"
check "transitions 0.5" eq "$(setting global transition_animation_scale)" "0.5"
check "durée 0.5" eq "$(setting global animator_duration_scale)" "0.5"
run 4 >/dev/null
check "retour à 1" eq "$(setting global animator_duration_scale)" "1"

echo "7/8. Fréquence d'écran (aucune valeur d'origine)"
reset_phone
out="$(run 7)"
check "120 Hz détectés" has "$out" "120 Hz"
check "peak forcé" eq "$(setting system peak_refresh_rate)" "120.0"
check "min forcé" eq "$(setting system min_refresh_rate)" "120.0"
check "sauvegarde créée" test -f "$WORK/fluide-sauvegarde-ecran.txt"
run 8 >/dev/null
check "peak supprimé" eq "$(setting system peak_refresh_rate)" "(absent)"
check "min supprimé" eq "$(setting system min_refresh_rate)" "(absent)"
check "sauvegarde consommée" test ! -f "$WORK/fluide-sauvegarde-ecran.txt"

echo "7/8. Fréquence d'écran (valeur d'origine 90)"
reset_phone
mkdir -p "$FAKE_DIR/settings/system"; echo "90.0" > "$FAKE_DIR/settings/system/peak_refresh_rate"
run 7 >/dev/null
run 7 >/dev/null # un second forçage ne doit pas écraser la sauvegarde d'origine
run 8 >/dev/null
check "peak d'origine rétabli" eq "$(setting system peak_refresh_rate)" "90.0"
check "min d'origine (absent) rétabli" eq "$(setting system min_refresh_rate)" "(absent)"

echo "9/10. Applis préinstallées"
reset_phone
out="$(run 9)"
check "Facebook App Manager désactivée" grep -qx com.facebook.appmanager "$FAKE_DIR/disabled"
check "Analytics Xiaomi désactivée" grep -qx com.miui.analytics "$FAKE_DIR/disabled"
check "Astuces Samsung désactivée" grep -qx com.samsung.android.app.tips "$FAKE_DIR/disabled"
check "correspondance exacte (pas com.facebook.services.extra)" grep -qx com.facebook.services.extra "$FAKE_DIR/enabled"
check "appli hors liste intacte" grep -qx com.example.notes "$FAKE_DIR/enabled"
check "journal de restauration" eq "$(wc -l < "$WORK/fluide-applis-desactivees.txt" | tr -d ' ')" "3"
run 10 >/dev/null
check "toutes réactivées" eq "$(wc -l < "$FAKE_DIR/disabled" | tr -d ' ')" "0"
check "réactivée (Facebook)" grep -qx com.facebook.appmanager "$FAKE_DIR/enabled"

echo "9/10. Surcouche qui refuse la désactivation (Xiaomi)"
reset_phone
out="$(FAKE_MIUI=1 run 9)"
check "repli : retrait pour l'utilisateur" grep -qx com.miui.analytics "$FAKE_DIR/removed"
check "journalisé en uninstall" grep -q "^uninstall com.miui.analytics$" "$WORK/fluide-applis-desactivees.txt"
run 10 >/dev/null
check "réinstallée (install-existing)" grep -qx com.miui.analytics "$FAKE_DIR/enabled"
check "plus rien de retiré" eq "$(wc -l < "$FAKE_DIR/removed" | tr -d ' ')" "0"

echo "5. Compilation, Android 14+ (dexopt-packages au premier plan)"
reset_phone
out="$(run 5)"
check "dexopt-packages lancé" grep -q "pm art dexopt-packages -r bg-dexopt" "$FAKE_DIR/log"
check "plus de bg-dexopt-job (interrompu par le système)" eq "$(grep -c 'bg-dexopt-job' "$FAKE_DIR/log")" "0"
check "« To cancel it » n'est pas pris pour une annulation" eq "$(grep -c 'compile -m speed-profile' "$FAKE_DIR/log")" "0"
check "réussite annoncée" has "$out" "applis optimisées"
reset_phone
out="$(FAKE_ART_CANCELLED=1 run 5)"
check "annulation détectée : repli speed-profile" grep -q "cmd package compile -m speed-profile -a" "$FAKE_DIR/log"
check "réussite après repli" has "$out" "applis optimisées"
reset_phone
out="$(FAKE_ART_MISSING=1 run 5)"
check "commande absente : repli speed-profile" grep -q "cmd package compile -m speed-profile -a" "$FAKE_DIR/log"

echo "5. Compilation, Android 13 et avant (bg-dexopt-job)"
reset_phone
out="$(FAKE_SDK=33 run 5)"
check "bg-dexopt-job lancé" grep -q "cmd package bg-dexopt-job" "$FAKE_DIR/log"
check "pas de repli inutile" eq "$(grep -c 'compile -m speed-profile' "$FAKE_DIR/log")" "0"
reset_phone
out="$(FAKE_SDK=33 FAKE_DEXOPT_FAIL=1 run 5)"
check "échec : repli speed-profile" grep -q "cmd package compile -m speed-profile -a" "$FAKE_DIR/log"
reset_phone
out="$(FAKE_SDK=33 FAKE_BG_CANCELLED=1 run 5)"
check "« Job cancelled » (code 0) détecté : repli" grep -q "cmd package compile -m speed-profile -a" "$FAKE_DIR/log"
check "jamais « Terminé » sur une annulation sans repli" has "$out" "Méthode principale interrompue"

echo "6/11. Stockage et cache"
out="$(run 6)"
check "TRIM lancé" grep -q "sm idle-maint run" "$FAKE_DIR/log"
out="$(run 11)"
check "cache vidé" grep -q "pm trim-caches 999G" "$FAKE_DIR/log"

echo "A. Tout le recommandé"
reset_phone
out="$(run a)"
check "permission" test -f "$FAKE_DIR/granted"
check "animations 0.5" eq "$(setting global window_animation_scale)" "0.5"
check "TRIM" grep -q "sm idle-maint run" "$FAKE_DIR/log"
check "compilation" grep -q "dexopt-packages" "$FAKE_DIR/log"
check "écran non touché" eq "$(setting system peak_refresh_rate)" "(absent)"

echo "Confirmation refusée"
reset_phone
out="$(cd "$WORK" && printf 'n\n' | FLUIDE_OUI='' bash ./fluide.sh 7 2>&1)"
check "rien forcé sans « o »" eq "$(setting system peak_refresh_rate)" "(absent)"

echo "Téléphone absent ou non autorisé"
out="$(FAKE_STATE=unauthorized run 3)"
check "message non autorisé" has "$out" "non autorisé"
out="$(FAKE_STATE=none run 1)"
check "message aucun téléphone" has "$out" "Aucun téléphone détecté"

echo "Menu interactif"
reset_phone
out="$(cd "$WORK" && printf '1\n\nq\n' | bash ./fluide.sh 2>&1)"
# (read -p n'affiche son invite que dans un vrai terminal : on vérifie le menu lui-même.)
check "menu affiché" has "$out" "Q. Quitter"
check "option exécutée depuis le menu" has "$out" "Pixel Test"
out="$(run 42)"
check "choix inconnu signalé" has "$out" "Choix inconnu"

echo
echo "Résultat : $PASS réussis, $FAIL échoués"
[ "$FAIL" -eq 0 ]
