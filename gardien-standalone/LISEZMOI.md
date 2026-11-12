# Gardien — version autonome (backend `api.php`, accès par IP)

Cette version déploie Gardien **tout seul**, sur un dossier servi par ton serveur
interne et accessible par **adresse IP**, comme tes autres outils (tête pensante,
diapo…). Le backend s'appelle ici **`api.php`** (et non `gardien-api.php`), sans
aucun lien avec le planning D8.

## Contenu

- `api.php` — le backend (copie de `gardien-api.php`, le code se référence par son
  propre nom de fichier : il fonctionne donc aussi bien sous `api.php`).
- `index.html` — le tableau de bord (copie de `gardien.html` ; il **détecte seul**
  s'il parle à `api.php` ou `gardien-api.php`).
- `gardien.apk` — l'application Android (à déposer ici pour le téléchargement par QR).

## Déploiement (IIS / Windows)

1. Crée un dossier dans ta racine web, p. ex. `C:\inetpub\wwwroot\gardien\`.
2. Copies-y **`api.php`, `index.html`** et **`gardien.apk`**.
3. Donne au compte IIS (`IIS_IUSRS`) le droit **Modifier** sur ce dossier (pour que
   `data\gardien` puisse s'écrire). Le script `Installer-Gardien.ps1` le fait, ou :
   `icacls "C:\inetpub\wwwroot\gardien" /grant "*S-1-5-32-568:(OI)(CI)M" /T`

## Accès par IP

- Sur un poste du réseau interne, ouvre **`http://<IP-du-serveur>/gardien/`**
  (p. ex. `http://192.168.1.50/gardien/`). Le tableau de bord s'ouvre.
- Crée le compte administrateur, puis **Ajouter** : le QR et le lien d'appairage
  reprennent **automatiquement cette IP** (rien n'est figé en « localhost »).
- Le **téléphone doit être sur le même réseau Wi-Fi** que le serveur pour joindre
  cette IP. Scanne le QR → l'app se télécharge, s'installe, s'appaire sur `http://<IP>/gardien/api.php`.

> HTTPS : en IP + HTTP, batterie et jetons circulent en clair sur ton réseau interne.
> C'est acceptable sur un LAN de confiance ; pour un accès depuis l'extérieur (4G),
> il faut un vrai nom de domaine en HTTPS.

## À savoir

`api.php` et `index.html` sont des **copies** de `gardien-api.php` et `gardien.html`.
Si tu modifies les originaux, recopie-les ici (ou relance la génération du zip).
