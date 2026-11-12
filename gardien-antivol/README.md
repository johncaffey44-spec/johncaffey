# Gardien — application Android (antivol)

Application compagnon du tableau de bord **Gardien** (`gardien.html` + `gardien-api.php`).
Pour **vos propres appareils**, **en clair** : une fois installée, l'application est **visible**
(icône dans le menu + **notification permanente « Gardien — protection active »**). Il n'y a
**aucun mode caché**, aucune activation silencieuse du micro ou de l'écran. Un appareil ne rejoint
le parc qu'avec un **code d'appairage à usage unique** que vous générez depuis le tableau de bord.

> ⚠️ Installer ce type d'outil sur le téléphone d'un tiers à son insu est illégal en France
> (art. 226-1 et s. du Code pénal). Gardien est conçu pour l'usage légitime : retrouver, verrouiller
> ou effacer **un appareil qui vous appartient**.

---

## 1. Côté serveur (5 minutes)

Déposez `gardien-api.php` **à côté** de `gardien.html` sur le même serveur interne que le planning
(PHP 7.4+). À la première ouverture de `gardien.html`, créez le mot de passe administrateur.
Les données sont stockées en JSON sous `data/gardien/` (comme le planning).

- Servez en **HTTPS** si l'appareil doit joindre le serveur depuis l'extérieur (réseau mobile).
- `$ALLOWED_NETS` (dans `gardien-api.php`) limite l'accès au **tableau de bord** aux IP internes ;
  les **appareils** s'authentifient par jeton, pas par IP.

## 2. Côté Android — construire l'APK

Aucun SDK Android n'est fourni ici : l'APK se construit sur votre poste.

**Option simple — Android Studio :**
1. *File ▸ Open* → sélectionnez le dossier `gardien-antivol/`.
2. Laissez Gradle se synchroniser (il télécharge l'AGP et le SDK au besoin).
3. *Build ▸ Build App Bundle(s) / APK(s) ▸ Build APK(s)*.
4. L'APK de debug se trouve dans `app/build/outputs/apk/debug/app-debug.apk`.

**Option ligne de commande** (SDK Android installé, `ANDROID_HOME` défini) :
```bash
cd gardien-antivol
# première fois seulement, pour générer le wrapper :
gradle wrapper --gradle-version 8.9
./gradlew assembleDebug
```

Installez l'APK sur le téléphone (transfert direct + « sources inconnues », ou `adb install`).

## 3. Appairer

1. Dans `gardien.html`, cliquez **Ajouter** → un code (ex. `ABCD-1234`) et l'adresse du serveur s'affichent.
2. Ouvrez **Gardien** sur le téléphone, saisissez l'adresse (`…/gardien-api.php`) et le code, puis **Appairer**.
3. Accordez les autorisations proposées (localisation, notifications) et activez l'**administration
   d'appareil** pour le verrouillage/effacement à distance.

---

## Ce que fait la v1

| Commande | État | Détail |
|---|---|---|
| 📍 Localiser | ✅ | `LocationManager` natif (sans Google Play Services), position fraîche à la demande + dernière connue |
| 🔔 Faire sonner | ✅ | alarme forte même en silencieux, durée réglable, vibration |
| 🔒 Verrouiller | ✅ | `DevicePolicyManager.lockNow()` (administration d'appareil) |
| 💬 Message | ✅ | notification plein écran par-dessus le verrouillage |
| 🗑️ Effacer | ✅ | `wipeData()` — double confirmation côté serveur |
| 📸 Photo | ⏳ | la commande circule ; **capture CameraX + envoi au serveur à l'incrément suivant** |

La relève se fait toutes les ~20 s (réglable côté serveur via `$HEARTBEAT_SEC`). L'appareil **pousse**
son état et **récupère** les commandes : pas besoin d'ouvrir de port entrant sur le téléphone.

## Autorisations et pourquoi

- **Localisation** (dont « en permanence ») : retrouver l'appareil, y compris quand l'app n'est pas à l'écran.
- **Notifications** : la notification permanente (visibilité) et les messages plein écran.
- **Administration d'appareil** : verrouiller / effacer à distance. Révocable à tout moment dans
  *Paramètres ▸ Sécurité ▸ Applications d'administration*.
- **Service de premier plan** (`location`+`dataSync`) : maintenir la relève en tâche de fond, **visiblement**.

## Limites connues (honnêteté technique)

- **« Même éteint » n'existe pas** : un téléphone sans alimentation n'émet rien. Gardien relève la
  position tant que l'appareil est allumé et connecté ; la dernière position connue reste visible ensuite.
- **Android 14** restreint les notifications plein écran : l'utilisateur peut devoir autoriser
  `USE_FULL_SCREEN_INTENT` ; sinon le message s'affiche en bannière haute priorité.
- Le démarrage auto après reboot d'un service de type *localisation* peut être limité par l'OS : Gardien
  retombe alors sur le type *dataSync* jusqu'à réouverture de l'app.
- Trafic en clair autorisé (`usesCleartextTraffic`) pour les serveurs internes en HTTP — préférez HTTPS.

## Retirer Gardien proprement

1. Dans l'app : **Dissocier cet appareil**.
2. *Paramètres ▸ Sécurité ▸ Applications d'administration* : désactivez Gardien.
3. Désinstallez l'application.

---

## Structure

```
gardien-antivol/
├─ settings.gradle.kts · build.gradle.kts · gradle/libs.versions.toml   (build)
└─ app/src/main/
   ├─ AndroidManifest.xml
   ├─ java/com/d8/gardien/
   │  ├─ MainActivity.kt            écran appairage + état (Compose)
   │  ├─ GardienApp.kt              canaux de notification
   │  ├─ Prefs.kt                   réglages persistants
   │  ├─ net/Api.kt                 client HTTP (HttpURLConnection + org.json)
   │  ├─ service/GardienService.kt  service de premier plan + boucle de relève
   │  ├─ service/Notifier.kt        notifications (permanente, actions, message)
   │  ├─ service/BootReceiver.kt    relance au démarrage
   │  ├─ admin/…DeviceAdminReceiver.kt  verrouillage / effacement
   │  ├─ cmd/LocationOnce.kt        localisation en un coup
   │  ├─ cmd/Commands.kt            sonnerie / verrouillage / effacement
   │  └─ ui/MessageActivity.kt      message plein écran
   └─ res/                          icônes, thèmes, politiques d'administration
```
