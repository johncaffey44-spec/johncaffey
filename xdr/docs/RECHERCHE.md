# Recherche préalable : XDR, avis clients, menaces, API Freebox

Recherches menées le 25 novembre 2026 pour concevoir Sentinelle XDR. L'objectif : partir de ce que les meilleurs produits font, de ce que leurs clients leur reprochent et de ce qui menace réellement un foyer français, puis vérifier ce que la Freebox permet techniquement.

## 1. Les XDR du marché (entreprises)

- **Notes des clients** (Gartner Peer Insights, catégorie *Extended Detection and Response*) : CrowdStrike Falcon environ 4,7/5, SentinelOne Singularity environ 4,6/5, Microsoft Defender XDR environ 4,5/5. Les sources consultées ne s'accordent pas sur la première place.
  [Gartner – XDR](https://www.gartner.com/reviews/market/extended-detection-and-response) · [Defender XDR vs CrowdStrike](https://www.gartner.com/reviews/market/extended-detection-and-response/compare/product/microsoft-defender-xdr-vs-crowdstrike-falcon) · [Defender XDR vs SentinelOne](https://www.gartner.com/reviews/market/extended-detection-and-response/compare/product/microsoft-defender-xdr-vs-sentinelone-singularity-endpoint)
- Les « Leaders » revendiqués par CrowdStrike et SentinelOne concernent le Magic Quadrant **Endpoint Protection**, et non un quadrant XDR : ce sont des affirmations des éditeurs.
  [CrowdStrike](https://www.crowdstrike.com/en-gb/resources/reports/crowdstrike-named-xdr-leader-fast-mover-2025-gigaom-radar-report/) · [SentinelOne – plateformes XDR](https://www.sentinelone.com/cybersecurity-101/xdr/xdr-platforms/)
- Un classement de presse (Cybersecurity News, 2026) place Palo Alto Cortex XDR devant CrowdStrike, puis SentinelOne, Defender XDR et Trend Vision One. Sa méthodologie n'est pas publiée.
  [Cybersecurity News – Best XDR platforms](https://cybersecuritynews.com/best-xdr-platforms/)

## 2. Ce que les clients reprochent aux XDR

- **Complexité** : courbe d'apprentissage raide et réglage des politiques difficile (Cortex XDR) ; interface difficile pour les petites équipes (Cisco XDR).
  [G2 – Cortex XDR](https://www.g2.com/products/palo-alto-networks-cortex-xdr) · [G2 – Cisco XDR](https://g2.com/products/cisco-xdr-cisco-xdr/reviews)
- **Faux positifs** : un client parle de trois semaines de réglages avant d'obtenir un équilibre acceptable.
  [Avis G2 – Cortex XDR](https://www.g2.com/survey_responses/cortex-xdr-review-13137557)
- **Fatigue d'alertes** : « filtrer le bruit pour se concentrer sur les bons signaux » est la première difficulté citée (38 %) dans une enquête ESG.
  [Dark Reading](https://www.darkreading.com/analytics/soc-teams-burdened-by-alert-fatigue-explore-xdr/d/d-id/1341017) · [Trend Micro](https://www.trendmicro.com/pt_br/research/24/f/upgrade-edr-solution.html)
- **Coût** : licences et stockage des données facturés en plus.
  [G2 – Cortex XDR](https://www.g2.com/products/palo-alto-networks-cortex-xdr) · [Bitdefender – volume d'alertes](https://www.bitdefender.com/en-us/blog/businessinsights/why-alert-volume-matters-cutting-through-the-noise)

## 3. Produits grand public de sécurité du réseau

- **Firewalla** : avis récents mitigés sur le support et la fiabilité (Trustpilot), malgré de bons tests de presse.
  [Trustpilot](https://www.trustpilot.com/review/firewalla.com) · [Comparatif Windows Report](https://windowsreport.com/firewalla-vs-bitdefender-box/)
- **Bitdefender BOX** : reproches surtout liés au prix et à l'abonnement annuel ; le produit n'est plus guère disponible.
  [SmallNetBuilder](https://www.smallnetbuilder.com/lanwan/lanwan-reviews/) · [Best Buy – avis](https://www.bestbuy.com/site/reviews/bitdefender-box-2-smart-home-cybersecurity-hub-black-white/6156902?page=5)
- **Fing / Fingbox** : un outil d'inventaire et de contrôle, pas un pare-feu.
  [Fing – Fingbox et pare-feu](https://help.fing.com/hc/en-us/articles/4418759584402)

## 4. Ce qui menace réellement un foyer en France

Rapport d'activité 2025 de Cybermalveillance.gouv.fr (publié en mars 2026) : l'**hameçonnage** est la première menace pour tous les publics (+70 %), devant le **piratage de comptes** et les **fuites de données personnelles**. Viennent ensuite les arnaques au faux colis, à la fausse commande et au faux conseiller bancaire. 93 % des demandes d'assistance viennent de particuliers, pour environ 504 000 demandes au total (+20 %).
[Communiqué Cybermalveillance 2026](https://www.cybermalveillance.gouv.fr/medias/2026/03/CP-RA2026.pdf) · [Solutions Numériques](https://www.solutions-numeriques.com/?p=246447) · [Maire-info](https://www.maire-info.com/fraude-au-virement-cyberharcelement-piratage-face-%C3%A0-une-menace-cyber-qui-se-diversifie-les-collectivites-ont-besoin-d'assistance-article2-30628)

## 5. Ce que ces constats ont changé dans la conception

| Constat | Choix dans Sentinelle |
|---|---|
| Complexité, jargon | Français clair. Chaque alerte dit le risque, la correction et, si possible, l'applique en un clic. |
| Fatigue d'alertes, faux positifs | Peu de règles, à fort signal. Les alertes de configuration se ferment seules une fois corrigées. Bouton « accepter le risque ». Seuils réglables. Seuil d'envoi massif relatif à l'habitude de chaque appareil. |
| Coût, abonnement | Gratuit, sans abonnement, sur le PC déjà allumé à la maison. |
| Confiance, vie privée | 100 % local, aucun cloud (la CNIL recommande les outils de contrôle parental qui restent en local). |
| Objets connectés vulnérables | Détection des nouveaux appareils, ports ouverts par UPnP, envois massifs, usurpation d'adresse IP. Isolement en un clic. |
| Hameçonnage, n° 1 en France | Avec AdGuard Home : blocage des domaines malveillants et alerte par appareil. |
| Exposition IPv6 de la Freebox | Contrôle du pare-feu IPv6 de la box (alerte critique et correction en un clic) et vérification du pare-feu Windows à l'installation. |
| Rendre compte des actions | Journal d'audit de chaque action et connexion. |

## 6. API Freebox : sources techniques

La documentation officielle (`dev.freebox.fr/sdk/os`) était indisponible pendant le développement (erreur 503). La Freebox Ultra embarque sa propre documentation sur `http://mafreebox.freebox.fr/doc`, qui fait foi.

- **Authentification, réseau, Wi-Fi, ports, UPnP, DHCP, système, statistiques, Player** : bibliothèque de référence `freebox-api` (HACF, utilisée par Home Assistant), qui fournit aussi les autorités de certification Freebox.
  [hacf-fr/freebox-api](https://github.com/hacf-fr/freebox-api) · [documentation](https://freebox-api.readthedocs.io/)
- **Noms exacts des champs** (`ipv6_firewall`, `allow_token_request`, `mac_filter_state`, `network_control` des hôtes…) : définitions de types transcrites de la documentation officielle.
  [@piarre/ts-freebox](https://cdn.jsdelivr.net/npm/@piarre/ts-freebox@1.0.1/out/@types/connection/config.d.ts)
- **Contrôle d'accès par profil (`network_control`)**, non documenté officiellement mais fonctionnel sur Freebox Ultra. Le `PUT` doit renvoyer l'objet complet (liste des adresses MAC comprise), et l'application doit recevoir les droits « Modification des réglages », « Contrôle parental » et « Gestion des profils ».
  [kayasax/freebox-parental-control](https://github.com/kayasax/freebox-parental-control) · [guliangsi/freebox-ultra-profiles](https://github.com/guliangsi/freebox-ultra-profiles/blob/main/README.md) · [The Static Turtle – Freebox parental API](https://blog.thestaticturtle.fr/stop-now-freebox-parental-api/)
- **Pièges connus** : les modifications partielles peuvent être ignorées sans erreur (d'où la relecture après chaque action) ; validation de l'association par la flèche de l'écran de la box.
  [zifken/freebox-control](https://github.com/zifken/freebox-control)
- **Player** (`power_state`, `foreground_app`, chaîne, volume), compatible Delta, Pop et Révolution.
  [lbaey/freebox-player-ha](https://github.com/lbaey/freebox-player-ha) · [Wiki Player API](https://github.com/Aymkdn/assistant-freebox-cloud/wiki/Player-API)
- **Pare-feu IPv6 de la Freebox** : réglable seulement en actif ou inactif.
  [Univers Freebox – tuto pare-feu IPv6](https://www.universfreebox.com/article/50318/Tuto-Freebox-comment-parametrer-le-nouveau-pare-feu-IPV6-et-pourquoi-il-est-important-de-l-activer/amp) · [Next – mise à jour 4.0.6](https://next.ink/brief-article/mise-a-jour-4-0-6-du-freebox-server-firewall-ipv6-et-corrections-de-bugs/)
- **AdGuard Home** : API REST (journal des requêtes, liste d'accès, statistiques).
  [openapi.yaml d'AdGuard Home](https://github.com/AdguardTeam/AdGuardHome/blob/master/openapi/openapi.yaml)

## 7. Cadre légal

- **Secret des correspondances** : l'article 226-15 du Code pénal punit l'interception de correspondances électroniques commise de mauvaise foi (1 an et 45 000 €, peines aggravées entre conjoints selon les sources consultées ; à vérifier sur Légifrance).
  [Cabinet ACI – violation du secret des correspondances](https://www.cabinetaci.com/violation-du-secret-des-correspondances/) · [Selectra – lire les SMS de son conjoint](https://selectra.info/telecom/actualites/marche/lire-sms-conjoint-cachette-amende-60000-euros)
- **Contrôle parental** : la CNIL demande de la transparence envers l'enfant, se méfie des fonctions très intrusives et recommande les outils qui fonctionnent en local.
  [CNIL – Recommandation 5](https://www.cnil.fr/fr/recommandation-5-promouvoir-des-outils-de-controle-parental-respectueux-de-la-vie-privee-et-de) · [CNIL – avis sur les décrets « contrôle parental »](https://www.cnil.fr/fr/la-cnil-rend-son-avis-sur-les-decrets-relatifs-au-controle-parental)
