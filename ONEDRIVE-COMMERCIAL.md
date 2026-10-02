# Documents commerciaux : OneDrive, Teams et SharePoint sans impact pour les collègues

Fichier : `onedrive-commercial.html`, une seule page, sans serveur, sans base de données et sans PHP.

La page se connecte à Microsoft 365 **avec le compte de la personne** et lui affiche :

- son OneDrive ;
- les éléments partagés avec elle ;
- les fichiers de ses équipes Teams et des sites SharePoint qu'elle suit.

Elle peut tout **lire, prévisualiser et télécharger** (PDF et .zip compris). Pour **modifier** un document que d'autres voient, l'outil crée d'abord une **copie personnelle**. L'original et les collègues ne voient donc aucun changement.

Pour l'essayer sans rien configurer : ouvrez la page et cliquez sur **Découvrir la démonstration**, ou ajoutez `?demo` à l'adresse. La démonstration utilise des données fictives et n'envoie rien à Microsoft.

---

## 1. À lire d'abord : ce que l'outil garantit, et ce qu'il ne garantit pas

### La règle

> L'outil ne modifie **jamais** un élément que quelqu'un d'autre peut voir.

| Action | Document d'un collègue, d'une équipe ou d'un site | Mon document **partagé** | Mon document **privé** |
|---|---|---|---|
| Aperçu, téléchargement, PDF, .zip | oui | oui | oui |
| Voir l'historique des versions | oui (consultation seule) | oui | oui |
| « Modifier » | crée **ma copie**, puis l'ouvre | crée **ma copie**, puis l'ouvre | modification directe |
| Renommer, supprimer | non proposé | désactivé | oui (dossier : seulement s'il est vide) |
| Ajouter des fichiers, créer un dossier | non | non | oui |
| Restaurer une version, partager, déplacer | **jamais** | **jamais** | **jamais** |

### Deux verrous indépendants

1. **Verrou Microsoft.** L'application ne reçoit que `Files.ReadWrite`, qui autorise l'écriture dans **son propre OneDrive** uniquement, et des droits de **lecture** ailleurs (`Files.Read.All`, `Sites.Read.All`). Si la page tentait d'écrire dans le OneDrive d'un collègue ou dans un site d'équipe, **Microsoft refuserait**, même en cas de bogue.
2. **Verrou de la page.** Dans le OneDrive de la personne, avant chaque écriture, la page relit les autorisations de l'élément, y compris celles héritées du dossier parent. Elle refuse s'il est partagé. Si la vérification échoue, l'élément est traité comme partagé.

### Ce que l'outil ne peut PAS garantir

- **En dehors de l'outil, aucune de ces protections ne s'applique.** Dans OneDrive, SharePoint, Teams, Word, Excel ou le client de synchronisation, un commercial qui a le droit de modifier peut toujours modifier ou supprimer l'original. L'outil est un **garde-fou pratique**, pas une barrière de sécurité.
  - **La vraie protection**, qui vaut partout : donner au groupe commercial le niveau **Lecture** (et non Modification) sur les bibliothèques ou dossiers de référence (Tarifs, CGV, Modèles). Seuls leurs responsables (direction commerciale) gardent la modification. C'est 10 minutes de réglage dans SharePoint, et c'est **à faire en plus de l'outil**, pas à la place.
  - Piège classique : un dossier partagé **synchronisé** sur un PC (client OneDrive, « Ajouter un raccourci à Mes fichiers »). Une suppression locale le supprime pour tout le monde.
- **Une copie vieillit.** Une copie de la grille tarifaire faite en janvier est fausse en juin. L'outil compare chaque copie à son original et affiche **« Original modifié »**. La décision reste humaine : avant d'envoyer des prix à un client, il faut regarder l'alerte.
- **Lire laisse une trace.** Comme toute utilisation de Microsoft 365, les consultations et téléchargements figurent dans le journal d'audit de l'entreprise. Selon le paramétrage, elles apparaissent aussi dans les statistiques d'affichage d'un fichier SharePoint.
- **Accès donnés sur tout un OneDrive.** Un accès accordé par un administrateur à tout le OneDrive d'une personne (compte d'administration, accès du responsable après un départ) n'est pas considéré comme un partage : il concerne tout le OneDrive, copies comprises.

---

## 2. Prérequis

- Un abonnement **Microsoft 365 professionnel**. Les comptes Microsoft personnels ne sont pas pris en charge.
- Une personne ayant le rôle **Administrateur d'application cloud** ou **Administrateur général** pour l'étape 3.1. Si ce n'est pas votre cas chez D8, c'est l'étape à faire avec l'administrateur ou le prestataire Microsoft 365.
- Un serveur web interne capable de servir la page en **HTTPS**. Microsoft **refuse** la connexion depuis une adresse `http://`, sauf `http://localhost`. Le planning, servi en `http://`, ne convient donc pas tel quel (voir 3.2).
- Un navigateur à jour : Edge, Chrome, Firefox ou Safari, sur PC ou téléphone.

---

## 3. Mise en service

### 3.1 Déclarer l'application dans Microsoft Entra ID

Les libellés du portail changent parfois légèrement.

1. Ouvrez <https://entra.microsoft.com> › **Identité** › **Applications** › **Inscriptions d'applications** › **Nouvelle inscription**.
2. Remplissez le formulaire :
   - **Nom** : `D8 Commercial - Documents` ;
   - **Types de comptes pris en charge** : *Comptes dans cet annuaire d'organisation uniquement (locataire unique)* ;
   - **URI de redirection** : plateforme **Application monopage (SPA)**, adresse **exacte** de la page, par exemple `https://intranet.d8.local/documents/onedrive-commercial.html`.
     - Même casse, sans `?` ni `#`.
     - L'écran « Outil pas encore configuré » de la page affiche l'adresse exacte à déclarer.
     - Si vous choisissez la plateforme **Web** au lieu de **SPA**, la connexion échoue (erreur AADSTS9002326).
3. Sur la page de l'application, notez :
   - **ID d'application (client)**, qui devient `clientId` ;
   - **ID de l'annuaire (locataire)**, qui devient `tenantId`.
4. Allez dans **Autorisations de l'API** › **Ajouter une autorisation** › **Microsoft Graph** › **Autorisations déléguées**, puis cochez :
   - `User.Read`
   - `Files.ReadWrite`
   - `Files.Read.All`
   - `Sites.Read.All`
   - `Team.ReadBasic.All` (liste des équipes Teams ; sinon mettez `"equipesTeams": false`)
   - `openid`, `profile`, `offline_access`

   **N'ajoutez PAS** `Files.ReadWrite.All` ni `Sites.ReadWrite.All` : c'est leur absence qui fait le verrou Microsoft décrit en section 1.
5. Cliquez sur **Accorder un consentement d'administrateur pour <votre organisation>**. Sinon, chaque commercial verra une demande d'autorisation, ou un refus si le consentement des utilisateurs est désactivé.
6. **Réservez l'outil au service commercial** (recommandé) :
   1. **Applications d'entreprise** › `D8 Commercial - Documents` › **Propriétés** › **Affectation obligatoire ?** = **Oui**.
   2. **Utilisateurs et groupes** › ajoutez le groupe du service commercial. L'affectation d'un *groupe* demande Entra ID P1 (inclus dans Microsoft 365 Business Premium). Sans P1, ajoutez les personnes une par une.
7. **Ne créez ni secret ni certificat.** Une application monopage n'en a pas besoin (connexion OAuth 2.0 avec PKCE), et un secret dans une page web serait public.

### 3.2 Héberger la page en HTTPS

Déposez `onedrive-commercial.html` dans un site web interne servi en HTTPS : IIS avec un certificat de votre autorité interne (AD CS) ou un certificat public. La page n'a besoin **ni de PHP ni de droits d'écriture** sur le serveur.

**Ne la déposez pas dans SharePoint ni dans OneDrive** : ils téléchargent les fichiers HTML au lieu de les exécuter.

En-têtes conseillés. Pour **IIS**, ajoutez ceci au `web.config` du dossier (à fusionner avec celui du planning s'il est dans le même dossier) :

```xml
<configuration>
  <location path="onedrive-commercial.html">
    <system.webServer>
      <staticContent>
        <clientCache cacheControlMode="DisableCache" />
      </staticContent>
      <httpProtocol>
        <customHeaders>
          <add name="Content-Security-Policy" value="frame-ancestors 'none'" />
          <add name="X-Frame-Options" value="DENY" />
          <add name="X-Content-Type-Options" value="nosniff" />
          <add name="Referrer-Policy" value="no-referrer" />
        </customHeaders>
      </httpProtocol>
    </system.webServer>
  </location>
</configuration>
```

Pour **Apache**, ajoutez ceci au `.htaccess` (module `mod_headers`) :

```apache
<Files "onedrive-commercial.html">
  Header set Cache-Control "no-cache"
  Header set Content-Security-Policy "frame-ancestors 'none'"
  Header set X-Frame-Options "DENY"
  Header set Referrer-Policy "no-referrer"
</Files>
```

Autres possibilités :

- **Essai rapide sur un poste** : lancez `python -m http.server 8080` dans le dossier, déclarez `http://localhost:8080/onedrive-commercial.html` comme URI de redirection SPA, puis ouvrez cette adresse.
- **Sans serveur interne HTTPS** : un hébergement statique HTTPS comme Azure Static Web Apps (offre gratuite) convient. La page ne contient aucun secret, et les documents ne transitent jamais par l'hébergeur, puisque le navigateur parle directement à Microsoft.

### 3.3 Renseigner les réglages

Ouvrez le fichier dans un éditeur de texte (Bloc-notes, VS Code) et modifiez **uniquement** le bloc `<script type="application/json" id="config">` en haut du fichier. Le format est du **JSON strict** : guillemets doubles, pas de virgule après le dernier élément. Enregistrez en **UTF-8**.

| Réglage | Rôle | Valeur par défaut |
|---|---|---|
| `tenantId` | ID de l'annuaire (locataire) | **obligatoire** |
| `clientId` | ID d'application (client) | **obligatoire** |
| `organisation`, `service` | Textes affichés (logo, titre) | `D8`, `Commercial` |
| `redirectUri` | À renseigner seulement si l'adresse déclarée diffère de celle de la page (proxy inverse) | vide (adresse de la page) |
| `dossierCopies` | Nom du dossier des copies dans le OneDrive de chacun. **Ne le changez plus après la mise en service** : les copies de l'ancien dossier ne seraient plus suivies. | `Mes copies de travail` |
| `copiesAncienneteJours` | Âge à partir duquel « Nettoyer les anciennes copies » propose une copie | `90` |
| `tailleMaxCopieMo` | Taille maximale d'une copie faite par le navigateur (fichier, ou dossier recopié fichier par fichier) | `250` |
| `tailleMaxZipMo` | Taille maximale d'un .zip préparé dans le navigateur | `1024` |
| `deconnexionInactiviteMin` | Déconnexion après N minutes sans action (`0` : jamais). Utile sur un poste partagé. | `0` |
| `equipesTeams` | Afficher les équipes Teams (demande `Team.ReadBasic.All`) | `true` |
| `support` | Contact affiché dans l'aide | `Service informatique` |
| `raccourcis` | Tuiles de l'accueil : `titre`, `description`, `lien` | 4 exemples sans lien |

**Raccourcis du service.** Dans SharePoint ou Teams, ouvrez le dossier (Tarifs, Modèles de devis, Catalogue, CGV), copiez l'adresse de la barre du navigateur ou un lien **« Personnes ayant déjà accès »**, et collez-la dans `lien`. Une tuile dont le lien est vide n'est pas affichée.

L'outil n'« utilise » jamais un lien de partage : il ne s'ajoute aucun droit. Une personne sans accès au dossier verra donc « inaccessible ».

Ce bloc n'est pas couvert par l'empreinte de sécurité (section 5) : le modifier ne casse rien.

### 3.4 Recette avant de l'annoncer (30 minutes, avec deux comptes : A et B)

1. A se connecte : son prénom s'affiche.
2. Premier contrôle, **le plus important** :
   1. A crée une copie de n'importe quel document.
   2. Dans **Mes copies de travail**, bouton ⓘ du dossier : le panneau doit afficher **« Visible par vous seul »**.
   3. Si l'outil affiche « Partagé », notez les personnes listées et prévenez-moi : c'est l'accès par défaut de votre OneDrive qu'il faut ajuster.
3. B partage un fichier Word avec A. A l'ouvre dans l'outil, en aperçu puis en PDF. Dans SharePoint, l'**historique des versions** de l'original ne doit comporter aucune nouvelle version.
4. A clique sur **Modifier** sur ce fichier. Une copie est créée et s'ouvre, et l'original reste inchangé (vérifiez l'historique).
5. B modifie l'original. A clique sur **Vérifier les originaux** dans « Mes copies de travail » : l'alerte **Original modifié** apparaît.
6. A partage un de ses dossiers avec B. Dans l'outil, le dossier affiche « Partagé par vous », et **Renommer** et **Supprimer** sont grisés.
7. Faites un essai sur téléphone.
8. Un compte **non affecté** à l'application doit être refusé (AADSTS50105).
9. **Télécharger en PDF** un fichier Excel.
   - Si le navigateur refuse la conversion, un message s'affiche (voir la section 6).
   - Notez le navigateur concerné.

---

## 4. Ce que l'outil apporte au service commercial

- **Accueil** :
  - raccourcis du service (tarifs, modèles, catalogue, CGV) ;
  - alertes sur les copies dépassées ;
  - favoris, documents récents, espace OneDrive utilisé.
- **Navigation** : Mon OneDrive, Partagés avec moi, Équipes Teams et sites suivis, fil d'Ariane, tri, filtres par type (Word, Excel, PDF…), filtre par nom, sélection multiple.
- **Recherche** dans tout Microsoft 365 (Microsoft Search), ou limitée au dossier courant.
- **Aperçu** sans rien télécharger :
  - PDF, images, textes ;
  - CSV affichés en tableau (séparateur `;` des exports Excel français, encodage Windows-1252 reconnu) ;
  - Word, Excel et PowerPoint en lecture seule, avec bascule en PDF si besoin ;
  - passage au document précédent ou suivant avec ← et →.
- **Téléchargement** d'un fichier, d'une sélection ou d'un dossier entier en **.zip**, et **conversion PDF** des documents Office (pour l'envoi au client).
- **Préparer un devis** avec « Utiliser comme modèle » : copie nommée `Modèle devis - <Client> - JJ-MM-AAAA.docx`, rangée au choix dans un sous-dossier client, puis ouverte dans Word ou Excel pour le Web.
- **Mes copies de travail** :
  - suivi de chaque copie par rapport à son original (modifié, introuvable) ;
  - « Recréer une copie à jour » ;
  - « Ignorer l'alerte » ;
  - nettoyage des copies anciennes (données clients, RGPD).
- **Détails** d'un élément :
  - qui y a accès ;
  - dates et auteurs ;
  - historique des versions (consultation et téléchargement d'une ancienne version, jamais de restauration) ;
  - pour une copie, l'original dont elle vient.
- **Ajouter par un lien** : coller un lien reçu par e-mail ou Teams pour retrouver le document en favori.
- **Éditeur de texte** intégré pour les fichiers .txt et .csv privés :
  - brouillon conservé dans l'onglet ;
  - détection d'une modification faite ailleurs entre-temps ;
  - CSV réenregistré en UTF-8 lisible par Excel.
- **Mon activité** : historique local des actions (téléchargements, copies…).
- **Confort** :
  - thème clair ou sombre ;
  - utilisable sur téléphone ;
  - raccourci `/` pour rechercher ;
  - protection contre la fermeture de l'onglet pendant un envoi ;
  - reprise automatique quand Microsoft limite les requêtes.

---

## 5. Sécurité de la page

- **Pas de serveur, pas de secret.** La connexion utilise OAuth 2.0 avec PKCE, directement auprès de Microsoft.
- **Jetons de connexion.** Ils sont gardés **dans l'onglet** (`sessionStorage`), effacés à la fermeture de l'onglet ou par « Se déconnecter ». Microsoft limite leur renouvellement à 24 h pour ce type d'application : il faut ensuite se reconnecter, souvent sans ressaisir de mot de passe.
- **Droits délégués.** L'outil ne peut jamais faire plus que ce que la personne a le droit de faire, et il fait volontairement moins (section 1).
- **Aucune donnée ailleurs que chez Microsoft.**
  - Les favoris, les récents et « Mon activité » sont dans le navigateur du poste.
  - Le suivi des copies est un petit fichier `_suivi-des-copies.json` dans « Mes copies de travail ». L'outil le masque. S'il est supprimé, les copies restent, mais leur suivi est perdu.
- **Empreinte du programme (CSP).**
  - La page n'exécute que **son propre programme**, identifié par une empreinte SHA-256 inscrite dans la balise `Content-Security-Policy` en tête du fichier. Un script injecté (par un nom de fichier piégé, par exemple) ne s'exécuterait pas.
  - Les réseaux autorisés se limitent à Microsoft (`login.microsoftonline.com`, `graph.microsoft.com`, `*.sharepoint.com`, `*.svc.ms`).
  - **Si vous modifiez le programme** (le dernier `<script>`, pas le bloc de réglages), la page reste bloquée sur « Chargement… ». Recalculez l'empreinte et remplacez la valeur `sha256-…` de la balise, avec cette commande PowerShell lancée dans le dossier du fichier :

    ```powershell
    $h = Get-Content -Raw -Encoding UTF8 .\onedrive-commercial.html
    $a = $h.LastIndexOf('<script>') + 8; $b = $h.LastIndexOf('</script>')
    $js = $h.Substring($a, $b - $a) -replace "`r`n", "`n"
    'sha256-' + [Convert]::ToBase64String([Security.Cryptography.SHA256]::Create().ComputeHash([Text.Encoding]::UTF8.GetBytes($js)))
    ```

  - Les fins de ligne Windows (CRLF) ne changent pas l'empreinte : le navigateur les normalise.
- **Aperçu Office.**
  - Il s'affiche dans un cadre isolé (`sandbox`) **sans droit d'ouvrir de nouvelle fenêtre**. Le bouton « Modifier dans Word » de la visionneuse Microsoft ne peut donc pas ouvrir l'original en modification.
  - Les liens d'aperçu fournis par Microsoft agissent au nom de la personne et ne doivent pas être partagés. L'outil ne les affiche pas.

---

## 6. Limites connues et choix assumés

1. **« Partagés avec moi » : Microsoft retire l'API officielle en novembre 2026.**
   - Elle fonctionne déjà en mode dégradé et ne renverra plus rien ensuite.
   - L'outil l'interroge tant qu'elle répond, et la complète par une recherche Microsoft Search (`SharedWithUsersOWSUSER`).
   - Cette recherche ne voit que les documents **partagés nommément** avec la personne : pas ceux partagés à un groupe ou par un lien « toute l'organisation ».
   - Pour le reste, utilisez **Équipes et sites**, la **recherche**, ou **Ajouter par un lien**.
   - Microsoft n'a annoncé aucun remplaçant exact : à surveiller.
2. **Recherche.** Un document tout juste ajouté peut mettre quelques minutes à être trouvé (indexation Microsoft).
3. **Conversion PDF.**
   - Microsoft la fournit par une redirection que sa documentation déclare incompatible avec les pages web. Elle fonctionne dans les navigateurs récents, mais sans garantie.
   - En cas d'échec, le message invite à ouvrir sa copie dans Office puis *Fichier › Enregistrer sous › PDF*.
4. **Copies.**
   - Microsoft refuse la copie « côté serveur » depuis l'espace d'un autre avec les droits limités de l'outil (c'est le verrou). L'outil télécharge donc puis renvoie le fichier dans votre OneDrive, jusqu'à `tailleMaxCopieMo`.
   - Un dossier est recopié fichier par fichier : 500 fichiers au plus, blocs-notes OneNote exclus.
   - Les métadonnées et l'historique de l'original ne sont pas copiés.
5. **Anciens formats Office** (.doc, .xls, .ppt) : pour modifier la copie, Office pour le Web propose une conversion, qui crée un nouveau fichier à côté.
6. **Suivi d'un dossier copié d'un bloc** (dossier de votre propre OneDrive) : l'alerte se base sur la date du dossier, moins fiable que celle de chaque fichier.
7. **Hors ligne** : rien n'est conservé. Téléchargez avant un rendez-vous sans réseau.
8. **Volumes.** Un .zip est préparé en mémoire dans le navigateur, jusqu'à `tailleMaxZipMo` et 4 000 fichiers. Au-delà, téléchargez les sous-dossiers séparément.
9. **Ce qui a été testé, et ce qui ne l'a pas été.** L'outil a été testé de bout en bout dans Chromium :
   - en **démonstration** : 39 contrôles ;
   - contre une **simulation de Microsoft 365** : 19 contrôles, dont la connexion PKCE, le renouvellement du jeton, les reprises après les erreurs 401 et 429, la copie de secours, le conflit de modification, et l'absence d'écriture sur l'espace partagé.

   Il n'a **pas encore** été testé sur un vrai locataire Microsoft 365. La recette de la section 3.4 sert précisément à valider les points qui dépendent du comportement réel de Microsoft : liste des accès sur un OneDrive, aperçu Office, conversion PDF, recherche des partages.

---

## 7. Messages d'erreur fréquents à la connexion

| Message ou code | Cause | Solution |
|---|---|---|
| « Votre compte n'est pas autorisé » (AADSTS50105) | Affectation obligatoire, personne non affectée | Ajouter la personne ou son groupe (3.1, étape 6) |
| « Consentement » (AADSTS65001, 90094) | Consentement administrateur non accordé | 3.1, étape 5 |
| « Adresse non déclarée » (AADSTS50011) | L'URI de redirection ne correspond pas exactement | Copier l'adresse affichée par la page dans Entra ID (plateforme SPA) |
| « Plateforme Web » (AADSTS9002326) | Adresse déclarée en « Web » | La déclarer en « Application monopage » |
| « Application introuvable » (AADSTS700016) | `clientId` ou `tenantId` erroné | Vérifier les réglages |
| « Accès conditionnel » (AADSTS53003) | Poste non conforme, pays bloqué… | Voir les stratégies d'accès conditionnel |
| « La page doit être ouverte en HTTPS » | Adresse `http://` (hors localhost) | 3.2 |
| Page bloquée sur « Chargement… » | Programme modifié sans nouvelle empreinte, ou navigateur trop ancien | Section 5 |
| « Réglages illisibles » | Erreur de JSON dans le bloc de réglages | Guillemets et virgules |

---

## 8. Mettre l'outil à jour

1. **Copiez le bloc de réglages** de l'ancienne version.
2. Remplacez `onedrive-commercial.html` par la nouvelle version, **au même emplacement et sous le même nom**. L'adresse est déclarée dans Entra ID.
3. Recollez le bloc de réglages dans le nouveau fichier.
4. Ouvrez la page avec **Ctrl + F5**.

Rien d'autre n'est à migrer : les copies et leur suivi sont dans le OneDrive de chacun.
