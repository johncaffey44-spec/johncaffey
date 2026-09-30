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
6. Commencer par vous connecter vous-même, en tant qu'administrateur. À la toute première ouverture (serveur vide), l'écran *Première mise en service* vous fait choisir votre nom parmi les administrateurs, puis un identifiant et un mot de passe.
7. **Invitez ensuite chaque personne**, les autres administrateurs compris : *Paramétrage › Utilisateurs*, bouton **enveloppe** sur sa ligne. Transmettez-lui le lien obtenu (bouton *Envoyer par courriel* ou *Copier le lien*). En l'ouvrant, elle choisit son identifiant et son mot de passe. Ensuite, ils sont demandés **à chaque ouverture du navigateur**.
8. **Écrans d'atelier** : créez une fiche utilisateur dédiée (ex. « Écran atelier », rôle *Lecture seule*), créez son accès une fois sur chaque écran, puis laissez le navigateur ouvert. La session reste active tant que l'écran interroge le serveur.

## 4. Fonctionnement de la connexion

- **Première mise en service** (serveur sans planning ni compte) : un administrateur choisit son nom et crée son identifiant et son mot de passe. C'est le seul accès qui se crée sans invitation.
- **Invitation** : un administrateur clique sur l'enveloppe d'une personne dans *Paramétrage › Utilisateurs*. Après la création d'une fiche, la page propose aussi de l'inviter tout de suite. Le lien obtenu est **personnel**, ne sert **qu'une fois** et expire après **7 jours** (`$INVITE_DAYS`). Un nouveau lien annule le précédent. La colonne « Identifiant » affiche *à inviter*, *invité · expire le…*, puis l'identifiant choisi.
- **Créer son accès** : la personne ouvre son lien, voit son nom, choisit un identifiant et un mot de passe (8 caractères minimum). Sans lien valable, le serveur refuse toute création d'accès.
- Le serveur ne garde que l'**empreinte** du lien (`data/invitations.json`), jamais le lien lui-même.
- **Se connecter** : identifiant et mot de passe. La session dure jusqu'à la fermeture du navigateur, ou jusqu'à 12 h sans aucun échange avec le serveur (`$SESSION_IDLE`).
- **Session expirée pendant le travail** : les modifications en attente sont **conservées**. La page redemande le mot de passe, puis les enregistre.
- **Mot de passe oublié** : un administrateur clique sur le cadenas de la personne dans *Paramétrage › Utilisateurs* (colonne « Identifiant »). L'accès est supprimé et la page propose aussitôt d'envoyer une nouvelle invitation.
- **Changer son mot de passe** : menu en haut à droite › *Changer mon mot de passe*. Cette action ferme ses sessions sur les autres postes.
- **Compte désactivé** (case « Compte actif ») : la connexion est refusée.
- **Anti-force brute** : après 8 échecs depuis une même adresse IP, la connexion est bloquée 15 min.
- Les mots de passe sont stockés **hachés** (bcrypt) dans `data/accounts.json`. Ils ne sont jamais écrits dans le planning, ni dans les exports JSON.

### Limites à connaître

1. **Un lien d'invitation vaut un accès** tant qu'il n'a pas servi : envoyez-le à la personne concernée seulement (courriel interne, messagerie), jamais dans un canal partagé. Un lien intercepté puis utilisé avant elle se repère facilement : la personne voit « lien plus valable » et la colonne affiche un identifiant qu'elle n'a pas choisi. Réinitialisez alors l'accès et réinvitez-la.
2. **En http, les mots de passe circulent en clair** sur le réseau interne. Passez en HTTPS dès que possible (certificat interne de l'AD CS, par exemple).
3. **Le serveur vérifie *qui* enregistre, pas *ce qui* est modifié.** Les droits par rôle (Commercial, Lecture seule…) sont appliqués par l'interface. Une personne connectée et techniquement habile peut encore modifier le document complet. Ses enregistrements sont toutefois signés par sa session, dans le journal et dans `planning.meta.json`.
4. **« Reprendre là où je m'étais arrêté »** (Chrome, Edge) conserve les cookies de session : dans ce cas, la fermeture du navigateur ne déconnecte pas. La déconnexion se fait par le menu, ou après 12 h d'inactivité.
5. **Base locale** : il n'y a pas de connexion. Toutes les données sont dans le navigateur du poste, donc un mot de passe vérifié dans le navigateur serait purement décoratif.
