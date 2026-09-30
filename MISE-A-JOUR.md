# Planning D8 — connexion et mise à jour sans perte de données

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
6. Commencer par vous connecter vous-même, en tant qu'administrateur : *Créer mon accès*, puis choisir votre nom, un identifiant et un mot de passe.
7. Chacun fait de même à sa première connexion. Ensuite, l'identifiant et le mot de passe sont demandés **à chaque ouverture du navigateur**.
8. **Écrans d'atelier** : créez une fiche utilisateur dédiée (ex. « Écran atelier », rôle *Lecture seule*), créez son accès une fois sur chaque écran, puis laissez le navigateur ouvert. La session reste active tant que l'écran interroge le serveur.

## 4. Fonctionnement de la connexion

- **Créer son accès** : on choisit son nom dans la liste des fiches *Paramétrage › Utilisateurs* qui sont actives et n'ont pas encore d'accès, puis on saisit un identifiant et un mot de passe (8 caractères minimum).
- **Se connecter** : identifiant et mot de passe. La session dure jusqu'à la fermeture du navigateur, ou jusqu'à 12 h sans aucun échange avec le serveur (`$SESSION_IDLE`).
- **Session expirée pendant le travail** : les modifications en attente sont **conservées**. La page redemande le mot de passe, puis les enregistre.
- **Mot de passe oublié** : un administrateur clique sur le cadenas de la personne dans *Paramétrage › Utilisateurs* (colonne « Identifiant »). L'accès est supprimé et la personne le recrée.
- **Changer son mot de passe** : menu en haut à droite › *Changer mon mot de passe*. Cette action ferme ses sessions sur les autres postes.
- **Compte désactivé** (case « Compte actif ») : la connexion est refusée.
- **Anti-force brute** : après 8 échecs depuis une même adresse IP, la connexion est bloquée 15 min.
- Les mots de passe sont stockés **hachés** (bcrypt) dans `data/accounts.json`. Ils ne sont jamais écrits dans le planning, ni dans les exports JSON.

### Limites à connaître

1. **Création libre des accès** : sans code, la première personne qui choisit un nom encore « à créer » en prend l'accès. Il y a deux parades : renseigner `$SIGNUP_CODE` dans `api.php` et le donner de vive voix, ou faire créer les accès le jour même de la mise en service.
2. **En http, les mots de passe circulent en clair** sur le réseau interne. Passez en HTTPS dès que possible (certificat interne de l'AD CS, par exemple).
3. **Le serveur vérifie *qui* enregistre, pas *ce qui* est modifié.** Les droits par rôle (Commercial, Lecture seule…) sont appliqués par l'interface. Une personne connectée et techniquement habile peut encore modifier le document complet. Ses enregistrements sont toutefois signés par sa session, dans le journal et dans `planning.meta.json`.
4. **« Reprendre là où je m'étais arrêté »** (Chrome, Edge) conserve les cookies de session : dans ce cas, la fermeture du navigateur ne déconnecte pas. La déconnexion se fait par le menu, ou après 12 h d'inactivité.
5. **Base locale** : il n'y a pas de connexion. Toutes les données sont dans le navigateur du poste, donc un mot de passe vérifié dans le navigateur serait purement décoratif.
