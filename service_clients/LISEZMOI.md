# Service clients D8 — `service_clients/`

Outil web partagé, installé sur le serveur IIS qui héberge déjà le planning D8. Chacun se connecte avec son adresse e-mail et ne voit que ce que son rôle permet. Il sert à :

- traiter les quatre types de demandes : SAV carte bancaire, SAV intervention, remboursement, commande réglée par carte ;
- générer l'e-mail de réponse adapté aux renseignements saisis ;
- produire les fiches PDF à champs remplissables ;
- recevoir les demandes de remboursement que les clients remplissent en ligne.

| Fichier | Rôle |
|---|---|
| `index.html` | L'outil (connexion, onglets, réponses, fiches PDF, administration). |
| `formulaire.html` | Formulaire de remboursement **public**, ouvert par le client depuis le lien reçu. |
| `api.php` | Serveur : comptes, droits, dossiers, e-mails, sauvegardes. Il contrôle tous les droits. |
| `web.config`, `.user.ini` | Réglages IIS et PHP du dossier. |
| `config.exemple.php` | Modèle de `config.php` (réglages propres au serveur, jamais écrasés par une mise à jour). |
| `installer-service-clients.ps1` / `.cmd` | Installation et mise à jour sur IIS, activation de PHP pour ce dossier. |

## Qui voit quoi

| Personnes | Rôle | Onglets visibles |
|---|---|---|
| cfernandes@d8.fr, kbutant@d8.fr, sbertrand@d8.fr, nmarchiori@d8.fr | Paiement CB et remboursement | Remboursement, Commande CB |
| dmalemebe@d8.fr | SAV (carte bancaire et intervention) | SAV · Carte bancaire, SAV · Intervention |
| monetique@d8.fr | Formulaires en ligne (Monétique) | Formulaires en ligne, et rien d'autre |
| aneves@d8.fr, mzidani@d8.fr, tmefre@d8.fr, lgasp@d8.fr | Super administrateur | Tous les onglets et l'Administration |

Les onglets ne sont qu'un affichage : c'est `api.php` qui refuse la lecture et l'enregistrement d'un type de dossier non autorisé, même si on appelle l'API directement.

Rôles et personnes se modifient dans **Administration › Rôles et droits** et **› Utilisateurs**. Un administrateur qui n'est pas super administrateur ne peut ni attribuer un droit qu'il n'a pas, ni modifier une personne qui a plus de droits que lui.

## Installation sur le serveur IIS

1. Copier le dossier `service_clients` sur le serveur, par exemple dans `C:\Temp\service_clients`.
2. Double-cliquer sur `installer-service-clients.cmd`. Le script demande les droits administrateur.

   Équivalent en ligne de commande :

   ```powershell
   powershell -NoProfile -ExecutionPolicy Bypass -File .\installer-service-clients.ps1
   ```

3. Le script fait seulement ce qui manque, dans cet ordre :
   1. Rôles IIS : serveur web, CGI/FastCGI, filtrage des requêtes.
   2. PHP : il réutilise le PHP déjà déclaré dans IIS pour le planning. S'il n'y en a pas, il prend un PHP existant (`-PhpPath`), une archive (`-PhpZip`), ou le télécharge depuis windows.php.net (`-InstallPhp`, avec vérification SHA-256).
   3. Extensions PHP. `php.ini` est partagé avec le planning : le script signale seulement ce qui manque, sauf avec `-CorrigerPhpIni`.
   4. Traitement des fichiers `.php`, déclaré **pour le seul dossier `service_clients`**. Le reste du site n'est pas modifié.
   5. Copie de `index.html`, `formulaire.html`, `api.php`, `web.config` et `.user.ini` dans `C:\inetpub\wwwroot\service_clients`. L'ancienne version est mise de côté.
   6. Dossier des données hors de la racine web (`C:\inetpub\service_clients_data`), avec le droit « Modifier » pour le pool d'applications IIS.
   7. Mot de passe super administrateur. Il est demandé, haché, et n'apparaît jamais en clair.
   8. Tests : la page, l'API PHP, et le fait que les données ne sont pas servies par le web.
   9. Avec `-SauvegardePlanifiee` : une tâche planifiée qui déclenche les sauvegardes même si personne n'a l'outil ouvert.
4. Ouvrir `http://<serveur>/service_clients/`.

**Mise à jour :** remplacer les fichiers dans le dossier de copie, puis relancer le script. `config.php` et les données ne sont jamais écrasés. Les postes ouverts affichent « nouvelle version installée ».

## Mise en service (une seule fois)

1. **Premier accès.** À la première ouverture, choisir son nom parmi les super administrateurs, saisir le mot de passe super administrateur (celui défini par le script), puis choisir son mot de passe personnel.

   Si le script n'a pas défini le mot de passe super administrateur, ouvrir la page depuis le serveur lui-même (`http://localhost/service_clients/`) : elle propose de le définir.
2. **Envoi des e-mails.** Dans Administration › Sécurité & accès, régler l'envoi. Il sert aux invitations, aux mots de passe oubliés et au formulaire en ligne. Deux préréglages :
   - **Microsoft 365 (avec compte)** : `smtp.office365.com:587`, avec le compte et le mot de passe de la boîte d'envoi (par exemple `Monetique@d8.fr`).

     Le « SMTP authentifié » est souvent désactivé dans Microsoft 365. L'administrateur M365 doit alors l'activer pour cette boîte. Le message d'erreur de l'outil l'indique.
   - **Relais Microsoft 365 (sans compte)** : `d8-fr.mail.protection.outlook.com:25`. Il n'accepte que des destinataires du domaine d8.fr, sauf connecteur M365 autorisant l'IP du serveur. **Il ne convient donc pas pour l'accusé de réception envoyé aux clients.**

   Ensuite : renseigner « Adresse de l'application », puis cliquer sur « Envoyer un e-mail de test ».
3. **Invitations.** Dans Administration › Utilisateurs, cliquer sur « Inviter » pour chaque personne. Chacune reçoit un lien à usage unique, valable 7 jours, pour choisir son mot de passe. Sans envoi d'e-mail, le lien et le code s'affichent : il suffit de les transmettre.
4. **Réglages de l'outil** : coordonnées de l'entreprise, adresses de retour, délais, logo. Ils sont communs à tous les postes.

## Utilisation

- **Onglets.** Saisir les renseignements, ou importer la fiche PDF renvoyée par le client (bouton « Importer une fiche PDF » ou glisser-déposer). L'encadré « Contrôle des renseignements » signale les manques et les incohérences.
- **Enregistrer** crée le dossier sur le serveur. Il reçoit un numéro du type `REMB-261009-001`, que l'objet de l'e-mail reprend.
- **Copier l'e-mail, Brouillon Outlook (.eml), Ouvrir la messagerie.** Chacun enregistre d'abord le dossier, puis inscrit la réponse dans son historique.
- **Dossiers.** Recherche, filtres, export CSV, historique, clôture, suppression (selon les droits).
- **Modification simultanée.** Si deux personnes modifient le même dossier, la seconde à enregistrer est prévenue. Elle choisit alors de charger la version de l'autre ou de garder la sienne : rien n'est écrasé sans le savoir.
- **Menu personnel** (nom en haut à droite) : signature des e-mails (fonction, téléphone), mot de passe, double authentification, déconnexion.

## Formulaire de remboursement en ligne

Parcours, côté Monétique (compte `monetique@d8.fr`, vue « Formulaires en ligne ») :

1. **Envoyer un lien à un client.** Saisir l'adresse e-mail du client : elle doit contenir `@` et un domaine. Le client reçoit par e-mail un lien personnel, à usage unique, valable 30 jours (réglable).
2. **Le client remplit le formulaire.** L'e-mail est obligatoire et contrôlé, côté navigateur puis côté serveur. Le client n'a besoin d'aucun compte et ne reçoit aucun cookie.
3. **À l'envoi, le serveur :**
   1. enregistre le dossier ;
   2. l'envoie à **Monetique@d8.fr**. On peut y répondre directement : la réponse part au client ;
   3. **seulement si cet envoi a été accepté**, envoie au client un **accusé de réception avec le récapitulatif** de sa demande ;
   4. envoie à Monetique@d8.fr une **confirmation que l'accusé de réception est parti**, avec sa copie.
4. **Dans la vue Formulaires en ligne :**
   - chaque demande affiche l'état de ses trois e-mails (✓ ou ✕) ;
   - « Renvoyer les e-mails manquants » relance ceux en échec ;
   - « Marquer comme pris en charge » range la demande ;
   - « Ouvrir le dossier » fonctionne pour les rôles qui ont le droit Remboursement.

**Réglages** (bouton du même nom) :

- ouverture ou fermeture du formulaire ;
- adresse qui reçoit les demandes ;
- validité des liens ;
- adresse publique ;
- accusé de réception automatique ;
- lien générique.

### Rendre le formulaire accessible depuis Internet

Par défaut, l'outil n'est accessible que depuis le réseau interne (`$ALLOWED_NETS`). Seules les deux actions du formulaire (`form-info`, `form-submit`) sont acceptées depuis l'extérieur, et **uniquement en HTTPS**. Pour qu'un client ouvre le lien depuis chez lui :

1. Publier le dossier en HTTPS sous un nom public, par exemple `https://sav.d8.fr/service_clients/`. Cela passe par un certificat sur le site IIS, ou par un proxy inverse : Azure AD Application Proxy, pare-feu, IIS ARR…
2. Si un **proxy inverse** relaie les requêtes, inscrire son adresse IP dans `config.php` : `$TRUSTED_PROXIES = ['10.0.0.5'];`.

   **Sans ce réglage, tous les visiteurs d'Internet paraissent venir de l'IP interne du proxy et auraient accès à l'écran de connexion de l'outil.**
3. Indiquer cette adresse dans Formulaires en ligne › Réglages › « Adresse publique ». Les liens envoyés l'utiliseront.

Tant que l'adresse publique n'est pas réglée, les liens ne s'ouvrent que sur le réseau de l'entreprise. Un bandeau le rappelle.

**Lien générique** (affichette ou QR code sur les distributeurs) : il est désactivé par défaut et déconseillé. N'importe qui peut alors déposer une demande, et faire envoyer un accusé de réception à n'importe quelle adresse. Il est limité à 300 demandes par jour et 10 par heure et par adresse IP. Les robots sont filtrés par un champ piège et un délai minimal de remplissage.

## Sécurité

- Mots de passe hachés. Avec les réglages par défaut :
  - 8 caractères minimum, dont un chiffre et un caractère spécial ;
  - compte bloqué 15 min après 3 erreurs ;
  - super administrateurs bloqués jusqu'au lien de déblocage envoyé par e-mail ;
  - blocage par adresse IP en plus.
- Double authentification (application TOTP : Microsoft Authenticator, Google Authenticator…), avec 10 codes de secours. Facultative par défaut, elle peut être rendue obligatoire pour les super administrateurs, les administrateurs ou tout le monde.
- Le mot de passe super administrateur est redemandé pour la restauration d'une sauvegarde. La confirmation est valable 5 minutes.
- Le journal des connexions et des actions sensibles se consulte dans Administration › Journal et blocages.
- Numéros de carte :
  - un numéro complet saisi par erreur (clé de Luhn valide) est masqué avant enregistrement ;
  - seuls les 4 derniers chiffres sont conservés ;
  - aucun cryptogramme n'est jamais demandé.
- Mode maintenance : les enregistrements sont suspendus, sauf pour les administrateurs.
- **HTTP ou HTTPS.** Sans liaison HTTPS sur le site IIS, les mots de passe circulent en clair sur le réseau interne. Une liaison HTTPS (certificat interne) est fortement recommandée. Elle est obligatoire pour le formulaire public.

## Sauvegardes

- **Sauvegarde complète automatique** à 12 h et 17 h, 60 conservées. Elle contient les personnes, les rôles, les accès (mots de passe hachés), les réglages et les dossiers. Une sauvegarde manuelle se lance dans Administration › Sauvegardes.
- **Restauration** (super administrateur et mot de passe super administrateur) : une copie de l'état actuel est faite juste avant.
- **Les sauvegardes sont dans le même dossier de données que les originaux.** Il faut donc inclure `C:\inetpub\service_clients_data` dans la sauvegarde du serveur, sinon une panne de disque emporte tout.
- **Durée de conservation** (Réglages de l'outil, 12 mois par défaut) : les dossiers non modifiés depuis plus longtemps sont effacés automatiquement, une fois par jour.

## Points d'attention et limites

- **Le compte `monetique@d8.fr` est partagé.** Plusieurs personnes qui l'utilisent apparaissent sous un même nom dans l'historique et le journal : on ne sait plus qui a fait quoi. Si plusieurs personnes traitent les formulaires, mieux vaut un compte nominatif pour chacune, avec le rôle « Formulaires en ligne (Monétique) ». De plus, tout le monde connaît alors le même mot de passe.
- **« Envoyé » veut dire « accepté par le serveur de messagerie »**, pas « lu » ni même « arrivé ». L'accusé de réception est envoyé dès que Microsoft 365 a accepté le message pour Monétique. Il ne confirme pas qu'une personne l'a ouvert, et il peut finir en courrier indésirable.
- **L'adresse du client est celle qu'il a saisie.** Avec un lien personnel, elle est pré-remplie, mais le client peut la changer.
- **Le relais Microsoft 365 sans compte n'envoie pas vers l'extérieur.** Pour l'accusé de réception aux clients, il faut le SMTP authentifié, ou un connecteur M365 qui autorise l'adresse IP du serveur.
- **Brouillon `.eml`.** Il s'ouvre comme un nouveau message dans Outlook classique. Le nouvel Outlook et Outlook Web peuvent l'afficher comme un message reçu : utiliser alors « Copier l'e-mail ».
- **Données personnelles.** La mention RGPD du formulaire nomme D8 S.A.S.U. comme responsable du traitement, la durée de conservation et le recours à la CNIL. Le traitement est à inscrire au registre des traitements de D8.

## Dépannage

| Symptôme | Cause probable |
|---|---|
| « PHP n'est sans doute pas activé pour le dossier service_clients » | Le traitement des `.php` n'est pas déclaré : relancer le script. |
| « Accès refusé pour l'adresse … » | Le poste n'est pas dans `$ALLOWED_NETS` : l'ajouter dans `config.php`. |
| « Dossier de données non accessible en écriture » | Il manque le droit « Modifier » du pool d'applications IIS sur le dossier des données : relancer le script. |
| E-mail refusé avec `5.7.139` | SMTP authentifié désactivé dans Microsoft 365 (voir Mise en service). |
| Le client voit « Connexion non sécurisée : ouvrez le formulaire avec une adresse https:// » | La publication HTTPS n'est pas en place, ou le proxy inverse n'est pas déclaré dans `$TRUSTED_PROXIES` (il doit transmettre `X-Forwarded-Proto: https`). |

Bibliothèque PDF incluse dans `index.html` : pdf-lib 1.17.1 (licence MIT).
