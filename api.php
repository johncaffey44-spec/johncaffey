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
 *   3. Un accès ne se crée qu'avec une invitation (code à usage unique,
 *      valable $INVITE_DAYS jours) générée par un administrateur : personne
 *      ne peut choisir un nom dans une liste et s'approprier sa fiche. La
 *      mise en service (aucun accès sur le serveur) exige le mot de passe
 *      super administrateur.
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
$TIMEZONE      = 'Europe/Paris';      // fuseau des heures de sauvegarde et du journal
/* sauvegarde complète automatique (planning + accès + réglages) : heures, jours (1 = lundi … 7 = dimanche), nombre conservé */
$AUTO_BACKUP_TIMES = ['12:00', '17:00'];
$AUTO_BACKUP_DAYS  = [1, 2, 3, 4, 5, 6, 7];
$AUTO_BACKUP_KEEP  = 60;

/* --- connexion --- */
$INVITE_DAYS   = 7;                   // validité (jours) d'une invitation à créer son accès
$SESSION_IDLE  = 12 * 3600;           // déconnexion après N secondes sans aucun échange avec le serveur
$MIN_PASSWORD  = 8;                   // longueur minimale des mots de passe
$PW_DIGIT      = true;                // au moins un chiffre
$PW_SPECIAL    = true;                // au moins un caractère spécial (ni lettre ni chiffre)
$PW_UPPER      = false;               // au moins une majuscule
$LOCK_ATTEMPTS = 3;                   // compte bloqué après N mots de passe erronés…
$LOCK_MINUTES  = 15;                  // …pendant N minutes (0 = jusqu'au déblocage par un administrateur)
$SUPER_LOCK_ATTEMPTS = 3;             // super administrateurs : bloqués après N erreurs (0 = jamais, alerte e-mail à la place)…
$SUPER_LOCK_MINUTES  = 0;             // …pendant N minutes (0 = jusqu'à la réinitialisation par e-mail ou le déblocage par un administrateur)
$SUPER_LOCK_MAIL     = true;          // e-mail au super administrateur bloqué, avec un lien pour choisir un nouveau mot de passe
$RESET_MINUTES = 30;                  // validité du lien « mot de passe oublié » envoyé aux super administrateurs
$MAX_FAILS     = 8;                   // échecs de connexion tolérés par adresse IP…
$FAIL_WINDOW   = 900;                 // …sur cette durée (s), puis blocage pendant la même durée

/* --- super administrateur : mot de passe exigé pour « Vider les projets », « Tout réinitialiser »
   et « Restaurer ». Seule son empreinte (bcrypt) figure ici. Il se change depuis l'application
   (Données & sauvegarde) : le nouveau est alors rangé dans data/superadmin.json, qui prime. */
$SUPERADMIN_HASH = '$2y$12$t95WZ/hH9x3iSbXx/h0O5u2KxUSyRUu8qsE0QFbDbbAI0eQe6EHZW';
$SUPERADMIN_TTL  = 300;               // validité (s) d'une confirmation super administrateur

if (!@date_default_timezone_set($TIMEZONE)) date_default_timezone_set('Europe/Paris');
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
$INV   = "$DATA_DIR/invites.json";       // invitations en attente (empreinte du code → personne, échéance)
$SECF  = "$DATA_DIR/security.json";      // réglages de sécurité modifiés par un super administrateur (priment sur ceux ci-dessus)
$SEC = read_json($SECF);
if (!empty($SEC['inviteDays'])) $INVITE_DAYS = max(1, min(30, (int)$SEC['inviteDays']));
foreach (['pwDigit' => 'PW_DIGIT', 'pwSpecial' => 'PW_SPECIAL', 'pwUpper' => 'PW_UPPER'] as $k => $v) if (isset($SEC[$k])) $$v = (bool)$SEC[$k];
if (isset($SEC['lockAttempts'])) $LOCK_ATTEMPTS = max(1, min(20, (int)$SEC['lockAttempts']));
if (isset($SEC['lockMinutes'])) $LOCK_MINUTES = max(0, min(1440, (int)$SEC['lockMinutes']));
if (isset($SEC['superLockAttempts'])) $SUPER_LOCK_ATTEMPTS = max(0, min(20, (int)$SEC['superLockAttempts']));
if (isset($SEC['superLockMinutes'])) $SUPER_LOCK_MINUTES = max(0, min(1440, (int)$SEC['superLockMinutes']));
if (isset($SEC['superLockMail'])) $SUPER_LOCK_MAIL = (bool)$SEC['superLockMail'];
if (isset($SEC['resetMinutes'])) $RESET_MINUTES = max(5, min(1440, (int)$SEC['resetMinutes']));
if (isset($SEC['backupTimes']) && is_array($SEC['backupTimes'])) $AUTO_BACKUP_TIMES = array_values(array_filter($SEC['backupTimes'], function ($t) { return is_string($t) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t); }));
if (isset($SEC['backupDays']) && is_array($SEC['backupDays'])) $AUTO_BACKUP_DAYS = array_values(array_filter(array_map('intval', $SEC['backupDays']), function ($d) { return $d >= 1 && $d <= 7; }));
if (!empty($SEC['backupKeep'])) $AUTO_BACKUP_KEEP = max(2, min(500, (int)$SEC['backupKeep']));
$LOCKF = "$DATA_DIR/login-locks.json";   // comptes bloqués après trop de mots de passe erronés
$RESETS = "$DATA_DIR/resets.json";       // liens « mot de passe oublié » (empreinte du lien → compte, échéance)
$RFAILS = "$DATA_DIR/reset-fails.json";  // demandes de lien et liens erronés, par adresse IP
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
/** code d'invitation : 4 groupes de 4 caractères sans ambiguïté (ni 0/O, ni 1/I/L) — 31^16 ≈ 7·10^23 possibilités */
function invite_code(): string {
    $al = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; $s = '';
    for ($i = 0; $i < 16; $i++) $s .= $al[random_int(0, strlen($al) - 1)];
    return implode('-', str_split($s, 4));
}
function invite_norm(string $c): string { return strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', $c)); }
/** invitation valide pour ce code, ou null ; les invitations expirées sont purgées au passage */
function invite_find(string $F, string $code): ?array {
    $all = read_json($F); $now = time(); $keep = [];
    foreach ($all as $k => $v) if (is_array($v) && (int)($v['exp'] ?? 0) > $now) $keep[$k] = $v;
    if (count($keep) !== count($all)) write_json($F, $keep);
    $c = invite_norm($code);
    if (strlen($c) !== 16) return null;
    $v = $keep[hash('sha256', $c)] ?? null;
    return $v ? ['userId' => (string)$v['userId'], 'name' => (string)$v['name'], 'expires' => date('c', (int)$v['exp'])] : null;
}
/* --- règles des mots de passe --- */
function pw_policy(): array {
    global $MIN_PASSWORD, $PW_DIGIT, $PW_SPECIAL, $PW_UPPER;
    return ['min' => $MIN_PASSWORD, 'digit' => $PW_DIGIT, 'special' => $PW_SPECIAL, 'upper' => $PW_UPPER];
}
/** motif de refus d'un mot de passe, ou null s'il respecte les règles */
function pw_problem(string $p, string $login): ?string {
    $P = pw_policy();
    if (mb_strlen($p, 'UTF-8') < $P['min']) return 'Mot de passe trop court (' . $P['min'] . ' caractères minimum).';
    if (strlen($p) > 200) return 'Mot de passe trop long.';
    if ($P['digit'] && !preg_match('/\d/u', $p)) return 'Le mot de passe doit contenir au moins un chiffre.';
    if ($P['special'] && !preg_match('/[^\p{L}\p{N}]/u', $p)) return 'Le mot de passe doit contenir au moins un caractère spécial (! ? @ # % & * - _ …).';
    if ($P['upper'] && !preg_match('/\p{Lu}/u', $p)) return 'Le mot de passe doit contenir au moins une majuscule.';
    if (mb_strtolower($p, 'UTF-8') === $login) return 'Le mot de passe doit être différent de l’identifiant.';
    return null;
}

/* --- blocage d'un compte après N mots de passe erronés (par identifiant, en plus du blocage par adresse IP) --- */
function lock_state(string $F, string $login, ?int $min = null): ?array {
    global $LOCK_MINUTES;
    $e = read_json($F)[$login] ?? null;
    if (!is_array($e)) return null;
    $win = max(900, ($min ?? $LOCK_MINUTES) * 60);            // les échecs anciens sont oubliés
    if ((int)($e['until'] ?? 0) <= time() && time() - (int)($e['t'] ?? 0) > $win) return null;
    return $e;
}
function lock_msg(array $e, bool $super = false): string {
    $u = (int)($e['until'] ?? 0); $n = 'Compte bloqué après ' . (int)$e['n'] . ' mots de passe erronés';
    if ($super && !mail_ready()) $super = false;         // pas d'envoi d'e-mails configuré : seul un administrateur peut débloquer
    if ($super) return $n . ($u >= PHP_INT_MAX - 1 ? '.' : ', jusqu’à ' . date('H:i', $u) . '.')
        . ' Pour le débloquer, cliquez sur « Mot de passe oublié ? » : un lien pour choisir un nouveau mot de passe est envoyé à votre adresse e-mail.';
    return $u >= PHP_INT_MAX - 1 ? $n . ' : un administrateur doit le débloquer.'
        : $n . ', jusqu’à ' . date('H:i', $u) . '. Un administrateur peut le débloquer avant.';
}
/** compte un échec ; renvoie l'état (bloqué ou non) */
function lock_fail(string $F, string $login, int $att, int $min): array {
    $all = read_json($F);
    $e = lock_state($F, $login, $min) ?? ['n' => 0, 'until' => 0];
    if ((int)$e['until'] > 0 && (int)$e['until'] <= time()) $e = ['n' => 0, 'until' => 0];   // blocage échu : on repart de zéro
    $e['n'] = (int)$e['n'] + 1; $e['t'] = time();
    if ($att > 0 && $e['n'] >= $att && (int)$e['until'] <= time()) {          // $att = 0 : jamais bloqué
        $e['until'] = $min > 0 ? time() + $min * 60 : PHP_INT_MAX;
        auth_log('locked', $login, $e['n'] . ' mots de passe erronés');
    }
    $all[$login] = $e;
    write_json($F, $all);
    return $e;
}
function lock_clear(string $F, string $login): void { $all = read_json($F); if (isset($all[$login])) { unset($all[$login]); write_json($F, $all); } }

/* --- sauvegarde complète : planning + accès + réglages, dans un seul fichier JSON --- */
function full_backup(string $why): ?string {
    global $DATA_DIR, $FILE, $META, $ACC, $SECF, $INV, $AUTO_BACKUP_KEEP;
    if (!is_file($FILE)) return null;
    $raw = (string)file_get_contents($FILE);
    if ($raw === '' || !valid_json($raw)) return null;
    $bdir = "$DATA_DIR/backups";
    if (!is_dir($bdir)) @mkdir($bdir, 0770, true);
    $ver = (int)read_meta($META)['version'];
    $sa = is_file("$DATA_DIR/superadmin.json") ? read_json("$DATA_DIR/superadmin.json") : null;
    $head = ['kind' => 'd8-complet', 'created' => date('c'), 'reason' => $why, 'version' => $ver,
             'accounts' => read_json($ACC), 'security' => read_json($SECF), 'invites' => read_json($INV), 'superadmin' => $sa];
    $json = rtrim((string)json_encode($head, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), '}') . ',"planning":' . $raw . '}';
    $name = sprintf('complet-%s-v%06d-%s.json', date('Ymd-His'), $ver, $why);
    if (@file_put_contents("$bdir/$name.tmp", $json) === false || !@rename("$bdir/$name.tmp", "$bdir/$name")) return null;
    $files = glob("$bdir/complet-*.json") ?: [];
    if (count($files) > $AUTO_BACKUP_KEEP) { sort($files); foreach (array_slice($files, 0, count($files) - $AUTO_BACKUP_KEEP) as $old) @unlink($old); }
    return $name;
}
/** dernier créneau programmé déjà passé (timestamp) et prochain créneau */
function backup_slots(): array {
    global $AUTO_BACKUP_TIMES, $AUTO_BACKUP_DAYS;
    $now = time(); $last = 0; $next = 0;
    for ($d = -8; $d <= 8; $d++) {
        $day = strtotime(date('Y-m-d', $now) . " $d day");
        if (!in_array((int)date('N', $day), $AUTO_BACKUP_DAYS, true)) continue;
        foreach ($AUTO_BACKUP_TIMES as $t) {
            $ts = strtotime(date('Y-m-d', $day) . ' ' . $t);
            if ($ts === false) continue;
            if ($ts <= $now) $last = max($last, $ts); elseif (!$next || $ts < $next) $next = $ts;
        }
    }
    return [$last, $next];
}
/** sauvegarde programmée due ? Déclenchée par les pages ouvertes (toutes les 15 s) ou par la tâche planifiée (?a=cron) */
function auto_backup_tick(): ?string {
    global $DATA_DIR, $AUTO_BACKUP_TIMES;
    if (!$AUTO_BACKUP_TIMES) return null;
    $state = "$DATA_DIR/backup-state.json";
    [$slot] = backup_slots();
    if (!$slot || (int)(read_json($state)['slot'] ?? 0) >= $slot) return null;
    $bl = @fopen("$DATA_DIR/backup.lock", 'c');
    if (!$bl || !flock($bl, LOCK_EX | LOCK_NB)) return null;         // une autre requête s'en occupe déjà
    $st = read_json($state);
    $name = null;
    if ((int)($st['slot'] ?? 0) < $slot) {
        $name = full_backup('auto');
        if ($name) {
            write_json($state, ['slot' => $slot, 'done' => date('c'), 'file' => $name]);
            auth_log('backup-auto', '', $name . ' (créneau de ' . date('H:i', $slot) . ')');
        }
    }
    flock($bl, LOCK_UN); fclose($bl);
    return $name;
}
/* --- envoi d'e-mails : serveur SMTP (Microsoft 365, Exchange, relais interne…) ou mail() de PHP --- */
function mail_cfg(): array {
    global $SEC;
    return ['host' => (string)($SEC['smtpHost'] ?? ''), 'port' => (int)($SEC['smtpPort'] ?? 0), 'secure' => (string)($SEC['smtpSecure'] ?? 'tls'),
            'user' => (string)($SEC['smtpUser'] ?? ''), 'pass' => (string)($SEC['smtpPass'] ?? ''), 'from' => (string)($SEC['mailFrom'] ?? ''),
            'fromName' => (string)($SEC['mailFromName'] ?? 'Planning D8'), 'appUrl' => (string)($SEC['appUrl'] ?? '')];
}
function mail_ready(): bool { $c = mail_cfg(); return filter_var($c['from'], FILTER_VALIDATE_EMAIL) !== false && $c['appUrl'] !== ''; }
/** envoie un e-mail texte ; renvoie null si tout va bien, sinon le motif de l'échec */
function send_mail(string $to, string $subject, string $body): ?string {
    $c = mail_cfg();
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return 'Adresse du destinataire invalide.';
    if (!filter_var($c['from'], FILTER_VALIDATE_EMAIL)) return 'Adresse d’expédition non réglée (Sécurité & accès › Envoi des e-mails).';
    $enc = function (string $s): string { return '=?UTF-8?B?' . base64_encode(str_replace(["\r", "\n"], ' ', $s)) . '?='; };
    $domain = substr(strrchr($c['from'], '@'), 1);
    $headers = ['Date: ' . date('r'), 'From: ' . $enc($c['fromName']) . ' <' . $c['from'] . '>', 'To: <' . $to . '>', 'Subject: ' . $enc($subject),
                'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>', 'MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: base64', 'Auto-Submitted: auto-generated'];
    $data = chunk_split(base64_encode($body));
    if ($c['host'] === '') {                       // pas de serveur SMTP réglé : mail() de PHP (SMTP / sendmail du php.ini)
        $h = array_values(array_filter($headers, function ($x) { return stripos($x, 'To:') !== 0 && stripos($x, 'Subject:') !== 0; }));
        return @mail($to, $enc($subject), $data, implode("\r\n", $h), '-f' . $c['from']) ? null : 'La fonction mail() de PHP a échoué : réglez un serveur SMTP.';
    }
    $port = $c['port'] ?: ($c['secure'] === 'ssl' ? 465 : ($c['secure'] === 'tls' ? 587 : 25));
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $c['host']]]);
    $fp = @stream_socket_client(($c['secure'] === 'ssl' ? 'ssl://' : 'tcp://') . $c['host'] . ':' . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) return "Connexion impossible au serveur {$c['host']}:$port ($errstr).";
    stream_set_timeout($fp, 20);
    $read = function () use ($fp): string { $r = ''; while (($l = fgets($fp, 1024)) !== false) { $r .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; } return $r; };
    $cmd = function (?string $line, array $ok) use ($fp, $read): string {
        if ($line !== null) fwrite($fp, $line . "\r\n");
        $r = $read();
        if (!in_array((int)substr($r, 0, 3), $ok, true)) throw new RuntimeException(trim($r) !== '' ? trim(preg_replace('/\s+/', ' ', $r)) : 'pas de réponse');
        return $r;
    };
    try {
        $cmd(null, [220]);
        $ehlo = 'EHLO ' . preg_replace('/[^A-Za-z0-9.-]/', '', (string)(gethostname() ?: 'planning'));
        $cmd($ehlo, [250]);
        if ($c['secure'] === 'tls') {
            $cmd('STARTTLS', [220]);
            $m = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) $m |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) $m |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            if (!@stream_socket_enable_crypto($fp, true, $m)) throw new RuntimeException('chiffrement STARTTLS refusé (certificat ?)');
            $cmd($ehlo, [250]);
        }
        if ($c['user'] !== '') { $cmd('AUTH LOGIN', [334]); $cmd(base64_encode($c['user']), [334]); $cmd(base64_encode($c['pass']), [235]); }
        $cmd('MAIL FROM:<' . $c['from'] . '>', [250]);
        $cmd('RCPT TO:<' . $to . '>', [250, 251]);
        $cmd('DATA', [354]);
        $cmd(implode("\r\n", $headers) . "\r\n\r\n" . $data . "\r\n.", [250]);
        try { $cmd('QUIT', [221]); } catch (Throwable $e) { }
        fclose($fp);
        return null;
    } catch (Throwable $e) {
        @fclose($fp);
        return 'Serveur de messagerie : ' . $e->getMessage();
    }
}
/** fiche et adresse d'un super administrateur à partir de son identifiant, ou null */
function super_target(?array $doc, array $acc, string $login): ?array {
    $a = $acc[$login] ?? null;
    if (!is_array($a) || !$doc) return null;
    $uid = (string)$a['userId'];
    $u = find_user($doc, $uid);
    if (!$u || ($u['active'] ?? true) === false || !has_perm($doc, $uid, 'super')) return null;
    return ['userId' => $uid, 'name' => user_label($u), 'email' => trim((string)($u['email'] ?? ''))];
}
/** crée un lien de réinitialisation (3 par heure et par compte au plus) ; renvoie le jeton, ou null si la limite est atteinte */
function reset_issue(string $login, array $tg): ?string {
    global $RESETS, $RESET_MINUTES, $ip;
    reset_find($RESETS, '');                          // purge
    $all = read_json($RESETS);
    $recent = count(array_filter($all, function ($v) use ($login) { return is_array($v) && ($v['login'] ?? '') === $login && (int)($v['t'] ?? 0) > time() - 3600; }));
    if ($recent >= 3) return null;
    $tok = bin2hex(random_bytes(32));
    $all[hash('sha256', $tok)] = ['login' => $login, 'userId' => $tg['userId'], 't' => time(), 'exp' => time() + $RESET_MINUTES * 60, 'ip' => $ip];
    write_json($RESETS, $all);
    return $tok;
}
function app_link(string $param, string $token): string { $u = mail_cfg()['appUrl']; return $u . (strpos($u, '?') === false ? '?' : '&') . $param . '=' . $token; }
/** demande de réinitialisation valide pour ce lien, ou null (les demandes expirées sont purgées) */
function reset_find(string $F, string $token): ?array {
    $all = read_json($F); $now = time(); $keep = [];
    foreach ($all as $k => $v) if (is_array($v) && (int)($v['exp'] ?? 0) > $now - 3600) $keep[$k] = $v;   // on garde 1 h l'historique des envois (limite par compte)
    if (count($keep) !== count($all)) write_json($F, $keep);
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $v = $keep[hash('sha256', $token)] ?? null;
    return $v && (int)$v['exp'] > $now && empty($v['used']) ? $v : null;
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
    return ['auth' => true, 'userId' => $s['userId'], 'login' => $s['login'], 'name' => $s['name'], 'build' => page_build(),
            'policy' => pw_policy(), 'mustChange' => !empty($s['weak'])];
}

$action = (string)($_GET['a'] ?? 'ping');
$lock = fopen($LOCK, 'c');
if ($lock === false) out(500, ['error' => 'Verrou indisponible']);

/* --- sauvegardes programmées : vérifiées à chaque échange courant ; tâche planifiée Windows/cron : ?a=cron&key=… --- */
if (in_array($action, ['ping', 'load', 'me', 'cron', 'view'], true)) auto_backup_tick();
if ($action === 'cron') {
    session_write_close();
    throttle_check($FAILS, $ip, $MAX_FAILS, $FAIL_WINDOW);
    $k = (string)($SEC['cronKey'] ?? '');
    if (strlen($k) < 32 || !hash_equals($k, (string)($_GET['key'] ?? ''))) { throttle_fail($FAILS, $ip, $FAIL_WINDOW); out(403, ['error' => 'Clé invalide']); }
    [$l, $n] = backup_slots();
    out(200, ['ok' => true, 'last' => read_json("$DATA_DIR/backup-state.json"), 'next' => $n ? date('c', $n) : null]);
}

/* --- vue écran d'atelier : accès par clé (sans compte), lecture seule, données réduites au strict nécessaire --- */
if ($action === 'view') {
    session_write_close();
    throttle_check($FAILS, $ip, $MAX_FAILS, $FAIL_WINDOW);
    $key = (string)($_GET['key'] ?? '');
    flock($lock, LOCK_SH);
    $m = read_meta($META);
    $doc = read_doc($FILE);
    flock($lock, LOCK_UN);
    $view = null;
    $keys = is_array($doc['settings']['viewKeys'] ?? null) ? $doc['settings']['viewKeys'] : [];
    if (strlen($key) >= 16 && strlen($key) <= 128) {
        foreach ($keys as $id => $k) { if (is_string($k) && strlen($k) >= 16 && hash_equals($k, $key)) { $view = (string)$id; break; } }
    }
    if ($view === null) {
        throttle_fail($FAILS, $ip, $FAIL_WINDOW);
        auth_log('view-refused', '', 'clé de vue écran invalide');
        out(403, ['error' => 'Clé d’accès invalide ou révoquée.']);
    }
    $only = function ($list, array $keep) {
        $k = array_flip($keep);
        return array_values(array_map(function ($x) use ($k) { return array_intersect_key((array)$x, $k); }, array_filter((array)$list, 'is_array')));
    };
    $doc['projects'] = $only($doc['projects'] ?? [], ['id', 'client', 'city', 'typeId', 'agencyId', 'daModelId', 'requested', 'tags', 'archived', 'archivedAt', 'part', 'erpRef', 'daCount']);
    $doc['staff']    = $only($doc['staff'] ?? [], ['id', 'firstName', 'lastName', 'kind', 'groupIds', 'active', 'color']);
    $doc['absences'] = $only($doc['absences'] ?? [], ['id', 'staffId', 'typeId', 'start', 'startSlot', 'end', 'endSlot']);
    $doc['users'] = []; $doc['roles'] = []; $doc['log'] = [];
    unset($doc['settings']['viewKeys']);
    out(200, ['view' => $view, 'version' => (int)$m['version'], 'data' => $doc]);
}

$me = auth_user($ACC, $SESSION_IDLE, $lock);
$PUBLIC = ['me', 'signup-list', 'invite-check', 'signup', 'login', 'logout', 'forgot', 'reset-check', 'reset-password'];
if (!$me && !in_array($action, $PUBLIC, true)) out(401, ['auth' => false, 'error' => 'Connexion requise']);
// la session n'est plus modifiée ensuite : on la libère pour ne pas bloquer les requêtes parallèles
if (!in_array($action, ['signup', 'login', 'logout', 'password', 'sa-check', 'sa-change', 'kick-all', 'reset-password'], true)) session_write_close();

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
    out(401, ['auth' => false, 'minPassword' => $MIN_PASSWORD, 'policy' => pw_policy(), 'bootstrap' => !read_json($ACC)]);
}

/* --- mise en service : les administrateurs du planning (seul cas où l'on choisit un nom, avec le mot de passe super administrateur) ---
   En temps normal, il n'y a plus de liste de noms : un accès ne se crée qu'avec l'invitation d'un administrateur. */
if ($action === 'signup-list') {
    flock($lock, LOCK_SH);
    $doc = read_doc($FILE);
    $acc = read_json($ACC);
    flock($lock, LOCK_UN);
    $boot = !$acc;
    $users = [];
    if ($boot && $doc) {
        foreach (($doc['users'] ?? []) as $u) {
            if (!is_array($u) || ($u['active'] ?? true) === false) continue;
            if (has_any($doc, (string)($u['id'] ?? ''), ['users', 'super'])) $users[] = ['id' => (string)$u['id'], 'name' => user_label($u)];
        }
        usort($users, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
    }
    out(200, ['bootstrap' => $boot, 'fresh' => $boot && !$doc, 'minPassword' => $MIN_PASSWORD, 'policy' => pw_policy(), 'users' => $users]);
}

/* --- invitation : vérification du code (page de création d'accès) --- */
if ($action === 'invite-check') {
    flock($lock, LOCK_EX);
    throttle_check($FAILS, $ip, $MAX_FAILS, $FAIL_WINDOW);
    $inv = invite_find($INV, (string)($_GET['code'] ?? ''));
    if (!$inv) {
        throttle_fail($FAILS, $ip, $FAIL_WINDOW);
        flock($lock, LOCK_UN);
        out(404, ['error' => 'Invitation inconnue, déjà utilisée ou expirée : demandez-en une nouvelle à un administrateur.']);
    }
    flock($lock, LOCK_UN);
    out(200, ['name' => $inv['name'], 'expires' => $inv['expires'], 'minPassword' => $MIN_PASSWORD, 'policy' => pw_policy()]);
}

/* --- création de son accès (identifiant + mot de passe) --- */
if ($action === 'signup') {
    $in = body_json();
    flock($lock, LOCK_EX);
    throttle_check($FAILS, $ip, $MAX_FAILS, $FAIL_WINDOW);
    $login = clean_login($in['login'] ?? '');
    $pass = (string)($in['password'] ?? '');
    $acc = read_json($ACC);
    $doc = read_doc($FILE);
    $boot = !$acc;
    $code = (string)($in['invite'] ?? '');
    if ($boot) {
        // mise en service, ou remise en route quand plus aucun accès n'existe : réservée à qui connaît le mot de passe super administrateur
        if (!password_verify((string)($in['sa'] ?? ''), superadmin_hash($DATA_DIR, $SUPERADMIN_HASH))) {
            throttle_fail($FAILS, $ip, $FAIL_WINDOW);
            auth_log('fail', $login, 'mise en service : mot de passe super administrateur incorrect');
            out(403, ['error' => 'Mot de passe super administrateur incorrect.']);
        }
        $uid = (string)($in['userId'] ?? '');
        if ($doc) {
            $u = find_user($doc, $uid);
            if (!$u || ($u['active'] ?? true) === false || !has_any($doc, $uid, ['users', 'super'])) out(400, ['error' => 'Choisissez un administrateur dans la liste.']);
            $name = user_label($u);
        } else {
            $name = cut(trim((string)($in['name'] ?? '')), 80);
            if ($uid === '' || $name === '') out(400, ['error' => 'Choisissez votre nom dans la liste.']);
        }
    } else {
        $inv = invite_find($INV, $code);
        if (!$inv) {
            throttle_fail($FAILS, $ip, $FAIL_WINDOW);
            auth_log('fail', $login, 'invitation inconnue, utilisée ou expirée');
            out(403, ['error' => 'Invitation inconnue, déjà utilisée ou expirée : demandez-en une nouvelle à un administrateur.']);
        }
        $uid = (string)$inv['userId'];
        $u = find_user($doc, $uid);
        if (!$u || ($u['active'] ?? true) === false) out(400, ['error' => 'Votre fiche a été supprimée ou désactivée entre-temps : contactez un administrateur.']);
        $name = user_label($u);
    }
    if (!login_ok($login)) out(400, ['error' => 'Identifiant : 3 à 60 caractères parmi lettres, chiffres, point, tiret, @.']);
    if ($why = pw_problem($pass, $login)) out(400, ['error' => $why]);
    if (isset($acc[$login])) out(409, ['error' => 'Cet identifiant est déjà pris : choisissez-en un autre.']);
    foreach ($acc as $a) if ((string)($a['userId'] ?? '') === $uid)
        out(409, ['error' => 'Cette personne a déjà un accès. Connectez-vous, ou demandez à un administrateur de le réinitialiser.']);
    $acc[$login] = ['userId' => $uid, 'name' => $name, 'hash' => password_hash($pass, PASSWORD_DEFAULT),
                    'stamp' => bin2hex(random_bytes(8)), 'created' => date('c'), 'lastLogin' => date('c')];
    if (!write_json($ACC, $acc)) out(500, ['error' => 'Écriture impossible']);
    if (!$boot) { $all = read_json($INV); unset($all[hash('sha256', invite_norm($code))]); write_json($INV, $all); }   // usage unique
    throttle_clear($FAILS, $ip);
    flock($lock, LOCK_UN);
    auth_log('signup', $login, $name . ($boot ? ' (mise en service)' : ' (invitation)'));
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
    $doc = read_doc($FILE);
    $sup = $login !== '' ? super_target($doc, $acc, $login) : null;   // super administrateur : règles de blocage propres
    [$att, $min] = $sup ? [$SUPER_LOCK_ATTEMPTS, $SUPER_LOCK_MINUTES] : [$LOCK_ATTEMPTS, $LOCK_MINUTES];
    // compte bloqué : refusé sans même vérifier le mot de passe (même traitement que l'identifiant existe ou non)
    $lk = $login !== '' ? lock_state($LOCKF, $login, $min) : null;
    if ($lk && (int)($lk['until'] ?? 0) > time()) {
        throttle_fail($FAILS, $ip, $FAIL_WINDOW);
        auth_log('fail', $login, 'compte bloqué');
        out(423, ['auth' => false, 'locked' => true, 'error' => lock_msg($lk, (bool)$sup)]);
    }
    // même coût de calcul que l'identifiant existe ou non (ne révèle pas les identifiants valides)
    $ok = password_verify($pass, is_array($a) ? (string)$a['hash'] : password_hash('x', PASSWORD_DEFAULT));
    if (!is_array($a) || !$ok) {
        throttle_fail($FAILS, $ip, $FAIL_WINDOW);
        auth_log('fail', $login, is_array($a) ? ($sup ? 'mot de passe incorrect (super administrateur)' : 'mot de passe incorrect') : 'identifiant inconnu');
        $e = $login !== '' ? lock_fail($LOCKF, $login, $att, $min) : ['n' => 0, 'until' => 0];
        $locked = (int)$e['until'] > time();
        if ($sup) {
            // e-mail au titulaire : au moment du blocage, avec un lien pour choisir un nouveau mot de passe ;
            // si le blocage est désactivé (0 essai), une alerte toutes les N erreurs
            $notify = $locked ? (int)$e['n'] === $att : ($att === 0 && (int)$e['n'] % max(1, $LOCK_ATTEMPTS) === 0);
            $tok = $notify && $locked && $SUPER_LOCK_MAIL && mail_ready() && filter_var($sup['email'], FILTER_VALIDATE_EMAIL) ? reset_issue($login, $sup) : null;
            flock($lock, LOCK_UN);
            if ($att === 0) usleep(800000);            // jamais bloqué : on ralentit les essais en série
            if ($notify && $SUPER_LOCK_MAIL && mail_ready() && filter_var($sup['email'], FILTER_VALIDATE_EMAIL)) {
                $when = date('d/m/Y à H:i');
                $body = "Bonjour {$sup['name']},\n\n" . (int)$e['n'] . " mots de passe erronés ont été saisis sur votre compte super administrateur (identifiant « $login »), le dernier depuis l'adresse $ip, le $when.\n\n";
                if ($locked) $body .= "Votre compte est maintenant bloqué" . ($min > 0 ? " jusqu'à " . date('H:i', (int)$e['until']) : '') . ".\n\n"
                    . ($tok ? "Pour le débloquer, choisissez un nouveau mot de passe avec ce lien (valable $RESET_MINUTES minutes, une seule fois) :\n" . app_link('reinit', $tok) . "\n\n"
                            : "Pour le débloquer, utilisez « Mot de passe oublié ? » sur l'écran de connexion : " . mail_cfg()['appUrl'] . "\n\n")
                    . "Si ce n'était pas vous, quelqu'un essaie votre mot de passe : changez-le et prévenez le service informatique.\n";
                else $body .= "Si ce n'était pas vous, changez votre mot de passe dès maintenant (menu en haut à droite › Changer mon mot de passe) ou utilisez « Mot de passe oublié ? » : " . mail_cfg()['appUrl'] . "\n";
                $err = send_mail($sup['email'], $locked ? 'Compte bloqué : mots de passe erronés – planning' : 'Alerte : mots de passe erronés sur votre compte – planning', $body);
                auth_log($err ? 'mail-fail' : 'super-alert', $login, $err ?: ($locked ? 'compte bloqué, lien de déblocage envoyé à ' : 'alerte envoyée à ') . $sup['email']);
            }
        }
        if ($locked) out(423, ['auth' => false, 'locked' => true, 'error' => lock_msg($e, (bool)$sup)]);
        $left = $att - (int)$e['n'];
        out(401, ['auth' => false, 'error' => 'Identifiant ou mot de passe incorrect.' . ($att > 0 && $left > 0 && $left < $att ? " Encore $left essai" . ($left > 1 ? 's' : '') . ' avant le blocage du compte.' : '')]);
    }
    lock_clear($LOCKF, $login);
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
    $s = open_session($login, $acc[$login], $name);
    if (pw_problem($pass, $login)) { $_SESSION['auth']['weak'] = true; $s = $_SESSION['auth']; }   // ne respecte plus les règles : à changer
    out(200, me_payload($s));
}

/* --- mot de passe oublié (super administrateurs) : lien de réinitialisation par e-mail --- */
if ($action === 'forgot') {
    $in = body_json();
    $t0 = microtime(true);
    throttle_check($RFAILS, $ip, 10, 900);
    throttle_fail($RFAILS, $ip, 900);                 // chaque demande compte : 10 par quart d'heure et par adresse IP
    $login = clean_login($in['login'] ?? '');
    flock($lock, LOCK_EX);
    $tg = $login !== '' ? super_target(read_doc($FILE), read_json($ACC), $login) : null;
    $send = null;
    if ($tg && filter_var($tg['email'], FILTER_VALIDATE_EMAIL) && mail_ready()) $send = reset_issue($login, $tg);   // 3 e-mails par heure et par compte au plus
    flock($lock, LOCK_UN);
    if ($send) {
        $err = send_mail($tg['email'], 'Réinitialisation de votre mot de passe – planning',
            "Bonjour {$tg['name']},\n\nUne réinitialisation du mot de passe de votre compte super administrateur (identifiant « $login ») a été demandée depuis l'adresse $ip, le " . date('d/m/Y à H:i') . ".\n\n"
            . "Pour choisir un nouveau mot de passe, ouvrez ce lien (valable $RESET_MINUTES minutes, une seule fois) :\n" . app_link('reinit', $send) . "\n\n"
            . "Si vous n'êtes pas à l'origine de cette demande, ignorez ce message : votre mot de passe actuel reste valable.\n");
        auth_log($err ? 'mail-fail' : 'forgot', $login, $err ?: 'lien envoyé à ' . $tg['email']);
    } else auth_log('forgot', $login, 'aucun envoi (compte non super administrateur, sans adresse, envoi non réglé ou limite atteinte)');
    $wait = 2.0 - (microtime(true) - $t0); if ($wait > 0) usleep((int)($wait * 1e6));   // même durée de réponse dans tous les cas
    out(200, ['ok' => true, 'message' => 'Si cet identifiant est celui d’un super administrateur dont la fiche comporte une adresse e-mail, un lien de réinitialisation vient de lui être envoyé (valable ' . $RESET_MINUTES . ' minutes).']);
}
if ($action === 'reset-check' || $action === 'reset-password') {
    $in = $action === 'reset-password' ? body_json() : [];
    throttle_check($RFAILS, $ip, 10, 900);
    $tok = strtolower((string)($action === 'reset-check' ? ($_GET['token'] ?? '') : ($in['token'] ?? '')));
    flock($lock, LOCK_EX);
    $r = reset_find($RESETS, $tok);
    $acc = read_json($ACC); $doc = read_doc($FILE);
    $tg = $r ? super_target($doc, $acc, (string)$r['login']) : null;
    if (!$r || !$tg || $tg['userId'] !== (string)$r['userId']) {
        throttle_fail($RFAILS, $ip, 900); flock($lock, LOCK_UN);
        out(404, ['error' => 'Lien de réinitialisation inconnu, déjà utilisé ou expiré : refaites une demande depuis « Mot de passe oublié ? ».']);
    }
    $login = (string)$r['login'];
    if ($action === 'reset-check') { flock($lock, LOCK_UN); out(200, ['name' => $tg['name'], 'login' => $login, 'policy' => pw_policy(), 'expires' => date('c', (int)$r['exp'])]); }
    $pass = (string)($in['password'] ?? '');
    if ($why = pw_problem($pass, $login)) { flock($lock, LOCK_UN); out(400, ['error' => $why]); }
    $acc[$login]['hash'] = password_hash($pass, PASSWORD_DEFAULT);
    $acc[$login]['stamp'] = bin2hex(random_bytes(8));          // toutes les sessions ouvertes sont fermées
    $acc[$login]['lastLogin'] = date('c');
    if (!write_json($ACC, $acc)) { flock($lock, LOCK_UN); out(500, ['error' => 'Écriture impossible']); }
    $all = read_json($RESETS);
    foreach ($all as $k => $v) if (($v['login'] ?? '') === $login) $all[$k]['used'] = true;   // tous les liens envoyés à ce compte deviennent inutilisables
    write_json($RESETS, $all);
    lock_clear($LOCKF, $login); throttle_clear($FAILS, $ip); throttle_clear($RFAILS, $ip);
    flock($lock, LOCK_UN);
    auth_log('pw-reset', $login, $tg['name'] . ' (lien reçu par e-mail)');
    if (mail_ready()) send_mail($tg['email'], 'Votre mot de passe a été changé – planning',
        "Bonjour {$tg['name']},\n\nLe mot de passe de votre compte (identifiant « $login ») vient d'être changé grâce au lien de réinitialisation, depuis l'adresse $ip, le " . date('d/m/Y à H:i') . ".\n\n"
        . "Si ce n'est pas vous, prévenez immédiatement le service informatique.\n");
    out(200, me_payload(open_session($login, $acc[$login], $tg['name'])));
}
/* --- super administrateur : e-mail de test --- */
if ($action === 'mail-test') {
    body_json();
    $doc = read_doc($FILE);
    if (!has_perm($doc, $me['userId'], 'super')) out(403, ['error' => 'Réservé au super administrateur']);
    $u = find_user($doc, $me['userId']); $to = trim((string)($u['email'] ?? ''));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) out(400, ['error' => 'Votre fiche utilisateur n’a pas d’adresse e-mail valide (Paramétrage › Utilisateurs).']);
    $err = send_mail($to, 'E-mail de test – planning', "Bonjour,\n\nCet e-mail confirme que le planning peut envoyer des messages (réinitialisation du mot de passe des super administrateurs, alertes de connexion).\n\nLien de l'application : " . mail_cfg()['appUrl'] . "\n");
    auth_log($err ? 'mail-fail' : 'mail-test', $me['login'], $err ?: 'envoyé à ' . $to);
    if ($err) out(502, ['error' => $err]);
    out(200, ['ok' => true, 'to' => $to]);
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
    if ($why = pw_problem($next, $me['login'])) out(400, ['error' => $why]);
    if (hash_equals((string)($in['current'] ?? ''), $next)) out(400, ['error' => 'Le nouveau mot de passe doit être différent de l’actuel.']);
    $acc[$me['login']]['hash'] = password_hash($next, PASSWORD_DEFAULT);
    $acc[$me['login']]['stamp'] = bin2hex(random_bytes(8));
    if (!write_json($ACC, $acc)) out(500, ['error' => 'Écriture impossible']);
    flock($lock, LOCK_UN);
    $_SESSION['auth']['stamp'] = $acc[$me['login']]['stamp'];
    unset($_SESSION['auth']['weak']);
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

/* --- administrateurs : inviter une personne à créer son accès (code à usage unique), lister, annuler --- */
if ($action === 'invite' || $action === 'invite-revoke' || $action === 'invites') {
    flock($lock, LOCK_EX);
    $doc = read_doc($FILE);
    if (!has_perm($doc, $me['userId'], 'users')) { flock($lock, LOCK_UN); out(403, ['error' => 'Réservé aux gestionnaires des utilisateurs']); }
    $now = time();
    $all = array_filter(read_json($INV), function ($v) use ($now) { return is_array($v) && (int)($v['exp'] ?? 0) > $now; });
    if ($action === 'invites') {
        flock($lock, LOCK_UN);
        $list = [];
        foreach ($all as $v) $list[] = ['userId' => (string)$v['userId'], 'name' => (string)$v['name'], 'by' => (string)($v['by'] ?? ''),
                                        'created' => date('c', (int)($v['t'] ?? 0)), 'expires' => date('c', (int)$v['exp'])];
        out(200, ['invites' => $list, 'days' => $INVITE_DAYS]);
    }
    $in = body_json();
    $uid = (string)($in['userId'] ?? '');
    $u = find_user($doc, $uid);
    if (!$u) { flock($lock, LOCK_UN); out(404, ['error' => 'Personne inconnue : enregistrez d’abord sa fiche.']); }
    if (has_perm($doc, $uid, 'super') && !has_perm($doc, $me['userId'], 'super')) { flock($lock, LOCK_UN); out(403, ['error' => 'Seul un super administrateur peut inviter un super administrateur.']); }
    $all = array_filter($all, function ($v) use ($uid) { return (string)$v['userId'] !== $uid; });   // une seule invitation en cours par personne
    if ($action === 'invite-revoke') {
        write_json($INV, $all); flock($lock, LOCK_UN);
        auth_log('invite-revoke', $me['login'], user_label($u));
        out(200, ['ok' => true]);
    }
    if (($u['active'] ?? true) === false) { flock($lock, LOCK_UN); out(400, ['error' => 'Compte désactivé : réactivez la fiche avant d’inviter.']); }
    foreach (read_json($ACC) as $a) if ((string)($a['userId'] ?? '') === $uid) { flock($lock, LOCK_UN); out(409, ['error' => 'Cette personne a déjà un accès : réinitialisez-le d’abord si besoin.']); }
    $code = invite_code();
    $all[hash('sha256', invite_norm($code))] = ['userId' => $uid, 'name' => user_label($u), 'by' => $me['name'], 't' => $now, 'exp' => $now + $INVITE_DAYS * 86400];
    if (!write_json($INV, $all)) { flock($lock, LOCK_UN); out(500, ['error' => 'Écriture impossible']); }
    flock($lock, LOCK_UN);
    auth_log('invite', $me['login'], user_label($u));
    out(200, ['code' => $code, 'expires' => date('c', $now + $INVITE_DAYS * 86400), 'days' => $INVITE_DAYS, 'name' => user_label($u)]);
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
/* --- comptes bloqués : liste et déblocage (gestion des utilisateurs ou sécurité) --- */
if ($action === 'locks' || $action === 'unlock-login') {
    $doc = read_doc($FILE);
    if (!has_any($doc, $me['userId'], ['users', 'security'])) out(403, ['error' => 'Réservé aux gestionnaires des utilisateurs']);
    flock($lock, LOCK_EX);
    if ($action === 'unlock-login') {
        $in = body_json(); $l = clean_login($in['login'] ?? '');
        lock_clear($LOCKF, $l); flock($lock, LOCK_UN);
        auth_log('unlock', $me['login'], $l);
        out(200, ['ok' => true]);
    }
    $list = [];
    foreach (array_keys(read_json($LOCKF)) as $l) {
        $e = lock_state($LOCKF, (string)$l); if (!$e) continue;
        $acc = read_json($ACC)[$l] ?? null;
        $list[] = ['login' => (string)$l, 'userId' => is_array($acc) ? (string)$acc['userId'] : '', 'n' => (int)$e['n'], 'last' => date('c', (int)($e['t'] ?? 0)),
                   'locked' => (int)($e['until'] ?? 0) > time(), 'until' => (int)($e['until'] ?? 0) >= PHP_INT_MAX - 1 ? null : date('c', (int)$e['until'])];
    }
    flock($lock, LOCK_UN);
    out(200, ['locks' => $list, 'attempts' => $LOCK_ATTEMPTS, 'minutes' => $LOCK_MINUTES]);
}

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
        foreach ((is_dir($bdir) ? array_merge(glob("$bdir/planning-*.json") ?: [], glob("$bdir/complet-*.json") ?: []) : []) as $f)
            $files[] = ['name' => basename($f), 'size' => filesize($f), 'date' => date('c', filemtime($f)), 'full' => strpos(basename($f), 'complet-') === 0,
                        'version' => preg_match('/-v(\d+)/', $f, $m) ? (int)$m[1] : null];
        usort($files, function ($a, $b) { return strcmp($b['date'], $a['date']) ?: strcmp($b['name'], $a['name']); });
        [$l, $n] = backup_slots();
        out(200, ['files' => $files, 'keep' => $KEEP_BACKUPS, 'every' => $BACKUP_EVERY,
                  'auto' => ['times' => $AUTO_BACKUP_TIMES, 'days' => $AUTO_BACKUP_DAYS, 'keep' => $AUTO_BACKUP_KEEP, 'state' => read_json("$DATA_DIR/backup-state.json"),
                             'next' => $AUTO_BACKUP_TIMES && $n ? date('c', $n) : null, 'tz' => date_default_timezone_get()]]);
    }
    if ($action === 'backup-get') {
        $name = basename((string)($_GET['f'] ?? ''));
        if (!preg_match('/^(planning|complet)-[\w-]+\.json$/', $name) || !is_file("$bdir/$name")) out(404, ['error' => 'Sauvegarde introuvable']);
        auth_log('backup-get', $me['login'], $name);
        http_response_code(200); readfile("$bdir/$name"); exit;
    }
    if ($action === 'backup-now') {
        body_json();
        flock($lock, LOCK_SH);
        if (!is_file($FILE)) out(400, ['error' => 'Aucun planning à sauvegarder']);
        $name = full_backup('manuelle');
        flock($lock, LOCK_UN);
        if (!$name) out(500, ['error' => 'Copie impossible']);
        auth_log('backup-now', $me['login'], $name);
        out(200, ['ok' => true, 'name' => $name]);
    }
    $isSuper = has_perm($doc, $me['userId'], 'super');
    if ($action === 'sec-get') {
        if ($isSuper && empty($SEC['cronKey'])) { $SEC['cronKey'] = bin2hex(random_bytes(20)); write_json($SECF, $SEC); }
        out(200, ['inviteDays' => $INVITE_DAYS, 'sessionHours' => (int)round($SESSION_IDLE / 3600),
            'minPassword' => $MIN_PASSWORD, 'pwDigit' => $PW_DIGIT, 'pwSpecial' => $PW_SPECIAL, 'pwUpper' => $PW_UPPER,
            'lockAttempts' => $LOCK_ATTEMPTS, 'lockMinutes' => $LOCK_MINUTES,
            'backupTimes' => $AUTO_BACKUP_TIMES, 'backupDays' => $AUTO_BACKUP_DAYS, 'backupKeep' => $AUTO_BACKUP_KEEP,
            'cronKey' => $isSuper ? (string)($SEC['cronKey'] ?? '') : '',
            'smtpHost' => (string)($SEC['smtpHost'] ?? ''), 'smtpPort' => (int)($SEC['smtpPort'] ?? 0), 'smtpSecure' => (string)($SEC['smtpSecure'] ?? 'tls'),
            'smtpUser' => $isSuper ? (string)($SEC['smtpUser'] ?? '') : '', 'smtpPassSet' => ($SEC['smtpPass'] ?? '') !== '',
            'mailFrom' => (string)($SEC['mailFrom'] ?? ''), 'mailFromName' => (string)($SEC['mailFromName'] ?? 'Planning D8'), 'appUrl' => (string)($SEC['appUrl'] ?? ''),
            'mailReady' => mail_ready(), 'resetMinutes' => $RESET_MINUTES,
            'superLockAttempts' => $SUPER_LOCK_ATTEMPTS, 'superLockMinutes' => $SUPER_LOCK_MINUTES, 'superLockMail' => $SUPER_LOCK_MAIL,
            'maxFails' => $MAX_FAILS, 'failWindow' => $FAIL_WINDOW, 'https' => $https, 'super' => $isSuper]);
    }
    if ($action === 'sec-set') {
        if (!$isSuper) out(403, ['error' => 'Réservé au super administrateur']);
        $in = body_json();
        $times = [];
        foreach ((array)($in['backupTimes'] ?? []) as $t) { $t = trim((string)$t); if (preg_match('/^(\d{1,2})[:hH](\d{2})?$/', $t, $m) && (int)$m[1] < 24 && (int)($m[2] ?? 0) < 60) $times[] = sprintf('%02d:%02d', (int)$m[1], (int)($m[2] ?? 0)); }
        $times = array_values(array_unique($times)); sort($times);
        $days = array_values(array_unique(array_filter(array_map('intval', (array)($in['backupDays'] ?? [])), function ($d) { return $d >= 1 && $d <= 7; }))); sort($days);
        $new = array_merge($SEC, ['inviteDays' => max(1, min(30, (int)($in['inviteDays'] ?? 7))), 'sessionHours' => max(1, min(72, (int)($in['sessionHours'] ?? 12))),
                'minPassword' => max(8, min(64, (int)($in['minPassword'] ?? 8))), 'pwDigit' => !empty($in['pwDigit']), 'pwSpecial' => !empty($in['pwSpecial']), 'pwUpper' => !empty($in['pwUpper']),
                'lockAttempts' => max(1, min(20, (int)($in['lockAttempts'] ?? 3))), 'lockMinutes' => max(0, min(1440, (int)($in['lockMinutes'] ?? 15))),
                'superLockAttempts' => max(0, min(20, (int)($in['superLockAttempts'] ?? 3))), 'superLockMinutes' => max(0, min(1440, (int)($in['superLockMinutes'] ?? 0))),
                'superLockMail' => !array_key_exists('superLockMail', $in) || !empty($in['superLockMail']), 'resetMinutes' => max(5, min(1440, (int)($in['resetMinutes'] ?? 30))),
                'backupTimes' => array_slice($times, 0, 12), 'backupDays' => $days ?: [1, 2, 3, 4, 5, 6, 7], 'backupKeep' => max(2, min(500, (int)($in['backupKeep'] ?? 60))),
                'changed' => date('c'), 'by' => $me['name']]);
        if (!empty($in['newCronKey'])) $new['cronKey'] = bin2hex(random_bytes(20));
        // envoi des e-mails (le mot de passe SMTP n'est remplacé que s'il est ressaisi)
        $clean = function ($v, int $n): string { return cut(trim(str_replace(["\r", "\n", "\0"], '', (string)$v)), $n); };
        if (array_key_exists('smtpHost', $in)) {
            $new['smtpHost'] = preg_replace('/[^A-Za-z0-9.\-]/', '', $clean($in['smtpHost'], 120));
            $new['smtpPort'] = max(0, min(65535, (int)($in['smtpPort'] ?? 0)));
            $new['smtpSecure'] = in_array($in['smtpSecure'] ?? '', ['none', 'tls', 'ssl'], true) ? $in['smtpSecure'] : 'tls';
            $new['smtpUser'] = $clean($in['smtpUser'] ?? '', 120);
            if ((string)($in['smtpPass'] ?? '') !== '') $new['smtpPass'] = cut((string)$in['smtpPass'], 200);
            if (!empty($in['smtpPassClear'])) $new['smtpPass'] = '';
            $from = $clean($in['mailFrom'] ?? '', 120);
            if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) out(400, ['error' => 'Adresse d’expédition invalide.']);
            $new['mailFrom'] = $from;
            $new['mailFromName'] = $clean($in['mailFromName'] ?? 'Planning D8', 60) ?: 'Planning D8';
            $url = $clean($in['appUrl'] ?? '', 300);
            if ($url !== '' && !preg_match('#^https?://[^\s"<>]+$#i', $url)) out(400, ['error' => 'Adresse de l’application invalide (http://… ou https://…).']);
            $new['appUrl'] = preg_replace('/[?#].*$/', '', $url);
        }
        if (!write_json($SECF, $new)) out(500, ['error' => 'Écriture impossible']);
        auth_log('sec-set', $me['login'], 'mots de passe ' . $new['minPassword'] . ' car.' . ($new['pwDigit'] ? ' + chiffre' : '') . ($new['pwSpecial'] ? ' + spécial' : '') . ($new['pwUpper'] ? ' + majuscule' : '')
            . ' · blocage après ' . $new['lockAttempts'] . ' essais (' . ($new['lockMinutes'] ?: '∞') . ' min), super admin ' . ($new['superLockAttempts'] ?: 'jamais') . ' (' . ($new['superLockMinutes'] ?: 'jusqu’au lien e-mail') . ') · sauvegardes ' . (implode(', ', $new['backupTimes']) ?: 'désactivées')
            . ' · session ' . $new['sessionHours'] . ' h · invitations ' . $new['inviteDays'] . ' j');
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
    if (!empty($me['weak'])) out(403, ['denied' => true, 'error' => 'Votre mot de passe ne respecte plus les règles de sécurité : changez-le avant d’enregistrer.']);

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
