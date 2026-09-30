<?php
/**
 * D8 Production · Planning — stockage partagé (optionnel)
 * -----------------------------------------------------------------------------
 * Déposez ce fichier À CÔTÉ de planning-d8.html sur un serveur web interne
 * disposant de PHP 7.4 ou plus (IIS + PHP, Apache, nginx…). L'application le
 * détecte seule au démarrage et bascule de « base locale au navigateur » à
 * « base partagée » : tout le monde voit le même planning, les vues écrans
 * comprises.
 *
 * Fonctionnement : un document JSON unique, versionné. Chaque enregistrement
 * indique la version sur laquelle il s'appuie ; si quelqu'un a enregistré
 * entre-temps, le serveur répond 409 et l'application rejoue ses modifications
 * sur la version à jour. Adapté à quelques dizaines d'utilisateurs et quelques
 * milliers de projets — au-delà, il faut une vraie base de données.
 *
 * SÉCURITÉ — à lire avant la mise en service :
 *   1. Ce script n'authentifie personne. Protégez le dossier au niveau du
 *      serveur (authentification Windows sur IIS, .htaccess, VPN) et gardez-le
 *      sur le réseau interne.
 *   2. $ALLOWED_NETS limite l'accès aux plages IP internes : ajustez-le.
 *   3. Le dossier « data » contient toutes les données : placez-le hors de la
 *      racine web si votre hébergement le permet ($DATA_DIR ci-dessous), et
 *      sauvegardez-le avec vos autres données (Iperius, snapshot NAS…).
 * -----------------------------------------------------------------------------
 */
declare(strict_types=1);

$DATA_DIR      = __DIR__ . '/data';   // idéalement hors racine web : '/var/planning-data' ou 'D:\\planning-data'
$ALLOWED_NETS  = ['127.0.0.0/8', '::1/128', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'];
$KEEP_BACKUPS  = 150;                 // nombre de copies conservées dans data/backups
$BACKUP_EVERY  = 900;                 // une copie au maximum toutes les N secondes
$MAX_BYTES     = 40 * 1024 * 1024;    // taille maximale acceptée pour un enregistrement

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex');

function out(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function ip_in(string $ip, string $cidr): bool {
    $parts = explode('/', $cidr, 2);
    $net = $parts[0];
    $ipb = @inet_pton($ip);
    $netb = @inet_pton($net);
    if ($ipb === false || $netb === false || strlen($ipb) !== strlen($netb)) return false;
    $bits = isset($parts[1]) ? (int)$parts[1] : strlen($ipb) * 8;
    $bytes = intdiv($bits, 8);
    $rem = $bits % 8;
    if ($bytes > 0 && strncmp($ipb, $netb, $bytes) !== 0) return false;
    if ($rem === 0) return true;
    $mask = chr((0xff << (8 - $rem)) & 0xff);
    return ($ipb[$bytes] & $mask) === ($netb[$bytes] & $mask);
}
function valid_json(string $s): bool {
    if (function_exists('json_validate')) return json_validate($s);   // PHP 8.3+
    json_decode($s);
    return json_last_error() === JSON_ERROR_NONE;
}

/* --- contrôle d'accès réseau --- */
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$allowed = empty($ALLOWED_NETS);
foreach ($ALLOWED_NETS as $net) { if (ip_in($ip, $net)) { $allowed = true; break; } }
if (!$allowed) out(403, ['error' => "Accès refusé pour l'adresse $ip"]);

/* --- dossier de données --- */
if (!is_dir($DATA_DIR) && !@mkdir($DATA_DIR, 0770, true) && !is_dir($DATA_DIR)) {
    out(500, ['error' => 'Impossible de créer le dossier de données']);
}
if (!is_writable($DATA_DIR)) out(500, ['error' => 'Dossier de données non accessible en écriture']);
// refus d'accès direct au dossier (Apache puis IIS)
if (!file_exists("$DATA_DIR/.htaccess")) @file_put_contents("$DATA_DIR/.htaccess", "Require all denied\nDeny from all\n");
if (!file_exists("$DATA_DIR/web.config")) @file_put_contents("$DATA_DIR/web.config",
    '<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer><security><requestFiltering>'
  . '<fileExtensions allowUnlisted="false" /></requestFiltering></security></system.webServer></configuration>');

$FILE = "$DATA_DIR/planning.json";
$META = "$DATA_DIR/planning.meta.json";
$LOCK = "$DATA_DIR/planning.lock";

function read_meta(string $META): array {
    $m = is_file($META) ? json_decode((string)file_get_contents($META), true) : null;
    return is_array($m) ? $m + ['version' => 0, 'savedAt' => null, 'by' => null] : ['version' => 0, 'savedAt' => null, 'by' => null];
}

$action = (string)($_GET['a'] ?? 'ping');
$lock = fopen($LOCK, 'c');
if ($lock === false) out(500, ['error' => 'Verrou indisponible']);

/* --- état --- */
if ($action === 'ping') {
    flock($lock, LOCK_SH);
    $m = read_meta($META);
    flock($lock, LOCK_UN);
    out(200, ['ok' => true, 'version' => (int)$m['version'], 'savedAt' => $m['savedAt'], 'by' => $m['by'], 'php' => PHP_VERSION]);
}

/* --- lecture --- */
if ($action === 'load') {
    flock($lock, LOCK_SH);
    $m = read_meta($META);
    $raw = is_file($FILE) ? (string)file_get_contents($FILE) : '';
    flock($lock, LOCK_UN);
    http_response_code(200);
    echo '{"version":' . (int)$m['version']
       . ',"savedAt":' . json_encode($m['savedAt'])
       . ',"data":' . ($raw !== '' ? $raw : 'null') . '}';
    exit;
}

/* --- enregistrement --- */
if ($action === 'save') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') out(405, ['error' => 'POST attendu']);
    $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($len > $MAX_BYTES) out(413, ['error' => 'Données trop volumineuses']);
    $body = (string)file_get_contents('php://input');
    if ($body === '' || strlen($body) > $MAX_BYTES) out(400, ['error' => 'Corps de requête vide ou trop grand']);
    if (!valid_json($body)) out(400, ['error' => 'JSON invalide']);
    $base = isset($_GET['base']) ? (int)$_GET['base'] : -1;
    $by = substr((string)($_GET['user'] ?? ''), 0, 80);

    flock($lock, LOCK_EX);
    $m = read_meta($META);
    $cur = (int)$m['version'];
    if ($base !== $cur) {                       // conflit : on renvoie la version du serveur
        $raw = is_file($FILE) ? (string)file_get_contents($FILE) : '';
        flock($lock, LOCK_UN);
        http_response_code(409);
        echo '{"version":' . $cur . ',"savedAt":' . json_encode($m['savedAt']) . ',"data":' . ($raw !== '' ? $raw : 'null') . '}';
        exit;
    }
    // copie de sécurité espacée dans le temps
    $bdir = "$DATA_DIR/backups";
    if (is_file($FILE)) {
        if (!is_dir($bdir)) @mkdir($bdir, 0770, true);
        $last = 0;
        $files = is_dir($bdir) ? glob("$bdir/planning-*.json") : [];
        if ($files) { $last = max(array_map('filemtime', $files)); }
        if (time() - $last > $BACKUP_EVERY) {
            @copy($FILE, sprintf('%s/planning-%s-v%06d.json', $bdir, date('Ymd-His'), $cur));
            $files = glob("$bdir/planning-*.json") ?: [];
            if (count($files) > $KEEP_BACKUPS) {
                sort($files);
                foreach (array_slice($files, 0, count($files) - $KEEP_BACKUPS) as $old) @unlink($old);
            }
        }
    }
    $tmp = $FILE . '.tmp';
    if (@file_put_contents($tmp, $body, LOCK_EX) === false || !@rename($tmp, $FILE)) {
        flock($lock, LOCK_UN);
        out(500, ['error' => 'Écriture impossible']);
    }
    $new = ['version' => $cur + 1, 'savedAt' => date('c'), 'by' => $by, 'bytes' => strlen($body)];
    @file_put_contents($META, json_encode($new, JSON_UNESCAPED_UNICODE));
    flock($lock, LOCK_UN);
    out(200, ['version' => $new['version'], 'savedAt' => $new['savedAt']]);
}

out(400, ['error' => 'Action inconnue']);
