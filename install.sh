#!/usr/bin/env bash
# =====================================================================
#  Gardien - antivol : installation sur serveur Linux (Apache/nginx + PHP)
#  (Pour Windows/IIS, utilisez plutot Installer-Gardien.cmd.)
#  Usage :  sudo ./install.sh [/dossier/web]
# =====================================================================
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
DEST="${1:-}"

echo "=== Installation de Gardien (antivol) ==="

if [ -z "$DEST" ]; then
  for c in /var/www/html /var/www /srv/http /usr/share/nginx/html; do
    if [ -f "$c/api.php" ]; then DEST="$c"; break; fi
  done
  if [ -z "$DEST" ]; then read -rp "Dossier web de destination : " DEST; fi
fi
[ -d "$DEST" ] || { echo "Dossier introuvable : $DEST" >&2; exit 1; }
echo "Destination : $DEST"

cp "$HERE/gardien-api.php" "$HERE/gardien.html" "$DEST/"
echo "Fichiers copies : gardien-api.php, gardien.html"
if [ -f "$HERE/gardien.apk" ]; then
  cp "$HERE/gardien.apk" "$DEST/"
  echo "APK copiee : telechargeable depuis la page d'installation (scan du QR)."
else
  echo "Note : gardien.apk absente ; la page d'installation ne proposera pas le telechargement."
fi

mkdir -p "$DEST/data/gardien"
# Protection de l'acces direct au dossier data (Apache + IIS). gardien-api.php les recree aussi.
[ -f "$DEST/data/.htaccess" ]  || printf 'Require all denied\nDeny from all\n' > "$DEST/data/.htaccess"
[ -f "$DEST/data/web.config" ] || printf '%s\n' '<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer><security><requestFiltering><fileExtensions allowUnlisted="false" /></requestFiltering></security></system.webServer></configuration>' > "$DEST/data/web.config"

# Droits d'ecriture pour le compte du serveur web
WEBUSER="$(ps -o user= -C apache2 -C httpd -C nginx -C php-fpm 2>/dev/null | sort -u | grep -v '^root$' | head -1 || true)"
WEBUSER="${WEBUSER:-www-data}"
if id "$WEBUSER" >/dev/null 2>&1; then
  chown -R "$WEBUSER":"$WEBUSER" "$DEST/data" 2>/dev/null || true
  echo "Droits d'ecriture accordes a : $WEBUSER"
fi
chmod -R u+rwX "$DEST/data"

echo ""
echo "=== Termine ==="
echo "Ouvrez http://<serveur>/gardien.html et creez le compte administrateur."
echo "Servez en HTTPS si les telephones joignent le serveur depuis l'exterieur."
