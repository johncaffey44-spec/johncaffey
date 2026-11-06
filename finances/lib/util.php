<?php
/**
 * Boussole — fonctions communes (montants, dates, textes).
 * Compatible PHP 7.4 et plus. Les montants sont toujours manipulés en CENTIMES (entiers)
 * pour éviter les erreurs d'arrondi des nombres à virgule.
 */
if (!defined('BOUSSOLE')) { http_response_code(403); exit; }

const MOIS_FR = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
const MOIS_ABR = ['JANV' => 1, 'JAN' => 1, 'JANVIER' => 1, 'FEVR' => 2, 'FEV' => 2, 'FEVRIER' => 2, 'MARS' => 3, 'MAR' => 3, 'AVR' => 4, 'AVRIL' => 4,
    'MAI' => 5, 'JUIN' => 6, 'JUIL' => 7, 'JUILLET' => 7, 'AOUT' => 8, 'AOU' => 8, 'SEPT' => 9, 'SEP' => 9, 'SEPTEMBRE' => 9, 'OCT' => 10, 'OCTOBRE' => 10,
    'NOV' => 11, 'NOVEMBRE' => 11, 'DEC' => 12, 'DECEMBRE' => 12,
    'JANUARY' => 1, 'FEB' => 2, 'FEBRUARY' => 2, 'MARCH' => 3, 'APR' => 4, 'APRIL' => 4, 'MAY' => 5, 'JUN' => 6, 'JUNE' => 6, 'JUL' => 7, 'JULY' => 7,
    'AUG' => 8, 'AUGUST' => 8, 'SEPTEMBER' => 9, 'OCTOBER' => 10, 'NOVEMBER' => 11, 'DECEMBER' => 12];

function has(string $hay, string $needle): bool { return $needle === '' || strpos($hay, $needle) !== false; }
function starts(string $hay, string $needle): bool { return strncmp($hay, $needle, strlen($needle)) === 0; }

/** Texte « normalisé » : majuscules sans accents, uniquement lettres/chiffres/espaces. Sert aux comparaisons. */
function norm_text(string $s): string {
    static $map = null;
    if ($map === null) {
        $map = ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ì' => 'i', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ù' => 'u', 'û' => 'u',
            'ü' => 'u', 'ú' => 'u', 'ÿ' => 'y', 'ñ' => 'n', 'œ' => 'oe', 'æ' => 'ae', 'ß' => 'ss',
            'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Á' => 'A', 'Ã' => 'A', 'Å' => 'A', 'Ç' => 'C', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'Î' => 'I', 'Ï' => 'I', 'Í' => 'I', 'Ì' => 'I', 'Ô' => 'O', 'Ö' => 'O', 'Ó' => 'O', 'Ò' => 'O', 'Õ' => 'O', 'Ù' => 'U', 'Û' => 'U',
            'Ü' => 'U', 'Ú' => 'U', 'Ÿ' => 'Y', 'Ñ' => 'N', 'Œ' => 'OE', 'Æ' => 'AE', '’' => ' ', '\'' => ' '];
    }
    $s = strtr($s, $map);
    $s = strtoupper($s);
    $s = preg_replace('/[^A-Z0-9]+/', ' ', $s);
    return trim((string)$s);
}

/** Coupe une chaîne UTF-8 sans casser un caractère. */
function cut(string $s, int $n): string {
    if (function_exists('mb_substr')) return mb_substr($s, 0, $n, 'UTF-8');
    return preg_match('/^.{0,' . $n . '}/us', $s, $m) ? $m[0] : substr($s, 0, $n);
}

/** Nettoie un texte saisi : supprime les caractères de contrôle, coupe à $n caractères. */
function clean_str($v, int $n = 200): string {
    $s = is_scalar($v) ? (string)$v : '';
    if (!preg_match('//u', $s)) $s = utf8_from_any($s);
    $s = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
    return trim(cut($s, $n));
}

/** Convertit en UTF-8 un texte venant d'un export bancaire (souvent Windows-1252). */
function utf8_from_any(string $s): string {
    if (starts($s, "\xEF\xBB\xBF")) $s = substr($s, 3);
    if (preg_match('//u', $s)) return $s;
    if (function_exists('mb_convert_encoding')) return (string)mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    if (function_exists('iconv')) { $r = @iconv('Windows-1252', 'UTF-8//IGNORE', $s); if ($r !== false) return $r; }
    return (string)preg_replace('/[\x80-\xFF]/', '?', $s);
}

/**
 * Lit un montant écrit « à la française » ou « à l'anglaise » et le rend en centimes.
 * Accepte : 1 234,56 · 1.234,56 · 1234.56 · -12,30 · 12,30- · +12,30 · (12,30) · 12,30 € · 1,234.56 · 45,00 D / 45,00 C
 * Rend null si ce n'est pas un montant.
 */
function money_parse(string $s): ?int {
    $s = trim(str_replace(["\xC2\xA0", "\xE2\x80\xAF", "\xE2\x80\x89", '€', 'EUR', 'eur'], ' ', $s));
    $s = str_replace(['−', '–'], '-', $s);
    if ($s === '') return null;
    $neg = false;
    if (preg_match('/^\((.*)\)$/', $s, $m)) { $neg = true; $s = trim($m[1]); }
    if (preg_match('/^(.*?)\s*(DB|D|CR|C)$/i', $s, $m) && preg_match('/\d/', $m[1])) { if (strtoupper($m[2][0]) === 'D') $neg = !$neg; $s = trim($m[1]); }
    if (substr($s, -1) === '-') { $neg = !$neg; $s = trim(substr($s, 0, -1)); }
    if ($s !== '' && ($s[0] === '-' || $s[0] === '+')) { if ($s[0] === '-') $neg = !$neg; $s = trim(substr($s, 1)); }
    $s = str_replace(' ', '', $s);
    if ($s === '' || !preg_match('/^[0-9.,\']+$/', $s)) return null;
    $s = str_replace('\'', '', $s);
    $lastC = strrpos($s, ',');
    $lastD = strrpos($s, '.');
    if ($lastC !== false && $lastD !== false) {               // les deux : le dernier est le séparateur décimal
        $dec = $lastC > $lastD ? ',' : '.';
        $s = str_replace($dec === ',' ? '.' : ',', '', $s);
        $s = str_replace($dec, '.', $s);
    } elseif ($lastC !== false) {                              // virgule seule : décimale si 1 ou 2 chiffres après
        $after = strlen($s) - $lastC - 1;
        if (substr_count($s, ',') === 1 && $after >= 1 && $after <= 2) $s = str_replace(',', '.', $s);
        elseif ($after === 3) $s = str_replace(',', '', $s);  // 1,234 (milliers à l'anglaise)
        else return null;
    } elseif ($lastD !== false) {
        $after = strlen($s) - $lastD - 1;
        if (substr_count($s, '.') === 1 && $after >= 1 && $after <= 2) { /* 1234.56 */ }
        elseif ($after === 3 || substr_count($s, '.') > 1) $s = str_replace('.', '', $s); // 1.234 ou 1.234.567
        else return null;
    }
    if (!is_numeric($s)) return null;
    $c = (int)round(((float)$s) * 100);
    return $neg ? -$c : $c;
}

/** 123456 → « 1 234,56 € » (espace insécable fine). */
function money_fmt(int $cents, bool $sign = false): string {
    $neg = $cents < 0;
    $a = abs($cents);
    $s = number_format(intdiv($a, 100), 0, ',', "\u{202F}") . ',' . str_pad((string)($a % 100), 2, '0', STR_PAD_LEFT);
    return ($neg ? '−' : ($sign && $cents > 0 ? '+' : '')) . $s . "\u{00A0}€";
}

/** Date « JJ/MM/AAAA », « JJ.MM.AA », « AAAA-MM-JJ », « 3 oct. 2026 »… → « AAAA-MM-JJ » (ou null). $dayFirst gère l'ambiguïté US. */
function date_parse_any(string $s, ?int $defYear = null, bool $dayFirst = true): ?string {
    $s = trim($s);
    if ($s === '') return null;
    if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})(?:[T ].*)?$/', $s, $m)) return ymd((int)$m[1], (int)$m[2], (int)$m[3]);
    if (preg_match('/^(\d{4})(\d{2})(\d{2})/', $s, $m) && strlen(preg_replace('/\D/', '', substr($s, 0, 8))) === 8) return ymd((int)$m[1], (int)$m[2], (int)$m[3]);
    if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})(?:[\/.\-\'](\d{2,4}))?(?:\s.*)?$/', $s, $m)) {
        $a = (int)$m[1]; $b = (int)$m[2];
        $y = isset($m[3]) && $m[3] !== '' ? (int)$m[3] : ($defYear ?? (int)date('Y'));
        if ($y < 100) $y += 2000;
        [$d, $mo] = $dayFirst ? [$a, $b] : [$b, $a];
        if ($mo > 12 && $d <= 12) { [$d, $mo] = [$mo, $d]; }
        return ymd($y, $mo, $d);
    }
    $n = norm_text($s);
    if (preg_match('/^(\d{1,2}) ([A-Z]{3,9})(?: (\d{2,4}))?/', $n, $m) && isset(MOIS_ABR[$m[2]])) {
        $y = isset($m[3]) ? (int)$m[3] : ($defYear ?? (int)date('Y'));
        if ($y < 100) $y += 2000;
        return ymd($y, MOIS_ABR[$m[2]], (int)$m[1]);
    }
    return null;
}
function ymd(int $y, int $m, int $d): ?string {
    if ($y < 1990 || $y > 2100 || !checkdate($m, $d, $y)) return null;
    return sprintf('%04d-%02d-%02d', $y, $m, $d);
}
function month_of(string $ymd): string { return substr($ymd, 0, 7); }
function month_add(string $ym, int $n): string {
    $y = (int)substr($ym, 0, 4); $m = (int)substr($ym, 5, 2) + $n;
    while ($m > 12) { $m -= 12; $y++; }
    while ($m < 1) { $m += 12; $y--; }
    return sprintf('%04d-%02d', $y, $m);
}
function days_between(string $a, string $b): int { return (int)round((strtotime($b . ' 12:00:00') - strtotime($a . ' 12:00:00')) / 86400); }
function date_add_days(string $ymd, int $n): string { return date('Y-m-d', strtotime($ymd . ' 12:00:00') + $n * 86400); }
function days_in_month(string $ym): int { return (int)date('t', strtotime($ym . '-01')); }
function fr_date(?string $ymd): string { return $ymd ? substr($ymd, 8, 2) . '/' . substr($ymd, 5, 2) . '/' . substr($ymd, 0, 4) : ''; }
function fr_month(string $ym): string { return (MOIS_FR[(int)substr($ym, 5, 2)] ?? '') . ' ' . substr($ym, 0, 4); }
function fr_date_long(string $ymd): string { return ltrim(substr($ymd, 8, 2), '0') . ' ' . MOIS_FR[(int)substr($ymd, 5, 2)] . ' ' . substr($ymd, 0, 4); }

function median(array $v): float {
    if (!$v) return 0.0;
    sort($v);
    $n = count($v);
    return $n % 2 ? (float)$v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
}
function pct(float $part, float $whole): float { return $whole > 0 ? round($part / $whole * 100, 1) : 0.0; }

/** Masque un numéro de compte / IBAN : on ne garde que les 4 derniers caractères. */
function mask_account(string $s): string {
    $c = preg_replace('/\s+/', '', $s);
    if (strlen($c) <= 4) return $c;
    return (preg_match('/^[A-Z]{2}\d{2}/', $c) ? substr($c, 0, 4) . ' ' : '') . '•••• ' . substr($c, -4);
}

function json_enc($v): string { return (string)json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
function json_dec(?string $s): array { $d = $s ? json_decode($s, true) : null; return is_array($d) ? $d : []; }
