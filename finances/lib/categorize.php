<?php
/**
 * Boussole — catégorisation des opérations bancaires.
 *
 * Trois questions pour chaque ligne de relevé :
 *   1. quel TYPE d'opération ? (carte, prélèvement, virement, retrait, chèque, frais…)
 *   2. quelle CATÉGORIE ? (courses, loyer, frais d'incident…)
 *   3. quelle NATURE ? essentiel / plaisir / évitable / épargne / revenu / transfert / à classer
 *
 * « Utile ou inutile » est un jugement personnel : la nature par défaut de chaque catégorie
 * se change dans Réglages, et chaque opération peut être reclassée (avec « toujours classer ainsi »).
 * Les règles personnelles passent avant tout le reste.
 */
if (!defined('BOUSSOLE')) { http_response_code(403); exit; }

const NATURES = [
    'revenu'    => ['Revenus', 'Salaires, aides, pensions : ce que vous gagnez.'],
    'entree'    => ['Autres entrées', 'Remboursements et virements reçus : de l’argent qui rentre sans être un revenu.'],
    'essentiel' => ['Essentiel', 'Les besoins : logement, énergie, courses, santé, transport, assurances, impôts, crédits.'],
    'plaisir'   => ['Plaisir & confort', 'Choisi et utile au moral : à budgéter plutôt qu’à supprimer.'],
    'evitable'  => ['Évitable', 'De l’argent perdu : frais d’incident, agios, amendes, jeux d’argent.'],
    'epargne'   => ['Épargne', 'Ce que vous mettez de côté.'],
    'transfert' => ['Transferts', 'Mouvements entre vos comptes ou entre vous deux : neutres.'],
    'inconnu'   => ['À classer', 'Espèces, chèques et virements sans destinataire identifié.'],
];

/** id => [libellé, nature par défaut, icône] */
function categories(): array {
    return [
        // revenus et entrées
        'salaire' => ['Salaire & primes', 'revenu', '💼'],
        'allocations' => ['Aides & allocations', 'revenu', '🏛️'],
        'autres_revenus' => ['Autres revenus', 'revenu', '➕'],
        'remboursement' => ['Remboursements', 'entree', '↩️'],
        'virement_recu' => ['Virement reçu', 'entree', '📥'],
        'remise_cheque' => ['Remise de chèque / dépôt', 'entree', '🧾'],
        // transferts et épargne
        'virement_foyer' => ['Virement entre vous deux', 'transfert', '💞'],
        'virement_interne' => ['Entre mes comptes', 'transfert', '🔁'],
        'epargne' => ['Épargne & placements', 'epargne', '🐷'],
        // essentiel
        'loyer' => ['Loyer & charges', 'essentiel', '🏠'],
        'credit_immo' => ['Crédit immobilier', 'essentiel', '🏦'],
        'energie' => ['Électricité & gaz', 'essentiel', '⚡'],
        'eau' => ['Eau', 'essentiel', '💧'],
        'telecom' => ['Téléphone & internet', 'essentiel', '📶'],
        'assurances' => ['Assurances & mutuelle', 'essentiel', '🛡️'],
        'courses' => ['Courses alimentaires', 'essentiel', '🛒'],
        'sante' => ['Santé', 'essentiel', '🩺'],
        'transport' => ['Transports en commun & train', 'essentiel', '🚆'],
        'carburant' => ['Carburant & péage', 'essentiel', '⛽'],
        'vehicule' => ['Véhicule & stationnement', 'essentiel', '🚗'],
        'enfants' => ['Enfants & scolarité', 'essentiel', '🧸'],
        'impots' => ['Impôts & taxes', 'essentiel', '🧾'],
        'credit_conso' => ['Crédit à la consommation', 'essentiel', '💳'],
        'frais_bancaires' => ['Frais bancaires (service)', 'essentiel', '🏦'],
        'administratif' => ['Démarches & courrier', 'essentiel', '📮'],
        'animaux' => ['Animaux', 'essentiel', '🐾'],
        'formation' => ['Formation', 'essentiel', '🎓'],
        // plaisir & confort
        'restaurants' => ['Restaurants & cafés', 'plaisir', '🍽️'],
        'fastfood' => ['Fast-food & snacks', 'plaisir', '🍔'],
        'livraison' => ['Livraison de repas', 'plaisir', '🛵'],
        'shopping' => ['Vêtements & shopping', 'plaisir', '🛍️'],
        'ecommerce' => ['Achats en ligne', 'plaisir', '📦'],
        'high_tech' => ['High-tech', 'plaisir', '💻'],
        'maison' => ['Maison & bricolage', 'plaisir', '🛋️'],
        'loisirs' => ['Loisirs & sorties', 'plaisir', '🎟️'],
        'abonnements' => ['Abonnements & streaming', 'plaisir', '📺'],
        'jeux_video' => ['Jeux vidéo & achats d’applis', 'plaisir', '🎮'],
        'sport' => ['Sport', 'plaisir', '🏋️'],
        'beaute' => ['Beauté & coiffeur', 'plaisir', '💇'],
        'voyages' => ['Voyages & hôtels', 'plaisir', '✈️'],
        'vtc_taxi' => ['Taxi & VTC', 'plaisir', '🚕'],
        'tabac' => ['Tabac & presse', 'plaisir', '🚬'],
        'dons' => ['Dons', 'plaisir', '🤝'],
        'cadeaux' => ['Cadeaux & fleurs', 'plaisir', '🎁'],
        'bnpl' => ['Paiement en 3/4 fois', 'plaisir', '🧩'],
        // évitable
        'frais_incidents' => ['Frais d’incidents bancaires', 'evitable', '⚠️'],
        'agios' => ['Agios (découvert)', 'evitable', '📉'],
        'amendes' => ['Amendes, pénalités, recouvrement', 'evitable', '🚨'],
        'jeux_argent' => ['Jeux d’argent', 'evitable', '🎰'],
        // à classer
        'retraits' => ['Retraits d’espèces', 'inconnu', '💶'],
        'virement_emis' => ['Virement émis', 'inconnu', '📤'],
        'cheque_emis' => ['Chèque émis', 'inconnu', '✍️'],
        'autre' => ['Autre', 'inconnu', '❔'],
    ];
}

/** Catégories comptées par défaut comme « dépenses communes du foyer » (réglable). */
const COMMON_DEFAULT = ['loyer', 'credit_immo', 'energie', 'eau', 'courses', 'maison', 'enfants', 'animaux'];

/* ========================================================================== */
/* Frais bancaires                                                             */
/* ========================================================================== */

/** type => [libellé, famille] ; famille : incident (plafonné), agios, saisie, service, evitable */
function fee_types(): array {
    return [
        'intervention' => ['Commission d’intervention / irrégularité', 'incident'],
        'rejet_prlv' => ['Frais de rejet de prélèvement ou de virement', 'incident'],
        'rejet_cheque' => ['Frais de rejet de chèque', 'incident'],
        'lettre' => ['Lettre d’information / d’incident', 'incident'],
        'incident_divers' => ['Autres frais d’incident', 'incident'],
        'saisie' => ['Frais de saisie (SATD, saisie-attribution)', 'saisie'],
        'agios' => ['Agios / intérêts débiteurs', 'agios'],
        'assurance_moyens' => ['Assurance « moyens de paiement »', 'evitable'],
        'retrait' => ['Frais de retrait d’espèces', 'evitable'],
        'etranger' => ['Frais hors zone euro / change', 'evitable'],
        'virement_frais' => ['Frais de virement', 'evitable'],
        'cotisation_carte' => ['Cotisation carte', 'service'],
        'package' => ['Offre groupée / convention', 'service'],
        'tenue_compte' => ['Frais de tenue de compte', 'service'],
        'chequier' => ['Frais de chéquier', 'service'],
        'releve' => ['Frais de relevé / d’envoi', 'service'],
        'carte_refaite' => ['Frais de carte (réédition, opposition)', 'service'],
        'autre_frais' => ['Autres frais bancaires', 'service'],
    ];
}

/** Rend le type de frais bancaire d'une ligne au DÉBIT (texte normalisé), ou null. */
function fee_detect(string $n): ?string {
    // une opération par carte, un prélèvement, un virement ou un chèque n'est pas un frais de la banque
    if (preg_match('/^(CB|CARTE|PAIEMENT PAR CARTE|PAIEMENT CB|PAIEMENT CARTE|ACHAT CB|PRLV|PRELEVEMENT|PRELEV|VIR|VIREMENT|VIRT|CHQ|CHEQUE|RETRAIT DAB|RET DAB|RETRAIT CB|RETRAIT)\b/', $n)
        && !preg_match('/\b(ASSURANCE MOYENS|MOYENS DE PAIEMENT|SECURICOMPTE|SECURILION)\b/', $n)
        && !preg_match('/^(RETRAIT|RET DAB|RETRAIT DAB|RETRAIT CB)\b.*\b(FRAIS|COMMISSION|COM)\b/', $n)) return null;
    $fee = '(FRAIS|FRS|FR|COMMISSION|COMMISSIONS|COM|COMM|COMMIS|FORFAIT|FORF|COTISATION|COTIS|TARIF|TARIFICATION|PENALITE|PENALITES)';
    $has = function (string $re) use ($n): bool { return (bool)preg_match('/\b(' . $re . ')\b/', $n); };
    $hasFee = (bool)preg_match('/\b' . $fee . '\b/', $n);

    if ($hasFee && $has('CHQ|CHEQUE|CHEQUES') && $has('REJET|REJETE|IMPAYE|IMPAYES|SANS PROVISION|RETOUR|IRREGULIER|INCIDENT|INSUFFISANCE')) return 'rejet_cheque';
    if ($hasFee && $has('REJET|REJETE|REJETS|IMPAYE|IMPAYES|NON PAIEMENT|RETOUR|REFUS|INSUFFISANCE')
        && $has('PRLV|PRELEVEMENT|PRELEVEMENTS|PRELEV|PRL|SDD|ECHEANCE|VIR|VIREMENT|TIP|PAIEMENT|ORDRE|OPERATION')) return 'rejet_prlv';
    if ($has('FRAIS DE REJET|FRAIS REJET|COMMISSION DE REJET|COM REJET|FRS REJET')) return 'rejet_prlv';
    if ($has('INTERVENTION|INTERVENTIONS|INTERV|INTERVENT|IRREGULARITE|IRREGULARITES|IRREG|FORCAGE|OPERATION FORCEE|DEPASSEMENT|DEP DECOUVERT|DEPASS')
        && ($hasFee || $has('INTERVENTION|INTERVENTIONS|IRREGULARITE|IRREGULARITES'))) return 'intervention';
    if ($has('LETTRE|LETTRES|COURRIER|COURRIERS|RECOMMANDE|NOTIFICATION')
        && $has('INFORMATION|INFO|PREALABLE|INJONCTION|AVERTISSEMENT|RELANCE|MISE EN DEMEURE|DEBITEUR|DECOUVERT|INCIDENT|IRREGULARITE|POSITION|COMPTE DEBITEUR')) return 'lettre';
    if ($has('AGIOS|AGIO|INTERETS DEBITEURS|INTERETS DEBITEUR|INTERETS DEBIT|INT DEBITEURS|INT DEB|INT DEBIT|INTERETS DE DECOUVERT|INTERETS SUR DECOUVERT|INTERETS COMPTE DEBITEUR|PLUS FORT DECOUVERT|COMMISSION DE DECOUVERT|MINIMUM FORFAITAIRE|MINIMUM DE PERCEPTION|INTERETS ET FRAIS|INT ET FRAIS|ARRETE DE COMPTE|ARRETE DE COMPTES|ARRETE COMPTE|TAEG')) return 'agios';
    if ($hasFee && $has('SAISIE|SAISIES|SATD|ATD|TIERS DETENTEUR|OPPOSITION ADMINISTRATIVE|SAISIE ATTRIBUTION|SAISIE CONSERVATOIRE|PAIEMENT DIRECT|AVIS A TIERS')) return 'saisie';
    if ($hasFee && $has('INCIDENT|INCIDENTS|IMPAYE|IMPAYES|RECOUVREMENT|INTERDIT|INTERDICTION|FCC|FICP|BANQUE DE FRANCE|DECLARATION BDF|MAINLEVEE|DEBITEUR')) return 'incident_divers';
    if ($has('ASSURANCE MOYENS|MOYENS DE PAIEMENT|MOYENS PAIEMENT|SECURICOMPTE|SECURI COMPTE|SECURILION|SECURITE CARTE|PROTECTION DES MOYENS|PROTECTION MOYENS|ASSURANCE PERTE|PERTE ET VOL|ASSURANCE CARTE|SECURIPLUS|SECUR PLUS|PROTECTION PLUS|ASSURANCE COMPTE|PROTECTION COMPTE|SECURITE PLUS|GARANTIE MOYENS|ASSUR MOYENS|ASSURANCE DES MOYENS')) return 'assurance_moyens';
    if (($hasFee || $has('COMMISSION')) && $has('HORS ZONE|HORS EURO|HORS ZONE EURO|ZONE EURO|DEVISE|DEVISES|ETRANGER|INTERNATIONAL|INTERNATIONALE|CHANGE|NON EURO|HORS SEPA')) return 'etranger';
    if ($hasFee && $has('RETRAIT|RETRAITS|DAB|GAB|DISTRIBUTEUR')) return 'retrait';
    if ($hasFee && $has('VIREMENT|VIREMENTS|VIR|VIRT|VIR INST|INSTANTANE')) return 'virement_frais';
    if ($has('COTISATION|COTIS|ABONNEMENT|ADHESION|FRAIS|FRS') && $has('CARTE|CB|VISA|MASTERCARD|PREMIER|GOLD|INFINITE|PLATINUM|ULTIM|CLASSIC|ELECTRON|MAESTRO|CIRRUS')) return 'cotisation_carte';
    if ($has('CHEQUIER|CHEQUIERS')) return 'chequier';
    if ($has('TENUE DE COMPTE|TENUE COMPTE|FRAIS DE GESTION|GESTION DU COMPTE|GESTION COMPTE|COMPTE INACTIF|FRAIS DE COMPTE|FRAIS MENSUELS|FRAIS DE SERVICE|ABONNEMENT BANCAIRE')) return 'tenue_compte';
    if ($has('COTISATION|COTIS|ABONNEMENT|FORFAIT|OFFRE') && $has('OFFRE|FORMULE|PACKAGE|CONVENTION|COMPTE A COMPOSER|ESPRIT LIBRE|JAZZ|SOBRIO|EUREKA|PRIVILEGE|BOUQUET|EKO|GLOBE|ESSENTIEL|SERVICES|EQUIPEMENT|PACK|LIBERTE|FIDELITE|PERSONNALISABLE|ALTERNATIVE|INITIATIVE|MOUV|BANCAIRE|COMPTE')) return 'package';
    if ($hasFee && $has('RELEVE|RELEVES|EDITION|DUPLICATA|ENVOI|EXPEDITION')) return 'releve';
    if ($hasFee && $has('REFABRICATION|REEDITION|REFECTION|REMPLACEMENT|NOUVELLE CARTE|CODE CONFIDENTIEL|ENVOI CODE|OPPOSITION|URGENCE|EXPRESS')) return 'carte_refaite';
    if (preg_match('/^(FRAIS|FRS|COMMISSION|COMMISSIONS|COM|COTISATION|COTIS|FORFAIT|TARIF)\b/', $n)) return 'autre_frais';
    return null;
}

/** Un CRÉDIT qui rembourse des frais (geste commercial, réclamation gagnée…). */
function fee_refund_detect(string $n): bool {
    return (bool)(preg_match('/\b(REMBOURSEMENT|REMBOURSEMENTS|REMB|RBT|REMBT|ANNULATION|ANNUL|EXTOURNE|RESTITUTION|RETROCESSION|GESTE|RISTOURNE|REGULARISATION|REGUL)\b/', $n)
        && preg_match('/\b(FRAIS|FRS|COMMISSION|COMMISSIONS|COM|AGIOS|INTERETS|COTISATION|COTIS|INTERVENTION|REJET)\b/', $n));
}

/* ========================================================================== */
/* Type d'opération et contrepartie                                            */
/* ========================================================================== */

function cat_kind(string $n): string {
    if (preg_match('/^(CB|CARTE|PAIEMENT PAR CARTE|PAIEMENT CB|PAIEMENT CARTE|ACHAT CB|ACHAT CARTE|FACTURE CARTE|FACT CB|PAIEMENT PSC|PAIEMENT SANS CONTACT|APPLE PAY|GOOGLE PAY|PAIEMENT MOBILE)\b/', $n)
        || preg_match('/\bCARTE X?\d{4}\b|\bCB \d{4}\b|\bX{2,}\d{4}\b|\bCB\s?X\d{4}\b/', $n)) return 'carte';
    if (preg_match('/^(RETRAIT|RET DAB|RETRAIT DAB|RET|DAB|RETRAIT CB|RETRAIT GAB|RETRAIT ESPECES|RETRAIT GUICHET)\b/', $n)) return 'retrait';
    if (preg_match('/^(PRLV|PRELEVEMENT|PRELEVEMENTS|PRELEV|PRL|PRELEVT|ECHEANCE|ECH|SDD|TIP)\b/', $n)) return 'prelevement';
    if (preg_match('/^(VIR|VIREMENT|VIRT|VRT|AVIS DE VIREMENT|LYDIA|PAYLIB|WERO|SUMERIA)\b/', $n)) return 'virement';
    if (preg_match('/^(CHQ|CHEQUE|CHEQUES|REMISE CHQ|REMISE CHEQUE|REMISE CHEQUES|REMISE DE CHEQUE|REMISE DE CHEQUES|REM CHQ|DEPOT CHEQUE)\b/', $n)) return 'cheque';
    if (preg_match('/^(FRAIS|FRS|COMMISSION|COMMISSIONS|COM|COTISATION|COTIS|AGIOS|INTERETS|INT|FORFAIT|TARIF|ARRETE)\b/', $n)) return 'frais';
    if (preg_match('/^(VERSEMENT|DEPOT ESPECES|VERSEMENT ESPECES|DEPOT)\b/', $n)) return 'depot';
    if (preg_match('/\b(PRLV|PRELEVEMENT)\b/', $n)) return 'prelevement';
    if (preg_match('/\b(VIR|VIREMENT)\b/', $n)) return 'virement';
    if (preg_match('/\b(CB|CARTE)\b/', $n)) return 'carte';
    if (preg_match('/\b(RETRAIT|DAB)\b/', $n)) return 'retrait';
    if (preg_match('/\b(CHQ|CHEQUE)\b/', $n)) return 'cheque';
    return 'autre';
}

/** Nom lisible de la contrepartie (marchand, créancier, émetteur) à partir du libellé normalisé. */
function cat_merchant(string $n): string {
    $t = (string)preg_replace('/\b(MOTIF|REF|REFERENCE|ICS|RUM|ID EMETTEUR|IDENTIFIANT CREANCIER|MANDAT|NUM MANDAT|REF MANDAT|BIC|IBAN|LIB|INFO|INFOS)\b.*$/', '', $n);
    $stop = array_flip(['CB', 'CARTE', 'PAIEMENT', 'PAIEMENTS', 'PAR', 'ACHAT', 'ACHATS', 'FACTURE', 'FACT', 'PRLV', 'PRELEVEMENT', 'PRELEVEMENTS', 'PRELEV',
        'PRL', 'PRELEVT', 'SEPA', 'VIR', 'VIREMENT', 'VIRT', 'VRT', 'RECU', 'RECUE', 'EMIS', 'EMISE', 'DE', 'DU', 'DES', 'D', 'VERS', 'A', 'AU', 'AUX', 'POUR',
        'INST', 'INSTANTANE', 'INSTANTANEE', 'TIP', 'RETRAIT', 'RETRAITS', 'DAB', 'GAB', 'EUROPEEN', 'PERMANENT', 'ORDRE', 'EN', 'FAVEUR', 'CHEZ', 'LE', 'LA',
        'LES', 'L', 'X', 'SDD', 'CORE', 'B2B', 'MME', 'M', 'MR', 'MLLE', 'MONSIEUR', 'MADAME', 'MLE', 'MM', 'STE', 'SOCIETE', 'SARL', 'SAS', 'SASU', 'SA',
        'EURL', 'SCI', 'SNC', 'CIE', 'ET', 'AVEC', 'SANS', 'CONTACT', 'NFC', 'INTERNET', 'WEB', 'EUR', 'EUROS', 'PSC', 'APPLE', 'PAY', 'AVIS', 'ECHEANCE',
        'ECH', 'CHQ', 'CHEQUE', 'NO', 'N', 'NUM', 'NUMERO', 'DATE', 'LE', 'WWW', 'HTTPS', 'HTTP', 'FR', 'FRA', 'FRANCE', 'COM', 'ETS', 'ETABLISSEMENTS']);
    $out = [];
    foreach (explode(' ', $t) as $tok) {
        if ($tok === '' || isset($stop[$tok])) continue;
        if (preg_match('/^\d+$/', $tok)) continue;                         // dates, heures, numéros de magasin
        if (preg_match('/^X+\d*$|^\d+X+\d*$/', $tok)) continue;            // X1234, XXXX1234
        if (preg_match('/^[A-Z]{0,3}\d{5,}[A-Z0-9]*$/', $tok)) continue;    // références
        if (preg_match('/^FR\d{2}[A-Z0-9]{3,}$/', $tok)) continue;          // ICS / IBAN
        $out[] = $tok;
        if (count($out) >= 4) break;
    }
    return $out ? implode(' ', $out) : cut($n, 30);
}
/** Clé de regroupement (2 premiers mots de la contrepartie) : sert aux abonnements et aux doublons. */
function merchant_key(string $merchant): string {
    $p = explode(' ', $merchant);
    return implode(' ', array_slice($p, 0, 2));
}

/* ========================================================================== */
/* Dictionnaire des enseignes (ordre = priorité)                               */
/* ========================================================================== */

/** [catégorie, motifs, applicable aux virements ?]. Motifs écrits en texte normalisé (MAJUSCULES sans accents). */
function cat_dictionary(): array {
    static $d = null;
    if ($d !== null) return $d;
    $raw = [
        ['jeux_argent', 'FDJ|FRANCAISE DES JEUX|FRANCAISE DES J|PARIONS ?SPORT|PMU|BETCLIC|WINAMAX|UNIBET|ZEBET|BWIN|POKERSTARS|PARTYPOKER|NETBET|VBET|GENYBET|FRANCE PARI|JOA|CASINO BARRIERE|GROUPE BARRIERE|CASINOS BARRIERE|PARTOUCHE|TRANQUILO|EUROMILLIONS|ILLIKO|ZETURF|LEETURF|BETSSON|STAKE|1XBET|BET365|VAVADA|MELBET|BC GAME|LUCKYBIRD|TURFOO|PMU CITY|LOTO', false],
        ['livraison', 'UBER ?EATS|DELIVEROO|JUST ?EAT|FRICHTI|FOODORA|GLOVO|FLINK|GETIR|GORILLAS|CAJOO|STUART', false],
        ['vtc_taxi', 'UBER|BOLT|HEETCH|G7|TAXI|TAXIS|FREENOW|FREE NOW|MARCEL CAB|LECAB|LE CAB|ALLOCAB|CABIFY|VTC', false],
        ['abonnements', 'NETFLIX|SPOTIFY|DEEZER|DISNEY ?PLUS|DISNEY|CANAL ?PLUS|MYCANAL|AMAZON PRIME|PRIME VIDEO|AMZN PRIME|APPLE COM BILL|APPLE COM|ITUNES|APPLE MUSIC|APPLE TV|ICLOUD|YOUTUBE|GOOGLE ONE|GOOGLE STORAGE|DAZN|BEIN|RMC SPORT|HBO|MAX COM|PARAMOUNT|CRUNCHYROLL|MOLOTOV|AUDIBLE|KINDLE|KOBO|STORYTEL|YOUBOOX|CAFEYN|MEDIAPART|LE MONDE|LE FIGARO|LIBERATION|L EQUIPE|LE PARISIEN|DROPBOX|MICROSOFT 365|OFFICE 365|MSFT|GAME PASS|PLAYSTATION PLUS|PS PLUS|NINTENDO SWITCH ONLINE|ADOBE|CANVA|NORDVPN|EXPRESSVPN|SURFSHARK|PROTON AG|OPENAI|CHATGPT|ANTHROPIC|CLAUDE AI|MIDJOURNEY|TINDER|BUMBLE|MEETIC|HINGE|HAPPN|FRUITZ|LINKEDIN|DUOLINGO|BABBEL|HEADSPACE|PETIT BAMBOU|STRAVA|PATREON|TWITCH|DISCORD|QOBUZ|TIDAL|OCS|TF1 ?PLUS|M6 ?PLUS|MUBI|SNAPCHAT|X CORP', false],
        ['jeux_video', 'STEAM|STEAMPOWERED|VALVE|PLAYSTATION|SONY INTERACTIVE|PSN|XBOX|NINTENDO|EPIC GAMES|RIOT GAMES|BLIZZARD|BATTLE NET|ACTIVISION|ELECTRONIC ARTS|EA GAMES|UBISOFT|ROBLOX|SUPERCELL|KING COM|CANDY CRUSH|MOON ACTIVE|COIN MASTER|GARENA|MIHOYO|HOYOVERSE|GOOGLE PLAY|INSTANT GAMING|G2A|ENEBA|KINGUIN|GAMESTOP|MICROMANIA|ZYNGA|SCOPELY|PLARIUM|NIANTIC|ROCKSTAR GAMES|TAKE TWO|NEXON|LILITH|MOONTON|KRAFTON|PLAYTIKA|PLAYRIX|DREAM GAMES|ROYAL MATCH|VOODOO|FORTNITE', false],
        ['bnpl', 'KLARNA|ALMA|SCALAPAY|ONEY 3X|ONEY 4X|ONEY 3 4X|ONEY 3X 4X|FLOA 3X|FLOA 4X|FLOA PAY|PAYPAL 4X|PAYPAL PAY IN|PAY IN 4|PAIEMENT EN 3|PAIEMENT EN 4|PAIEMENT 3X|PAIEMENT 4X|3X SANS FRAIS|4X SANS FRAIS|COFIDIS 3X|COFIDIS 4X|YOUNITED PAY|AFTERPAY|CLEARPAY|PAYLATER|PAY LATER', true],
        ['credit_immo', 'PRET IMMO|PRET IMMOBILIER|PRET HABITAT|CREDIT IMMOBILIER|CREDIT HABITAT|PTZ|PRET A TAUX ZERO|ECHEANCE PRET|ECH PRET|ECHEANCE DE PRET|REMBOURSEMENT PRET|REMB PRET|PRET ACCESSION|PRET ACTION LOGEMENT|CREDIT LOGEMENT', true],
        ['credit_conso', 'CETELEM|COFIDIS|SOFINCO|FRANFINANCE|CA CONSUMER|CONSUMER FINANCE|ONEY|FLOA|YOUNITED|CARREFOUR BANQUE|CREDIPAR|DIAC|MOBILIZE FINANCIAL|RCI BANQUE|PSA BANQUE|STELLANTIS FINANCE|VOLKSWAGEN BANK|VOLKSWAGEN FINANCIAL|TOYOTA FRANCE FINANCEMENT|BMW FINANCE|MERCEDES BENZ FINANCIAL|SOGEFINANCEMENT|FINAREF|COFINOGA|SOFEMO|FINANCO|CREDIT RENOUVELABLE|RESERVE D ARGENT|COMPTE PERMANENT|CREDIT REVOLVING|PRET PERSO|PRET PERSONNEL|CREDIT CONSO|CREDIT AUTO|PRET AUTO|LOA|LLD|ECHEANCE CREDIT|ECH CREDIT|CREDIT CONSOMMATION', true],
        ['loyer', 'LOYER|LOYERS|QUITTANCE|BAILLEUR|FONCIA|NEXITY|CITYA|SERGIC|ORPI|CENTURY 21|GUY HOQUET|LAFORET|IMMO DE FRANCE|PARIS HABITAT|ICF HABITAT|I3F|IMMOBILIERE 3F|LOGIREP|VILOGIA|EFIDIS|BATIGERE|SEQENS|CDC HABITAT|EMMAUS HABITAT|OPH|OPAC|HLM|ERILIA|LOGEMENT FRANCAIS|ANTIN RESIDENCES|ACTION LOGEMENT|IN LI|SYNDIC|COPROPRIETE|CHARGES COPRO|STUDEFI|FAC HABITAT|STUDEA|RESIDENCE ETUDIANTE|CROUS LOGEMENT|ADOMA|HABITAT SOCIAL|ESH|SAGECO|GESTION LOCATIVE|AGENCE IMMOBILIERE|IMMOBILIER', true],
        ['energie', 'EDF|ENGIE|GRDF|ENEDIS|ENI GAS|ENI PLENITUDE|PLENITUDE|OHM ENERGIE|MINT ENERGIE|EKWATEUR|VATTENFALL|ILEK|PLANETE OUI|ALPIQ|SOWEE|ENERCOOP|ELMY|PRIMEO|IBERDROLA|ENDESA|WEKIWI|MEGA ENERGIE|ALTERNA|BUTAGAZ|PRIMAGAZ|ANTARGAZ|HAPPE|GAZ DE BORDEAUX|ES ENERGIES|ELECTRICITE DE STRASBOURG|GEDIA|SOREGIES|SEOLIS|ELECTRICITE|GAZ NATUREL|FIOUL|CHAUFFAGE|DIRECT ENERGIE|TOTAL DIRECT ENERGIE', true],
        ['eau', 'VEOLIA|SUEZ|EAU DE PARIS|SAUR|SEDIF|EAUX DE|EAUX DU|EAU DU|SERVICE DES EAUX|REGIE DES EAUX|REGIE EAU|SYNDICAT DES EAUX|EAU ET ASSAINISSEMENT|SOGEDO|AQUALTER', true],
        ['sport', 'BASIC ?FIT|FITNESS PARK|NEONESS|KEEP COOL|ON AIR|L ORANGE BLEUE|ORANGE BLEUE|CMG SPORTS|CLUB MED GYM|MAGIC FORM|GIGAFIT|SALLE DE SPORT|ESPRIT FORME|FITNESS|CROSSFIT|YOGA|PILATES|URBAN SOCCER|LE FIVE|CLIMB UP|ARKOSE|BLOCK OUT|HOOPS FACTORY|FEDERATION FRANCAISE|ASSOCIATION SPORTIVE|CLUB SPORTIF|DOJO|BOXE|JUDO|KARATE|TENNIS|PADEL|ESCALADE|FREELETICS|AQUABIKE', false],
        ['telecom', 'FREE MOBILE|FREE TELECOM|FREEBOX|ORANGE|SOSH|SFR|RED BY SFR|BOUYGUES TELECOM|BOUYGUES TEL|BYTEL|B AND YOU|PRIXTEL|NRJ MOBILE|LA POSTE MOBILE|LA POSTE TELECOM|CORIOLIS|SYMA|LEBARA|LYCAMOBILE|AUCHAN TELECOM|CDISCOUNT MOBILE|YOUPRICE|REGLO MOBILE|TELE2|STARLINK|NORDNET|K NET|VIDEOFUTUR|ZEOP|CANALBOX|TRANSATEL|BBOX', true],
        ['assurances', 'ASSURANCE|ASSURANCES|ASSUR|MUTUELLE|HARMONIE|MGEN|ALAN SA|ALAN INSURANCE|MALAKOFF|HUMANIS|AG2R|SWISSLIFE|SWISS LIFE|AXA|MAIF|MACIF|MATMUT|MMA|GMF|ALLIANZ|GROUPAMA|GENERALI|AVIVA|ABEILLE|LEOCARE|LUKO|ACHEEL|DIRECT ASSURANCE|OLIVIER ASSURANCE|PACIFICA|SOGESSUR|CARDIF|PREDICA|SURAVENIR|AMV|MAAF|MACSF|AREAS|THELEM|SMACL|MNT|MNH|CNP|AESIO|UNEO|INTERIALE|PRO BTP|KLESIA|APICIL|AUDIENS|MUTEX|OCIANE|SMATIS|EOVI|ADREA|SPVIE|NETVOX|ZENIOO|GAN|HISCOX|CHUBB|SANTIANE|SOLLY AZAR|LMDE|HEYME|VYV|MUTUALIA|LOVYS|ACM IARD|APRIL MON ASSURANCE|APRIL SANTE', true],
        ['amendes', 'ANTAI|AMENDE|AMENDES|FPS|FORFAIT POST STATIONNEMENT|MAJORATION|CONTRAVENTION|PROCES VERBAL|AMENDE FORFAITAIRE|REGIE DES AMENDES|PENALITE DE RETARD|PENALITES DE RETARD|FRAIS DE RECOUVREMENT|HUISSIER|COMMISSAIRE DE JUSTICE|RECOUVREMENT|INTRUM|IQERA|EOS FRANCE|HOIST|CABOT|KRUK|CONTENTIA', true],
        ['administratif', 'ANTS|TIMBRE FISCAL|TIMBRES FISCAUX|LA POSTE|LAPOSTE|COLISSIMO|CHRONOPOST|MAIRIE|HOTEL DE VILLE|PREFECTURE|SERVICE PUBLIC|IMPRIMERIE NATIONALE|GREFFE|INFOGREFFE|TRIBUNAL|NOTAIRE|NOTAIRES|AVOCAT|AVOCATS|ETAT CIVIL|CONSULAT|AMBASSADE|PASSEPORT|CARTE GRISE|CERTIFICAT D IMMATRICULATION', true],
        ['impots', 'DGFIP|IMPOT|IMPOTS|TRESOR PUBLIC|FINANCES PUBLIQUES|DIRECTION GENERALE DES FINANCES|DRFIP|DDFIP|SIP|SERVICE DES IMPOTS|TAXE FONCIERE|TAXE D HABITATION|TAXE HABITATION|CONTRIBUTION AUDIOVISUELLE|PRELEVEMENT A LA SOURCE|URSSAF|IMPOT SUR LE REVENU|TEOM|CFE', true],
        ['dons', 'CROIX ROUGE|RESTOS DU COEUR|RESTAURANTS DU COEUR|SECOURS POPULAIRE|SECOURS CATHOLIQUE|UNICEF|MEDECINS SANS FRONTIERES|MSF|AMNESTY|ACTION CONTRE LA FAIM|EMMAUS|FONDATION ABBE PIERRE|HANDICAP INTERNATIONAL|HUMANITE INCLUSION|WWF|GREENPEACE|LA SPA|SOCIETE PROTECTRICE DES ANIMAUX|TELETHON|AFM TELETHON|INSTITUT PASTEUR|INSTITUT CURIE|LIGUE CONTRE LE CANCER|PETITS FRERES DES PAUVRES|APPRENTIS D AUTEUIL|SOS VILLAGES|PLAN INTERNATIONAL|SECOURS ISLAMIQUE|ISLAMIC RELIEF|HELLOASSO|SOS MEDITERRANEE|ARMEE DU SALUT|FONDATION DE FRANCE|ORDRE DE MALTE|SAMU SOCIAL|CCFD|BANQUES ALIMENTAIRES|MEDECINS DU MONDE|DON|DONS|FONDATION', true],
        ['formation', 'UDEMY|COURSERA|OPENCLASSROOMS|CNED|MON COMPTE FORMATION|MONCOMPTEFORMATION|CPF|FORMATION|UNIVERSITE|FACULTE|SCOLARITE|FRAIS D INSCRIPTION|CVEC|LINKEDIN LEARNING|SKILLSHARE|MASTERCLASS|DATASCIENTEST|GRETA|AFPA|CNAM|LE WAGON|STUDI|ENI ECOLE|M2I|ORSYS|IFOCOP', true],
        ['vehicule', 'PARKING|INDIGO|SAEMES|ONEPARK|ZENPARK|EFFIA|Q PARK|INTERPARKING|PAYBYPHONE|FLOWBIRD|EASYPARK|STATIONNEMENT|HORODATEUR|NORAUTO|MIDAS|SPEEDY|FEU VERT|POINT S|EUROMASTER|CONTROLE TECHNIQUE|DEKRA|AUTOSUR|SECURITEST|GARAGE|CARROSSERIE|AUTO ECOLE|PERMIS DE CONDUIRE|LAVAGE|ELEPHANT BLEU|ROADY|PIECES AUTO|OSCARO|MISTER AUTO|AUTODOC|CARTER CASH|VULCO|PNEU|PNEUS|AUTO BILAN|VROOMLY|IDGARAGES', false],
        ['enfants', 'CRECHE|MICRO CRECHE|ECOLE|COLLEGE|LYCEE|CANTINE|RESTAURATION SCOLAIRE|PERISCOLAIRE|CENTRE DE LOISIRS|ACCUEIL DE LOISIRS|ASSISTANTE MATERNELLE|ASS MAT|PAJEMPLOI|NOUNOU|BABY SITTING|BABYSITTING|YOOPIES|KANGOUROU KIDS|AUBERT|OKAIDI|OBAIBI|ORCHESTRA|VERTBAUDET|JOUE CLUB|KING JOUET|LA GRANDE RECRE|PICWIC|TOYS R US|SMYTHS|FOURNITURES SCOLAIRES|CAISSE DES ECOLES|SORTIE SCOLAIRE|CLASSE VERTE|COLONIE|CENTRE AERE|SERGENT MAJOR|DU PAREIL AU MEME|DPAM|JACADI|PETIT BATEAU|CYRILLUS|PUERICULTURE|TAPE A L OEIL', true],
        ['animaux', 'VETERINAIRE|VETO|CLINIQUE VETERINAIRE|ANIMALIS|MAXI ZOO|MAXIZOO|TOM AND CO|TOM CO|ZOOPLUS|WANIMO|CROQUETTES|ANIMALERIE|TOILETTAGE|PENSION CANINE', false],
        ['sante', 'PHARMACIE|PHARMA|PHIE|PARAPHARMACIE|MEDECIN|DOCTEUR|CABINET MEDICAL|CENTRE MEDICAL|CENTRE DE SANTE|CENTRE DENTAIRE|DENTISTE|DENTAIRE|ORTHODONTISTE|KINE|KINESITHERAPEUTE|OSTEO|OSTEOPATHE|OPTIC|OPTICIEN|OPTIQUE|KRYS|AFFLELOU|ATOL|GRAND OPTICAL|OPTIC 2000|GENERALE D OPTIQUE|LISSAC|AUDIKA|AMPLIFON|LABORATOIRE|LABO|BIOGROUP|CERBALLIANCE|SYNLAB|EUROFINS|HOPITAL|CLINIQUE|CHU|APHP|AP HP|DOCTOLIB|SOS MEDECINS|ORTHOPHONISTE|PSYCHOLOGUE|PSYCHIATRE|INFIRMIER|INFIRMIERE|SAGE FEMME|RADIOLOGIE|IMAGERIE|PODOLOGUE|DERMATOLOGUE|GYNECOLOGUE|OPHTALMO|PEDIATRE|MAISON MEDICALE|POLYCLINIQUE|AMBULANCE', false],
        ['voyages', 'BOOKING|AIRBNB|EXPEDIA|HOTELS COM|HOTEL|IBIS|NOVOTEL|MERCURE|ACCOR|CAMPANILE|KYRIAD|B B HOTEL|BB HOTEL|PREMIERE CLASSE|HOTELF1|HILTON|MARRIOTT|AIR FRANCE|AIRFRANCE|EASYJET|RYANAIR|TRANSAVIA|VUELING|VOLOTEA|IBERIA|LUFTHANSA|KLM|EMIRATES|TURKISH AIRLINES|ROYAL AIR MAROC|AIR ALGERIE|TUNISAIR|CORSAIR|AIR CARAIBES|FRENCH BEE|WIZZ AIR|NORWEGIAN|OPODO|GO VOYAGES|LASTMINUTE|EDREAMS|KAYAK|SKYSCANNER|TRIVAGO|PIERRE ET VACANCES|CENTER PARCS|CLUB MED|BELAMBRA|ODALYS|VVF|HOMAIR|CAMPING|ABRITEL|GITES DE FRANCE|TUI|FRAM|AEROPORT|AEROPORTS DE PARIS|PARIS AEROPORT|DUTY FREE|HERTZ|AVIS BUDGET|AVIS LOCATION|EUROPCAR|SIXT|RENT A CAR|RENTACAR|ADA LOCATION|GETAROUND|UCAR|ENTERPRISE RENT|BUDGET RENT|BRITTANY FERRIES|CORSICA FERRIES|CORSICA LINEA|DFDS|LA MERIDIONALE|MOBY LINES|AGODA|HOSTELWORLD|LOGIS HOTELS|BEST WESTERN|HOLIDAY INN|RADISSON|HYATT|SOFITEL|PULLMAN|AGENCE DE VOYAGE|SELECTOUR|LECLERC VOYAGES|CARREFOUR VOYAGES|PROMOVACANCES|VACANCES', false],
        ['transport', 'RATP|NAVIGO|IDF MOBILITES|ILE DE FRANCE MOBILITES|COMUTITRES|SNCF|OUIGO|TGV|TER|TRANSILIEN|TCL|RTM|TISSEO|TBM|KEOLIS|TRANSDEV|BLABLACAR|FLIXBUS|BLABLABUS|VELIB|LIME|DOTT|TIER|VOI|CITYSCOOT|TRAINLINE|EUROSTAR|THALYS|TRENITALIA|RENFE|ILEVIA|STAS|FILBLEU|ASTUCE|IDELIS|SEMITAN|NAOLIB|TRANSPORTS EN COMMUN|TRAMWAY|BUS|SOLEA|TWISTO|ZOU|FLUO|ALEOP|MOBIGO|BREIZHGO|PASS NAVIGO|CONNECT TICKET', true],
        ['carburant', 'TOTAL|TOTALENERGIES|TOTAL ENERGIES|ESSO|BP|SHELL|AVIA|AGIP|ENI|STATION|STATION SERVICE|CARBURANT|CARBURANTS|DYNEFF|AS24|VINCI AUTOROUTES|SANEF|APRR|ASF|AREA|ESCOTA|COFIROUTE|ATMB|SAPN|PEAGE|BIP GO|BIP AND GO|ULYS|EMOVIS|AUTOROUTE|AUTOROUTES|STATION U|DAC|IONITY|CHARGEMAP|ELECTRA|FASTNED|TESLA SUPERCHARGER|FREESHOT|IZIVIA|BORNE DE RECHARGE', false],
        ['tabac', 'TABAC|BUREAU DE TABAC|TABAC PRESSE|LE BALTO|PRESSE|MAISON DE LA PRESSE|RELAY|CIGARETTE|CIGARETTES|VAPE|VAPOTEUSE|E LIQUIDE|CIGUSTO|J WELL|LE PETIT VAPOTEUR|CIGARETTE ELECTRONIQUE|CBD', false],
        ['fastfood', 'MCDONALD|MC DONALD|MC DONALDS|MCDONALDS|MCDO|BURGER KING|KFC|QUICK|DOMINO|DOMINOS|PIZZA HUT|O TACOS|OTACOS|FIVE GUYS|STARBUCKS|COLUMBUS CAFE|BRIOCHE DOREE|SUBWAY|POKAWA|PITAYA|BAGELSTEIN|BCHEF|PRET A MANGER|EXKI|COJEAN|SUSHI SHOP|PLANET SUSHI|POPEYES|CHAMAS TACOS|NABAB KEBAB|PATAPAIN|LA CROISSANTERIE|FAST FOOD|SNACK|FRITERIE|TACOS|KEBAB|BURGER|PIZZA|FOOD TRUCK|DUNKIN|KRISPY KREME|TACO BELL|CHIPOTLE|BIG FERNAND|BOX BURGER|PAPA JOHN|SPEED BURGER|LA PATATERIE', false],
        ['restaurants', 'RESTAURANT|RESTAURANTS|RESTO|BRASSERIE|BISTROT|BISTRO|TRATTORIA|PIZZERIA|SUSHI|CREPERIE|TRAITEUR|HIPPOPOTAMUS|BUFFALO GRILL|COURTEPAILLE|DEL ARTE|LEON DE BRUXELLES|FLUNCH|BIG MAMMA|BOUILLON|CAFETERIA|CROUS|SODEXO|ELIOR|COMPASS GROUP|CAFE|CAFES|BAR|PUB|TAVERNE|CANTINA|GRILL|STEAKHOUSE|WOK|RAMEN|TAPAS|LA CRIEE|AU BUREAU|IRISH PUB|LE COMPTOIR|L ENTRECOTE|AUBERGE|BUFFET|GLACIER|SALON DE THE|TEA ROOM', false],
        ['courses', 'CARREFOUR|LECLERC|AUCHAN|INTERMARCHE|ITM|LIDL|ALDI|MONOPRIX|MONOP|FRANPRIX|CASINO|GEANT|SUPER U|HYPER U|U EXPRESS|SYSTEME U|MAGASINS U|CORA|NETTO|LEADER PRICE|PICARD|BIOCOOP|NATURALIA|G20|SPAR|VIVAL|GRAND FRAIS|LA VIE CLAIRE|SIMPLY MARKET|PROXI|COCCINELLE|SUPERMARCHE|HYPERMARCHE|MATCH|NOZ|CHRONODRIVE|COLRUYT|PRIMEUR|BOULANGERIE|BOULANGER PATISSIER|PATISSERIE|BOUCHERIE|FROMAGERIE|EPICERIE|SUPERETTE|ALIMENTATION|THIRIET|TOUPARGEL|BIO C BON|NATURE DE FRANCE|AU MARCHE VRAI|O FRAIS|PROMOCASH|COSTCO|MARIE BLACHERE|PAUL|KAYSER|LA MIE CALINE|BANETTE|MARCHE|HALLES|FRUITS ET LEGUMES|DAILY MONOP|LA GRANDE EPICERIE|TANG FRERES|PARIS STORE|ASIA MARKET|HALAL|DAY BY DAY|LA LOUVE|8 A HUIT|HUIT A 8|SHERPA|MARKET', false],
        ['high_tech', 'FNAC|DARTY|BOULANGER|APPLE STORE|APPLE RETAIL|SAMSUNG|LDLC|MATERIEL NET|RUE DU COMMERCE|BACK ?MARKET|GROSBILL|TOPACHAT|CYBERTEK|SONY|XIAOMI|HUAWEI|GAME CASH|EASY CASH|CASH CONVERTERS|ELECTRO DEPOT|PHONE HOUSE|LOGITECH|DELL|LENOVO', false],
        ['shopping', 'H M|HENNES|ZARA|PRIMARK|KIABI|CELIO|JULES|BRICE|CAMAIEU|PIMKIE|PROMOD|MANGO|BERSHKA|PULL AND BEAR|PULL BEAR|STRADIVARIUS|UNIQLO|GAP FRANCE|GAP STORE|LEVIS|NIKE|ADIDAS|FOOT LOCKER|COURIR|SNIPES|JD SPORTS|INTERSPORT|GO SPORT|SPORT 2000|DECATHLON|GALERIES LAFAYETTE|PRINTEMPS|BHV|LA REDOUTE|ZALANDO|ASOS|SHEIN|TEMU|ALIEXPRESS|WISH|VINTED|SHOWROOMPRIVE|VEEPEE|VENTE PRIVEE|SARENZA|SPARTOO|ERAM|BOCAGE|MINELLI|BATA|CHAUSSEA|GEMO|LA HALLE|TATI|DEVRED|IZAC|ARMAND THIERY|ETAM|UNDIZ|DIM|LACOSTE|RALPH LAUREN|TOMMY HILFIGER|HUGO BOSS|GUESS|CALVIN KLEIN|NORTH FACE|TIMBERLAND|VANS|CONVERSE|NEW BALANCE|PUMA|ASICS|CITADIUM|BONOBO|CACHE CACHE|MORGAN|BREAL|SOLDES|PRET A PORTER|VETEMENTS|CHAUSSURES|BIJOUTERIE|HISTOIRE D OR|MARC ORIAN|CLEOR|PANDORA|SWAROVSKI|MAROQUINERIE|LOUIS VUITTON|GUCCI|PRADA|ZADIG|SANDRO|MAJE|SEZANE|DESIGUAL|OYSHO|MASSIMO DUTTI|SKECHERS|GEOX|TEDDY SMITH|KAPORAL|JENNYFER|NEW YORKER|TALLY WEIJL|ZEEMAN|C AND A', false],
        ['ecommerce', 'AMAZON|AMZN|AMZ|CDISCOUNT|RAKUTEN|EBAY|ETSY|LEBONCOIN|MANOMANO|PRIVALIA|ALIBABA|PAYPAL|MARKETPLACE', false],
        ['maison', 'IKEA|LEROY MERLIN|CASTORAMA|BRICO DEPOT|BRICOMARCHE|MR BRICOLAGE|MONSIEUR BRICOLAGE|WELDOM|BRICORAMA|CONFORAMA|BUT|MAISONS DU MONDE|ALINEA|HABITAT|ACTION|GIFI|LA FOIR FOUILLE|FOIR FOUILLE|CENTRAKOR|STOKOMANI|BABOU|MAXI BAZAR|HEMA|FLYING TIGER|TIGER|ZODIO|CULINARION|JARDILAND|TRUFFAUT|BOTANIC|VILLAVERDE|POINT VERT|GAMM VERT|LINVOSGES|SOSTRENE GRENE|NATURE ET DECOUVERTES|LA CHAISE LONGUE|BRICOLAGE|QUINCAILLERIE|DROGUERIE|ELECTROMENAGER|LITERIE|MATELAS|EMMA MATELAS|TEDIBER|CUISINELLA|SCHMIDT|MOBALPA|LAPEYRE|KILOUTOU|LOXAM|BRICOMAN|SAINT MACLOU', false],
        ['loisirs', 'CINEMA|UGC|PATHE|GAUMONT|MK2|CGR|KINEPOLIS|MEGARAMA|TICKETMASTER|DIGITICK|BILLETREDUC|SEETICKETS|SHOTGUN|WEEZEVENT|DICE|EVENTBRITE|FNAC SPECTACLES|DISNEYLAND|PARC ASTERIX|FUTUROSCOPE|PUY DU FOU|MUSEE|MUSEUM|LOUVRE|BOWLING|LASER GAME|ESCAPE GAME|KARTING|CULTURA|LIBRAIRIE|GIBERT|DECITRE|FURET DU NORD|ZOO|AQUARIUM|THEATRE|CONCERT|SPECTACLE|PISCINE|PATINOIRE|FESTIVAL|PARC D ATTRACTIONS|TRAMPOLINE|PAINTBALL|ACCROBRANCHE|BILLETTERIE|SKI|REMONTEES MECANIQUES|GOLF|EQUITATION|PHILIBERT|SALLE DE JEUX|ARCADE', false],
        ['beaute', 'COIFFEUR|COIFFURE|SALON DE COIFFURE|BARBIER|BARBER|BARBERSHOP|ONGLERIE|INSTITUT DE BEAUTE|ESTHETIQUE|SEPHORA|NOCIBE|MARIONNAUD|YVES ROCHER|L OCCITANE|KIKO|LUSH|RITUALS|BODY SHOP|DOUGLAS|BEAUTY SUCCESS|PASSION BEAUTE|PARASHOP|SPA|MASSAGE|EPILATION|DESSANGE|FRANCK PROVOST|JEAN LOUIS DAVID|TCHIP|SAINT ALGUE|PLANITY|TREATWELL|BEAUTE|COSMETIQUE|ONGLES|NAIL|NAILS|TATOUAGE|TATTOO|PIERCING|GUERLAIN|DIOR', false],
        ['cadeaux', 'CADEAU|CADEAUX|FLEURISTE|INTERFLORA|AQUARELLE|FLEURS|SMARTBOX|WONDERBOX|TICKETBOX|CARTE CADEAU|GIFT', false],
    ];
    $d = [];
    foreach ($raw as $r) $d[] = [$r[0], '/\b(?:' . $r[1] . ')\b/', $r[2]];
    return $d;
}

/** Placements et épargne (virements vers / depuis un livret, une assurance-vie, un courtier…). */
const EPARGNE_RE = '/\b(LIVRET|LIVRET A|LDDS|LDD|LEP|PEL|CEL|PEA|ASSURANCE VIE|EPARGNE|PER|PERP|PERCO|PERECO|YOMONI|NALO|LINXEA|PLACEMENT DIRECT|BOURSE DIRECT|TRADE REPUBLIC|FORTUNEO VIE|LUCYA|SPIRICA|GOODVEST|RAMIFY|DEGIRO|INTERACTIVE BROKERS|BINANCE|COINBASE|KRAKEN|BITSTACK|BITPANDA|LA PREMIERE BRIQUE|ANAXAGO|HOMUNITY|ENERFIP|LENDOSPHERE|SCPI|LIVRET JEUNE|COMPTE EPARGNE|EPARGNE SALARIALE|AMUNDI|NATIXIS INTEREPARGNE|EPSENS|ESALIA|INTER EPARGNE|COMPTE A TERME|PLAN EPARGNE|EPARGNE LOGEMENT|LIVRET BLEU|SUPER LIVRET|LIVRET PLUS|ETORO|TRADING 212|XTB|SAXO|BOURSORAMA BOURSE)\b/';
const ALLOC_RE = '/\b(CAF|CNAF|C A F|ALLOCATION|ALLOCATIONS|ALLOC|APL|ALS|PRIME D ACTIVITE|PRIME ACTIVITE|RSA|FRANCE TRAVAIL|POLE EMPLOI|ASSURANCE CHOMAGE|IJSS|INDEMNITES JOURNALIERES|INDEMNITE JOURNALIERE|MSA|CARSAT|CNAV|AGIRC|ARRCO|RETRAITE|PENSION|AAH|MDPH|BOURSE|CHEQUE ENERGIE|PAJE|CMG|ASPA|PRESTATIONS FAMILIALES|AIDE AU LOGEMENT)\b/';
const SALAIRE_RE = '/\b(SALAIRE|SALAIRES|PAIE|PAYE|REMUNERATION|ACOMPTE|PRIME|PRIMES|GRATIFICATION|INDEMNITE DE STAGE|SOLDE DE TOUT COMPTE|NET A PAYER|TRAITEMENT|INTERESSEMENT|PARTICIPATION|13EME MOIS|TREIZIEME MOIS|HEURES SUP)\b/';
const REMB_RE = '/\b(CPAM|AMELI|ASSURANCE MALADIE|CAISSE PRIMAIRE|MUTUELLE|HARMONIE|MGEN|REMBOURSEMENT|REMBOURSEMENTS|REMBT|RBT|REMB|AVOIR|REFUND|RETOUR|ANNULATION|TROP PERCU|REGULARISATION|RESTITUTION|EXTOURNE|DGFIP|IMPOTS|TRESOR PUBLIC|FINANCES PUBLIQUES|CASHBACK|GESTE COMMERCIAL|INDEMNISATION|SINISTRE|REJET PRLV|REJET PRELEVEMENT|IMPAYE)\b/';
const AUTRES_REV_RE = '/\b(VINTED|LEBONCOIN|EBAY|INTERETS|INTERETS CREDITEURS|DIVIDENDE|DIVIDENDES|COUPON|COUPONS|GAINS|PARRAINAGE|IGRAAL|POULPEO|WIDILO|LOYER PERCU|AIRBNB PAYOUT|AIRBNB PAYMENTS|HONORAIRES|FACTURE CLIENT)\b/';

/** Transforme une liste d'alias saisis (« Sam Martin », « S MARTIN ») en motifs normalisés. */
function alias_list($v): array {
    $out = [];
    foreach ((array)$v as $a) { $n = norm_text((string)$a); if (strlen($n) >= 3) $out[] = $n; }
    return array_values(array_unique($out));
}
function alias_match(string $n, array $aliases): bool {
    foreach ($aliases as $a) if (preg_match('/\b' . preg_quote($a, '/') . '\b/', $n)) return true;
    return false;
}

/**
 * Classe une opération.
 * $ctx : rules (règles perso), me / partner / employer (alias), nature_over (cat => nature), common_cats
 */
function cat_classify(string $label, int $amount, array $ctx = []): array {
    $cats = categories();
    $n = norm_text($label);
    $kind = cat_kind($n);
    $merchant = cat_merchant($n);
    $r = ['norm' => $n, 'merchant' => $merchant, 'kind' => $kind, 'category' => 'autre', 'nature' => null, 'fee' => null, 'flags' => [], 'by' => 'defaut'];
    $credit = $amount > 0;
    $set = function (string $cat, string $by) use (&$r) { $r['category'] = $cat; $r['by'] = $by; };

    // 1. règles personnelles (« toujours classer ainsi »)
    foreach ((array)($ctx['rules'] ?? []) as $rule) {
        $p = (string)($rule['p'] ?? '');
        if ($p === '' || !has($n, $p)) continue;
        if (isset($rule['sens']) && $rule['sens'] !== '' && ($rule['sens'] === 'credit') !== $credit) continue;
        if (isset($cats[$rule['category'] ?? ''])) $set($rule['category'], 'regle');
        if (!empty($rule['nature']) && isset(NATURES[$rule['nature']])) $r['nature'] = $rule['nature'];
        if (isset($rule['common'])) $r['common'] = (int)$rule['common'];
        break;
    }

    if ($r['by'] !== 'regle') {
        if (!$credit) {
            $fee = fee_detect($n);
            if ($fee !== null) {
                $r['fee'] = $fee;
                $fam = fee_types()[$fee][1];
                if ($fam === 'incident' || $fam === 'saisie') $set('frais_incidents', 'frais');
                elseif ($fam === 'agios') $set('agios', 'frais');
                else { $set('frais_bancaires', 'frais'); if ($fam === 'evitable') $r['nature'] = 'evitable'; }
                $r['kind'] = 'frais';
            }
        } elseif (fee_refund_detect($n)) {
            $set('remboursement', 'frais');
            $r['flags'][] = 'remb_frais';
        }
    }

    if ($r['by'] === 'defaut') {
        $isTransfer = in_array($kind, ['virement', 'autre'], true) || preg_match('/\b(LYDIA|PAYLIB|WERO|SUMERIA|PAYPAL)\b/', $n);
        $me = (array)($ctx['me'] ?? []); $partner = (array)($ctx['partner'] ?? []); $employer = (array)($ctx['employer'] ?? []);
        $savings = ($kind !== 'carte' && preg_match(EPARGNE_RE, $n)) || preg_match('/\b(BINANCE|COINBASE|KRAKEN|BITPANDA|BITSTACK|TRADE REPUBLIC|ETORO)\b/', $n);
        if ($isTransfer && $partner && alias_match($n, $partner)) $set('virement_foyer', 'alias');
        elseif ($credit && $employer && alias_match($n, $employer)) $set('salaire', 'alias');
        elseif ($credit && preg_match(ALLOC_RE, $n)) $set('allocations', 'mot');
        elseif ($credit && preg_match(SALAIRE_RE, $n) && $kind !== 'carte') $set('salaire', 'mot');
        elseif (!$credit && $isTransfer && $me && alias_match($n, $me)) $set('virement_interne', 'alias');
        elseif ($savings) $set($credit ? 'virement_interne' : 'epargne', 'mot');
        elseif ($credit && preg_match(REMB_RE, $n)) $set('remboursement', 'mot');
        elseif ($credit && preg_match(AUTRES_REV_RE, $n)) $set('autres_revenus', 'mot');
        elseif ($credit && $isTransfer && $me && alias_match($n, $me)) $set('virement_interne', 'alias');
    }

    if ($r['by'] === 'defaut') {
        // cas particuliers avant le dictionnaire
        if (preg_match('/\b(TOTALENERGIES|TOTAL ENERGIES|TOTAL DIRECT ENERGIE)\b/', $n) && $kind === 'prelevement') $set('energie', 'dico');
        elseif (preg_match('/\b(CARREFOUR|LECLERC|E LECLERC|INTERMARCHE|AUCHAN|SUPER U|HYPER U|SYSTEME U|CASINO|ESSO|TOTAL)\b.*\b(STATION|CARBU|CARBURANT|CARBURANTS|DAC|ESSENCE|GAZOLE)\b/', $n)) $set('carburant', 'dico');
        else {
            foreach (cat_dictionary() as [$cat, $re, $vir]) {
                if ($kind === 'virement' && !$vir) continue;
                if (preg_match($re, $n)) { $set($cat, 'dico'); break; }
            }
        }
        // au crédit, un marchand qui rembourse = remboursement ; une enseigne de revente = revenu
        if ($credit && $r['by'] === 'dico' && !in_array($r['category'], ['salaire', 'allocations', 'autres_revenus', 'remboursement'], true)) {
            $r['category'] = in_array($r['category'], ['shopping', 'ecommerce'], true) && preg_match('/\b(VINTED|LEBONCOIN|EBAY)\b/', $n) ? 'autres_revenus' : 'remboursement';
        }
    }

    if ($r['by'] === 'defaut') {
        if ($credit) {
            if ($kind === 'cheque' || $kind === 'depot') $set('remise_cheque', 'type');
            elseif ($kind === 'virement') $set('virement_recu', 'type');
            else $set('virement_recu', 'type');
        } else {
            if ($kind === 'retrait') $set('retraits', 'type');
            elseif ($kind === 'virement') $set('virement_emis', 'type');
            elseif ($kind === 'cheque') $set('cheque_emis', 'type');
            else $set('autre', 'type');
        }
    }

    // signaux utiles aux alertes
    if (preg_match('/\b(SAISIE ATTRIBUTION|SAISIE CONSERVATOIRE|SATD|SAISIE ADMINISTRATIVE|AVIS A TIERS DETENTEUR|ATD)\b/', $n) && $r['fee'] === null) $r['flags'][] = 'saisie';
    if (preg_match('/\b(RENOUVELABLE|REVOLVING|RESERVE D ARGENT|COMPTE PERMANENT|CARTE AURORE|CARTE PASS)\b/', $n)) $r['flags'][] = 'revolving';
    if (preg_match('/\b(USD|GBP|CHF|CAD|JPY|CNY|AUD|SEK|NOK|DKK|PLN|CZK|HUF|RON|TRY|MAD|TND|DZD|THB|AED|HKD|SGD|INR|BRL|MXN|ZAR|XOF|XAF)\b/', $n) || ($r['fee'] === 'etranger')) $r['flags'][] = 'devise';
    if (preg_match('/\b(INST|INSTANTANE|INSTANTANEE)\b/', $n)) $r['flags'][] = 'instantane';

    $cat = $r['category'];
    if ($r['nature'] === null) $r['nature'] = (string)(($ctx['nature_over'][$cat] ?? null) ?: $cats[$cat][1]);
    if ($credit && in_array($r['nature'], ['essentiel', 'plaisir', 'evitable', 'inconnu'], true)) $r['nature'] = 'entree';
    if (!isset($r['common'])) $r['common'] = (!$credit && in_array($cat, (array)($ctx['common_cats'] ?? COMMON_DEFAULT), true)) ? 1 : 0;
    return $r;
}
