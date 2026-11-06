<?php
/**
 * Boussole — alertes.
 *
 * Quatre niveaux : critique (argent à récupérer, risque immédiat), attention (à vérifier vite),
 * info (habitudes, conseils), bravo (progrès : ils comptent autant que les reproches).
 * Chaque alerte a une clé stable : « traitée » ou « ignorée » une fois, elle ne revient pas.
 */
if (!defined('BOUSSOLE')) { http_response_code(403); exit; }

/** Contexte d'un courrier à partir d'une liste d'identifiants d'opérations. */
function letter_items(array $ids, array $byId): array {
    $out = [];
    foreach ($ids as $id) if (isset($byId[$id])) $out[] = ['date' => $byId[$id]['date'], 'label' => $byId[$id]['label'], 'montant' => -(int)$byId[$id]['amount']];
    return $out;
}

/**
 * $c : profile, fees, recurring, forecast, docs, budgets [cat => centimes], subs [clé => ['state','t']],
 *      others_recurring ([[nom, recurrents], …] des personnes qui partagent leur détail), today
 */
function alerts_build(array $txs, array $c): array {
    $A = [];
    $add = function (string $cle, string $niv, string $type, string $titre, string $texte, ?string $mois = null, array $actions = [], array $ids = []) use (&$A) {
        $A[$cle] = ['cle' => $cle, 'niveau' => $niv, 'type' => $type, 'titre' => $titre, 'texte' => $texte, 'mois' => $mois, 'actions' => $actions, 'tx_ids' => array_values(array_map('intval', $ids))];
    };
    $today = (string)($c['today'] ?? date('Y-m-d'));
    $byId = [];
    foreach ($txs as $t) $byId[(int)$t['id']] = $t;
    $last = an_last_date($txs);
    if ($last === null) return [];
    $first = null; foreach ($txs as $t) if ($first === null || $t['date'] < $first) $first = $t['date'];
    $months = an_months($txs);
    $recent = array_slice($months, -2);                     // habitudes : les 2 derniers mois importés
    $cats = categories();
    $courrier = function (string $id, array $ctx, string $label = 'Préparer le courrier') { return ['type' => 'courrier', 'id' => $id, 'label' => $label, 'ctx' => $ctx]; };
    $lien = function (string $href, string $label) { return ['type' => 'lien', 'href' => $href, 'label' => $label]; };
    $eviter = 'Pour les éviter : alerte SMS de solde bas, prélèvements décalés après la paie, un « matelas » de 100 à 200 € laissé sur le compte, ou une autorisation de découvert adaptée (souvent moins chère que des commissions à répétition).';

    // ---------------------------------------------------------------- frais bancaires
    $fees = $c['fees'] ?? ['opportunites' => [], 'months' => [], 'annee' => []];
    $limit13 = substr(date_add_days($last, -395), 0, 7);
    foreach ($fees['opportunites'] as $o) {
        if ($o['mois'] !== null && $o['mois'] < $limit13) continue;
        $ctx = ['items' => letter_items($o['tx_ids'], $byId), 'montant' => $o['montant'], 'mois' => $o['mois'], 'motifs' => [$o['motif'] ?? $o['detail']], 'refs' => $o['refs']];
        $ctx['total'] = 0; foreach ($ctx['items'] as $it) $ctx['total'] += $it['montant'];
        if ($o['type'] === 'droit') {
            $add('frais:' . $o['cle'], 'critique', 'frais', $o['titre'] . ' : ' . money_fmt($o['montant']) . ' à récupérer', $o['detail'] . ' Ce remboursement est un droit, pas une faveur.', $o['mois'],
                [$courrier($o['courrier'], $ctx + ['signature' => $o['titre']]), $lien('#/frais', 'Voir l’analyse des frais')], $o['tx_ids']);
        } elseif ($o['type'] === 'fragilite') {
            $ctx['n_incidents'] = (int)($fees['months'][$o['mois']]['n_incidents'] ?? 0);
            $add('frais:' . $o['cle'], 'attention', 'frais', $o['titre'], $o['detail'], $o['mois'], [$courrier('fragilite', $ctx), $lien('#/droits', 'Comprendre le statut')], $o['tx_ids']);
        } elseif ($o['type'] === 'a_verifier') {
            $add('frais:' . $o['cle'], 'attention', 'frais', $o['titre'], $o['detail'], $o['mois'], [$courrier($o['courrier'], $ctx)], $o['tx_ids']);
        } elseif ($o['type'] === 'geste' && in_array($o['mois'], $recent, true)) {
            $other = (int)($fees['months'][$o['mois']]['a_reclamer'] ?? 0) > 0;
            $add('frais:' . $o['cle'], 'attention', 'frais', ($other ? 'Autres frais d’incidents et agios en ' . fr_month($o['mois']) . ' : ' . money_fmt($o['montant']) : money_fmt($o['montant']) . ' de frais d’incidents et d’agios en ' . fr_month($o['mois'])),
                'Ces frais respectent les plafonds, mais vous pouvez demander un geste commercial (souvent accordé pour un premier incident). ' . $eviter, $o['mois'],
                [$courrier('frais_geste', $ctx, 'Demander un geste commercial'), $lien('#/frais', 'Voir le détail')], $o['tx_ids']);
        } elseif ($o['type'] === 'service') {
            $add('frais:' . $o['cle'], 'info', 'frais', $o['titre'], $o['detail'], null, [$courrier($o['courrier'], ['montant' => $o['montant'], 'items' => []])]);
        }
    }
    $yearTotal = (int)($fees['annee']['total'] ?? 0);
    if ($yearTotal >= 12000) {
        $add('cout_banque:' . substr($last, 0, 4), 'info', 'frais', 'Votre banque vous a coûté ' . money_fmt($yearTotal) . ' sur 12 mois',
            'Frais de service ' . money_fmt((int)($fees['annee']['service'] ?? 0) + (int)($fees['annee']['evitable'] ?? 0)) . ', incidents ' . money_fmt((int)($fees['annee']['incident'] ?? 0)) . ', agios ' . money_fmt((int)($fees['annee']['agios'] ?? 0)) . '. Beaucoup de banques en ligne facturent 0 € pour un usage courant. Changer de banque est gratuit : la nouvelle banque transfère vos prélèvements et virements (mobilité bancaire, art. L312-1-7 CMF).',
            null, [$lien('#/frais', 'Détail des frais'), $courrier('recap_frais', ['annee' => (int)substr($last, 0, 4) - 1], 'Demander le récapitulatif annuel')]);
    }
    foreach ($recent as $ym) {
        $ag = (int)($fees['months'][$ym]['agios'] ?? 0);
        if ($ag > 0) $add('agios:' . $ym, 'info', 'frais', money_fmt($ag) . ' d’agios en ' . fr_month($ym), 'Les agios rémunèrent le découvert utilisé. Un virement depuis l’épargne avant la fin du mois coûte souvent moins cher que des jours de découvert. Le taux appliqué ne peut pas dépasser le taux d’usure publié chaque trimestre par la Banque de France.', $ym, [], []);
    }

    // ---------------------------------------------------------------- opérations suspectes
    $debits = array_values(array_filter($txs, function ($t) { return (int)$t['amount'] < 0; }));
    $win60 = date_add_days($last, -60);
    $win90 = date_add_days($last, -90);
    $seenKey = [];
    usort($debits, function ($a, $b) { return strcmp($a['date'], $b['date']) ?: $a['id'] <=> $b['id']; });
    $noDup = ['transport', 'courses', 'fastfood', 'restaurants', 'carburant', 'tabac', 'vehicule', 'livraison', 'retraits', 'frais_incidents', 'frais_bancaires', 'agios', 'virement_foyer', 'virement_interne', 'epargne'];
    $nDeb = count($debits);
    for ($i = 0; $i < $nDeb; $i++) {
        $t = $debits[$i];
        $k = merchant_key((string)$t['merchant']);
        $v = -(int)$t['amount'];
        // doublons
        if ($t['date'] >= $win90 && !in_array($t['category'], $noDup, true)) {
            for ($j = $i + 1; $j < $nDeb && days_between($t['date'], $debits[$j]['date']) <= 2; $j++) {
                $u = $debits[$j];
                if ((int)$u['amount'] !== (int)$t['amount'] || merchant_key((string)$u['merchant']) !== $k) continue;
                $okKind = $t['kind'] === 'prelevement' || ($t['kind'] === 'carte' && $v >= 2000) || ($t['kind'] === 'virement' && $v >= 5000);
                if (!$okKind) continue;
                $isP = $t['kind'] === 'prelevement';
                $add('doublon:' . $t['id'] . '-' . $u['id'], 'attention', 'suspect', 'Débit en double ? ' . $t['merchant'] . ' — 2 × ' . money_fmt($v),
                    'Deux débits identiques le ' . fr_date($t['date']) . ' et le ' . fr_date($u['date']) . '. Si c’est une erreur : ' . ($isP ? 'un prélèvement autorisé se fait rembourser sans justification dans les 8 semaines.' : 'contestez auprès de la banque (13 mois au plus) et demandez au marchand d’annuler le second débit.'),
                    substr($u['date'], 0, 7), [$courrier($isP ? 'prlv_8_semaines' : 'operation_non_autorisee', ['date' => $u['date'], 'label' => $u['label'], 'montant' => $v, 'type' => $t['kind']])], [(int)$t['id'], (int)$u['id']]);
                break;
            }
        }
        // nouveau créancier de prélèvement
        if ($t['kind'] === 'prelevement' && !isset($seenKey[$k]) && $t['date'] >= $win60 && days_between($first, $t['date']) >= 45 && !in_array($t['category'], ['frais_incidents', 'frais_bancaires', 'agios'], true)) {
            $unknown = in_array($t['category'], ['autre'], true);
            $add('nouveau_creancier:' . $k, $unknown ? 'attention' : 'info', 'suspect', 'Nouveau prélèvement : ' . $t['merchant'] . ' (' . money_fmt($v) . ')',
                'Premier prélèvement de ce créancier, le ' . fr_date($t['date']) . '. Vous le reconnaissez ? Sinon : remboursement sans justification dans les 8 semaines si vous aviez signé un mandat, contestation jusqu’à 13 mois si vous n’en avez jamais signé. Vous pouvez aussi demander à la banque de bloquer ce créancier.',
                substr($t['date'], 0, 7), [$courrier('prlv_8_semaines', ['date' => $t['date'], 'label' => $t['label'], 'montant' => $v]), $courrier('opposition_creancier', ['creancier' => $t['merchant']], 'Bloquer ce créancier')], [(int)$t['id']]);
        }
        $seenKey[$k] = true;
        if ($t['date'] < $win60) continue;
        // petit paiement inconnu (test de carte)
        if ($v <= 200 && in_array($t['kind'], ['carte', 'autre'], true) && $t['category'] === 'autre') {
            $add('micro:' . $t['id'], 'attention', 'suspect', 'Petit paiement inconnu de ' . money_fmt($v) . ' : ' . $t['merchant'],
                'Les fraudeurs testent souvent une carte avec un petit montant avant de gros achats. Si vous ne le reconnaissez pas : opposition immédiate (0 892 705 705), puis contestation écrite.',
                substr($t['date'], 0, 7), [$courrier('operation_non_autorisee', ['date' => $t['date'], 'label' => $t['label'], 'montant' => $v, 'type' => 'carte'])], [(int)$t['id']]);
        }
        // gros débit inconnu
        if ($v >= max(30000, (int)($c['revenu_moyen'] ?? 0) * 0.4) && in_array($t['category'], ['autre', 'virement_emis', 'cheque_emis'], true)) {
            $add('gros:' . $t['id'], 'attention', 'suspect', 'Débit important à identifier : ' . money_fmt($v) . ' (' . $t['merchant'] . ')',
                'Le ' . fr_date($t['date']) . '. Classez-le s’il est normal ; sinon contestez-le sans attendre.', substr($t['date'], 0, 7),
                [$courrier('operation_non_autorisee', ['date' => $t['date'], 'label' => $t['label'], 'montant' => $v, 'type' => $t['kind']])], [(int)$t['id']]);
        }
        // paiement en devise d'un marchand inconnu
        if (has((string)$t['flags'], 'devise') && $t['category'] === 'autre' && $t['kind'] !== 'frais') {
            $add('devise:' . $t['id'], 'info', 'suspect', 'Paiement hors zone euro : ' . $t['merchant'] . ' (' . money_fmt($v) . ')', 'Vérifiez que vous le reconnaissez. Les paiements hors zone euro coûtent souvent une commission de change.', substr($t['date'], 0, 7), [], [(int)$t['id']]);
        }
        // saisie
        if (has((string)$t['flags'], 'saisie')) {
            $add('saisie:' . $t['id'], 'critique', 'suspect', 'Saisie sur le compte : ' . money_fmt($v),
                'La banque doit vous laisser un solde bancaire insaisissable de ' . money_fmt(LEGAL_SBI) . ' (RSA personne seule, depuis le ' . fr_date_long(LEGAL_SBI_DATE) . '), une fois par mois. Contactez le créancier ou un Point conseil budget pour un échéancier.',
                substr($t['date'], 0, 7), [$courrier('sbi', ['date' => $t['date'], 'montant' => 0])], [(int)$t['id']]);
        }
    }

    // ---------------------------------------------------------------- abonnements et charges récurrentes
    $rec = (array)($c['recurring'] ?? []);
    $subs = (array)($c['subs'] ?? []);
    $abos = 0; $aboList = [];
    foreach ($rec as $r) {
        if (!$r['actif']) continue;
        $st = $subs[$r['key']]['state'] ?? '';
        if (in_array($r['category'], ['abonnements', 'jeux_video'], true)) { $abos += $r['mensuel']; $aboList[] = $r['merchant']; }
        if ($r['hausse'] && $r['dernier'] >= $win60) {
            $tel = $r['category'] === 'telecom';
            $add('hausse:' . $r['key'] . ':' . $r['dernier'], 'info', 'abonnement', 'Hausse de prix : ' . $r['merchant'] . ' passe de ' . money_fmt($r['hausse']['avant']) . ' à ' . money_fmt($r['hausse']['apres']) . ' (+' . str_replace('.', ',', (string)$r['hausse']['pct']) . ' %)',
                ($tel ? 'Opérateur télécom : une hausse vous permet de résilier sans pénalité jusqu’à 4 mois après son entrée en vigueur (art. L224-33 du Code de la consommation). ' : '') . 'Sur un an, la hausse représente ' . money_fmt((int)round(($r['hausse']['apres'] - $r['hausse']['avant']) * 365.25 / $r['jours'])) . '. C’est le bon moment pour comparer ou négocier.',
                substr($r['dernier'], 0, 7), [$courrier('resiliation', ['creancier' => $r['merchant'], 'hausse' => $r['hausse']], 'Préparer la résiliation')], $r['tx_ids']);
        }
        if ($st === 'inutile') {
            $add('inutile:' . $r['key'] . ':' . $r['dernier'], 'attention', 'abonnement', 'Abonnement jugé inutile toujours payé : ' . $r['merchant'] . ' (' . money_fmt($r['annuel']) . ' par an)',
                'Vous l’avez marqué « inutile ». Chaque mois qui passe coûte ' . money_fmt($r['mensuel']) . '.', substr($r['dernier'], 0, 7), [$courrier('resiliation', ['creancier' => $r['merchant']], 'Préparer la résiliation')], $r['tx_ids']);
        }
        if ($r['nouveau'] && in_array($r['category'], ['abonnements', 'jeux_video', 'sport'], true)) {
            $add('nouvel_abo:' . $r['key'], 'info', 'abonnement', 'Nouvel abonnement : ' . $r['merchant'] . ' (' . money_fmt($r['mensuel']) . ' par mois)', 'Soit ' . money_fmt($r['annuel']) . ' par an. Si c’était un essai gratuit, c’est le moment de décider.', substr($r['dernier'], 0, 7), [], $r['tx_ids']);
        }
    }
    // prélèvement après résiliation
    foreach ($subs as $key => $s) {
        if (($s['state'] ?? '') !== 'resilie' || empty($s['t'])) continue;
        $since = date('Y-m-d', (int)$s['t'] + 3 * 86400);
        foreach ($debits as $t) {
            if ($t['date'] <= $since || merchant_key((string)$t['merchant']) !== $key) continue;
            $v = -(int)$t['amount'];
            $add('apres_resiliation:' . $t['id'], 'critique', 'suspect', 'Prélevé après résiliation : ' . $t['merchant'] . ' (' . money_fmt($v) . ')',
                'Vous avez indiqué avoir résilié le ' . fr_date(date('Y-m-d', (int)$s['t'])) . '. Un mandat révoqué rend le prélèvement non autorisé : remboursement exigible (13 mois). Demandez aussi à la banque de bloquer ce créancier.',
                substr($t['date'], 0, 7), [$courrier('operation_non_autorisee', ['date' => $t['date'], 'label' => $t['label'], 'montant' => $v, 'type' => 'prelevement']), $courrier('opposition_creancier', ['creancier' => $t['merchant']], 'Bloquer ce créancier')], [(int)$t['id']]);
        }
    }
    if ($abos >= 4000) {
        $add('abos_total:' . substr($last, 0, 7), 'info', 'abonnement', 'Abonnements : ' . money_fmt($abos) . ' par mois, ' . money_fmt((int)round($abos * 12)) . ' par an',
            implode(', ', array_slice($aboList, 0, 8)) . '. Passez-les en revue une fois par trimestre : on garde ce qu’on utilise vraiment, on alterne les plateformes au lieu de les cumuler.', substr($last, 0, 7), [$lien('#/abonnements', 'Faire le tri')]);
    }
    // abonnements payés en double (ou plus) dans le foyer
    if (!empty($c['others_recurring'])) {
        $mine = [];
        foreach ($rec as $r) if ($r['actif'] && $r['category'] === 'abonnements') $mine[$r['key']] = $r;
        $aussi = [];
        foreach ((array)$c['others_recurring'] as $o) {
            foreach ((array)$o['rec'] as $p) {
                if (!$p['actif'] || !isset($mine[$p['key']])) continue;
                $aussi[$p['key']][] = ['nom' => (string)$o['nom'], 'mensuel' => (int)$p['mensuel'], 'annuel' => (int)$p['annuel']];
            }
        }
        foreach ($aussi as $key => $autres) {
            $r = $mine[$key];
            $noms = array_column($autres, 'nom');
            $qui = count($noms) === 1 ? 'Vous et ' . $noms[0] . ' payez' : 'Vous, ' . implode(', ', array_slice($noms, 0, -1)) . ' et ' . end($noms) . ' payez';
            $detail = 'Vous : ' . money_fmt($r['mensuel']) . ' par mois';
            $eco = $r['annuel'];
            foreach ($autres as $x) { $detail .= ' ; ' . $x['nom'] . ' : ' . money_fmt($x['mensuel']) . ' par mois'; $eco = min($eco, $x['annuel']); }
            $add('abo_double:' . $key, 'info', 'abonnement', $qui . ' chacun ' . $r['merchant'],
                $detail . '. Une offre partagée (duo, famille) coûte généralement bien moins que plusieurs abonnements : au moins ' . money_fmt($eco * count($autres)) . ' par an d’économie possible.',
                null, [$lien('#/abonnements', 'Voir les abonnements')]);
        }
    }

    // ---------------------------------------------------------------- habitudes du mois
    $budgets = (array)($c['budgets'] ?? []);
    foreach ($recent as $ym) {
        $mt = an_month($txs, $ym);
        $s = an_summary($mt);
        $rev = $s['revenus'];
        $byCat = function (array $list) use ($mt) { return array_values(array_filter($mt, function ($t) use ($list) { return (int)$t['amount'] < 0 && in_array($t['category'], $list, true); })); };
        $sum = function (array $l) { $x = 0; foreach ($l as $t) $x += -(int)$t['amount']; return $x; };

        $j = $byCat(['jeux_argent']);
        if ($j) {
            $tot = $sum($j);
            $add('jeux:' . $ym, ($tot >= 5000 || ($rev > 0 && $tot >= $rev * 0.05)) ? 'attention' : 'info', 'habitude', money_fmt($tot) . ' de jeux d’argent en ' . fr_month($ym) . ' (' . count($j) . (count($j) > 1 ? ' mises)' : ' mise)'),
                'Ça reste un loisir tant que c’est un budget fixé à l’avance et jamais « rattrapé ». Si c’est difficile à contrôler : Joueurs Info Service, 09 74 75 13 13 (appel non surtaxé), et possibilité d’interdiction volontaire de jeux auprès de l’Autorité nationale des jeux.', $ym, [], array_map(function ($t) { return (int)$t['id']; }, $j));
        }
        $b = $byCat(['bnpl']);
        $prov = []; foreach ($b as $t) $prov[merchant_key((string)$t['merchant'])] = true;
        if (count($b) >= 3 || count($prov) >= 2) {
            $add('bnpl:' . $ym, 'attention', 'habitude', count($b) . ' échéances de paiement fractionné en ' . fr_month($ym) . ' (' . money_fmt($sum($b)) . ')',
                'Payer en 3 ou 4 fois, c’est du crédit : les échéances s’additionnent sans qu’on les voie, et un rejet entraîne des frais des deux côtés (organisme et banque). Gardez-en une seule à la fois.', $ym, [], array_map(function ($t) { return (int)$t['id']; }, $b));
        }
        foreach ($mt as $t) if ((int)$t['amount'] < 0 && has((string)$t['flags'], 'revolving')) {
            $add('revolving:' . $ym, 'attention', 'habitude', 'Crédit renouvelable détecté (' . $t['merchant'] . ')', 'Les crédits renouvelables (« réserve d’argent ») ont des taux souvent proches du taux d’usure. Rembourser en priorité, ou remplacer par un prêt personnel à taux fixe, coûte généralement beaucoup moins cher.', $ym, [], [(int)$t['id']]);
            break;
        }
        $l = $byCat(['livraison']);
        if (count($l) >= 5 || $sum($l) >= 8000) {
            $add('livraison:' . $ym, 'info', 'habitude', count($l) . ' repas livrés en ' . fr_month($ym) . ' : ' . money_fmt($sum($l)), 'À ce rythme : environ ' . money_fmt($sum($l) * 12) . ' par an. Les frais de livraison et de service représentent souvent 20 à 30 % de la note.', $ym, [], array_map(function ($t) { return (int)$t['id']; }, $l));
        }
        // achats impulsifs : 4 achats « plaisir » ou plus le même jour
        $perDay = [];
        foreach ($mt as $t) if ((int)$t['amount'] < 0 && $t['nature'] === 'plaisir' && in_array($t['kind'], ['carte', 'autre'], true)) $perDay[$t['date']][] = $t;
        $worst = null; foreach ($perDay as $d => $list) if (count($list) >= 4 && ($worst === null || count($list) > count($perDay[$worst]))) $worst = $d;
        if ($worst !== null) {
            $add('impulsif:' . $worst, 'info', 'habitude', count($perDay[$worst]) . ' achats « plaisir » le ' . fr_date($worst) . ' (' . money_fmt($sum($perDay[$worst])) . ')', 'Une journée chargée n’est pas un problème en soi. Si ça se répète, une règle simple aide : attendre 24 heures avant tout achat non prévu de plus de 30 €.', $ym, [], array_map(function ($t) { return (int)$t['id']; }, $perDay[$worst]));
        }
        // effet jour de paie
        $pay = array_values(array_filter($mt, function ($t) { return (int)$t['amount'] > 0 && $t['category'] === 'salaire'; }));
        $pl = $s['nature']['plaisir'];
        if ($pay && $pl >= 15000) {
            $pd = $pay[0]['date']; $after = 0;
            foreach ($mt as $t) if ((int)$t['amount'] < 0 && $t['nature'] === 'plaisir' && $t['date'] >= $pd && days_between($pd, $t['date']) <= 3) $after += -(int)$t['amount'];
            if ($after >= $pl * 0.4) $add('jour_paie:' . $ym, 'info', 'habitude', round($after / $pl * 100) . ' % des dépenses plaisir de ' . fr_month($ym) . ' dans les 3 jours après la paie', 'C’est « l’effet jour de paie » : le compte semble plein, on dépense plus. L’antidote : un virement automatique vers l’épargne programmé le lendemain de la paie, et une enveloppe plaisir fixe pour le mois.', $ym, [$lien('#/reglages', 'Définir une enveloppe')], []);
        }
        // petits achats
        $small = array_values(array_filter($mt, function ($t) { return (int)$t['amount'] < 0 && (int)$t['amount'] > -1000 && $t['nature'] === 'plaisir'; }));
        if (count($small) >= 15 && $sum($small) >= 8000) {
            $add('petits:' . $ym, 'info', 'habitude', money_fmt($sum($small)) . ' en ' . count($small) . ' petits achats de moins de 10 € (' . fr_month($ym) . ')', 'Pris un par un, ils ne pèsent rien ; sur un an, cela fait environ ' . money_fmt($sum($small) * 12) . '. Pas besoin de tout supprimer : choisissez ceux qui vous font vraiment plaisir.', $ym, [], []);
        }
        // espèces
        $cash = $byCat(['retraits']);
        if ($cash && ($sum($cash) >= 15000 || ($s['depenses'] > 0 && $sum($cash) >= $s['depenses'] * 0.15))) {
            $add('especes:' . $ym, 'info', 'habitude', money_fmt($sum($cash)) . ' retirés en espèces en ' . fr_month($ym), 'L’argent liquide échappe à tout suivi : c’est de la dépense « invisible ». Si vous préférez le liquide, notez à quoi il sert (une catégorie suffit) ou fixez un retrait hebdomadaire.', $ym, [], array_map(function ($t) { return (int)$t['id']; }, $cash));
        }
        // budgets (enveloppes)
        foreach ($budgets as $cat => $lim) {
            $lim = (int)$lim; if ($lim <= 0) continue;
            $spent = (int)($s['cats'][$cat]['montant'] ?? 0);
            if ($spent >= $lim) $add('budget:' . $cat . ':' . $ym, 'attention', 'budget', 'Enveloppe « ' . ($cats[$cat][0] ?? $cat) . ' » dépassée : ' . money_fmt($spent) . ' sur ' . money_fmt($lim), 'Dépassement de ' . money_fmt($spent - $lim) . ' en ' . fr_month($ym) . '. Ajustez l’enveloppe si elle était irréaliste : un budget tenable vaut mieux qu’un budget idéal.', $ym, [$lien('#/reglages', 'Ajuster')], []);
            elseif ($spent >= $lim * 0.8) $add('budget80:' . $cat . ':' . $ym, 'info', 'budget', 'Enveloppe « ' . ($cats[$cat][0] ?? $cat) . ' » à ' . round($spent / $lim * 100) . ' %', money_fmt($lim - $spent) . ' restants en ' . fr_month($ym) . '.', $ym, [], []);
        }
        // épargne
        if ($rev > 0 && $s['epargne'] <= 0) {
            $add('epargne0:' . $ym, 'info', 'epargne', 'Rien mis de côté en ' . fr_month($ym), 'Programmez un virement automatique de ' . money_fmt((int)(round($rev * 0.1 / 1000) * 1000) ?: 2000) . ' (environ 10 % des revenus) le lendemain de la paie : ce qui part en premier ne se dépense pas.', $ym, [], []);
        } elseif ($rev > 0 && $s['epargne'] >= $rev * 0.1) {
            $add('epargne_bravo:' . $ym, 'bravo', 'epargne', 'Bravo : ' . money_fmt($s['epargne']) . ' épargnés en ' . fr_month($ym), round($s['epargne'] / $rev * 100) . ' % de vos revenus. C’est ce rythme qui construit la sérénité.', $ym, [], []);
        }
        $inc = (int)($fees['months'][$ym]['incident'] ?? 0);
        $prevYm = month_add($ym, -1);
        if ($rev > 0 && $inc === 0 && (int)($fees['months'][$prevYm]['incident'] ?? 0) > 0) {
            $add('zero_frais:' . $ym, 'bravo', 'frais', 'Aucun frais d’incident en ' . fr_month($ym), 'Le mois précédent en comptait ' . money_fmt((int)$fees['months'][$prevYm]['incident']) . '. Ce qui a changé fonctionne : gardez-le.', $ym, [], []);
        }
        if ($s['a_classer'] >= 5) {
            $add('a_classer:' . $ym, 'info', 'classement', $s['a_classer'] . ' opérations à classer en ' . fr_month($ym), 'Espèces, virements, chèques : Boussole ne peut pas deviner leur usage. Les classer affine le score et les conseils (une règle « toujours classer ainsi » évite de recommencer).', $ym, [$lien('#/operations?nature=inconnu', 'Classer')], []);
        }
        if ($rev > 0 && $pl > $rev * 0.35) {
            $add('plaisir:' . $ym, 'info', 'habitude', 'Plaisir & confort : ' . round($pl / $rev * 100) . ' % des revenus en ' . fr_month($ym), 'Repère souvent cité : 50 % pour l’essentiel, 30 % pour le plaisir, 20 % pour l’épargne. Ce n’est pas une règle absolue, mais un écart durable mérite une enveloppe.', $ym, [$lien('#/reglages', 'Définir des enveloppes')], []);
        }
    }
    // tabac sur 3 mois
    $t3 = date_add_days($last, -92); $tab = 0;
    foreach ($debits as $t) if ($t['date'] >= $t3 && $t['category'] === 'tabac') $tab += -(int)$t['amount'];
    if ($tab >= 10000) $add('tabac:' . substr($last, 0, 7), 'info', 'habitude', 'Tabac & presse : ' . money_fmt($tab) . ' en 3 mois', 'Environ ' . money_fmt((int)round($tab * 4)) . ' par an. Si vous souhaitez réduire, Tabac Info Service (39 89) propose un accompagnement gratuit.', substr($last, 0, 7), [], []);
    // dons
    $year = substr($last, 0, 4); $dons = 0;
    foreach ($debits as $t) if (substr($t['date'], 0, 4) === $year && $t['category'] === 'dons') $dons += -(int)$t['amount'];
    if ($dons > 0) $add('dons:' . $year, 'bravo', 'impots', money_fmt($dons) . ' de dons en ' . $year, 'Environ ' . money_fmt((int)round($dons * 0.66)) . ' de réduction d’impôt (66 %, voire 75 % pour l’aide aux personnes en difficulté). Gardez les reçus fiscaux : déposez-les dans le coffre-fort.', null, [$lien('#/coffre', 'Ranger les reçus')], []);

    // ---------------------------------------------------------------- projection
    $f = $c['forecast'] ?? null;
    if ($f !== null) {
        if ($f['ancien']) {
            $add('solde_ancien:' . $f['depart']['date'], 'info', 'projection', 'Indiquez votre solde du jour pour une projection fiable', 'La projection part du ' . fr_date($f['depart']['date']) . '. Un solde récent (Accueil › « Mon solde aujourd’hui ») permet d’annoncer un découvert avant qu’il arrive.', null, [$lien('#/accueil', 'Mettre à jour')], []);
        } elseif ($f['premier_negatif'] !== null) {
            $big = null; foreach ($f['echeances'] as $e) if ($e['montant'] < 0 && $e['date'] >= $f['premier_negatif'] && ($big === null || $e['montant'] < $big['montant'])) $big = $e;
            if ($big === null) foreach ($f['echeances'] as $e) if ($e['montant'] < 0 && ($big === null || $e['montant'] < $big['montant'])) $big = $e;
            $need = -$f['point_bas'] + 5000;
            $add('decouvert:' . $f['premier_negatif'], 'critique', 'projection', 'Risque de découvert le ' . fr_date($f['premier_negatif']) . ' (point bas prévu : ' . money_fmt($f['point_bas']) . ')',
                'D’après vos charges habituelles et vos dépenses moyennes, le compte passerait dans le rouge avant la paie' . ($f['jour_paie'] ? ' (vers le ' . $f['jour_paie'] . ')' : '') . '. Pour l’éviter : virement d’environ ' . money_fmt((int)(ceil($need / 1000) * 1000)) . ' avant cette date, ou décaler ' . ($big ? '« ' . $big['label'] . ' »' : 'un prélèvement') . ' après la paie. Un découvert évité, ce sont des commissions d’intervention en moins.',
                null, $big ? [$courrier('changement_date', ['creancier' => $big['label'], 'jour' => min(28, (int)($f['jour_paie'] ?? 1) + 3)], 'Décaler un prélèvement')] : [], []);
        }
    }

    // ---------------------------------------------------------------- documents et paie
    $docs = (array)($c['docs'] ?? []);
    $salaries = array_values(array_filter($txs, function ($t) { return (int)$t['amount'] > 0 && $t['category'] === 'salaire'; }));
    $types = doc_types();
    $latest = [];
    foreach ($docs as $d) {
        $meta = json_dec($d['meta'] ?? '{}');
        if ($d['type'] === 'paie' && !empty($d['period']) && !empty($meta['net'])) {
            $ym = (string)$d['period'];
            $from = date_add_days(substr($ym, 0, 7) . '-' . days_in_month($ym), -7);
            $to = month_add($ym, 1) . '-12';
            if ($last < $to && $last < date_add_days($to, -5)) continue;          // relevé de la période pas encore importé
            $got = 0; $ids = [];
            foreach ($salaries as $t) if ($t['date'] >= $from && $t['date'] <= $to) { $got += (int)$t['amount']; $ids[] = (int)$t['id']; }
            $net = (int)$meta['net'];
            if ($got === 0) {
                if ($first <= $from) $add('paie_absente:' . $d['id'], 'attention', 'paie', 'Salaire de ' . fr_month($ym) . ' introuvable sur le compte', 'Le bulletin annonce ' . money_fmt($net) . ' net à payer, mais aucun virement de salaire n’apparaît entre le ' . fr_date($from) . ' et le ' . fr_date($to) . '. Vérifiez l’alias de votre employeur dans Réglages, ou contactez le service paie.', $ym, [$lien('#/coffre', 'Voir le bulletin')], []);
            } elseif (abs($got - $net) > 100) {
                $add('paie_ecart:' . $d['id'], 'attention', 'paie', 'Écart de paie en ' . fr_month($ym) . ' : ' . money_fmt($got - $net, true), 'Bulletin : ' . money_fmt($net) . ' net à payer ; reçu sur le compte : ' . money_fmt($got) . '. Un acompte, une avance ou une erreur ? Demandez le détail au service paie.', $ym, [], $ids);
            }
        }
        if (in_array($d['type'], ['identite', 'vehicule', 'assurance'], true) && !empty($d['expiry'])) {
            $left = days_between($today, (string)$d['expiry']);
            if ($left < 0) $add('expire:' . $d['id'], 'attention', 'document', ($types[$d['type']][0] ?? 'Document') . ' expiré : ' . $d['title'], 'Expiré depuis le ' . fr_date((string)$d['expiry']) . '.', null, [$lien('#/coffre', 'Ouvrir le coffre')], []);
            elseif ($left <= 60) $add('expire:' . $d['id'], 'attention', 'document', ($types[$d['type']][0] ?? 'Document') . ' : expire le ' . fr_date((string)$d['expiry']), 'Il reste ' . $left . ' jours. Les délais de rendez-vous peuvent être longs : anticipez.', null, [$lien('#/coffre', 'Ouvrir le coffre')], []);
        }
        if (in_array($d['type'], ['domicile', 'facture_energie', 'facture_telecom', 'quittance'], true) && !empty($d['doc_date'])) {
            if (!isset($latest['dom']) || $d['doc_date'] > $latest['dom']) $latest['dom'] = (string)$d['doc_date'];
        }
    }
    if (isset($latest['dom']) && days_between($latest['dom'], $today) > 92) {
        $add('domicile:' . substr($today, 0, 7), 'info', 'document', 'Justificatif de domicile de plus de 3 mois', 'Le plus récent date du ' . fr_date($latest['dom']) . '. La plupart des démarches demandent moins de 3 mois : déposez la dernière facture d’énergie ou de box.', null, [$lien('#/coffre', 'Ajouter un document')], []);
    }

    // ---------------------------------------------------------------- relevés à importer
    if (days_between($last, $today) > 40) {
        $add('releve:' . month_add(substr($last, 0, 7), 1), 'info', 'classement', 'Dernière opération importée : ' . fr_date($last), 'Importez le relevé suivant : les alertes (frais, prélèvements suspects, découvert) ne sont utiles que si les données sont récentes. Les délais pour contester sont courts (8 semaines pour un prélèvement).', null, [$lien('#/importer', 'Importer un relevé')], []);
    }
    return array_values($A);
}
