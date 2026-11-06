<?php
/**
 * Boussole — formats de fichiers.
 *
 * Lecture : tableurs Excel (.xlsx et ancien .xls), OpenDocument (.ods), tableaux HTML que certaines banques
 * enregistrent en « .xls », relevés XML ISO 20022 (camt.052 / camt.053 / camt.054) et SWIFT MT940.
 * Écriture : classeur Excel (.xlsx), OFX, QIF (logiciels de comptes) et courrier Word (.docx).
 *
 * Aucune extension PHP supplémentaire : zip.php lit et écrit les archives, libxml (intégré à PHP) lit le XML,
 * et le format binaire .xls est décodé ici même.
 */
if (!defined('BOUSSOLE')) { http_response_code(403); exit; }

const FMT_MAX_CELLS = 400000;   // garde-fou : un relevé n'a jamais autant de cellules
const NS_ODS_OFFICE = 'urn:oasis:names:tc:opendocument:xmlns:office:1.0';
const NS_ODS_TABLE = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';
const NS_ODS_TEXT = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';

/* ========================================================================== */
/* Outils communs                                                              */
/* ========================================================================== */

/** Nombre d'une cellule de tableur en texte lisible par money_parse() (12.300000000000001 → 12.3, 1.5E3 → 1500). */
function fmt_num(string $v): string {
    $v = trim($v);
    if ($v === '' || !is_numeric($v)) return $v;
    $f = (float)$v;
    if (abs($f - round($f)) < 1e-9 && abs($f) < 1e15) return (string)(int)round($f);
    return rtrim(rtrim(sprintf('%.6F', $f), '0'), '.');
}

/** Numéro de série de date d'un tableur → AAAA-MM-JJ (calendrier 1900, ou 1904 des anciens classeurs Mac). */
function fmt_serial_date(float $n, bool $s1904 = false): ?string {
    if ($n < 1 || $n > 2958465) return null;
    $base = $s1904 ? gmmktime(0, 0, 0, 1, 1, 1904) : gmmktime(0, 0, 0, 12, 30, 1899);
    return gmdate('Y-m-d', $base + (int)floor($n) * 86400);
}

/** Le format de nombre d'une cellule est-il une date ? (formats intégrés 14 à 22, ou format personnalisé avec j/m/a) */
function fmt_is_date_format(int $id, string $code): bool {
    if (($id >= 14 && $id <= 22) || ($id >= 27 && $id <= 36) || ($id >= 50 && $id <= 58)) return true;
    if ($code === '') return false;
    $c = (string)preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\./', '', $code);
    return (bool)preg_match('/[dyj]|(^|[^0#?])m/i', $c) && !preg_match('/^[#0.,%\s;Ee+\-]*$/', $c);
}

function fmt_xml(string $xml): ?SimpleXMLElement {
    if (!function_exists('simplexml_load_string')) throw new RuntimeException('Le module XML de PHP (SimpleXML) est absent : lecture de ce fichier impossible.');
    $prev = libxml_use_internal_errors(true);
    $x = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return $x === false ? null : $x;
}
function fmt_dom(string $xml): ?DOMDocument {
    if (!class_exists('DOMDocument')) throw new RuntimeException('Le module DOM de PHP est absent : lecture de ce fichier impossible.');
    $dom = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return $ok ? $dom : null;
}

/** Octets UTF-16LE → UTF-8 (sans dépendre de mbstring ni d'iconv). */
function fmt_utf16le(string $s): string {
    if (function_exists('mb_convert_encoding')) return (string)mb_convert_encoding($s, 'UTF-8', 'UTF-16LE');
    if (function_exists('iconv')) { $r = @iconv('UTF-16LE', 'UTF-8//IGNORE', $s); if ($r !== false) return $r; }
    $out = '';
    $n = strlen($s) - 1;
    for ($i = 0; $i < $n; $i += 2) {
        $c = ord($s[$i]) | (ord($s[$i + 1]) << 8);
        if ($c >= 0xD800 && $c <= 0xDBFF && $i + 3 < strlen($s)) {
            $lo = ord($s[$i + 2]) | (ord($s[$i + 3]) << 8);
            $c = 0x10000 + (($c - 0xD800) << 10) + ($lo - 0xDC00);
            $i += 2;
        }
        $out .= fmt_utf8_chr($c);
    }
    return $out;
}
/** Octets Latin-1 (caractères « compressés » d'Excel) → UTF-8. */
function fmt_latin1(string $s): string {
    if (!preg_match('/[\x80-\xFF]/', $s)) return $s;
    $out = '';
    for ($i = 0, $n = strlen($s); $i < $n; $i++) $out .= fmt_utf8_chr(ord($s[$i]));
    return $out;
}
function fmt_utf8_chr(int $c): string {
    if ($c < 0x80) return chr($c);
    if ($c < 0x800) return chr(0xC0 | ($c >> 6)) . chr(0x80 | ($c & 0x3F));
    if ($c < 0x10000) return chr(0xE0 | ($c >> 12)) . chr(0x80 | (($c >> 6) & 0x3F)) . chr(0x80 | ($c & 0x3F));
    return chr(0xF0 | ($c >> 18)) . chr(0x80 | (($c >> 12) & 0x3F)) . chr(0x80 | (($c >> 6) & 0x3F)) . chr(0x80 | ($c & 0x3F));
}
function fmt_xml_esc(string $s): string {
    $s = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/* ========================================================================== */
/* Excel .xlsx                                                                 */
/* ========================================================================== */

/** Feuilles d'un classeur .xlsx (ordre du classeur) : chacune est une liste de lignes de textes. */
function fmt_xlsx_sheets(array $z): array {
    $wb = isset($z['xl/workbook.xml']) ? fmt_xml($z['xl/workbook.xml']) : null;
    if (!$wb) throw new RuntimeException('Classeur Excel illisible.');
    $s1904 = isset($wb->workbookPr) && in_array(strtolower((string)$wb->workbookPr['date1904']), ['1', 'true'], true);
    $rels = [];
    if (isset($z['xl/_rels/workbook.xml.rels']) && ($rx = fmt_xml($z['xl/_rels/workbook.xml.rels']))) {
        foreach ($rx->Relationship as $r) {
            $t = (string)$r['Target'];
            $rels[(string)$r['Id']] = ($t !== '' && $t[0] === '/') ? ltrim($t, '/') : 'xl/' . $t;
        }
    }
    $paths = [];
    if (isset($wb->sheets->sheet)) foreach ($wb->sheets->sheet as $sh) {
        $a = $sh->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $rid = $a ? (string)$a['id'] : '';
        if (isset($rels[$rid])) $paths[] = $rels[$rid];
    }
    if (!$paths) {
        $paths = array_values(array_filter(array_keys($z), function ($n) { return (bool)preg_match('#^xl/worksheets/sheet\d+\.xml$#', $n); }));
        natsort($paths);
        $paths = array_values($paths);
    }
    $shared = [];
    if (isset($z['xl/sharedStrings.xml']) && ($sx = fmt_xml($z['xl/sharedStrings.xml']))) foreach ($sx->si as $si) $shared[] = fmt_xlsx_text($si);
    $dateStyle = [];
    if (isset($z['xl/styles.xml']) && ($st = fmt_xml($z['xl/styles.xml']))) {
        $codes = [];
        if (isset($st->numFmts->numFmt)) foreach ($st->numFmts->numFmt as $nf) $codes[(int)$nf['numFmtId']] = (string)$nf['formatCode'];
        $i = 0;
        if (isset($st->cellXfs->xf)) foreach ($st->cellXfs->xf as $xf) { $id = (int)$xf['numFmtId']; $dateStyle[$i++] = fmt_is_date_format($id, $codes[$id] ?? ''); }
    }
    $out = [];
    $cells = 0;
    foreach ($paths as $p) {
        if (!isset($z[$p]) || !($sx = fmt_xml($z[$p]))) continue;
        $rows = [];
        if (isset($sx->sheetData->row)) foreach ($sx->sheetData->row as $row) {
            $line = [];
            foreach ($row->c as $c) {
                $ref = (string)$c['r'];
                $col = $ref !== '' ? fmt_col_index($ref) : count($line);
                if ($col > 1000) continue;
                $t = (string)$c['t'];
                $v = isset($c->v) ? (string)$c->v : '';
                if ($t === 's') $val = $shared[(int)$v] ?? '';
                elseif ($t === 'inlineStr') $val = isset($c->is) ? fmt_xlsx_text($c->is) : '';
                elseif ($t === 'str') $val = $v;
                elseif ($t === 'e') $val = '';
                elseif ($t === 'b') $val = $v === '1' ? 'VRAI' : 'FAUX';
                elseif ($t === 'd') $val = substr($v, 0, 10);
                else {
                    $val = fmt_num($v);
                    if ($val !== '' && is_numeric($v) && (float)$v >= 1 && !empty($dateStyle[(int)$c['s']])) $val = fmt_serial_date((float)$v, $s1904) ?? $val;
                }
                for ($k = count($line); $k < $col; $k++) $line[$k] = '';
                $line[$col] = trim((string)$val);
                if (++$cells > FMT_MAX_CELLS) throw new RuntimeException('Tableur trop grand pour un relevé de compte.');
            }
            if (implode('', $line) !== '') $rows[] = $line;
        }
        $out[] = $rows;
    }
    return $out;
}
function fmt_xlsx_text(SimpleXMLElement $node): string {
    $s = isset($node->t) ? (string)$node->t : '';
    if (isset($node->r)) foreach ($node->r as $r) $s .= (string)$r->t;   // morceaux mis en forme ; les indications phonétiques (rPh) sont ignorées
    return $s;
}
function fmt_col_index(string $ref): int {
    $n = 0;
    for ($i = 0, $l = strlen($ref); $i < $l && ctype_alpha($ref[$i]); $i++) $n = $n * 26 + (ord(strtoupper($ref[$i])) - 64);
    return max(0, $n - 1);
}

/* ========================================================================== */
/* OpenDocument .ods                                                           */
/* ========================================================================== */

function fmt_ods_sheets(array $z): array {
    $dom = isset($z['content.xml']) ? fmt_dom($z['content.xml']) : null;
    if (!$dom) throw new RuntimeException('Classeur OpenDocument illisible.');
    $out = [];
    $cells = 0;
    foreach ($dom->getElementsByTagNameNS(NS_ODS_TABLE, 'table') as $table) {
        $rows = [];
        foreach ($table->getElementsByTagNameNS(NS_ODS_TABLE, 'table-row') as $tr) {
            $line = [];
            $vides = 0;
            foreach ($tr->childNodes as $td) {
                if (!($td instanceof DOMElement) || $td->namespaceURI !== NS_ODS_TABLE || !in_array($td->localName, ['table-cell', 'covered-table-cell'], true)) continue;
                $rep = max(1, (int)$td->getAttributeNS(NS_ODS_TABLE, 'number-columns-repeated'));
                $type = $td->getAttributeNS(NS_ODS_OFFICE, 'value-type');
                if (in_array($type, ['float', 'currency', 'percentage'], true)) $val = fmt_num($td->getAttributeNS(NS_ODS_OFFICE, 'value'));
                elseif ($type === 'date') $val = substr($td->getAttributeNS(NS_ODS_OFFICE, 'date-value'), 0, 10);
                elseif ($type === 'boolean') $val = $td->getAttributeNS(NS_ODS_OFFICE, 'boolean-value') === 'true' ? 'VRAI' : 'FAUX';
                else $val = fmt_ods_text($td);
                $val = trim((string)preg_replace('/\s+/u', ' ', $val));
                if ($val === '') { $vides += min($rep, 1000); continue; }       // cellules vides répétées en fin de ligne : jamais développées
                for (; $vides > 0; $vides--) $line[] = '';
                for ($k = 0; $k < min($rep, 100); $k++) $line[] = $val;
                if (($cells += $rep) > FMT_MAX_CELLS) throw new RuntimeException('Tableur trop grand pour un relevé de compte.');
            }
            if (!$line) continue;                                             // ligne vide (même répétée 1 048 576 fois)
            $repRows = max(1, (int)$tr->getAttributeNS(NS_ODS_TABLE, 'number-rows-repeated'));
            for ($k = 0; $k < min($repRows, 1000); $k++) $rows[] = $line;
        }
        $out[] = $rows;
    }
    return $out;
}
function fmt_ods_text(DOMNode $n): string {
    $s = '';
    foreach ($n->childNodes as $c) {
        if ($c->nodeType === XML_TEXT_NODE || $c->nodeType === XML_CDATA_SECTION_NODE) { $s .= $c->nodeValue; continue; }
        if (!($c instanceof DOMElement)) continue;
        if ($c->localName === 's') { $s .= str_repeat(' ', max(1, (int)$c->getAttributeNS(NS_ODS_TEXT, 'c'))); continue; }
        if ($c->localName === 'tab' || $c->localName === 'line-break') { $s .= ' '; continue; }
        if ($c->localName === 'annotation') continue;
        $s .= fmt_ods_text($c);
        if ($c->localName === 'p') $s .= ' ';
    }
    return $s;
}

/* ========================================================================== */
/* Tableaux HTML (« .xls » de certaines banques)                               */
/* ========================================================================== */

function fmt_html_tables(string $html): array {
    if (!class_exists('DOMDocument')) throw new RuntimeException('Le module DOM de PHP est absent : lecture de ce fichier impossible.');
    $html = (string)preg_replace('/<meta[^>]+charset[^>]*>/i', '', $html);
    $dom = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $out = [];
    foreach ($dom->getElementsByTagName('table') as $t) {
        $rows = [];
        foreach ($t->getElementsByTagName('tr') as $tr) {
            $line = [];
            foreach ($tr->childNodes as $td) {
                if (!($td instanceof DOMElement) || !in_array(strtolower($td->nodeName), ['td', 'th'], true)) continue;
                $line[] = trim((string)preg_replace('/\s+/u', ' ', str_replace("\xC2\xA0", ' ', $td->textContent)));
                $span = min(50, (int)$td->getAttribute('colspan'));
                for ($k = 1; $k < $span; $k++) $line[] = '';
            }
            if (implode('', $line) !== '') $rows[] = $line;
        }
        if ($rows) $out[] = $rows;
    }
    usort($out, function ($a, $b) { return count($b) <=> count($a); });      // le plus grand tableau d'abord
    return $out;
}

/* ========================================================================== */
/* Excel 97-2003 .xls (format binaire BIFF8 dans un « fichier composé » OLE)   */
/* ========================================================================== */

/** Extrait un flux (« Workbook ») d'un fichier composé OLE2 / CFB. */
function fmt_cfb_stream(string $raw, array $names): string {
    if (strlen($raw) < 1024 || substr($raw, 0, 8) !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") throw new RuntimeException('Fichier Excel (.xls) illisible.');
    $h = unpack('vminor/vmajor/vbom/vsecShift/vminiShift', substr($raw, 24, 10));
    $f = unpack('VnumDir/VnumFat/VdirStart/Vtxn/VminiCutoff/VminiFatStart/VnumMiniFat/VdifatStart/VnumDifat', substr($raw, 40, 36));
    if ($h['secShift'] !== 9 && $h['secShift'] !== 12) throw new RuntimeException('Fichier Excel (.xls) illisible (taille de secteur inconnue).');
    $ss = 1 << $h['secShift'];
    $ms = 1 << $h['miniShift'];
    $sector = function (int $i) use ($raw, $ss): string { return (string)substr($raw, ($i + 1) * $ss, $ss); };
    $fatSecs = array_values(unpack('V109', substr($raw, 76, 436)));
    $d = $f['difatStart'];
    for ($guard = 0; $d < 0xFFFFFFFA && $guard < 10000; $guard++) {
        $blk = array_values(unpack('V' . ($ss / 4), $sector($d)));
        $d = (int)array_pop($blk);
        $fatSecs = array_merge($fatSecs, $blk);
    }
    $fat = [];
    foreach ($fatSecs as $s) if ($s < 0xFFFFFFFA) foreach (unpack('V' . ($ss / 4), $sector($s)) as $v) $fat[] = $v;
    $chain = function (int $start, array $table, callable $read) : string {
        $out = '';
        for ($i = $start, $guard = 0; $i < 0xFFFFFFFA && isset($table[$i]) && $guard < 2000000; $guard++) { $out .= $read($i); $i = $table[$i]; }
        return $out;
    };
    $dir = $chain($f['dirStart'], $fat, $sector);
    $entries = [];
    for ($o = 0; $o + 128 <= strlen($dir); $o += 128) {
        $e = substr($dir, $o, 128);
        $len = unpack('v', substr($e, 64, 2))[1];
        $entries[] = ['name' => $len >= 2 ? strtolower(fmt_utf16le(substr($e, 0, min(64, $len) - 2))) : '', 'type' => ord($e[66]),
            'start' => unpack('V', substr($e, 116, 4))[1], 'size' => unpack('V', substr($e, 120, 4))[1]];
    }
    $want = null;
    foreach ($names as $n) { foreach ($entries as $e) if ($e['type'] === 2 && $e['name'] === strtolower($n)) { $want = $e; break 2; } }
    if ($want === null) throw new RuntimeException('Ce fichier .xls ne contient pas de classeur Excel lisible.');
    if ($want['size'] < $f['miniCutoff'] && isset($entries[0])) {
        $mini = $chain($entries[0]['start'], $fat, $sector);
        $miniFat = array_values(unpack('V*', $chain($f['miniFatStart'], $fat, $sector)) ?: []);
        $data = $chain($want['start'], $miniFat, function (int $i) use ($mini, $ms): string { return (string)substr($mini, $i * $ms, $ms); });
    } else {
        $data = $chain($want['start'], $fat, $sector);
    }
    return substr($data, 0, $want['size']);
}

/** Chaîne XLUnicodeString à la position $o (longueur sur 1 ou 2 octets). Rend [texte, position suivante]. */
function fmt_biff_str(string $d, int $o, int $lenBytes = 2): array {
    $cch = $lenBytes === 1 ? ord($d[$o] ?? "\0") : (unpack('v', substr($d, $o, 2) . "\0\0")[1]);
    $o += $lenBytes;
    $flags = ord($d[$o] ?? "\0");
    $o++;
    $runs = 0; $ext = 0;
    if ($flags & 0x08) { $runs = unpack('v', substr($d, $o, 2) . "\0\0")[1]; $o += 2; }
    if ($flags & 0x04) { $ext = unpack('V', substr($d, $o, 4) . "\0\0\0\0")[1]; $o += 4; }
    $bytes = ($flags & 0x01) ? $cch * 2 : $cch;
    $raw = substr($d, $o, $bytes);
    $s = ($flags & 0x01) ? fmt_utf16le($raw) : fmt_latin1($raw);
    return [$s, $o + $bytes + $runs * 4 + $ext];
}

/** Table des textes partagés (SST), découpée en morceaux par les enregistrements CONTINUE. */
function fmt_biff_sst(array $segs): array {
    $out = [];
    if (!$segs || strlen($segs[0]) < 8) return $out;
    $total = unpack('V', substr($segs[0], 4, 4))[1];
    $si = 0; $o = 8; $nseg = count($segs);
    $read = function (int $n) use (&$segs, &$si, &$o, $nseg): string {
        $s = '';
        while ($n > 0 && $si < $nseg) {
            $avail = strlen($segs[$si]) - $o;
            if ($avail <= 0) { $si++; $o = 0; continue; }
            $take = min($n, $avail);
            $s .= substr($segs[$si], $o, $take);
            $o += $take; $n -= $take;
        }
        return $s;
    };
    for ($k = 0; $k < $total && $si < $nseg; $k++) {
        if ($o >= strlen($segs[$si])) { $si++; $o = 0; if ($si >= $nseg) break; }
        $hdr = $read(3);
        if (strlen($hdr) < 3) break;
        $cch = unpack('v', substr($hdr, 0, 2))[1];
        $flags = ord($hdr[2]);
        $high = $flags & 0x01;
        $runs = ($flags & 0x08) ? unpack('v', $read(2) . "\0\0")[1] : 0;
        $ext = ($flags & 0x04) ? unpack('V', $read(4) . "\0\0\0\0")[1] : 0;
        $str = '';
        $left = $cch;
        while ($left > 0 && $si < $nseg) {
            $avail = strlen($segs[$si]) - $o;
            if ($avail <= 0) {
                // le texte continue dans l'enregistrement CONTINUE suivant, qui commence par un nouvel octet d'options
                $si++; $o = 0;
                if ($si >= $nseg) break;
                $high = ord($segs[$si][0]) & 0x01;
                $o = 1;
                continue;
            }
            $per = $high ? 2 : 1;
            $n = min($left, intdiv($avail, $per));
            if ($n === 0) { $o = strlen($segs[$si]); continue; }
            $chunk = substr($segs[$si], $o, $n * $per);
            $o += $n * $per;
            $left -= $n;
            $str .= $high ? fmt_utf16le($chunk) : fmt_latin1($chunk);
        }
        if ($runs || $ext) $read($runs * 4 + $ext);
        $out[] = $str;
    }
    return $out;
}

/** Valeur RK (nombre compacté d'Excel). */
function fmt_biff_rk(int $rk): float {
    if ($rk & 0x02) {
        $v = $rk >> 2;
        if ($v & 0x20000000) $v -= 0x40000000;
        $v = (float)$v;
    } else {
        $v = (float)unpack('e', pack('VV', 0, $rk & 0xFFFFFFFC))[1];
    }
    return ($rk & 0x01) ? $v / 100 : $v;
}

/** Feuilles de calcul d'un classeur .xls (BIFF8). */
function fmt_xls_sheets(string $raw): array {
    $w = fmt_cfb_stream($raw, ['Workbook', 'Book']);
    $len = strlen($w);
    $rec = function (int $p) use ($w, $len): ?array {
        if ($p + 4 > $len) return null;
        $h = unpack('vid/vlen', substr($w, $p, 4));
        return [$h['id'], substr($w, $p + 4, $h['len']), $p + 4 + $h['len']];
    };
    // 1. partie « globale » : textes partagés, formats, styles, feuilles
    $first = $rec(0);
    if ($first === null || $first[0] !== 0x0809) throw new RuntimeException('Classeur .xls illisible.');
    if (unpack('v', substr($first[1], 0, 2) . "\0\0")[1] !== 0x0600) throw new RuntimeException('Ancien classeur Excel 5 / 95 : ouvrez-le et enregistrez-le en .xlsx ou en .csv, ou choisissez l’export CSV de votre banque.');
    $formats = []; $xfs = []; $sheets = []; $sst = []; $s1904 = false;
    $p = $first[2];
    while (($r = $rec($p)) !== null) {
        [$id, $d, $next] = $r;
        if ($id === 0x000A) break;                                            // EOF de la partie globale
        if ($id === 0x0022) $s1904 = unpack('v', $d . "\0\0")[1] === 1;
        elseif ($id === 0x041E) { $ifmt = unpack('v', $d)[1]; [$code] = fmt_biff_str($d, 2); $formats[$ifmt] = $code; }
        elseif ($id === 0x00E0) $xfs[] = unpack('v', substr($d, 2, 2) . "\0\0")[1];
        elseif ($id === 0x0085) { $b = unpack('Vpos/Cstate/Ctype', $d); [$nom] = fmt_biff_str($d, 6, 1); $sheets[] = ['pos' => $b['pos'], 'type' => $b['type'], 'nom' => $nom]; }
        elseif ($id === 0x00FC) {
            $segs = [$d];
            while (($c = $rec($next)) !== null && $c[0] === 0x003C) { $segs[] = $c[1]; $next = $c[2]; }
            $sst = fmt_biff_sst($segs);
        }
        $p = $next;
    }
    $isDate = function (int $xf) use ($xfs, $formats): bool { $f = $xfs[$xf] ?? 0; return fmt_is_date_format($f, $formats[$f] ?? ''); };
    // 2. chaque feuille de calcul
    $out = [];
    $cells = 0;
    foreach ($sheets as $sh) {
        if ($sh['type'] !== 0) continue;                                      // graphiques, macros…
        $grid = [];
        $pending = null;
        $num = function (int $row, int $col, int $xf, float $v) use (&$grid, $isDate, $s1904) {
            $grid[$row][$col] = ($v >= 1 && $isDate($xf)) ? (fmt_serial_date($v, $s1904) ?? fmt_num((string)$v)) : fmt_num(sprintf('%.10F', $v));
        };
        $p = $sh['pos'];
        while (($r = $rec($p)) !== null) {
            [$id, $d, $next] = $r;
            $p = $next;
            if ($id === 0x000A) break;
            if (++$cells > FMT_MAX_CELLS) throw new RuntimeException('Tableur trop grand pour un relevé de compte.');
            if ($id === 0x00FD && strlen($d) >= 10) { $c = unpack('vrow/vcol/vxf/Vi', $d); $grid[$c['row']][$c['col']] = $sst[$c['i']] ?? ''; }
            elseif ($id === 0x0203 && strlen($d) >= 14) { $c = unpack('vrow/vcol/vxf', $d); $num($c['row'], $c['col'], $c['xf'], (float)unpack('e', substr($d, 6, 8))[1]); }
            elseif ($id === 0x027E && strlen($d) >= 10) { $c = unpack('vrow/vcol/vxf/Vrk', $d); $num($c['row'], $c['col'], $c['xf'], fmt_biff_rk($c['rk'])); }
            elseif ($id === 0x00BD && strlen($d) >= 12) {
                $row = unpack('v', $d)[1]; $col = unpack('v', substr($d, 2, 2))[1];
                $n = intdiv(strlen($d) - 6, 6);
                for ($k = 0; $k < $n; $k++) { $c = unpack('vxf/Vrk', substr($d, 4 + $k * 6, 6)); $num($row, $col + $k, $c['xf'], fmt_biff_rk($c['rk'])); }
            }
            elseif ($id === 0x0204 && strlen($d) >= 9) { $c = unpack('vrow/vcol', $d); [$s] = fmt_biff_str($d, 6); $grid[$c['row']][$c['col']] = $s; }
            elseif ($id === 0x0006 && strlen($d) >= 14) {
                $c = unpack('vrow/vcol/vxf', $d);
                if (substr($d, 12, 2) === "\xFF\xFF") {
                    $t = ord($d[6]);
                    if ($t === 0) $pending = [$c['row'], $c['col']];
                    elseif ($t === 1) $grid[$c['row']][$c['col']] = ord($d[8]) ? 'VRAI' : 'FAUX';
                } else $num($c['row'], $c['col'], $c['xf'], (float)unpack('e', substr($d, 6, 8))[1]);
            }
            elseif ($id === 0x0207 && $pending !== null) { [$s] = fmt_biff_str($d, 0); $grid[$pending[0]][$pending[1]] = $s; $pending = null; }
            elseif ($id === 0x0205 && strlen($d) >= 8) { $c = unpack('vrow/vcol/vxf/Cval/Cerr', $d); if (!$c['err']) $grid[$c['row']][$c['col']] = $c['val'] ? 'VRAI' : 'FAUX'; }
        }
        ksort($grid);
        $rows = [];
        foreach ($grid as $cols) {
            $line = [];
            $max = max(array_keys($cols));
            if ($max > 1000) continue;
            for ($k = 0; $k <= $max; $k++) $line[] = trim((string)($cols[$k] ?? ''));
            if (implode('', $line) !== '') $rows[] = $line;
        }
        $out[] = $rows;
    }
    return $out;
}

/* ========================================================================== */
/* ISO 20022 : camt.052 / camt.053 / camt.054                                  */
/* ========================================================================== */

function fmt_parse_camt(string $xml): array {
    $dom = fmt_dom($xml);
    if (!$dom) return ['format' => 'camt.053', 'rows' => [], 'meta' => [], 'warnings' => ['Fichier XML illisible.']];
    $xp = new DOMXPath($dom);
    $L = function (string $n): string { return "*[local-name()='" . $n . "']"; };
    $q = function (string $path, ?DOMNode $ctx = null) use ($xp): array { $l = $ctx ? $xp->query($path, $ctx) : $xp->query($path); return $l === false ? [] : iterator_to_array($l); };
    $txt = function (string $path, DOMNode $ctx) use ($q): string { $n = $q($path, $ctx); return $n ? trim($n[0]->textContent) : ''; };
    $rows = []; $meta = []; $skipped = 0; $kind = 'camt.053';
    if (preg_match('/camt\.05([234])/', substr($xml, 0, 2000), $m)) $kind = 'camt.05' . $m[1];
    $blocs = $q('//' . $L('Stmt') . ' | //' . $L('Rpt') . ' | //' . $L('Ntfctn'));
    foreach ($blocs as $st) {
        $iban = $txt('./' . $L('Acct') . '/' . $L('Id') . '/' . $L('IBAN'), $st);
        if ($iban === '') $iban = $txt('./' . $L('Acct') . '/' . $L('Id') . '//' . $L('Id'), $st);
        if ($iban !== '' && empty($meta['account'])) $meta['account'] = mask_account($iban);
        foreach ($q('./' . $L('Bal'), $st) as $b) {
            $code = $txt('./' . $L('Tp') . '/' . $L('CdOrPrtry') . '/' . $L('Cd'), $b);
            $amt = money_parse($txt('./' . $L('Amt'), $b));
            if ($amt === null) continue;
            if ($txt('./' . $L('CdtDbtInd'), $b) === 'DBIT') $amt = -abs($amt);
            $date = date_parse_any(substr($txt('./' . $L('Dt') . '/*', $b), 0, 10));
            if (($code === 'OPBD' || $code === 'PRCD') && !isset($meta['opening'])) { $meta['opening'] = $amt; if ($date) $meta['period_start'] = $date; }
            if ($code === 'CLBD') { $meta['closing'] = $amt; if ($date) $meta['period_end'] = $date; }
        }
        $du = date_parse_any(substr($txt('./' . $L('FrToDt') . '/' . $L('FrDtTm'), $st), 0, 10));
        $au = date_parse_any(substr($txt('./' . $L('FrToDt') . '/' . $L('ToDtTm'), $st), 0, 10));
        if ($du && empty($meta['period_start'])) $meta['period_start'] = $du;
        if ($au) $meta['period_end'] = $au;
        foreach ($q('./' . $L('Ntry'), $st) as $e) {
            $sts = strtoupper($txt('./' . $L('Sts'), $e));
            if ($sts !== '' && !in_array($sts, ['BOOK', 'BOOKED'], true)) { $skipped++; continue; }
            $amt = money_parse($txt('./' . $L('Amt'), $e));
            if (!$amt) continue;
            $amt = abs($amt) * ($txt('./' . $L('CdtDbtInd'), $e) === 'DBIT' ? -1 : 1);
            $d = $txt('./' . $L('BookgDt') . '/*', $e);
            if ($d === '') $d = $txt('./' . $L('ValDt') . '/*', $e);
            $date = date_parse_any(substr($d, 0, 10));
            if ($date === null) continue;
            $parts = [];
            $add = $txt('./' . $L('AddtlNtryInf'), $e);
            if ($add !== '') $parts[] = $add;
            $tx = $q('./' . $L('NtryDtls') . '/' . $L('TxDtls'), $e);
            if ($tx) {
                $t0 = $tx[0];
                $nm = $txt('./' . $L('RltdPties') . '/' . $L($amt < 0 ? 'Cdtr' : 'Dbtr') . '//' . $L('Nm'), $t0);
                if ($nm !== '') $parts[] = $nm;
                foreach ($q('./' . $L('RmtInf') . '/' . $L('Ustrd'), $t0) as $u) $parts[] = trim($u->textContent);
                $info = $txt('./' . $L('AddtlTxInf'), $t0);
                if ($info !== '') $parts[] = $info;
            }
            $label = '';
            foreach ($parts as $part) {
                $part = trim((string)preg_replace('/\s+/u', ' ', $part));
                if ($part !== '' && !has(norm_text($label), norm_text($part))) $label = trim($label . ' ' . $part);
            }
            if (count($tx) > 1) $label .= ' (' . count($tx) . ' opérations groupées)';
            if ($label === '') $label = $amt < 0 ? 'Débit' : 'Crédit';
            $rows[] = ['date' => $date, 'label' => cut($label, 300), 'amount' => $amt, 'sure' => true];
        }
    }
    usort($rows, function ($a, $b) { return strcmp($a['date'], $b['date']); });
    $warn = [];
    if (!$blocs) $warn[] = 'Ce fichier XML n’est pas un relevé bancaire ISO 20022 (camt.052, camt.053 ou camt.054).';
    elseif (!$rows) $warn[] = 'Aucune opération comptabilisée dans ce relevé XML.';
    if ($skipped) $warn[] = $skipped . ' opération(s) en attente ou pour information ignorée(s).';
    return ['format' => $kind, 'rows' => $rows, 'meta' => $meta, 'warnings' => $warn];
}

/* ========================================================================== */
/* SWIFT MT940                                                                 */
/* ========================================================================== */

function fmt_parse_mt940(string $t): array {
    $t = str_replace("\r", '', $t);
    $t = (string)preg_replace('/\{[1-3]:[^}]*\}/', '', $t);                     // enveloppe SWIFT éventuelle
    $t = str_replace(['{4:', '-}'], ["\n", "\n"], $t);
    $fields = [];
    foreach (explode("\n", $t) as $line) {
        if (preg_match('/^:(\d{2}[A-Z]?):(.*)$/', $line, $m)) $fields[] = [$m[1], [$m[2]]];
        elseif ($fields && trim($line) !== '' && trim($line) !== '-') $fields[count($fields) - 1][1][] = $line;
    }
    $rows = []; $meta = []; $cur = null;
    foreach ($fields as [$tag, $lines]) {
        $val = implode("\n", $lines);
        if ($tag === '25' && empty($meta['account'])) $meta['account'] = mask_account(trim($val));
        elseif ($tag === '60F' || $tag === '60M') { $b = fmt_mt940_balance($val); if ($b && !isset($meta['opening'])) { $meta['opening'] = $b[1]; $meta['period_start'] = $b[0]; } }
        elseif ($tag === '62F' || $tag === '62M') { $b = fmt_mt940_balance($val); if ($b) { $meta['closing'] = $b[1]; $meta['period_end'] = $b[0]; } }
        elseif ($tag === '61') { if ($cur) $rows[] = $cur; $cur = fmt_mt940_line($lines); }
        elseif ($tag === '86' && $cur) { $cur['label'] = fmt_mt940_info($lines, $cur['label']); $rows[] = $cur; $cur = null; }
    }
    if ($cur) $rows[] = $cur;
    $rows = array_values(array_filter($rows));
    return ['format' => 'mt940', 'rows' => $rows, 'meta' => $meta, 'warnings' => $rows ? [] : ['Aucune opération trouvée dans ce fichier MT940.']];
}
function fmt_mt940_amount(string $a): ?int {
    $a = trim($a);
    if ($a === '') return null;
    if (substr($a, -1) === ',') $a .= '00';
    return money_parse($a);
}
function fmt_mt940_balance(string $v): ?array {
    if (!preg_match('/^([CD])(\d{2})(\d{2})(\d{2})[A-Z]{3}([\d,.]+)/', trim($v), $m)) return null;
    $amt = fmt_mt940_amount($m[5]);
    if ($amt === null) return null;
    return ['20' . $m[2] . '-' . $m[3] . '-' . $m[4], $m[1] === 'D' ? -abs($amt) : abs($amt)];
}
/** Ligne :61: — date de valeur AAMMJJ, date comptable MMJJ facultative, sens (C, D, RC, RD), montant, type, référence. */
function fmt_mt940_line(array $lines): ?array {
    $l = trim((string)($lines[0] ?? ''));
    if (!preg_match('/^(\d{2})(\d{2})(\d{2})(\d{4})?(R?[CD])([A-Z])?(\d+,\d{0,2})([A-Z0-9]{4})?(.*)$/', $l, $m)) return null;
    $y = 2000 + (int)$m[1]; $mv = (int)$m[2];
    $date = sprintf('%04d-%02d-%02d', $y, $mv, (int)$m[3]);
    if ($m[4] !== '') {
        $me = (int)substr($m[4], 0, 2);
        $ye = $y + (($mv === 1 && $me === 12) ? -1 : (($mv === 12 && $me === 1) ? 1 : 0));
        $date = sprintf('%04d-%02d-%02d', $ye, $me, (int)substr($m[4], 2, 2));
    }
    if (date_parse_any($date) === null) return null;
    $amt = fmt_mt940_amount($m[7]);
    if (!$amt) return null;
    $neg = in_array($m[5], ['D', 'RC'], true);
    $ref = trim((string)preg_replace('/^NONREF|\/\/.*$/', '', trim($m[9])));
    $suite = isset($lines[1]) ? trim((string)$lines[1]) : '';
    $label = trim(($m[8] ?? '') . ' ' . $ref . ' ' . $suite);
    return ['date' => $date, 'label' => cut($label !== '' ? $label : ($neg ? 'Débit' : 'Crédit'), 300), 'amount' => $neg ? -abs($amt) : abs($amt), 'sure' => true];
}
/** Champ :86: (informations pour le titulaire) : sous-champs ?20…?29 / ?32 (format allemand), /NAME/…/REMI/…, ou texte libre. */
function fmt_mt940_info(array $lines, string $fallback): string {
    $v = '';
    foreach ($lines as $i => $l) {
        $prev = $i > 0 ? (string)$lines[$i - 1] : '';
        $v .= ($i === 0 || strlen(rtrim($prev)) >= 60) ? $l : ' ' . $l;      // une ligne pleine est coupée au milieu d'un mot
    }
    $v = trim($v);
    $parts = [];
    if (preg_match('/^\d{3}\?/', $v) || preg_match('/\?2\d/', $v)) {
        preg_match_all('/\?(\d{2})([^?]*)/', $v, $mm, PREG_SET_ORDER);
        $nom = []; $motif = []; $type = '';
        foreach ($mm as $x) {
            $c = (int)$x[1];
            if ($c === 0) $type = trim($x[2]);
            elseif (($c >= 20 && $c <= 29) || ($c >= 60 && $c <= 63)) $motif[] = $x[2];
            elseif ($c === 32 || $c === 33) $nom[] = $x[2];
        }
        // les sous-champs se suivent sans espace : on isole chaque mot-clé SEPA avant de retirer les références techniques
        $m = (string)preg_replace('/(EREF|MREF|CRED|KREF|SVWZ|ABWA|ABWE|IBAN|BIC)\+/', ' $1+', implode('', $motif));
        $m = str_replace('SVWZ+', '', (string)preg_replace('/\b(EREF|MREF|CRED|KREF|IBAN|BIC|ABWA|ABWE)\+\S*/', '', $m));
        $parts = [implode('', $nom), $type, $m];
    } elseif (preg_match('#^/[A-Z]{3,4}/#', $v)) {
        preg_match_all('#/([A-Z]{3,4})/([^/]*(?:/(?![A-Z]{3,4}/)[^/]*)*)#', $v, $mm, PREG_SET_ORDER);
        $f = [];
        foreach ($mm as $x) $f[$x[1]] = trim(rtrim($x[2], '/ '));
        $parts = [$f['NAME'] ?? ($f['ORDP'] ?? ($f['BENM'] ?? '')), (string)preg_replace('#^USTD/+#', '', $f['REMI'] ?? '')];
    } else $parts = [$v];
    $label = trim((string)preg_replace('/\s+/u', ' ', implode(' ', $parts)));
    return cut($label !== '' ? $label : $fallback, 300);
}

/* ========================================================================== */
/* Écriture : classeur Excel .xlsx                                             */
/* ========================================================================== */

/**
 * Classeur d'une feuille. $cols : [[titre, type, largeur]] avec type « date » (AAAA-MM-JJ), « euros » (centimes) ou « texte ».
 * Première ligne figée, filtre automatique, montants au format « 1 234,56 € ».
 */
function fmt_xlsx_build(string $sheetName, array $cols, array $rows): string {
    $colLetter = function (int $i): string { $s = ''; for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s; return $s; };
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetFormatPr defaultRowHeight="15"/><cols>';
    foreach ($cols as $i => $c) $xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (int)($c[2] ?? 14) . '" customWidth="1"/>';
    $xml .= '</cols><sheetData><row r="1">';
    foreach ($cols as $i => $c) $xml .= '<c r="' . $colLetter($i) . '1" t="inlineStr" s="1"><is><t>' . fmt_xml_esc((string)$c[0]) . '</t></is></c>';
    $xml .= '</row>';
    $r = 1;
    foreach ($rows as $row) {
        $r++;
        $xml .= '<row r="' . $r . '">';
        foreach ($cols as $i => $c) {
            $v = $row[$i] ?? null;
            $ref = $colLetter($i) . $r;
            if ($v === null || $v === '') continue;
            if ($c[1] === 'date' && is_string($v) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) {
                $serial = (int)round((gmmktime(0, 0, 0, (int)$m[2], (int)$m[3], (int)$m[1]) - gmmktime(0, 0, 0, 12, 30, 1899)) / 86400);
                $xml .= '<c r="' . $ref . '" s="2"><v>' . $serial . '</v></c>';
            } elseif ($c[1] === 'euros' && is_numeric($v)) {
                $xml .= '<c r="' . $ref . '" s="3"><v>' . rtrim(rtrim(sprintf('%.2F', (int)$v / 100), '0'), '.') . '</v></c>';
            } else {
                $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . fmt_xml_esc((string)$v) . '</t></is></c>';
            }
        }
        $xml .= '</row>';
    }
    $last = $colLetter(max(0, count($cols) - 1));
    $xml .= '</sheetData><autoFilter ref="A1:' . $last . max(1, $r) . '"/><pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/><pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/></worksheet>';
    $brut = mb_substr_safe((string)preg_replace('/[\[\]:*?\/\\\\]/', ' ', $sheetName), 31);
    $name = fmt_xml_esc($brut);
    $plage = fmt_xml_esc("'" . str_replace("'", "''", $brut) . "'") . '!$A$1:$' . $last . '$' . max(1, $r);
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="2"><numFmt numFmtId="164" formatCode="dd/mm/yyyy"/><numFmt numFmtId="165" formatCode="#,##0.00\ &quot;€&quot;;[Red]\-#,##0.00\ &quot;€&quot;"/></numFmts>'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/><family val="2"/></font><font><b/><sz val="11"/><name val="Calibri"/><family val="2"/></font></fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFEFEFF1"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
        . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    return zip_build([
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>',
        'docProps/core.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>' . $name . '</dc:title><dc:creator>Boussole</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created></cp:coreProperties>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView/></bookViews><sheets><sheet name="' . $name . '" sheetId="1" r:id="rId1"/></sheets><definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">' . $plage . '</definedName></definedNames></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
        'xl/styles.xml' => $styles,
        'xl/worksheets/sheet1.xml' => $xml,
    ]);
}
function mb_substr_safe(string $s, int $n): string { return function_exists('mb_substr') ? (string)mb_substr($s, 0, $n, 'UTF-8') : substr($s, 0, $n); }

/* ========================================================================== */
/* Écriture : OFX et QIF (logiciels de comptes : HomeBank, GnuCash, Money…)    */
/* ========================================================================== */

/** Texte en Windows-1252 (jeu de caractères attendu par les logiciels qui lisent OFX 1.x et QIF). */
function fmt_cp1252(string $s): string {
    $s = str_replace(["\r", "\n", "\t"], ' ', $s);
    if (function_exists('iconv')) { $r = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $s); if ($r !== false) return $r; }
    if (function_exists('mb_convert_encoding')) return (string)mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
    return (string)preg_replace('/[^\x20-\x7E]/', '?', $s);
}

/** $txs : opérations (date, amount en centimes, label, merchant, fp). $meta : account, closing, closing_date. */
function fmt_ofx_build(array $txs, array $meta = []): string {
    $esc = function (string $s, int $max): string { return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], fmt_cp1252(cut($s, $max))); };
    $d = function (string $ymd): string { return str_replace('-', '', $ymd); };
    $dates = array_column($txs, 'date');
    sort($dates);
    $start = $dates ? $dates[0] : date('Y-m-d');
    $end = $dates ? $dates[count($dates) - 1] : date('Y-m-d');
    $o = "OFXHEADER:100\r\nDATA:OFXSGML\r\nVERSION:102\r\nSECURITY:NONE\r\nENCODING:USASCII\r\nCHARSET:1252\r\nCOMPRESSION:NONE\r\nOLDFILEUID:NONE\r\nNEWFILEUID:NONE\r\n\r\n";
    $o .= "<OFX>\r\n<SIGNONMSGSRSV1><SONRS><STATUS><CODE>0<SEVERITY>INFO</STATUS><DTSERVER>" . date('YmdHis') . "<LANGUAGE>FRA</SONRS></SIGNONMSGSRSV1>\r\n";
    $o .= "<BANKMSGSRSV1><STMTTRNRS><TRNUID>1<STATUS><CODE>0<SEVERITY>INFO</STATUS>\r\n<STMTRS><CURDEF>EUR\r\n";
    $o .= '<BANKACCTFROM><BANKID>BOUSSOLE<ACCTID>' . $esc((string)($meta['account'] ?? 'COMPTE'), 22) . "<ACCTTYPE>CHECKING</BANKACCTFROM>\r\n";
    $o .= '<BANKTRANLIST><DTSTART>' . $d($start) . '<DTEND>' . $d($end) . "\r\n";
    foreach ($txs as $t) {
        $amt = (int)$t['amount'];
        $name = trim((string)($t['merchant'] ?? '')) !== '' ? (string)$t['merchant'] : (string)$t['label'];
        $o .= '<STMTTRN><TRNTYPE>' . ($amt < 0 ? 'DEBIT' : 'CREDIT') . '<DTPOSTED>' . $d((string)$t['date']) . '<TRNAMT>' . sprintf('%.2F', $amt / 100)
            . '<FITID>' . substr((string)($t['fp'] ?? sha1($t['date'] . $amt . $t['label'])), 0, 32) . '<NAME>' . $esc($name, 32) . '<MEMO>' . $esc((string)$t['label'], 255) . "</STMTTRN>\r\n";
    }
    $o .= "</BANKTRANLIST>\r\n";
    if (isset($meta['closing'])) $o .= '<LEDGERBAL><BALAMT>' . sprintf('%.2F', (int)$meta['closing'] / 100) . '<DTASOF>' . $d((string)($meta['closing_date'] ?? $end)) . "</LEDGERBAL>\r\n";
    $o .= "</STMTRS></STMTTRNRS></BANKMSGSRSV1>\r\n</OFX>\r\n";
    return $o;
}

function fmt_qif_build(array $txs, array $catLabels = []): string {
    $o = "!Type:Bank\r\n";
    foreach ($txs as $t) {
        $name = trim((string)($t['merchant'] ?? '')) !== '' ? (string)$t['merchant'] : (string)$t['label'];
        $o .= 'D' . substr($t['date'], 8, 2) . '/' . substr($t['date'], 5, 2) . '/' . substr($t['date'], 0, 4) . "\r\n"
            . 'T' . sprintf('%.2F', (int)$t['amount'] / 100) . "\r\n"
            . 'P' . fmt_cp1252(cut($name, 80)) . "\r\n"
            . 'M' . fmt_cp1252(cut((string)$t['label'], 250)) . "\r\n";
        $cat = $catLabels[$t['category'] ?? ''] ?? '';
        if ($cat !== '') $o .= 'L' . fmt_cp1252(str_replace(['[', ']', '/'], ['(', ')', '-'], $cat)) . "\r\n";
        $o .= "^\r\n";
    }
    return $o;
}

/* ========================================================================== */
/* Écriture : courrier Word .docx                                              */
/* ========================================================================== */

/** Courrier au format Word : une ligne du texte = un paragraphe ; la ligne « Objet : » en gras. */
function fmt_docx_build(string $title, string $text): string {
    $paras = '';
    foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
        $bold = preg_match('/^\s*(Objet|OBJET)\s*:/u', $line) === 1;
        $paras .= '<w:p><w:pPr><w:spacing w:after="0" w:line="276" w:lineRule="auto"/></w:pPr>'
            . ($line === '' ? '' : '<w:r>' . ($bold ? '<w:rPr><w:b/></w:rPr>' : '') . '<w:t xml:space="preserve">' . fmt_xml_esc($line) . '</w:t></w:r>') . '</w:p>';
    }
    $doc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><w:body>'
        . $paras
        . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1418" w:right="1418" w:bottom="1418" w:left="1418" w:header="709" w:footer="709" w:gutter="0"/></w:sectPr></w:body></w:document>';
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:eastAsia="Calibri" w:cs="Calibri"/><w:sz w:val="22"/><w:szCs w:val="22"/><w:lang w:val="fr-FR"/></w:rPr></w:rPrDefault><w:pPrDefault><w:pPr><w:spacing w:after="0" w:line="276" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>'
        . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style></w:styles>';
    return zip_build([
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>',
        'docProps/core.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>' . fmt_xml_esc($title) . '</dc:title><dc:creator>Boussole</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created></cp:coreProperties>',
        'word/_rels/document.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
        'word/document.xml' => $doc,
        'word/styles.xml' => $styles,
    ]);
}
