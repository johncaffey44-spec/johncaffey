<?php
/**
 * Boussole — analyses : bilan du mois, abonnements, frais bancaires et plafonds légaux,
 * score de bien-être, projection jusqu'à la paie, équité dans le couple, alertes.
 *
 * Tout est calculé à partir des opérations importées (montants en centimes, débit < 0).
 * Les plafonds légaux sont dans legal.php (constantes LEGAL_*), datés et sourcés.
 */
if (!defined('BOUSSOLE')) { http_response_code(403); exit; }

const SPEND_NATURES = ['essentiel', 'plaisir', 'evitable', 'inconnu'];

/* ========================================================================== */
/* Bilan d'une liste d'opérations (un mois, une personne ou le foyer)          */
/* ========================================================================== */

function an_summary(array $txs): array {
    $s = ['revenus' => 0, 'entrees' => 0, 'depenses' => 0, 'epargne' => 0, 'transferts_in' => 0, 'transferts_out' => 0,
        'nature' => ['essentiel' => 0, 'plaisir' => 0, 'evitable' => 0, 'inconnu' => 0],
        'cats' => [], 'revenus_cats' => [], 'n' => 0, 'a_classer' => 0, 'communes' => 0,
        'frais' => ['incident' => 0, 'agios' => 0, 'saisie' => 0, 'service' => 0, 'evitable' => 0, 'rembourses' => 0], 'vers_partenaire' => 0, 'du_partenaire' => 0];
    $ft = fee_types();
    foreach ($txs as $t) {
        $a = (int)$t['amount']; $nat = (string)$t['nature']; $cat = (string)$t['category'];
        $s['n']++;
        if ($a > 0) {
            if ($nat === 'revenu') { $s['revenus'] += $a; $s['revenus_cats'][$cat] = ($s['revenus_cats'][$cat] ?? 0) + $a; }
            elseif ($nat === 'transfert') { $s['transferts_in'] += $a; if ($cat === 'virement_foyer') $s['du_partenaire'] += $a; }
            elseif ($nat === 'epargne') $s['epargne'] -= $a;
            else $s['entrees'] += $a;
            if (has((string)$t['flags'], 'remb_frais')) $s['frais']['rembourses'] += $a;
            continue;
        }
        $v = -$a;
        if ($nat === 'transfert') { $s['transferts_out'] += $v; if ($cat === 'virement_foyer') $s['vers_partenaire'] += $v; continue; }
        if ($nat === 'epargne') { $s['epargne'] += $v; continue; }
        if (!isset($s['nature'][$nat])) $nat = 'inconnu';
        $s['nature'][$nat] += $v;
        $s['depenses'] += $v;
        if ($nat === 'inconnu') $s['a_classer']++;
        if (!isset($s['cats'][$cat])) $s['cats'][$cat] = ['montant' => 0, 'n' => 0, 'nature' => $nat];
        $s['cats'][$cat]['montant'] += $v;
        $s['cats'][$cat]['n']++;
        if ((int)$t['common']) $s['communes'] += $v;
        $fee = (string)($t['fee'] ?? '');
        if ($fee !== '' && isset($ft[$fee])) $s['frais'][$ft[$fee][1]] += $v;
    }
    uasort($s['cats'], function ($x, $y) { return $y['montant'] <=> $x['montant']; });
    arsort($s['revenus_cats']);
    // ce qui reste : revenus et entrées, moins les dépenses, moins ce qui a été versé à l'autre (en vue foyer, ces versements s'annulent)
    $s['reste'] = $s['revenus'] + $s['entrees'] - $s['depenses'] - $s['vers_partenaire'] + $s['du_partenaire'];
    $s['taux_epargne'] = $s['revenus'] > 0 ? round($s['reste'] / $s['revenus'] * 100, 1) : null;
    return $s;
}

/** Opérations d'un mois donné. */
function an_month(array $txs, string $ym): array {
    return array_values(array_filter($txs, function ($t) use ($ym) { return strncmp($t['date'], $ym, 7) === 0; }));
}
function an_months(array $txs): array {
    $m = [];
    foreach ($txs as $t) $m[substr($t['date'], 0, 7)] = true;
    $m = array_keys($m);
    sort($m);
    return $m;
}
function an_last_date(array $txs): ?string {
    $d = null;
    foreach ($txs as $t) if ($d === null || $t['date'] > $d) $d = $t['date'];
    return $d;
}

/* ========================================================================== */
/* Prélèvements et dépenses récurrentes (abonnements, charges fixes)           */
/* ========================================================================== */

function an_recurring(array $txs, ?string $ref = null): array {
    $ref = $ref ?? an_last_date($txs) ?? date('Y-m-d');
    $firstAll = null;
    foreach ($txs as $t) if ($firstAll === null || $t['date'] < $firstAll) $firstAll = $t['date'];
    $never = ['courses', 'restaurants', 'fastfood', 'livraison', 'carburant', 'shopping', 'high_tech', 'maison', 'loisirs', 'beaute', 'voyages', 'vtc_taxi',
        'tabac', 'cadeaux', 'jeux_argent', 'frais_incidents', 'agios', 'retraits', 'ecommerce', 'virement_foyer', 'virement_interne', 'amendes'];
    $groups = [];
    foreach ($txs as $t) {
        if ((int)$t['amount'] >= 0 || $t['kind'] === 'retrait') continue;
        if (in_array($t['category'], $never, true) && $t['kind'] !== 'prelevement') continue;
        if (in_array($t['category'], ['virement_foyer', 'virement_interne', 'frais_incidents', 'agios'], true)) continue;
        $k = merchant_key((string)$t['merchant']);
        if ($k === '') continue;
        $groups[$k][] = $t;
    }
    $periods = [['hebdomadaire', 6, 8, 7.0], ['mensuel', 25, 36, 30.44], ['bimestriel', 54, 66, 60.9], ['trimestriel', 84, 98, 91.3], ['semestriel', 170, 195, 182.6], ['annuel', 345, 385, 365.25]];
    $out = [];
    foreach ($groups as $k => $g) {
        usort($g, function ($a, $b) { return strcmp($a['date'], $b['date']) ?: $a['id'] <=> $b['id']; });
        $months = [];
        foreach ($g as $t) $months[substr($t['date'], 0, 7)] = true;
        if (count($months) < 2) continue;
        $iv = [];
        for ($i = 1; $i < count($g); $i++) { $d = days_between($g[$i - 1]['date'], $g[$i]['date']); if ($d >= 5) $iv[] = $d; }
        if (!$iv) continue;
        $med = median($iv);
        $per = null;
        foreach ($periods as $p) if ($med >= $p[1] && $med <= $p[2]) { $per = $p; break; }
        if ($per === null) continue;
        $amts = array_map(function ($t) { return -(int)$t['amount']; }, $g);
        $cat = (string)$g[count($g) - 1]['category'];
        $variable = in_array($cat, ['energie', 'eau', 'telecom', 'impots', 'sante', 'transport'], true);
        $lim = $variable ? 1.0 : 0.3;
        $m = median($amts);
        if ((max($amts) - min($amts)) / max(1, $m) > $lim) {
            $tail = array_slice($amts, -3);
            if (count($tail) < 2 || (max($tail) - min($tail)) / max(1, median($tail)) > $lim) continue;
            $m = median($tail);
        }
        $last = $g[count($g) - 1];
        $prev = count($g) >= 2 ? $g[count($g) - 2] : null;
        $since = days_between($last['date'], $ref);
        $lastA = -(int)$last['amount'];
        $prevA = $prev ? -(int)$prev['amount'] : 0;
        if (!$variable) $m = $lastA;                 // prix fixe : le dernier montant est le prix actuel (après une hausse)
        $out[] = [
            'key' => $k,
            'merchant' => (string)$last['merchant'],
            'label' => (string)$last['label'],
            'category' => $cat,
            'nature' => (string)$last['nature'],
            'kind' => (string)$last['kind'],
            'periode' => $per[0],
            'jours' => $per[3],
            'montant' => (int)round($m),
            'mensuel' => (int)round($m * 30.44 / $per[3]),
            'annuel' => (int)round($m * 365.25 / $per[3]),
            'jour' => (int)round(median(array_map(function ($t) { return (int)substr($t['date'], 8, 2); }, $g))),
            'n' => count($g),
            'premier' => $g[0]['date'],
            'dernier' => $last['date'],
            'dernier_montant' => $lastA,
            'prochain' => date_add_days($last['date'], (int)round($per[3])),
            'actif' => $since <= $per[3] * 1.6 + 5,
            'hausse' => $prev !== null && $lastA > $prevA * 1.05 && $lastA - $prevA >= 50 ? ['avant' => $prevA, 'apres' => $lastA, 'pct' => round(($lastA - $prevA) / max(1, $prevA) * 100, 1)] : null,
            // « nouveau » : absent des relevés plus anciens (il faut donc de l'historique avant sa première apparition)
            'nouveau' => days_between($g[0]['date'], $ref) <= 70 && days_between((string)$firstAll, $g[0]['date']) >= 45,
            'tx_ids' => array_map(function ($t) { return (int)$t['id']; }, array_slice($g, -6)),
        ];
    }
    usort($out, function ($a, $b) { return ($b['actif'] <=> $a['actif']) ?: $b['mensuel'] <=> $a['mensuel']; });
    return $out;
}

/* ========================================================================== */
/* Frais bancaires : plafonds légaux, représentations, fragilité, gestes        */
/* ========================================================================== */

/** Signature du créancier dans un libellé de frais (pour reconnaître un même prélèvement présenté plusieurs fois). */
function an_fee_signature(string $norm): ?string {
    $t = preg_replace('/\b(FRAIS|FRS|COMMISSION|COMMISSIONS|COM|FORFAIT|REJET|REJETE|REJETS|IMPAYE|IMPAYES|NON|PAIEMENT|RETOUR|REFUS|PRLV|PRELEVEMENT|PRELEVEMENTS|PRELEV|PRL|SEPA|SDD|VIR|VIREMENT|DE|DU|DES|D|SUR|LE|LA|POUR|PAR|ECHEANCE|ORDRE|OPERATION|INSUFFISANCE|PROVISION|TIP)\b|\d+/', ' ', $norm);
    $t = trim((string)preg_replace('/\s+/', ' ', (string)$t));
    return strlen($t) >= 3 ? $t : null;
}

/**
 * Analyse des frais. $profile['fragile'] : 'non' (défaut), 'fragile' (reconnu en situation de fragilité),
 * 'ocf' (offre spécifique souscrite) ou 'sbb' (droit au compte / services bancaires de base).
 */
function an_fees(array $txs, array $profile = []): array {
    $status = (string)($profile['fragile'] ?? 'non');
    $lowIC = in_array($status, ['ocf', 'sbb'], true);
    $capOp = $lowIC ? LEGAL_CI_OP_REDUIT : LEGAL_CI_OP;
    $capMonth = $lowIC ? LEGAL_CI_MOIS_REDUIT : LEGAL_CI_MOIS;
    $capFragile = $status === 'ocf' ? LEGAL_OCF_MOIS : ($status === 'fragile' || $status === 'sbb' ? LEGAL_FRAGILE_MOIS : null);
    $ft = fee_types();

    $fees = []; $credits = [];
    foreach ($txs as $t) {
        if ((int)$t['amount'] < 0 && ($t['fee'] ?? '') !== '') $fees[] = $t;
        elseif ((int)$t['amount'] > 0) $credits[] = $t;
    }
    usort($fees, function ($a, $b) { return strcmp($a['date'], $b['date']) ?: $a['id'] <=> $b['id']; });

    $months = []; $opps = []; $year = ['incident' => 0, 'agios' => 0, 'saisie' => 0, 'service' => 0, 'evitable' => 0, 'rembourses' => 0];
    $service = [];
    $since = date('Y-m-d', strtotime((an_last_date($txs) ?? date('Y-m-d')) . ' -365 days'));
    foreach ($credits as $c) if (has((string)$c['flags'], 'remb_frais') && $c['date'] > $since) $year['rembourses'] += (int)$c['amount'];

    foreach ($fees as $f) {
        $ym = substr($f['date'], 0, 7);
        $fam = $ft[$f['fee']][1] ?? 'service';
        $v = -(int)$f['amount'];
        if (!isset($months[$ym])) $months[$ym] = ['incident' => 0, 'agios' => 0, 'saisie' => 0, 'service' => 0, 'evitable' => 0, 'n_incidents' => 0, 'items' => [], 'a_reclamer' => 0];
        $months[$ym][$fam] += $v;
        $months[$ym]['items'][] = ['id' => (int)$f['id'], 'date' => $f['date'], 'label' => $f['label'], 'montant' => $v, 'type' => $f['fee'], 'type_label' => $ft[$f['fee']][0] ?? $f['fee'], 'famille' => $fam];
        if (in_array($f['fee'], ['intervention', 'rejet_prlv', 'rejet_cheque', 'incident_divers'], true)) $months[$ym]['n_incidents']++;
        if ($f['date'] > $since) {
            $year[$fam] += $v;
            if ($fam === 'service' || $fam === 'evitable') {
                if (!isset($service[$f['fee']])) $service[$f['fee']] = ['type' => $f['fee'], 'label' => $ft[$f['fee']][0], 'total' => 0, 'n' => 0, 'famille' => $fam];
                $service[$f['fee']]['total'] += $v;
                $service[$f['fee']]['n']++;
            }
        }
    }

    // --- contrôles par mois
    $used = [];          // frais déjà rattachés à un incident de rejet
    $repSeen = [];       // signature => [date du 1er rejet]
    foreach ($months as $ym => &$M) {
        $items = array_values(array_filter($fees, function ($f) use ($ym) { return strncmp($f['date'], $ym, 7) === 0; }));
        $excessMonth = 0;

        // 1. rejets de prélèvement / virement : tout l'incident ≤ min(montant rejeté, 20 €) — art. D133-6 CMF
        foreach ($items as $f) {
            if ($f['fee'] !== 'rejet_prlv' || isset($used[$f['id']])) continue;
            // un rejet entraîne au plus UNE commission d'intervention et UNE lettre : on rattache la plus proche en date
            $group = [$f]; $used[$f['id']] = true;
            foreach (['intervention' => 1, 'lettre' => 3, 'incident_divers' => 0] as $type => $maxDays) {
                $best = null; $bestD = PHP_INT_MAX;
                foreach ($items as $g) {
                    if (isset($used[$g['id']]) || $g['fee'] !== $type) continue;
                    $dd = abs(days_between($f['date'], $g['date']));
                    if ($dd <= $maxDays && $dd < $bestD) { $best = $g; $bestD = $dd; }
                }
                if ($best !== null) { $group[] = $best; $used[$best['id']] = true; }
            }
            $order = null;
            foreach ($credits as $c) {
                if (abs(days_between($f['date'], $c['date'])) <= 5 && preg_match('/\b(REJET|REJETE|IMPAYE|RETOUR|ANNULATION)\b/', $c['norm']) && !has((string)$c['flags'], 'remb_frais')) { $order = (int)$c['amount']; break; }
            }
            $total = 0; foreach ($group as $g) $total += -(int)$g['amount'];
            $cap = $order !== null ? min(LEGAL_REJET_PRLV, $order) : LEGAL_REJET_PRLV;
            $sig = an_fee_signature((string)$f['norm']);
            $repeat = false;
            if ($sig !== null) {
                if (isset($repSeen[$sig]) && days_between($repSeen[$sig], $f['date']) <= 45) $repeat = true;
                else $repSeen[$sig] = $f['date'];
            }
            $ids = array_map(function ($g) { return (int)$g['id']; }, $group);
            if ($repeat) {
                $opps[] = ['type' => 'droit', 'cle' => 'represente:' . $f['id'], 'mois' => $ym, 'montant' => $total, 'courrier' => 'represente', 'tx_ids' => $ids,
                    'titre' => 'Même prélèvement rejeté une nouvelle fois (' . $sig . ')',
                    'detail' => 'Un prélèvement rejeté puis présenté à nouveau par le créancier ne peut être facturé qu’une fois : les frais au-delà du premier rejet (' . money_fmt($total) . ' le ' . fr_date($f['date']) . ') sont remboursables sur demande, preuve par tout moyen.',
                    'refs' => ['D133-6']];
                $excessMonth += $total;
            } elseif ($total > $cap) {
                $ex = $total - $cap;
                $opps[] = ['type' => 'droit', 'cle' => 'rejet:' . $f['id'], 'mois' => $ym, 'montant' => $ex, 'courrier' => 'frais_plafond', 'tx_ids' => $ids,
                    'titre' => 'Rejet de prélèvement facturé ' . money_fmt($total) . ' (plafond ' . money_fmt($cap) . ')',
                    'detail' => 'Pour un même incident, l’ensemble des sommes facturées (frais de rejet, commission d’intervention, lettre…, « quelle que soit leur dénomination ») ne peut dépasser le montant du prélèvement rejeté, dans la limite de 20 €.' . ($order === null ? ' Montant du prélèvement rejeté non retrouvé : si celui-ci était inférieur à 20 €, le plafond est encore plus bas.' : ''),
                    'motif' => 'Or l’article D133-6 du Code monétaire et financier dispose que les frais perçus à l’occasion du rejet d’un ordre de paiement ne peuvent excéder le montant de l’ordre rejeté, dans la limite de 20 euros, et que ces frais comprennent l’ensemble des sommes facturées au titulaire du compte, quelles que soient leur dénomination et leur justification. Pour cet incident du ' . fr_date($f['date']) . ', vous m’avez facturé ' . money_fmt($total) . ', soit ' . money_fmt($ex) . ' au-delà du plafond' . ($order !== null ? ' (montant de l’ordre rejeté : ' . money_fmt($order) . ')' : '') . '.',
                    'refs' => ['D133-6']];
                $excessMonth += $ex;
            } elseif ($order === null && $total > 0) {
                $M['notes'][] = 'Rejet du ' . fr_date($f['date']) . ' : si le prélèvement rejeté était inférieur à ' . money_fmt($total) . ', les frais ne pouvaient pas dépasser son montant (art. D133-6).';
            }
        }

        // 2. chèques rejetés : forfait tout compris 30 € (chèque ≤ 50 €) ou 50 € — art. D131-25 CMF
        foreach ($items as $f) {
            if ($f['fee'] !== 'rejet_cheque' || isset($used[$f['id']])) continue;
            $group = [$f]; $used[$f['id']] = true;
            foreach ($items as $g) if (!isset($used[$g['id']]) && in_array($g['fee'], ['intervention', 'lettre', 'incident_divers'], true) && abs(days_between($f['date'], $g['date'])) <= 2) { $group[] = $g; $used[$g['id']] = true; }
            $total = 0; foreach ($group as $g) $total += -(int)$g['amount'];
            $ids = array_map(function ($g) { return (int)$g['id']; }, $group);
            if ($total > LEGAL_CHEQUE_HAUT) {
                $opps[] = ['type' => 'droit', 'cle' => 'cheque:' . $f['id'], 'mois' => $ym, 'montant' => $total - LEGAL_CHEQUE_HAUT, 'courrier' => 'frais_plafond', 'tx_ids' => $ids,
                    'titre' => 'Chèque rejeté facturé ' . money_fmt($total) . ' (plafond 50 €)', 'detail' => 'Le forfait de rejet de chèque comprend la lettre d’information préalable, l’injonction et la commission d’intervention : 50 € au plus, 30 € si le chèque faisait 50 € ou moins.',
                    'motif' => 'Or l’article D131-25 du Code monétaire et financier plafonne à 50 euros (30 euros pour un chèque d’un montant inférieur ou égal à 50 euros) l’ensemble des frais liés au rejet d’un chèque, lettre d’information préalable et commission d’intervention comprises. Vous m’avez facturé ' . money_fmt($total) . ' pour ce rejet.', 'refs' => ['D131-25']];
                $excessMonth += $total - LEGAL_CHEQUE_HAUT;
            } elseif ($total > LEGAL_CHEQUE_BAS) {
                $opps[] = ['type' => 'a_verifier', 'cle' => 'cheque:' . $f['id'], 'mois' => $ym, 'montant' => $total - LEGAL_CHEQUE_BAS, 'courrier' => 'frais_plafond', 'tx_ids' => $ids,
                    'titre' => 'Chèque rejeté facturé ' . money_fmt($total) . ' : trop si le chèque faisait 50 € ou moins', 'detail' => 'Plafond de 30 € tout compris pour un chèque de 50 € ou moins (50 € au-delà). Un chèque représenté dans les 30 jours compte pour un seul incident.',
                    'motif' => 'Or l’article D131-25 du Code monétaire et financier plafonne à 30 euros l’ensemble des frais liés au rejet d’un chèque d’un montant inférieur ou égal à 50 euros, lettre d’information préalable et commission d’intervention comprises. Le chèque rejeté était de [montant du chèque] ; vous m’avez facturé ' . money_fmt($total) . '.', 'refs' => ['D131-25']];
            }
        }

        // 3. commissions d'intervention hors rejets : 8 €/opération et 80 €/mois (4 € et 20 € avec l'offre spécifique) — art. R312-4-1 CMF
        $ci = array_values(array_filter($items, function ($f) use ($used) { return $f['fee'] === 'intervention' && !isset($used[$f['id']]); }));
        $perOp = 0; $capped = 0; $ids = [];
        foreach ($ci as $f) { $v = -(int)$f['amount']; $ids[] = (int)$f['id']; if ($v > $capOp) $perOp += $v - $capOp; $capped += min($v, $capOp); }
        $overMonth = max(0, $capped - $capMonth);
        if ($perOp + $overMonth > 0) {
            $opps[] = ['type' => 'droit', 'cle' => 'ci:' . $ym, 'mois' => $ym, 'montant' => $perOp + $overMonth, 'courrier' => 'frais_plafond', 'tx_ids' => $ids,
                'titre' => 'Commissions d’intervention au-delà du plafond (' . fr_month($ym) . ')',
                'detail' => 'Plafond légal : ' . money_fmt($capOp) . ' par opération et ' . money_fmt($capMonth) . ' par mois' . ($lowIC ? ' (offre spécifique / services bancaires de base)' : '') . '.',
                'motif' => 'Or l’article R312-4-1 du Code monétaire et financier plafonne les commissions perçues pour le traitement des irrégularités de fonctionnement du compte à ' . money_fmt($capOp) . ' par opération et ' . money_fmt($capMonth) . ' par mois. Les commissions facturées en ' . fr_month($ym) . ' dépassent ce plafond de ' . money_fmt($perOp + $overMonth) . '.', 'refs' => ['R312-4-1']];
            $excessMonth += $perOp + $overMonth;
        }

        // 4. frais de saisie : 10 % de la somme due au Trésor, 100 € au plus
        foreach ($items as $f) if ($f['fee'] === 'saisie' && -(int)$f['amount'] > LEGAL_SAISIE_MAX) {
            $ex = -(int)$f['amount'] - LEGAL_SAISIE_MAX;
            $opps[] = ['type' => 'droit', 'cle' => 'saisie:' . $f['id'], 'mois' => $ym, 'montant' => $ex, 'courrier' => 'frais_plafond', 'tx_ids' => [(int)$f['id']],
                'titre' => 'Frais de saisie supérieurs à 100 €', 'detail' => 'Les frais bancaires d’une saisie administrative à tiers détenteur sont plafonnés à 10 % de la somme due au Trésor public, et à 100 € au plus.',
                'motif' => 'Or les frais bancaires afférents à une saisie administrative à tiers détenteur ne peuvent excéder 10 % du montant dû au Trésor public, dans la limite de 100 euros (article L262 du Livre des procédures fiscales). Vous m’avez facturé ' . money_fmt(-(int)$f['amount']) . ' le ' . fr_date($f['date']) . '.', 'refs' => ['L262-LPF']];
            $excessMonth += $ex;
        }

        // 5. plafond global des clients fragiles : 25 €/mois (20 €/mois et 200 €/an avec l'offre spécifique)
        $incTotal = $M['incident'];
        if ($capFragile !== null && $incTotal > $capFragile) {
            $ex = $incTotal - $capFragile - $excessMonth;
            if ($ex > 0) {
                $opps[] = ['type' => 'droit', 'cle' => 'fragile:' . $ym, 'mois' => $ym, 'montant' => $ex, 'courrier' => 'frais_plafond', 'tx_ids' => array_map(function ($i) { return $i['id']; }, array_filter($M['items'], function ($i) { return $i['famille'] === 'incident'; })),
                    'titre' => 'Frais d’incidents au-delà du plafond « client fragile » (' . fr_month($ym) . ')', 'detail' => 'Vous avez indiqué être reconnu(e) en situation de fragilité financière : vos frais d’incidents sont plafonnés à ' . money_fmt($capFragile) . ' par mois.',
                    'motif' => 'Étant reconnu(e) en situation de fragilité financière, je bénéficie du plafonnement des frais d’irrégularités et d’incidents prévu aux articles L312-1-3 et R312-4-3 du Code monétaire et financier, soit ' . money_fmt($capFragile) . ' par mois. Les frais facturés en ' . fr_month($ym) . ' s’élèvent à ' . money_fmt($incTotal) . '.', 'refs' => ['L312-1-3', 'R312-4-3']];
                $excessMonth += $ex;
            }
        }

        // 6. cinq incidents dans le mois : critère réglementaire de fragilité financière
        if ($capFragile === null && $M['n_incidents'] >= LEGAL_FRAGILE_INCIDENTS) {
            $opps[] = ['type' => 'fragilite', 'cle' => 'fragilite:' . $ym, 'mois' => $ym, 'montant' => max(0, $incTotal - LEGAL_FRAGILE_MOIS - $excessMonth), 'courrier' => 'fragilite', 'tx_ids' => array_map(function ($i) { return $i['id']; }, $M['items']),
                'titre' => $M['n_incidents'] . ' incidents en ' . fr_month($ym) . ' : demandez le statut de client « fragile »',
                'detail' => 'Au moins 5 irrégularités ou incidents dans un même mois : la banque doit vous considérer en situation de fragilité financière pendant au moins 3 mois. Vos frais d’incidents sont alors plafonnés à 25 €/mois et la banque doit vous proposer l’offre spécifique (3 €/mois au plus). Montant indiqué : ce que ce plafond ferait récupérer EN PLUS des réclamations de droit ci-dessus.',
                'refs' => ['L312-1-3', 'R312-4-3']];
        }

        // 7. le reste : demande de geste commercial (aucune obligation légale de rembourser des frais conformes)
        $remaining = $incTotal + $M['agios'] - $excessMonth;
        if ($remaining > 0) {
            $opps[] = ['type' => 'geste', 'cle' => 'geste:' . $ym, 'mois' => $ym, 'montant' => $remaining, 'courrier' => 'frais_geste', 'tx_ids' => array_map(function ($i) { return $i['id']; }, array_filter($M['items'], function ($i) { return in_array($i['famille'], ['incident', 'agios'], true); })),
                'titre' => 'Demander le remboursement à titre commercial (' . fr_month($ym) . ')',
                'detail' => 'Ces frais respectent les plafonds : la banque n’est pas obligée de les rendre, mais un geste est souvent accordé sur demande, surtout pour un premier incident ou un client fidèle. Vérifiez aussi qu’ils figuraient sur un relevé au moins 14 jours avant d’être prélevés (art. L312-1-5) : sinon ils sont contestables.',
                'refs' => ['L312-1-5']];
        }
        $M['a_reclamer'] = $excessMonth;
    }
    unset($M);

    // --- frais de service évitables
    foreach ($service as $type => $s) {
        if ($type === 'assurance_moyens') {
            $opps[] = ['type' => 'service', 'cle' => 'assurance_moyens', 'mois' => null, 'montant' => $s['total'], 'courrier' => 'assurance_moyens', 'tx_ids' => [],
                'titre' => 'Assurance « moyens de paiement » : ' . money_fmt($s['total']) . ' sur 12 mois', 'detail' => 'La loi limite déjà votre perte à 50 € avant opposition en cas de perte ou de vol, à 0 € après opposition, et à 0 € pour un paiement frauduleux à distance. Cette assurance couvre surtout ce que la loi protège déjà : comparez avant de la garder.', 'refs' => ['L133-19']];
        }
        if ($type === 'virement_frais') {
            $opps[] = ['type' => 'service', 'cle' => 'virement_frais', 'mois' => null, 'montant' => $s['total'], 'courrier' => 'virement_instantane', 'tx_ids' => [],
                'titre' => 'Frais de virement : ' . money_fmt($s['total']) . ' sur 12 mois', 'detail' => 'Depuis le 9 janvier 2025, un virement instantané en euros ne peut pas coûter plus cher qu’un virement classique (règlement UE 2024/886). Si vos virements classiques sont gratuits, les instantanés aussi.', 'refs' => ['UE-2024-886']];
        }
    }

    krsort($months);
    $order = ['droit' => 0, 'fragilite' => 1, 'a_verifier' => 2, 'geste' => 3, 'service' => 4];
    usort($opps, function ($a, $b) use ($order) { return ($order[$a['type']] <=> $order[$b['type']]) ?: strcmp((string)$b['mois'], (string)$a['mois']); });
    $year['total'] = $year['incident'] + $year['agios'] + $year['saisie'] + $year['service'] + $year['evitable'];
    $droit = 0; foreach ($opps as $o) if ($o['type'] === 'droit') $droit += $o['montant'];
    return ['months' => $months, 'opportunites' => $opps, 'annee' => $year, 'services' => array_values($service), 'a_reclamer' => $droit,
        'plafonds' => ['ci_op' => $capOp, 'ci_mois' => $capMonth, 'fragile' => $capFragile, 'statut' => $status]];
}

/* ========================================================================== */
/* Score de bien-être financier (0 à 100)                                      */
/* ========================================================================== */

function an_score(array $s, array $o): array {
    $comp = [];
    $frais = (int)($s['frais']['incident'] ?? 0) + (int)($s['frais']['agios'] ?? 0) + (int)($s['frais']['saisie'] ?? 0);
    $pts = $frais === 0 ? 20 : ($frais <= 1000 ? 14 : ($frais <= 3000 ? 8 : 0));
    $comp[] = ['id' => 'frais', 'label' => 'Frais d’incidents et agios', 'points' => $pts, 'max' => 20, 'valeur' => $frais === 0 ? 'aucun' : money_fmt($frais),
        'conseil' => $frais === 0 ? 'Aucun frais d’incident : c’est la base d’un compte serein.' : 'Chaque euro de frais d’incident est perdu sans contrepartie : alerte de solde, virement d’équilibrage, prélèvements après la paie.'];

    $t = $s['taux_epargne'];
    $pts = $t === null ? 10 : ($t >= 15 ? 20 : ($t >= 10 ? 16 : ($t >= 5 ? 12 : ($t >= 0 ? 6 : 0))));
    $comp[] = ['id' => 'epargne', 'label' => 'Ce qui reste en fin de mois', 'points' => $pts, 'max' => 20, 'valeur' => $t === null ? 'revenus inconnus' : str_replace(['.', '-'], [',', '−'], (string)$t) . ' % des revenus',
        'conseil' => $t !== null && $t < 10 ? 'Visez 10 % : programmez un virement automatique vers l’épargne le lendemain de la paie (« se payer en premier »).' : 'Bon rythme. Gardez le virement automatique vers l’épargne.'];

    $r = $s['revenus'] > 0 ? $s['nature']['essentiel'] / $s['revenus'] * 100 : null;
    $pts = $r === null ? 10 : ($r <= 50 ? 20 : ($r <= 60 ? 15 : ($r <= 70 ? 10 : ($r <= 85 ? 5 : 0))));
    $comp[] = ['id' => 'essentiel', 'label' => 'Poids des dépenses essentielles', 'points' => $pts, 'max' => 20, 'valeur' => $r === null ? '—' : round($r) . ' % des revenus',
        'conseil' => $r !== null && $r > 60 ? 'Au-delà de 60 %, la marge est mince : renégociez assurances, box, énergie et vérifiez vos droits (aides au logement, prime d’activité).' : 'Vos charges essentielles laissent de la marge.'];

    $ev = $s['depenses'] > 0 ? $s['nature']['evitable'] / $s['depenses'] * 100 : 0;
    $pts = $ev <= 0.1 ? 20 : ($ev <= 2 ? 16 : ($ev <= 5 ? 11 : ($ev <= 10 ? 6 : 0)));
    $comp[] = ['id' => 'evitable', 'label' => 'Dépenses évitables', 'points' => $pts, 'max' => 20, 'valeur' => str_replace('.', ',', (string)round($ev, 1)) . ' % des dépenses',
        'conseil' => $ev > 2 ? 'Frais, agios, amendes, jeux d’argent : c’est la part à faire tomber en premier, elle n’apporte rien.' : 'Presque rien d’évitable : bravo.'];

    $cush = $o['epargne_dispo'] ?? null; $ess = (int)($o['essentiel_moyen'] ?? 0);
    $months = ($cush !== null && $ess > 0) ? $cush / $ess : null;
    $pts = $months === null ? 0 : ($months >= 3 ? 20 : ($months >= 2 ? 15 : ($months >= 1 ? 10 : ($months > 0 ? 5 : 0))));
    $comp[] = ['id' => 'coussin', 'label' => 'Épargne de précaution', 'points' => $pts, 'max' => 20,
        'valeur' => $months === null ? 'non renseignée' : str_replace('.', ',', (string)round($months, 1)) . ' mois de dépenses essentielles',
        'conseil' => $months === null ? 'Indiquez dans Réglages ce que vous avez de côté (livret…) : l’objectif est 3 mois de dépenses essentielles.' : ($months < 3 ? 'Objectif : 3 mois de dépenses essentielles sur un livret disponible. C’est ce qui transforme un imprévu en simple contrariété.' : 'Coussin de sécurité solide.')];

    $total = 0; foreach ($comp as $c) $total += $c['points'];
    $niv = $total >= 80 ? 'Serein' : ($total >= 60 ? 'Solide' : ($total >= 40 ? 'Fragile' : 'Sous tension'));
    return ['total' => $total, 'niveau' => $niv, 'composantes' => $comp];
}

/* ========================================================================== */
/* Projection du solde jusqu'à la prochaine paie                               */
/* ========================================================================== */

/**
 * $start : ['solde' => centimes, 'date' => 'Y-m-d', 'source' => 'saisi'|'releve']
 * Rend la courbe jour par jour, les échéances prévues, le point bas et « ce qui reste à dépenser par jour ».
 */
function an_forecast(array $txs, array $recurring, array $profile, ?array $start, string $today): ?array {
    if ($start === null) return null;
    $from = $start['date'];
    if ($from > $today) $today = $from;
    $stale = days_between($from, $today) > 10;
    // salaire : jour et montant habituels
    $sal = array_values(array_filter($txs, function ($t) { return (int)$t['amount'] > 0 && $t['category'] === 'salaire'; }));
    $byMonth = [];
    foreach ($sal as $t) $byMonth[substr($t['date'], 0, 7)][] = $t;
    $payday = (int)($profile['jour_paie'] ?? 0);
    $salary = 0;
    if ($byMonth) {
        ksort($byMonth);
        $sums = []; $days = [];
        foreach (array_slice($byMonth, -3) as $list) { $s = 0; foreach ($list as $t) { $s += (int)$t['amount']; $days[] = (int)substr($t['date'], 8, 2); } $sums[] = $s; }
        $salary = (int)round(median($sums));
        if (!$payday) $payday = (int)round(median($days));
    }
    // dépenses variables quotidiennes (hors charges récurrentes), sur 90 jours au plus
    $recKeys = [];
    foreach ($recurring as $r) if ($r['actif']) $recKeys[$r['key']] = true;
    $last = an_last_date($txs) ?? $today;
    $win = date_add_days($last, -89);
    $var = 0;
    foreach ($txs as $t) {
        if ($t['date'] < $win || (int)$t['amount'] >= 0 || !in_array($t['nature'], SPEND_NATURES, true)) continue;
        if (isset($recKeys[merchant_key((string)$t['merchant'])])) continue;
        $var += -(int)$t['amount'];
    }
    $firstDate = null; foreach ($txs as $t) if ($firstDate === null || $t['date'] < $firstDate) $firstDate = $t['date'];
    $span = $firstDate ? max(30, min(90, days_between(max($firstDate, $win), $last) + 1)) : 90;
    $daily = (int)round($var / $span);

    // horizon : la première paie APRÈS aujourd'hui (sinon 30 jours)
    $end = null;
    if ($payday >= 1 && $payday <= 31) {
        for ($i = 1; $i <= 400; $i++) {
            $d = date_add_days($from, $i);
            if ($d <= $today) continue;
            if ((int)substr($d, 8, 2) === min($payday, days_in_month(substr($d, 0, 7)))) { $end = $d; break; }
        }
    }
    if ($end === null) $end = date_add_days($today, 30);

    $bal = (int)$start['solde'];
    $balToday = $from === $today ? $bal : null;
    $points = [['date' => $from, 'solde' => $bal]];
    $events = [];
    $min = null; $minDate = null; $firstNeg = null; $fixedAfter = 0;
    $n = days_between($from, $end);
    $lastSeen = [];
    foreach ($recurring as $r) $lastSeen[$r['key']] = $r['dernier'];
    for ($i = 1; $i <= $n; $i++) {
        $d = date_add_days($from, $i);
        $dom = (int)substr($d, 8, 2); $dim = days_in_month(substr($d, 0, 7));
        $bal -= $daily;
        foreach ($recurring as $r) {
            if (!$r['actif']) continue;
            $due = $r['periode'] === 'mensuel' ? (min($r['jour'], $dim) === $dom && $lastSeen[$r['key']] < date_add_days($d, -20)) : ($r['prochain'] === $d);
            if (!$due) continue;
            $lastSeen[$r['key']] = $d;
            $bal -= $r['montant'];
            if ($d > $today) { $fixedAfter += $r['montant']; $events[] = ['date' => $d, 'label' => $r['merchant'], 'montant' => -$r['montant'], 'categorie' => $r['category']]; }
        }
        if ($salary > 0 && $payday && $dom === min($payday, $dim)) {
            $bal += $salary;
            if ($d > $today) $events[] = ['date' => $d, 'label' => 'Salaire (habituel)', 'montant' => $salary, 'categorie' => 'salaire'];
        }
        if ($d === $today) $balToday = $bal;
        if ($d >= $today) {
            if ($min === null || $bal < $min) { $min = $bal; $minDate = $d; }
            if ($firstNeg === null && $bal < 0) $firstNeg = $d;
        }
        $points[] = ['date' => $d, 'solde' => $bal];
    }
    if ($balToday === null) $balToday = $bal;
    $daysLeft = max(1, days_between($today, $end));
    $safe = (int)floor(($balToday - $fixedAfter) / $daysLeft);
    return ['depart' => $start, 'aujourdhui' => $today, 'solde_estime' => $balToday, 'fin' => $end, 'jour_paie' => $payday ?: null, 'salaire' => $salary, 'quotidien' => $daily,
        'charges_fixes' => $fixedAfter, 'point_bas' => $min ?? $bal, 'date_point_bas' => $minDate ?? $end, 'premier_negatif' => $firstNeg, 'par_jour' => $safe, 'jours' => $daysLeft,
        'echeances' => $events, 'courbe' => $points, 'ancien' => $stale];
}

/* ========================================================================== */
/* Équité dans le couple                                                       */
/* ========================================================================== */

/** $people : [['id','nom','revenus','communes','verse','recu']] — versements entre vous deux inclus. */
function an_equity(array $people, string $mode = 'prorata'): array {
    $totRev = 0; $totCom = 0;
    foreach ($people as $p) { $totRev += max(0, $p['revenus']); $totCom += $p['communes']; }
    $out = [];
    foreach ($people as $p) {
        $share = ($mode === 'prorata' && $totRev > 0) ? max(0, $p['revenus']) / $totRev : 1 / max(1, count($people));
        $contrib = $p['communes'] + $p['verse'] - $p['recu'];
        $target = (int)round($totCom * $share);
        $out[] = $p + ['part' => round($share * 100, 1), 'contribution' => $contrib, 'cible' => $target, 'ecart' => $contrib - $target];
    }
    $settle = null;
    if (count($out) === 2 && $totCom > 0) {
        [$a, $b] = $out;
        if (abs($a['ecart']) >= 100) $settle = $a['ecart'] > 0 ? ['de' => $b['nom'], 'a' => $a['nom'], 'montant' => $a['ecart']] : ['de' => $a['nom'], 'a' => $b['nom'], 'montant' => -$a['ecart']];
    }
    return ['mode' => $mode, 'total_communes' => $totCom, 'personnes' => $out, 'reequilibrage' => $settle];
}
