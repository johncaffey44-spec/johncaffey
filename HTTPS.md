# Gardien — HTTPS (port 8443) sur serveur interne, avec iPhone et Android

Ton serveur est en **IP interne** : impossible d'avoir un certificat d'une autorité
publique (Let's Encrypt exige un nom de domaine public). On utilise donc un **certificat
auto-signé**, fait aux normes des téléphones, et on l'installe sur chaque appareil.

> **À savoir (honnêteté) :** si tes téléphones joignent déjà le serveur **via WireGuard**,
> le trafic est **déjà chiffré par le tunnel**. Le HTTPS ajoute surtout : plus d'avertissement
> « non sécurisé » dans le navigateur, et le chiffrement aussi quand tu es sur le **Wi-Fi local**
> (hors VPN). C'est propre, mais ce n'est pas indispensable si tu passes toujours par le VPN.

## 1. Générer le certificat sur le serveur (Windows / IIS)

1. **Double-clic sur `Certificat-HTTPS.cmd`** (accepte l'élévation admin). Le script :
   - crée un certificat auto-signé avec **l'IP du serveur dans le SAN** (obligatoire pour iPhone/Android),
     serverAuth, SHA-256, RSA 2048, valide ~820 jours (limite iOS) ;
   - l'associe à **IIS sur le port 8443**, ouvre le pare-feu ;
   - exporte le certificat **public** `gardien-ca.cer` (sans clé privée) à côté du script.

2. Si le téléphone joint le serveur par **une autre adresse** que l'IP LAN (p. ex. son IP WireGuard),
   ajoute-la pour qu'elle soit dans le certificat :
   ```
   powershell -ExecutionPolicy Bypass -File Certificat-HTTPS.ps1 -Ip 192.168.1.50 -ExtraNames 10.8.0.1,gardien.lan
   ```
   (Mets **toutes** les adresses par lesquelles un appareil ouvre le serveur.)

3. Accès : **`https://<IP>:8443/gardien/`**.

## 2. iPhone / iPad

1. Envoie `gardien-ca.cer` sur l'appareil (AirDrop, mail, ou pose-le dans le dossier web et
   ouvre `http://<IP>/gardien/gardien-ca.cer` dans Safari).
2. *Réglages* → **Profil téléchargé** → *Installer* (en haut à droite), code, *Installer*.
3. **Étape cruciale** : *Réglages* → *Général* → *Informations* → **Réglages de confiance des
   certificats** → active l'interrupteur en face de **Gardien**. Sans ça, iOS refuse le certificat.
4. Ouvre **`https://<IP>:8443/gardien/`** dans Safari : plus d'avertissement.

## 3. Android

1. Mets la **nouvelle APK** (celle fournie avec cette mise à jour) : elle est configurée pour
   accepter les certificats que tu installes toi-même.
2. Installe le certificat : copie `gardien-ca.cer` sur le téléphone →
   *Paramètres* → *Sécurité* → *Chiffrement & identifiants* → **Installer un certificat** →
   **Certificat CA** → choisis le fichier → *Installer quand même*.
3. Appaire le téléphone **depuis le tableau de bord ouvert en HTTPS** (`https://<IP>:8443/gardien/`) :
   le QR et l'appairage utiliseront alors l'adresse HTTPS automatiquement.

> Tu préfères ne rien installer sur Android ? Laisse l'app en **HTTP via WireGuard** (déjà chiffré
> par le tunnel) : appaire depuis `http://<IP>/gardien/`. Le HTTPS reste utile pour le **navigateur**
> et l'**iPhone**.

## 4. iPhone : le point important sur l'application

Il n'existe **pas d'application Gardien pour iOS** (iOS n'autorise pas une app de localisation/
caméra en arrière-plan installée hors App Store, sans compte développeur ni MDM — c'est une limite
d'Apple, pas un oubli). Sur iPhone, tu peux :

- **Piloter tes appareils Android** depuis le tableau de bord, dans Safari (une fois le certificat accepté).
- **Protéger un iPhone lui-même** : utilise **« Localiser » (Find My)**, intégré à iOS — il localise,
  fait sonner, verrouille et efface à distance, mieux et plus légalement que n'importe quelle app
  chargée de force. C'est la bonne solution pour tes propres iPhone/iPad, et elle est gratuite.

Si un jour tu veux un vrai agent iOS (parc d'iPhone pro), ça passe par une inscription **MDM /
supervision** — dis-le-moi et je te montre la voie.
