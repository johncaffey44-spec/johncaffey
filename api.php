<?php
/**
 * D8 Production · Planning — stockage partagé + connexion des utilisateurs
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
 * CONNEXION : chaque personne listée dans Paramétrage › Utilisateurs crée son
 * propre identifiant et son mot de passe à sa première connexion, puis les
 * saisit à chaque ouverture du navigateur. Les mots de passe sont stockés
 * hachés (password_hash) dans data/accounts.json, jamais dans le planning.
 * Un administrateur peut réinitialiser l'accès d'une personne (mot de passe
 * oublié, départ) : elle devra alors recréer son identifiant.
 *
 * SÉCURITÉ — à lire avant la mise en service :
 *   1. Servez l'application en HTTPS si possible : sans HTTPS, les mots de
 *      passe circulent en clair sur le réseau interne.
 *   2. $ALLOWED_NETS limite l'accès aux plages IP internes : ajustez-le.
 *   3. $SIGNUP_CODE : sans code, n'importe qui sur le réseau peut créer
 *      l'accès d'une personne qui n'en a pas encore. Définissez un code et
 *      communiquez-le de vive voix, ou faites créer les accès rapidement.
 *   4. Le dossier « data » contient toutes les données (planning, comptes,
 *      sessions) : placez-le hors de la racine web si votre hébergement le
 *      permet ($DATA_DIR ci-dessous), et sauvegardez-le avec vos autres
 *      données (Iperius, snapshot NAS…).
 *   5. Le serveur vérifie qui se connecte et refuse les changements qui
 *      dépassent les droits de la personne : rôles et utilisateurs (droit
 *      « users »), rôle super administrateur (réservé aux super
 *      administrateurs), mode maintenance, comptes en lecture seule, et
 *      opérations destructrices (mot de passe super administrateur retapé,
 *      même par un super administrateur). Le reste des droits est appliqué
 *      par l'interface.
 * -----------------------------------------------------------------------------
 */
declare(strict_types=1);

$DATA_DIR      = __DIR__ . '/data';   // idéalement hors racine web : '/var/planning-data' ou 'D:\\planning-data'
$ALLOWED_NETS  = ['127.0.0.0/8', '::1/128', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'];
$KEEP_BACKUPS  = 150;                 // nombre de copies conservées dans data/backups
$BACKUP_EVERY  = 900;                 // une copie au maximum toutes les N secondes
$MAX_BYTES     = 40 * 1024 * 1024;    // taille maximale acceptée pour un enregistrement

/* --- connexion --- */
$SIGNUP_CODE   = '';                  // code à saisir pour créer son accès ('' = aucun code demandé)
$SESSION_IDLE  = 12 * 3600;           // déconnexion après N secondes sans aucun échange avec le serveur
$MIN_PASSWORD  = 8;                   // longueur minimale des mots de passe
$MAX_FAILS     = 8;                   // échecs de connexion tolérés par adresse IP…
$FAIL_WINDOW   = 900;                 // …sur cette durée (s), puis blocage pendant la même durée

/* --- super administrateur : mot de passe exigé pour « Vider les projets », « Tout réinitialiser »
   et « Restaurer ». Seule son empreinte (bcrypt) figure ici. Il se change depuis l'application
   (Données & sauvegarde) : le nouveau est alors rangé dans data/superadmin.json, qui prime. */
$SUPERADMIN_HASH = '$2y$12$t95WZ/hH9x3iSbXx/h0O5u2KxUSyRUu8qsE0QFbDbbAI0eQe6EHZW';
$SUPERADMIN_TTL  = 300;               // validité (s) d'une confirmation super administrateur

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex');

/* Répond et termine. Les verrous (flock) sont libérés par PHP à la fin du script. */
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
function read_json(string $f): array {
    $d = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    return is_array($d) ? $d : [];
}
function write_json(string $f, array $d): bool {
    $tmp = $f . '.tmp';
    return @file_put_contents($tmp, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX) !== false
        && @rename($tmp, $f);
}
/** corps JSON d'une requête POST (les formulaires d'un autre site ne peuvent pas l'envoyer) */
function body_json(): array {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') out(405, ['error' => 'POST attendu']);
    if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') === false) out(415, ['error' => 'JSON attendu']);
    $j = json_decode((string)file_get_contents('php://input', false, null, 0, 65536), true);
    return is_array($j) ? $j : [];
}
/** planning complet décodé, ou null s'il n'existe pas encore */
function read_doc(string $FILE): ?array {
    if (!is_file($FILE)) return null;
    $d = json_decode((string)file_get_contents($FILE), true);
    return is_array($d) ? $d : null;
}
function find_user(?array $doc, string $id): ?array {
    foreach (($doc['users'] ?? []) as $u) if (is_array($u) && (string)($u['id'] ?? '') === $id) return $u;
    return null;
}
function user_label(array $u): string {
    if (isset($u['name'])) return (string)$u['name'];
    return trim((string)($u['firstName'] ?? '') . ' ' . (string)($u['lastName'] ?? ''));
}
/* --- droits : même logique que la page (effPerms) --- */
const ALL_PERMS = ['projects', 'delete', 'planning', 'absences', 'reports', 'export', 'import', 'admin', 'settings', 'users', 'security', 'data', 'super'];
const WRITE_PERMS = ['projects', 'delete', 'planning', 'absences', 'import', 'admin', 'settings', 'users', 'data'];
function role_perms(?array $r): array {
    if (!$r) return [];
    $p = array_values(array_filter((array)($r['perms'] ?? []), 'is_string'));
    if (in_array('super', $p, true)) return ALL_PERMS;
    if (empty($r['v2'])) {                       // rôle créé avant le détail des droits : droits d'origine élargis
        $p[] = 'reports'; $p[] = 'export';
        if (in_array('planning', $p, true)) $p[] = 'absences';
        if (in_array('projects', $p, true)) $p[] = 'delete';
        if (in_array('admin', $p, true)) array_push($p, 'delete', 'absences', 'import', 'settings', 'users', 'security', 'data');
    }
    return array_values(array_unique($p));
}
function find_role(?array $doc, $id): ?array {
    foreach (($doc['roles'] ?? []) as $r) if (is_array($r) && ($r['id'] ?? null) === $id) return $r;
    return null;
}
function eff_perms(?array $doc, string $userId): array {
    $u = find_user($doc, $userId);
    if (!$u || ($u['active'] ?? true) === false) return [];
    return role_perms(find_role($doc, $u['roleId'] ?? null));
}
function has_perm(?array $doc, string $userId, string $p): bool {
    $e = eff_perms($doc, $userId);
    return in_array('super', $e, true) || in_array($p, $e, true);
}
function has_any(?array $doc, string $userId, array $ps): bool { foreach ($ps as $p) if (has_perm($doc, $userId, $p)) return true; return false; }
function super_role_ids(?array $doc): array {
    $ids = [];
    foreach (($doc['roles'] ?? []) as $r) if (is_array($r) && in_array('super', (array)($r['perms'] ?? []), true)) $ids[] = $r['id'] ?? null;
    return $ids;
}
/** JSON canonique (clés triées) pour comparer deux versions sans tenir compte de l'ordre des clés */
function canon($v) {
    if (!is_array($v)) return $v;
    if ($v !== [] && array_keys($v) !== range(0, count($v) - 1)) ksort($v);
    foreach ($v as $k => $x) $v[$k] = canon($x);
    return $v;
}
function cj($v): string { return (string)json_encode(canon($v), JSON_UNESCAPED_UNICODE); }
function users_wo_login(?array $doc): array {
    return array_map(function ($u) { if (is_array($u)) unset($u['lastLogin']); return $u; }, (array)($doc['users'] ?? []));
}
const COLLS = ['agencies', 'services', 'staffGroups', 'staff', 'opGroups', 'projectTypes', 'daModels', 'statuses', 'absenceTypes', 'tags', 'roles', 'projects', 'ops', 'absences'];
/** contenu réel d'un planning : listes, journal et réglages déjà présents dans $ref
    (on ignore la date de dernière utilisation et ce que la page ajoute seule : réglages par défaut, métadonnées) */
function content_view(?array $d, ?array $ref): array {
    $v = [];
    foreach (COLLS as $k) $v[$k] = $d[$k] ?? [];
    $v['users'] = users_wo_login($d);
    $v['log'] = $d['log'] ?? [];
    $v['settings'] = array_intersect_key((array)($d['settings'] ?? []), (array)($ref['settings'] ?? []));
    return $v;
}
/** motif de refus d'un enregistrement, ou null s'il est permis */
function save_refusal(?array $old, ?array $new, string $uid, bool $saOK): ?string {
    if ($old === null || $new === null) return null;          // toute première mise en service
    if (has_perm($old, $uid, 'super')) return null;            // le super administrateur peut tout
    $same = cj(content_view($old, $old)) === cj(content_view($new, $old));
    if (!$same && !empty($old['settings']['maintenance']) && !has_perm($old, $uid, 'settings'))
        return 'Mode maintenance : les modifications sont réservées aux administrateurs.';
    if (!$same && !has_any($old, $uid, WRITE_PERMS))            // lecture seule : rien d'autre que sa date de dernière utilisation
        return 'Votre rôle ne permet pas de modifier les données.';
    $rolesChanged = cj($old['roles'] ?? []) !== cj($new['roles'] ?? []);
    if (($rolesChanged || cj(users_wo_login($old)) !== cj(users_wo_login($new))) && !has_perm($old, $uid, 'users'))
        return 'Seuls les gestionnaires des utilisateurs peuvent modifier les utilisateurs et les rôles.';
    if ($saOK) return null;                                     // mot de passe super administrateur saisi il y a moins de 5 min
    $oS = super_role_ids($old); $nS = super_role_ids($new);
    $pick = function ($doc, $ids) { return array_values(array_filter((array)($doc['roles'] ?? []), function ($r) use ($ids) { return is_array($r) && in_array($r['id'] ?? null, $ids, true); })); };
    if (array_diff($nS, $oS) || cj($pick($old, $oS)) !== cj($pick($new, $oS)))
        return 'Seul un super administrateur peut créer ou modifier le rôle super administrateur.';
    $ou = []; foreach ((array)($old['users'] ?? []) as $u) if (is_array($u)) $ou[(string)($u['id'] ?? '')] = $u;
    $nu = []; foreach ((array)($new['users'] ?? []) as $u) if (is_array($u)) $nu[(string)($u['id'] ?? '')] = $u;
    foreach (array_unique(array_merge(array_keys($ou), array_keys($nu))) as $id) {
        $wasS = isset($ou[$id]) && in_array($ou[$id]['roleId'] ?? null, $oS, true);
        $isS = isset($nu[$id]) && in_array($nu[$id]['roleId'] ?? null, $nS, true);
        if ($wasS !== $isS) return 'Seul un super administrateur peut nommer ou retirer un super administrateur.';
        if ($wasS) { $a = $ou[$id]; $b = $nu[$id]; unset($a['lastLogin'], $b['lastLogin']); if (cj($a) !== cj($b)) return 'La fiche d’un super administrateur ne peut être modifiée que par un super administrateur.'; }
    }
    return null;
}
/* --- journal des connexions (data/auth-log.json, 1 000 derniers événements) --- */
function auth_log(string $ev, string $login = '', string $info = ''): void {
    global $DATA_DIR;
    $h = @fopen("$DATA_DIR/auth-log.json", 'c+'); if (!$h) return;
    flock($h, LOCK_EX);
    $all = json_decode((string)stream_get_contents($h), true); if (!is_array($all)) $all = [];
    array_unshift($all, ['t' => date('c'), 'ev' => $ev, 'login' => $login, 'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''), 'info' => $info]);
    ftruncate($h, 0); rewind($h); fwrite($h, json_encode(array_slice($all, 0, 1000), JSON_UNESCAPED_UNICODE)); fflush($h);
    flock($h, LOCK_UN); fclose($h);
}
/** identifiant réduit à un jeu de caractères sûr, insensible à la casse */
/** tronque sans couper un caractère accentué (n'exige pas l'extension mbstring) */
function cut(string $s, int $n): string { return preg_match('/^.{0,' . $n . '}/us', $s, $m) ? $m[0] : ''; }
function clean_login($s): string { return strtolower(trim((string)$s)); }
function login_ok(string $l): bool { return (bool)preg_match('/^[a-z0-9._@-]{3,60}$/', $l); }
/** empreinte du fichier HTML servi : permet aux postes ouverts de voir qu'une nouvelle version est installée */
function page_build(): ?string {
    $p = basename((string)($_GET['page'] ?? ''));
    $f = __DIR__ . '/' . $p;
    if (!preg_match('/^[\w.-]+\.html?$/i', $p) || !is_file($f)) return null;
    clearstatcache(true, $f);
    return filemtime($f) . '-' . filesize($f);
}

/* --- limitation des tentatives de connexion (par adresse IP) --- */
function throttle_check(string $F, string $ip, int $max, int $win): void {
    $e = read_json($F)[$ip] ?? null;
    $age = $e ? time() - (int)$e['t'] : PHP_INT_MAX;
    if ($e && $age < $win && (int)$e['n'] >= $max) {
        if ((int)$e['n'] === $max) auth_log('blocked', '', 'adresse bloquée après ' . $max . ' échecs');
        out(429, ['error' => 'Trop de tentatives. Réessayez dans ' . (int)ceil(($win - $age) / 60) . ' min.']);
    }
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

$FILE  = "$DATA_DIR/planning.json";
$META  = "$DATA_DIR/planning.meta.json";
$LOCK  = "$DATA_DIR/planning.lock";
$ACC   = "$DATA_DIR/accounts.json";      // identifiants + mots de passe hachés
$FAILS = "$DATA_DIR/auth-fails.json";    // tentatives de connexion ratées
$SECF  = "$DATA_DIR/security.json";      // réglages de sécurité modifiés par un super administrateur (priment sur ceux ci-dessus)
$SEC = read_json($SECF);
if (isset($SEC['signupCode']) && is_string($SEC['signupCode'])) $SIGNUP_CODE = $SEC['signupCode'];
if (!empty($SEC['sessionHours'])) $SESSION_IDLE = max(1, min(72, (int)$SEC['sessionHours'])) * 3600;
if (!empty($SEC['minPassword'])) $MIN_PASSWORD = max(8, min(64, (int)$SEC['minPassword']));

/* --- session : cookie de navigateur (effacé à la fermeture), HttpOnly, SameSite=Strict --- */
$SESS_DIR = "$DATA_DIR/sessions";
if (!is_dir($SESS_DIR)) @mkdir($SESS_DIR, 0770, true);
if (is_dir($SESS_DIR) && is_writable($SESS_DIR)) session_save_path($SESS_DIR);
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.gc_maxlifetime', (string)($SESSION_IDLE + 600));
ini_set('session.gc_probability', '1');
ini_set('session.gc_divisor', '100');
$https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
      || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
$COOKIE_PATH = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/') . '/';
session_name('D8PLANNING');
session_set_cookie_params(['lifetime' => 0, 'path' => $COOKIE_PATH, 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
if (!@session_start()) out(500, ['error' => 'Sessions PHP indisponibles (dossier data/sessions non accessible en écriture ?)']);

/** utilisateur connecté, ou null (session absente, expirée, accès réinitialisé ou mot de passe changé ailleurs) */
function auth_user(string $ACC, int $idle, $lock): ?array {
    $s = $_SESSION['auth'] ?? null;
    if (!is_array($s)) return null;
    flock($lock, LOCK_SH);
    $a = read_json($ACC)[$s['login']] ?? null;
    flock($lock, LOCK_UN);
    if (time() - (int)($s['seen'] ?? 0) > $idle || !is_array($a)
        || (string)$a['userId'] !== (string)$s['userId'] || (string)($a['stamp'] ?? '') !== (string)$s['stamp']) {
        unset($_SESSION['auth']);
        return null;
    }
    $_SESSION['auth']['seen'] = time();
    return $_SESSION['auth'];
}
function open_session(string $login, array $a, string $name): array {
    session_regenerate_id(true);                // nouvel identifiant de session à chaque connexion
    $_SESSION['auth'] = ['login' => $login, 'userId' => (string)$a['userId'], 'name' => $name, 'stamp' => (string)$a['stamp'], 'seen' => time()];
    return $_SESSION['auth'];
}
function superadmin_hash(string $DATA_DIR, string $default): string {
    $h = read_json("$DATA_DIR/superadmin.json")['hash'] ?? '';
    return is_string($h) && $h !== '' ? $h : $default;
}
/** volume d'un planning et identité de la base (meta.gen change si la base est remplacée) */
function doc_shape(?array $d): array {
    $n = 0;
    foreach (['projects', 'ops', 'absences'] as $k) $n += is_array($d[$k] ?? null) ? count($d[$k]) : 0;
    return ['total' => $n, 'gen' => (string)($d['meta']['gen'] ?? '')];
}
function read_meta(string $META): array {
    $m = is_file($META) ? json_decode((string)file_get_contents($META), true) : null;
    return is_array($m) ? $m + ['version' => 0, 'savedAt' => null, 'by' => null] : ['version' => 0, 'savedAt' => null, 'by' => null];
}
function me_payload(array $s): array {
    return ['auth' => true, 'userId' => $s['userId'], 'login' => $s['login'], 'name' => $s['name'], 'build' => page_build()];
}

$action = (string)($_GET['a'] ?? 'ping');
$lock = fopen($LOCK, 'c');
if ($lock === false) out(500, ['error' => 'Verrou indisponible']);

$me = auth_user($ACC, $SESSION_IDLE, $lock);
$PUBLIC = ['me', 'signup-list', 'signup', 'login', 'logout'];
if (!$me && !in_array($action, $PUBLIC, true)) out(401, ['auth' => false, 'error' => 'Connexion requise']);
// la session n'est plus modifiée ensuite : on la libère pour ne pas bloquer les requêtes parallèles
if (!in_array($action, ['signup', 'login', 'logout', 'password', 'sa-check', 'sa-change', 'kick-all'], true)) session_write_close();

/* --- super administrateur : confirmation (valable $SUPERADMIN_TTL s pour cette session) --- */
if ($action === 'sa-check' || $action === 'sa-change') {
    $in = body_json();
    flock($lock, LOCK_EX);
    $doc = read_doc($FILE);
    if ($doc !== null && !has_any($doc, $me['userId'], ['admin', 'users', 'settings', 'security', 'data'])) out(403, ['error' => 'Réservé aux administrateurs']);
    throttle_check($FAILS, $ip, $MAX_FAILS, $FAIL_WINDOW);
    // le mot de passe actuel est toujours exigé, super administrateur compris : une session laissée ouverte ne suffit pas
    if (!password_verify((string)($in['password'] ?? ''), superadmin_hash($DATA_DIR, $SUPERADMIN_HASH))) {
        throttle_fail($FAILS, $ip, $FAIL_WINDOW);
        auth_log('sa-fail', $me['login']);
        out(403, ['error' => 'Mot de passe super administrateur incorrect.']);
    }
    throttle_clear($FAILS, $ip);
    auth_log($action === 'sa-change' ? 'sa-change' : 'sa-ok', $me['login']);
    if ($action === 'sa-change') {
        $next = (string)($in['next'] ?? '');
        if (strlen($next) < 12) out(400, ['error' => 'Le mot de passe super administrateur doit faire au moins 12 caractères.']);
        if (strlen($next) > 200) out(400, ['error' => 'Mot de passe trop long.']);
        if (!write_json("$DATA_DIR/superadmin.json", ['hash' => password_hash($next, PASSWORD_DEFAULT), 'changed' => date('c'), 'by' => $me['name']]))
            out(500, ['error' => 'Écriture impossible']);
    }
    flock($lock, LOCK_UN);
    $_SESSION['sa_until'] = time() + $SUPERADMIN_TTL;
    out(200, ['ok' => true, 'ttl' => $SUPERADMIN_TTL]);
}

/* --- qui suis-je ? --- */
if ($action === 'me') {
    if ($me) out(200, me_payload($me));
    out(401, ['auth' => false, 'needCode' => $SIGNUP_CODE !== '', 'minPassword' => $MIN_PASSWORD,
              'bootstrap' => !is_file($FILE) && !read_json($ACC)]);
}

/* --- personnes pouvant encore créer leur accès --- */
if ($action === 'signup-list') {
    flock($lock, LOCK_SH);
    $doc = read_doc($FILE);
    $acc = read_json($ACC);
    flock($lock, LOCK_UN);
    $taken = [];
    foreach ($acc as $a) $taken[(string)($a['userId'] ?? '')] = true;
    $users = [];
    foreach (($doc['users'] ?? []) as $u) {
        if (!is_array($u) || ($u['active'] ?? true) === false || isset($taken[(string)($u['id'] ?? '')])) continue;
        $users[] = ['id' => (string)$u['id'], 'name' => user_label($u)];
    }
    usort($users, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
    out(200, ['bootstrap' => !is_file($FILE) && !$acc, 'needCode' => $SIGNUP_CODE !== '', 'minPassword' => $MIN_PASSWORD, 'users' => $users]);
}

/* --- création de son accès (identifiant + mot de passe) --- */
if ($action === 'signup') {
    $in = body_json();
    flock($lock, LOCK_EX);
    throttle_check($FAILS, $ip, $MAX_FAILS, $FAIL_WINDOW);
    if ($SIGNUP_CODE !== '' && !hash_equals($SIGNUP_CODE, (string)($in['code'] ?? ''))) {
        throttle_fail($FAILS, $ip, $FAIL_WINDOW);
        auth_log('fail', clean_login($in['login'] ?? ''), 'code d’accès incorrect');
        out(403, ['error' => 'Code d’accès incorrect.']);
    }
    $login = clean_login($in['login'] ?? '');
    $pass = (string)($in['password'] ?? '');
    $uid = (string)($in['userId'] ?? '');
    if (!login_ok($login)) out(400, ['error' => 'Identifiant : 3 à 60 caractères parmi lettres, chiffres, point, tiret, @.']);
    if (strlen($pass) < $MIN_PASSWORD) out(400, ['error' => "Mot de passe trop court ($MIN_PASSWORD caractères minimum)."]);
    if (strlen($pass) > 200) out(400, ['error' => 'Mot de passe trop long.']);
    if (strtolower($pass) === $login) out(400, ['error' => 'Le mot de passe doit être différent de l’identifiant.']);
    $acc = read_json($ACC);
    $bootstrap = !is_file($FILE) && !$acc;   // toute première mise en service : pas encore de planning sur le serveur
    if ($bootstrap) {
        $name = cut(trim((string)($in['name'] ?? '')), 80);
        if ($uid === '' || $name === '') out(400, ['error' => 'Choisissez votre nom dans la liste.']);
    } else {
        $u = find_user(read_doc($FILE), $uid);
        if (!$u || ($u['active'] ?? true) === false) out(400, ['error' => 'Personne inconnue ou compte désactivé.']);
        $name = user_label($u);
    }
    if (isset($acc[$login])) out(409, ['error' => 'Cet identifiant est déjà pris : choisissez-en un autre.']);
    foreach ($acc as $a) if ((string)($a['userId'] ?? '') === $uid)
        out(409, ['error' => 'Cette personne a déjà un accès. Connectez-vous, ou demandez à un administrateur de le réinitialiser.']);
    $acc[$login] = ['userId' => $uid, 'name' => $name, 'hash' => password_hash($pass, PASSWORD_DEFAULT),
                    'stamp' => bin2hex(random_bytes(8)), 'created' => date('c'), 'lastLogin' => date('c')];
    if (!write_json($ACC, $acc)) out(500, ['error' => 'Écriture impossible']);
    flock($lock, LOCK_UN);
    auth_log('signup', $login, $name);
    out(200, me_payload(open_session($login, $acc[$login], $name)));
}

/* --- connexion --- */
if ($action === 'login') {
    $in = body_json();
    flock($lock, LOCK_EX);
    throttle_check($FAILS, $ip, $MAX_FAILS, $FAIL_WINDOW);
    $login = clean_login($in['login'] ?? '');
    $pass = (string)($in['password'] ?? '');
    $acc = read_json($ACC);
    $a = $acc[$login] ?? null;
    // même coût de calcul que l'identifiant existe ou non (ne révèle pas les identifiants valides)
    $ok = password_verify($pass, is_array($a) ? (string)$a['hash'] : password_hash('x', PASSWORD_DEFAULT));
    if (!is_array($a) || !$ok) {
        throttle_fail($FAILS, $ip, $FAIL_WINDOW);
        auth_log('fail', $login, is_array($a) ? 'mot de passe incorrect' : 'identifiant inconnu');
        out(401, ['auth' => false, 'error' => 'Identifiant ou mot de passe incorrect.']);
    }
    $doc = read_doc($FILE);
    $name = (string)($a['name'] ?? $login);
    if ($doc !== null) {
        $u = find_user($doc, (string)$a['userId']);
        if (!$u || ($u['active'] ?? true) === false) { auth_log('disabled', $login); out(403, ['error' => 'Ce compte est désactivé. Contactez un administrateur.']); }
        $name = user_label($u);
    }
    throttle_clear($FAILS, $ip);
    if (password_needs_rehash((string)$a['hash'], PASSWORD_DEFAULT)) $acc[$login]['hash'] = password_hash($pass, PASSWORD_DEFAULT);
    $acc[$login]['lastLogin'] = date('c');
    $acc[$login]['name'] = $name;
    write_json($ACC, $acc);
    flock($lock, LOCK_UN);
    auth_log('login', $login, $name);
    out(200, me_payload(open_session($login, $acc[$login], $name)));
}

/* --- déconnexion --- */
if ($action === 'logout') {
    body_json();
    if ($me) auth_log('logout', $me['login']);
    $_SESSION = [];
    session_destroy();
    setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $COOKIE_PATH, 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
    out(200, ['ok' => true]);
}

/* --- changement de son mot de passe (ferme ses autres sessions) --- */
if ($action === 'password') {
    $in = body_json();
    flock($lock, LOCK_EX);
    throttle_check($FAILS, $ip, $MAX_FAILS, $FAIL_WINDOW);
    $acc = read_json($ACC);
    $a = $acc[$me['login']] ?? null;
    $next = (string)($in['next'] ?? '');
    if (!is_array($a) || !password_verify((string)($in['current'] ?? ''), (string)$a['hash'])) {
        throttle_fail($FAILS, $ip, $FAIL_WINDOW);
        out(403, ['error' => 'Mot de passe actuel incorrect.']);
    }
    if (strlen($next) < $MIN_PASSWORD) out(400, ['error' => "Mot de passe trop court ($MIN_PASSWORD caractères minimum)."]);
    if (strlen($next) > 200) out(400, ['error' => 'Mot de passe trop long.']);
    if (strtolower($next) === $me['login']) out(400, ['error' => 'Le mot de passe doit être différent de l’identifiant.']);
    $acc[$me['login']]['hash'] = password_hash($next, PASSWORD_DEFAULT);
    $acc[$me['login']]['stamp'] = bin2hex(random_bytes(8));
    if (!write_json($ACC, $acc)) out(500, ['error' => 'Écriture impossible']);
    flock($lock, LOCK_UN);
    $_SESSION['auth']['stamp'] = $acc[$me['login']]['stamp'];
    auth_log('password', $me['login']);
    out(200, ['ok' => true]);
}

/* --- administrateurs : liste des accès créés --- */
if ($action === 'accounts') {
    flock($lock, LOCK_SH);
    $doc = read_doc($FILE);
    $acc = read_json($ACC);
    flock($lock, LOCK_UN);
    if (!has_any($doc, $me['userId'], ['users', 'security'])) out(403, ['error' => 'Réservé aux gestionnaires des utilisateurs']);
    $list = [];
    foreach ($acc as $login => $a) $list[] = ['login' => (string)$login, 'userId' => (string)$a['userId'],
                                             'created' => $a['created'] ?? null, 'lastLogin' => $a['lastLogin'] ?? null];
    out(200, ['accounts' => $list]);
}

/* --- administrateurs : réinitialiser l'accès d'une personne --- */
if ($action === 'reset') {
    $in = body_json();
    $uid = (string)($in['userId'] ?? '');
    flock($lock, LOCK_EX);
    $doc = read_doc($FILE);
    if (!has_perm($doc, $me['userId'], 'users')) out(403, ['error' => 'Réservé aux gestionnaires des utilisateurs']);
    if ($uid === $me['userId']) out(400, ['error' => 'Pour votre propre accès, utilisez « Changer mon mot de passe ».']);
    if (has_perm($doc, $uid, 'super') && !has_perm($doc, $me['userId'], 'super')) out(403, ['error' => 'Seul un super administrateur peut agir sur l’accès d’un super administrateur.']);
    $acc = read_json($ACC);
    $n = count($acc);
    $acc = array_filter($acc, function ($a) use ($uid) { return (string)($a['userId'] ?? '') !== $uid; });
    if (count($acc) !== $n && !write_json($ACC, $acc)) out(500, ['error' => 'Écriture impossible']);
    flock($lock, LOCK_UN);
    auth_log('reset', $me['login'], user_label(find_user($doc, $uid) ?? ['name' => $uid]));
    out(200, ['ok' => true, 'removed' => $n - count($acc)]);
}

/* --- déconnecter une personne (gestion des utilisateurs) ou tout le monde (super administrateur) --- */
if ($action === 'kick' || $action === 'kick-all') {
    $in = body_json();
    $uid = (string)($in['userId'] ?? '');
    flock($lock, LOCK_EX);
    $doc = read_doc($FILE);
    if ($action === 'kick' && !has_perm($doc, $me['userId'], 'users')) out(403, ['error' => 'Réservé aux gestionnaires des utilisateurs']);
    if ($action === 'kick-all' && !has_perm($doc, $me['userId'], 'super')) out(403, ['error' => 'Réservé au super administrateur']);
    if ($action === 'kick' && has_perm($doc, $uid, 'super') && !has_perm($doc, $me['userId'], 'super')) out(403, ['error' => 'Seul un super administrateur peut déconnecter un super administrateur.']);
    if ($action === 'kick' && $uid === $me['userId']) out(400, ['error' => 'Utilisez « Se déconnecter ».']);
    $acc = read_json($ACC); $n = 0;
    foreach ($acc as $login => $a) {
        if ($login === $me['login'] || ($action === 'kick' && (string)($a['userId'] ?? '') !== $uid)) continue;
        $acc[$login]['stamp'] = bin2hex(random_bytes(8)); $n++;
    }
    if ($n && !write_json($ACC, $acc)) out(500, ['error' => 'Écriture impossible']);
    flock($lock, LOCK_UN);
    auth_log($action, $me['login'], $action === 'kick' ? user_label(find_user($doc, $uid) ?? ['name' => $uid]) : $n . ' accès');
    out(200, ['ok' => true, 'sessions' => $n]);
}

/* --- sécurité : journal des connexions, adresses bloquées, sauvegardes, réglages --- */
if (in_array($action, ['auth-log', 'fails', 'unblock', 'backups', 'backup-get', 'backup-now', 'sec-get', 'sec-set'], true)) {
    $doc = read_doc($FILE);
    if (!has_perm($doc, $me['userId'], 'security')) out(403, ['error' => 'Réservé aux responsables de la sécurité']);
    $bdir = "$DATA_DIR/backups";
    if ($action === 'auth-log') out(200, ['events' => array_slice(read_json("$DATA_DIR/auth-log.json"), 0, 500)]);
    if ($action === 'fails') {
        $list = [];
        foreach (read_json($FAILS) as $fip => $e) {
            $age = time() - (int)($e['t'] ?? 0);
            if ($age < $FAIL_WINDOW) $list[] = ['ip' => (string)$fip, 'n' => (int)($e['n'] ?? 0), 'last' => date('c', (int)($e['t'] ?? 0)),
                'blocked' => (int)($e['n'] ?? 0) >= $MAX_FAILS, 'until' => date('c', (int)($e['t'] ?? 0) + $FAIL_WINDOW)];
        }
        out(200, ['ips' => $list, 'max' => $MAX_FAILS, 'window' => $FAIL_WINDOW]);
    }
    if ($action === 'unblock') {
        $in = body_json();
        flock($lock, LOCK_EX); throttle_clear($FAILS, (string)($in['ip'] ?? '')); flock($lock, LOCK_UN);
        auth_log('unblock', $me['login'], (string)($in['ip'] ?? ''));
        out(200, ['ok' => true]);
    }
    if ($action === 'backups') {
        $files = [];
        foreach ((is_dir($bdir) ? glob("$bdir/planning-*.json") : []) ?: [] as $f)
            $files[] = ['name' => basename($f), 'size' => filesize($f), 'date' => date('c', filemtime($f)),
                        'version' => preg_match('/-v(\d+)/', $f, $m) ? (int)$m[1] : null];
        usort($files, function ($a, $b) { return strcmp($b['name'], $a['name']); });
        out(200, ['files' => $files, 'keep' => $KEEP_BACKUPS, 'every' => $BACKUP_EVERY]);
    }
    if ($action === 'backup-get') {
        $name = basename((string)($_GET['f'] ?? ''));
        if (!preg_match('/^planning-[\w-]+\.json$/', $name) || !is_file("$bdir/$name")) out(404, ['error' => 'Sauvegarde introuvable']);
        auth_log('backup-get', $me['login'], $name);
        http_response_code(200); readfile("$bdir/$name"); exit;
    }
    if ($action === 'backup-now') {
        body_json();
        flock($lock, LOCK_SH);
        if (!is_file($FILE)) out(400, ['error' => 'Aucun planning à sauvegarder']);
        if (!is_dir($bdir)) @mkdir($bdir, 0770, true);
        $name = sprintf('planning-%s-v%06d-manuelle.json', date('Ymd-His'), (int)read_meta($META)['version']);
        $ok = @copy($FILE, "$bdir/$name");
        flock($lock, LOCK_UN);
        if (!$ok) out(500, ['error' => 'Copie impossible']);
        auth_log('backup-now', $me['login'], $name);
        out(200, ['ok' => true, 'name' => $name]);
    }
    $isSuper = has_perm($doc, $me['userId'], 'super');
    if ($action === 'sec-get') out(200, ['signupCode' => $isSuper ? $SIGNUP_CODE : ($SIGNUP_CODE !== '' ? '••••••' : ''), 'sessionHours' => (int)round($SESSION_IDLE / 3600),
        'minPassword' => $MIN_PASSWORD, 'maxFails' => $MAX_FAILS, 'failWindow' => $FAIL_WINDOW, 'https' => $https, 'super' => $isSuper]);
    if ($action === 'sec-set') {
        if (!$isSuper) out(403, ['error' => 'Réservé au super administrateur']);
        $in = body_json();
        $new = ['signupCode' => cut(trim((string)($in['signupCode'] ?? '')), 64), 'sessionHours' => max(1, min(72, (int)($in['sessionHours'] ?? 12))),
                'minPassword' => max(8, min(64, (int)($in['minPassword'] ?? 8))), 'changed' => date('c'), 'by' => $me['name']];
        if (!write_json($SECF, $new)) out(500, ['error' => 'Écriture impossible']);
        auth_log('sec-set', $me['login'], 'session ' . $new['sessionHours'] . ' h · mot de passe ' . $new['minPassword'] . ' car. · code ' . ($new['signupCode'] !== '' ? 'oui' : 'non'));
        out(200, ['ok' => true]);
    }
}

/* --- état --- */
if ($action === 'ping') {
    flock($lock, LOCK_SH);
    $m = read_meta($META);
    flock($lock, LOCK_UN);
    out(200, ['ok' => true, 'version' => (int)$m['version'], 'savedAt' => $m['savedAt'], 'by' => $m['by'], 'php' => PHP_VERSION, 'build' => page_build()]);
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
    if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') === false) out(415, ['error' => 'JSON attendu']);
    $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($len > $MAX_BYTES) out(413, ['error' => 'Données trop volumineuses']);
    $body = (string)file_get_contents('php://input');
    if ($body === '' || strlen($body) > $MAX_BYTES) out(400, ['error' => 'Corps de requête vide ou trop grand']);
    if (!valid_json($body)) out(400, ['error' => 'JSON invalide']);
    $base = isset($_GET['base']) ? (int)$_GET['base'] : -1;
    $by = cut((string)$me['name'], 80);     // l'auteur vient de la session, pas du navigateur
    $newDoc = json_decode($body, true);
    $shape = doc_shape($newDoc);
    $sa = (int)($_SESSION['sa_until'] ?? 0) > time();

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
    // droits : rôles, utilisateurs, super administrateur, maintenance, lecture seule
    $oldDoc = read_doc($FILE);
    $why = save_refusal($oldDoc, is_array($newDoc) ? $newDoc : null, (string)$me['userId'], $sa);
    if ($why !== null) { flock($lock, LOCK_UN); out(403, ['denied' => true, 'error' => $why]); }
    // remplacement de la base ou suppression massive : super administrateur ou mot de passe super administrateur
    $was = $m['shape'] ?? null;
    // exigé de tous, super administrateur compris : le mot de passe doit avoir été retapé il y a moins de 5 min
    if (is_array($was) && !$sa && (($was['gen'] !== '' && $shape['gen'] !== '' && $was['gen'] !== $shape['gen'])
        || ((int)$was['total'] >= 10 && $shape['total'] < (int)$was['total'] / 2))) {
        flock($lock, LOCK_UN);
        out(403, ['superadmin' => true, 'error' => 'Opération réservée au super administrateur.']);
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
    $new = ['version' => $cur + 1, 'savedAt' => date('c'), 'by' => $by, 'bytes' => strlen($body), 'shape' => $shape];
    @file_put_contents($META, json_encode($new, JSON_UNESCAPED_UNICODE));
    flock($lock, LOCK_UN);
    out(200, ['version' => $new['version'], 'savedAt' => $new['savedAt']]);
}

out(400, ['error' => 'Action inconnue']);
