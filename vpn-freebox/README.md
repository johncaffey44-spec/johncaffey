# VPN Maison — se connecter à sa Freebox depuis son smartphone

Application VPN personnelle (Android + iPhone) qui se connecte au **serveur WireGuard intégré à la Freebox**,
et tutoriel PDF illustré pour débutants.

| Fichier | Pour quoi faire |
|---|---|
| [`dist/Tutoriel-VPN-Freebox.pdf`](dist/Tutoriel-VPN-Freebox.pdf) | **Commencez ici.** Tutoriel pas à pas (19 pages, schémas avec flèches et encadrés) : réglage de la box, Android, iPhone, tests, dépannage, sécurité. |
| [`dist/VPN-Maison-1.0.0.apk`](dist/VPN-Maison-1.0.0.apk) | Application Android prête à installer (Android 7 et plus, 4 Mo). |
| [`dist/VPN-Maison-1.0.0-iOS-NON-SIGNE.ipa`](dist/VPN-Maison-1.0.0-iOS-NON-SIGNE.ipa) | Application iPhone compilée mais **non signée** : ne s'installe pas telle quelle (voir plus bas). |
| [`android/`](android) | Code source de l'application Android (Kotlin, Jetpack Compose). |
| [`ios/`](ios) | Code source de l'application iPhone (SwiftUI + extension réseau). Voir les limites ci-dessous. |
| [`tutoriel/`](tutoriel) | Générateur du PDF : `python3 build.py && node render.js`, puis copie du PDF dans `dist/`. |

## Comment ça marche

```
Smartphone (4G, Wi-Fi public…) ══ tunnel chiffré WireGuard ══▶ Freebox (serveur VPN) ──▶ réseau de la maison
```

1. Dans Freebox OS : *Paramètres de la Freebox › Mode avancé › Serveur VPN › WireGuard › Activer*,
   puis un **utilisateur WireGuard par appareil** et son **QR code**.
2. Dans l'application : *Scanner le QR code*, puis le gros bouton *Connecter*.

Le détail, avec les captures schématisées, est dans le PDF.

## Application Android « VPN Maison »

- Moteur : bibliothèque **officielle** WireGuard (`com.wireguard.android:tunnel`, WireGuard LLC, Apache-2.0).
  Aucun chiffrement « maison ».
- Import de la configuration : QR code (caméra), fichier `.conf` (ou `.zip`), ou copier-coller.
- Gros bouton marche/arrêt, preuve que la box répond (dernier échange de clés WireGuard), compteurs, durée.
- Bouton *Tester l'accès à ma Freebox* (lit `http://mafreebox.freebox.fr/api_version` à travers le tunnel).
- Interrupteur *Tout Internet passe par ma Freebox* : désactivé, seul le réseau de la maison passe dans le tunnel.
  Le DNS de la Freebox (`212.27.38.253`) est alors ajouté au tunnel ; sans cela, plus aucun site ne s'ouvre en 4G.
- Tuile des réglages rapides et compatibilité avec le *VPN permanent* d'Android.
- Configuration rangée uniquement dans le stockage privé de l'appli, **exclue des sauvegardes cloud** et des transferts.
- Ni publicité, ni statistiques, ni serveur tiers.

**Empreintes de l'APK 1.0.0** (pour vérifier qu'il n'a pas été modifié) :

- SHA-256 du fichier : `a65325ca5622f1266f01811d16dfbb4bd846dcc0dcf6d4de918c3135a94fdeeb`
- SHA-256 du certificat de signature : `a72480d76730a332ed73db57d2fce82a22118ad62fa348222cd049f4438ce1db`

### Compiler l'APK

```bash
cd vpn-freebox/android
./gradlew testReleaseUnitTest assembleRelease
```

Il faut le SDK Android (`local.properties` → `sdk.dir=…`) et Java 17 ou plus. La signature « release » se règle dans
`keystore.properties` (jamais publié, voir `.gitignore`). Sans clé, l'APK est signé avec une clé de test.
La compilation est aussi faite automatiquement par GitHub Actions (`.github/workflows/vpn-android.yml`).

> Un APK signé avec une autre clé ne peut pas mettre à jour l'application déjà installée : il faut alors la
> désinstaller puis réimporter le QR code (une minute).

## Application iPhone : ce qu'il faut savoir

Apple n'accorde la capacité *Network Extension* (indispensable à toute application VPN) qu'aux membres du
**programme Apple Developer, 99 $ par an**, et l'application doit être signée avec leur certificat. Un `.ipa` non signé
ne s'installe pas sur un iPhone normal ; les outils de « sideload » avec un identifiant Apple gratuit ne donnent pas
cette capacité.

- Le projet `ios/` (SwiftUI + extension `PacketTunnel` basée sur WireGuardKit) est fourni pour être complet.
- `.github/workflows/vpn-ios.yml` le compile sur un Mac de GitHub et produit un `.ipa` **non signé**
  (copie dans `dist/`, SHA-256 `bb010f2fadc3830e76dda9c1d03e2283f713e99bf475977b87be3d671abcafc8`).
- **Recommandation** : sur iPhone, utilisez l'application officielle gratuite **WireGuard** (App Store) avec le même
  QR code. Elle utilise le même moteur ; c'est la partie 3 du tutoriel.

Pour l'installer malgré tout (Mac + compte payant) :

```bash
brew install xcodegen go
cd vpn-freebox/ios
./scripts/prepare-wireguardkit.sh   # WireGuardKit figé + correctifs Xcode 16
xcodegen generate
open VPNMaison.xcodeproj
```

Puis choisissez votre équipe dans *Signing & Capabilities* pour les deux cibles et lancez ▶ sur l'iPhone.

`prepare-wireguardkit.sh` est nécessaire car le paquet officiel `wireguard-apple` (inchangé depuis 2023) ne se compile
plus tel quel avec Xcode 16 : manifeste en `swift-tools-version:5.3` incompatible avec `.iOS(.v15)`, et un
`#include <sys/types.h>` manquant dans `WireGuardKitC`.

## Limites connues (en toute franchise)

- L'APK a été compilé, signé, analysé (manifeste, bibliothèques natives) et sa logique de configuration est couverte
  par des tests unitaires. Il **n'a pas pu être essayé sur un vrai téléphone ni sur une vraie Freebox** avant la livraison.
- Le projet iPhone **se compile** sur macOS (Xcode 16.4, GitHub Actions) : l'extension `PacketTunnel.appex` du `.ipa`
  contient bien le moteur wireguard-go. Il **n'a pas été essayé sur un iPhone** : il faudrait le signer avec un compte
  Apple Developer payant.
- Les noms des menus Freebox OS viennent de la documentation disponible fin 2026 ; Free peut les changer.
- Google prévoit, à partir de 2027, de restreindre l'installation d'applications hors Play Store aux développeurs vérifiés
  (avec une procédure « avancée » pour les utilisateurs expérimentés). Solution de repli : l'application officielle WireGuard du Play Store.

## Sécurité

- **Un utilisateur WireGuard par appareil.** Téléphone perdu : supprimez son utilisateur dans Freebox OS.
- Ne partagez jamais le QR code ni le fichier `.conf` : ils contiennent la clé privée.
- Ce dépôt est **public** : n'y ajoutez jamais de fichier `.conf`, de capture du QR code ni de clé de signature.

« Freebox » est une marque de Free. Projet personnel, indépendant et non affilié.
