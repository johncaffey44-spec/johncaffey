<?php
/**
 * Boussole — lecture et écriture d'archives ZIP sans l'extension PHP « zip ».
 *
 * Les fichiers Excel (.xlsx) et OpenDocument (.ods) sont des archives ZIP : ce petit module permet
 * de les lire et de les produire même si l'extension zip n'est pas activée (cas fréquent sous
 * Windows). Méthodes gérées : « stockée » (0) et « deflate » (8, via zlib, intégré à PHP).
 */
if (!defined('BOUSSOLE')) { http_response_code(403); exit; }

/** Construit une archive ZIP. $files : [nom dans l'archive => contenu]. */
function zip_build(array $files): string {
    $out = ''; $central = ''; $n = 0;
    [$dosTime, $dosDate] = zip_dos_time(time());
    foreach ($files as $name => $data) {
        $name = str_replace('\\', '/', (string)$name);
        $data = (string)$data;
        $crc = crc32($data);
        $comp = function_exists('gzdeflate') ? gzdeflate($data, 6) : false;
        $method = ($comp !== false && strlen($comp) < strlen($data)) ? 8 : 0;
        if ($method === 0) $comp = $data;
        $flags = preg_match('//u', $name) && preg_match('/[^\x20-\x7E]/', $name) ? 0x0800 : 0;   // noms UTF-8
        $local = pack('VvvvvvVVVvv', 0x04034b50, 20, $flags, $method, $dosTime, $dosDate, $crc, strlen($comp), strlen($data), strlen($name), 0);
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, $flags, $method, $dosTime, $dosDate, $crc, strlen($comp), strlen($data), strlen($name), 0, 0, 0, 0, 0, strlen($out)) . $name;
        $out .= $local . $name . $comp;
        $n++;
    }
    return $out . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, $n, $n, strlen($central), strlen($out), 0);
}

function zip_dos_time(int $t): array {
    $d = getdate($t);
    return [($d['hours'] << 11) | ($d['minutes'] << 5) | intdiv($d['seconds'], 2), (max(1980, $d['year']) - 1980 << 9) | ($d['mon'] << 5) | $d['mday']];
}

/** Lit une archive ZIP en mémoire. Rend [nom => contenu] ; les fichiers chiffrés ou d'autres méthodes sont ignorés. */
function zip_entries(string $raw, int $maxTotal = 200 * 1024 * 1024): array {
    $eocd = strrpos($raw, "PK\x05\x06");
    if ($eocd === false || $eocd + 22 > strlen($raw)) throw new RuntimeException('Archive ZIP illisible.');
    $e = unpack('Vsig/vdisk/vcdisk/ventries/vtotal/Vsize/Voffset/vclen', substr($raw, $eocd, 22));
    $pos = (int)$e['offset'];
    $out = []; $total = 0;
    for ($i = 0; $i < (int)$e['total']; $i++) {
        if (substr($raw, $pos, 4) !== "PK\x01\x02") break;
        $c = unpack('Vsig/vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen/vclen/vdisk/viattr/Vattr/Voffset', substr($raw, $pos, 46));
        $name = substr($raw, $pos + 46, (int)$c['nlen']);
        $pos += 46 + (int)$c['nlen'] + (int)$c['elen'] + (int)$c['clen'];
        if (substr($name, -1) === '/' || ((int)$c['flags'] & 1)) continue;               // dossier ou fichier chiffré
        $lo = (int)$c['offset'];
        if (substr($raw, $lo, 4) !== "PK\x03\x04") continue;
        $l = unpack('vnlen/velen', substr($raw, $lo + 26, 4));
        $data = substr($raw, $lo + 30 + (int)$l['nlen'] + (int)$l['elen'], (int)$c['csize']);
        if ((int)$c['method'] === 8) $data = @gzinflate($data);
        elseif ((int)$c['method'] !== 0) continue;
        if ($data === false) continue;
        $total += strlen($data);
        if ($total > $maxTotal) throw new RuntimeException('Archive trop volumineuse une fois décompressée.');
        $out[$name] = $data;
    }
    return $out;
}

/**
 * Archive ZIP écrite au fil de l'eau dans un flux (téléchargement direct) : chaque fichier est compressé puis écrit
 * aussitôt, la mémoire reste faible même pour des centaines de documents. Rien n'est écrit en clair sur le disque.
 */
final class ZipFlux {
    /** @var resource */
    private $out;
    private $central = '';
    private $offset = 0;
    private $n = 0;
    private $names = [];

    /** @param resource $handle */
    public function __construct($handle) { $this->out = $handle; }

    /** Nom unique dans l'archive : « facture.pdf », puis « facture (2).pdf »… */
    public function uniqueName(string $name): string {
        $name = trim(str_replace('\\', '/', $name), '/');
        if ($name === '') $name = 'document';
        $base = $name; $i = 2;
        while (isset($this->names[strtolower($name)])) {
            $dot = strrpos($base, '.');
            $slash = strrpos($base, '/');
            $name = ($dot !== false && ($slash === false || $dot > $slash)) ? substr($base, 0, $dot) . ' (' . $i . ')' . substr($base, $dot) : $base . ' (' . $i . ')';
            $i++;
        }
        $this->names[strtolower($name)] = true;
        return $name;
    }

    public function add(string $name, string $data, ?int $time = null): string {
        $name = $this->uniqueName($name);
        [$dosTime, $dosDate] = zip_dos_time($time ?? time());
        $crc = crc32($data);
        $comp = function_exists('gzdeflate') ? gzdeflate($data, 6) : false;
        $method = ($comp !== false && strlen($comp) < strlen($data)) ? 8 : 0;
        if ($method === 0) $comp = $data;
        $flags = preg_match('/[^\x20-\x7E]/', $name) ? 0x0800 : 0;
        $local = pack('VvvvvvVVVvv', 0x04034b50, 20, $flags, $method, $dosTime, $dosDate, $crc, strlen($comp), strlen($data), strlen($name), 0) . $name;
        $this->central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, $flags, $method, $dosTime, $dosDate, $crc, strlen($comp), strlen($data), strlen($name), 0, 0, 0, 0, 0, $this->offset) . $name;
        fwrite($this->out, $local);
        fwrite($this->out, $comp);
        $this->offset += strlen($local) + strlen($comp);
        $this->n++;
        return $name;
    }

    public function count(): int { return $this->n; }

    public function finish(): void {
        fwrite($this->out, $this->central);
        fwrite($this->out, pack('VvvvvVVv', 0x06054b50, 0, 0, $this->n, $this->n, strlen($this->central), $this->offset, 0));
        fflush($this->out);
    }
}

/** Nom de fichier sans caractères interdits par Windows (\ / : * ? " < > |) ni espaces superflus. */
function zip_safe_name(string $s, int $max = 90): string {
    $s = (string)preg_replace('/[\x00-\x1F\\\\\/:*?"<>|]+/u', '_', $s);
    $s = trim((string)preg_replace('/\s+/u', ' ', $s), ' .');
    if (function_exists('mb_substr')) $s = mb_substr($s, 0, $max, 'UTF-8'); else $s = substr($s, 0, $max);
    return $s === '' ? 'document' : $s;
}
