# D8 Support : installation sur Windows avec IIS

Outil de tickets informatiques interne, en un seul fichier PHP (`index.php`)
avec une base SQLite. Aucune base de données à installer.

## Installation (3 étapes)

1. Copier ce dossier `d8-support` sur un disque local du serveur Windows
   (par exemple `C:\Installation\d8-support`, **pas** dans `wwwroot`). Les
   4 fichiers utiles doivent rester ensemble :
   - `INSTALLER.cmd`
   - `installer-iis.ps1`
   - `index.php`
   - `web.config`
2. Double-cliquer sur **`INSTALLER.cmd`** et accepter la demande d'autorisation
   de Windows. Compter 2 à 10 minutes la première fois (activation d'IIS et
   téléchargement de PHP).
3. À la fin, la fenêtre affiche :
   - l'adresse à donner aux utilisateurs : `http://NOM-DU-SERVEUR/ticketing/`
   - un **code d'installation** à 6 caractères.

   Ouvrir l'adresse, saisir le code, créer le compte administrateur. Puis
   ouvrir `http://NOM-DU-SERVEUR/ticketing/index.php?page=verification` :
   tout doit être au vert (hors HTTPS, voir plus bas).

Configuration requise : Windows 10/11 ou Windows Server 2012 R2 et plus
récent, 64 bits, compte administrateur, accès Internet (sinon voir
« Serveur sans Internet »).

## Ce que fait l'installateur

| Étape | Détail |
|---|---|
| IIS | Active IIS et FastCGI (fonctionnalités Windows), démarre les services. |
| Runtime Visual C++ | Installé s'il manque ou s'il est trop ancien (signature Microsoft vérifiée). |
| PHP | Dernière version 8.4 x64 « Non Thread Safe » depuis windows.php.net (empreinte SHA-256 vérifiée), dans `C:\PHP`, avec un `php.ini` adapté : extensions (SQLite, fileinfo, zip, LDAP, OpenSSL, mbstring), pièces jointes 8 Mo, erreurs dans `C:\PHP\logs` et jamais à l'écran. |
| Application | Copie `index.php` et `web.config` dans `C:\inetpub\wwwroot\ticketing`. |
| Droits | IIS peut **lire** l'application et **écrire uniquement** dans `data\` ; `data\` est fermé aux autres comptes Windows. `C:\PHP` n'est modifiable que par les administrateurs. |
| IIS | Application `/ticketing` avec son propre pool `D8Support`. PHP est branché **sur cette application seulement** : les autres sites du serveur ne changent pas. Les messages d'erreur de l'application sont transmis tels quels (sinon IIS les remplace par ses pages HTML). |
| Sécurité | `web.config` rend le dossier `data\` (base, pièces jointes, journaux) inaccessible depuis le navigateur ; l'installateur le vérifie. |
| Pare-feu | Ouvre le port HTTP du site en entrée. |
| Planning D8 | Active aussi PHP pour `planning_prod_d8` (même site) : `api.php` fonctionne, le planning passe en **base partagée**, son dossier `data\` (planning, comptes, sessions) est accessible en écriture pour IIS et masqué du navigateur. Si `$SIGNUP_CODE` est vide dans `api.php`, un code aléatoire y est écrit (ancienne version gardée dans `data\api.php.precedent`) et affiché à la fin : il faut le saisir pour créer son accès. Rien n'est fait si le dossier n'existe pas. |
| Vérification | Interroge l'application, contrôle que la base n'est pas téléchargeable, affiche le code d'installation. |
| Sauvegarde | Tâche planifiée quotidienne (22 h 00, compte SYSTEM) dans `C:\Sauvegardes\D8Support`, 14 archives gardées, et une sauvegarde d'essai tout de suite. |

Le détail de chaque exécution est écrit dans `installation-iis.log`, à côté
du script.

## Mettre à jour

Remplacer `index.php` dans le dossier d'installation, puis relancer
`INSTALLER.cmd`. Le script :
- remplace `index.php` (l'ancien est gardé dans `data\index.php.precedent`) ;
- installe les correctifs de PHP 8.4 s'il y en a ;
- ne touche **jamais** aux données (`data\`) ni au `php.ini` existant.

Relancer le script est sans risque : chaque étape déjà faite est simplement
vérifiée.

## Serveur sans Internet

Déposer à côté de `installer-iis.ps1`, avant de lancer `INSTALLER.cmd` :
- le zip de PHP : sur <https://windows.php.net/download/>, section PHP 8.4,
  **« VS17 x64 Non Thread Safe »**, lien « Zip »
  (`php-8.4.x-nts-Win32-vs17-x64.zip`) ;
- `vc_redist.x64.exe` : <https://aka.ms/vs/17/release/vc_redist.x64.exe>.

Ils sont utilisés à la place du téléchargement.

## Options

À passer après `INSTALLER.cmd` dans une invite de commandes, ou directement à
`installer-iis.ps1` :

```bat
INSTALLER.cmd -NomApplication support -HeureSauvegarde 12:30
```

| Option | Défaut | Rôle |
|---|---|---|
| `-NomApplication` | `ticketing` | Adresse `http://serveur/<nom>/` et dossier `C:\inetpub\wwwroot\<nom>`. |
| `-Dossier` | `C:\inetpub\wwwroot\<nom>` | Dossier de l'application. |
| `-Site` | `Default Web Site` | Site IIS d'accueil. |
| `-DossierPHP` | `C:\PHP` | Dossier de PHP. Si `C:\PHP` contient déjà un PHP installé à la main, il n'est pas touché et `C:\PHP-D8Support` est utilisé. |
| `-VersionPHP` | `8.4` | Branche de PHP. |
| `-DossierSauvegardes` | `C:\Sauvegardes\D8Support` | Destination des sauvegardes. De préférence un partage réseau (`\\nas\sauvegardes\d8support`) : la tâche tourne sous le compte SYSTEM, c'est donc le compte ordinateur du serveur (`DOMAINE\SERVEUR$`) qui doit pouvoir y écrire. |
| `-HeureSauvegarde` | `22:00` | Heure de la sauvegarde quotidienne. |
| `-SauvegardesAGarder` | `14` | Nombre d'archives conservées. |
| `-AutresApplications` | `planning_prod_d8` | Autres dossiers du site où activer PHP (séparés par des virgules). `-AutresApplications @()` : aucun. |
| `-SansSauvegarde` | | Ne crée pas la tâche planifiée. |
| `-SansPause` | | Ne demande pas « Entrée » à la fin (déploiement automatisé). |

## Dépannage

| Symptôme | Cause probable et solution |
|---|---|
| La fenêtre se ferme aussitôt, ou « l'exécution de scripts est désactivée » | Une stratégie de groupe interdit les scripts PowerShell. Lancer depuis une invite **administrateur** : `powershell -ExecutionPolicy Bypass -File installer-iis.ps1`, ou faire assouplir la GPO. |
| « Le site ne démarre pas » | Le port 80 est déjà pris (XAMPP/Apache, Skype…). `netstat -ano \| findstr :80` donne le numéro du processus. Arrêter ce logiciel, puis relancer. |
| Téléchargement impossible | Proxy ou pare-feu sortant : voir « Serveur sans Internet ». |
| Erreur après installation | Ouvrir `…/index.php?page=verification`. Journaux : `C:\PHP\logs\php-erreurs.log` et `data\.ht_erreurs.log`. |
| Modifier un réglage PHP | Éditer `C:\PHP\php.ini` en administrateur : IIS le relit tout seul. Pour repartir du `php.ini` fourni par l'installateur, le supprimer puis relancer `INSTALLER.cmd`. |

## Désinstaller

Dans le Gestionnaire IIS (`inetmgr`) : supprimer l'application `ticketing`
et le pool `D8Support`. Dans le Planificateur de tâches : supprimer
« D8 Support - sauvegarde quotidienne ». Puis supprimer les dossiers
`C:\inetpub\wwwroot\ticketing` (**les données sont dans `data\`**) et, si
rien d'autre ne s'en sert, `C:\PHP`.

## Planning D8 : passage en base partagée

Tant que PHP n'était pas actif, chaque poste gardait **son** planning dans
son navigateur (base locale). Une fois `api.php` actif, la page bascule en
base partagée, vide au départ. **Avant de lancer l'installateur**, exporter
le planning depuis le poste qui a les bonnes données (*Paramétrage › Données
& sauvegarde › Exporter*), puis le recharger à l'écran « Première mise en
service » (voir `MISE-A-JOUR.md` à la racine du dépôt).

La sauvegarde quotidienne de l'installateur ne couvre que D8 Support :
le planning garde ses propres copies dans `planning_prod_d8\data\backups`,
sur le même disque. Faites-les copier ailleurs aussi.

## Limites à connaître

- **Pas de HTTPS** : l'installateur publie l'outil en HTTP. Les mots de passe
  circulent donc en clair sur le réseau local. Acceptable sur un réseau
  interne maîtrisé ; indispensable de passer en HTTPS (certificat sur le site
  IIS) si l'outil est accessible depuis l'extérieur ou par Wi-Fi invité.
- **Sauvegardes sur le même disque** par défaut : elles protègent d'une erreur
  de manipulation, pas d'une panne du serveur. Copier le dossier de
  sauvegardes ailleurs (NAS, autre serveur) ou le choisir sur un partage avec
  `-DossierSauvegardes`.
- **SQLite** convient à quelques dizaines d'utilisateurs simultanés, pas à
  plusieurs centaines.
