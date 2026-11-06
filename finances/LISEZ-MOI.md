# Boussole — les finances du foyer, en toute sérénité

Boussole lit vos relevés bancaires, calcule ce qui entre et ce qui sort, classe chaque dépense
(essentiel, plaisir, évitable), vérifie les frais de la banque au regard des plafonds légaux,
prépare les courriers de réclamation et range vos papiers dans un coffre-fort chiffré.
Chaque personne du foyer (jusqu'à 8) a son propre accès : rien n'est partagé sans son accord.

## 1. Ce que fait Boussole

| Onglet | Ce que vous y trouvez |
|---|---|
| **Tableau de bord** | Score de bien-être financier (5 critères), la « prochaine meilleure action », revenus nets, dépenses, reste du mois, répartition essentiel / plaisir / évitable, tendance sur 12 mois, **solde prévu jusqu'à la paie** et « ce que je peux dépenser par jour ». Vue *Moi* ou *Foyer*. |
| **Importer un relevé** | PDF, CSV, **Excel (.xlsx et ancien .xls)**, **OpenDocument (.ods)**, OFX, QFX, QIF, **XML ISO 20022 (camt.052 / 053 / 054)**, **MT940**, ou texte collé. Aperçu corrigeable, contrôle « solde initial + mouvements = solde final », doublons écartés. |
| **Opérations** | Recherche, filtres (dont « par personne » quand d'autres partagent leur détail), reclassement, « toujours classer ainsi », dépense commune, contestation en un clic. **Export** du mois ou de tout : Excel, CSV, OFX, QIF, JSON. |
| **Alertes** | Frais au-delà des plafonds, rejet facturé plusieurs fois, 5 incidents dans le mois, prélèvement en double, nouveau créancier, petit paiement « test de carte », prélèvement après résiliation, hausse de prix, jeux d'argent, paiements en 3/4 fois empilés, effet « jour de paie », découvert prévu avant la paie, écart entre bulletin de paie et virement, abonnement payé deux ou trois fois dans le foyer… |
| **Frais bancaires** | Coût réel de la banque sur 12 mois, ce qui est **exigible de droit** (et pourquoi), ce qui relève du **geste commercial**, conseils pour ne plus en payer. |
| **Abonnements** | Prélèvements et charges récurrentes détectés automatiquement, hausses de prix, coût annuel ; marquez « inutile » ou « résilié » : Boussole vous relance tant que c'est prélevé. |
| **Courriers** | 14 modèles (réclamation, contestation, 8 semaines, liste noire de créanciers, statut fragile, médiateur, résiliation…), pré-remplis avec vos coordonnées et les articles de loi, **modifiables dans la page**, à copier, envoyer par e-mail, télécharger en **Word (.docx)** ou en texte, ou imprimer / enregistrer en PDF. |
| **Vos droits** | Les règles qui protègent le client, vérifiées le 6 novembre 2026, avec leurs références. |
| **Alertes par e-mail** | Récapitulatif des nouvelles alertes, e-mail de sécurité en cas de blocage, lien « mot de passe oublié », invitations. Connexion par adresse e-mail. |
| **Coffre-fort** | **Tous les formats** (PDF, photos, tableurs, archives, vidéos… sauf les programmes), chiffrés sur le serveur, avec leur durée de conservation officielle ; plusieurs fichiers d'un coup ; dossiers prêts (location, prêt, CAF, impôts) et **tout le coffre** en .zip avec un inventaire. |
| **Foyer & équité** | Les personnes du foyer (rôles, invitations, désactivation), répartition des dépenses communes au prorata des revenus ou à parts égales, **virements à faire pour équilibrer**, virements entre vous neutralisés. |

## 2. Installation

### Sur un PC Windows 11 à la maison (IIS + PHP)

Un script fait tout : vérifications, extensions PHP, copie, dossier des données hors du site, accès réservé à la maison,
sauvegarde et veille quotidiennes. Suivez **[windows/WINDOWS.md](windows/WINDOWS.md)** : double-clic sur
`windows\Installer-Boussole.cmd`, c'est tout. Le même script sert aux mises à jour.

### Sur un hébergement ou un autre serveur

**Il faut** : un serveur web avec **PHP 7.4 ou plus** et les extensions `pdo_sqlite` et `sodium` *ou* `openssl`
(`mbstring` et `fileinfo` sont utiles, pas indispensables). Presque tous les hébergements les proposent d'origine
(OVH, o2switch, IONOS, Hostinger…), tout comme un NAS Synology (Web Station) ou un Raspberry Pi (`sudo apt install php php-sqlite3`).

1. Copiez **tout le dossier `finances`** sur le serveur (`index.html`, `api.php`, `lib/`, `assets/`, `vendor/`, `outils/`, `.htaccess`, `web.config`, `manifest.webmanifest`).
2. Donnez au serveur web le droit d'écrire dans ce dossier : `api.php` y crée `data/` au premier lancement
   (mieux : un dossier **hors du site**, indiqué dans `config.local.php`, voir plus bas).
3. Ouvrez `https://votre-site/finances/` : l'écran **Première mise en service** apparaît.
4. Saisissez le nom du foyer, **votre** prénom, **votre adresse e-mail** (identifiant de connexion et adresse des alertes), puis créez votre mot de passe (saisi deux fois). Ajoutez si vous voulez le prénom de votre conjoint(e).
5. Notez votre **code de secours** (il permet de récupérer l'accès même si les e-mails ne fonctionnent pas) et transmettez le **code d'invitation** : la personne saisira sa propre adresse et choisira son propre mot de passe.
6. Dans **Réglages › Envoi des e-mails**, choisissez comment partent les e-mails, puis « Enregistrer et m'envoyer un test » (section 5).

> **N'hébergez pas vos finances personnelles sur le serveur ou le NAS de votre employeur.**
> L'administrateur y aurait accès, et le service ne vous appartient pas.

### Réglages propres à votre serveur : `config.local.php`

Créez `config.local.php` à côté d'`api.php` (il n'est jamais remplacé par une mise à jour ni publié) :

```php
<?php
$DATA_DIR = '/srv/boussole-donnees';      // données hors du site web
$ALLOWED_NETS = ['local'];                 // seulement le réseau de la maison et le VPN Tailscale
$REQUIRE_HTTPS = true;                     // dès que l'outil est joignable depuis Internet
$MAX_USERS = 8;                            // personnes dans le foyer
```

`$ALLOWED_NETS` accepte `'tout'`, `'local'` (adresses privées IPv4, IPv6 locales et même réseau /64 que le serveur, VPN Tailscale 100.64.0.0/10)
et des adresses ou plages précises (`'203.0.113.7'`, `'2001:db8::/48'`). Tout autre appareil reçoit « Accès refusé ».

### Serveur nginx (le .htaccess y est ignoré)

```nginx
location ~ ^/finances/(data|lib|tests|outils|windows)/ { deny all; return 404; }
location ~ ^/finances/config\.local\.php$ { deny all; return 404; }
location ~ \.(sqlite|key|md|log|bak)$ { deny all; return 404; }
```

L'application vérifie elle-même que `data/` n'est pas lisible depuis le navigateur et affiche une **bannière rouge** si c'est le cas.

## 3. Sécurité — à lire

- **HTTPS obligatoire dès que l'outil est accessible depuis Internet** : certificat gratuit Let's Encrypt, puis `$REQUIRE_HTTPS = true` dans `config.local.php`. Sans https, mots de passe et relevés circulent en clair. À la maison, préférez un **VPN** (Tailscale) à une ouverture de port.
- **Données hors de la racine web** (`$DATA_DIR`) et **accès limité au réseau de la maison** (`$ALLOWED_NETS = ['local']`) : l'installeur Windows règle les deux.
- **Documents chiffrés** (libsodium, ou AES-256-GCM) avec la clé `secret.key`. Le chiffrement protège contre la fuite d'une sauvegarde ou d'un dossier exposé par erreur ; il ne protège pas contre quelqu'un qui contrôle le serveur.
- **Coffre-fort** : tous les formats sont acceptés sauf les programmes et scripts (.exe, .bat, .ps1, .js…), même renommés ; seuls PDF, images et textes s'affichent dans le navigateur, le reste (HTML, SVG…) part en téléchargement.
- Mots de passe hachés (bcrypt), session de 30 minutes, blocage après 5 erreurs, limitation par adresse IP, jeton anti-CSRF, journal des connexions (Réglages › Sécurité).
- **Mode discret** (icône œil en bas du menu) : floute les montants quand quelqu'un regarde l'écran.
- Les PDF sont lus **dans le navigateur** : ils ne transitent par aucun service extérieur. Aucune ressource externe n'est chargée.

## 4. Plusieurs personnes dans le foyer

- **Administrateur** : invite, gère les personnes, règle le foyer (dépenses communes, règle de partage) et l'envoi des e-mails. Il y a toujours au moins un administrateur actif. Les deux personnes créées à l'installation le sont.
- **Membre** : gère ses relevés, ses alertes et ses documents.
- **Inviter** (page Foyer) : prénom, adresse e-mail facultative, rôle. Avec l'envoi des e-mails réglé, l'invitation part toute seule avec un lien ; sinon, transmettez le code. Code valable 30 jours, à usage unique.
- **Partage** : chacun choisit de partager ses totaux (vue Foyer, équité) et/ou le détail de ses opérations (consultation sans modification, détection des abonnements en double). On ne voit les totaux des autres que si l'on partage les siens.
- **Équité** : une personne peut se retirer du partage des dépenses communes (enfant, personne hébergée) dans ses Réglages.
- **Désactiver** une personne coupe ses connexions sans effacer ses données ; la **retirer** efface tout, avec la possibilité de reprendre ses documents partagés.

## 5. Alertes par e-mail

**Ce que vous recevez** (Réglages › Alertes par e-mail) : un récapitulatif des **nouvelles** alertes, à chaque import et lors de la veille automatique. Au choix : critiques et à vérifier (conseillé), critiques seulement, toutes, ou aucune. Le mode **discret** n'indique que le nombre d'alertes, sans aucun montant : un e-mail n'est pas un canal confidentiel.
Boussole écrit aussi en cas de **compte bloqué**, de **changement d'adresse** (à l'ancienne adresse), pour le lien **« mot de passe oublié »** (30 minutes, une seule fois) et pour les **invitations**.

**Par où partent les e-mails** (Réglages › Envoi des e-mails, réglage commun au foyer, réservé aux administrateurs) :

| Solution | Quand la choisir |
|---|---|
| Fonction mail() de l'hébergeur | Le plus simple sur un hébergement Linux mutualisé (OVH, o2switch, IONOS…). **Indisponible sous Windows.** |
| SMTP de l'hébergeur ou du fournisseur d'accès | Plus fiable que mail() : serveur, port et identifiants fournis (ex. `ssl0.ovh.net`, port 465, SSL). |
| Gmail | `smtp.gmail.com`, port 587, STARTTLS ; identifiant = l'adresse Gmail ; mot de passe = un **mot de passe d'application** (compte Google › Sécurité › Validation en deux étapes). |
| Brevo (relais gratuit) | `smtp-relay.brevo.com`, port 587 ; identifiant et clé SMTP dans votre compte Brevo. |

> **Une adresse Outlook, Hotmail ou Live peut RECEVOIR les alertes, mais ne peut plus servir à les ENVOYER** :
> depuis le 16 septembre 2024, Microsoft exige une connexion OAuth pour ces comptes, que ce petit serveur ne gère pas.

Pour que les alertes n'arrivent pas dans les **courriers indésirables** : ajoutez l'adresse d'expédition à vos contacts, et, si vous utilisez votre propre domaine, activez SPF et DKIM chez l'hébergeur.

**Veille automatique** : Boussole recalcule les alertes au plus toutes les 6 heures, dès que quelqu'un ouvre l'application. Pour être prévenu **même sans l'ouvrir** (découvert prévu avant la paie, pièce d'identité qui expire) : sous Windows, l'installeur programme une tâche quotidienne ; ailleurs, programmez une tâche qui appelle l'adresse secrète affichée dans Réglages › Envoi des e-mails › Veille automatique (depuis le serveur lui-même, `http://localhost/finances/api.php?a=veille` suffit, sans clé) :

```bash
5 7 * * * curl -fsS "https://votre-site/finances/api.php?a=veille&cle=VOTRE_CLE" > /dev/null
```

## 6. Sauvegardes

`outils/sauvegarde.php` fait une copie **cohérente** de la base (même pendant l'utilisation), de la clé et des documents chiffrés,
garde les N plus récentes et ne recopie pas les documents inchangés (liens physiques). Chaque sauvegarde contient sa notice de restauration.
Sous Windows, l'installeur la programme chaque nuit. Ailleurs :

```bash
15 3 * * * php /chemin/vers/finances/outils/sauvegarde.php --vers=/chemin/des/sauvegardes --garder=30
```

Les administrateurs voient la date de la dernière sauvegarde dans **Réglages › Sécurité**, et une alerte sur le tableau de bord si elle a échoué ou date de plus de 8 jours.
Une sauvegarde sur le même disque ne protège pas d'une panne : copiez régulièrement la dernière sur un disque externe **chiffré**.

## 7. La routine mensuelle (10 minutes)

1. **Le 5 du mois** : chacun importe le relevé du mois écoulé (export **CSV, OFX ou Excel** de préférence : espace client › comptes › télécharger / exporter).
2. **Alertes critiques** : préparez le courrier proposé, envoyez-le par la messagerie sécurisée de la banque, marquez l'alerte « traitée ».
3. **Coffre-fort** : déposez le bulletin de paie (avec le net à payer : Boussole vérifie qu'il correspond au virement reçu).
4. **Une fois par trimestre** : passez les abonnements en revue.

## 8. Ce qu'il faut savoir (limites honnêtes)

- **La loi plafonne les frais ; elle n'oblige pas à rembourser des frais conformes.** Boussole distingue toujours ce qui est *exigible* (plafond dépassé, prélèvement non autorisé, rejet facturé plusieurs fois…) de ce qui relève du *geste commercial*.
- **La lecture des PDF est automatique mais pas infaillible** : chaque banque a sa mise en page. D'où l'aperçu et le contrôle des soldes ; en cas d'écart, préférez l'export CSV, OFX ou Excel. Un relevé **scanné** (image) ne contient pas de texte : il faut le relevé numérique.
- **Tableurs** : la première feuille dont les colonnes « date » et « montant » (ou « débit » / « crédit ») sont reconnues est lue. Les classeurs Excel 5 / 95 (antérieurs à 1997) ne sont pas lus : enregistrez-les en .xlsx.
- **La catégorisation se trompe parfois** (une enseigne inconnue, un libellé ambigu) : reclassez, et cochez « toujours classer ainsi ».
- **« Utile » ou « inutile » est un choix personnel** : Boussole parle d'« essentiel », de « plaisir » (assumé, à budgéter) et d'« évitable » (frais, agios, amendes…). Changez la nature d'une catégorie dans Réglages.
- Boussole est une aide pour agir, **pas un conseil juridique personnalisé**. En cas de litige important : association de consommateurs, Point conseil budget (gratuit) ou avocat.

## 9. Mettre à jour

- **Windows** : téléchargez la nouvelle version et relancez `windows\Installer-Boussole.cmd`.
- **Ailleurs** : sauvegardez les données, remplacez tous les fichiers **sauf `data/` et `config.local.php`**, puis rechargez la page (Ctrl + F5).

## 10. Valeurs à tenir à jour (`lib/legal.php`)

| Valeur | Montant | Quand la vérifier |
|---|---|---|
| Solde bancaire insaisissable (= RSA personne seule) | 651,69 € depuis le 1er avril 2026 | chaque 1er avril |
| Plafonds des frais d'incidents (8 €/80 €, 20 €, 30/50 €, 25 €/mois) | voir `LEGAL_*` | à chaque nouveau décret |

## 11. Tests

```bash
php tests/run-tests.php
```

Vérifie la lecture des relevés (texte, CSV Windows-1252, OFX, QIF, Excel .xlsx et .xls, OpenDocument, tableau HTML, camt.053, MT940),
les exports relus à l'identique (Excel, OFX, QIF), le courrier Word, la catégorisation, les plafonds de frais, les alertes (dont les abonnements
en double dans un foyer de trois personnes), l'équité à plusieurs, les courriers, les e-mails (encodage, injection d'en-têtes, mode discret),
le coffre-fort (formats refusés, affichage sûr), les archives ZIP et le filtrage réseau, sur des relevés **fictifs** (`tests/samples/`).

## 12. Sources (vérifiées le 6 novembre 2026)

- Code monétaire et financier : art. R312-4-1 (commissions d'intervention), D133-6 (rejet de prélèvement : tout compris, représentations), D131-25 (chèque), L312-1-3 et R312-4-3 (clients fragiles), L312-1-5 (information 14 jours avant), L133-18, L133-19, L133-23, L133-24 (opérations non autorisées), L133-25 et L133-25-1 (prélèvement SEPA, 8 semaines), L133-45 (réponse sous 15 jours ouvrables), L316-1 (médiateur), L312-1-1 (tarifs, récapitulatif annuel), L312-1-7 (mobilité bancaire).
- [INC — La tarification bancaire en cas d'incidents de paiement](https://www.inc-conso.fr/content/la-tarification-bancaire-en-cas-dincidents-de-paiement)
- [Banque de France — Les paiements SEPA](https://www.banque-france.fr/stabilite-financiere/comite-national-des-paiements-scripturaux/faq-les-paiements-en-questions/les-paiements-sepa)
- Règlement (UE) n° 260/2012, art. 5 §3 (listes blanche et noire de créanciers) ; règlement (UE) 2024/886 (virement instantané au prix du virement classique depuis le 9 janvier 2025).
- [service-public.gouv.fr — Combien de temps conserver ses papiers](https://www.service-public.gouv.fr/particuliers/vosdroits/F19134)
- [Code du travail — art. D3243-8 (bulletin de paie électronique)](https://code.travail.gouv.fr/code-du-travail/d3243-8)
- Loi n° 89-462 du 6 juillet 1989, art. 22-2, et décret n° 2015-1437 (pièces d'un dossier de location).
