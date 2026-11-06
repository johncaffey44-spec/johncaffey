# Boussole sur un PC Windows 11 à la maison (IIS + PHP)

Ce guide installe Boussole sur le PC qui sert déjà de serveur web (tutoriel « Serveur web IIS + PHP sur Windows 11 Pro »).
Le script fait tout le travail et peut être relancé autant de fois que vous voulez : **c'est aussi la procédure de mise à jour**.
Il ne touche jamais à vos données ni à vos sauvegardes.

## 1. Avant de commencer

- IIS et PHP sont installés (script `Installer-IIS-PHP.cmd` du tutoriel 1) : `http://localhost/` affiche la page d'IIS.
- PHP est dans `C:\PHP` (sinon, ajoutez l'option `-DossierPHP`).
- Téléchargez Boussole (bouton **Code › Download ZIP** sur GitHub), puis **avant de décompresser** :
  clic droit sur le fichier ZIP › **Propriétés** › cochez **Débloquer** › **OK**. Décompressez ensuite tout le ZIP.

## 2. Installer ou mettre à jour

1. Ouvrez le dossier `finances\windows` du ZIP décompressé.
2. Double-cliquez sur **`Installer-Boussole.cmd`** et acceptez la demande d'autorisation de Windows.
   Si Windows affiche « Windows a protégé votre ordinateur » : **Informations complémentaires › Exécuter quand même**.
3. Lisez le résumé affiché à la fin : il donne l'adresse à ouvrir sur ce PC et depuis votre téléphone.

Pour vérifier d'abord sans rien modifier, ouvrez PowerShell **en tant qu'administrateur** dans le dossier `windows` et tapez :

```powershell
.\Installer-Boussole.ps1 -Simulation
```

### Ce que fait le script

| Étape | Détail |
|---|---|
| Vérifications | Windows, IIS avec FastCGI, site « Default Web Site », PHP 7.4 ou plus, lien entre IIS et PHP |
| Extensions PHP | active `pdo_sqlite`, `openssl`, `sodium`, `mbstring`, `fileinfo` et règle la taille des envois si besoin. **php.ini est copié avant toute modification** (`php.ini.avant-boussole-…`) |
| Copie | `C:\inetpub\wwwroot\finances` (le site est arrêté quelques secondes) |
| Données | `C:\BoussoleDonnees`, **hors du site web**, lisible seulement par IIS, le système et les administrateurs. Une ancienne installation (dossier `data` dans le site) y est déplacée automatiquement |
| Réglages | `config.local.php` : emplacement des données et accès réservé aux appareils de la maison |
| Contrôle | l'application répond, IIS écrit dans le dossier des données, la base, la clé et le code sont **invisibles depuis un navigateur** |
| Tâches planifiées | **Veille** chaque jour à 07:05 (alertes par e-mail) et **Sauvegarde** chaque jour à 03:15, puis une première sauvegarde |
| Pare-feu | signale un port 80 fermé ou ouvert à tout Internet ; avec `-PareFeu`, le limite au réseau local |

Un journal complet est écrit dans `C:\ProgramData\Boussole\installation-AAAAMMJJ-HHMMSS.log`.

### Options

| Option | Par défaut | Rôle |
|---|---|---|
| `-DossierSite` | `C:\inetpub\wwwroot\finances` | dossier de l'application (adresse `http://localhost/finances/`) |
| `-DossierDonnees` | `C:\BoussoleDonnees` | base, documents chiffrés, clé de chiffrement |
| `-DossierSauvegardes` | `C:\BoussoleSauvegardes` | **mettez de préférence un autre disque**, par exemple `E:\Sauvegardes\Boussole` |
| `-GarderSauvegardes` | `30` | nombre de sauvegardes conservées |
| `-HeureVeille`, `-HeureSauvegarde` | `07:05`, `03:15` | heures des tâches planifiées |
| `-DossierPHP` | `C:\PHP` | dossier de PHP |
| `-NomSite` | `Default Web Site` | site IIS qui publie l'application |
| `-Acces` | `local` | `local` : appareils de la maison et VPN Tailscale seulement ; `tout` : aucun filtrage |
| `-PareFeu` | (désactivé) | ouvre le port 80 au seul réseau local, désactive les règles ouvertes à tous, passe le réseau en profil Privé |
| `-SansTaches` | (désactivé) | ne programme ni veille ni sauvegarde |
| `-SansExtensions` | (désactivé) | ne modifie pas php.ini |
| `-Simulation` | (désactivé) | vérifie et décrit sans rien modifier |
| `-Desinstaller` | (désactivé) | retire l'application et les tâches ; **données et sauvegardes conservées** |

Exemple :

```powershell
.\Installer-Boussole.ps1 -DossierSauvegardes 'E:\Sauvegardes\Boussole' -GarderSauvegardes 60 -PareFeu
```

## 3. Après l'installation

1. Ouvrez `http://localhost/finances/` : créez votre accès (adresse e-mail et mot de passe) et **notez le code de secours** sur papier.
2. **Réglages › Envoi des e-mails** : avec Gmail, créez un « mot de passe d'application » (compte Google › Sécurité ›
   Validation en deux étapes › Mots de passe des applications), serveur `smtp.gmail.com`, port 587, TLS. Puis **Envoyer un test**.
   Les adresses Outlook.com / Hotmail / Live n'acceptent plus l'envoi par mot de passe depuis septembre 2024 : utilisez Gmail
   ou votre fournisseur d'accès comme serveur d'envoi ; vos alertes peuvent quand même arriver sur votre adresse Outlook.
3. **Foyer** : ajoutez les autres personnes. Chacune reçoit un code d'invitation (et un e-mail si l'envoi est réglé) et choisit son mot de passe.
4. **Téléphone et autres PC de la maison** : ouvrez l'adresse `http://192.168.x.x/finances/` donnée par le résumé.
   Dans Freebox OS, réservez cette adresse au PC (**Paramètres de la Freebox › DHCP › Baux statiques**) pour qu'elle ne change pas.
5. **Hors de la maison** : installez Tailscale sur le PC et sur le téléphone, puis ouvrez `http://100.x.y.z/finances/`.
   N'ouvrez **pas** de port sur la Freebox vers ce PC tant que HTTPS n'est pas installé (tutoriel 2).

## 4. Sauvegardes

- Chaque nuit, un dossier `boussole-AAAAMMJJ-HHMMSS` est créé dans le dossier des sauvegardes : base de données, clé de chiffrement,
  documents chiffrés et une notice `LISEZ-MOI-RESTAURATION.txt`. Les 30 plus récentes sont gardées.
- Les documents inchangés ne sont pas recopiés (liens physiques NTFS) : chacun n'occupe la place qu'une fois, quel que soit le nombre de sauvegardes.
- Le journal `sauvegardes.log` du dossier des sauvegardes indique chaque réussite ou échec ; **Réglages › Sécurité** affiche la date de la dernière sauvegarde.
- Une sauvegarde sur le même disque protège d'une fausse manœuvre, **pas d'une panne du disque ni d'un vol** : utilisez un autre disque,
  et copiez de temps en temps le dernier dossier sur une clé USB chiffrée (BitLocker To Go) rangée ailleurs.
- Sauvegarde immédiate : Planificateur de tâches › Bibliothèque › Boussole › Sauvegarde › **Exécuter**.

**Restaurer** : `iisreset /stop`, copiez `boussole.sqlite`, `secret.key` et `vault` de la sauvegarde dans `C:\BoussoleDonnees`
(en remplaçant), puis `iisreset /start`. La notice de chaque sauvegarde reprend ces étapes.

## 5. Sécurité : la liste à cocher

- [ ] **BitLocker** activé sur le disque du PC (Windows 11 Pro : Panneau de configuration › Chiffrement de lecteur BitLocker).
- [ ] Windows Update automatique, PC redémarré régulièrement.
- [ ] Aucune redirection de port de la Freebox vers ce PC.
- [ ] Dans Freebox OS, le pare-feu IPv6 bloque les connexions entrantes (sinon, les appareils de la maison ont une adresse joignable depuis Internet ;
      Boussole les refuse quand même, mais autant fermer la porte).
- [ ] Les codes de secours sont notés sur papier, à l'abri.
- [ ] Une copie de `C:\BoussoleDonnees\secret.key` est rangée hors du PC : sans elle, les documents sauvegardés sont illisibles.

L'Explorateur peut répondre « Vous n'avez pas l'autorisation d'accéder à ce dossier » sur `C:\BoussoleDonnees` : c'est normal, le dossier est réservé.
Cliquer sur **Continuer** ajoute votre compte Windows à la liste des autorisations.

## 6. Dépannage

| Symptôme | Où regarder |
|---|---|
| « Accès refusé : Boussole n'accepte que les appareils de la maison » | l'appareil n'est pas sur le réseau de la maison (4G, Wi-Fi invité). Passez par Tailscale, ou ajoutez son adresse dans `$ALLOWED_NETS` de `config.local.php`, **sous** le bloc de l'installeur |
| Erreur 500 | `C:\BoussoleDonnees\erreurs.log`, puis le journal des erreurs de PHP (`error_log` dans php.ini, par exemple `C:\PHP\logs\php_errors.log`) |
| Erreur 404 | le dossier n'est pas publié par le site indiqué : vérifiez `-NomSite` et `-DossierSite` |
| Le téléphone n'arrive pas à se connecter | relancez avec `-PareFeu` ; vérifiez que le réseau Windows est en profil **Privé** |
| Pas d'e-mail d'alerte | Réglages › Envoi des e-mails › Envoyer un test ; Planificateur de tâches › Boussole › Veille : « Résultat de la dernière exécution » doit valoir 0x0 |
| Sauvegarde en échec | `sauvegardes.log` dans le dossier des sauvegardes ; Planificateur de tâches › Boussole › Sauvegarde › Historique |
| L'installation s'arrête | le message indique la cause ; le journal complet est dans `C:\ProgramData\Boussole\` |

## 7. Désinstaller

```powershell
.\Installer-Boussole.ps1 -Desinstaller
```

L'application et les tâches planifiées sont retirées. Les données (`C:\BoussoleDonnees`) et les sauvegardes restent en place :
supprimez-les vous-même, seulement quand vous êtes sûr de ne plus en avoir besoin.
