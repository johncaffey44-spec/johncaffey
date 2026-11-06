<?php
/**
 * Boussole — textes de loi, plafonds et modèles de courriers.
 *
 * Plafonds et références VÉRIFIÉS LE 6 NOVEMBRE 2026 (sources : Code monétaire et financier,
 * INC — Institut national de la consommation, Banque de France, service-public.gouv.fr).
 * Les montants qui changent (RSA → solde bancaire insaisissable) sont regroupés ici : mettez-les
 * à jour à chaque revalorisation. Ceci est une aide, pas un conseil juridique personnalisé.
 */
if (!defined('BOUSSOLE')) { http_response_code(403); exit; }

const LEGAL_VERIFIE = '2026-11-06';
const LEGAL_CI_OP = 800;            // commission d'intervention : 8 € par opération (art. R312-4-1 CMF)
const LEGAL_CI_MOIS = 8000;         // … et 80 € par mois
const LEGAL_CI_OP_REDUIT = 400;     // 4 € par opération : offre spécifique « clientèle fragile » ou services bancaires de base
const LEGAL_CI_MOIS_REDUIT = 2000;  // … et 20 € par mois
const LEGAL_REJET_PRLV = 2000;      // rejet de prélèvement / virement : ≤ montant de l'ordre, 20 € au plus, tout compris (art. D133-6 CMF)
const LEGAL_CHEQUE_BAS = 3000;      // rejet de chèque ≤ 50 € : 30 € tout compris (art. D131-25 CMF)
const LEGAL_CHEQUE_HAUT = 5000;     // rejet de chèque > 50 € : 50 € tout compris
const LEGAL_FRAGILE_MOIS = 2500;    // client en situation de fragilité financière : 25 € par mois (art. L312-1-3 et R312-4-3 CMF)
const LEGAL_OCF_MOIS = 2000;        // avec l'offre spécifique : 20 € par mois…
const LEGAL_OCF_AN = 20000;         // … et 200 € par an
const LEGAL_OCF_PRIX = 300;         // prix de l'offre spécifique : 3 € par mois au plus
const LEGAL_FRAGILE_INCIDENTS = 5;  // 5 irrégularités ou incidents dans un même mois → fragilité pendant au moins 3 mois
const LEGAL_SAISIE_MAX = 10000;     // frais de saisie administrative à tiers détenteur : 10 % du dû, 100 € au plus
const LEGAL_SBI = 65169;            // solde bancaire insaisissable = RSA d'une personne seule : 651,69 € depuis le 1er avril 2026
const LEGAL_SBI_DATE = '2026-04-01';

/** Référence courte → [libellé complet, texte] */
function legal_refs(): array {
    return [
        'R312-4-1' => ['Art. R312-4-1 du Code monétaire et financier', 'Commissions d’intervention : 8 € par opération et 80 € par mois au plus (4 € et 20 € pour l’offre spécifique et les services bancaires de base).'],
        'D133-6' => ['Art. D133-6 du Code monétaire et financier', 'Rejet d’un prélèvement ou d’un virement : l’ensemble des sommes facturées pour l’incident, quelle que soit leur dénomination, ne peut dépasser le montant rejeté, dans la limite de 20 €. Si la même opération est rejetée plusieurs fois, les frais au-delà du premier rejet sont remboursables sur demande.'],
        'D131-25' => ['Art. D131-25 du Code monétaire et financier', 'Rejet de chèque : 30 € tout compris pour un chèque de 50 € ou moins, 50 € au-delà. Un chèque représenté dans les 30 jours compte pour un seul incident.'],
        'L312-1-3' => ['Art. L312-1-3 du Code monétaire et financier', 'Plafonnement des frais d’incidents pour les clients en situation de fragilité financière et offre spécifique.'],
        'R312-4-3' => ['Art. R312-4-3 du Code monétaire et financier', 'Critères de fragilité financière (dont 5 irrégularités ou incidents dans un même mois, pour au moins 3 mois) ; plafond de 25 € par mois ; offre spécifique à 3 € par mois au plus.'],
        'L312-1-5' => ['Art. L312-1-5 du Code monétaire et financier', 'Les frais d’irrégularités et d’incidents doivent être annoncés gratuitement au moins 14 jours avant leur débit (liste des frais : art. R312-1-2).'],
        'L312-1-1' => ['Art. L312-1-1 du Code monétaire et financier', 'Convention de compte : toute modification (dont les tarifs) doit être communiquée au moins 2 mois avant ; récapitulatif annuel des frais envoyé en janvier.'],
        'L312-1-7' => ['Art. L312-1-7 du Code monétaire et financier', 'Mobilité bancaire : la nouvelle banque transfère gratuitement vos prélèvements et virements.'],
        'L133-18' => ['Art. L133-18 du Code monétaire et financier', 'Opération non autorisée : remboursement immédiat, au plus tard à la fin du premier jour ouvrable suivant le signalement.'],
        'L133-19' => ['Art. L133-19 du Code monétaire et financier', 'Perte ou vol : au plus 50 € à votre charge avant l’opposition, 0 € après ; 0 € pour un paiement à distance sans utilisation de vos données de sécurité personnalisées.'],
        'L133-23' => ['Art. L133-23 du Code monétaire et financier', 'C’est à la banque de prouver que l’opération a été authentifiée, enregistrée et comptabilisée ; l’utilisation de l’instrument de paiement ne suffit pas à prouver que vous l’avez autorisée.'],
        'L133-24' => ['Art. L133-24 du Code monétaire et financier', 'Opération non autorisée ou mal exécutée : à signaler sans tarder, au plus tard dans les 13 mois suivant le débit.'],
        'L133-25' => ['Art. L133-25 et L133-25-1 du Code monétaire et financier', 'Prélèvement SEPA autorisé : droit inconditionnel au remboursement dans les 8 semaines suivant le débit.'],
        'L133-7' => ['Art. L133-7 du Code monétaire et financier', 'Retrait du consentement : après révocation d’un mandat, toute opération postérieure est réputée non autorisée.'],
        'L133-45' => ['Art. L133-45 du Code monétaire et financier', 'Réclamation sur un service de paiement : réponse sous 15 jours ouvrables, 35 au plus avec une réponse d’attente motivée.'],
        'L316-1' => ['Art. L316-1 du Code monétaire et financier', 'Chaque banque désigne un médiateur, gratuit pour le client.'],
        'L612-2' => ['Art. L612-2 du Code de la consommation', 'Le médiateur est saisi après une réclamation écrite à la banque restée sans réponse satisfaisante.'],
        'UE-260-2012' => ['Règlement (UE) n° 260/2012, art. 5 §3', 'Vous pouvez demander à votre banque de limiter les prélèvements (montant, périodicité), de bloquer tous les prélèvements, ceux d’un créancier précis (liste noire) ou de n’autoriser que certains créanciers (liste blanche).'],
        'UE-2024-886' => ['Règlement (UE) 2024/886', 'Depuis le 9 janvier 2025, un virement instantané en euros ne peut pas être facturé plus cher qu’un virement classique.'],
        'L262-LPF' => ['Art. L262 du Livre des procédures fiscales', 'Saisie administrative à tiers détenteur : frais bancaires plafonnés à 10 % de la somme due au Trésor, 100 € au plus.'],
        'R162-2-CPCE' => ['Art. L162-2 et R162-2 du Code des procédures civiles d’exécution', 'En cas de saisie, la banque laisse à disposition un solde bancaire insaisissable égal au RSA d’une personne seule (651,69 € depuis le 1er avril 2026), une fois par mois.'],
        'C-civ-1302' => ['Art. 1302 et 1302-1 du Code civil', 'Ce qui a été reçu sans être dû doit être restitué (répétition de l’indu).'],
        'C-civ-2224' => ['Art. 2224 du Code civil', 'Délai de prescription de droit commun : 5 ans.'],
        'L215-1' => ['Art. L215-1 du Code de la consommation (loi Chatel)', 'Contrat reconduit tacitement : le professionnel doit vous prévenir 1 à 3 mois avant la date limite de résiliation ; à défaut, vous pouvez résilier à tout moment.'],
        'L215-1-1' => ['Art. L215-1-1 du Code de la consommation', 'Un contrat conclu par internet doit pouvoir être résilié par internet, simplement (« en trois clics »).'],
        'L224-33' => ['Art. L224-33 du Code de la consommation', 'Opérateur télécom : en cas de modification du contrat (dont une hausse de prix), vous pouvez résilier sans pénalité jusqu’à 4 mois après son entrée en vigueur.'],
        'L113-15-2' => ['Art. L113-15-2 du Code des assurances (loi Hamon)', 'Assurances auto, habitation et affinitaires : résiliation à tout moment après la première année.'],
        'loi-89-22-2' => ['Art. 22-2 de la loi n° 89-462 du 6 juillet 1989', 'Un bailleur ne peut pas exiger, entre autres, vos relevés de compte ni une attestation de bonne tenue de compte. Liste des pièces autorisées : décret n° 2015-1437.'],
        'D3243-8' => ['Art. D3243-8 du Code du travail', 'Bulletin de paie électronique : l’employeur garantit sa disponibilité pendant 50 ans ou jusqu’à vos 75 ans.'],
        'CGI-200' => ['Art. 200 du Code général des impôts', 'Dons : réduction d’impôt de 66 % (75 % pour l’aide aux personnes en difficulté, dans une limite annuelle), sur présentation des reçus.'],
    ];
}

/* ========================================================================== */
/* Fiche « Vos droits »                                                        */
/* ========================================================================== */

function legal_kb(): array {
    $inc = ['INC — tarification bancaire en cas d’incidents', 'https://www.inc-conso.fr/content/la-tarification-bancaire-en-cas-dincidents-de-paiement'];
    return [
        ['id' => 'plafonds', 'titre' => 'Les plafonds des frais d’incidents', 'resume' => 'Ce que la banque peut facturer au maximum, quoi qu’en dise sa grille tarifaire.',
            'points' => [
                'Commission d’intervention (irrégularité, opération forcée, dépassement) : 8 € par opération et 80 € par mois au plus. 4 € et 20 € avec l’offre spécifique ou les services bancaires de base.',
                'Rejet de prélèvement ou de virement : pas plus que le montant rejeté, et 20 € au plus. Ce plafond couvre TOUT ce qui est facturé pour l’incident, quel que soit son nom : une commission d’intervention ajoutée par-dessus peut être réclamée.',
                'Même prélèvement présenté et rejeté plusieurs fois par le créancier : seuls les frais du premier rejet sont dus ; les suivants se récupèrent sur simple demande.',
                'Chèque sans provision : 30 € tout compris (lettre, injonction, commission) pour un chèque de 50 € ou moins, 50 € au-delà. Représenté dans les 30 jours, il reste un seul incident.',
                'Client en situation de fragilité financière : 25 € de frais d’incidents par mois au plus ; avec l’offre spécifique, 20 € par mois et 200 € par an.',
                'Frais d’une saisie administrative à tiers détenteur : 10 % de la somme due au Trésor, 100 € au plus.',
                'Agios : le taux du découvert ne peut pas dépasser le taux d’usure, publié chaque trimestre par la Banque de France.',
            ],
            'refs' => ['R312-4-1', 'D133-6', 'D131-25', 'L312-1-3', 'R312-4-3', 'L262-LPF'], 'liens' => [$inc]],
        ['id' => 'info14', 'titre' => 'Être prévenu 14 jours avant', 'resume' => 'Des frais d’incident prélevés sans annonce préalable sont contestables.',
            'points' => [
                'La banque doit annoncer gratuitement, sur le relevé ou par un autre moyen, le montant et la nature des frais d’irrégularités et d’incidents qu’elle va débiter.',
                'Le débit ne peut intervenir qu’au moins 14 jours après cette information.',
                'Réflexe : quand des frais apparaissent, cherchez leur annonce sur le relevé du mois précédent. Absente ? Demandez le remboursement intégral.',
            ], 'refs' => ['L312-1-5'], 'liens' => [$inc]],
        ['id' => 'fragile', 'titre' => 'Clients en situation de fragilité financière', 'resume' => 'Un statut protecteur que la banque doit appliquer, mais qu’il vaut mieux réclamer.',
            'points' => [
                'Critères : au moins 5 irrégularités ou incidents dans un même mois (statut pour 3 mois au moins), inscription au fichier de la Banque de France pendant 3 mois, dossier de surendettement, ou critères propres à la banque (incidents répétés, faibles ressources).',
                'Effets : frais d’incidents plafonnés à 25 € par mois ; la banque doit proposer l’offre spécifique (3 € par mois au plus, avec carte à autorisation systématique et commissions réduites).',
                'L’offre spécifique évite surtout l’effet boule de neige : les rejets coûtent beaucoup moins cher.',
            ], 'refs' => ['L312-1-3', 'R312-4-3', 'R312-4-1'], 'liens' => [$inc]],
        ['id' => 'fraude', 'titre' => 'Une opération que vous n’avez pas faite', 'resume' => 'Paiement frauduleux, carte volée, prélèvement inconnu : la loi est de votre côté.',
            'points' => [
                'Faites opposition immédiatement (appli, conseiller ou serveur interbancaire 0 892 705 705), puis contestez par écrit.',
                'Délai : sans tarder, et au plus tard 13 mois après le débit.',
                'La banque doit rembourser immédiatement, au plus tard à la fin du premier jour ouvrable suivant le signalement, sauf fraude de votre part qu’elle signale à la Banque de France.',
                'C’est à la banque de prouver que vous avez autorisé l’opération. Elle ne peut refuser qu’en démontrant une négligence grave de votre part.',
                'Avant opposition, en cas de perte ou de vol, 50 € au plus restent à votre charge ; 0 € après l’opposition ; 0 € pour un paiement à distance fait avec vos données volées.',
                'Paiement frauduleux par internet : signalez-le aussi sur la plateforme Perceval (service-public.gouv.fr).',
            ], 'refs' => ['L133-18', 'L133-19', 'L133-23', 'L133-24'], 'liens' => []],
        ['id' => 'prlv', 'titre' => 'Prélèvements SEPA', 'resume' => '8 semaines pour se faire rembourser sans justifier, 13 mois si vous ne l’avez jamais autorisé.',
            'points' => [
                'Prélèvement autorisé (vous aviez signé le mandat) : remboursement sans condition si vous le demandez dans les 8 semaines suivant le débit. Le litige avec le créancier reste à régler à part.',
                'Prélèvement non autorisé (aucun mandat, ou mandat révoqué) : 13 mois pour le contester.',
                'Vous pouvez demander à votre banque de bloquer un créancier (liste noire), de n’accepter que certains créanciers (liste blanche), ou de plafonner les montants. La banque ne peut pas refuser.',
                'Révoquer un mandat (écrire au créancier et prévenir la banque) rend tout prélèvement suivant non autorisé.',
            ], 'refs' => ['L133-25', 'L133-24', 'UE-260-2012', 'L133-7'], 'liens' => [['Banque de France — les paiements SEPA', 'https://www.banque-france.fr/stabilite-financiere/comite-national-des-paiements-scripturaux/faq-les-paiements-en-questions/les-paiements-sepa']]],
        ['id' => 'reclamer', 'titre' => 'Réclamer efficacement', 'resume' => 'Écrit, daté, chiffré, avec la référence du texte : la méthode qui marche.',
            'points' => [
                '1. Réclamation écrite à la banque (messagerie sécurisée ou courriel au conseiller, puis service réclamations). Gardez une copie.',
                '2. Pour un service de paiement (carte, prélèvement, virement), la banque doit répondre sous 15 jours ouvrables (35 au plus avec une réponse d’attente). Pour le reste, l’ACPR recommande 2 mois au plus.',
                '3. Sans réponse satisfaisante : saisissez le médiateur de votre banque, gratuit (coordonnées sur votre convention de compte et sur le site de la banque).',
                'Frais prélevés au-delà des plafonds = somme perçue sans être due : vous pouvez en demander la restitution pendant 5 ans.',
                'Frais conformes à la loi : aucune obligation de remboursement, mais un geste commercial est fréquent si vous le demandez poliment, surtout pour un premier incident.',
                'Signalez une pratique abusive d’une entreprise sur SignalConso.',
            ], 'refs' => ['L133-45', 'L316-1', 'L612-2', 'C-civ-1302', 'C-civ-2224'], 'liens' => [['SignalConso', 'https://signal.conso.gouv.fr']]],
        ['id' => 'tarifs', 'titre' => 'Tarifs et changement de banque', 'resume' => 'Vous pouvez refuser une hausse, comparer, et partir sans frais.',
            'points' => [
                'Toute modification de la convention, tarifs compris, doit vous être communiquée au moins 2 mois avant. Sans refus de votre part, elle est réputée acceptée ; si vous refusez, vous pouvez résilier sans frais.',
                'En janvier, la banque doit vous envoyer le récapitulatif des frais de l’année écoulée : comparez-le au total calculé ici.',
                'Changer de banque est gratuit : la nouvelle banque transfère vos prélèvements et virements (mobilité bancaire).',
                'Virement instantané : depuis le 9 janvier 2025, il ne peut pas coûter plus cher qu’un virement classique.',
            ], 'refs' => ['L312-1-1', 'L312-1-7', 'UE-2024-886'], 'liens' => []],
        ['id' => 'saisie', 'titre' => 'Saisie sur le compte', 'resume' => 'Une partie de l’argent reste toujours disponible.',
            'points' => [
                'Lors d’une saisie, la banque doit vous laisser un solde bancaire insaisissable égal au RSA d’une personne seule : ' . money_fmt(LEGAL_SBI) . ' depuis le ' . fr_date_long(LEGAL_SBI_DATE) . ', une fois par mois, sans démarche de votre part.',
                'Les frais bancaires d’une saisie administrative à tiers détenteur sont plafonnés (10 % du dû, 100 € au plus).',
                'Certaines sommes restent insaisissables (une partie des prestations sociales, par exemple) : demandez le détail à la banque.',
            ], 'refs' => ['R162-2-CPCE', 'L262-LPF'], 'liens' => []],
        ['id' => 'contrats', 'titre' => 'Abonnements et contrats', 'resume' => 'Résilier est plus facile qu’on ne le croit.',
            'points' => [
                'Reconduction tacite : le professionnel doit vous prévenir avant la date limite de résiliation ; sinon vous pouvez résilier à tout moment.',
                'Contrat souscrit sur internet : il doit pouvoir être résilié en ligne, simplement (« résiliation en trois clics »).',
                'Box, mobile : une hausse de prix vous permet de résilier sans pénalité jusqu’à 4 mois après son entrée en vigueur.',
                'Assurances auto, habitation et affinitaires (téléphone, moyens de paiement…) : résiliables à tout moment après un an.',
            ], 'refs' => ['L215-1', 'L215-1-1', 'L224-33', 'L113-15-2'], 'liens' => []],
        ['id' => 'location', 'titre' => 'Dossier de location : ce qu’on ne peut pas vous demander', 'resume' => 'Vos relevés bancaires ne regardent pas le bailleur.',
            'points' => [
                'Interdits : relevés de compte, attestation de bonne tenue de compte, autorisation de prélèvement, attestation d’absence de crédit, chèque de réservation, photo (hors pièce d’identité), carte Vitale, dossier médical, extrait de casier judiciaire, contrat de mariage, jugement de divorce (sauf pour la pension).',
                'Autorisés : pièce d’identité, justificatif de domicile, justificatifs d’activité (contrat de travail…), de ressources (3 derniers bulletins de paie, dernier avis d’imposition, prestations sociales).',
                'DossierFacile, le service gratuit de l’État, vérifie et met en forme votre dossier.',
            ], 'refs' => ['loi-89-22-2'], 'liens' => [['DossierFacile', 'https://www.dossierfacile.logement.gouv.fr']]],
        ['id' => 'papiers', 'titre' => 'Combien de temps garder ses papiers', 'resume' => 'Durées minimales indiquées par service-public.gouv.fr.',
            'points' => [
                'Bulletins de paie et contrat de travail : jusqu’à la liquidation de la retraite. Le bulletin électronique reste disponible 50 ans ou jusqu’à vos 75 ans chez l’employeur.',
                'Relevés bancaires, talons de chèques : 5 ans.',
                'Factures d’électricité, de gaz, d’eau, de téléphone et d’internet : 5 ans.',
                'Avis d’imposition : jusqu’au 31 décembre de la 2e année suivant la mise en recouvrement (gardez-les plus longtemps par prudence).',
                'Bail et quittances de loyer : durée de la location + 3 ans.',
                'Contrat d’assurance : 2 ans après sa fin. Décomptes de l’Assurance maladie : 2 ans.',
                'Contrat de prêt : 5 ans après la fin du prêt. Factures d’achat : 5 ans, et jusqu’à la fin de la garantie.',
            ], 'refs' => ['D3243-8'], 'liens' => [['service-public.gouv.fr — conservation des papiers', 'https://www.service-public.gouv.fr/particuliers/vosdroits/F19134'], ['Code du travail — D3243-8', 'https://code.travail.gouv.fr/code-du-travail/d3243-8']]],
        ['id' => 'aide', 'titre' => 'Se faire aider (gratuit)', 'resume' => 'Des services publics et associatifs, confidentiels.',
            'points' => [
                'Points conseil budget : accompagnement budgétaire gratuit et confidentiel (liste sur mesquestionsdargent.fr, site de la Banque de France).',
                'Surendettement : dossier gratuit auprès de la Banque de France.',
                'Aides auxquelles vous avez peut-être droit (prime d’activité, aides au logement…) : simulateur officiel mesdroitssociaux.gouv.fr.',
                'Jeux d’argent : Joueurs Info Service, 09 74 75 13 13 (appel non surtaxé). Interdiction volontaire de jeux possible auprès de l’Autorité nationale des jeux.',
                'Tabac : Tabac Info Service, 39 89.',
                'Dons : 66 % de réduction d’impôt (75 % pour l’aide aux personnes en difficulté, dans une limite annuelle) ; gardez les reçus fiscaux.',
            ], 'refs' => ['CGI-200'], 'liens' => [['Mes questions d’argent', 'https://www.mesquestionsdargent.fr'], ['Mes droits sociaux', 'https://www.mesdroitssociaux.gouv.fr'], ['Joueurs Info Service', 'https://www.joueurs-info-service.fr']]],
    ];
}

/* ========================================================================== */
/* Modèles de courriers (à copier-coller)                                      */
/* ========================================================================== */

function letters_catalog(): array {
    return [
        'frais_plafond' => ['Frais au-delà des plafonds légaux', 'Banque', 'Des frais dépassent un plafond : remboursement exigible.'],
        'represente' => ['Prélèvement rejeté plusieurs fois', 'Banque', 'Le même prélèvement a été rejeté et facturé plusieurs fois.'],
        'frais_geste' => ['Geste commercial sur des frais', 'Banque', 'Frais conformes mais exceptionnels : demande polie.'],
        'fragilite' => ['Statut de client fragile', 'Banque', '5 incidents ou plus dans un mois, ou difficultés durables.'],
        'operation_non_autorisee' => ['Opération non autorisée', 'Banque', 'Paiement ou prélèvement que vous n’avez pas fait (13 mois).'],
        'prlv_8_semaines' => ['Remboursement d’un prélèvement', 'Banque', 'Prélèvement autorisé contesté dans les 8 semaines.'],
        'opposition_creancier' => ['Bloquer un créancier', 'Banque', 'Liste noire ou liste blanche de créanciers.'],
        'mediateur' => ['Saisir le médiateur', 'Médiateur', 'Réclamation restée sans réponse satisfaisante.'],
        'recap_frais' => ['Récapitulatif annuel des frais', 'Banque', 'Pour comparer avec le total calculé par Boussole.'],
        'assurance_moyens' => ['Résilier l’assurance moyens de paiement', 'Banque', 'Assurance qui double la protection légale.'],
        'virement_instantane' => ['Frais de virement instantané', 'Banque', 'Virement instantané facturé plus cher qu’un virement classique.'],
        'sbi' => ['Solde bancaire insaisissable', 'Banque', 'Saisie sur le compte sans que le minimum vital ait été laissé.'],
        'changement_date' => ['Changer la date d’un prélèvement', 'Créancier', 'Faire passer un prélèvement après la paie.'],
        'resiliation' => ['Résilier un abonnement', 'Créancier', 'Abonnement inutile ou dont le prix augmente.'],
    ];
}

/**
 * Rend ['objet', 'corps', 'conseils', 'refs', 'destinataire'] prêts à copier.
 * $ctx : éléments propres au cas (frais concernés, montants…) ; $p : profil (nom, adresse, banque, compte).
 */
function letter_render(string $id, array $ctx, array $p): array {
    $cat = letters_catalog();
    if (!isset($cat[$id])) $id = 'frais_geste';
    $nom = trim((string)($p['nom_complet'] ?? '')) ?: '[Prénom NOM]';
    $adr = trim((string)($p['adresse'] ?? '')) ?: '[Adresse]';
    $banque = trim((string)($p['banque'] ?? '')) ?: '[votre banque]';
    $compte = trim((string)($p['compte'] ?? '')) ?: '[n° de compte]';
    $ville = trim((string)($p['ville'] ?? ''));
    $today = fr_date_long(date('Y-m-d'));
    $items = (array)($ctx['items'] ?? []);
    $liste = '';
    foreach ($items as $it) $liste .= "  • " . fr_date((string)($it['date'] ?? '')) . ' — ' . (string)($it['label'] ?? '') . ' — ' . money_fmt(abs((int)($it['montant'] ?? 0))) . "\n";
    $total = (int)($ctx['total'] ?? 0);
    $montant = (int)($ctx['montant'] ?? 0);
    $mois = isset($ctx['mois']) && $ctx['mois'] ? fr_month((string)$ctx['mois']) : '';
    $hello = "Madame, Monsieur,\n\n";
    $bye = "\nJe vous prie d’agréer, Madame, Monsieur, l’expression de mes salutations distinguées.\n\n" . $nom . "\n" . $adr . "\n" . ($ville !== '' ? "\nFait à " . $ville . ', le ' . $today . "\n" : '');
    $refs = []; $tips = []; $obj = ''; $body = '';
    $mediation = "\nSans réponse satisfaisante de votre part, je saisirai le médiateur de votre établissement (article L316-1 du Code monétaire et financier).\n";
    $info14 = "\nJe vous remercie également de m’indiquer à quelle date ces frais m’ont été annoncés, comme l’exige l’article L312-1-5 du Code monétaire et financier (information préalable gratuite au moins quatorze jours avant leur débit). À défaut de cette information, je vous demande de me les rembourser en totalité.\n";

    switch ($id) {
        case 'frais_plafond':
            $obj = 'Réclamation — frais bancaires dépassant les plafonds légaux — compte ' . $compte;
            $motifs = '';
            foreach ((array)($ctx['motifs'] ?? []) as $m) $motifs .= "\n" . trim((string)$m) . "\n";
            $body = $hello . 'En consultant mes relevés' . ($mois !== '' ? ' (' . $mois . ')' : '') . ", je constate le prélèvement des frais suivants sur mon compte " . $compte . " :\n\n" . $liste
                . ($motifs !== '' ? $motifs : "\nCes frais dépassent les plafonds fixés par le Code monétaire et financier.\n")
                . "\nLes sommes prélevées au-delà de ces plafonds, soit " . money_fmt($montant) . ", ont été perçues sans être dues. Je vous demande de me les restituer (articles 1302 et 1302-1 du Code civil).\n"
                . $info14 . $mediation . $bye;
            $refs = array_values(array_unique(array_merge((array)($ctx['refs'] ?? []), ['C-civ-1302', 'L312-1-5', 'L316-1'])));
            $tips = ['Envoyez-le par la messagerie sécurisée de votre banque : la date d’envoi fait foi.', 'Joignez le relevé où figurent les frais.', 'Sans réponse sous 2 mois, utilisez le courrier « Saisir le médiateur ».'];
            break;
        case 'represente':
            $obj = 'Réclamation — frais de rejet facturés plusieurs fois pour une même opération — compte ' . $compte;
            $body = $hello . "Un même prélèvement" . (!empty($ctx['signature']) ? ' (' . $ctx['signature'] . ')' : '') . " a été présenté plusieurs fois par le créancier et rejeté à chaque présentation. Vous m’avez facturé des frais à chaque rejet :\n\n" . $liste
                . "\nOr l’article D133-6 du Code monétaire et financier prévoit que, lorsque plusieurs demandes de paiement relatives à une même opération sont rejetées, le payeur peut obtenir le remboursement des frais d’incident facturés au-delà de ceux du premier rejet. Il s’agit bien de la même opération (même créancier, même montant, présentations successives).\n"
                . "\nJe vous demande donc de me rembourser " . money_fmt($montant) . ".\n" . $mediation . $bye;
            $refs = ['D133-6', 'L316-1'];
            $tips = ['La preuve se fait « par tout moyen » : un relevé montrant les présentations successives suffit généralement.'];
            break;
        case 'frais_geste':
            $obj = 'Demande de remboursement de frais à titre commercial — compte ' . $compte;
            $body = $hello . 'Mon compte ' . $compte . ' a supporté' . ($mois !== '' ? ' en ' . $mois : '') . " les frais suivants, pour un montant total de " . money_fmt($total) . " :\n\n" . $liste
                . "\nCes frais sont liés à une situation ponctuelle [précisez : retard de versement du salaire, dépense imprévue…], que j’ai régularisée depuis. Client(e) de votre établissement depuis [nombre] ans, je domicilie mes revenus sur ce compte et je m’efforce d’en assurer le bon fonctionnement.\n"
                . "\nJe vous serais reconnaissant(e) de bien vouloir m’accorder, à titre commercial, le remboursement de tout ou partie de ces frais.\n"
                . $info14
                . "\nPar ailleurs, afin d’éviter qu’une telle situation ne se reproduise, je souhaiterais connaître les solutions que vous pouvez me proposer (alerte de solde, autorisation de découvert adaptée, report de date de prélèvement).\n" . $bye;
            $refs = ['L312-1-5'];
            $tips = ['Appelez d’abord votre conseiller, puis confirmez par écrit : les gestes s’obtiennent plus facilement de vive voix.', 'Restez factuel et courtois : vous demandez une faveur, pas un droit.', 'Si d’autres banques vous proposent mieux, mentionner la mobilité bancaire (gratuite) est un argument légitime.'];
            break;
        case 'fragilite':
            $obj = 'Demande d’application du statut de client en situation de fragilité financière — compte ' . $compte;
            $n = (int)($ctx['n_incidents'] ?? 0);
            $body = $hello . 'Mon compte ' . $compte . ' a enregistré ' . ($n > 0 ? $n . ' irrégularités ou incidents' : 'plusieurs irrégularités ou incidents') . ($mois !== '' ? ' au cours du mois de ' . $mois : '') . ", qui ont donné lieu aux frais suivants :\n\n" . $liste
                . "\nEn application des articles L312-1-3 et R312-4-3 du Code monétaire et financier, ces incidents me placent en situation de fragilité financière. Je vous demande en conséquence :\n"
                . "  1. d’appliquer le plafonnement des frais d’irrégularités et d’incidents à 25 € par mois, pendant toute la durée de cette situation ;\n"
                . "  2. de me rembourser les frais perçus au-delà de ce plafond" . ($montant > 0 ? ', soit ' . money_fmt($montant) : '') . " ;\n"
                . "  3. de me présenter l’offre spécifique destinée à la clientèle en situation de fragilité financière, dont le prix ne peut excéder 3 € par mois.\n"
                . $mediation . $bye;
            $refs = ['L312-1-3', 'R312-4-3', 'R312-4-1', 'L316-1'];
            $tips = ['Le statut dure au moins 3 mois : redemandez-le si les incidents continuent.', 'Avec l’offre spécifique, les commissions d’intervention passent à 4 € (20 € par mois au plus).', 'Un Point conseil budget peut vous accompagner gratuitement (mesquestionsdargent.fr).'];
            break;
        case 'operation_non_autorisee':
            $d = fr_date((string)($ctx['date'] ?? '')); $lab = (string)($ctx['label'] ?? '[libellé]');
            $obj = 'Contestation d’une opération non autorisée — compte ' . $compte;
            $body = $hello . 'Je conteste l’opération suivante, débitée sur mon compte ' . $compte . " :\n\n  • " . ($d ?: '[date]') . ' — ' . $lab . ' — ' . money_fmt($montant) . "\n"
                . "\nJe n’ai jamais autorisé cette opération" . (($ctx['type'] ?? '') === 'prelevement' ? ' : je n’ai signé aucun mandat de prélèvement en faveur de ce créancier, ou ce mandat a été révoqué' : '') . ". [Le cas échéant : j’ai fait opposition le … à …]\n"
                . "\nConformément à l’article L133-18 du Code monétaire et financier, je vous demande de me rembourser immédiatement cette somme, et au plus tard à la fin du premier jour ouvrable suivant la réception de la présente, en rétablissant mon compte dans l’état où il se serait trouvé si l’opération n’avait pas eu lieu (frais et agios éventuels compris).\n"
                . "\nJe vous rappelle qu’il vous appartient de prouver que l’opération a été authentifiée, dûment enregistrée et comptabilisée, et que l’utilisation de l’instrument de paiement ne suffit pas à prouver que je l’ai autorisée (article L133-23). Ma contestation intervient dans le délai de treize mois prévu à l’article L133-24.\n"
                . "\nJe vous remercie de me répondre par écrit dans le délai de quinze jours ouvrables prévu à l’article L133-45.\n" . $bye;
            $refs = ['L133-18', 'L133-19', 'L133-23', 'L133-24', 'L133-45'];
            $tips = ['Faites opposition AVANT d’envoyer ce courrier (appli ou 0 892 705 705) si une carte est en cause.', 'Paiement frauduleux sur internet : signalez-le aussi sur Perceval (service-public.gouv.fr).', 'Ne donnez jamais vos codes à quelqu’un qui se présente comme votre banque au téléphone : c’est la fraude la plus courante.'];
            break;
        case 'prlv_8_semaines':
            $d = fr_date((string)($ctx['date'] ?? '')); $lab = (string)($ctx['label'] ?? '[créancier]');
            $obj = 'Demande de remboursement d’un prélèvement SEPA (délai de 8 semaines) — compte ' . $compte;
            $body = $hello . "Je vous demande le remboursement du prélèvement SEPA suivant :\n\n  • " . ($d ?: '[date]') . ' — ' . $lab . ' — ' . money_fmt($montant) . "\n"
                . "\nCette demande intervient dans le délai de huit semaines suivant le débit. Conformément aux articles L133-25 et L133-25-1 du Code monétaire et financier, ce remboursement est de droit pour un prélèvement SEPA, sans que j’aie à le justifier.\n"
                . "\nJe vous remercie de procéder au remboursement dans les meilleurs délais.\n" . $bye;
            $refs = ['L133-25'];
            $tips = ['Ce remboursement ne règle pas une éventuelle dette envers le créancier : réglez le litige avec lui à part.', 'Au-delà de 8 semaines, seul un prélèvement non autorisé peut être contesté (13 mois).'];
            break;
        case 'opposition_creancier':
            $cr = (string)($ctx['creancier'] ?? '[nom du créancier]');
            $obj = 'Blocage des prélèvements d’un créancier — compte ' . $compte;
            $body = $hello . 'En application de l’article 5, paragraphe 3, du règlement (UE) n° 260/2012, je vous demande de bloquer, à compter de ce jour, tout prélèvement SEPA présenté sur mon compte ' . $compte . ' par le créancier suivant : ' . $cr . (!empty($ctx['ics']) ? ' (identifiant créancier SEPA : ' . $ctx['ics'] . ')' : '') . ".\n"
                . "\n[Variante « liste blanche » : je vous demande de n’autoriser sur mon compte que les prélèvements des créanciers suivants : …]\n"
                . "\nJ’ai par ailleurs informé ce créancier de la révocation de mon mandat : tout prélèvement postérieur serait une opération non autorisée (article L133-7 du Code monétaire et financier).\n"
                . "\nJe vous remercie de me confirmer la mise en place de ce blocage.\n" . $bye;
            $refs = ['UE-260-2012', 'L133-7'];
            $tips = ['Écrivez aussi au créancier pour révoquer le mandat : le blocage bancaire et la révocation se complètent.', 'La liste blanche est la protection la plus forte contre les prélèvements frauduleux.'];
            break;
        case 'mediateur':
            $obj = 'Saisine du médiateur — litige avec ' . $banque;
            $body = $hello . 'Je suis client(e) de ' . $banque . ' (compte ' . $compte . "). Je sollicite votre intervention dans le litige suivant :\n\n"
                . '[Résumé en 3 lignes : les faits, les dates, le montant en jeu' . ($montant > 0 ? ' — ici ' . money_fmt($montant) : '') . "]\n"
                . "\nJ’ai adressé une réclamation écrite à ma banque le [date]. [Je n’ai reçu aucune réponse / La réponse du (date) ne me satisfait pas, pour les raisons suivantes : …]\n"
                . "\nJe demande [le remboursement de … € / l’application du plafond légal / …], sur le fondement de [références des textes].\n"
                . "\nVous trouverez en pièces jointes : ma réclamation, la réponse de la banque (le cas échéant) et les relevés concernés.\n" . $bye;
            $refs = ['L316-1', 'L612-2'];
            $tips = ['La médiation est gratuite. Ses coordonnées figurent sur votre convention de compte et sur le site de la banque.', 'Vous devez d’abord avoir réclamé par écrit à la banque, et saisir le médiateur dans l’année qui suit.'];
            break;
        case 'recap_frais':
            $an = (string)($ctx['annee'] ?? ((int)date('Y') - 1));
            $obj = 'Demande du récapitulatif annuel des frais ' . $an . ' — compte ' . $compte;
            $body = $hello . 'Je n’ai pas reçu (ou ne retrouve pas) le récapitulatif des frais perçus en ' . $an . " sur mon compte " . $compte . ", que vous devez adresser chaque année en janvier (article L312-1-1 du Code monétaire et financier).\n\nJe vous remercie de me le transmettre.\n" . $bye;
            $refs = ['L312-1-1'];
            $tips = ['Comparez-le au total de l’onglet « Frais & droits » : un écart peut révéler des frais oubliés.'];
            break;
        case 'assurance_moyens':
            $obj = 'Résiliation de l’assurance « moyens de paiement » — compte ' . $compte;
            $body = $hello . "Je vous demande de résilier l’assurance des moyens de paiement associée à mon compte " . $compte . ($montant > 0 ? ' (' . money_fmt($montant) . ' par an)' : '') . ".\n"
                . "\nJe souhaite résilier ce contrat conformément à ses conditions et, s’il a plus d’un an, à l’article L113-15-2 du Code des assurances (résiliation à tout moment). En effet, la loi limite déjà ma responsabilité en cas de perte ou de vol de ma carte (article L133-19 du Code monétaire et financier).\n"
                . "\nJe vous remercie de me confirmer la date d’effet et l’arrêt des prélèvements correspondants.\n" . $bye;
            $refs = ['L113-15-2', 'L133-19'];
            $tips = ['Vérifiez avant ce que le contrat couvre en plus (vol d’espèces au distributeur, papiers, clés) : si cela vous sert, gardez-le.'];
            break;
        case 'virement_instantane':
            $obj = 'Frais de virement instantané — compte ' . $compte;
            $body = $hello . "Les frais suivants m’ont été facturés pour des virements instantanés :\n\n" . $liste
                . "\nOr, depuis le 9 janvier 2025, le règlement (UE) 2024/886 interdit de facturer un virement instantané en euros plus cher qu’un virement classique de même type. Mes virements classiques étant [gratuits / facturés … €], je vous demande de me rembourser la différence, soit " . money_fmt($montant) . ".\n" . $mediation . $bye;
            $refs = ['UE-2024-886', 'L316-1'];
            $tips = ['La règle compare au virement classique du même canal (en ligne, en agence).'];
            break;
        case 'sbi':
            $obj = 'Saisie sur mon compte — mise à disposition du solde bancaire insaisissable — compte ' . $compte;
            $body = $hello . "Mon compte " . $compte . " a fait l’objet d’une saisie" . (!empty($ctx['date']) ? ' le ' . fr_date((string)$ctx['date']) : '') . ".\n"
                . "\nConformément aux articles L162-2 et R162-2 du Code des procédures civiles d’exécution, vous devez laisser à ma disposition une somme égale au montant forfaitaire du RSA pour une personne seule, soit " . money_fmt(LEGAL_SBI) . " depuis le " . fr_date_long(LEGAL_SBI_DATE) . ". Je vous demande de mettre ce solde bancaire insaisissable à ma disposition sans délai.\n"
                . "\nJe vous remercie également de m’indiquer le détail des frais prélevés au titre de cette saisie" . ($montant > 0 ? ' (' . money_fmt($montant) . ')' : '') . ".\n" . $bye;
            $refs = ['R162-2-CPCE', 'L262-LPF'];
            $tips = ['Le solde insaisissable est laissé une fois par mois, même en cas de plusieurs saisies.', 'Contactez sans attendre le créancier ou un Point conseil budget pour un échéancier.'];
            break;
        case 'changement_date':
            $cr = (string)($ctx['creancier'] ?? '[organisme]');
            $obj = 'Demande de changement de la date de prélèvement — contrat [référence]';
            $body = $hello . 'Titulaire du contrat [référence] chez ' . $cr . ", je souhaite que mes échéances soient désormais prélevées le " . (int)($ctx['jour'] ?? 5) . " de chaque mois, mes revenus étant versés en fin de mois.\n"
                . "\nCe changement me permettra d’honorer chaque échéance dans de bonnes conditions. Je vous remercie de me confirmer la nouvelle date de prélèvement.\n" . $bye;
            $tips = ['Énergie, box, assurances et mutuelles proposent presque toutes plusieurs dates : souvent faisable en ligne en deux minutes.', 'Choisissez 2 à 5 jours après la date habituelle de la paie.'];
            break;
        case 'resiliation':
            $sv = (string)($ctx['creancier'] ?? '[service]');
            $obj = 'Résiliation de mon abonnement — ' . $sv;
            $extra = '';
            if (!empty($ctx['hausse'])) $extra = "\nVotre tarif est passé de " . money_fmt((int)$ctx['hausse']['avant']) . ' à ' . money_fmt((int)$ctx['hausse']['apres']) . ". [Opérateur télécom : conformément à l’article L224-33 du Code de la consommation, cette modification me permet de résilier sans pénalité.]\n";
            $body = $hello . 'Je vous informe de ma décision de résilier mon abonnement ' . $sv . " (référence client : [référence]).\n" . $extra
                . "\nJe vous remercie de me confirmer la date de fin de contrat et l’arrêt de tout prélèvement à compter de celle-ci. À défaut, tout prélèvement postérieur serait contesté comme non autorisé.\n" . $bye;
            $refs = ['L215-1', 'L215-1-1', 'L224-33'];
            $tips = ['Abonnement souscrit en ligne : utilisez le bouton de résiliation du site (il doit être accessible simplement).', 'Gardez la confirmation : elle vous servira si un prélèvement continue.', 'Marquez ensuite l’abonnement « résilié » dans Boussole : il vous alertera s’il est encore prélevé.'];
            break;
    }
    $R = legal_refs();
    $refsOut = [];
    foreach ($refs as $r) if (isset($R[$r])) $refsOut[] = ['code' => $r, 'titre' => $R[$r][0], 'texte' => $R[$r][1]];
    return ['id' => $id, 'titre' => $cat[$id][0], 'destinataire' => $cat[$id][1], 'objet' => $obj, 'corps' => $body, 'conseils' => $tips, 'refs' => $refsOut, 'verifie' => LEGAL_VERIFIE];
}
