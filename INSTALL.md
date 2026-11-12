# Gardien — antivol pour vos propres appareils · Installation

Gardien localise, verrouille, fait sonner, affiche un message, photographie (avec
notification visible) et efface **vos propres appareils**, depuis un tableau de bord
hébergé sur **votre serveur interne**. L'application Android est **visible** (icône +
notification permanente) : aucun mode caché. Un appareil ne rejoint le parc qu'avec un
**code d'appairage à usage unique** que vous générez.

## Contenu de l'archive

```
gardien/
├─ gardien-api.php          backend (à déposer sur le serveur)
├─ gardien.html             tableau de bord (à déposer sur le serveur)
├─ gardien.apk              l'application Android, déjà compilée (déposée par l'installeur)
├─ Installer-Gardien.cmd    installeur Windows/IIS — DOUBLE-CLIC
├─ Installer-Gardien.ps1    (appelé par le .cmd)
├─ install.sh              installeur Linux (Apache/nginx) — bonus
├─ INSTALL.md              ce guide
└─ gardien-antivol/         projet Android Studio (les sources de l'app, pour recompiler)
```

---

## 1. Serveur — Windows 11 Pro + IIS + PHP (votre cas)

> Pré-requis : IIS + PHP déjà en place (votre `Installer-IIS-PHP`). Gardien n'y touche pas.

1. Décompressez l'archive.
2. **Double-cliquez `Installer-Gardien.cmd`** et acceptez l'élévation administrateur.
   Le script :
   - détecte le dossier de votre planning (celui qui contient `api.php`), ou vous le demande ;
   - y copie `gardien-api.php` et `gardien.html` ;
   - crée `data\gardien`, le **protège** (web.config : accès direct refusé) et accorde
     l'**écriture à IIS** (groupe `IIS_IUSRS`).
3. À la fin, il affiche l'adresse à ouvrir, par ex. `http://localhost/gardien.html`.

**Méthode manuelle équivalente**, si vous préférez : copiez les deux fichiers à côté de
`planning-d8.html`, créez `data\gardien`, et donnez à `IIS_IUSRS` le droit « Modifier » sur `data`.

## 1 bis. Serveur — Linux (si un jour vous migrez)

```bash
sudo ./install.sh            # auto-détecte /var/www/html, etc.
# ou : sudo ./install.sh /chemin/du/site
```

## 2. Première ouverture du tableau de bord

1. Ouvrez `…/gardien.html` depuis un poste **du réseau interne** (l'accès admin est
   limité aux IP internes).
2. Créez le **compte administrateur (propriétaire)** : identifiant + mot de passe.
3. *(facultatif)* Menu « administrateurs » (icône en haut à droite) : ajoutez des comptes.

> **HTTPS** : si les téléphones doivent joindre le serveur depuis l'extérieur (4G/5G),
> servez le site en HTTPS. Sinon codes et jetons circulent en clair.

---

## 3. Application Android — construire l'APK

Aucun SDK Android n'est fourni ; l'APK se construit sur un poste avec **Android Studio**.

1. *File ▸ Open* → dossier `gardien-antivol/`.
2. Laissez Gradle se synchroniser (il télécharge ce qu'il faut).
3. *Build ▸ Build App Bundle(s) / APK(s) ▸ Build APK(s)*.
4. APK : `gardien-antivol/app/build/outputs/apk/debug/app-debug.apk`.
5. Installez-le sur le téléphone (« sources inconnues », ou `adb install`).

En ligne de commande (SDK Android installé) :
```bash
cd gardien-antivol
gradle wrapper --gradle-version 8.9   # une seule fois
./gradlew assembleDebug
```

## 4. Appairer un téléphone (le plus simple)

1. Dans le tableau de bord : **Ajouter** → un **QR** + un code (30 min, usage unique) s'affichent.
2. Sur le téléphone, **scannez le QR avec l'appareil photo** : il ouvre une page qui
   propose de **télécharger l'app** (`gardien.apk` servie par le serveur), puis un bouton
   **« Ouvrir dans Gardien »** qui lance l'app **déjà remplie** (serveur + code) → appairage automatique.
3. Accordez les autorisations proposées (localisation, notifications, caméra), **« ne pas limiter
   la batterie »**, et activez l'**administration d'appareil** (pour verrouillage / effacement). C'est suivi.

> **Téléphone utilisé au quotidien** (pas seulement un mobile de test) : même procédure. En plus,
> accordez l'exemption de batterie dans l'app, et sur Samsung / Xiaomi / Oppo autorisez le
> **démarrage automatique** de Gardien (réglages Batterie du constructeur) — sinon le système peut
> couper le suivi. Si **Play Protect** prévient à l'installation, choisissez « Installer quand même ».

> Le téléphone a déjà Gardien ? Ouvrez l'app → **Scanner le QR** (bouton dans l'app) : il lit
> directement le serveur et le code. La saisie manuelle (adresse `…/gardien-api.php` + code) reste possible.

## 5. Retirer proprement

Dans l'app : **Dissocier** → *Paramètres ▸ Sécurité ▸ Applications d'administration* : désactiver
Gardien → **désinstaller** l'application.

---

## Rappels honnêtes

- **« Même éteint » n'existe pas** : un téléphone sans courant n'émet rien. Gardien suit la
  position tant que l'appareil est allumé et connecté, et garde la dernière position connue.
- **Usage légitime uniquement** : vos appareils, ou un parc professionnel déclaré avec charte.
  Installer ce type d'outil sur le téléphone d'un tiers à son insu est illégal (art. 226-1 et s. du Code pénal).
