# Réponses & fiches SAV — `generateur-sav-d8.html`

Outil autonome (un seul fichier HTML, sans installation ni serveur) pour les quatre demandes traitées par mail :

| Onglet | Fiche PDF | Retour à |
|---|---|---|
| SAV · Carte bancaire (lecteur CB en panne) | `FORM-SAV-CB-01` | Monétique |
| SAV · Intervention (panne, fuite, entretien…) | `FORM-SAV-INT-01` | SAV |
| Remboursement (reprend la fiche Canva `FORM-REMB-01`) | `FORM-REMB-02` | Monétique |
| Commande réglée par carte bancaire | `FORM-CMD-CB-01` | Commandes |

Rien n'est envoyé sur Internet : la page fonctionne ouverte par double-clic, ou déposée à côté de `planning-d8.html` sur le serveur interne.

## Utilisation

1. Choisir l'onglet, puis **saisir** les renseignements ou **importer la fiche PDF** renvoyée par le client (bouton en haut, ou glisser-déposer le PDF sur la page).
2. Lire **Contrôle des renseignements** :
   - les manques ;
   - les incohérences (matricule ≠ 6 chiffres, date future, transaction hors délai, espèces + remboursement « sur le compte »…) ;
   - les points à vérifier.
3. **E-mail de réponse** : « Automatique » choisit la réponse selon la saisie. Si un renseignement manque, on demande les compléments. Sinon, la réponse suit la partie « Traitement D8 » :
   - date d'intervention renseignée : intervention programmée ;
   - compte rendu renseigné : intervention réalisée ;
   - décision « accepté » ou « refusé » : remboursement accepté ou refus motivé ;
   - paiement reçu : commande confirmée ; date d'expédition : commande expédiée.

   On peut forcer une autre réponse et retoucher le texte (« Modifier le texte »).
4. Envoyer :
   - **Copier l'e-mail** : texte mis en forme, à coller dans Outlook.
   - **Brouillon Outlook (.eml)** : nouveau message prêt à envoyer, fiches PDF jointes (cases « Pièces jointes »). Par défaut, la fiche pré-remplie est jointe à une demande de compléments, et la fiche de remboursement vierge à un signalement de débit sans produit.
   - **Ouvrir la messagerie** (`mailto:`) : texte brut, sans pièce jointe ; les messages longs peuvent être tronqués.
5. **Fiche PDF** : vierge, à envoyer au client, ou pré-remplie avec la saisie. La fiche pré-remplie peut être verrouillée pour l'archivage. Les champs se remplissent avec Adobe Reader, Edge, Chrome ou l'aperçu macOS. La partie « Traitement D8 » n'y figure jamais.

Le n° de dossier (`REMB-261009-K7QF`…) est généré automatiquement. Vous pouvez le remplacer par un n° de ticket existant.

## Paramètres (bouton ⚙)

Ils sont enregistrés dans le navigateur de chaque poste. Exportez-les en `.json` pour les importer sur les autres postes. Les valeurs par défaut sont dans `DEFAULTS`, en tête du script.

**À vérifier avant la mise en service :**

- **Adresses de retour.** `Monetique@d8.fr` vient de la fiche existante. Pour les interventions et les commandes, l'adresse par défaut est `comd8@d8.fr` (pied de page de la fiche), faute de mieux : indiquez les bonnes.
- **Téléphone.** La fiche Canva indique `01 47 18 38 38` en page 1 et `01 47 18 38 28` en page 2 ; le premier a été retenu.
- **Délais.** Réclamation 30 jours et réponse 5 jours ouvrés, repris de la fiche. Délai d'intervention et de livraison : vides par défaut, donc aucun engagement écrit tant que vous ne les fixez pas.
- **Signature.** Vide : signature d'équipe (« Le service Monétique D8 »). Renseignée : vos nom et fonction.

## Sécurité et données personnelles

- On ne demande et on ne conserve jamais de numéro de carte complet. Un numéro saisi ou importé (13 à 19 chiffres, clé de Luhn valide) est masqué. Dans « 4 derniers chiffres », seuls les 4 derniers sont gardés, et l'e-mail rappelle au client de ne pas transmettre son numéro.
- Le bon de commande ne comporte aucun champ carte : le paiement passe par un lien sécurisé (`https://` obligatoire), par téléphone ou par le terminal à la livraison. PCI DSS interdit d'envoyer un numéro de carte non chiffré par messagerie et de conserver le cryptogramme après le paiement.
- La saisie reste dans l'onglet du navigateur (`sessionStorage`) et disparaît à sa fermeture. Seuls les paramètres sont conservés (`localStorage`).
- La mention de protection des données des fiches nomme le responsable du traitement, les droits et la CNIL, comme l'exige l'article 13 du RGPD. Pour les commandes, la durée de conservation est celle des obligations comptables, et non 12 mois.

## Import de l'ancienne fiche Canva `FORM-REMB-01`

Ses champs s'appellent `Texte1`…`Texte13` : la correspondance est intégrée à l'outil. Deux défauts de cette fiche sont gérés :

- **Les 5 cases « Moyen de paiement » sont déjà cochées dans la fiche vierge.** Si le client ne décoche rien, sa réponse est inconnue. L'outil le signale et tente de déduire le moyen de paiement de la description (marqué « à vérifier »).
- **Il n'y a pas de case pour la nature de l'incident.** Elle est déduite de la description (marquée « à vérifier »).

Les autres défauts de cette fiche, corrigés dans `FORM-REMB-02` :

- coquilles : « excitude », « suffissent », « génére », « Léon Giffroy » en page 2 ;
- les repères « [30] » et « [5] » sont restés entre crochets ;
- la mention RGPD ne cite pas la CNIL.

## Limites connues

- Le brouillon `.eml` s'ouvre comme un nouveau message dans **Outlook classique**. Le nouvel Outlook et Outlook Web peuvent l'afficher comme un message reçu : utilisez alors « Copier l'e-mail ».
- Les déductions faites à l'import (moyen de paiement, nature de l'incident) restent des propositions : elles sont signalées et doivent être vérifiées.
- Bibliothèque PDF incluse : pdf-lib 1.17.1 (licence MIT), en fin de fichier.
