<?php
/**
 * Boussole — tests automatiques (ligne de commande uniquement) : php tests/run-tests.php
 * Vérifie la lecture des relevés, la catégorisation, les plafonds de frais, les alertes,
 * les courriers et le chiffrement, sur des relevés FICTIFS (dossier tests/samples).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
define('BOUSSOLE', 'test');
date_default_timezone_set('Europe/Paris');
$root = dirname(__DIR__);
foreach (['util', 'categorize', 'import', 'legal', 'analyse', 'alertes', 'vault', 'mail', 'zip'] as $f) require $root . '/lib/' . $f . '.php';

$pass = 0; $fail = 0;
function ok($cond, string $what): void { global $pass, $fail; if ($cond) { $pass++; } else { $fail++; echo "  ✗ $what\n"; } }
function section(string $t): void { echo "• $t\n"; }

section('Montants et dates');
ok(money_parse('1 234,56') === 123456, '1 234,56');
ok(money_parse('1.234,56') === 123456, '1.234,56');
ok(money_parse('-12,30') === -1230, '-12,30');
ok(money_parse('12,30-') === -1230, '12,30-');
ok(money_parse('(12,30)') === -1230, '(12,30)');
ok(money_parse('1,234.56') === 123456, '1,234.56');
ok(money_parse('45,00 D') === -4500, '45,00 D');
ok(money_parse('8,00 €') === 800, '8,00 €');
ok(money_parse('abc') === null, 'abc');
ok(date_parse_any('03/10/2026') === '2026-10-03', 'date FR');
ok(date_parse_any('2026-10-03 14:22:11') === '2026-10-03', 'date ISO');
ok(date_parse_any('3 oct. 2026') === '2026-10-03', 'date en lettres');
ok(date_parse_any('20261003') === '2026-10-03', 'date OFX');
ok(money_fmt(-123456) === "−1\u{202F}234,56\u{00A0}€", 'format monétaire');

section('Relevé texte / PDF mis en page');
$r = imp_parse_file(file_get_contents(__DIR__ . '/samples/releve-texte.txt'), 'releve.txt');
ok(count($r['rows']) === 22, '22 opérations lues (lu : ' . count($r['rows']) . ')');
ok($r['check']['status'] === 'ok', 'soldes cohérents (' . $r['check']['status'] . ')');
ok(($r['meta']['opening'] ?? null) === 41235 && ($r['meta']['closing'] ?? null) === 65949, 'soldes initial et final');
ok(($r['meta']['period_start'] ?? '') === '2026-10-01', 'période lue');
$labels = array_column($r['rows'], 'label');
ok(in_array('VIR SEPA RECU /DE EXEMPLE SAS /MOTIF SALAIRE OCTOBRE 2026', $labels, true), 'libellé sur deux lignes recollé');

section('Export CSV 3 mois (Windows-1252, préambule, Débit / Crédit)');
$c = imp_parse_file(file_get_contents(__DIR__ . '/samples/export-3-mois.csv'), 'export.csv');
ok($c['format'] === 'csv', 'format CSV');
ok(count($c['rows']) === 59, '59 opérations (lu : ' . count($c['rows']) . ')');
ok($c['rows'][0]['amount'] === 185000, 'crédit positif');
ok(preg_match('//u', implode('', array_column($c['rows'], 'label'))) === 1, 'texte converti en UTF-8');

section('OFX et QIF');
$o = imp_parse_file(file_get_contents(__DIR__ . '/samples/exemple.ofx'), 'exemple.ofx');
ok(count($o['rows']) === 3 && $o['rows'][2]['amount'] === -800, 'OFX : 3 opérations, montant à virgule');
ok(($o['meta']['closing'] ?? null) === 147435, 'OFX : solde');
$q = imp_parse_file(file_get_contents(__DIR__ . '/samples/exemple.qif'), 'exemple.qif');
ok(count($q['rows']) === 3 && $q['rows'][0]['date'] === '2026-10-03', 'QIF : 3 opérations, date jour/mois');

section('Catégorisation');
$ctx = ['partner' => alias_list(['Sam Martin']), 'me' => alias_list(['Alex Martin']), 'employer' => [], 'common_cats' => COMMON_DEFAULT];
$k = function (string $l, int $a) use ($ctx) { return cat_classify($l, $a, $ctx); };
ok($k('PRLV SEPA EDF clients particuliers', -8900)['category'] === 'energie', 'EDF → énergie');
ok($k('CB CASINO BARRIERE ENGHIEN', -5000)['category'] === 'jeux_argent', 'Casino Barrière → jeux');
ok($k('CB GEANT CASINO MONTPELLIER', -5000)['category'] === 'courses', 'Géant Casino → courses');
ok($k('CB UBER EATS', -2000)['category'] === 'livraison' && $k('CB UBER TRIP', -1500)['category'] === 'vtc_taxi', 'Uber Eats ≠ Uber');
ok($k('PRLV SEPA TOTALENERGIES ELECTRICITE', -6000)['category'] === 'energie' && $k('CB TOTALENERGIES STATION', -6000)['category'] === 'carburant', 'TotalEnergies : électricité ou carburant');
ok($k('VIR SEPA EMIS VERS SAM MARTIN', -30000)['category'] === 'virement_foyer', 'virement au partenaire');
ok($k('VIR SEPA RECU DE AMAZON REMBOURSEMENT ALEX MARTIN', 2500)['category'] === 'remboursement', 'remboursement mentionnant son propre nom');
ok($k('VIR SEPA EMIS VERS LIVRET A', -10000)['category'] === 'epargne', 'livret → épargne');
ok($k('VIR SEPA RECU DE LIVRET A', 10000)['nature'] === 'transfert', 'retour d’épargne ≠ revenu');
ok($k('COMMISSION D INTERVENTION', -800)['fee'] === 'intervention', 'commission d’intervention');
ok($k('FRAIS REJET PRLV SEPA EDF', -2000)['fee'] === 'rejet_prlv', 'frais de rejet');
ok($k('PRLV SEPA FRAIS DE SCOLARITE', -15000)['fee'] === null, 'pas de faux frais bancaire');
ok($k('FRAIS RETRAIT DAB AUTRE BANQUE', -100)['fee'] === 'retrait', 'frais de retrait');
ok($k('COTISATION CARTE VISA PREMIER', -1100)['fee'] === 'cotisation_carte', 'cotisation carte');
ok($k('REMBOURSEMENT FRAIS COMMISSION', 800)['flags'] === ['remb_frais'], 'frais remboursés détectés');
ok($k('PAIEMENT PAR CARTE X1234 KLARNA*ZALANDO', -2999)['category'] === 'bnpl', 'Klarna → paiement fractionné');
ok($k('PRLV SEPA CETELEM RESERVE D ARGENT', -9000)['flags'] === ['revolving'], 'crédit renouvelable signalé');

section('Analyses sur 3 mois');
$txs = []; $id = 0;
foreach ($c['rows'] as $row) {
    $cl = cat_classify($row['label'], $row['amount'], $ctx);
    $txs[] = ['id' => ++$id, 'user_id' => 1, 'date' => $row['date'], 'amount' => $row['amount'], 'label' => $row['label'], 'norm' => $cl['norm'], 'merchant' => $cl['merchant'],
        'kind' => $cl['kind'], 'category' => $cl['category'], 'nature' => $cl['nature'], 'fee' => (string)$cl['fee'], 'flags' => implode(',', $cl['flags']), 'common' => $cl['common'], 'manual' => 0, 'note' => ''];
}
$s = an_summary(an_month($txs, '2026-09'));
ok($s['revenus'] === 185000 && $s['vers_partenaire'] === 30000 && $s['epargne'] === 10000, 'bilan de septembre');
$rec = an_recurring($txs);
$byKey = []; foreach ($rec as $x) $byKey[$x['key']] = $x;
ok(isset($byKey['NETFLIX']) && $byKey['NETFLIX']['periode'] === 'mensuel', 'Netflix mensuel détecté');
ok(isset($byKey['NETFLIX']['hausse']['pct']) && $byKey['NETFLIX']['hausse']['pct'] > 18, 'hausse Netflix détectée');
ok(($byKey['NETFLIX']['montant'] ?? 0) === 1599, 'après une hausse, le prix actuel est retenu (15,99 €)');
ok(empty($byKey['LIVRET']['nouveau']), 'pas « nouveau » sans historique antérieur');
ok(isset($byKey['EDF CLIENTS']) && $byKey['EDF CLIENTS']['hausse'] !== null, 'hausse EDF détectée');
ok(!isset($byKey['CARREFOUR CITY']), 'les courses ne sont pas un abonnement');

$f = an_fees($txs, ['fragile' => 'non']);
$types = []; foreach ($f['opportunites'] as $op) $types[$op['type']][] = $op;
$droit = array_sum(array_column($types['droit'] ?? [], 'montant'));
ok(count($types['droit'] ?? []) === 2, 'deux réclamations de droit (rejet au-delà de 20 € + représentation)');
ok($droit === 800 + 2900, 'montant exigible : 37,00 € (lu : ' . money_fmt($droit) . ')');
ok(isset($types['fragilite']), 'critère des 5 incidents dans le mois');
ok(($types['fragilite'][0]['montant'] ?? null) === 8100 - 2500 - 3700, 'statut fragile : seulement le supplément (19,00 €), sans double compte');
ok(has((string)($types['droit'][0]['motif'] ?? ''), 'article D133-6') && !has((string)$types['droit'][0]['motif'], 'non retrouvé'), 'motif formel pour le courrier, sans note interne');
$f2 = an_fees($txs, ['fragile' => 'fragile']);
ok($f2['plafonds']['fragile'] === 2500, 'plafond client fragile appliqué');

$al = alerts_build($txs, ['profile' => [], 'fees' => $f, 'recurring' => $rec, 'forecast' => null, 'docs' => [], 'budgets' => ['restaurants' => 5000], 'subs' => [], 'today' => '2026-11-06']);
$keys = array_column($al, 'cle');
$has = function (string $prefix) use ($keys) { foreach ($keys as $k) if (strpos($k, $prefix) === 0) return true; return false; };
foreach (['frais:rejet:', 'frais:represente:', 'frais:fragilite:', 'doublon:', 'nouveau_creancier:INCONNUE', 'micro:', 'livraison:2026-10', 'bnpl:2026-10', 'jeux:2026-10', 'hausse:NETFLIX'] as $p) ok($has($p), 'alerte « ' . $p . ' »');
ok(!$has('nouveau_creancier:NETFLIX'), 'pas d’alerte « nouveau » pour un créancier connu');

section('Projection');
$fc = an_forecast($txs, $rec, ['jour_paie' => 1], ['solde' => 15000, 'date' => '2026-11-06', 'source' => 'saisi'], '2026-11-06');
ok($fc !== null && $fc['fin'] === '2026-12-01', 'horizon : prochaine paie');
ok($fc['premier_negatif'] !== null, 'découvert anticipé avec 150 € au 6 novembre');
$fc2 = an_forecast($txs, $rec, ['jour_paie' => 1], ['solde' => 300000, 'date' => '2026-11-06', 'source' => 'saisi'], '2026-11-06');
ok($fc2['premier_negatif'] === null && $fc2['par_jour'] > 0, 'pas de découvert avec 3 000 €');

section('Équité du couple');
$e = an_equity([['id' => 1, 'nom' => 'A', 'revenus' => 300000, 'communes' => 100000, 'verse' => 0, 'recu' => 0], ['id' => 2, 'nom' => 'B', 'revenus' => 100000, 'communes' => 0, 'verse' => 0, 'recu' => 0]], 'prorata');
ok($e['reequilibrage'] !== null && $e['reequilibrage']['de'] === 'B' && $e['reequilibrage']['montant'] === 25000, 'prorata des revenus : B verse 250 € à A');

section('Courriers');
foreach (array_keys(letters_catalog()) as $lid) {
    $l = letter_render($lid, ['items' => [['date' => '2026-10-10', 'label' => 'COMMISSION', 'montant' => 800]], 'montant' => 800, 'mois' => '2026-10', 'date' => '2026-10-10', 'label' => 'X', 'creancier' => 'Y'], ['nom_complet' => 'Alex Martin', 'adresse' => '1 rue Exemple', 'compte' => '…5678']);
    ok($l['objet'] !== '' && strlen($l['corps']) > 200 && has($l['corps'], 'Alex Martin'), 'courrier ' . $lid);
}

section('E-mails');
[$hd, , $full] = mail_build('alertes@exemple.fr', 'Boussole', "victime@exemple.fr\r\nBcc: espion@exemple.fr", "Frais d’incidents\r\nBcc: espion@exemple.fr", 'texte', '<p>html</p>');
ok(!preg_match('/^Bcc:/mi', $full), 'injection d’en-tête bloquée (destinataire et objet)');
ok(has($full, 'Subject: =?UTF-8?B?'), 'objet accentué encodé (RFC 2047)');
ok(has($hd, 'Auto-Submitted: auto-generated'), 'marqué comme message automatique');
ok(valid_email('prenom.nom@exemple.fr') && !valid_email("a@b.fr\nX") && !valid_email('pas-une-adresse'), 'validation des adresses');
[$sub, $txt] = mail_alerts('Alex', [['niveau' => 'critique', 'titre' => 'Rejet facturé 28,00 € : 8,00 € à récupérer']], 'https://exemple.fr/finances/#/alertes', 'discret');
ok(!preg_match('/\d+,\d{2}/', $txt . $sub), 'mode discret : aucun montant dans l’e-mail');
[$sub2, $txt2] = mail_alerts('Alex', [['niveau' => 'critique', 'titre' => 'Rejet facturé 28,00 €'], ['niveau' => 'attention', 'titre' => 'X']], 'https://exemple.fr/', 'titres');
ok(has($sub2, '2 nouvelles alertes dont 1 critique') && has($txt2, 'Rejet facturé'), 'récapitulatif des alertes');
ok(has(smtp_hint('535 5.7.139 Authentication unsuccessful, basic authentication is disabled', ['host' => 'smtp-mail.outlook.com']), 'OAuth'), 'refus Microsoft expliqué');

section('Coffre-fort');
$key = random_bytes(32);
$plain = random_bytes(5000) . 'fin';
ok(vault_decrypt(vault_encrypt($plain, $key), $key) === $plain, 'chiffrement aller-retour');
$bad = false; try { vault_decrypt(vault_encrypt($plain, $key), random_bytes(32)); } catch (RuntimeException $e) { $bad = true; }
ok($bad, 'mauvaise clé refusée');
ok(doc_keep_until('impots', '2026-08-20') === '2028-12-31', 'conservation avis d’imposition');
ok(doc_keep_until('releve', '2026-10-31') === '2031-10-31', 'conservation relevé : 5 ans');

section('Coffre-fort : formats acceptés');
$essai = function (string $nom, string $contenu): string {
    $f = tempnam(sys_get_temp_dir(), 'bsl');
    file_put_contents($f, $contenu);
    try { $r = doc_check_upload($f, $nom, strlen($contenu), 1000000); $res = 'ok:' . $r[0]; } catch (RuntimeException $e) { $res = 'refus'; }
    unlink($f);
    return $res;
};
ok($essai('facture.pdf', "%PDF-1.7\n") === 'ok:application/pdf', 'PDF accepté');
ok($essai('faux.pdf', 'bonjour') === 'refus', 'faux PDF refusé');
ok($essai('virus.exe', "MZ\x90\x00") === 'refus' && $essai('facture.pdf.exe', '%PDF') === 'refus' && $essai('script.ps1', 'x') === 'refus', 'programmes et scripts refusés');
ok($essai('photo.jpg', "MZ\x90\x00") === 'refus', 'programme déguisé en photo refusé');
ok(strpos($essai('recu.html', '<html><script>x</script>'), 'ok:') === 0 && strpos($essai('film.mp4', "\0\0\0\x18ftypmp42"), 'ok:') === 0 && strpos($essai('archive.7z', "7z\xBC\xAF"), 'ok:') === 0, 'HTML, vidéo, archive acceptés');
ok(doc_serve_type('recu.html', '<html>') === ['application/octet-stream', false] && doc_serve_type('logo.svg', '<svg>') === ['application/octet-stream', false], 'HTML et SVG toujours en téléchargement');
ok(doc_serve_type('a.pdf', '%PDF-1.4')[1] === true && doc_serve_type('a.jpg', '<html>')[1] === false && doc_serve_type('a.txt', 'texte')[0] === 'text/plain; charset=utf-8', 'affichage seulement si le contenu correspond');

section('Archives ZIP (sans extension zip)');
$fichiers = ['a.txt' => str_repeat('Boussole ', 500), 'dossier/é.bin' => random_bytes(3000), 'vide.txt' => ''];
$lu = zip_entries(zip_build($fichiers));
ok($lu === $fichiers, 'écriture puis relecture identiques (compressé, stocké, nom accentué)');
$flux = fopen('php://memory', 'w+b');
$z = new ZipFlux($flux);
$n1 = $z->add('Paie/octobre.pdf', '%PDF-1');
$n2 = $z->add('Paie/octobre.pdf', '%PDF-2');
$z->finish();
rewind($flux);
$lu2 = zip_entries(stream_get_contents($flux));
ok($n2 === 'Paie/octobre (2).pdf' && ($lu2['Paie/octobre.pdf'] ?? '') === '%PDF-1' && ($lu2['Paie/octobre (2).pdf'] ?? '') === '%PDF-2', 'archive au fil de l’eau, noms en double renommés');
ok(zip_safe_name('Facture : avril/mai 2026 ?') === 'Facture _ avril_mai 2026 _', 'noms de fichiers sans caractères interdits par Windows');

section('Réseau de la maison');
$cas = [['192.168.1.20', ['local'], '', true], ['8.8.8.8', ['local'], '', false], ['100.101.102.103', ['local'], '', true], ['::ffff:192.168.0.4', ['local'], '', true],
    ['fe80::1%12', ['local'], '', true], ['fd12:3456::1', ['local'], '', true], ['2a01:e0a:1:2:aaaa::5', ['local'], '2a01:e0a:1:2::10', true],
    ['2a01:e0a:1:3::5', ['local'], '2a01:e0a:1:2::10', false], ['2001:db8::1', ['local'], '', false], ['203.0.113.7', ['local', '203.0.113.7'], '', true],
    ['203.0.113.8', ['203.0.113.0/30'], '', false], ['203.0.113.3', ['203.0.113.0/30'], '', true], ['1.2.3.4', ['tout'], '', true], ['127.0.0.1', ['203.0.113.0/30'], '', true],
    ['pas-une-ip', ['local'], '', false], ['172.32.0.1', ['local'], '', false]];
foreach ($cas as [$ip, $nets, $srv, $attendu]) ok(net_allowed($ip, $nets, $srv) === $attendu, $ip . ' ' . json_encode($nets) . ($srv ? ' (serveur ' . $srv . ')' : '') . ' → ' . ($attendu ? 'autorisé' : 'refusé'));

echo "\n" . ($fail === 0 ? "✓ " : "✗ ") . $pass . ' réussi(s), ' . $fail . ' échec(s)' . "\n";
exit($fail === 0 ? 0 : 1);
