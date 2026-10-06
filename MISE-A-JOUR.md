# Planning D8 — connexion et mise à jour sans perte de données

## 0. Vous n'avez que `planning-d8.html` et `api.php`, sans dossier `data` ?

`api.php` crée le dossier `data` **dès sa première exécution par un serveur web avec PHP**. Si ce dossier n'existe pas, `api.php` n'a jamais tourné, et **vos données sont dans le navigateur** (base locale) de chaque poste.

**Vérifier :** ouvrez le planning et regardez l'indicateur en haut.
- « **Enregistré sur ce poste** » : base locale. Les données sont dans ce navigateur, sur ce PC uniquement. Chaque poste a son propre planning, et vider les données du navigateur efface tout.
- Barre d'adresse commençant par `file:///` : la page est ouverte par double-clic. PHP ne peut pas s'exécuter et `api.php` est ignoré.

**Passer en base partagée sans rien perdre :**
1. Sur le poste qui contient le planning à jour : *Paramétrage › Données & sauvegarde › Exporter*. Gardez ce fichier `.json` précieusement. S'il y a eu des saisies sur plusieurs postes, chaque poste a son propre planning. Choisissez celui de référence : les données ne se fusionnent pas.
2. Déposez `planning-d8.html` et `api.php` dans un dossier d'un serveur web interne **avec PHP 7.4 ou plus** : IIS + PHP, ou un NAS Synology/QNAP avec Web Station. Donnez au compte du serveur web le droit d'écrire dans ce dossier.
3. Ouvrez `http://serveur/dossier/planning-d8.html`. L'écran « Première mise en service » apparaît.
4. Cliquez sur **Reprendre une sauvegarde (.json)** et choisissez le fichier exporté.
5. Choisissez votre nom (administrateur), un identifiant et un mot de passe. Vos données sont alors envoyées sur le serveur, et le dossier `data` apparaît.
6. Chacun ouvre ensuite la même adresse `http://…` (et non plus le fichier en double-clic) et crée son accès.

Tant que le planning est en base locale, **aucune connexion n'est demandée** : les données ne quittent pas le poste, donc un mot de passe vérifié dans le navigateur serait décoratif.

**Mettre à jour le HTML en base locale :**
1. Exportez une sauvegarde.
2. Remplacez le fichier **au même emplacement, avec le même nom**.
3. Rouvrez-le **dans le même navigateur**.

Si le planning apparaît vide ou revient au jeu de démonstration, *Restaurer* le fichier exporté. Changer le nom, le dossier ou le navigateur donne une « adresse » différente, donc une base différente.

## 1. Où sont les données ?

| Mode | Où sont les données | Remplacer le HTML… |
|---|---|---|
| **Base partagée** (`api.php` à côté de la page, sur le serveur) | `data/planning.json` sur le serveur, plus `data/backups/`, `data/accounts.json` (accès) et `data/sessions/` | **n'efface rien** : le fichier HTML ne contient que le programme. |
| **Base locale** (page ouverte sans `api.php`, ou avec `?local`) | Dans le navigateur de chaque poste (IndexedDB), rattachée à **l'adresse exacte** de la page | ne supprime rien **si l'adresse ne change pas**. Si l'adresse change (autre dossier, autre nom de fichier, autre navigateur), la page semble vide ou affiche le jeu de démonstration. |

Le seul moment où la page crée des données est celui où **aucune** donnée n'existe : elle fabrique alors le jeu de démonstration. Un planning existant n'est jamais écrasé par une nouvelle version du HTML.

## 2. Procédure pour chaque mise à jour (base partagée)

1. **Choisir un moment calme** et prévenir les utilisateurs.
2. **Vérifier que tout est enregistré** : sur les postes ouverts, l'indicateur en haut doit afficher « Synchronisé · vN », et non « Enregistrement… », « Hors ligne » ou « Session expirée ».
3. **Faire deux sauvegardes :**
   - dans l'application : *Paramétrage › Données & sauvegarde › Exporter* (fichier JSON) ;
   - sur le serveur : copier tout le dossier `data` dans une archive datée (`data-2026-09-30.zip`).
4. **Remplacer `planning-d8.html`** par la nouvelle version : **même nom, même dossier**.
   - Ne supprimez pas, ne déplacez pas et n'écrasez jamais le dossier `data`.
   - Si une nouvelle version d'`api.php` est livrée, remplacez-la **en même temps**.
   - Si vous aviez modifié des réglages en tête de l'ancien `api.php` (`$DATA_DIR`, `$ALLOWED_NETS`…), reportez-les dans le nouveau avant de le copier.
5. **Recharger les postes :**
   - les postes restés ouverts voient apparaître le bandeau « Une nouvelle version de l'application vient d'être installée » et le bouton **Recharger maintenant** (qui attend la fin des enregistrements en cours) ;
   - les vues écrans (`#/ecran/…`) se rechargent seules ;
   - en cas de doute, faites **Ctrl + F5** pour contourner le cache du navigateur.
6. **Contrôler** dans *Données & sauvegarde* : le nombre de projets, d'opérations et d'absences doit être le même qu'avant, et le numéro de version doit être au moins égal à l'ancien.

**Retour arrière :** remettez l'ancien fichier HTML. Si des données ont été abîmées, restaurez le JSON exporté (*Restaurer*) ou une copie automatique de `data/backups/`. Le serveur en fait une toutes les 15 min au plus et en garde 150.

### Empêcher le navigateur de garder une vieille version en cache

**IIS** : ajoutez ceci dans le `web.config` du dossier de l'application.

```xml
<configuration>
  <location path="planning-d8.html">
    <system.webServer>
      <staticContent>
        <clientCache cacheControlMode="DisableCache" />
      </staticContent>
    </system.webServer>
  </location>
</configuration>
```

**Apache** : ajoutez ceci dans le `.htaccess` du dossier (module `mod_headers` requis).

```apache
<Files "planning-d8.html">
  Header set Cache-Control "no-cache"
</Files>
```

## 3. Cas particulier : CETTE mise à jour (arrivée de la connexion)

L'ancien et le nouveau `api.php` **ne sont pas compatibles** avec l'autre version du HTML :

- **nouveau HTML + ancien `api.php`** : la page affiche un message clair, sans risque ;
- **ancien HTML (resté en cache ou ouvert) + nouveau `api.php`** : l'ancienne page reçoit « Connexion requise » et **bascule sans le dire en base locale**. Les saisies faites dans cet état restent sur ce poste et les autres ne les voient pas. **C'est le vrai risque de perte.**

Ordre à respecter :

1. Faire fermer l'application sur **tous** les postes et écrans, après avoir vérifié « Synchronisé ».
2. Sauvegarder (JSON + dossier `data`).
3. Copier **les deux fichiers ensemble** : `planning-d8.html` et `api.php`.
4. Vérifier que le compte du serveur web (sous IIS : `IIS AppPool\<pool>` ou `IUSR`) peut **écrire** dans `data`. Le sous-dossier `data/sessions` est créé automatiquement.
5. Rouvrir la page avec **Ctrl + F5** sur chaque poste.
6. Commencer par vous connecter vous-même : choisissez votre nom (seuls les administrateurs sont proposés), saisissez **le mot de passe super administrateur**, puis votre identifiant et votre mot de passe.
7. Invitez ensuite chaque personne (*Paramétrage › Utilisateurs › Inviter*, voir section 4). Une fois l'accès créé, l'identifiant et le mot de passe sont demandés **à chaque ouverture du navigateur**.
8. **Écrans d'atelier** : utilisez l'adresse avec clé (*Vues écrans › Copier l'adresse*, section 8). Elle ne demande aucun compte.

## 4. Fonctionnement de la connexion

- **Créer son accès : uniquement sur invitation.** Il n'y a plus de liste de noms : personne ne peut s'approprier la fiche d'un collègue.
  1. Un administrateur (droit « Utilisateurs ») clique sur **Inviter** en face de la personne, dans *Paramétrage › Utilisateurs*.
  2. La page affiche un **code à usage unique** (ex. `PQ66-RNA4-KQYD-AXQ4`) et un **lien**, valables 7 jours (réglable dans *Sécurité & accès*). Le bouton « Préparer l'email » ouvre la messagerie avec un message prêt à envoyer.
  3. La personne ouvre le lien, ou saisit le code dans « J'ai une invitation ». Son nom s'affiche ; elle choisit son identifiant et son mot de passe (8 caractères minimum).
  4. Le code ne sert qu'une fois. *Renvoyer* génère un nouveau code et annule l'ancien ; la croix annule l'invitation.
  - Le serveur ne garde que l'empreinte du code (`data/invites.json`) : le code n'est plus affiché après la fermeture de la fenêtre.
  - Un super administrateur ne peut être invité que par un super administrateur.
- **Mise en service, ou plus aucun accès sur le serveur** (`accounts.json` supprimé) : la page propose les administrateurs du planning et exige le **mot de passe super administrateur**. C'est le seul cas où l'on choisit un nom.
- **Se connecter** : identifiant et mot de passe. La session dure jusqu'à la fermeture du navigateur, ou jusqu'à 12 h sans aucun échange avec le serveur (`$SESSION_IDLE`).
- **Session expirée pendant le travail** : les modifications en attente sont **conservées**. La page redemande le mot de passe, puis les enregistre.
- **Mot de passe oublié** : un administrateur clique sur le cadenas de la personne dans *Paramétrage › Utilisateurs* (colonne « Identifiant »). L'accès est supprimé et la page propose aussitôt une nouvelle invitation à lui transmettre.
- **Changer son mot de passe** : menu en haut à droite › *Changer mon mot de passe*. Cette action ferme ses sessions sur les autres postes.
- **Compte désactivé** (case « Compte actif ») : la connexion est refusée.
- **Anti-force brute** : après 8 échecs depuis une même adresse IP, la connexion est bloquée 15 min.
- Les mots de passe sont stockés **hachés** (bcrypt) dans `data/accounts.json`. Ils ne sont jamais écrits dans le planning, ni dans les exports JSON.

### Limites à connaître

1. **Une invitation vaut une clé** jusqu'à son utilisation : transmettez-la à la personne elle-même (en main propre, par téléphone, sur sa messagerie professionnelle), jamais sur un canal partagé. Si elle a pu être vue par un tiers, cliquez sur *Renvoyer*.
2. **En http, les mots de passe circulent en clair** sur le réseau interne. Passez en HTTPS dès que possible (certificat interne de l'AD CS, par exemple).
3. **Le serveur vérifie *qui* enregistre, pas *ce qui* est modifié.** Les droits par rôle (Commercial, Lecture seule…) sont appliqués par l'interface. Une personne connectée et techniquement habile peut encore modifier le document complet. Ses enregistrements sont toutefois signés par sa session, dans le journal et dans `planning.meta.json`.
4. **« Reprendre là où je m'étais arrêté »** (Chrome, Edge) conserve les cookies de session : dans ce cas, la fermeture du navigateur ne déconnecte pas. La déconnexion se fait par le menu, ou après 12 h d'inactivité.
5. **Base locale** : il n'y a pas de connexion. Toutes les données sont dans le navigateur du poste, donc un mot de passe vérifié dans le navigateur serait purement décoratif.

## 5. Mot de passe super administrateur

Trois actions de *Paramétrage › Données & sauvegarde* exigent le mot de passe **super administrateur** : **Vider les projets**, **Tout réinitialiser** et **Restaurer**. Il faut aussi être connecté avec un rôle administrateur.

- **Base partagée** : le mot de passe est vérifié par **le serveur**, qui refuse aussi tout enregistrement qui remplace la base ou supprime plus de la moitié des projets, opérations et absences sans cette confirmation. Une manipulation par la console du navigateur (F12) est donc bloquée.
  - La confirmation reste valable 5 minutes pour la session (`$SUPERADMIN_TTL`).
  - Après 8 essais ratés, l'adresse IP est bloquée 15 min.
  - Pour le changer : bouton **Changer ce mot de passe**, sous « Remise à zéro ». Il faut 12 caractères minimum et l'ancien mot de passe. Le nouveau est rangé, haché, dans `data/superadmin.json`.
- **Base locale** : la vérification se fait dans la page. C'est un garde-fou contre les fausses manœuvres, pas une protection : les données sont dans le navigateur. Ce mot de passe-là reste celui d'origine.

**Mot de passe oublié :** supprimez `data/superadmin.json` sur le serveur. Le mot de passe d'origine (empreinte `$SUPERADMIN_HASH` dans `api.php`) redevient valable. Pour en définir un autre sans le connaître, remplacez `$SUPERADMIN_HASH` par le résultat de :
`php -r "echo password_hash('NouveauMotDePasse', PASSWORD_DEFAULT);"`

## 6. Exports, imports et diagrammes

Tout se fait dans le navigateur, sans logiciel ni connexion Internet supplémentaires.

**Bouton « Exporter » (sur chaque écran)** : Excel (.xlsx), CSV, JSON, PDF, copie du tableau, et selon l'écran agenda (.ics) ou image (PNG, SVG). Les filtres en cours sont respectés : on exporte ce qu'on voit.

| Écran | Ce qui est exporté |
|---|---|
| Projets en cours / archivés | la liste filtrée (20 colonnes, dont prochaine étape et avancement) |
| Planning d'un service | les opérations de la semaine affichée ; agenda .ics de toute l'équipe |
| Diagramme de Gantt | l'image (PNG, SVG, PDF paginé), les opérations de la période, l'agenda .ics |
| Préparation | la liste des machines à préparer |
| Absences | toutes les absences (avec jours ouvrés) ; agenda .ics ; graphique annuel par motif |
| Alertes, journal | la liste affichée |
| Rapports | les tableaux du rapport ; chaque graphique (icône ⬇) en image ou en données |
| Tableau de bord | chaque graphique (icône ⬇) |
| Paramétrage (agences, personnel…) | la liste, avec une colonne ID pour la réimporter |
| Données & sauvegarde | « Tout exporter » : un classeur Excel d'une feuille par liste, ou un .zip de CSV, ou un JSON |

**PDF** : l'application prépare une page imprimable et ouvre la fenêtre d'impression. Choisissez l'imprimante « Enregistrer au format PDF » (ou « Microsoft Print to PDF »).

**Bouton « Importer »** : Excel (.xlsx), CSV (séparateur ; , ou tabulation, encodage UTF-8 ou Windows) et JSON, vers :
- **Projets** : les projets déjà présents (même n°) sont ignorés ou mis à jour, au choix.
- **Absences** : les absences identiques déjà saisies sont ignorées.
- **Listes de paramétrage** : un élément est mis à jour s'il a le même ID ou le même nom, sinon il est créé.

Méthode conseillée : **exportez la liste en Excel, complétez-la, réimportez-la**. Les en-têtes sont alors reconnus automatiquement. Chaque import peut être annulé juste après (bouton « Annuler » de la notification).

Formats non pris en charge à l'import : `.xls` (ancien Excel) et `.ods` (enregistrez-les en `.xlsx`), et le PDF : un PDF ne contient pas de tableau exploitable de façon fiable.

## 7. Rôles et droits

Chaque rôle (*Paramétrage › Rôles & droits*) coche des droits parmi les suivants :

| Droit | Permet |
|---|---|
| Projets | créer et modifier les projets |
| Suppression | supprimer des projets et des étapes |
| Planification | planifier, déplacer les opérations, préparation atelier |
| Absences | saisir les absences et congés |
| Rapports | consulter les rapports |
| Export | exporter (Excel, CSV, PDF, agenda, images) |
| Import | importer des fichiers (dans les listes autorisées par les autres droits) |
| Paramétrage | agences, services, personnel, modèles, statuts, étiquettes… |
| Paramètres | paramètres généraux et mode maintenance |
| Utilisateurs | fiches utilisateurs, rôles, réinitialisation des accès, déconnexion forcée |
| Sécurité | page *Sécurité & accès* : journal des connexions, adresses bloquées, sauvegardes du serveur, journal d'activité |
| Données | restaurer, vider, tout réinitialiser (avec le mot de passe super administrateur) |
| **Super administrateur** | **tous les droits, sans restriction** |

**Rôles fournis** : Super administrateur (tout) · Administrateur (tout sauf super) · Éditeur planning · Commercial · Lecture seule. Les rôles créés avant cette version gardent exactement ce qu'ils permettaient : l'ancien « Administrateur » reçoit tous les droits sauf super.

**Ce que seul le super administrateur peut faire** :
- nommer ou retirer un super administrateur, modifier ce rôle ou la fiche d'un super administrateur ;
- régler la sécurité : validité des invitations, durée de session, longueur des mots de passe ;
- déconnecter tout le monde d'un coup.

**Même pour le super administrateur**, *Vider les projets*, *Tout réinitialiser* et *Restaurer* exigent de retaper le mot de passe super administrateur, et le serveur le vérifie. Changer ce mot de passe exige l'ancien. En cas d'oubli, supprimez `data/superadmin.json` sur le serveur (voir section 5).

**Devenir super administrateur** : *Paramétrage › Sécurité & accès › Devenir super administrateur*. Il faut avoir le droit « Utilisateurs » et saisir le mot de passe super administrateur. C'est la seule porte d'entrée vers ce rôle, en dehors d'un super administrateur existant.

**Contrôlé par le serveur** (une manipulation par la console F12 est refusée) :
- les rôles, les utilisateurs et le rôle super administrateur ;
- le mode maintenance ;
- les comptes en lecture seule ;
- les opérations destructrices.

Les autres droits (par exemple « Suppression » pour quelqu'un qui a « Projets ») sont appliqués par l'interface. Le serveur enregistre l'auteur de chaque modification.

## 8. Reprise de l'outil actuel (projets, paramètres, écrans, utilisateurs)

**Fiche projet** : mêmes rubriques que l'outil actuel.
- *Projet* : type, commercial, code Vega, agence, terminé & archivé.
- *Client* : entreprise, civilité, prénom et nom du contact, adresse, code postal, ville, téléphone, portable, email.
- *Planning* : date demandée.
- *Distributeur automatique* : modèle, matricule (repris dans l'étape de production), nombre de DA, nombre de clés, système de paiement, particularités.

Ces champs sont aussi dans les exports et les imports Excel/CSV des projets.

**Catalogue des distributeurs** : les modèles n° 18 à 141 de l'outil actuel sont ajoutés automatiquement, une seule fois, au premier chargement de la nouvelle page. Un nom qui commence par « zz » dans l'ancien outil est un modèle retiré : il est ajouté sans le « zz » et décoché « Au catalogue ». Les n° 1 à 17 n'étaient pas dans l'enregistrement fourni : complétez-les dans *Paramétrage › Modèles de distributeur* (colonne « N° outil actuel »).

**Groupes de personnel** : renommés comme dans l'outil actuel, et les groupes « Agences + partiel » (Production) et « Partiel » (Installation) sont ajoutés. Filtres par agence et par service, colonne « Nombre de personnels ».

**Paramètres** :
- délais après chaque service (production, intersite…) et entre deux opérations ;
- durée du défilement de chaque vue écran ;
- clés des vues écrans.

**Clés des vues écrans** (base partagée uniquement) : l'adresse copiée depuis *Vues écrans* contient une clé. Un écran d'atelier l'ouvre sans compte, en lecture seule. Il ne reçoit ni les coordonnées des clients, ni les utilisateurs, ni le journal. Une clé ne donne accès qu'à sa vue ; la clé « Rotation » donne accès à toutes les vues. Changer une clé (bouton ↻ puis *Enregistrer*) coupe immédiatement l'accès des écrans qui utilisent l'ancienne adresse. Une adresse avec clé donne un accès en lecture à qui la possède : ne la diffusez pas hors des postes d'affichage.

**Utilisateurs** : la liste n'est pas intégrée au fichier HTML, car elle contient les emails des collaborateurs et le dépôt GitHub de l'application est public. Importez le fichier `utilisateurs-d8.csv` fourni à part : *Paramétrage › Utilisateurs › Importer*.
- Une personne déjà présente est reconnue à son email, sinon à son nom.
- Deux homonymes avec des emails différents restent deux personnes.
- Le rôle super administrateur n'est jamais retiré par un import.
- La date de dernière utilisation de l'ancien outil est reprise : le bouton « Désactiver les comptes dormants » s'appuie dessus.

## 9. Retours d'utilisation (octobre 2026)

**Saisie des projets**
- *Date demandée* : se tape au clavier (jj/mm/aaaa) ou se remplit avec un raccourci : +1 semaine, +1, +3 ou +6 mois, +1 an.
- *Dossier multi-machines* : dès 2 DA, la case « une fiche par machine » crée un dossier numéroté (D2026-0001). Il contient une fiche par machine, numérotée 1/N, 2/N…, chacune avec ses étapes et ses dates.
  - Pour les matricules, saisissez-en un par machine, séparés par des espaces.
  - La fiche d'une machine liste tout le dossier ; le bouton « Ajouter une machine » renumérote les fiches.
- *Type « Fixation »* : disponible dans les types de projet. Il apparaît dans les statistiques par type.
- *Champs obligatoires* : seuls l'entreprise, le type et l'agence le sont. L'email n'est contrôlé que s'il est rempli.

**Imports (Vega, Excel)**
- Un champ corrigé à la main dans le planning (téléphone, contact, adresse…) n'est plus écrasé par les imports suivants. La fiche projet le signale et propose « Réautoriser les imports ».
- Un projet existant est aussi reconnu à son matricule. Les modèles inconnus sont créés automatiquement.
- La liste des modèles indique combien de projets utilisent chacun : un modèle à 0 peut être retiré du catalogue sans risque.

**Rôles** (créés automatiquement à la première connexion d'un administrateur)

| Rôle | Peut modifier | Consulte |
|---|---|---|
| Administrateur | tout, paramétrage et utilisateurs compris | tout |
| Éditeur planning | projets, planning de tous les services, absences | tout |
| Planification | création et modification des projets | plannings en lecture seule |
| Planification agence | projets, planning et absences de **son agence** (champ « Agence » de sa fiche utilisateur) | les autres agences, en lecture seule (box grisées) |
| Commercial | rien | plannings, projets, filtre « Bloqués » et « Avec étapes à planifier » |

Le rôle Commercial n'est passé en lecture seule que s'il avait encore ses droits d'origine. Si vous l'aviez déjà modifié, il reste tel quel. Comme les droits « Projets » et « Planning », la limite par agence est appliquée par l'interface. Le serveur, lui, ne contrôle que les rôles, les utilisateurs et les opérations destructrices (voir section 7).

**Planning**
- *Durée à la souris* : étirez la poignée à droite d'une box ; chaque cran vaut une demi-journée, ou une journée pour les services planifiés à la journée. Au clavier, sélectionnez la box puis appuyez sur + ou −.
- *Absences* : saisie par demi-journée (« dès l'après-midi », « jusqu'au matin »). Elles s'affichent en calque hachuré transparent, et les box en dessous restent visibles et cliquables. Cliquez l'étiquette de l'absence pour la modifier.
- *Séries compactes* : plusieurs machines d'un même dossier sur le même créneau s'affichent en box fines empilées. Le bouton « Séries compactes » active ou désactive cet affichage.
- *Anciens collaborateurs* : renseignez la « Date de départ » sur leur fiche. Ils disparaissent des plannings, sauf sur les semaines où ils ont des opérations : ces lignes restent grisées, l'historique est conservé.
- *Recherche* : dans *Projets*, la recherche par client se combine avec une période (du… au…). Un projet ressort si sa date demandée ou une de ses étapes planifiées tombe dans la période.

**Statistiques** : *Rapports › Rendement atelier*.
- Machines terminées, ou jours réalisés, par opérateur et par mois, avec les partiels à part.
- Comparaison avec l'année précédente « à date » : même jour de l'année précédente, pour une comparaison juste en cours d'année.
- *Phase de chronométrage* : saisissez le « Temps réel passé » dans chaque opération terminée (informatique, PDA et magasin compris). Le rapport compare, modèle par modèle, le temps réel moyen à la durée prévue et propose une durée standard arrondie à la demi-journée. Attendez au moins 5 mesures par modèle avant de modifier les durées.

**Préparation (P)** : l'étape est retirée du menu, du tableau de bord, des alertes et des box. Les données existantes sont conservées. Elle peut être réactivée dans *Paramètres › Planification*.
