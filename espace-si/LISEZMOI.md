# D8 · Espace SI — outil d'échange du service informatique

Un seul espace pour le service informatique de D8 : messagerie et appels vidéo, projets et diagramme de Gantt, tâches et notes partageables, calendrier, rappels sonores, problèmes récurrents, congés, retards et compteur d'heures.

Comptes prévus : **Mohamed Zidani**, **Tafré Mefré**, **Ludovic Gasp**, **Victor Gomes**. Chacun choisit son mot de passe à sa première connexion.

| Fichier | Rôle |
|---|---|
| `index.html` | L'application complète, en un seul fichier, sans dépendance Internet |
| `api.php` | Le serveur : comptes, droits, synchronisation, fichiers joints, signalisation des appels |
| `web.config` | Réglages IIS (page d'accueil, dossier `data` protégé, pas de cache) |
| `.htaccess` | Les mêmes réglages pour Apache |
| `.user.ini` | Réglages PHP du dossier (FastCGI) |
| `data/` | Créé au premier lancement : toutes les données. **À sauvegarder.** |

---

## 1. Essayer tout de suite (mode démonstration)

Double-cliquez sur `index.html`. Sans serveur PHP, l'application démarre en **mode démonstration** : les données d'exemple restent dans le navigateur. Choisissez un compte, puis un mot de passe.

Pour tester la messagerie ou les appels à deux, ouvrez un **second onglet** et connectez-vous avec un autre compte.

Rien de ce qui est saisi en démonstration n'est partagé, et vider les données du navigateur efface tout.

## 2. Mise en service pour l'équipe

1. **Prérequis** : un serveur web interne avec **PHP 7.4 ou plus**. Cela peut être IIS + PHP, Apache ou nginx, ou un NAS Synology ou QNAP avec Web Station. Aucune base de données n'est nécessaire.
2. Copiez le dossier `espace-si` sur le serveur, par exemple sous `C:\inetpub\wwwroot\espace-si`.
3. Donnez au compte du serveur web le droit d'**écrire** dans ce dossier. Sous IIS, c'est `IIS AppPool\<nom du pool>` ou `IUSR`. `api.php` y crée le sous-dossier `data`.
4. Ouvrez `https://serveur/espace-si/`. En cas d'erreur, le message indique la cause, le plus souvent un droit d'écriture manquant.
5. **Codes de première connexion** : ouvrez sur le serveur le fichier `data\PREMIERE-CONNEXION.txt`. Il contient un code à usage unique par personne. Transmettez-les **de vive voix**.
6. Chacun ouvre la même adresse et saisit son identifiant (`prenom.nom`, sans accent). À la première connexion, il saisit ensuite son code et choisit son mot de passe. Le mot de passe doit faire 10 caractères minimum et mélanger 3 types parmi minuscules, majuscules, chiffres et caractères spéciaux.

**Recommandé** : `$ALLOWED_NETS` (en tête d'`api.php`) limite l'accès aux adresses du réseau interne. Ajustez-le à vos plages IP.

## 3. HTTPS : indispensable pour la vidéo

Sur une adresse en `http://`, les navigateurs **bloquent** la caméra, le micro, le partage d'écran et les notifications Windows. Ils bloquent donc les appels vidéo et les messages vidéo et vocaux enregistrés. Ce n'est pas réglable dans l'application.

Ce qui reste disponible en `http://` :

- la messagerie et les photos ou vidéos envoyées comme fichiers ;
- les alertes sonores et le reste de l'outil.

Les mots de passe circulent alors en clair sur le réseau.

**Solutions, de la meilleure à la plus rapide :**

1. **Certificat interne** : demandez un certificat à votre autorité de certification Active Directory (AD CS). Ajoutez une liaison HTTPS au site dans IIS. Les postes du domaine font déjà confiance à cette autorité.
2. **NAS Synology** : *Panneau de configuration › Sécurité › Certificat*. Utilisez Let's Encrypt si le nom est public, sinon importez un certificat interne.
3. **Dépannage temporaire** : par stratégie de groupe (Edge et Chrome), activez `OverrideSecurityRestrictionsOnInsecureOrigin` en y listant `http://serveur`. Le navigateur traite alors cette adresse comme sécurisée. Les mots de passe restent toutefois en clair.

## 4. Rôles et droits

Rôles par défaut :

- **Tafré Mefré** est administrateur et responsable : il valide les absences et les heures.
- Les trois autres comptes sont membres.
- Pour les modifier : *Paramètres › Équipe & accès*.

| Action | Membre | Responsable | Administrateur |
|---|---|---|---|
| Messagerie, projets, tâches et notes d'équipe, problèmes, base de connaissances | ✅ | ✅ | ✅ |
| Notes, tâches et rappels **privés** | les siens uniquement | les siens uniquement | les siens uniquement |
| Demander une absence, saisir des heures, signaler un retard | pour soi | pour toute l'équipe | pour toute l'équipe |
| Valider ou refuser absences et heures | — | ✅ | ✅ |
| Gérer l'équipe, les codes d'accès, les réglages, l'export | — | — | ✅ |

Ces droits sont **vérifiés par le serveur**, pas seulement masqués dans l'interface. Une note privée n'est jamais envoyée au poste d'un collègue.

**Mot de passe oublié ou départ d'une personne** :

- *Paramètres › Équipe & accès › Réinitialiser* : un nouveau code s'affiche, à transmettre de vive voix.
- Pour un départ, décochez « Compte actif » : l'accès est coupé immédiatement.

**Protection** : après 8 échecs de connexion depuis un même poste, la connexion est bloquée 15 minutes.

## 5. Les menus

| Menu | Pour quoi faire |
|---|---|
| **Accueil** | La journée en un coup d'œil : qui est là, absent, en retard ou d'astreinte ; mes tâches et événements du jour ; projets ; urgences ; échéances ; annonces à lire. |
| **Messagerie** | Canaux (Général, Urgences, Projets, Veille), messages directs et de groupe. Vous pouvez joindre photos, vidéos et fichiers, par glisser-déposer ou en collant une capture avec Ctrl+V. Messages vidéo et vocaux, réactions, réponses, mentions @prénom et @tous, épinglage, « vu par », recherche dans tout l'historique. |
| **Appels** | Appel audio ou vidéo depuis une conversation, ou visio d'équipe (bouton caméra en haut). Partage d'écran, fenêtre réduite pour continuer à travailler. |
| **Annonces** | Informations importantes avec accusé de lecture (« J'ai lu », lu par 3/4). |
| **Projets** | En cours, à venir, en stand-by (avec raison et date de reprise), terminés. Tableau à glisser-déposer ou liste, étapes, jalons, tâches liées, commentaires, documents, canal de discussion dédié. |
| **Diagramme de Gantt** | Étapes et jalons de tous les projets. On déplace ou allonge une barre à la souris. Dépendances signalées en rouge si elles se chevauchent. Zoom semaine, mois, trimestre ou année ; impression. |
| **Tâches** | Personnelles ou partagées : assignation, échéance, priorité, sous-tâches, répétition, pièces jointes, commentaires. Saisie rapide : « Changer le toner @Ludovic ! ». |
| **Notes** | Privées, partagées avec un collègue ou avec toute l'équipe. Couleurs, étiquettes, cases à cocher cliquables, et « cases → tâches » pour planifier à partir d'une note. |
| **Calendrier** | Jour, semaine, mois ou liste. Événements (réunion, intervention, maintenance, astreinte…) avec participants, répétition et alerte sonore. On y voit aussi ses tâches, les absences, échéances, jalons et rappels. Export `.ics` vers Outlook. |
| **Rappels** | Rappels personnels sonores, avec report en un clic (15 min, 1 h, demain 9 h…) et répétition. |
| **Problèmes & incidents** | Signalement avec impact × urgence → priorité P1 à P4, traitement « maintenant » ou « plus tard » (planifié). Bouton **« Ça recommence (+1) »** : les occurrences sont comptées. Au-delà de 3 en 30 jours (réglable), le problème devient **récurrent** et l'équipe est alertée. Les doublons sont détectés à la saisie et les équipements à surveiller sont repérés. Une solution peut devenir une fiche de connaissance. |
| **Base de connaissances** | Procédures et solutions, pour ne plus chercher deux fois. |
| **Échéances & licences** | Certificats SSL, licences, contrats, garanties, noms de domaine : alerte avant expiration et « Renouvelé : +1 an ». |
| **Contacts & prestataires** | Hotlines, numéros de contrat, horaires, procédure d'escalade. |
| **Planning d'équipe** | Vue du mois avec congés (demi-journées), télétravail, retards, astreintes, jours fériés, et nombre de présents avec alerte sous l'effectif minimum. Rotation d'astreinte automatique. |
| **Congés & absences** | Demandes avec calcul des jours ouvrés (fériés français exclus) et validation par un responsable. Avertissement si un collègue est déjà absent ou si l'effectif minimum n'est pas atteint. Soldes et export. |
| **Retards** | « Signaler un retard » prévient toute l'équipe (alerte sonore). Bouton « Je suis arrivé(e) », ajout possible au compteur d'heures. |
| **Compteur d'heures** | Heures tardives, interventions hors horaires et rattrapages (crédit) ; retards, départs anticipés et récupérations (débit). Solde par personne, validation. |
| **Statistiques** | Incidents par mois, catégories, problèmes et équipements les plus récurrents, projets, absences, retards. |
| **Journal d'activité** | Qui a créé, modifié ou supprimé quoi. |
| **Paramètres** | Profil et photo, sons et « ne pas déranger », mot de passe. Pour les administrateurs : équipe et accès, horaires, effectif minimum, seuil de récurrence, catégories, sites, export. |

**Raccourcis** : `Ctrl K` recherche partout et sert aussi à naviguer ; `Entrée` envoie un message ; `↑` modifie son dernier message ; `Ctrl Entrée` enregistre un formulaire.

## 6. Alertes sonores et notifications

- Les sons sont générés par le navigateur : aucun fichier audio n'est nécessaire. Ils se règlent par type : message, mention, rappel, urgence, retard ou absence, appel. Volume et plage « ne pas déranger » sont réglables ; les urgences et les appels sonnent toujours.
- **Il faut un onglet ouvert** : un site web ne peut pas sonner quand le navigateur est fermé. Astuce : dans Edge, *Applications › Installer ce site en tant qu'application*. L'Espace SI s'ouvre alors dans sa propre fenêtre et peut démarrer avec Windows.
- Les **notifications Windows** (en HTTPS) préviennent quand l'onglet est en arrière-plan. Le titre de l'onglet et l'icône affichent aussi le nombre de non-lus.

## 7. Appels vidéo

- Les appels passent directement de poste à poste sur le réseau local. Le serveur ne sert qu'à les mettre en relation, et aucune vidéo n'y transite.
- Prévu pour 2 à 5 participants. Au-delà, préférez Teams ou un outil équivalent.
- Les postes sans caméra ni micro peuvent rejoindre l'appel pour voir et entendre les autres.
- **Postes en VPN ou sur des sous-réseaux filtrés** : si l'appel ne se connecte pas, installez un serveur TURN interne (coturn) et renseignez-le dans `$ICE_SERVERS` en tête d'`api.php`.

## 8. Données et sauvegardes

- Tout est dans `data/` :
  - `store/` contient les données (un fichier JSON par type, un fichier par mois pour les messages) ;
  - `files/` contient les pièces jointes ;
  - `accounts.json` contient les comptes, avec les mots de passe hachés en bcrypt ;
  - `journal/` contient le journal d'activité.
- `api.php` fait une **copie quotidienne** de `store/` dans `data/backups/` et garde 30 jours.
- **Sauvegardez le dossier `data` complet** avec vos sauvegardes habituelles (Iperius, snapshot du NAS…).
- *Paramètres › Données* télécharge une sauvegarde JSON complète, sans les mots de passe ni les fichiers joints.

## 9. Mettre à jour l'application

Remplacez `index.html` (et `api.php` s'il est fourni) **sans toucher au dossier `data`**.

Si vous aviez modifié les réglages en tête de l'ancien `api.php` (`$ALLOWED_NETS`, `$ICE_SERVERS`…), reportez-les dans le nouveau.

Les postes ouverts affichent « Une nouvelle version vient d'être installée » avec un bouton **Recharger**.

## 10. Sécurité et RGPD — à lire avant la mise en service

- **Données personnelles** : absences, retards et heures sont des données relatives aux salariés. Avant la mise en service :
  - informez les personnes concernées ;
  - inscrivez le traitement au registre RGPD de D8 (voyez avec le DPO ou les RH) ;
  - fixez une durée de conservation.
- **CSE** : un outil qui permet de suivre les retards et les heures peut être vu comme un moyen de contrôle de l'activité. Le Code du travail (art. L2312-38) prévoit alors l'information et la consultation du CSE avant sa mise en place. Faites valider le périmètre par les RH.
- **Santé** : pour une absence maladie, n'indiquez que le motif « Maladie », jamais de détail médical.
- **Pas de mots de passe dans l'Espace SI** : ni dans les notes, ni dans la base de connaissances, ni dans les messages. Utilisez le coffre-fort de l'entreprise (KeePass, Bitwarden…).
- **Pièces jointes** : elles ne sont accessibles qu'aux personnes connectées, via un lien impossible à deviner.

## 11. Limites connues

- **Outil interne au service, pas outil RH officiel.** Les compteurs de congés et d'heures servent au suivi d'équipe. Les soldes officiels restent ceux du logiciel de paie ou RH.
- **Hors du réseau, pas d'accès.** Pour signaler un retard depuis son téléphone dans les transports, il faut un VPN ou une publication sécurisée (reverse proxy HTTPS). Sinon, un appel à un collègue qui le saisit suffit.
- **Taille d'équipe** : le stockage en fichiers JSON convient à une équipe de quelques dizaines de personnes au plus.
- **Microsoft 365** : si D8 dispose déjà de Teams, la messagerie et la visio font doublon. L'intérêt de l'Espace SI est alors surtout tout le reste : projets, problèmes récurrents, planning, temps.
