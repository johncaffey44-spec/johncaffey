#!/bin/sh
# Récupère WireGuardKit (dépôt officiel wireguard-apple) à une version figée,
# puis corrige son Package.swift pour les Xcode récents :
# il annonce « swift-tools-version:5.3 » mais utilise .macOS(.v12) / .iOS(.v15),
# qui exigent la 5.5 (erreur « 'v12' is unavailable » avec Xcode 16),
# et ajoute un #include manquant pour Xcode 16.
set -eu
cd "$(dirname "$0")/.."
REV=2fec12a6e1f6e3460b6ee483aa00ad29cddadab1
DEST=ThirdParty/wireguard-apple

if [ ! -d "$DEST/.git" ]; then
  mkdir -p ThirdParty
  git clone --quiet https://github.com/WireGuard/wireguard-apple.git "$DEST"
fi
git -C "$DEST" checkout --quiet --force "$REV"
perl -pi -e 's#^// swift-tools-version:5\.3#// swift-tools-version:5.5#' "$DEST/Package.swift"

# Xcode 16 : WireGuardKitC utilise u_int32_t & co. sans inclure <sys/types.h>
# (correctif de la PR WireGuard/wireguard-apple#33, jamais fusionnée).
H="$DEST/Sources/WireGuardKitC/WireGuardKitC.h"
grep -q '<sys/types.h>' "$H" || perl -0pi -e 's|(#include "key.h")|#include <sys/types.h>\n\n$1|' "$H"
head -1 "$DEST/Package.swift"
echo "WireGuardKit prêt dans $DEST (révision $REV)"
