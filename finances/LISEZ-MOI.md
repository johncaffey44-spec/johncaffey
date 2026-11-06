# Boussole — les finances du foyer, en toute sérénité

Boussole lit vos relevés bancaires, calcule ce qui entre et ce qui sort, classe chaque dépense
(essentiel, plaisir, évitable), vérifie les frais de la banque au regard des plafonds légaux,
prépare les courriers de réclamation et range vos papiers dans un coffre-fort chiffré.
Chacun des deux membres du foyer a son propre accès : rien n'est partagé sans son accord.

## 1. Ce que fait Boussole

| Onglet | Ce que vous y trouvez |
|---|---|
| **Tableau de bord** | Score de bien-être financier (5 critères), la « prochaine meilleure action », revenus nets, dépenses, reste du mois, répartition essentiel / plaisir / évitable, tendance sur 12 mois, **solde prévu jusqu'à la paie** et « ce que je peux dépenser par jour ». Vue *Moi* ou *Foyer*. |
| **Importer un relevé** | PDF, CSV, OFX, QFX, QIF ou texte collé. Aperçu corrigeable, contrôle « solde initial + mouvements = solde final », doublons écartés. |
| **Opérations** | Recherche, filtres, reclassement, « toujours classer ainsi », dépense commune, contestation en un clic. |
| **Alertes** | Frais au-delà des plafonds, rejet facturé plusieurs fois, 5 incidents dans le mois, prélèvement en double, nouveau créancier, petit paiement « test de carte », prélèvement après résiliation, hausse de prix, jeux d'argent, paiements en 3/4 fois empilés, effet « jour de paie », découvert prévu avant la paie, écart entre bulletin de paie et virement… |
| **Frais bancaires** | Coût réel de la banque sur 12 mois, ce qui est **exigible de droit** (et pourquoi), ce qui relève du **geste commercial**, conseils pour ne plus en payer. |
| **Abonnements** | Prélèvements et charges récurrentes détectés automatiquement, hausses de prix, coût annuel ; marquez « inutile » ou « résilié » : Boussole vous relance tant que c'est prélevé. |
| **Courriers** | 14 modèles prêts à copier (réclamation, contestation, 8 semaines, liste noire de créanciers, statut fragile, médiateur, résiliation…), pré-remplis avec vos coordonnées et les articles de loi. |
| **Vos droits** | Les règles qui protègent le client, vérifiées le 6 novembre 2026, avec leurs références. |
| **Coffre-fort** | Bulletins de paie, justificatifs, factures, chiffrés sur le serveur, avec leur durée de conservation officielle ; dossiers prêts (location, prêt, CAF, impôts) en .zip. |
| **Foyer & équité** | Répartition des dépenses communes au prorata des revenus ou à parts égales, virements entre vous neutralisés. |

## 2. Installation (10 minutes)

**Il faut** : un serveur web avec **PHP 7.4 ou plus** et les extensions `pdo_sqlite`, `sodium` *ou* `openssl`, et si possible `zip`.
Presque tous les hébergements les proposent d'origine (OVH, o2switch, IONOS, Hostinger…), tout comme un NAS Synology
(Web Station) ou un Raspberry Pi (`sudo apt install php php-sqlite3`).

1. Copiez **tout le dossier `finances`** sur le serveur (`index.html`, `api.php`, `lib/`, `assets/`, `vendor/`, `.htaccess`, `web.config`, `manifest.webmanifest`).
2. Donnez au serveur web le droit d'écrire dans ce dossier : `api.php` y crée `data/` au premier lancement.
3. Ouvrez `https://votre-site/finances/` : l'écran **Première mise en service** apparaît.
4. Saisissez le nom du foyer, **votre** prénom et **votre** mot de passe, puis le prénom de votre partenaire.
5. Notez votre **code de secours** (seul moyen de récupérer l'accès en cas d'oubli) et transmettez le **code d'invitation** à votre partenaire : il ou elle choisira son propre mot de passe.

> **N'hébergez pas vos finances personnelles sur le serveur ou le NAS de votre employeur.**
> L'administrateur y aurait accès, et le service ne vous appartient pas.

### Serveur nginx (le .htaccess y est ignoré)

```nginx
location ~ ^/finances/(data|lib|tests)/ { deny all; return 404; }
location ~ \.(sqlite|key|md)$ { deny all; return 404; }
```

L'application vérifie elle-même que `data/` n'est pas lisible depuis le navigateur et affiche une **bannière rouge** si c'est le cas.

## 3. Sécurité — à lire

- **HTTPS obligatoire dès que l'outil est accessible depuis Internet** : certificat gratuit Let's Encrypt, puis `$REQUIRE_HTTPS = true` en tête d'`api.php`. Sans https, mots de passe et relevés circulent en clair.
- **Dossier `data/` hors de la racine web** si votre hébergement le permet (`$DATA_DIR` en tête d'`api.php`).
- **Documents chiffrés** (libsodium, ou AES-256-GCM) avec la clé `data/secret.key`. Le chiffrement protège contre la fuite d'une sauvegarde ou d'un dossier exposé par erreur ; il ne protège pas contre quelqu'un qui contrôle le serveur.
- **Sauvegardez tout le dossier `data/`, clé comprise** (copie chiffrée sur un disque externe ou un cloud). Sans `secret.key`, les documents sont illisibles.
- Mots de passe hachés (bcrypt), session de 30 minutes, blocage après 5 erreurs, limitation par adresse IP, jeton anti-CSRF, journal des connexions (Réglages › Sécurité).
- **Mode discret** (icône œil en bas du menu) : floute les montants quand quelqu'un regarde l'écran.
- Les PDF sont lus **dans le navigateur** : ils ne transitent par aucun service extérieur. Aucune ressource externe n'est chargée.

## 4. La routine mensuelle (10 minutes)

1. **Le 5 du mois** : chacun importe le relevé du mois écoulé (export **CSV ou OFX** de préférence : espace client › comptes › télécharger / exporter).
2. **Alertes critiques** : préparez le courrier proposé, envoyez-le par la messagerie sécurisée de la banque, marquez l'alerte « traitée ».
3. **Coffre-fort** : déposez le bulletin de paie (avec le net à payer : Boussole vérifie qu'il correspond au virement reçu).
4. **Une fois par trimestre** : passez les abonnements en revue.

## 5. Ce qu'il faut savoir (limites honnêtes)

- **La loi plafonne les frais ; elle n'oblige pas à rembourser des frais conformes.** Boussole distingue toujours ce qui est *exigible* (plafond dépassé, prélèvement non autorisé, rejet facturé plusieurs fois…) de ce qui relève du *geste commercial*.
- **La lecture des PDF est automatique mais pas infaillible** : chaque banque a sa mise en page. D'où l'aperçu et le contrôle des soldes ; en cas d'écart, préférez l'export CSV. Un relevé **scanné** (image) ne contient pas de texte : il faut le relevé numérique.
- **La catégorisation se trompe parfois** (une enseigne inconnue, un libellé ambigu) : reclassez, et cochez « toujours classer ainsi ».
- **« Utile » ou « inutile » est un choix personnel** : Boussole parle d'« essentiel », de « plaisir » (assumé, à budgéter) et d'« évitable » (frais, agios, amendes…). Changez la nature d'une catégorie dans Réglages.
- Boussole est une aide pour agir, **pas un conseil juridique personnalisé**. En cas de litige important : association de consommateurs, Point conseil budget (gratuit) ou avocat.

## 6. Mettre à jour

1. Sauvegardez le dossier `data/`.
2. Remplacez tous les fichiers **sauf `data/`**.
3. Rechargez la page (Ctrl + F5).

## 7. Valeurs à tenir à jour (`lib/legal.php`)

| Valeur | Montant | Quand la vérifier |
|---|---|---|
| Solde bancaire insaisissable (= RSA personne seule) | 651,69 € depuis le 1er avril 2026 | chaque 1er avril |
| Plafonds des frais d'incidents (8 €/80 €, 20 €, 30/50 €, 25 €/mois) | voir `LEGAL_*` | à chaque nouveau décret |

## 8. Tests

```bash
php tests/run-tests.php
```

Vérifie la lecture des relevés (texte, CSV Windows-1252, OFX, QIF), la catégorisation, les plafonds de frais, les alertes,
les courriers et le chiffrement, sur des relevés **fictifs** (`tests/samples/`).

## 9. Sources (vérifiées le 6 novembre 2026)

- Code monétaire et financier : art. R312-4-1 (commissions d'intervention), D133-6 (rejet de prélèvement : tout compris, représentations), D131-25 (chèque), L312-1-3 et R312-4-3 (clients fragiles), L312-1-5 (information 14 jours avant), L133-18, L133-19, L133-23, L133-24 (opérations non autorisées), L133-25 et L133-25-1 (prélèvement SEPA, 8 semaines), L133-45 (réponse sous 15 jours ouvrables), L316-1 (médiateur), L312-1-1 (tarifs, récapitulatif annuel), L312-1-7 (mobilité bancaire).
- [INC — La tarification bancaire en cas d'incidents de paiement](https://www.inc-conso.fr/content/la-tarification-bancaire-en-cas-dincidents-de-paiement)
- [Banque de France — Les paiements SEPA](https://www.banque-france.fr/stabilite-financiere/comite-national-des-paiements-scripturaux/faq-les-paiements-en-questions/les-paiements-sepa)
- Règlement (UE) n° 260/2012, art. 5 §3 (listes blanche et noire de créanciers) ; règlement (UE) 2024/886 (virement instantané au prix du virement classique depuis le 9 janvier 2025).
- [service-public.gouv.fr — Combien de temps conserver ses papiers](https://www.service-public.gouv.fr/particuliers/vosdroits/F19134)
- [Code du travail — art. D3243-8 (bulletin de paie électronique)](https://code.travail.gouv.fr/code-du-travail/d3243-8)
- Loi n° 89-462 du 6 juillet 1989, art. 22-2, et décret n° 2015-1437 (pièces d'un dossier de location).
