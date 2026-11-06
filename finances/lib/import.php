<?php
/**
 * Boussole — lecture des relevés bancaires.
 *
 * Formats : CSV (exports de toutes les banques françaises et néobanques), OFX/QFX, QIF,
 * texte collé, et PDF. Les PDF sont lus DANS LE NAVIGATEUR (pdf.js) qui envoie les lignes
 * avec la position horizontale de chaque mot ; ce fichier reconnaît ensuite les colonnes
 * Débit / Crédit / Solde. Si le serveur dispose de « pdftotext », un PDF peut aussi être lu ici.
 *
 * Rien n'est enregistré à ce stade : on rend un APERÇU que la personne vérifie et corrige.
 * Contrôle de cohérence : solde initial + crédits − débits doit égaler le solde final.
 */
if (!defined('BOUSSOLE')) { http_response_code(403); exit; }

const AMOUNT_RE = '/^[-+]?(?:\d{1,3}(?:[ .\x{00A0}\x{202F}]\d{3})+|\d+),\d{2}(?:-)?$|^[-+]?(?:\d{1,3}(?:,\d{3})+|\d+)\.\d{2}(?:-)?$/u';
const DATE_TOKEN_RE = '/^(\d{1,2})[\/.\-](\d{1,2})(?:[\/.\-](\d{2}|\d{4}))?$/';
const CREDIT_HINT_RE = '/\b(VIR SEPA RECU|VIREMENT RECU|VIR RECU|VIREMENT DE|VIR DE|RECU DE|VIR INST RECU|SALAIRE|PAIE|REMBOURSEMENT|REMBOURSEMENTS|REMB|RBT|AVOIR|REMISE CHEQUE|REMISE CHQ|REMISE DE CHEQUE|VERSEMENT|DEPOT|CAF|CPAM|AMELI|PRIME D ACTIVITE|ALLOCATION|ALLOCATIONS|INTERETS CREDITEURS|EXTOURNE|ANNULATION|REFUND|FRANCE TRAVAIL|POLE EMPLOI|PENSION|RETRAITE)\b/';

/* ========================================================================== */
/* Aiguillage                                                                  */
/* ========================================================================== */

function imp_parse_file(string $raw, string $filename): array {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if ($ext === 'pdf' || starts($raw, '%PDF')) return imp_parse_pdf_server($raw);
    // tableurs : Excel .xlsx et OpenDocument .ods (archives ZIP), Excel 97-2003 .xls (fichier composé OLE)
    if (starts($raw, "PK\x03\x04")) {
        $z = zip_entries($raw, 60 * 1024 * 1024);
        if (isset($z['xl/workbook.xml'])) return imp_parse_sheets(fmt_xlsx_sheets($z), 'xlsx');
        if (isset($z['content.xml']) && strpos((string)($z['mimetype'] ?? ''), 'spreadsheet') !== false) return imp_parse_sheets(fmt_ods_sheets($z), 'ods');
        return imp_empty('zip', 'Archive ZIP : décompressez-la, puis importez le relevé qu’elle contient (CSV, OFX, Excel…).');
    }
    if (starts($raw, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) return imp_parse_sheets(fmt_xls_sheets($raw), 'xls');
    $txt = utf8_from_any($raw);
    $head = substr($txt, 0, 4096);
    if (preg_match('/<(\w+:)?(BkToCstmrStmt|BkToCstmrAcctRpt|BkToCstmrDbtCdtNtfctn)\b|camt\.05[234]/', $head)) return imp_finalize(fmt_parse_camt(ltrim($txt, "\xEF\xBB\xBF")));
    if (preg_match('/(^|\n|\{4:)\s*:20:/', $head) && preg_match('/\n:61:/', $txt) && preg_match('/\n:6[02][FM]:/', $txt)) return imp_finalize(fmt_parse_mt940($txt));
    if ($ext === 'ofx' || $ext === 'qfx' || preg_match('/<OFX>|OFXHEADER|<STMTTRN>/i', $head)) return imp_finalize(imp_parse_ofx($txt));
    if ($ext === 'qif' || preg_match('/^\s*!Type:/mi', $head)) return imp_finalize(imp_parse_qif($txt));
    // « .xls » qui est en réalité une page HTML (export de certaines banques)
    if (preg_match('/<table\b/i', $txt) && (in_array($ext, ['xls', 'htm', 'html'], true) || preg_match('/^\s*(<!DOCTYPE|<html|<table)/i', $head))) {
        $r = imp_parse_sheets(fmt_html_tables($txt), 'html');
        if ($r['rows']) return $r;
    }
    if (in_array($ext, ['xml'], true)) return imp_empty('xml', 'Fichier XML non reconnu : seuls les relevés ISO 20022 (camt.052, camt.053, camt.054) sont lus.');
    // un vrai CSV (.csv/.tsv) peut être sans en-tête ; un autre fichier n'est lu comme CSV que si son en-tête est reconnu
    $isCsv = in_array($ext, ['csv', 'tsv', 'xls'], true);
    if ($isCsv || imp_csv_delim(imp_lines($txt)) !== null) {
        $r = imp_parse_csv($txt, $isCsv);
        if ($r['rows']) return imp_finalize($r);
    }
    return imp_parse_text($txt);
}

function imp_empty(string $format, string $warning): array {
    return ['format' => $format, 'rows' => [], 'meta' => [], 'warnings' => [$warning], 'check' => ['status' => 'inconnu', 'detail' => '']];
}

/** Feuilles d'un tableur : la première dont les colonnes sont reconnues ; sinon, colonnes devinées sur la plus grande. */
function imp_parse_sheets(array $sheets, string $format): array {
    $best = null;
    foreach ($sheets as $rows) {
        if (!$rows) continue;
        $r = imp_parse_table($rows, false, $format);
        if ($r['rows']) return imp_finalize($r);
        if ($best === null || count($rows) > count($best)) $best = $rows;
    }
    if ($best !== null) {
        $r = imp_parse_table($best, true, $format);
        if ($r['rows']) return imp_finalize($r);
    }
    $nom = ['xlsx' => 'classeur Excel', 'xls' => 'classeur Excel', 'ods' => 'classeur OpenDocument', 'html' => 'tableau'][$format] ?? 'fichier';
    return imp_empty($format, 'Aucune colonne « date » et « montant » reconnue dans ce ' . $nom . '. Vérifiez qu’il s’agit bien de l’export des opérations, ou utilisez l’export CSV ou OFX de votre banque.');
}

function imp_lines(string $t, int $max = 40): array {
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', $t) as $l) { if (trim($l) !== '') $out[] = $l; if (count($out) >= $max) break; }
    return $out;
}

/** PDF lu côté serveur : seulement si l'outil pdftotext (poppler) est installé et que PHP peut le lancer. */
function imp_parse_pdf_server(string $raw): array {
    $bin = imp_find_pdftotext();
    if ($bin === null) {
        return ['format' => 'pdf', 'rows' => [], 'meta' => [], 'warnings' => ['Ce PDF doit être lu par la page (dans le navigateur) : glissez-le dans la zone d’import de l’application. Le serveur ne dispose pas de « pdftotext ».'], 'check' => ['status' => 'inconnu', 'detail' => '']];
    }
    $tmp = tempnam(sys_get_temp_dir(), 'bsl');
    file_put_contents($tmp, $raw);
    $txt = '';
    $p = @proc_open([$bin, '-layout', '-enc', 'UTF-8', $tmp, '-'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (is_resource($p)) { $txt = (string)stream_get_contents($pipes[1]); fclose($pipes[1]); fclose($pipes[2]); proc_close($p); }
    @unlink($tmp);
    if (trim($txt) === '') return ['format' => 'pdf', 'rows' => [], 'meta' => [], 'warnings' => ['Aucun texte trouvé dans ce PDF : c’est probablement un scan (image). Demandez à votre banque le relevé « numérique » ou l’export CSV.'], 'check' => ['status' => 'inconnu', 'detail' => '']];
    $r = imp_parse_text($txt);
    $r['format'] = 'pdf';
    return $r;
}
function imp_find_pdftotext(): ?string {
    if (!function_exists('proc_open')) return null;
    $dis = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (in_array('proc_open', $dis, true)) return null;
    foreach (['/usr/bin/pdftotext', '/usr/local/bin/pdftotext', '/opt/homebrew/bin/pdftotext'] as $c) if (@is_executable($c)) return $c;
    return null;
}

/* ========================================================================== */
/* CSV                                                                         */
/* ========================================================================== */

function imp_csv_delim(array $lines): ?string {
    $best = null; $bestScore = 0;
    foreach ([';', "\t", ',', '|'] as $d) {
        $freq = [];
        foreach ($lines as $l) { $n = count(str_getcsv($l, $d, '"', '\\')) - 1; if ($n > 0) $freq[$n] = ($freq[$n] ?? 0) + 1; }
        if (!$freq) continue;
        arsort($freq);
        $mode = (int)array_key_first($freq);
        $score = $freq[$mode] * min($mode, 6);
        if ($score > $bestScore) { $best = $d; $bestScore = $score; }
    }
    return $bestScore >= 4 ? $best : null;
}

function imp_csv_role(string $h): ?string {
    static $roles = [
        'date_valeur' => ['DATE VALEUR', 'DATE DE VALEUR', 'DATEVAL', 'VALUE DATE', 'VALEUR', 'DATE VAL'],
        'date' => ['DATE', 'DATE OPERATION', 'DATE D OPERATION', 'DATE DE L OPERATION', 'DATE OPE', 'DATE OP', 'DATEOP', 'DATE DE COMPTABILISATION', 'DATE COMPTABLE',
            'DATE COMPTA', 'BOOKING DATE', 'TRANSACTION DATE', 'STARTED DATE', 'COMPLETED DATE', 'DATE TRANSACTION', 'JOUR'],
        'debit' => ['DEBIT', 'DEBITS', 'MONTANT DEBIT', 'SORTIE', 'SORTIES', 'DEPENSE', 'DEPENSES', 'MONEY OUT', 'PAID OUT'],
        'credit' => ['CREDIT', 'CREDITS', 'MONTANT CREDIT', 'ENTREE', 'ENTREES', 'RECETTE', 'RECETTES', 'MONEY IN', 'PAID IN'],
        'fee' => ['FEE', 'FEES'],
        'amount' => ['MONTANT', 'AMOUNT', 'SOMME', 'MONTANT OPERATION', 'MONTANT DE L OPERATION', 'MONTANT EN EUROS', 'VALEUR EUR'],
        'balance' => ['SOLDE', 'BALANCE', 'ACCOUNTBALANCE', 'SOLDE APRES OPERATION'],
        'category' => ['CATEGORIE', 'CATEGORY', 'CATEGORIE OPERATION'],
        'state' => ['STATE', 'ETAT', 'STATUT', 'STATUS'],
        'currency' => ['CURRENCY', 'DEVISE'],
        'label' => ['LIBELLE', 'LIBELLES', 'LIBELLE OPERATION', 'LIBELLE SIMPLIFIE', 'LIBELLE DE L OPERATION', 'LIBELLE COURT', 'DESCRIPTION', 'OPERATION', 'OPERATIONS',
            'NATURE', 'NATURE DE L OPERATION', 'DETAIL', 'DETAILS', 'INTITULE', 'LABEL', 'PAYEE', 'BENEFICIAIRE', 'TIERS', 'MERCHANT', 'NOM', 'COUNTERPARTY'],
        'label2' => ['INFORMATIONS COMPLEMENTAIRES', 'INFORMATION COMPLEMENTAIRE', 'COMPLEMENT', 'DETAIL DE L ECRITURE', 'PAYMENT REFERENCE', 'MEMO', 'COMMENTAIRE',
            'COMMENT', 'LIBELLE COMPLEMENTAIRE', 'TYPE OPERATION', 'TYPE', 'TRANSACTION TYPE', 'SUPPLIERFOUND', 'REFERENCE', 'MOTIF'],
    ];
    if ($h === '' || has($h, 'FRANC')) return null;
    foreach ($roles as $role => $syn) foreach ($syn as $s) if ($h === $s) return $role;
    foreach ($roles as $role => $syn) foreach ($syn as $s) if (starts($h, $s . ' ')) return $role;
    return null;
}

function imp_parse_csv(string $txt, bool $allowGuess = true): array {
    $all = preg_split('/\r\n|\r|\n/', $txt);
    $delim = imp_csv_delim(imp_lines($txt, 60)) ?? ';';
    $rows = [];
    foreach ($all as $l) { if (trim($l) === '') continue; $rows[] = array_map('trim', str_getcsv($l, $delim, '"', '\\')); }
    return imp_parse_table($rows, $allowGuess, 'csv');
}

/** Tableau de lignes (CSV, Excel, OpenDocument, HTML) : repère l'en-tête puis lit date, libellé et montant. */
function imp_parse_table(array $rows, bool $allowGuess, string $format): array {
    $rows = array_map(function ($r) { return array_map(function ($c) { return trim((string)$c); }, (array)$r); }, $rows);
    $warn = [];
    // ligne d'en-tête : la première qui contient une date ET un montant (ou débit / crédit)
    $map = null; $start = 0;
    foreach (array_slice($rows, 0, 40) as $i => $r) {
        $m = [];
        foreach ($r as $j => $cell) {
            $role = imp_csv_role(norm_text($cell));
            if ($role === null) continue;
            if ($role === 'label2' || $role === 'label') { $m[$role][] = $j; continue; }
            if (!isset($m[$role])) $m[$role] = $j;
        }
        if (isset($m['date']) && (isset($m['amount']) || isset($m['debit']) || isset($m['credit']))) { $map = $m; $start = $i + 1; break; }
    }
    if ($map === null) {
        $map = $allowGuess ? imp_csv_guess($rows) : null;
        $start = 0;
        if ($map === null) return ['format' => $format, 'rows' => [], 'meta' => [], 'warnings' => ['Colonnes du fichier non reconnues.']];
        $warn[] = 'Fichier sans en-tête : colonnes déduites du contenu, vérifiez le sens des montants.';
    }
    if (!isset($map['label']) && isset($map['label2'])) { $map['label'] = $map['label2']; unset($map['label2']); }

    $dayFirst = imp_csv_dayfirst($rows, $start, $map['date']);
    $out = []; $balances = []; $foreign = 0; $skipped = 0;
    foreach (array_slice($rows, $start) as $r) {
        $cellDate = (string)($r[$map['date']] ?? '');
        $date = date_parse_any($cellDate, null, $dayFirst);
        // tableur : date restée en numéro de série (cellule sans format de date)
        if ($date === null && $format !== 'csv' && preg_match('/^\d{5}(\.\d+)?$/', $cellDate) && (float)$cellDate > 20000 && (float)$cellDate < 80000) $date = fmt_serial_date((float)$cellDate);
        if ($date === null) continue;
        if (isset($map['state'])) {
            $st = norm_text((string)($r[$map['state']] ?? ''));
            if (in_array($st, ['REVERTED', 'DECLINED', 'FAILED', 'CANCELLED', 'CANCELED', 'ANNULE', 'ANNULEE', 'REFUSE', 'REFUSEE', 'PENDING', 'EN ATTENTE'], true)) { $skipped++; continue; }
        }
        $amount = null;
        if (isset($map['amount'])) $amount = money_parse((string)($r[$map['amount']] ?? ''));
        if ($amount === null && (isset($map['debit']) || isset($map['credit']))) {
            $d = isset($map['debit']) ? money_parse((string)($r[$map['debit']] ?? '')) : null;
            $c = isset($map['credit']) ? money_parse((string)($r[$map['credit']] ?? '')) : null;
            if ($d === null && $c === null) continue;
            $amount = abs((int)$c) - abs((int)$d);
        }
        if ($amount === null || $amount === 0) continue;
        $parts = [];
        foreach ((array)($map['label'] ?? []) as $j) if (($r[$j] ?? '') !== '') $parts[] = $r[$j];
        foreach ((array)($map['label2'] ?? []) as $j) { $v = (string)($r[$j] ?? ''); if ($v !== '' && !has(norm_text(implode(' ', $parts)), norm_text($v))) $parts[] = $v; }
        $label = trim(preg_replace('/\s+/u', ' ', implode(' ', $parts)));
        if ($label === '') $label = '(sans libellé)';
        if (isset($map['currency'])) { $cur = strtoupper(trim((string)($r[$map['currency']] ?? ''))); if ($cur !== '' && $cur !== 'EUR' && $cur !== '€') $foreign++; }
        $out[] = ['date' => $date, 'label' => cut($label, 300), 'amount' => $amount, 'sure' => true];
        if (isset($map['fee'])) {
            $fee = money_parse((string)($r[$map['fee']] ?? ''));
            if ($fee) $out[] = ['date' => $date, 'label' => cut('FRAIS ' . $label, 300), 'amount' => -abs($fee), 'sure' => true];
        }
        if (isset($map['balance'])) { $b = money_parse((string)($r[$map['balance']] ?? '')); if ($b !== null) $balances[] = [$date, $amount, $b]; }
    }
    $meta = [];
    if ($balances) {
        // fichier trié du plus récent au plus ancien, ou l'inverse
        $asc = $balances[0][0] <= $balances[count($balances) - 1][0];
        $first = $asc ? $balances[0] : $balances[count($balances) - 1];
        $last = $asc ? $balances[count($balances) - 1] : $balances[0];
        $meta['closing'] = $last[2];
        $meta['opening'] = $first[2] - $first[1];
    }
    if ($foreign) $warn[] = $foreign . ' opération(s) dans une autre devise que l’euro : les montants sont repris tels quels.';
    if ($skipped) $warn[] = $skipped . ' opération(s) annulée(s) ou en attente ignorée(s).';
    return ['format' => $format, 'rows' => $out, 'meta' => $meta, 'warnings' => $warn];
}

function imp_csv_dayfirst(array $rows, int $start, int $col): bool {
    foreach (array_slice($rows, $start, 400) as $r) {
        if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-]/', (string)($r[$col] ?? ''), $m)) {
            if ((int)$m[1] > 12) return true;
            if ((int)$m[2] > 12) return false;
        }
    }
    return true;
}

/** Fichier sans en-tête : on devine la colonne de date, de montant(s) et de libellé à partir du contenu. */
function imp_csv_guess(array $rows): ?array {
    $sample = array_slice($rows, 0, 30);
    $n = 0; foreach ($sample as $r) $n = max($n, count($r));
    $dates = $amts = $lens = array_fill(0, $n, 0);
    foreach ($sample as $r) for ($j = 0; $j < $n; $j++) {
        $v = (string)($r[$j] ?? '');
        if (date_parse_any($v) !== null && preg_match('/\d/', $v)) $dates[$j]++;
        elseif ($v !== '' && money_parse($v) !== null) $amts[$j]++;
        else $lens[$j] += strlen($v);
    }
    $tot = max(1, count($sample));
    $dc = null; foreach ($dates as $j => $c) if ($c / $tot >= 0.6) { $dc = $j; break; }
    if ($dc === null) return null;
    $ac = []; foreach ($amts as $j => $c) if ($c / $tot >= 0.3 && $j !== $dc) $ac[] = $j;
    if (!$ac) return null;
    arsort($lens); $lc = (int)array_key_first($lens);
    $m = ['date' => $dc, 'label' => [$lc]];
    if (count($ac) >= 2 && $amts[$ac[0]] + $amts[$ac[1]] <= $tot * 1.1) { $m['debit'] = $ac[0]; $m['credit'] = $ac[1]; }
    else $m['amount'] = $ac[0];
    return $m;
}

/* ========================================================================== */
/* OFX / QFX                                                                   */
/* ========================================================================== */

function imp_parse_ofx(string $t): array {
    $tag = function (string $block, string $name): string {
        return preg_match('/<' . $name . '>\s*([^<\r\n]*)/i', $block, $m) ? trim(html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8')) : '';
    };
    $rows = [];
    if (preg_match_all('/<STMTTRN>(.*?)(?=<\/STMTTRN>|<STMTTRN>|<\/BANKTRANLIST>)/is', $t, $mm)) {
        foreach ($mm[1] as $b) {
            $date = date_parse_any(substr($tag($b, 'DTPOSTED'), 0, 8)) ?? date_parse_any(substr($tag($b, 'DTUSER'), 0, 8));
            $amt = money_parse($tag($b, 'TRNAMT'));
            if ($date === null || !$amt) continue;
            $name = $tag($b, 'NAME'); $memo = $tag($b, 'MEMO');
            $label = $name;
            if ($memo !== '' && !has(norm_text($name), norm_text($memo))) $label = trim($name . ' ' . $memo);
            $rows[] = ['date' => $date, 'label' => cut($label !== '' ? $label : $tag($b, 'TRNTYPE'), 300), 'amount' => $amt, 'sure' => true];
        }
    }
    $meta = [];
    if (preg_match('/<LEDGERBAL>(.*?)(?:<\/LEDGERBAL>|<AVAILBAL>|$)/is', $t, $m)) { $v = money_parse($tag($m[1], 'BALAMT')); if ($v !== null) $meta['closing'] = $v; }
    $ds = date_parse_any(substr($tag($t, 'DTSTART'), 0, 8)); $de = date_parse_any(substr($tag($t, 'DTEND'), 0, 8));
    if ($ds) $meta['period_start'] = $ds;
    if ($de) $meta['period_end'] = $de;
    $acct = $tag($t, 'ACCTID'); if ($acct !== '') $meta['account'] = mask_account($acct);
    if (isset($meta['closing'])) { $s = 0; foreach ($rows as $r) $s += $r['amount']; $meta['opening'] = $meta['closing'] - $s; $meta['opening_computed'] = true; }
    return ['format' => 'ofx', 'rows' => $rows, 'meta' => $meta, 'warnings' => $rows ? [] : ['Aucune opération trouvée dans ce fichier OFX.']];
}

/* ========================================================================== */
/* QIF                                                                         */
/* ========================================================================== */

function imp_parse_qif(string $t): array {
    $recs = []; $cur = [];
    foreach (preg_split('/\r\n|\r|\n/', $t) as $l) {
        $l = rtrim($l);
        if ($l === '' || $l[0] === '!') continue;
        if ($l[0] === '^') { if ($cur) $recs[] = $cur; $cur = []; continue; }
        $cur[$l[0]] = isset($cur[$l[0]]) && $l[0] === 'M' ? $cur[$l[0]] . ' ' . substr($l, 1) : substr($l, 1);
    }
    if ($cur) $recs[] = $cur;
    $dayFirst = true;
    foreach ($recs as $r) if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})/', (string)($r['D'] ?? ''), $m)) { if ((int)$m[1] > 12) { $dayFirst = true; break; } if ((int)$m[2] > 12) { $dayFirst = false; break; } }
    $rows = [];
    foreach ($recs as $r) {
        $d = str_replace('\'', '/', (string)($r['D'] ?? ''));
        $date = date_parse_any($d, null, $dayFirst);
        $amt = money_parse((string)($r['T'] ?? $r['U'] ?? ''));
        if ($date === null || !$amt) continue;
        $label = trim(($r['P'] ?? '') . ' ' . (isset($r['M']) && !has(norm_text($r['P'] ?? ''), norm_text($r['M'])) ? $r['M'] : ''));
        $rows[] = ['date' => $date, 'label' => cut($label !== '' ? $label : '(sans libellé)', 300), 'amount' => $amt, 'sure' => true];
    }
    return ['format' => 'qif', 'rows' => $rows, 'meta' => [], 'warnings' => $rows ? [] : ['Aucune opération trouvée dans ce fichier QIF.']];
}

/* ========================================================================== */
/* Texte et PDF (mise en page)                                                 */
/* ========================================================================== */

/** Texte collé ou sortie de « pdftotext -layout » : chaque ligne devient une liste de mots positionnés (x = colonne de caractère). */
function imp_parse_text(string $txt): array {
    $lines = [];
    foreach (preg_split('/\r\n|\r|\n/', $txt) as $line) {
        $line = rtrim(str_replace("\t", '    ', $line));
        $items = [];
        if (preg_match_all('/\S+(?: \S+)*/u', $line, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$s, $off]) {
                $x = function_exists('mb_strlen') ? mb_strlen(substr($line, 0, $off), 'UTF-8') : $off;
                $items[] = ['x' => $x, 'w' => function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s), 's' => $s];
            }
        }
        $lines[] = ['page' => 0, 'items' => $items];
    }
    $r = imp_parse_layout($lines);
    $r['format'] = 'texte';
    return $r;
}

/** Découpe les éléments d'une ligne en mots positionnés. */
function imp_tokens(array $items): array {
    $toks = [];
    foreach ($items as $it) {
        $s = (string)($it['s'] ?? ''); $x = (float)($it['x'] ?? 0); $w = (float)($it['w'] ?? 0);
        $len = max(1, function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s));
        $cw = $w > 0 ? $w / $len : 1.0;
        if (!preg_match_all('/\S+/u', $s, $m, PREG_OFFSET_CAPTURE)) continue;
        foreach ($m[0] as [$word, $off]) {
            $ci = function_exists('mb_strlen') ? mb_strlen(substr($s, 0, $off), 'UTF-8') : $off;
            $wl = function_exists('mb_strlen') ? mb_strlen($word, 'UTF-8') : strlen($word);
            $toks[] = ['s' => $word, 'x' => $x + $ci * $cw, 'r' => $x + ($ci + $wl) * $cw, 'cw' => $cw];
        }
    }
    usort($toks, function ($a, $b) { return $a['x'] <=> $b['x']; });
    return $toks;
}

/**
 * Montants en fin de ligne, lus de droite à gauche (on saute les dates de valeur, « € », « EUR », D/C).
 * Rend [montants, index du premier jeton qui n'appartient plus à la zone des montants].
 */
function imp_line_amounts(array $toks): array {
    $amts = []; $j = count($toks) - 1; $dates = [];
    $skip = '/^(€|EUR|EUROS|D|C|DB|CR|\*|-)$/i';
    while ($j >= 0) {
        $s = $toks[$j]['s'];
        if (preg_match($skip, $s)) { $j--; continue; }
        if (preg_match(DATE_TOKEN_RE, $s)) { $dates[] = $j; $j--; continue; }
        $found = false;
        for ($k = 3; $k >= 1; $k--) {
            $i0 = $j - $k + 1;
            if ($i0 < 0) continue;
            $ok = true; $str = '';
            for ($i = $i0; $i <= $j; $i++) {
                if ($i > $i0) {
                    $gap = $toks[$i]['x'] - $toks[$i - 1]['r'];
                    if ($gap > 1.15 * max($toks[$i]['cw'], 0.5)) { $ok = false; break; }
                    $str .= ' ';
                }
                $str .= $toks[$i]['s'];
            }
            if (!$ok) continue;
            $str = str_replace(['−', '–'], '-', $str);
            $str = (string)preg_replace('/\s*(€|EUR)$/u', '', $str);
            if ($k > 1 && !preg_match('/^[-+]?\d{1,3}( \d{3})+,\d{2}-?$|^[-+] ?[\d .]+,\d{2}$/', $str)) continue;
            $str = preg_replace('/^([-+]) /', '$1', $str);
            if (!preg_match(AMOUNT_RE, $str)) continue;
            $v = money_parse($str);
            if ($v === null) continue;
            $explicit = (bool)preg_match('/^[-+]|-$/', $str);
            $suffix = $j + 1 < count($toks) ? strtoupper($toks[$j + 1]['s']) : '';
            if (in_array($suffix, ['D', 'DB', '-'], true)) { $v = -abs($v); $explicit = true; }
            if (in_array($suffix, ['C', 'CR'], true)) { $v = abs($v); $explicit = true; }
            $amts[] = ['v' => $v, 'x' => $toks[$i0]['x'], 'r' => $toks[$j]['r'], 'c' => ($toks[$i0]['x'] + $toks[$j]['r']) / 2, 'explicit' => $explicit, 'i0' => $i0];
            $j = $i0 - 1; $found = true;
            break;
        }
        if (!$found) break;
        if (count($amts) >= 3) break;
    }
    return [array_reverse($amts), $j + 1, $dates];
}

function imp_leading_date(array $toks, ?int $year): ?array {
    if (!$toks) return null;
    $s = $toks[0]['s'];
    if (preg_match(DATE_TOKEN_RE, $s, $m)) {
        $d = (int)$m[1]; $mo = (int)$m[2];
        if ($mo < 1 || $mo > 12 || $d < 1 || $d > 31) return null;
        $y = isset($m[3]) && $m[3] !== '' ? (int)$m[3] : null;
        if ($y !== null && $y < 100) $y += 2000;
        $used = 1;
        if (isset($toks[1]) && preg_match(DATE_TOKEN_RE, $toks[1]['s'])) $used = 2;   // date de valeur juste après
        return ['d' => $d, 'm' => $mo, 'y' => $y, 'used' => $used];
    }
    if (preg_match('/^\d{1,2}$/', $s) && isset($toks[1])) {                         // 3 oct. 2026
        $mon = norm_text($toks[1]['s']);
        if (isset(MOIS_ABR[$mon])) {
            $y = null; $used = 2;
            if (isset($toks[2]) && preg_match('/^(20\d{2})$/', $toks[2]['s'])) { $y = (int)$toks[2]['s']; $used = 3; }
            return ['d' => (int)$s, 'm' => MOIS_ABR[$mon], 'y' => $y, 'used' => $used];
        }
    }
    return null;
}

/** Période, banque et compte trouvés dans le texte complet. */
function imp_meta_from_text(string $full): array {
    $meta = [];
    $n = norm_text($full);
    if (preg_match('/\b(?:DU|PERIODE DU|PERIODE)\s+(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2,4})\s+AU\s+(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2,4})/u', strtoupper(strtr($full, ['é' => 'e', 'É' => 'E'])), $m)) {
        $y1 = (int)$m[3]; $y2 = (int)$m[6]; if ($y1 < 100) $y1 += 2000; if ($y2 < 100) $y2 += 2000;
        $meta['period_start'] = ymd($y1, (int)$m[2], (int)$m[1]);
        $meta['period_end'] = ymd($y2, (int)$m[5], (int)$m[4]);
    }
    if (empty($meta['period_end']) && preg_match('/\b(JANVIER|FEVRIER|MARS|AVRIL|MAI|JUIN|JUILLET|AOUT|SEPTEMBRE|OCTOBRE|NOVEMBRE|DECEMBRE) (20\d{2})\b/', $n, $m)) {
        $mo = array_search(strtolower(strtr($m[1], ['FEVRIER' => 'février', 'AOUT' => 'août', 'DECEMBRE' => 'décembre'])), MOIS_FR, true);
        if ($mo === false) $mo = MOIS_ABR[$m[1]] ?? null;
        if ($mo) { $ym = sprintf('%04d-%02d', (int)$m[2], $mo); $meta['period_end'] = $ym . '-' . days_in_month($ym); $meta['period_guess'] = true; }
    }
    $banks = ['BNP PARIBAS' => 'BNP Paribas', 'HELLO BANK' => 'Hello bank!', 'SOCIETE GENERALE' => 'Société Générale', 'SOBANK' => 'SG', 'CREDIT AGRICOLE' => 'Crédit Agricole',
        'LCL' => 'LCL', 'CREDIT LYONNAIS' => 'LCL', 'CAISSE D EPARGNE' => 'Caisse d’Épargne', 'BANQUE POPULAIRE' => 'Banque Populaire', 'BANQUE POSTALE' => 'La Banque Postale',
        'CREDIT MUTUEL' => 'Crédit Mutuel', 'CIC' => 'CIC', 'BOURSORAMA' => 'BoursoBank', 'BOURSOBANK' => 'BoursoBank', 'FORTUNEO' => 'Fortuneo', 'MONABANQ' => 'Monabanq',
        'REVOLUT' => 'Revolut', 'N26' => 'N26', 'NICKEL' => 'Nickel', 'QONTO' => 'Qonto', 'SHINE' => 'Shine', 'MA FRENCH BANK' => 'Ma French Bank', 'ORANGE BANK' => 'Orange Bank',
        'BFORBANK' => 'BforBank', 'AXA BANQUE' => 'AXA Banque', 'HSBC' => 'HSBC', 'CREDIT DU NORD' => 'Crédit du Nord', 'LA NEF' => 'La Nef', 'SUMERIA' => 'Sumeria', 'LYDIA' => 'Lydia',
        'BRED' => 'BRED', 'CREDIT COOPERATIF' => 'Crédit Coopératif', 'BANQUE PALATINE' => 'Palatine', 'MILLEIS' => 'Milleis', 'CA CONSUMER' => 'CA Consumer Finance', 'TRADE REPUBLIC' => 'Trade Republic'];
    foreach ($banks as $k => $v) if (preg_match('/\b' . $k . '\b/', $n)) { $meta['bank'] = $v; break; }
    if (preg_match('/\b(FR\d{2}(?: ?[0-9A-Z]{4}){5,6}(?: ?[0-9A-Z]{1,3})?)/', strtoupper($full), $m)) $meta['account'] = mask_account($m[1]);
    elseif (preg_match('/\b(?:N[O°]|NUMERO|COMPTE)\s*(?:DE COMPTE)?\s*:?\s*(\d[\d ]{8,}\d)/u', strtoupper($full), $m)) $meta['account'] = mask_account($m[1]);
    return $meta;
}

/**
 * Cœur de la lecture d'un relevé mis en page. $lines : [['page'=>n, 'items'=>[['x','w','s'], …]], …]
 * Les mots portent une position horizontale : on repère les colonnes Débit / Crédit / Solde
 * dans les lignes d'en-tête et on range chaque montant dans la colonne la plus proche.
 */
function imp_parse_layout(array $lines): array {
    $L = [];
    $full = '';
    foreach ($lines as $ln) {
        $toks = imp_tokens((array)($ln['items'] ?? []));
        $text = implode(' ', array_map(function ($t) { return $t['s']; }, $toks));
        $L[] = ['page' => (int)($ln['page'] ?? 0), 'toks' => $toks, 'text' => $text, 'n' => norm_text($text)];
        $full .= $text . "\n";
    }
    $meta = imp_meta_from_text($full);
    $year = isset($meta['period_end']) ? (int)substr($meta['period_end'], 0, 4) : null;
    if ($year === null && preg_match_all('/\b(20\d{2})\b/', $full, $ym)) { $c = array_count_values($ym[1]); arsort($c); $year = (int)array_key_first($c); }

    $cols = null;            // ['debit'=>x, 'credit'=>x, 'solde'=>x, 'montant'=>x]
    $rows = []; $cur = null; $warn = [];
    $balances = []; $totals = null;
    $flush = function () use (&$cur, &$rows) {
        if ($cur !== null && $cur['amount'] !== null) { $cur['label'] = trim(preg_replace('/\s+/u', ' ', $cur['label'])); $rows[] = $cur; }
        $cur = null;
    };
    $noise = '/^(PAGE \d|\d+ ?\/ ?\d+$)|\bPAGE \d+ ?(SUR|\/) ?\d+\b|SUITE AU VERSO|A REPORTER|REPORT A NOUVEAU|\bIBAN\b|\bBIC\b|\bSWIFT\b|WWW |HTTP|\bSIREN\b|\bRCS\b|CAPITAL DE|ORIAS|\bTEL\b|TELEPHONE|MEDIATEUR|GARANTIE DES DEPOTS|FONDS DE GARANTIE|CONSEILLER|VOTRE AGENCE|SERVICE CLIENT|DATE.*LIBELLE|LIBELLE.*DEBIT|TITULAIRE|RELEVE DE COMPTE|RELEVE N|EXTRAIT DE COMPTE|DATE D ARRETE|MONTANT DISPONIBLE|SOLDE MOYEN|DECOUVERT AUTORISE/';
    $prevPage = null;
    $classify = function (array $a) use (&$cols): ?string {
        if ($cols === null) return null;
        $best = null; $bd = INF;
        foreach (['debit', 'credit', 'solde', 'montant'] as $k) {
            if (!isset($cols[$k])) continue;
            $d = min(abs($a['c'] - $cols[$k]['c']), abs($a['r'] - $cols[$k]['r']));
            if ($d < $bd) { $bd = $d; $best = $k; }
        }
        return $best;
    };

    foreach ($L as $ln) {
        $toks = $ln['toks']; $n = $ln['n'];
        if ($prevPage !== null && $ln['page'] !== $prevPage) $flush();
        $prevPage = $ln['page'];
        if (!$toks) { if ($cur && $cur['amount'] !== null) $flush(); continue; }

        // ligne d'en-tête : repère les colonnes
        $hdr = [];
        foreach ($toks as $t) {
            $tn = norm_text($t['s']);
            if (preg_match('/^(DEBIT|DEBITS|SORTIE|SORTIES|DEPENSES)$/', $tn)) $hdr['debit'] = ['c' => ($t['x'] + $t['r']) / 2, 'r' => $t['r']];
            elseif (preg_match('/^(CREDIT|CREDITS|ENTREE|ENTREES|RECETTES)$/', $tn)) $hdr['credit'] = ['c' => ($t['x'] + $t['r']) / 2, 'r' => $t['r']];
            elseif (preg_match('/^(SOLDE|BALANCE)$/', $tn) && count($toks) <= 8) $hdr['solde'] = ['c' => ($t['x'] + $t['r']) / 2, 'r' => $t['r']];
            elseif (preg_match('/^(MONTANT|MONTANTS)$/', $tn) && count($toks) <= 8) $hdr['montant'] = ['c' => ($t['x'] + $t['r']) / 2, 'r' => $t['r']];
        }
        if ((isset($hdr['debit']) && isset($hdr['credit'])) || (isset($hdr['montant']) && preg_match('/\b(DATE|LIBELLE|OPERATION|OPERATIONS)\b/', $n))) {
            $cols = $hdr; $flush(); continue;
        }

        [$amts, $endIdx, $dateIdx] = imp_line_amounts($toks);

        // soldes et totaux
        $isBalance = $amts && !preg_match('/\b(MOYEN|DISPONIBLE|AUTORISE|AUTORISATION|PREVISIONNEL|A VENIR)\b/', $n) && (
            (imp_leading_date($toks, $year) === null && preg_match('/\b(ANCIEN SOLDE|SOLDE PRECEDENT|SOLDE INITIAL|SOLDE DE DEBUT|SOLDE AU|SOLDE EN DEBUT|NOUVEAU SOLDE|SOLDE FINAL|SOLDE DE FIN|SOLDE EN FIN|SOLDE CREDITEUR|SOLDE DEBITEUR|SOLDE EN EUROS|SOLDE|REPORT)\b/', $n))
            || preg_match('/^(ANCIEN SOLDE|NOUVEAU SOLDE|SOLDE)\b/', imp_strip_date($n)));
        if ($isBalance) {
            $a = end($amts);
            $v = $a['v'];
            $col = $classify($a);
            if (!$a['explicit']) {
                if ($col === 'debit' || has($n, 'DEBITEUR')) $v = -abs($v); else $v = abs($v);
            }
            $kind = preg_match('/\b(ANCIEN|PRECEDENT|INITIAL|DEBUT|REPORT)\b/', $n) ? 'open' : (preg_match('/\b(NOUVEAU|FINAL|FIN)\b/', $n) ? 'close' : ($rows || $cur ? 'close' : 'open'));
            $balances[] = [$kind, $v];
            $flush();
            continue;
        }
        if (preg_match('/\bTOTAL\b/', $n) && $amts && !imp_leading_date($toks, $year)) {
            $t = ['debit' => null, 'credit' => null];
            foreach ($amts as $i => $a) {
                $col = $classify($a);
                if ($col === 'debit' || ($col === null && $i === 0 && count($amts) >= 2)) $t['debit'] = abs($a['v']);
                elseif ($col === 'credit' || ($col === null && $i === 1)) $t['credit'] = abs($a['v']);
            }
            if ($totals === null) $totals = $t; else { $totals['debit'] = $t['debit'] ?? $totals['debit']; $totals['credit'] = $t['credit'] ?? $totals['credit']; }
            $flush();
            continue;
        }

        $d = imp_leading_date($toks, $year);
        if ($d !== null) {
            $flush();
            $labelToks = [];
            for ($i = $d['used']; $i < $endIdx; $i++) if (!in_array($i, $dateIdx, true)) $labelToks[] = $toks[$i]['s'];
            $label = implode(' ', $labelToks);
            $cur = ['d' => $d, 'label' => $label, 'amount' => null, 'sure' => false, 'cont' => 0];
            if ($amts) imp_assign_amount($cur, $amts, $classify, $cols);
            continue;
        }

        if ($cur !== null) {
            if (preg_match($noise, $n)) { $flush(); continue; }
            if ($amts && $cur['amount'] === null) {
                $extra = [];
                for ($i = 0; $i < $endIdx; $i++) $extra[] = $toks[$i]['s'];
                $cur['label'] .= ' ' . implode(' ', $extra);
                imp_assign_amount($cur, $amts, $classify, $cols);
                continue;
            }
            if (!$amts && $cur['cont'] < 3 && strlen($ln['text']) < 160) {
                $cur['label'] .= ' ' . $ln['text'];
                $cur['cont']++;
                continue;
            }
            $flush();
        }
    }
    $flush();

    // Pas d'en-tête Débit / Crédit : si les montants « incertains » se rangent en deux colonnes,
    // celle de gauche est le débit et celle de droite le crédit (présentation des banques françaises).
    $uns = array_values(array_filter($rows, function ($r) { return !$r['sure'] && isset($r['ac']); }));
    $byCols = false;
    if (count($uns) >= 4) {
        $cs = array_map(function ($r) { return $r['ac']; }, $uns);
        sort($cs);
        $gap = 0; $cutX = null;
        for ($i = 1; $i < count($cs); $i++) if ($cs[$i] - $cs[$i - 1] > $gap) { $gap = $cs[$i] - $cs[$i - 1]; $cutX = ($cs[$i] + $cs[$i - 1]) / 2; }
        $w = median(array_map(function ($r) { return max(1, $r['acw']); }, $uns));
        if ($cutX !== null && $gap > 1.5 * $w) {
            $agree = 0; $tot = 0;
            foreach ($uns as $r) { if (preg_match(CREDIT_HINT_RE, norm_text($r['label']))) { $tot++; if ($r['ac'] > $cutX) $agree++; } }
            if ($tot === 0 || $agree / $tot >= 0.6) {
                foreach ($rows as &$r) if (!$r['sure'] && isset($r['ac'])) { $r['amount'] = $r['ac'] > $cutX ? abs($r['amount']) : -abs($r['amount']); }
                unset($r);
                $byCols = true;
            }
        }
    }

    // années manquantes
    $pe = $meta['period_end'] ?? null;
    $out = [];
    foreach ($rows as $r) {
        $d = $r['d'];
        $y = $d['y'];
        if ($y === null) {
            $y = $year ?? (int)date('Y');
            if ($pe !== null) { $pm = (int)substr($pe, 5, 2); if ($d['m'] > $pm + 1) $y--; }
            elseif (ymd($y, $d['m'], $d['d']) > date('Y-m-d', time() + 31 * 86400)) $y--;
        }
        $date = ymd($y, $d['m'], $d['d']);
        if ($date === null || $r['amount'] === 0) continue;
        $out[] = ['date' => $date, 'label' => cut($r['label'] !== '' ? $r['label'] : '(sans libellé)', 300), 'amount' => $r['amount'], 'sure' => $r['sure']];
    }

    // sans colonnes ni signes : le sens a été déduit des mots du libellé
    $unsure = count(array_filter($out, function ($r) { return !$r['sure']; }));
    if ($unsure) $warn[] = $unsure . ' ligne(s) dont le sens (débit ou crédit) a été déduit ' . ($byCols ? 'de la position des colonnes' : 'du libellé') . ' : vérifiez-les (repérées en orange).';

    foreach ($balances as [$k, $v]) {
        if ($k === 'open' && !isset($meta['opening'])) $meta['opening'] = $v;
        if ($k === 'close') $meta['closing'] = $v;
    }
    if ($totals !== null) { $meta['total_debit'] = $totals['debit']; $meta['total_credit'] = $totals['credit']; }
    if (!$out && trim($full) !== '') $warn[] = 'Aucune opération reconnue. Essayez l’export CSV de votre banque (espace client › comptes › télécharger / exporter).';
    return imp_finalize(['format' => 'pdf', 'rows' => $out, 'meta' => $meta, 'warnings' => $warn]);
}

function imp_strip_date(string $n): string { return (string)preg_replace('/^\d{1,2} \d{1,2}( \d{2,4})? /', '', $n); }

/** Range le montant d'une ligne en débit ou crédit, d'après la colonne, le signe écrit ou, à défaut, le libellé. */
function imp_assign_amount(array &$cur, array $amts, callable $classify, ?array $cols): void {
    $pick = null; $col = null;
    if ($cols !== null) {
        foreach (array_reverse($amts) as $a) { $c = $classify($a); if ($c === 'debit' || $c === 'credit' || $c === 'montant') { $pick = $a; $col = $c; break; } }
        if ($pick === null) { $pick = $amts[0]; $col = $classify($pick); }
    } else {
        $pick = $amts[0];                    // deux montants sans en-tête : opération puis solde courant
    }
    $v = $pick['v'];
    $cur['ac'] = $pick['c']; $cur['acw'] = $pick['r'] - $pick['x'];
    if ($pick['explicit']) { $cur['amount'] = $v; $cur['sure'] = true; return; }
    if ($col === 'debit') { $cur['amount'] = -abs($v); $cur['sure'] = true; return; }
    if ($col === 'credit') { $cur['amount'] = abs($v); $cur['sure'] = true; return; }
    $credit = (bool)preg_match(CREDIT_HINT_RE, norm_text($cur['label']));
    $cur['amount'] = $credit ? abs($v) : -abs($v);
    $cur['sure'] = false;
}

/* ========================================================================== */
/* Finalisation : tri, contrôle des totaux                                     */
/* ========================================================================== */

function imp_finalize(array $r): array {
    $rows = $r['rows'] ?? [];
    usort($rows, function ($a, $b) { return strcmp($a['date'], $b['date']); });
    $meta = $r['meta'] ?? [];
    $deb = 0; $cre = 0;
    foreach ($rows as $x) { if ($x['amount'] < 0) $deb += -$x['amount']; else $cre += $x['amount']; }
    if ($rows) {
        $meta['period_start'] = $meta['period_start'] ?? $rows[0]['date'];
        $meta['period_end'] = $meta['period_end'] ?? $rows[count($rows) - 1]['date'];
        if (!empty($meta['period_guess']) && $rows[0]['date'] < substr($meta['period_end'], 0, 8) . '01') $meta['period_start'] = $rows[0]['date'];
    }
    $meta['sum_debit'] = $deb; $meta['sum_credit'] = $cre;
    $check = ['status' => 'inconnu', 'detail' => 'Le relevé ne donne ni soldes ni totaux : contrôle impossible, vérifiez quelques lignes.'];
    if (isset($meta['opening'], $meta['closing']) && empty($meta['opening_computed'])) {
        $diff = $meta['opening'] + $cre - $deb - $meta['closing'];
        $check = abs($diff) <= 1
            ? ['status' => 'ok', 'detail' => 'Solde initial ' . money_fmt($meta['opening']) . ' + crédits ' . money_fmt($cre) . ' − débits ' . money_fmt($deb) . ' = solde final ' . money_fmt($meta['closing']) . ' : le relevé est complet.']
            : ['status' => 'ecart', 'diff' => $diff, 'detail' => 'Écart de ' . money_fmt(abs($diff)) . ' entre les soldes et les opérations lues : une ligne manque ou est dans le mauvais sens.'];
    } elseif (isset($meta['total_debit']) || isset($meta['total_credit'])) {
        $okD = !isset($meta['total_debit']) || abs($meta['total_debit'] - $deb) <= 1;
        $okC = !isset($meta['total_credit']) || abs($meta['total_credit'] - $cre) <= 1;
        $check = $okD && $okC
            ? ['status' => 'ok', 'detail' => 'Totaux du relevé retrouvés à l’euro près.']
            : ['status' => 'ecart', 'detail' => 'Totaux du relevé : débits ' . money_fmt((int)($meta['total_debit'] ?? 0)) . ', crédits ' . money_fmt((int)($meta['total_credit'] ?? 0)) . ' — lus : ' . money_fmt($deb) . ' / ' . money_fmt($cre) . '.'];
    } elseif (isset($meta['closing']) && !empty($meta['opening_computed'])) {
        $check = ['status' => 'partiel', 'detail' => 'Solde final fourni par la banque : ' . money_fmt($meta['closing']) . '.'];
    }
    $r['rows'] = $rows; $r['meta'] = $meta; $r['check'] = $check;
    $r['warnings'] = array_values(array_unique($r['warnings'] ?? []));
    return $r;
}
