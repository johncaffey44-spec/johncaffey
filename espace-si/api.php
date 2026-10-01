<?php
/**
 * D8 · Espace SI — serveur de l'outil d'échange du service informatique
 * -----------------------------------------------------------------------------
 * Déposez ce dossier (index.html + api.php + .user.ini + web.config) sur un
 * serveur web interne avec PHP 7.4 ou plus (IIS + PHP, Apache, nginx, NAS
 * Synology/QNAP avec Web Station). Donnez au compte du serveur web le droit
 * d'ÉCRIRE dans ce dossier : api.php y crée le sous-dossier « data ».
 *
 * PREMIÈRE CONNEXION : à la toute première exécution, api.php crée les comptes
 * de l'équipe ($TEAM ci-dessous) SANS mot de passe, et écrit un code
 * d'activation à usage unique par personne dans data/PREMIERE-CONNEXION.txt
 * (lisible uniquement sur le serveur). Chacun saisit son identifiant et son
 * code, puis choisit son mot de passe. Le code est ensuite détruit.
 *
 * STOCKAGE : un fichier JSON par type de données (data/store), un fichier par
 * mois pour les messages, les pièces jointes dans data/files. Chaque
 * modification reçoit un numéro de séquence ; les postes ne récupèrent que ce
 * qui a changé depuis leur dernier passage (toutes les 2 à 3 secondes).
 * Adapté à une équipe de quelques dizaines de personnes au plus.
 *
 * SÉCURITÉ — à lire avant la mise en service :
 *   1. En http (ex. http://192.168.1.174/espace-si/), tout fonctionne sauf
 *      ce que les navigateurs réservent au HTTPS : caméra, micro, partage
 *      d'écran, notifications Windows. L'application masque alors ces
 *      fonctions. Les mots de passe circulent en clair sur le réseau local.
 *   2. $ALLOWED_NETS limite l'accès aux plages IP internes : ajustez-le.
 *   3. Le dossier « data » contient tout (comptes, messages, fichiers) :
 *      placez-le hors de la racine web si possible ($DATA_DIR) et incluez-le
 *      dans vos sauvegardes. api.php en fait aussi une copie quotidienne.
 *   4. Contrairement à un simple stockage, ce serveur applique les droits :
 *      notes et tâches privées, messages privés, validation des absences et
 *      des heures réservée aux responsables, paramètres réservés aux admins.
 * -----------------------------------------------------------------------------
 */
declare(strict_types=1);

$DATA_DIR      = __DIR__ . '/data';   // idéalement hors racine web : 'D:\\espace-si-data' ou '/var/espace-si-data'
$ALLOWED_NETS  = ['127.0.0.0/8', '::1/128', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', 'fc00::/7', 'fe80::/10'];
$TIMEZONE      = 'Europe/Paris';

/* --- équipe créée à la première exécution (modifiable ensuite dans Paramètres › Équipe) --- */
$TEAM = [
    ['id' => 'u_mzidani', 'firstName' => 'Mohamed', 'lastName' => 'Zidani', 'login' => 'mohamed.zidani', 'role' => 'membre', 'validator' => false, 'color' => '#1487c9'],
    ['id' => 'u_tmefre',  'firstName' => 'Tafré',   'lastName' => 'Mefré',  'login' => 'tafre.mefre',    'role' => 'admin',  'validator' => true,  'color' => '#2c8f55'],
    ['id' => 'u_lgasp',   'firstName' => 'Ludovic', 'lastName' => 'Gasp',   'login' => 'ludovic.gasp',   'role' => 'membre', 'validator' => false, 'color' => '#c2721a'],
    ['id' => 'u_vgomes',  'firstName' => 'Victor',  'lastName' => 'Gomes',  'login' => 'victor.gomes',   'role' => 'membre', 'validator' => false, 'color' => '#8a4fc7'],
];

/* --- connexion --- */
$REQUIRE_CODE  = true;                // première connexion : code d'activation exigé (recommandé)
$SESSION_IDLE  = 12 * 3600;           // déconnexion après N secondes sans échange avec le serveur
$MIN_PASSWORD  = 10;                  // longueur minimale (+ 3 types de caractères sur 4)
$MAX_FAILS     = 8;                   // échecs tolérés par adresse IP…
$FAIL_WINDOW   = 900;                 // …sur cette durée (s), puis blocage pendant la même durée

/* --- fichiers --- */
$MAX_FILE      = 1024 * 1024 * 1024;  // taille maximale d'une pièce jointe (1 Go)
$MAX_CHUNK     = 8 * 1024 * 1024;     // envoi par morceaux de 4 Mo : pas besoin de modifier php.ini

/* --- sauvegardes et divers --- */
$KEEP_BACKUPS  = 30;                  // copies quotidiennes conservées dans data/backups
$TOMBSTONE_DAYS = 30;                 // éléments supprimés gardés N jours (corbeille), puis purgés
/* Appels vidéo : en réseau local, aucun serveur STUN/TURN n'est nécessaire. Pour des postes
   en VPN ou sur des réseaux différents, renseignez un serveur TURN interne (coturn), ex. :
   [['urls' => 'turn:turn.d8.local:3478', 'username' => 'espace', 'credential' => 'secret']] */
$ICE_SERVERS   = [];

date_default_timezone_set($TIMEZONE);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex');
header('Referrer-Policy: same-origin');

/* ============================== outils ============================== */
function out(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}
function ip_in(string $ip, string $cidr): bool {
    $parts = explode('/', $cidr, 2);
    $ipb = @inet_pton($ip);
    $netb = @inet_pton($parts[0]);
    if ($ipb === false || $netb === false || strlen($ipb) !== strlen($netb)) return false;
    $bits = isset($parts[1]) ? (int)$parts[1] : strlen($ipb) * 8;
    $bytes = intdiv($bits, 8);
    $rem = $bits % 8;
    if ($bytes > 0 && strncmp($ipb, $netb, $bytes) !== 0) return false;
    if ($rem === 0) return true;
    $mask = chr((0xff << (8 - $rem)) & 0xff);
    return ($ipb[$bytes] & $mask) === ($netb[$bytes] & $mask);
}
function read_json(string $f): array {
    $d = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    return is_array($d) ? $d : [];
}
function write_json(string $f, $d, bool $pretty = false): bool {
    $tmp = $f . '.' . bin2hex(random_bytes(3)) . '.tmp';
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR | ($pretty ? JSON_PRETTY_PRINT : 0);
    if (@file_put_contents($tmp, json_encode($d, $flags), LOCK_EX) === false) return false;
    if (!@rename($tmp, $f)) {                    // Windows : rename sur un fichier existant peut échouer
        @unlink($f);
        if (!@rename($tmp, $f)) { @unlink($tmp); return false; }
    }
    return true;
}
function body_json(int $max = 2097152): array {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') out(405, ['error' => 'POST attendu']);
    if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') === false) out(415, ['error' => 'JSON attendu']);
    $raw = (string)file_get_contents('php://input', false, null, 0, $max + 1);
    if (strlen($raw) > $max) out(413, ['error' => 'Contenu trop volumineux']);
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}
function cut(string $s, int $n): string { return preg_match('/^.{0,' . $n . '}/us', $s, $m) ? $m[0] : ''; }
function now_iso(): string {
    $t = microtime(true);
    return date('Y-m-d\TH:i:s', (int)$t) . sprintf('.%03d', (int)(($t - floor($t)) * 1000)) . date('P', (int)$t);
}
function clean_login($s): string { return strtolower(trim((string)$s)); }
function login_ok(string $l): bool { return (bool)preg_match('/^[a-z0-9._@-]{3,60}$/', $l); }
function id_ok($s): bool { return is_string($s) && (bool)preg_match('/^[A-Za-z][A-Za-z0-9_-]{2,63}$/', $s); }
function is_list_array($a): bool { return is_array($a) && ($a === [] || array_keys($a) === range(0, count($a) - 1)); }
function password_strength_error(string $p, string $login, int $min): ?string {
    if (strlen($p) < $min) return "Mot de passe trop court ($min caractères minimum).";
    if (strlen($p) > 200) return 'Mot de passe trop long.';
    $kinds = (int)preg_match('/[a-z]/', $p) + (int)preg_match('/[A-Z]/', $p) + (int)preg_match('/[0-9]/', $p) + (int)preg_match('/[^a-zA-Z0-9]/', $p);
    if ($kinds < 3) return 'Le mot de passe doit mélanger au moins 3 types de caractères : minuscules, majuscules, chiffres, caractères spéciaux.';
    if (stripos($p, $login) !== false || stripos($login, $p) !== false) return 'Le mot de passe ne doit pas contenir l’identifiant.';
    return null;
}
function page_build(): ?string {
    $p = basename((string)($_GET['page'] ?? 'index.html'));
    if ($p === '') $p = 'index.html';
    $f = __DIR__ . '/' . $p;
    if (!preg_match('/^[\w.-]+\.html?$/i', $p) || !is_file($f)) return null;
    clearstatcache(true, $f);
    return filemtime($f) . '-' . filesize($f);
}
function new_code(): string {
    $abc = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';   // sans 0/O ni 1/I/L
    $s = '';
    for ($i = 0; $i < 8; $i++) $s .= $abc[random_int(0, strlen($abc) - 1)];
    return substr($s, 0, 4) . '-' . substr($s, 4);
}
/** minuscules sans accents (sans dépendre des extensions mbstring/intl) */
function fold(string $s): string {
    static $map = null;
    if ($map === null) {
        $map = [];
        $src = ['àáâãäå' => 'a', 'ç' => 'c', 'èéêë' => 'e', 'ìíîï' => 'i', 'ñ' => 'n', 'òóôõö' => 'o', 'ùúûü' => 'u', 'ýÿ' => 'y', 'œ' => 'oe', 'æ' => 'ae',
                'ÀÁÂÃÄÅ' => 'a', 'Ç' => 'c', 'ÈÉÊË' => 'e', 'ÌÍÎÏ' => 'i', 'Ñ' => 'n', 'ÒÓÔÕÖ' => 'o', 'ÙÚÛÜ' => 'u', 'Ý' => 'y', 'Œ' => 'oe', 'Æ' => 'ae'];
        foreach ($src as $chars => $to) foreach (preg_split('//u', $chars, -1, PREG_SPLIT_NO_EMPTY) as $ch) $map[$ch] = $to;
    }
    return strtolower(strtr($s, $map));
}

/* --- limitation des tentatives (par adresse IP) --- */
function throttle_check(string $F, string $ip, int $max, int $win): void {
    $e = read_json($F)[$ip] ?? null;
    $age = $e ? time() - (int)$e['t'] : PHP_INT_MAX;
    if ($e && $age < $win && (int)$e['n'] >= $max)
        out(429, ['error' => 'Trop de tentatives. Réessayez dans ' . (int)ceil(($win - $age) / 60) . ' min.']);
}
function throttle_fail(string $F, string $ip, int $win): void {
    $all = array_filter(read_json($F), function ($e) use ($win) { return time() - (int)($e['t'] ?? 0) < $win; });
    $all[$ip] = ['n' => (int)($all[$ip]['n'] ?? 0) + 1, 't' => time()];
    write_json($F, $all);
}
function throttle_clear(string $F, string $ip): void {
    $all = read_json($F);
    if (isset($all[$ip])) { unset($all[$ip]); write_json($F, $all); }
}

/* ============================== accès réseau et dossiers ============================== */
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$allowed = empty($ALLOWED_NETS);
foreach ($ALLOWED_NETS as $net) { if (ip_in($ip, $net)) { $allowed = true; break; } }
if (!$allowed) out(403, ['error' => "Accès refusé pour l'adresse $ip"]);

foreach (['', '/store', '/files', '/tmp', '/signals', '/journal', '/backups', '/sessions'] as $sub) {
    $d = $DATA_DIR . $sub;
    if (!is_dir($d) && !@mkdir($d, 0770, true) && !is_dir($d)) out(500, ['error' => "Impossible de créer le dossier $d (droits d'écriture du serveur web ?)"]);
}
if (!is_writable($DATA_DIR)) out(500, ['error' => 'Dossier de données non accessible en écriture']);
if (!file_exists("$DATA_DIR/.htaccess")) @file_put_contents("$DATA_DIR/.htaccess", "Require all denied\nDeny from all\n");
if (!file_exists("$DATA_DIR/web.config")) @file_put_contents("$DATA_DIR/web.config",
    '<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer><security><requestFiltering>'
  . '<fileExtensions allowUnlisted="false" /></requestFiltering></security></system.webServer></configuration>');

$STORE = "$DATA_DIR/store";
$FILES = "$DATA_DIR/files";
$STATE = "$DATA_DIR/state.json";
$ACC   = "$DATA_DIR/accounts.json";
$FAILS = "$DATA_DIR/auth-fails.json";
$PRES  = "$DATA_DIR/presence.json";
$LOCKF = "$DATA_DIR/espace.lock";

$COLS = ['users', 'settings', 'channels', 'messages', 'reads', 'inbox', 'projects', 'tasks', 'notes', 'events', 'reminders',
         'absences', 'lates', 'hours', 'problems', 'kb', 'announcements', 'deadlines', 'contacts', 'comments'];
/* types tracés dans le journal d'activité (les messages et lectures n'y figurent pas) */
$JOURNALED = ['users', 'settings', 'channels', 'projects', 'tasks', 'events', 'absences', 'lates', 'hours', 'problems', 'kb', 'announcements', 'deadlines', 'contacts'];

function col_file(string $c, string $month = ''): string {
    global $STORE;
    return $c === 'messages' ? "$STORE/messages-$month.json" : "$STORE/$c.json";
}
/** mois d'un message, lu dans son identifiant : m_202610_xxxx → 2026-10 */
function msg_month(string $id): ?string {
    return preg_match('/^m_(\d{4})(\d{2})_[A-Za-z0-9]{4,40}$/', $id, $m) && (int)$m[2] >= 1 && (int)$m[2] <= 12 ? "$m[1]-$m[2]" : null;
}
function load_items(string $f): array { $d = read_json($f); return $d; }
function read_state(): array {
    global $STATE;
    $s = read_json($STATE);
    return $s + ['seq' => 0, 'cols' => [], 'gen' => ''];
}

/* ============================== initialisation ============================== */
$lock = fopen($LOCKF, 'c');
if ($lock === false) out(500, ['error' => 'Verrou indisponible']);

function bootstrap(): void {
    global $STATE, $ACC, $TEAM, $DATA_DIR, $lock;
    if (is_file($STATE)) return;
    flock($lock, LOCK_EX);
    clearstatcache();
    if (is_file($STATE)) { flock($lock, LOCK_UN); return; }
    $now = now_iso();
    $seq = 0;
    $users = [];
    $acc = read_json($ACC);
    foreach ($TEAM as $t) {
        $seq++;
        $users[$t['id']] = ['id' => $t['id'], 'firstName' => $t['firstName'], 'lastName' => $t['lastName'], 'login' => $t['login'],
            'role' => $t['role'], 'validator' => $t['validator'], 'color' => $t['color'], 'active' => true, 'jobTitle' => 'Service informatique',
            'cpBalance' => 25, 'rttBalance' => 0, 'hoursPerDay' => 7, '_seq' => $seq, '_at' => $now, 'createdAt' => $now];
        if (!isset($acc[$t['login']])) {
            $code = new_code();
            $acc[$t['login']] = ['userId' => $t['id'], 'hash' => null, 'code' => $code, 'stamp' => bin2hex(random_bytes(8)), 'created' => date('c')];
        }
    }
    $seq++;
    $settings = ['main' => ['id' => 'main', 'dayStart' => '09:00', 'dayEnd' => '17:30', 'workDays' => [1, 2, 3, 4, 5], 'minStaff' => 2,
        'recurrentCount' => 3, 'recurrentDays' => 30,
        'problemCategories' => ['Réseau', 'Poste de travail', 'Imprimante', 'Messagerie', 'Téléphonie', 'Logiciel métier', 'Serveur', 'Comptes & droits', 'Sécurité', 'Matériel', 'Caisse / TPE', 'Autre'],
        'sites' => ['Siège', 'Entrepôt', 'Atelier', 'Boutique'], '_seq' => $seq, '_at' => $now]];
    $channels = [];
    foreach ([['general', 'Général', 'Échanges de l’équipe'], ['urgences', 'Urgences', 'Incidents bloquants, alertes et pannes en cours'],
              ['projets', 'Projets', 'Avancement des projets'], ['veille', 'Veille & astuces', 'Liens, nouveautés, bonnes pratiques']] as $c) {
        $seq++;
        $channels['ch_' . $c[0]] = ['id' => 'ch_' . $c[0], 'name' => $c[1], 'topic' => $c[2], 'kind' => 'public', 'createdAt' => $now, '_seq' => $seq, '_at' => $now];
    }
    write_json(col_file('users'), $users);
    write_json(col_file('settings'), $settings);
    write_json(col_file('channels'), $channels);
    write_json($ACC, $acc, true);
    write_codes_file();
    write_json($STATE, ['seq' => $seq, 'cols' => ['users' => $seq, 'settings' => $seq, 'channels' => $seq], 'gen' => bin2hex(random_bytes(6)), 'created' => date('c')]);
    flock($lock, LOCK_UN);
}
/** data/PREMIERE-CONNEXION.txt : codes des comptes encore à activer (lisible sur le serveur uniquement) */
function write_codes_file(): void {
    global $ACC, $DATA_DIR;
    $acc = read_json($ACC);
    $users = read_json(col_file('users'));
    $lines = ["D8 · ESPACE SI — CODES DE PREMIÈRE CONNEXION", str_repeat('=', 46), '',
        "Chaque code ne sert qu'une fois. La personne saisit son identifiant, son code,",
        "puis choisit son mot de passe. Transmettez les codes de vive voix, pas par e-mail.",
        "Fichier mis à jour le " . date('d/m/Y à H:i') . '.', ''];
    $n = 0;
    foreach ($acc as $login => $a) {
        if (empty($a['code'])) continue;
        $u = $users[$a['userId']] ?? [];
        $name = trim(($u['firstName'] ?? '') . ' ' . ($u['lastName'] ?? ''));
        $label = $name ?: (string)$login;
        $lines[] = $label . str_repeat(' ', max(1, 24 - preg_match_all('/./u', $label))) . 'identifiant : ' . str_pad((string)$login, 18) . '  code : ' . $a['code'];
        $n++;
    }
    if (!$n) $lines[] = 'Tous les comptes sont activés.';
    @file_put_contents("$DATA_DIR/PREMIERE-CONNEXION.txt", implode("\r\n", $lines) . "\r\n");
}
bootstrap();

/* ============================== session ============================== */
$SESS_DIR = "$DATA_DIR/sessions";
if (is_dir($SESS_DIR) && is_writable($SESS_DIR)) session_save_path($SESS_DIR);
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.gc_maxlifetime', (string)($SESSION_IDLE + 600));
ini_set('session.gc_probability', '1');
ini_set('session.gc_divisor', '200');
$https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
      || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
$COOKIE_PATH = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/') . '/';
session_name('D8ESPACESI');
session_set_cookie_params(['lifetime' => 0, 'path' => $COOKIE_PATH, 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
if (!@session_start()) out(500, ['error' => 'Sessions PHP indisponibles (dossier data/sessions non accessible en écriture ?)']);

function auth_user(): ?array {
    global $ACC, $SESSION_IDLE, $lock;
    $s = $_SESSION['auth'] ?? null;
    if (!is_array($s)) return null;
    flock($lock, LOCK_SH);
    $a = read_json($ACC)[$s['login']] ?? null;
    $u = read_json(col_file('users'))[$s['userId']] ?? null;
    flock($lock, LOCK_UN);
    if (time() - (int)($s['seen'] ?? 0) > $SESSION_IDLE || !is_array($a) || !is_array($u) || ($u['active'] ?? true) === false
        || (string)$a['userId'] !== (string)$s['userId'] || (string)($a['stamp'] ?? '') !== (string)$s['stamp']) {
        unset($_SESSION['auth']);
        return null;
    }
    $_SESSION['auth']['seen'] = time();
    return $_SESSION['auth'] + ['user' => $u];
}
function open_session(string $login, array $a): array {
    session_regenerate_id(true);
    $_SESSION['auth'] = ['login' => $login, 'userId' => (string)$a['userId'], 'stamp' => (string)$a['stamp'], 'seen' => time()];
    return $_SESSION['auth'];
}
function max_upload(): int { global $MAX_FILE; return $MAX_FILE; }
/** '8M' → octets */
function ini_bytes(string $v): int {
    $v = trim($v); $n = (int)$v; $u = strtolower(substr($v, -1));
    return $u === 'g' ? $n * 1073741824 : ($u === 'm' ? $n * 1048576 : ($u === 'k' ? $n * 1024 : $n));
}
/** taille des morceaux d'envoi : 4 Mo, ou moins si post_max_size est plus petit */
function chunk_size(): int {
    $p = ini_bytes((string)ini_get('post_max_size'));
    return $p > 0 ? max(262144, min(4194304, $p - 131072)) : 4194304;
}
function me_payload(array $s): array {
    global $ICE_SERVERS, $https;
    $u = read_json(col_file('users'))[$s['userId']] ?? [];
    return ['auth' => true, 'userId' => $s['userId'], 'login' => $s['login'],
            'name' => trim(($u['firstName'] ?? '') . ' ' . ($u['lastName'] ?? '')),
            'build' => page_build(), 'ice' => $ICE_SERVERS, 'maxFile' => max_upload(), 'chunk' => chunk_size(), 'https' => $https, 'php' => PHP_VERSION];
}

$action = (string)($_GET['a'] ?? 'ping');
$auth = auth_user();
$PUBLIC = ['me', 'probe', 'activate', 'login', 'logout', 'ping'];
if (!$auth && !in_array($action, $PUBLIC, true)) out(401, ['auth' => false, 'error' => 'Connexion requise']);
if (!in_array($action, ['activate', 'login', 'logout', 'password'], true)) session_write_close();
$ME = $auth ? (string)$auth['userId'] : '';
$MEU = $auth ? $auth['user'] : [];
$IS_ADMIN = ($MEU['role'] ?? '') === 'admin';
$IS_VALIDATOR = $IS_ADMIN || !empty($MEU['validator']);

/* ============================== connexion ============================== */
if ($action === 'ping') out(200, ['ok' => true, 'app' => 'espace-si', 'build' => page_build()]);

if ($action === 'me') {
    if ($auth) out(200, me_payload($auth));
    out(401, ['auth' => false, 'minPassword' => $MIN_PASSWORD, 'https' => $https]);
}

/* identifiant saisi : mot de passe déjà défini, ou première connexion ? */
if ($action === 'probe') {
    $in = body_json();
    throttle_check($FAILS, $ip, $MAX_FAILS * 3, $FAIL_WINDOW);
    $login = clean_login($in['login'] ?? '');
    $a = read_json($ACC)[$login] ?? null;
    if (is_array($a) && empty($a['hash'])) {
        $u = read_json(col_file('users'))[$a['userId']] ?? [];
        if (($u['active'] ?? true) !== false)
            out(200, ['state' => 'setup', 'needCode' => $REQUIRE_CODE, 'minPassword' => $MIN_PASSWORD, 'name' => trim(($u['firstName'] ?? '') . ' ' . ($u['lastName'] ?? ''))]);
    }
    out(200, ['state' => 'login']);   // identifiant inconnu : même réponse qu'un compte existant
}

/* première connexion : code d'activation + choix du mot de passe */
if ($action === 'activate') {
    $in = body_json();
    flock($lock, LOCK_EX);
    throttle_check($FAILS, $ip, $MAX_FAILS, $FAIL_WINDOW);
    $login = clean_login($in['login'] ?? '');
    $acc = read_json($ACC);
    $a = $acc[$login] ?? null;
    if (!is_array($a) || !empty($a['hash'])) out(400, ['error' => 'Ce compte est déjà activé : connectez-vous avec votre mot de passe.']);
    $u = read_json(col_file('users'))[$a['userId']] ?? null;
    if (!$u || ($u['active'] ?? true) === false) out(403, ['error' => 'Compte désactivé. Contactez un administrateur.']);
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($in['code'] ?? '')));
    $want = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($a['code'] ?? '')));
    if ($REQUIRE_CODE && ($want === '' || !hash_equals($want, $code))) {
        throttle_fail($FAILS, $ip, $FAIL_WINDOW);
        out(403, ['error' => 'Code d’activation incorrect.']);
    }
    $pass = (string)($in['password'] ?? '');
    if ($e = password_strength_error($pass, $login, $MIN_PASSWORD)) out(400, ['error' => $e]);
    $acc[$login]['hash'] = password_hash($pass, PASSWORD_DEFAULT);
    $acc[$login]['code'] = null;
    $acc[$login]['stamp'] = bin2hex(random_bytes(8));
    $acc[$login]['activated'] = date('c');
    $acc[$login]['lastLogin'] = date('c');
    if (!write_json($ACC, $acc, true)) out(500, ['error' => 'Écriture impossible']);
    throttle_clear($FAILS, $ip);
    write_codes_file();
    flock($lock, LOCK_UN);
    out(200, me_payload(open_session($login, $acc[$login])));
}

if ($action === 'login') {
    $in = body_json();
    flock($lock, LOCK_EX);
    throttle_check($FAILS, $ip, $MAX_FAILS, $FAIL_WINDOW);
    $login = clean_login($in['login'] ?? '');
    $pass = (string)($in['password'] ?? '');
    $acc = read_json($ACC);
    $a = $acc[$login] ?? null;
    if (is_array($a) && empty($a['hash'])) out(409, ['setup' => true, 'error' => 'Première connexion : définissez d’abord votre mot de passe.']);
    // même coût de calcul que l'identifiant existe ou non (ne révèle pas les identifiants valides)
    $ok = password_verify($pass, is_array($a) ? (string)$a['hash'] : password_hash('x', PASSWORD_DEFAULT));
    if (!is_array($a) || !$ok) {
        throttle_fail($FAILS, $ip, $FAIL_WINDOW);
        out(401, ['auth' => false, 'error' => 'Identifiant ou mot de passe incorrect.']);
    }
    $u = read_json(col_file('users'))[$a['userId']] ?? null;
    if (!$u || ($u['active'] ?? true) === false) out(403, ['error' => 'Ce compte est désactivé. Contactez un administrateur.']);
    throttle_clear($FAILS, $ip);
    if (password_needs_rehash((string)$a['hash'], PASSWORD_DEFAULT)) $acc[$login]['hash'] = password_hash($pass, PASSWORD_DEFAULT);
    $acc[$login]['lastLogin'] = date('c');
    write_json($ACC, $acc, true);
    flock($lock, LOCK_UN);
    out(200, me_payload(open_session($login, $acc[$login])));
}

if ($action === 'logout') {
    body_json();
    $_SESSION = [];
    session_destroy();
    setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $COOKIE_PATH, 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
    out(200, ['ok' => true]);
}

if ($action === 'password') {
    $in = body_json();
    flock($lock, LOCK_EX);
    throttle_check($FAILS, $ip, $MAX_FAILS, $FAIL_WINDOW);
    $acc = read_json($ACC);
    $a = $acc[$auth['login']] ?? null;
    if (!is_array($a) || !password_verify((string)($in['current'] ?? ''), (string)$a['hash'])) {
        throttle_fail($FAILS, $ip, $FAIL_WINDOW);
        out(403, ['error' => 'Mot de passe actuel incorrect.']);
    }
    $next = (string)($in['next'] ?? '');
    if ($e = password_strength_error($next, $auth['login'], $MIN_PASSWORD)) out(400, ['error' => $e]);
    $acc[$auth['login']]['hash'] = password_hash($next, PASSWORD_DEFAULT);
    $acc[$auth['login']]['stamp'] = bin2hex(random_bytes(8));
    if (!write_json($ACC, $acc, true)) out(500, ['error' => 'Écriture impossible']);
    flock($lock, LOCK_UN);
    $_SESSION['auth']['stamp'] = $acc[$auth['login']]['stamp'];
    out(200, ['ok' => true]);
}

/* administrateurs : état des accès */
if ($action === 'access') {
    if (!$IS_ADMIN) out(403, ['error' => 'Réservé aux administrateurs']);
    $list = [];
    foreach (read_json($ACC) as $login => $a)
        $list[] = ['login' => (string)$login, 'userId' => (string)$a['userId'], 'activated' => !empty($a['hash']),
                   'created' => $a['created'] ?? null, 'lastLogin' => $a['lastLogin'] ?? null];
    out(200, ['accounts' => $list, 'needCode' => $REQUIRE_CODE]);
}
/* administrateurs : (ré)initialiser l'accès d'une personne → nouveau code d'activation */
if ($action === 'access-reset') {
    if (!$IS_ADMIN) out(403, ['error' => 'Réservé aux administrateurs']);
    $in = body_json();
    $uid = (string)($in['userId'] ?? '');
    flock($lock, LOCK_EX);
    $users = read_json(col_file('users'));
    if (!isset($users[$uid])) out(404, ['error' => 'Personne inconnue']);
    $acc = read_json($ACC);
    $login = null;
    foreach ($acc as $l => $a) if ((string)$a['userId'] === $uid) $login = (string)$l;
    if ($login === null) {
        $login = clean_login($in['login'] ?? ($users[$uid]['login'] ?? ''));
        if (!login_ok($login)) out(400, ['error' => 'Identifiant invalide : 3 à 60 caractères parmi lettres, chiffres, point, tiret, @.']);
        if (isset($acc[$login])) out(409, ['error' => 'Cet identifiant est déjà utilisé.']);
    } elseif ($uid === $ME) out(400, ['error' => 'Pour votre propre accès, utilisez « Changer mon mot de passe ».']);
    $code = new_code();
    $acc[$login] = ['userId' => $uid, 'hash' => null, 'code' => $code, 'stamp' => bin2hex(random_bytes(8)),
                    'created' => $acc[$login]['created'] ?? date('c'), 'resetBy' => $ME, 'resetAt' => date('c')];
    if (!write_json($ACC, $acc, true)) out(500, ['error' => 'Écriture impossible']);
    write_codes_file();
    flock($lock, LOCK_UN);
    out(200, ['ok' => true, 'login' => $login, 'code' => $code]);
}

/* ============================== droits ============================== */
function channels_map(): array { static $m = null; if ($m === null) $m = read_json(col_file('channels')); return $m; }
function can_see(string $c, array $it): bool {
    global $ME, $IS_VALIDATOR;
    switch ($c) {
        case 'reminders': return ($it['ownerId'] ?? '') === $ME;
        case 'inbox': return ($it['to'] ?? '') === $ME;
        case 'notes': case 'tasks': case 'events':
            $v = $it['visibility'] ?? 'equipe';
            if ($v === 'equipe') return true;
            if (($it['ownerId'] ?? '') === $ME) return true;
            return $v === 'partage' && in_array($ME, (array)($it['sharedWith'] ?? []), true);
        case 'hours': return ($it['userId'] ?? '') === $ME || $IS_VALIDATOR;
        case 'channels': return ($it['kind'] ?? 'public') === 'public' || in_array($ME, (array)($it['members'] ?? []), true);
        case 'messages':
            $ch = channels_map()[$it['ch'] ?? ''] ?? null;
            return is_array($ch) && can_see('channels', $ch);
    }
    return true;
}
/** version transmise au poste : l'élément, ou une simple marque de suppression s'il n'est pas visible */
function vis(string $c, array $it): array {
    if (!empty($it['_del']) || !can_see($c, $it)) return ['id' => $it['id'], '_del' => true, '_seq' => $it['_seq'] ?? 0];
    return $it;
}
function strip_ops(array &$in, array $keepSet, array $keepMap = []): void {
    $in['set'] = array_intersect_key((array)($in['set'] ?? []), array_flip($keepSet));
    $in['unset'] = array_values(array_intersect((array)($in['unset'] ?? []), $keepSet));
    $in['add'] = array_intersect_key((array)($in['add'] ?? []), array_flip($keepSet));
    $in['pull'] = array_intersect_key((array)($in['pull'] ?? []), array_flip($keepSet));
    $in['map'] = array_intersect_key((array)($in['map'] ?? []), array_flip($keepMap));
}
/** contrôle et ajuste une modification ; renvoie un message d'erreur ou null */
function policy(string $c, string $id, ?array $old, array &$in, bool $isDel): ?string {
    global $ME, $IS_ADMIN, $IS_VALIDATOR;
    $set = (array)($in['set'] ?? []);
    $isNew = $old === null || !empty($old['_del']);
    if ($old && !empty($old['_del']) && !$isDel && ($in['restore'] ?? false)) $isNew = false;
    if ($old && empty($old['_del']) && !can_see($c, $old)) return 'Élément introuvable.';
    switch ($c) {
        case 'users':
            if ($isDel) return 'Une personne ne se supprime pas : décochez « Compte actif ».';
            if ($IS_ADMIN) {
                if ($id === $ME && ((isset($set['role']) && $set['role'] !== 'admin') || (isset($set['active']) && $set['active'] === false)))
                    return 'Vous ne pouvez pas retirer vos propres droits d’administrateur.';
                if ($isNew && empty($set['login'])) return 'Identifiant manquant.';
                return null;
            }
            if ($id !== $ME) return 'Réservé aux administrateurs.';
            strip_ops($in, ['jobTitle', 'phone', 'mobile', 'email', 'color', 'avatar', 'signature'], ['prefs']);
            return null;
        case 'settings':
            return $IS_ADMIN ? null : 'Réservé aux administrateurs.';
        case 'reminders':
            if ($old && ($old['ownerId'] ?? '') !== $ME) return 'Ce rappel ne vous appartient pas.';
            if ($isNew) $in['set']['ownerId'] = $ME; else unset($in['set']['ownerId']);
            return null;
        case 'inbox':
            if ($isNew) {
                if (!id_ok($set['to'] ?? null)) return 'Destinataire manquant.';
                $in['set']['from'] = $ME;
                return null;
            }
            if (($old['to'] ?? '') !== $ME) return 'Réservé au destinataire.';
            strip_ops($in, ['read']);
            return null;
        case 'notes': case 'tasks': case 'events':
            if ($isNew) { $in['set']['ownerId'] = $ME; return null; }
            $owner = ($old['ownerId'] ?? '') === $ME;
            if (!$owner && ($old['visibility'] ?? 'equipe') !== 'equipe') {
                if ($isDel) return 'Seul le propriétaire peut supprimer cet élément.';
                unset($in['set']['visibility'], $in['set']['sharedWith'], $in['set']['ownerId']);
            }
            if (!$owner) unset($in['set']['ownerId']);
            return null;
        case 'channels':
            $kind = $old['kind'] ?? ($set['kind'] ?? 'public');
            if ($isNew) {
                if ($kind !== 'public' && !in_array($ME, (array)($set['members'] ?? []), true)) return 'Vous devez faire partie de la conversation.';
                $in['set']['createdBy'] = $ME;
                return null;
            }
            if ($isDel && $id === 'ch_general') return 'Le canal Général ne peut pas être supprimé.';
            if ($isDel && ($old['createdBy'] ?? '') !== $ME && !$IS_ADMIN) return 'Seul son créateur ou un administrateur peut supprimer ce canal.';
            unset($in['set']['kind'], $in['set']['createdBy']);
            return null;
        case 'messages':
            if ($isNew) {
                if ($isDel) return 'Message introuvable.';
                $ch = channels_map()[$set['ch'] ?? ''] ?? null;
                if (!is_array($ch) || !empty($ch['_del']) || !can_see('channels', $ch)) return 'Conversation introuvable.';
                if (strlen((string)($set['text'] ?? '')) > 40000) return 'Message trop long.';
                $in['set']['from'] = $ME;
                $in['set']['at'] = now_iso();
                return null;
            }
            if (($old['from'] ?? '') === $ME || ($isDel && $IS_ADMIN)) {
                unset($in['set']['from'], $in['set']['at'], $in['set']['ch']);
                if (!$isDel && array_key_exists('text', $in['set'] ?? [])) $in['set']['edited'] = now_iso();
                return null;
            }
            if ($isDel) return 'Vous ne pouvez supprimer que vos propres messages.';
            // autres personnes : seulement leurs propres réactions
            strip_ops($in, [], ['reactions']);
            if (isset($in['map']['reactions'])) {
                $in['map']['reactions'] = array_filter((array)$in['map']['reactions'], function ($k) use ($ME) {
                    return substr((string)$k, -strlen('|' . $ME)) === '|' . $ME;
                }, ARRAY_FILTER_USE_KEY);
            }
            return null;
        case 'comments':
            if ($isNew) { $in['set']['from'] = $ME; return null; }
            if (($old['from'] ?? '') !== $ME && !($isDel && $IS_ADMIN)) return 'Vous ne pouvez modifier que vos propres commentaires.';
            unset($in['set']['from']);
            return null;
        case 'reads':
            return $id === 'r_' . $ME ? null : 'Interdit.';
        case 'announcements':
            if ($isNew) { $in['set']['authorId'] = $ME; return null; }
            if (($old['authorId'] ?? '') === $ME || $IS_ADMIN) return null;
            if ($isDel) return 'Seul l’auteur ou un administrateur peut supprimer cette annonce.';
            strip_ops($in, [], ['ackBy']);
            if (isset($in['map']['ackBy'])) $in['map']['ackBy'] = array_intersect_key((array)$in['map']['ackBy'], [$ME => 1]);
            return null;
        case 'absences': case 'hours': case 'lates':
            $owner = (string)($old['userId'] ?? ($set['userId'] ?? $ME));
            if ($isNew && empty($set['userId'])) $in['set']['userId'] = $ME;
            if (!$IS_VALIDATOR && ($owner !== $ME || (isset($set['userId']) && $set['userId'] !== $ME)))
                return 'Vous ne pouvez saisir que pour vous-même (les responsables peuvent saisir pour l’équipe).';
            if ($c === 'lates') return null;
            $st = $set['status'] ?? null;
            if ($isDel) return (!$IS_VALIDATOR && ($old['status'] ?? '') === 'valide') ? 'Demande déjà validée : annulez-la plutôt.' : null;
            if (in_array($st, ['valide', 'refuse'], true)) {
                if (!$IS_VALIDATOR) return 'La validation est réservée aux responsables.';
                $in['set']['decidedBy'] = $ME;
                $in['set']['decidedAt'] = now_iso();
                return null;
            }
            if ($isNew && !$IS_VALIDATOR) $in['set']['status'] = 'demande';
            // une demande déjà tranchée et modifiée par son auteur repasse en attente (sauf annulation)
            if (!$isNew && !$IS_VALIDATOR && in_array($old['status'] ?? '', ['valide', 'refuse'], true) && $st !== 'annule') {
                $in['set']['status'] = 'demande';
                $in['unset'] = array_merge((array)($in['unset'] ?? []), ['decidedBy', 'decidedAt', 'decisionComment']);
            }
            return null;
    }
    return null;   // projets, problèmes, base de connaissances, échéances, contacts : toute l'équipe
}

/* ============================== écriture ============================== */
function apply_ops(array $it, array $in): array {
    foreach ((array)($in['set'] ?? []) as $k => $v) {
        if (!is_string($k) || $k === '' || $k[0] === '_' || $k === 'id') continue;
        $it[$k] = $v;
    }
    foreach ((array)($in['unset'] ?? []) as $k) if (is_string($k) && $k !== '' && $k[0] !== '_' && $k !== 'id') unset($it[$k]);
    foreach ((array)($in['add'] ?? []) as $k => $v) {
        if (!is_string($k) || $k === '' || $k[0] === '_') continue;
        $arr = is_list_array($it[$k] ?? null) ? $it[$k] : [];
        if (is_array($v) && isset($v['id'])) $arr = array_values(array_filter($arr, function ($e) use ($v) { return !(is_array($e) && ($e['id'] ?? null) === $v['id']); }));
        $arr[] = $v;
        $it[$k] = $arr;
    }
    foreach ((array)($in['pull'] ?? []) as $k => $v) {
        if (!is_string($k) || !is_array($it[$k] ?? null)) continue;
        $it[$k] = array_values(array_filter($it[$k], function ($e) use ($v) { return !($e === $v || (is_array($e) && ($e['id'] ?? null) === $v)); }));
    }
    foreach ((array)($in['map'] ?? []) as $k => $m) {
        if (!is_string($k) || $k === '' || $k[0] === '_' || !is_array($m)) continue;
        $cur = is_array($it[$k] ?? null) && !is_list_array($it[$k]) ? $it[$k] : [];
        foreach ($m as $mk => $mv) { if ($mv === null) unset($cur[$mk]); else $cur[(string)$mk] = $mv; }
        $it[$k] = $cur;
    }
    return $it;
}
function journal(string $c, array $item, string $act, string $label): void {
    global $DATA_DIR, $ME, $JOURNALED;
    if (!in_array($c, $JOURNALED, true)) return;
    if (in_array($c, ['tasks', 'events'], true) && ($item['visibility'] ?? 'equipe') !== 'equipe') return;
    $line = json_encode(['t' => now_iso(), 'by' => $ME, 'c' => $c, 'id' => $item['id'], 'act' => $act, 'label' => cut($label, 200)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    @file_put_contents("$DATA_DIR/journal/" . date('Y-m') . '.jsonl', $line . "\n", FILE_APPEND | LOCK_EX);
}
/** copie quotidienne de data/store, data/state.json et data/accounts.json */
function daily_backup(): void {
    global $DATA_DIR, $KEEP_BACKUPS, $STORE, $STATE, $ACC;
    $dir = "$DATA_DIR/backups/" . date('Y-m-d');
    if (is_dir($dir)) return;
    if (!@mkdir($dir, 0770, true)) return;
    foreach (glob("$STORE/*.json") ?: [] as $f) @copy($f, $dir . '/' . basename($f));
    @copy($STATE, "$dir/state.json");
    @copy($ACC, "$dir/accounts.json");
    $all = glob("$DATA_DIR/backups/*", GLOB_ONLYDIR) ?: [];
    sort($all);
    foreach (array_slice($all, 0, max(0, count($all) - $KEEP_BACKUPS)) as $old) {
        foreach (glob("$old/*") ?: [] as $f) @unlink($f);
        @rmdir($old);
    }
}
function purge_tombstones(array $items): array {
    global $TOMBSTONE_DAYS;
    $lim = time() - $TOMBSTONE_DAYS * 86400;
    foreach ($items as $k => $it) if (!empty($it['_del']) && strtotime((string)($it['_at'] ?? 'now')) < $lim) unset($items[$k]);
    return $items;
}

/** notifications : lues depuis plus de 30 jours, ou plus vieilles que 90 jours */
function purge_inbox(array $items): array {
    $t30 = time() - 30 * 86400; $t90 = time() - 90 * 86400;
    foreach ($items as $k => $it) {
        $at = strtotime((string)($it['_at'] ?? 'now'));
        if ($at < $t90 || (!empty($it['read']) && $at < $t30)) unset($items[$k]);
    }
    return $items;
}

if ($action === 'put' || $action === 'del') {
    $in = body_json();
    $c = (string)($in['c'] ?? '');
    $id = (string)($in['id'] ?? '');
    $isDel = $action === 'del';
    if (!in_array($c, $COLS, true)) out(400, ['error' => 'Type de données inconnu']);
    if (!id_ok($id)) out(400, ['error' => 'Identifiant invalide']);
    $month = '';
    if ($c === 'messages') { $month = msg_month($id); if (!$month) out(400, ['error' => 'Identifiant de message invalide']); }
    foreach (['set', 'unset', 'add', 'pull', 'map'] as $k) if (isset($in[$k]) && !is_array($in[$k])) out(400, ['error' => 'Format invalide']);
    flock($lock, LOCK_EX);
    $file = col_file($c, $month);
    $items = load_items($file);
    $old = $items[$id] ?? null;
    if ($isDel && (!$old || !empty($old['_del']))) { flock($lock, LOCK_UN); out(200, ['ok' => true]); }
    if ($err = policy($c, $id, $old, $in, $isDel)) { flock($lock, LOCK_UN); out(403, ['error' => $err]); }
    $now = now_iso();
    $restore = !$isDel && $old && !empty($old['_del']) && !empty($in['restore']);
    $isNew = !$old || (!empty($old['_del']) && !$restore);
    $base = $isNew ? ['id' => $id] : $old;
    $new = $isDel ? $old : apply_ops($base, $in);
    if ($isDel) $new['_del'] = true; else unset($new['_del']);
    if ($isNew && !$isDel) { $new['createdAt'] = $now; $new['createdBy'] = $ME; }
    $new['_at'] = $now;
    $new['_by'] = $ME;
    $enc = json_encode($new, JSON_UNESCAPED_UNICODE);
    if ($enc === false || strlen($enc) > 4 * 1024 * 1024) { flock($lock, LOCK_UN); out(413, ['error' => 'Élément trop volumineux']); }
    $state = read_state();
    $state['seq'] = (int)$state['seq'] + 1;
    $new['_seq'] = $state['seq'];
    $items[$id] = $new;
    if ($c !== 'messages' && $state['seq'] % 50 === 0) $items = purge_tombstones($items);
    if ($c === 'inbox' && $state['seq'] % 20 === 0) $items = purge_inbox($items);
    if (!write_json($file, $items)) { flock($lock, LOCK_UN); out(500, ['error' => 'Écriture impossible (droits sur data/store ?)']); }
    $state['cols'][$c === 'messages' ? "messages:$month" : $c] = $state['seq'];
    write_json($STATE, $state);
    daily_backup();
    flock($lock, LOCK_UN);
    journal($c, $new, $isDel ? 'delete' : ($isNew ? 'create' : ($restore ? 'restore' : 'update')), (string)($in['label'] ?? ''));
    out(200, ['item' => vis($c, $new), 'seq' => $state['seq']]);
}

/* ============================== lecture ============================== */
function month_list(): array {
    global $STORE;
    $m = [];
    foreach (glob("$STORE/messages-*.json") ?: [] as $f) if (preg_match('/messages-(\d{4}-\d{2})\.json$/', $f, $x)) $m[] = $x[1];
    rsort($m);
    return $m;
}
function presence_payload(): array {
    global $PRES;
    $p = read_json($PRES);
    $now = time();
    foreach ($p as $k => $v) $p[$k]['online'] = $now - (int)($v['seen'] ?? 0) < 40;
    return $p;
}
function touch_presence(array $patch = [], bool $force = false): void {
    global $PRES, $ME, $lock;
    $p = read_json($PRES);
    $cur = $p[$ME] ?? [];
    if (!$force && !$patch && time() - (int)($cur['seen'] ?? 0) < 15) return;
    flock($lock, LOCK_EX);
    $p = read_json($PRES);
    $p[$ME] = array_merge($p[$ME] ?? [], $patch, ['seen' => time()]);
    write_json($PRES, $p);
    flock($lock, LOCK_UN);
}
function drain_signals(): array {
    global $DATA_DIR, $ME, $lock;
    $f = "$DATA_DIR/signals/$ME.json";
    if (!is_file($f) || filesize($f) < 3) return [];
    flock($lock, LOCK_EX);
    $q = read_json($f);
    @file_put_contents($f, '[]');
    flock($lock, LOCK_UN);
    return array_values(array_filter($q, function ($s) { return time() - (int)($s['t'] ?? 0) < 60; }));
}

if ($action === 'boot') {
    flock($lock, LOCK_SH);
    $state = read_state();
    $data = [];
    foreach ($COLS as $c) {
        if ($c === 'messages') continue;
        $list = [];
        foreach (load_items(col_file($c)) as $it) if (is_array($it) && empty($it['_del']) && can_see($c, $it)) $list[] = $it;
        $data[$c] = $list;
    }
    $months = month_list();
    $msgs = [];
    foreach (array_slice($months, 0, 2) as $m)
        foreach (load_items(col_file('messages', $m)) as $it) if (is_array($it) && empty($it['_del']) && can_see('messages', $it)) $msgs[] = $it;
    flock($lock, LOCK_UN);
    touch_presence([], true);
    out(200, ['seq' => (int)$state['seq'], 'gen' => $state['gen'], 'data' => $data, 'messages' => $msgs,
              'loadedMonths' => array_slice($months, 0, 2), 'months' => $months, 'presence' => presence_payload(),
              'now' => now_iso(), 'build' => page_build()]);
}

if ($action === 'sync') {
    $since = (int)($_GET['since'] ?? 0);
    $gen = (string)($_GET['gen'] ?? '');
    flock($lock, LOCK_SH);
    $state = read_state();
    if ($gen !== $state['gen'] || $since > (int)$state['seq']) { flock($lock, LOCK_UN); out(200, ['reset' => true]); }
    $changes = [];
    if ($since < (int)$state['seq']) {
        foreach ($state['cols'] as $key => $s) {
            if ((int)$s <= $since) continue;
            $c = $key; $month = '';
            if (strpos($key, 'messages:') === 0) { $c = 'messages'; $month = substr($key, 9); }
            if (!in_array($c, $COLS, true)) continue;
            foreach (load_items(col_file($c, $month)) as $it)
                if (is_array($it) && (int)($it['_seq'] ?? 0) > $since) $changes[] = ['c' => $c, 'item' => vis($c, $it)];
        }
    }
    flock($lock, LOCK_UN);
    touch_presence();
    out(200, ['seq' => (int)$state['seq'], 'changes' => $changes, 'presence' => presence_payload(), 'signals' => drain_signals(),
              'build' => page_build(), 'now' => now_iso()]);
}

/* messages plus anciens d'une conversation */
if ($action === 'history') {
    $ch = (string)($_GET['ch'] ?? '');
    $before = (string)($_GET['before'] ?? '9999-99');
    $chan = channels_map()[$ch] ?? null;
    if (!is_array($chan) || !can_see('channels', $chan)) out(404, ['error' => 'Conversation introuvable']);
    $found = []; $month = null; $more = false;
    flock($lock, LOCK_SH);
    foreach (month_list() as $m) {
        if (strcmp($m, $before) >= 0) continue;
        if ($month !== null) { $more = true; break; }
        foreach (load_items(col_file('messages', $m)) as $it)
            if (is_array($it) && ($it['ch'] ?? '') === $ch && empty($it['_del'])) $found[] = $it;
        if ($found) $month = $m;
    }
    flock($lock, LOCK_UN);
    out(200, ['messages' => $found, 'month' => $month, 'more' => $more]);
}

/* recherche dans tous les messages */
if ($action === 'search') {
    $q = fold(trim((string)($_GET['q'] ?? '')));
    if (strlen($q) < 2) out(200, ['hits' => []]);
    $hits = [];
    flock($lock, LOCK_SH);
    foreach (month_list() as $m) {
        foreach (load_items(col_file('messages', $m)) as $it) {
            if (!is_array($it) || !empty($it['_del']) || !can_see('messages', $it)) continue;
            $txt = (string)($it['text'] ?? '');
            foreach ((array)($it['files'] ?? []) as $f) $txt .= ' ' . (string)($f['name'] ?? '');
            if (strpos(fold($txt), $q) !== false) $hits[] = $it;
        }
        if (count($hits) >= 150) break;
    }
    flock($lock, LOCK_UN);
    usort($hits, function ($a, $b) { return strcmp((string)$b['at'], (string)$a['at']); });
    out(200, ['hits' => array_slice($hits, 0, 150)]);
}

/* présence : statut choisi, « en train d'écrire » */
if ($action === 'presence') {
    $in = body_json(8192);
    $patch = [];
    if (isset($in['st'])) $patch['st'] = cut((string)$in['st'], 20);
    if (isset($in['tx'])) $patch['tx'] = cut((string)$in['tx'], 80);
    if (isset($in['typing'])) $patch['ty'] = ['ch' => cut((string)$in['typing'], 64), 't' => time()];
    touch_presence($patch, true);
    out(200, ['ok' => true]);
}

/* signalisation des appels vidéo (WebRTC) : file d'attente par destinataire */
if ($action === 'signal') {
    $in = body_json(262144);
    $to = array_slice(array_values(array_filter((array)($in['to'] ?? []), 'id_ok')), 0, 20);
    $data = $in['data'] ?? null;
    if (!$to || !is_array($data)) out(400, ['error' => 'Signal invalide']);
    $users = read_json(col_file('users'));
    flock($lock, LOCK_EX);
    foreach ($to as $uid) {
        if (!isset($users[$uid])) continue;
        $f = "$DATA_DIR/signals/$uid.json";
        $q = array_values(array_filter(read_json($f), function ($s) { return time() - (int)($s['t'] ?? 0) < 60; }));
        $q[] = ['from' => $ME, 't' => time(), 'data' => $data];
        if (count($q) > 300) $q = array_slice($q, -300);
        write_json($f, $q);
    }
    flock($lock, LOCK_UN);
    out(200, ['ok' => true]);
}

/* journal d'activité */
if ($action === 'journal') {
    $m = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['month'] ?? '')) ? (string)$_GET['month'] : date('Y-m');
    $f = "$DATA_DIR/journal/$m.jsonl";
    $rows = [];
    if (is_file($f)) foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) { $r = json_decode($l, true); if (is_array($r)) $rows[] = $r; }
    $months = [];
    foreach (glob("$DATA_DIR/journal/*.jsonl") ?: [] as $x) $months[] = basename($x, '.jsonl');
    rsort($months);
    out(200, ['month' => $m, 'rows' => array_reverse($rows), 'months' => $months]);
}

/* sauvegarde complète (administrateurs) : tout sauf les mots de passe et les fichiers joints */
if ($action === 'export') {
    if (!$IS_ADMIN) out(403, ['error' => 'Réservé aux administrateurs']);
    flock($lock, LOCK_SH);
    $dump = ['app' => 'espace-si', 'exportedAt' => now_iso(), 'state' => read_state(), 'cols' => [], 'messages' => []];
    foreach ($COLS as $c) if ($c !== 'messages') $dump['cols'][$c] = load_items(col_file($c));
    foreach (month_list() as $m) $dump['messages'][$m] = load_items(col_file('messages', $m));
    flock($lock, LOCK_UN);
    header('Content-Disposition: attachment; filename="espace-si-' . date('Y-m-d-His') . '.json"');
    echo json_encode($dump, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

/* ============================== pièces jointes ============================== */
$MIME = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp',
    'mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime', 'ogv' => 'video/ogg',
    'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac', 'ogg' => 'audio/ogg', 'oga' => 'audio/ogg', 'wav' => 'audio/wav', 'weba' => 'audio/webm',
    'pdf' => 'application/pdf'];

if ($action === 'upload') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') out(405, ['error' => 'POST attendu']);
    if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/octet-stream') === false) out(415, ['error' => 'Type de contenu inattendu']);
    $u = (string)($_GET['u'] ?? '');
    $i = (int)($_GET['i'] ?? -1);
    $n = (int)($_GET['n'] ?? 0);
    $off = (int)($_GET['off'] ?? -1);
    $size = (int)($_GET['size'] ?? -1);
    if (!preg_match('/^[a-z0-9]{8,40}$/', $u) || $i < 0 || $n < 1 || $i >= $n || $off < 0 || $size < 0) out(400, ['error' => 'Envoi invalide']);
    if ($size > $MAX_FILE) out(413, ['error' => 'Fichier trop volumineux (maximum ' . round($MAX_FILE / 1048576) . ' Mo).']);
    $tmp = "$DATA_DIR/tmp/$ME-$u.part";
    if ($i === 0) { @unlink($tmp); foreach (glob("$DATA_DIR/tmp/*.part") ?: [] as $old) if (filemtime($old) < time() - 86400) @unlink($old); }
    clearstatcache(true, $tmp);
    $have = is_file($tmp) ? filesize($tmp) : 0;
    if ($have !== $off) out(409, ['error' => 'Morceau inattendu', 'have' => $have]);
    $src = fopen('php://input', 'rb');
    $dst = fopen($tmp, 'ab');
    if (!$src || !$dst) out(500, ['error' => 'Écriture impossible (droits sur data/tmp ?)']);
    $copied = stream_copy_to_stream($src, $dst, $MAX_CHUNK + 1);
    fclose($src); fclose($dst);
    if ($copied === false || $copied > $MAX_CHUNK || $off + $copied > $size) { @unlink($tmp); out(413, ['error' => 'Morceau trop volumineux']); }
    if ($i < $n - 1) out(200, ['ok' => true, 'have' => $off + $copied]);
    clearstatcache(true, $tmp);
    if (filesize($tmp) !== $size) { @unlink($tmp); out(400, ['error' => 'Fichier incomplet, réessayez.']); }
    $name = cut(str_replace(['/', '\\', "\0", "\r", "\n"], '_', trim((string)($_GET['name'] ?? 'fichier'))), 180) ?: 'fichier';
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $fid = bin2hex(random_bytes(12));
    if (!@rename($tmp, "$FILES/$fid.bin")) { @unlink($tmp); out(500, ['error' => 'Écriture impossible (droits sur data/files ?)']); }
    $meta = ['id' => $fid, 'name' => $name, 'ext' => $ext, 'mime' => $MIME[$ext] ?? 'application/octet-stream', 'size' => $size,
             'by' => $ME, 'at' => now_iso()];
    foreach (['w', 'h', 'dur'] as $k) if (isset($_GET[$k]) && is_numeric($_GET[$k])) $meta[$k] = (float)$_GET[$k];
    write_json("$FILES/$fid.json", $meta);
    out(200, ['file' => $meta]);
}

if ($action === 'file') {
    $fid = preg_replace('/[^a-f0-9]/', '', (string)($_GET['id'] ?? ''));
    $meta = read_json("$FILES/$fid.json");
    $path = "$FILES/$fid.bin";
    if (!$fid || !$meta || !is_file($path)) out(404, ['error' => 'Fichier introuvable']);
    $size = filesize($path);
    $mime = $MIME[$meta['ext'] ?? ''] ?? 'application/octet-stream';
    $inline = $mime !== 'application/octet-stream' && empty($_GET['dl']);
    $name = (string)($meta['name'] ?? 'fichier');
    $ascii = preg_replace('/[^\x20-\x7e]/', '_', str_replace('"', '', $name));
    header('Content-Type: ' . ($inline ? $mime : 'application/octet-stream'));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
    header('Cache-Control: private, max-age=31536000, immutable');
    header('Accept-Ranges: bytes');
    if ($mime !== 'application/pdf') header("Content-Security-Policy: default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'; sandbox");
    $start = 0; $end = $size - 1;
    if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', (string)$_SERVER['HTTP_RANGE'], $r)) {
        if ($r[1] === '' && $r[2] !== '') { $start = max(0, $size - (int)$r[2]); }
        else { $start = (int)$r[1]; if ($r[2] !== '') $end = min($end, (int)$r[2]); }
        if ($start > $end || $start >= $size) { header("Content-Range: bytes */$size"); http_response_code(416); exit; }
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
    } else http_response_code(200);
    header('Content-Length: ' . ($end - $start + 1));
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'HEAD') exit;
    @set_time_limit(0);
    while (ob_get_level()) ob_end_clean();
    $fh = fopen($path, 'rb');
    fseek($fh, $start);
    $left = $end - $start + 1;
    while ($left > 0 && !feof($fh) && !connection_aborted()) {
        $chunk = fread($fh, (int)min(262144, $left));
        if ($chunk === false) break;
        echo $chunk;
        flush();
        $left -= strlen($chunk);
    }
    fclose($fh);
    exit;
}

out(400, ['error' => 'Action inconnue']);
