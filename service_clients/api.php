<?php
/**
 * D8 · Service clients — serveur partagé : connexion, droits, réglages, dossiers, sauvegardes
 * -----------------------------------------------------------------------------
 * Déposez ce fichier avec index.html et web.config dans
 * C:\inetpub\wwwroot\service_clients (IIS + PHP 7.4 ou plus). Le script
 * installer-service-clients.ps1 vérifie IIS, active PHP pour ce dossier,
 * protège le dossier de données et définit le mot de passe super administrateur.
 *
 * DROITS : chaque personne se connecte avec son adresse e-mail. Son rôle
 * détermine les onglets visibles ET les dossiers que le serveur accepte de lui
 * montrer ou d'enregistrer (cb = SAV carte bancaire, int = SAV intervention,
 * remb = remboursement, cmd = commande par carte). Le rôle « Super
 * administrateur » a tous les droits, comme dans le planning D8.
 *
 * CONNEXION (même fonctionnement que le planning D8) :
 *   - mise en service : le premier super administrateur crée son accès avec le
 *     mot de passe super administrateur (défini par le script d'installation,
 *     ou depuis le serveur lui-même à la première ouverture) ;
 *   - ensuite, un accès ne se crée qu'avec une invitation (code ou lien à usage
 *     unique, envoyé par e-mail si l'envoi est réglé) ;
 *   - blocage après N mots de passe erronés, par compte et par adresse IP ;
 *   - double authentification (TOTP) facultative ou obligatoire ;
 *   - « Mot de passe oublié ? » : lien par e-mail (réglable) ;
 *   - mots de passe hachés (password_hash), sessions HttpOnly SameSite=Strict.
 *
 * SÉCURITÉ — à lire avant la mise en service :
 *   1. Servez l'outil en HTTPS si possible : sans HTTPS, les mots de passe
 *      circulent en clair sur le réseau interne.
 *   2. $ALLOWED_NETS limite l'accès aux plages IP internes : ajustez-le.
 *   3. Le dossier « data » contient tout (comptes, dossiers clients, journal) :
 *      placez-le hors de la racine web si possible ($DATA_DIR) et sauvegardez-le.
 *   4. Le serveur contrôle tous les droits : une personne sans le droit
 *      « remb » ne peut ni lire ni enregistrer un dossier de remboursement,
 *      même en appelant l'API directement.
 *   5. FORMULAIRE CLIENT EN LIGNE (formulaire.html) : seules les actions
 *      form-info et form-submit sont accessibles hors du réseau interne
 *      ($PUBLIC_FORMS), et uniquement en HTTPS. Le client reçoit un lien
 *      personnel (usage unique) ; à l'envoi, la demande est enregistrée,
 *      transmise à l'adresse Monétique, puis un accusé de réception part au
 *      client et une confirmation de cet envoi revient à la Monétique.
 *
 * Vos réglages (dossier de données, plages IP…) se mettent de préférence dans
 * config.php, à côté de ce fichier : une mise à jour d'api.php ne les écrase pas.
 * -----------------------------------------------------------------------------
 */
declare(strict_types=1);
const DUMMY_HASH = '$2y$10$4xOVay09Vpo4GvOveNWNLOlV4fL3bflcy3djVT1PSB8hLgyUOldu6';   // leurre : identifiant inconnu, même coût de calcul

$DATA_DIR      = __DIR__ . '/data';   // idéalement hors racine web : 'C:\\inetpub\\service_clients_data'
$ALLOWED_NETS  = ['127.0.0.0/8', '::1/128', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'];
$TIMEZONE      = 'Europe/Paris';
$MAX_BYTES     = 60 * 1024 * 1024;    // taille maximale d'une restauration de sauvegarde
$DOSSIER_BYTES = 400 * 1024;          // taille maximale d'un dossier
/* sauvegarde complète automatique : heures, jours (1 = lundi … 7 = dimanche), nombre conservé */
$AUTO_BACKUP_TIMES = ['12:00', '17:00'];
$AUTO_BACKUP_DAYS  = [1, 2, 3, 4, 5, 6, 7];
$AUTO_BACKUP_KEEP  = 60;

/* --- connexion --- */
$INVITE_DAYS   = 7;                   // validité (jours) d'une invitation à créer son accès
$SESSION_IDLE  = 12 * 3600;           // déconnexion après N secondes sans échange avec le serveur
$MIN_PASSWORD  = 8;                   // longueur minimale (CNIL : 8 caractères de 3 types + blocage, ou 12 sans blocage)
$PW_DIGIT      = true;                // au moins un chiffre
$PW_SPECIAL    = true;                // au moins un caractère spécial
$PW_UPPER      = false;               // au moins une majuscule
$LOCK_ATTEMPTS = 3;                   // compte bloqué après N mots de passe erronés…
$LOCK_MINUTES  = 15;                  // …pendant N minutes (0 = jusqu'au déblocage par un administrateur)
$SUPER_LOCK_ATTEMPTS = 3;             // super administrateurs : bloqués après N erreurs (0 = jamais, alerte e-mail à la place)…
$SUPER_LOCK_MINUTES  = 0;             // …pendant N minutes (0 = jusqu'au lien e-mail ou au déblocage par un administrateur)
$SUPER_LOCK_MAIL     = true;          // e-mail au super administrateur bloqué, avec un lien pour choisir un nouveau mot de passe
$RESET_MINUTES = 30;                  // validité du lien « mot de passe oublié »
$RESET_FOR     = 'all';               // « mot de passe oublié » par e-mail : 'all' (tout le monde), 'super' ou 'none'
$MFA_REQUIRED  = 'none';              // double authentification obligatoire pour : 'none', 'super', 'admin' ou 'all'
$MAX_FAILS     = 8;                   // échecs de connexion tolérés par adresse IP…
$MFA_MAX_FAILS = 10;                  // codes de double authentification erronés (par compte) avant 15 minutes de blocage
$FAIL_WINDOW   = 900;                 // …sur cette durée (s), puis blocage pendant la même durée

/* --- mot de passe super administrateur : mise en service, restauration, réinitialisations sensibles.
   Il n'y a PAS de mot de passe par défaut : le script d'installation le définit (data/superadmin.json),
   ou il se choisit à la première ouverture de l'outil depuis le serveur lui-même (http://localhost/…). */
$SUPERADMIN_HASH = '';
$SUPERADMIN_TTL  = 300;               // validité (s) d'une confirmation super administrateur

/* --- formulaire client en ligne (formulaire.html) --- */
$PUBLIC_FORMS    = true;              // form-info / form-submit joignables hors des plages $ALLOWED_NETS (en HTTPS seulement)
$TRUSTED_PROXIES = [];                // proxys inverses de confiance (ex. ['10.0.0.5']) : l'adresse du client est lue dans X-Forwarded-For
$WEB_MAX_PER_IP  = 10;                // demandes envoyées par heure et par adresse IP
$WEB_MAX_PER_DAY = 300;               // demandes par jour au total via le lien générique (s'il est activé)

if (is_file(__DIR__ . '/config.php')) require __DIR__ . '/config.php';

if (!@date_default_timezone_set($TIMEZONE)) date_default_timezone_set('Europe/Paris');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex');
header('Referrer-Policy: same-origin');

/* =============================================================================
   Référentiel : types de demande, droits, rôles et utilisateurs de départ
   ========================================================================== */
const TYPES = ['cb', 'int', 'remb', 'cmd'];
const TYPE_CODES = ['cb' => 'CB', 'int' => 'INT', 'remb' => 'REMB', 'cmd' => 'CMD'];
const SCENARIOS = [
    'cb'   => ['accuse', 'complements', 'planifiee', 'resolu'],
    'int'  => ['accuse', 'complements', 'planifiee', 'realisee'],
    'remb' => ['accuse', 'complements', 'accepte', 'refus'],
    'cmd'  => ['accuse', 'complements', 'confirmee', 'refus', 'expediee'],
];
const ALL_PERMS = ['cb', 'int', 'remb', 'cmd', 'web', 'delete', 'export', 'settings', 'users', 'security', 'super'];
const SEED_ROLES = [
    ['id' => 'r_super', 'name' => 'Super administrateur', 'perms' => ['super']],
    ['id' => 'r_paiement', 'name' => 'Paiement CB et remboursement', 'perms' => ['cmd', 'remb', 'export']],
    ['id' => 'r_sav', 'name' => 'SAV (carte bancaire et intervention)', 'perms' => ['cb', 'int', 'export']],
    ['id' => 'r_web', 'name' => 'Formulaires en ligne (Monétique)', 'perms' => ['web']],
    ['id' => 'r_admin', 'name' => 'Administrateur', 'perms' => ['cb', 'int', 'remb', 'cmd', 'web', 'delete', 'export', 'settings', 'users', 'security']],
];
const SEED_USERS = [
    ['cfernandes@d8.fr', 'r_paiement'], ['kbutant@d8.fr', 'r_paiement'], ['sbertrand@d8.fr', 'r_paiement'], ['nmarchiori@d8.fr', 'r_paiement'],
    ['dmalemebe@d8.fr', 'r_sav'],
    ['aneves@d8.fr', 'r_super'], ['mzidani@d8.fr', 'r_super'], ['tmefre@d8.fr', 'r_super'], ['lgasp@d8.fr', 'r_super'],
    ['monetique@d8.fr', 'r_web'],
];
/** noms connus (fiches du planning D8) ; les autres sont déduits de l'adresse et se corrigent dans Administration › Utilisateurs */
const SEED_NAMES = ['tmefre@d8.fr' => 'Tafré Mefré', 'mzidani@d8.fr' => 'Mohamed Zidani', 'cfernandes@d8.fr' => 'Cristina Fernandes', 'monetique@d8.fr' => 'Monétique D8'];
/** réglages partagés de l'outil (coordonnées, adresses de retour, délais, logo) : valeurs par défaut et type */
const SETTINGS_DEFAULTS = [
    'societe' => 'D8 S.A.S.U.', 'adresse' => '7/9 rue Léon Geffroy – 94408 Vitry-sur-Seine Cedex', 'telephone' => '01 47 18 38 38',
    'emailGeneral' => 'comd8@d8.fr', 'emailMonetique' => 'Monetique@d8.fr', 'emailSav' => 'comd8@d8.fr', 'emailCommandes' => 'comd8@d8.fr',
    'expediteur' => '', 'politesse' => 'Cordialement', 'delaiReclamation' => 30, 'delaiTraitement' => 5, 'delaiIntervention' => '',
    'delaiLivraison' => '', 'horairesTel' => '', 'conservation' => 12, 'logo' => '',
    /* formulaire client en ligne */
    'webRemb' => true,                 // demande de remboursement en ligne ouverte
    'webNotify' => 'Monetique@d8.fr',  // reçoit chaque demande, puis la confirmation de l'accusé de réception
    'webAR' => true,                   // accusé de réception automatique au client
    'webPublicUrl' => '',              // adresse publique du dossier, ex. https://sav.d8.fr/service_clients/
    'webLinkDays' => 30,               // validité d'un lien envoyé à un client
    'webGeneric' => false,             // lien générique sans invitation (affiche sur les distributeurs) : déconseillé
];
const WEB_SETTINGS = ['webRemb', 'webNotify', 'webAR', 'webPublicUrl', 'webLinkDays', 'webGeneric'];

/* =============================================================================
   Outils
   ========================================================================== */
/* Répond et termine. Les verrous (flock) sont libérés par PHP à la fin du script. */
function out(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
function is_loopback(string $ip): bool { return ip_in($ip, '127.0.0.0/8') || $ip === '::1'; }
/** adresse lue dans X-Forwarded-For (« 1.2.3.4:51234 » et « [2001:db8::1]:443 » acceptés) ; illisible : '0.0.0.0', traitée comme extérieure */
function fwd_ip(string $s): string {
    $s = trim($s, " \t\"");
    if (preg_match('/^\[([0-9a-f:.]+)\](?::\d+)?$/i', $s, $m)) $s = $m[1];
    elseif (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $s, $m)) $s = $m[1];
    return filter_var($s, FILTER_VALIDATE_IP) ? $s : '0.0.0.0';
}
function valid_json(string $s): bool {
    if (function_exists('json_validate')) return json_validate($s);
    json_decode($s);
    return json_last_error() === JSON_ERROR_NONE;
}
function read_json(string $f): array {
    $d = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    return is_array($d) ? $d : [];
}
function write_json(string $f, array $d): bool {
    $tmp = $f . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX) === false) return false;
    // Windows : rename() échoue tant qu'un autre processus lit la cible ; on réessaie (jamais de suppression préalable)
    for ($i = 0; $i < 25; $i++) { if (@rename($tmp, $f)) return true; usleep(20000); }
    $ok = @file_put_contents($f, (string)file_get_contents($tmp), LOCK_EX) !== false;   // dernier recours : réécriture sur place
    @unlink($tmp);
    return $ok;
}
/** la requête vient-elle de cette même application ? (en-tête Origin, envoyé par les navigateurs pour les POST) */
function same_origin(): bool {
    global $viaProxy;
    $o = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($o === '' || $o === 'null') return $o === '';
    $h = (string)parse_url($o, PHP_URL_HOST); $p = parse_url($o, PHP_URL_PORT);
    $hosts = [(string)($_SERVER['HTTP_HOST'] ?? '')];
    if (!empty($viaProxy) && !empty($_SERVER['HTTP_X_FORWARDED_HOST'])) $hosts[] = trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_HOST'])[0]);
    foreach ($hosts as $host) if (strcasecmp($h . ($p ? ':' . $p : ''), $host) === 0 || strcasecmp($h, $host) === 0) return true;
    return false;
}
/** corps JSON d'une requête POST (les formulaires d'un autre site ne peuvent pas l'envoyer) */
function body_json(int $max = 262144): array {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') out(405, ['error' => 'POST attendu']);
    if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') === false) out(415, ['error' => 'JSON attendu']);
    if (!same_origin()) out(403, ['error' => 'Requête d’un autre site refusée']);
    $raw = (string)file_get_contents('php://input', false, null, 0, $max + 1);
    if (strlen($raw) > $max) out(413, ['error' => 'Données trop volumineuses']);
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}
/** tronque sans couper un caractère accentué (n'exige pas l'extension mbstring) */
function cut(string $s, int $n): string { return preg_match('/^.{0,' . $n . '}/us', $s, $m) ? $m[0] : ''; }
function clean_text($v, int $n, bool $multi = false): string {
    $s = (string)$v;
    if (!preg_match('//u', $s)) $s = (string)@iconv('Windows-1252', 'UTF-8//IGNORE', $s);
    $s = preg_replace($multi ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u', '', $s);
    return cut(trim((string)$s), $n);
}
function clean_login($s): string { return substr(strtolower(trim((string)$s)), 0, 160); }
function login_ok(string $l): bool { return strlen($l) <= 120 && (bool)preg_match('/^[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}$/', $l); }
/** nom affiché par défaut à partir de l'adresse : cfernandes@d8.fr → « C. Fernandes » */
function name_from_login(string $l): string {
    $p = (string)strtok($l, '@');
    if (preg_match('/^([a-z])[._-]?([a-z][a-z\-]+)$/', $p, $m)) return strtoupper($m[1]) . '. ' . ucfirst($m[2]);
    return ucfirst($p);
}
function luhn(string $d): bool {
    $s = 0; $alt = false;
    for ($i = strlen($d) - 1; $i >= 0; $i--) { $n = (int)$d[$i]; if ($alt) { $n *= 2; if ($n > 9) $n -= 9; } $s += $n; $alt = !$alt; }
    return $s % 10 === 0;
}
/** masque tout numéro de carte complet (13 à 19 chiffres, clé de Luhn valide) : on ne conserve jamais de numéro de carte */
function mask_pan(string $s): string {
    return (string)preg_replace_callback('/\d(?:[ \-]?\d){12,18}/', function ($m) {
        $d = preg_replace('/\D/', '', $m[0]);
        return strlen($d) >= 13 && strlen($d) <= 19 && luhn($d) ? '•••• •••• •••• ' . substr($d, -4) : $m[0];
    }, $s);
}
/** empreinte du fichier HTML servi : permet aux postes ouverts de voir qu'une nouvelle version est installée */
function page_build(): ?string {
    $f = __DIR__ . '/index.html';
    if (!is_file($f)) return null;
    clearstatcache(true, $f);
    return filemtime($f) . '-' . filesize($f);
}

/* --- contrôle d'accès réseau : l'outil est réservé au réseau interne, sauf le formulaire client en ligne --- */
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$viaProxy = false;
foreach ($TRUSTED_PROXIES as $px) if (ip_in($ip, strpos($px, '/') === false ? $px . (strpos($px, ':') === false ? '/32' : '/128') : $px)) { $viaProxy = true; break; }
$fwd = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
$unknownProxy = false;
if ($viaProxy) {
    // le proxy de confiance ajoute l'adresse du client en dernier
    if ($fwd !== '') { $chain = array_map('trim', explode(',', $fwd)); $ip = fwd_ip((string)end($chain)); }
} elseif ($fwd !== '' || !empty($_SERVER['HTTP_FORWARDED'])) {
    // relayée par un proxy non déclaré : l'adresse vue est celle du proxy (souvent interne), pas celle du visiteur
    $unknownProxy = true;
}
$action = (string)($_GET['a'] ?? 'ping');
$INTERNAL = !$unknownProxy && empty($ALLOWED_NETS);
if (!$unknownProxy) foreach ($ALLOWED_NETS as $net) { if (ip_in($ip, $net)) { $INTERNAL = true; break; } }
$WEB_ACTIONS = ['form-info', 'form-submit'];
if (!$INTERNAL && !($PUBLIC_FORMS && in_array($action, $WEB_ACTIONS, true)))
    out(403, ['error' => $unknownProxy ? "Requête relayée par un proxy non déclaré ($ip) : ajoutez son adresse à \$TRUSTED_PROXIES dans config.php." : "Accès refusé pour l'adresse $ip"]);

/* --- dossier de données --- */
if (!is_dir($DATA_DIR) && !@mkdir($DATA_DIR, 0770, true) && !is_dir($DATA_DIR)) out(500, ['error' => 'Impossible de créer le dossier de données (droits d’écriture du compte IIS ?)']);
if (!is_writable($DATA_DIR)) out(500, ['error' => 'Dossier de données non accessible en écriture : lancez installer-service-clients.ps1 ou donnez le droit « Modifier » au compte du pool d’applications IIS.']);
// refus d'accès direct au dossier (Apache puis IIS)
if (!file_exists("$DATA_DIR/.htaccess")) @file_put_contents("$DATA_DIR/.htaccess", "Require all denied\nDeny from all\n");
if (!file_exists("$DATA_DIR/web.config")) @file_put_contents("$DATA_DIR/web.config",
    '<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer><security><requestFiltering>'
  . '<fileExtensions allowUnlisted="false" /></requestFiltering></security></system.webServer></configuration>');
foreach (['dossiers', 'backups', 'sessions'] as $sub) if (!is_dir("$DATA_DIR/$sub")) @mkdir("$DATA_DIR/$sub", 0770, true);

$LOCK   = "$DATA_DIR/app.lock";
$USERS  = "$DATA_DIR/users.json";        // fiches des personnes et rôles
$ACC    = "$DATA_DIR/accounts.json";     // identifiants + mots de passe hachés + double authentification
$SETF   = "$DATA_DIR/settings.json";     // réglages partagés de l'outil
$DOS    = "$DATA_DIR/dossiers";          // un fichier par dossier
$SEQ    = "$DATA_DIR/sequence.json";     // numérotation des dossiers
$FAILS  = "$DATA_DIR/auth-fails.json";   // tentatives de connexion ratées, par adresse IP
$INV    = "$DATA_DIR/invites.json";      // invitations en attente (empreinte du code → personne, échéance)
$SECF   = "$DATA_DIR/security.json";     // réglages de sécurité modifiés par un super administrateur (priment sur ceux ci-dessus)
$LOCKF  = "$DATA_DIR/login-locks.json";  // comptes bloqués après trop de mots de passe erronés
$RESETS = "$DATA_DIR/resets.json";       // liens « mot de passe oublié » (empreinte du lien → compte, échéance)
$RFAILS = "$DATA_DIR/reset-fails.json";  // demandes de lien et liens erronés, par adresse IP
$SAF    = "$DATA_DIR/superadmin.json";   // empreinte du mot de passe super administrateur
$WEBL   = "$DATA_DIR/web-links.json";    // liens du formulaire en ligne envoyés aux clients (empreinte → client, échéance)
$WEBT   = "$DATA_DIR/web-throttle.json"; // envois du formulaire en ligne, par adresse IP

$SEC = read_json($SECF);
if (!empty($SEC['inviteDays'])) $INVITE_DAYS = max(1, min(30, (int)$SEC['inviteDays']));
foreach (['pwDigit' => 'PW_DIGIT', 'pwSpecial' => 'PW_SPECIAL', 'pwUpper' => 'PW_UPPER'] as $k => $v) if (isset($SEC[$k])) $$v = (bool)$SEC[$k];
if (isset($SEC['lockAttempts'])) $LOCK_ATTEMPTS = max(1, min(20, (int)$SEC['lockAttempts']));
if (isset($SEC['lockMinutes'])) $LOCK_MINUTES = max(0, min(1440, (int)$SEC['lockMinutes']));
if (isset($SEC['superLockAttempts'])) $SUPER_LOCK_ATTEMPTS = max(0, min(20, (int)$SEC['superLockAttempts']));
if (isset($SEC['superLockMinutes'])) $SUPER_LOCK_MINUTES = max(0, min(1440, (int)$SEC['superLockMinutes']));
if (isset($SEC['superLockMail'])) $SUPER_LOCK_MAIL = (bool)$SEC['superLockMail'];
if (isset($SEC['resetMinutes'])) $RESET_MINUTES = max(5, min(1440, (int)$SEC['resetMinutes']));
if (isset($SEC['resetFor']) && in_array($SEC['resetFor'], ['all', 'super', 'none'], true)) $RESET_FOR = $SEC['resetFor'];
if (isset($SEC['mfaRequired']) && in_array($SEC['mfaRequired'], ['none', 'super', 'admin', 'all'], true)) $MFA_REQUIRED = $SEC['mfaRequired'];
if (isset($SEC['backupTimes']) && is_array($SEC['backupTimes'])) $AUTO_BACKUP_TIMES = array_values(array_filter($SEC['backupTimes'], function ($t) { return is_string($t) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t); }));
if (isset($SEC['backupDays']) && is_array($SEC['backupDays'])) $AUTO_BACKUP_DAYS = array_values(array_filter(array_map('intval', $SEC['backupDays']), function ($d) { return $d >= 1 && $d <= 7; }));
if (!empty($SEC['backupKeep'])) $AUTO_BACKUP_KEEP = max(2, min(500, (int)$SEC['backupKeep']));
if (!empty($SEC['sessionHours'])) $SESSION_IDLE = max(1, min(72, (int)$SEC['sessionHours'])) * 3600;
if (!empty($SEC['minPassword'])) $MIN_PASSWORD = max(8, min(64, (int)$SEC['minPassword']));
$MAINTENANCE = !empty($SEC['maintenance']);

/* --- session : cookie de navigateur (effacé à la fermeture), HttpOnly, SameSite=Strict --- */
$SESS_DIR = "$DATA_DIR/sessions";
if (is_dir($SESS_DIR) && is_writable($SESS_DIR)) session_save_path($SESS_DIR);
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.gc_maxlifetime', (string)($SESSION_IDLE + 600));
ini_set('session.gc_probability', '1');
ini_set('session.gc_divisor', '100');
$https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
      || ($viaProxy && strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
$COOKIE_PATH = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/') . '/';
session_name('D8SERVICECLIENTS');
session_set_cookie_params(['lifetime' => 0, 'path' => $COOKIE_PATH, 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
// le formulaire client en ligne n'utilise pas de session (aucun cookie déposé chez le client)
if (!in_array($action, $WEB_ACTIONS, true) && !@session_start()) out(500, ['error' => 'Sessions PHP indisponibles (dossier data/sessions non accessible en écriture ?)']);

/* =============================================================================
   Personnes, rôles, droits
   ========================================================================== */
/** fiches et rôles ; créés au premier lancement avec les personnes et les rôles de départ */
function read_users(): array {
    global $USERS;
    $d = read_json($USERS);
    if (isset($d['users'], $d['roles'])) return $d;
    $now = date('c'); $users = [];
    foreach (SEED_USERS as $i => [$login, $role]) $users[] = ['id' => 'u' . ($i + 1), 'login' => $login, 'name' => SEED_NAMES[$login] ?? name_from_login($login), 'roleId' => $role,
        'active' => true, 'sigFonction' => '', 'sigTel' => '', 'created' => $now];
    $d = ['users' => $users, 'roles' => SEED_ROLES, 'version' => 1, 'seeded' => $now];
    write_json($USERS, $d);
    return $d;
}
function find_user(array $doc, string $id): ?array {
    foreach ($doc['users'] as $u) if ((string)($u['id'] ?? '') === $id) return $u;
    return null;
}
function find_user_login(array $doc, string $login): ?array {
    foreach ($doc['users'] as $u) if (clean_login($u['login'] ?? '') === $login) return $u;
    return null;
}
function find_role(array $doc, $id): ?array {
    foreach ($doc['roles'] as $r) if (($r['id'] ?? null) === $id) return $r;
    return null;
}
function role_perms(?array $r): array {
    if (!$r) return [];
    $p = array_values(array_intersect(ALL_PERMS, array_filter((array)($r['perms'] ?? []), 'is_string')));
    return in_array('super', $p, true) ? ALL_PERMS : $p;
}
function eff_perms(array $doc, string $uid): array {
    $u = find_user($doc, $uid);
    if (!$u || ($u['active'] ?? true) === false) return [];
    return role_perms(find_role($doc, $u['roleId'] ?? null));
}
function has_perm(array $doc, string $uid, string $p): bool { return in_array($p, eff_perms($doc, $uid), true); }
function has_any(array $doc, string $uid, array $ps): bool { foreach ($ps as $p) if (has_perm($doc, $uid, $p)) return true; return false; }
function is_super_role(?array $r): bool { return $r !== null && in_array('super', (array)($r['perms'] ?? []), true); }
function active_supers(array $doc): int {
    $n = 0; foreach ($doc['users'] as $u) if (($u['active'] ?? true) !== false && is_super_role(find_role($doc, $u['roleId'] ?? null))) $n++;
    return $n;
}
/** la personne visée a-t-elle des droits que l'administrateur n'a pas ? (seul un super administrateur peut alors agir sur elle) */
function outranks(array $doc, string $meId, string $targetId): bool {
    if (has_perm($doc, $meId, 'super')) return false;
    $u = find_user($doc, $targetId);
    return $u !== null && (bool)array_diff(role_perms(find_role($doc, $u['roleId'] ?? null)), eff_perms($doc, $meId));
}
function public_user(array $u): array {
    return ['id' => (string)$u['id'], 'login' => (string)$u['login'], 'name' => (string)$u['name'], 'roleId' => (string)($u['roleId'] ?? ''),
            'active' => ($u['active'] ?? true) !== false, 'sigFonction' => (string)($u['sigFonction'] ?? ''), 'sigTel' => (string)($u['sigTel'] ?? '')];
}

/* --- journal des connexions et de l'administration (data/auth-log.json, 2 000 derniers événements) --- */
function auth_log(string $ev, string $login = '', string $info = ''): void {
    global $DATA_DIR;
    $h = @fopen("$DATA_DIR/auth-log.json", 'c+'); if (!$h) return;
    flock($h, LOCK_EX);
    $all = json_decode((string)stream_get_contents($h), true); if (!is_array($all)) $all = [];
    array_unshift($all, ['t' => date('c'), 'ev' => $ev, 'login' => $login, 'ip' => (string)($GLOBALS['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? '')), 'info' => cut($info, 300)]);
    ftruncate($h, 0); rewind($h); fwrite($h, json_encode(array_slice($all, 0, 2000), JSON_UNESCAPED_UNICODE)); fflush($h);
    flock($h, LOCK_UN); fclose($h);
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
    $h = @fopen("$F.lock", 'c'); if ($h) flock($h, LOCK_EX);
    $all = array_filter(read_json($F), function ($e) use ($win) { return time() - (int)($e['t'] ?? 0) < $win; });
    $all[$ip] = ['n' => (int)($all[$ip]['n'] ?? 0) + 1, 't' => time()];
    write_json($F, $all);
    if ($h) { flock($h, LOCK_UN); fclose($h); }
}
function throttle_clear(string $F, string $ip): void {
    $h = @fopen("$F.lock", 'c'); if ($h) flock($h, LOCK_EX);
    $all = read_json($F);
    if (isset($all[$ip])) { unset($all[$ip]); write_json($F, $all); }
    if ($h) { flock($h, LOCK_UN); fclose($h); }
}

/** utilisateur connecté, ou null (session absente, expirée, accès réinitialisé ou mot de passe changé ailleurs) */
function auth_user(string $ACC, int $idle, $lock, bool $activity = true): ?array {
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
    if ($activity) $_SESSION['auth']['seen'] = time();
    return $_SESSION['auth'];
}
function open_session(string $login, array $a, string $name): array {
    session_regenerate_id(true);                // nouvel identifiant de session à chaque connexion
    $_SESSION = [];                             // rien de la session précédente (confirmation super administrateur…) ne survit
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
    $lp = mb_strtolower($p, 'UTF-8');
    if ($lp === $login || $lp === (string)strtok($login, '@')) return 'Le mot de passe doit être différent de l’identifiant.';
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
    global $RESET_FOR;
    $u = (int)($e['until'] ?? 0); $n = 'Compte bloqué après ' . (int)$e['n'] . ' mots de passe erronés';
    $mailUnlock = mail_ready() && ($RESET_FOR === 'all' || ($RESET_FOR === 'super' && $super));
    $tail = $mailUnlock ? ' Pour le débloquer, cliquez sur « Mot de passe oublié ? » : un lien pour choisir un nouveau mot de passe est envoyé à votre adresse e-mail.' : '';
    if ($u >= PHP_INT_MAX - 1) return $n . ($mailUnlock ? '.' . $tail : ' : un administrateur doit le débloquer.');
    return $n . ', jusqu’à ' . date('H:i', $u) . '.' . ($tail ?: ' Un administrateur peut le débloquer avant.');
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

/* =============================================================================
   Réglages partagés, dossiers
   ========================================================================== */
function read_settings(): array {
    global $SETF;
    $s = read_json($SETF);
    $out = [];
    foreach (SETTINGS_DEFAULTS as $k => $d) $out[$k] = array_key_exists($k, $s) ? $s[$k] : $d;
    return $out;
}
function dossier_file(string $id): ?string {
    global $DOS;
    return preg_match('/^(CB|INT|REMB|CMD)-\d{6}-\d{3,5}$/', $id) ? "$DOS/$id.json" : null;
}
function read_dossier(string $id): ?array {
    $f = dossier_file($id);
    if (!$f || !is_file($f)) return null;
    $d = json_decode((string)file_get_contents($f), true);
    return is_array($d) && in_array($d['type'] ?? '', TYPES, true) ? $d : null;
}
function dossier_summary(array $d): array {
    $v = (array)($d['v'] ?? []);
    $client = $d['type'] === 'cmd' ? trim((string)($v['societe'] ?? '') . ((string)($v['societe'] ?? '') !== '' && (string)($v['nom'] ?? '') !== '' ? ' – ' : '') . (string)($v['nom'] ?? '')) : (string)($v['nom'] ?? '');
    return ['id' => (string)$d['id'], 'type' => (string)$d['type'], 'rev' => (int)($d['rev'] ?? 1), 'client' => cut($client, 120), 'origin' => (string)($d['origin'] ?? ''),
            'site' => cut((string)($v['site'] ?? $v['liv_adresse'] ?? ''), 120), 'email' => cut((string)($v['email'] ?? ''), 120), 'matricule' => (string)($v['matricule'] ?? ''),
            'lastScen' => (string)($d['lastScen'] ?? ''), 'lastAt' => $d['lastAt'] ?? null, 'closed' => !empty($d['closed']),
            'createdAt' => $d['createdAt'] ?? null, 'createdBy' => (string)($d['createdBy'] ?? ''), 'updatedAt' => $d['updatedAt'] ?? null, 'updatedBy' => (string)($d['updatedBy'] ?? '')];
}
/** contenu d'un dossier envoyé par la page : uniquement des valeurs simples, tailles bornées, numéros de carte masqués */
function clean_values(array $in): array {
    $v = []; $n = 0;
    foreach ($in as $k => $x) {
        if (!is_string($k) || !preg_match('/^[a-z][a-z0-9_]{0,40}$/', $k) || ++$n > 150) continue;
        if (is_bool($x)) $v[$k] = $x;
        elseif (is_int($x) || is_float($x)) $v[$k] = (string)$x;
        elseif (is_string($x)) $v[$k] = mask_pan(clean_text($x, 6000, true));
        elseif ($k === 'items' && is_array($x)) {
            $items = [];
            foreach (array_slice(array_values($x), 0, 60) as $it) {
                if (!is_array($it)) continue;
                $row = [];
                foreach (['ref', 'des', 'qte', 'pu', 'tva'] as $c) $row[$c] = mask_pan(clean_text($it[$c] ?? '', 300));
                $items[] = $row;
            }
            $v[$k] = $items;
        } elseif (is_array($x) && array_values($x) === $x) {
            $v[$k] = array_values(array_map(function ($s) { return clean_text($s, 60); }, array_filter(array_slice($x, 0, 40), 'is_string')));
        }
    }
    return $v;
}
/** prochain numéro de dossier : CODE-AAMMJJ-NNN (sous le verrou exclusif) */
function next_dossier_id(string $type): string {
    global $SEQ;
    $prefix = TYPE_CODES[$type] . '-' . date('ymd');
    $seq = read_json($SEQ);
    $n = (int)($seq[$prefix] ?? 0);
    do { $n++; $id = sprintf('%s-%03d', $prefix, $n); } while (is_file((string)dossier_file($id)));
    $seq = array_filter($seq, function ($k) { return substr((string)$k, -6) >= date('ymd', strtotime('-3 days')); }, ARRAY_FILTER_USE_KEY);
    $seq[$prefix] = $n;
    write_json($SEQ, $seq);
    return $id;
}
/** effacement des dossiers plus anciens que la durée de conservation (une fois par jour) */
function purge_tick(bool $force = false): ?int {
    global $DATA_DIR, $DOS;
    $months = (int)(read_settings()['conservation'] ?? 0);
    if ($months <= 0) return null;
    $state = "$DATA_DIR/purge-state.json";
    if (!$force && (read_json($state)['day'] ?? '') === date('Y-m-d')) return null;
    $pl = @fopen("$DATA_DIR/purge.lock", 'c');
    if (!$pl || !flock($pl, LOCK_EX | LOCK_NB)) return null;
    $limit = strtotime("-$months months");
    $n = 0;
    foreach (glob("$DOS/*.json") ?: [] as $f) {
        $d = json_decode((string)file_get_contents($f), true);
        $t = is_array($d) ? strtotime((string)($d['updatedAt'] ?? '')) : false;
        if ($t !== false && $t < $limit && @unlink($f)) $n++;
    }
    write_json($state, ['day' => date('Y-m-d'), 'removed' => $n, 'limit' => date('c', $limit)]);
    if ($n) auth_log('purge', '', "$n dossier(s) de plus de $months mois effacé(s) (durée de conservation)");
    flock($pl, LOCK_UN); fclose($pl);
    return $n;
}

/* --- sauvegarde complète : personnes, accès, réglages, dossiers, dans un seul fichier JSON --- */
function full_backup(string $why): ?string {
    global $DATA_DIR, $USERS, $ACC, $SECF, $INV, $SETF, $SAF, $DOS, $AUTO_BACKUP_KEEP;
    $bdir = "$DATA_DIR/backups";
    if (!is_dir($bdir)) @mkdir($bdir, 0770, true);
    $dossiers = [];
    foreach (glob("$DOS/*.json") ?: [] as $f) { $d = json_decode((string)file_get_contents($f), true); if (is_array($d)) $dossiers[] = $d; }
    $all = ['kind' => 'd8-service-clients', 'format' => 1, 'created' => date('c'), 'reason' => $why,
            'users' => read_users(), 'accounts' => read_json($ACC), 'security' => read_json($SECF), 'settings' => read_json($SETF),
            'invites' => read_json($INV), 'superadmin' => is_file($SAF) ? read_json($SAF) : null, 'dossiers' => $dossiers];
    $name = sprintf('complet-%s-%s.json', date('Ymd-His'), preg_replace('/[^a-z0-9-]/', '', $why));
    if (@file_put_contents("$bdir/$name.tmp", json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false || !@rename("$bdir/$name.tmp", "$bdir/$name")) return null;
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
/** sauvegarde programmée due ? Déclenchée par les pages ouvertes ou par la tâche planifiée (?a=cron) */
function auto_backup_tick(): ?string {
    global $DATA_DIR, $AUTO_BACKUP_TIMES, $ACC;
    if (!$AUTO_BACKUP_TIMES || !read_json($ACC)) return null;      // rien à sauvegarder avant la mise en service
    $state = "$DATA_DIR/backup-state.json";
    [$slot] = backup_slots();
    if (!$slot || (int)(read_json($state)['slot'] ?? 0) >= $slot) return null;
    $bl = @fopen("$DATA_DIR/backup.lock", 'c');
    if (!$bl || !flock($bl, LOCK_EX | LOCK_NB)) return null;         // une autre requête s'en occupe déjà
    $name = null;
    if ((int)(read_json($state)['slot'] ?? 0) < $slot) {
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
            'fromName' => (string)($SEC['mailFromName'] ?? 'Service clients D8'), 'appUrl' => (string)($SEC['appUrl'] ?? '')];
}
function mail_ready(): bool { $c = mail_cfg(); return filter_var($c['from'], FILTER_VALIDATE_EMAIL) !== false && $c['appUrl'] !== ''; }
/** envoie un e-mail texte ; renvoie null si tout va bien, sinon le motif de l'échec */
function send_mail(string $to, string $subject, string $body, string $replyTo = ''): ?string {
    $c = mail_cfg();
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return 'Adresse du destinataire invalide.';
    if (!filter_var($c['from'], FILTER_VALIDATE_EMAIL)) return 'Adresse d’expédition non réglée (Administration › Sécurité & accès › Envoi des e-mails).';
    $enc = function (string $s): string { return '=?UTF-8?B?' . base64_encode(str_replace(["\r", "\n"], ' ', $s)) . '?='; };
    $domain = substr((string)strrchr($c['from'], '@'), 1);
    $headers = ['Date: ' . date('r'), 'From: ' . $enc($c['fromName']) . ' <' . $c['from'] . '>', 'To: <' . $to . '>', 'Subject: ' . $enc($subject),
                'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>', 'MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: base64', 'Auto-Submitted: auto-generated'];
    if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $headers[] = 'Reply-To: <' . $replyTo . '>';
    $data = chunk_split(base64_encode($body));
    if ($c['host'] === '') {                       // pas de serveur SMTP réglé : mail() de PHP (SMTP du php.ini)
        $h = array_values(array_filter($headers, function ($x) { return stripos($x, 'To:') !== 0 && stripos($x, 'Subject:') !== 0; }));
        return @mail($to, $enc($subject), $data, implode("\r\n", $h), '-f' . $c['from']) ? null : 'La fonction mail() de PHP a échoué : réglez un serveur SMTP.';
    }
    $port = $c['port'] ?: ($c['secure'] === 'ssl' ? 465 : ($c['secure'] === 'tls' ? 587 : 25));
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $c['host']]]);
    $fp = @stream_socket_client(($c['secure'] === 'ssl' ? 'ssl://' : 'tcp://') . $c['host'] . ':' . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) return "Connexion impossible au serveur {$c['host']}:$port ($errstr)." . ($port === 25 ? ' Le port 25 sortant est souvent bloqué (pare-feu, fournisseur d’accès) : essayez STARTTLS sur le port 587.' : ' Vérifiez le nom du serveur, le port et le pare-feu.');
    stream_set_timeout($fp, 20);
    $read = function () use ($fp): string { $r = ''; while (($l = fgets($fp, 1024)) !== false) { $r .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; } return $r; };
    $cmd = function (?string $line, array $ok) use ($fp, $read): string {
        if ($line !== null) fwrite($fp, $line . "\r\n");
        $r = $read();
        if (!in_array((int)substr($r, 0, 3), $ok, true)) throw new RuntimeException(trim($r) !== '' ? trim((string)preg_replace('/\s+/', ' ', $r)) : 'pas de réponse');
        return $r;
    };
    try {
        $cmd(null, [220]);
        $ehlo = 'EHLO ' . preg_replace('/[^A-Za-z0-9.-]/', '', (string)(gethostname() ?: 'service-clients'));
        $caps = $cmd($ehlo, [250]);
        // STARTTLS : exigé en mode « STARTTLS » ; en mode « aucun », utilisé quand même si le serveur le propose
        $opportunistic = $c['secure'] === 'none' && stripos($caps, 'STARTTLS') !== false;
        if ($c['secure'] === 'tls' || $opportunistic) {
            if ($c['secure'] === 'tls' && stripos($caps, 'STARTTLS') === false) throw new RuntimeException('le serveur ne propose pas STARTTLS (essayez « SSL/TLS » port 465, ou « Aucun » pour un relais interne)');
            $cmd('STARTTLS', [220]);
            if ($opportunistic) { stream_context_set_option($fp, 'ssl', 'verify_peer', false); stream_context_set_option($fp, 'ssl', 'verify_peer_name', false); }
            $m = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) $m |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) $m |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            if (!@stream_socket_enable_crypto($fp, true, $m)) throw new RuntimeException('chiffrement STARTTLS refusé (certificat du serveur non reconnu par PHP ?)');
            $caps = $cmd($ehlo, [250]);
        }
        if ($c['user'] !== '') {
            if (!preg_match('/^250[ -]AUTH[ =]/mi', $caps)) throw new RuntimeException('le serveur ne propose pas d’authentification sur cette connexion (choisissez STARTTLS, ou videz « Compte SMTP » pour un relais sans compte)');
            $cmd('AUTH LOGIN', [334]); $cmd(base64_encode($c['user']), [334]); $cmd(base64_encode($c['pass']), [235]);
        }
        $cmd('MAIL FROM:<' . $c['from'] . '>', [250]);
        $cmd('RCPT TO:<' . $to . '>', [250, 251]);
        $cmd('DATA', [354]);
        $cmd(implode("\r\n", $headers) . "\r\n\r\n" . $data . "\r\n.", [250]);
        try { $cmd('QUIT', [221]); } catch (Throwable $e) { }
        fclose($fp);
        return null;
    } catch (Throwable $e) {
        @fclose($fp);
        $hint = smtp_hint($e->getMessage(), $c);
        return 'Serveur de messagerie : ' . $e->getMessage() . ($hint ? "\n→ " . $hint : '');
    }
}
/** explication en français des refus courants (Microsoft 365, Exchange, relais) */
function smtp_hint(string $r, array $c): string {
    $m365 = stripos($c['host'], 'office365') !== false || stripos($c['host'], 'outlook') !== false;
    if (preg_match('/STARTTLS is required|must issue a STARTTLS|5\.7\.0 must issue/i', $r)) return 'Ce serveur exige le chiffrement : choisissez « STARTTLS (port 587) »' . ($m365 ? ' et renseignez le compte et le mot de passe de la boîte d’envoi (bouton « Microsoft 365 »).' : '.');
    if (preg_match('/5\.7\.139|SmtpClientAuthentication is disabled|basic authentication is disabled/i', $r)) return 'L’envoi par compte (« SMTP authentifié ») est désactivé pour cette boîte ou pour l’entreprise : l’administrateur Microsoft 365 doit l’activer (Centre d’administration › Utilisateurs › la boîte d’envoi › Courrier › Gérer les applications de messagerie › SMTP authentifié), ou utilisez le relais sans compte (bouton « Relais Microsoft 365 »).';
    if (preg_match('/5\.7\.57|not authenticated|authentication required/i', $r)) return 'Ce serveur exige un compte : renseignez « Compte SMTP » et « Mot de passe SMTP » (la boîte d’envoi)' . ($m365 ? ', ou utilisez le relais sans compte (bouton « Relais Microsoft 365 »).' : '.');
    if (preg_match('/^535|5\.7\.3 Authentication unsuccessful|authentication (unsuccessful|failed)/mi', $r)) return 'Compte ou mot de passe SMTP refusé. Si la boîte est protégée par l’authentification multifacteur, utilisez un mot de passe d’application, ou le relais sans compte.';
    if (preg_match('/5\.7\.60|SendAsDenied|not allowed to send as/i', $r)) return 'L’adresse d’expédition doit être celle du compte SMTP (ou ce compte doit avoir le droit « Envoyer en tant que » sur l’adresse d’expédition).';
    if (preg_match('/unable to relay|relay (access )?denied|5\.7\.64|TenantAttribution|5\.7\.606/i', $r)) return 'Relais refusé : sans compte, Microsoft 365 n’accepte que des destinataires de votre domaine, et l’adresse IP publique du serveur doit être autorisée (enregistrement SPF ou connecteur).';
    if (preg_match('/certifica/i', $r)) return 'Le certificat du serveur n’est pas reconnu par PHP : vérifiez le nom du serveur, ou installez le magasin de certificats (openssl.cafile dans php.ini).';
    return '';
}
/** fiche et adresse d'une personne pouvant recevoir un lien « mot de passe oublié », ou null */
function reset_target(array $doc, array $acc, string $login, bool $superOnly): ?array {
    $a = $acc[$login] ?? null;
    if (!is_array($a)) return null;
    $uid = (string)$a['userId'];
    $u = find_user($doc, $uid);
    if (!$u || ($u['active'] ?? true) === false) return null;
    $sup = has_perm($doc, $uid, 'super');
    if ($superOnly && !$sup) return null;
    return ['userId' => $uid, 'name' => (string)$u['name'], 'email' => (string)$u['login'], 'super' => $sup];
}
function super_target(array $doc, array $acc, string $login): ?array { return reset_target($doc, $acc, $login, true); }
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
/* --- double authentification (TOTP, RFC 6238) : Microsoft Authenticator, Google Authenticator… --- */
function b32_encode(string $bin): string {
    $al = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $out = '';
    foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    foreach (str_split($bits, 5) as $chunk) $out .= $al[bindec(str_pad($chunk, 5, '0'))];
    return $out;
}
function b32_decode(string $s): string {
    $al = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $out = '';
    foreach (str_split(strtoupper((string)preg_replace('/[^A-Za-z2-7]/', '', $s))) as $c) $bits .= str_pad(decbin((int)strpos($al, $c)), 5, '0', STR_PAD_LEFT);
    foreach (str_split($bits, 8) as $b) if (strlen($b) === 8) $out .= chr(bindec($b));
    return $out;
}
function totp_at(string $key, int $step): string {
    $h = hash_hmac('sha1', pack('N2', 0, $step), $key, true);
    $o = ord($h[19]) & 0xf;
    $v = ((ord($h[$o]) & 0x7f) << 24 | ord($h[$o + 1]) << 16 | ord($h[$o + 2]) << 8 | ord($h[$o + 3])) % 1000000;
    return str_pad((string)$v, 6, '0', STR_PAD_LEFT);
}
/** pas de temps accepté pour ce code (± 30 s de décalage d'horloge toléré), ou 0 ; refuse un code déjà utilisé */
function totp_check(string $secret, string $code, int $last = 0): int {
    if (!preg_match('/^\d{6}$/', $code)) return 0;
    $key = b32_decode($secret); $now = intdiv(time(), 30);
    for ($d = -1; $d <= 1; $d++) { $st = $now + $d; if ($st > $last && hash_equals(totp_at($key, $st), $code)) return $st; }
    return 0;
}
function otp_uri(string $login, string $secret): string {
    $iss = 'Service clients D8';
    return 'otpauth://totp/' . rawurlencode($iss) . ':' . rawurlencode($login) . '?secret=' . $secret . '&issuer=' . rawurlencode($iss) . '&algorithm=SHA1&digits=6&period=30';
}
/** 10 codes de secours à usage unique : on renvoie les codes en clair (affichés une fois) et on garde leurs empreintes */
function recovery_codes(): array {
    $al = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; $codes = []; $hashes = [];
    for ($i = 0; $i < 10; $i++) {
        $s = ''; for ($j = 0; $j < 8; $j++) $s .= $al[random_int(0, strlen($al) - 1)];
        $codes[] = substr($s, 0, 4) . '-' . substr($s, 4); $hashes[] = hash('sha256', $s);
    }
    return [$codes, $hashes];
}
/** la double authentification est-elle exigée pour cette personne ? */
function mfa_required(array $doc, string $uid): bool {
    global $MFA_REQUIRED;
    if ($MFA_REQUIRED === 'all') return true;
    if ($MFA_REQUIRED === 'admin') return has_any($doc, $uid, ['users', 'security', 'settings', 'super']);
    if ($MFA_REQUIRED === 'super') return has_perm($doc, $uid, 'super');
    return false;
}
/** fin de connexion (mot de passe vérifié) : demande du code si la double authentification est active ou exigée, sinon session ouverte */
function finish_login(string $login, array $acc, string $name, bool $weak, array $doc, string $ev = ''): array {
    $a = $acc[$login];
    $on = !empty($a['mfa']['on']);
    if (!$on && !mfa_required($doc, (string)$a['userId'])) {
        if ($ev !== '') auth_log($ev, $login, $name);
        $s = open_session($login, $a, $name);
        if ($weak) { $_SESSION['auth']['weak'] = true; $s = $_SESSION['auth']; }
        return me_payload($s, $doc);
    }
    session_regenerate_id(true);
    $_SESSION = [];
    $p = ['login' => $login, 'name' => $name, 'weak' => $weak, 't' => time(), 'n' => 0];
    if ($on) { $_SESSION['mfa_pending'] = $p; auth_log('mfa-ask', $login, $name); return ['auth' => false, 'mfa' => 'code', 'name' => $name]; }
    $p['enroll'] = b32_encode(random_bytes(20));        // exigée mais pas encore activée : mise en place à cette connexion
    $_SESSION['mfa_pending'] = $p;
    auth_log('mfa-ask', $login, $name . ' (mise en place obligatoire)');
    return ['auth' => false, 'mfa' => 'enroll', 'name' => $name, 'login' => $login, 'secret' => $p['enroll'], 'uri' => otp_uri($login, $p['enroll'])];
}
function superadmin_hash(): string {
    global $SAF, $SUPERADMIN_HASH;
    $h = read_json($SAF)['hash'] ?? '';
    return is_string($h) && $h !== '' ? $h : (string)$SUPERADMIN_HASH;
}
function me_payload(array $s, array $doc): array {
    global $MAINTENANCE;
    $u = find_user($doc, (string)$s['userId']) ?? [];
    $r = find_role($doc, $u['roleId'] ?? null);
    return ['auth' => true, 'userId' => $s['userId'], 'login' => $s['login'], 'name' => (string)($u['name'] ?? $s['name']),
            'perms' => eff_perms($doc, (string)$s['userId']), 'role' => (string)($r['name'] ?? ''),
            'sigFonction' => (string)($u['sigFonction'] ?? ''), 'sigTel' => (string)($u['sigTel'] ?? ''),
            'build' => page_build(), 'policy' => pw_policy(), 'mustChange' => !empty($s['weak']), 'maintenance' => $MAINTENANCE];
}


/* =============================================================================
   Formulaire client en ligne : schéma contrôlé par le serveur, liens, e-mails
   ========================================================================== */
/** champs du formulaire en ligne : [libellé, type, obligatoire (true | 'champ=valeur|valeur'), options ou longueur] */
const WEB_FORMS = [
    'remb' => [
        'title' => 'Demande de remboursement',
        'fields' => [
            'nom' => ['Nom et prénom', 'text', true, 80],
            'site' => ['Entreprise / Site', 'text', true, 120],
            'email' => ['E-mail', 'email', true, 120],
            'tel' => ['Téléphone', 'tel', false, 30],
            'matricule' => ['Matricule du distributeur', 'matricule', true, 6],
            'emplacement' => ['Emplacement précis', 'text', true, 120],
            'tx_date' => ['Date de la transaction', 'date', true, 0],
            'tx_heure' => ['Heure approximative', 'time', true, 0],
            'montant' => ['Montant débité', 'money', true, 0],
            'produit' => ['Produit sélectionné', 'text', false, 80],
            'paiement' => ['Moyen de paiement', 'radio', true, ['cb' => 'Carte bancaire', 'mobile' => 'Téléphone ou montre connectée', 'badge' => 'Badge / carte d’accès', 'especes' => 'Espèces', 'autre' => 'Autre']],
            'paiement_autre' => ['Autre moyen de paiement', 'text', 'paiement=autre', 60],
            'last4' => ['4 derniers chiffres de la carte', 'last4', 'paiement=cb|mobile', 4],
            'incident' => ['Nature de l’incident', 'radio', true, ['non_delivre' => 'Produit non délivré', 'sans_gobelet' => 'Boisson servie sans gobelet', 'bloque' => 'Distributeur bloqué', 'double' => 'Double débit', 'monnaie' => 'Monnaie non rendue', 'autre' => 'Autre']],
            'description' => ['Description', 'textarea', 'incident=autre', 2000],
            'rb_mode' => ['Mode de remboursement souhaité', 'radio', true, ['especes' => 'En espèces, à l’accueil ou auprès de votre référent site', 'compte' => 'Sur le compte utilisé pour le paiement', 'virement' => 'Par virement bancaire', 'appli' => 'Sur votre application Pay4Vend ou Matipay']],
            'attestation' => ['Attestation sur l’honneur', 'check', true, 0],
        ],
    ],
];
const WEB_SETTING_OF = ['remb' => 'webRemb'];
function web_enabled(string $type): bool { $s = read_settings(); return isset(WEB_FORMS[$type], WEB_SETTING_OF[$type]) && !empty($s[WEB_SETTING_OF[$type]]); }
function web_req_on($rule, array $v): bool {
    if ($rule === true) return true;
    if (!is_string($rule) || strpos($rule, '=') === false) return false;
    [$k, $vals] = explode('=', $rule, 2);
    return in_array((string)($v[$k] ?? ''), explode('|', $vals), true);
}
/** contrôle complet d'une demande en ligne ; renvoie [valeurs propres, erreurs par champ, numéro de carte masqué ?] */
function web_validate(string $type, array $in): array {
    $v = []; $err = []; $pan = false;
    foreach (WEB_FORMS[$type]['fields'] as $k => [$label, $kind, $req, $opt]) {
        $raw = $in[$k] ?? '';
        if ($kind === 'check') { $v[$k] = !empty($raw); continue; }
        if (is_array($raw) || is_object($raw)) $raw = '';
        $x = clean_text((string)$raw, $kind === 'textarea' ? 2000 : 200, $kind === 'textarea');
        switch ($kind) {
            case 'email':
                $x = strtolower($x);
                if ($x !== '' && (strpos($x, '@') === false || !filter_var($x, FILTER_VALIDATE_EMAIL))) $err[$k] = 'Adresse e-mail invalide : elle doit contenir @ et un domaine (ex. prenom.nom@entreprise.fr).';
                break;
            case 'matricule':
                $x = preg_replace('/\D/', '', $x);
                if ($x !== '' && strlen($x) !== 6) $err[$k] = 'Le matricule comporte 6 chiffres.';
                break;
            case 'last4':
                $d = preg_replace('/\D/', '', $x);
                if (strlen($d) >= 12) { $pan = true; $d = substr($d, -4); }
                $x = $d;
                if ($x !== '' && strlen($x) !== 4) $err[$k] = 'Indiquez exactement les 4 derniers chiffres.';
                break;
            case 'date':
                if ($x !== '') {
                    $t = DateTime::createFromFormat('!Y-m-d', $x);
                    if (!$t || $t->format('Y-m-d') !== $x) $err[$k] = 'Date invalide.';
                    elseif ($t->getTimestamp() > time()) $err[$k] = 'La date ne peut pas être dans le futur.';
                    elseif ($t->getTimestamp() < strtotime('-2 years')) $err[$k] = 'Date trop ancienne.';
                }
                break;
            case 'time':
                if ($x !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $x)) $err[$k] = 'Heure invalide (HH:MM).';
                break;
            case 'money':
                $n = str_replace([' ', "\u{00A0}", "\u{202F}", '€'], '', $x); $n = str_replace(',', '.', $n);
                if ($x !== '' && (!preg_match('/^\d{1,4}(\.\d{1,2})?$/', $n) || (float)$n <= 0)) $err[$k] = 'Montant invalide (ex. 2,50).';
                else $x = $x === '' ? '' : str_replace('.', ',', $n);
                break;
            case 'radio':
                if ($x !== '' && !array_key_exists($x, $opt)) { $err[$k] = 'Choix invalide.'; $x = ''; }
                break;
            case 'tel':
                $x = preg_replace('/[^\d +().-]/', '', $x);
                if (strlen((string)preg_replace('/\D/', '', $x)) >= 13) { $m = mask_pan($x); if ($m !== $x) { $pan = true; $x = $m; } }
                break;
            default:
                $x = mask_pan($x); if ($x !== clean_text((string)$raw, $kind === 'textarea' ? 2000 : 200, $kind === 'textarea')) $pan = true;
        }
        if (is_int($opt) && $opt > 0 && in_array($kind, ['text', 'textarea'], true)) $x = cut($x, $opt);
        $v[$k] = $x;
    }
    if (array_key_exists('last4', $v) && !in_array($v['paiement'] ?? '', ['cb', 'mobile'], true)) { $v['last4'] = ''; unset($err['last4']); }
    foreach (WEB_FORMS[$type]['fields'] as $k => [$label, $kind, $req]) {
        if (isset($err[$k]) || !web_req_on($req, $v)) continue;
        if ($kind === 'check' ? !$v[$k] : (string)$v[$k] === '') $err[$k] = $kind === 'check' ? 'Cette attestation est nécessaire pour traiter votre demande.' : 'Champ obligatoire.';
    }
    return [$v, $err, $pan];
}
function web_money($s): string { $s = (string)$s; $n = (float)str_replace(',', '.', $s); return number_format($n, 2, ',', "\u{00A0}") . "\u{00A0}€"; }
function web_date($iso): string { $iso = (string)$iso; return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m) ? "$m[3]/$m[2]/$m[1]" : $iso; }
function web_time($t): string { $t = (string)$t; return preg_match('/^(\d{2}):(\d{2})$/', $t, $m) ? (int)$m[1] . ' h ' . $m[2] : $t; }
function web_opt(string $type, string $k, string $val): string { $o = WEB_FORMS[$type]['fields'][$k][3] ?? []; return is_array($o) ? (string)($o[$val] ?? $val) : $val; }
/** texte saisi par le client, repris dans un e-mail qui lui est envoyé : sans adresse web */
function web_nolink($x): string { return (string)preg_replace('#\b(https?://|www\.)\S+|\b[\w-]+(\.[\w-]+)*\.(com|net|org|fr|io|ru|cn|xyz|top|info|biz|ly|me)\b(/\S*)?#i', '[lien retiré]', (string)$x); }
/** lignes « Libellé : valeur » du récapitulatif (le texte libre n'est repris que pour la Monétique ; sans liens pour le client) */
function web_recap(array $d, bool $full): array {
    $v = (array)$d['v']; $t = $d['type'];
    $carte = in_array($v['paiement'] ?? '', ['cb', 'mobile'], true) && preg_match('/^\d{4}$/', (string)($v['last4'] ?? ''));
    $rows = [
        ['Référence', $d['id']],
        ['Nom', $v['nom'] ?? ''], ['Entreprise / site', $v['site'] ?? ''], ['E-mail', $v['email'] ?? ''], ['Téléphone', $v['tel'] ?? ''],
        ['Distributeur', ($v['matricule'] ?? '') !== '' ? 'n° ' . $v['matricule'] . (($v['emplacement'] ?? '') !== '' ? ' – ' . $v['emplacement'] : '') : ''],
        ['Transaction', trim((($v['tx_date'] ?? '') !== '' ? 'le ' . web_date($v['tx_date']) : '') . (($v['tx_heure'] ?? '') !== '' ? ' vers ' . web_time($v['tx_heure']) : ''))],
        ['Montant débité', ($v['montant'] ?? '') !== '' ? web_money($v['montant']) : ''],
        ['Produit', $v['produit'] ?? ''],
        ['Moyen de paiement', ($v['paiement'] ?? '') === 'autre' ? (string)($v['paiement_autre'] ?? 'Autre') : web_opt($t, 'paiement', (string)($v['paiement'] ?? ''))
            . ($carte ? (($v['paiement'] ?? '') === 'mobile' ? ' (numéro de l’appareil se terminant par ' : ' (se terminant par ') . $v['last4'] . ')' : '')],
        ['Incident', web_opt($t, 'incident', (string)($v['incident'] ?? ''))],
        ['Remboursement souhaité', web_opt($t, 'rb_mode', (string)($v['rb_mode'] ?? ''))],
    ];
    if ($full) $rows[] = ['Description', (string)($v['description'] ?? '')];
    else { $rows = array_values(array_filter($rows, function ($r) { return !in_array($r[0], ['E-mail', 'Téléphone'], true); })); }
    $out = [];
    foreach ($rows as [$k, $x]) { $x = trim(preg_replace('/\s*\n\s*/', ' / ', (string)$x)); if ($x !== '') $out[] = "$k : " . ($full ? $x : web_nolink($x)); }
    return $out;
}
function web_signature(): string {
    $c = read_settings();
    return "Cordialement,\n\nLe service Monétique D8\n{$c['societe']}\n{$c['adresse']}\nTél. {$c['telephone']} · " . ($c['webNotify'] ?: $c['emailMonetique']);
}
/** accusé de réception envoyé au client (même contenu que la réponse « Accusé de réception » de l'outil) */
function web_ar(array $d): array {
    $c = read_settings(); $v = (array)$d['v'];
    $emp = ($v['matricule'] ?? '') !== '' ? 'le distributeur n° ' . web_nolink($v['matricule']) . (($v['emplacement'] ?? '') !== '' ? ' (' . web_nolink($v['emplacement']) . ')' : '') : 'le distributeur';
    $tx = trim((($v['tx_date'] ?? '') !== '' ? 'du ' . web_date($v['tx_date']) : '') . (($v['tx_heure'] ?? '') !== '' ? ' vers ' . web_time($v['tx_heure']) : '') . (($v['montant'] ?? '') !== '' ? ' (' . web_money($v['montant']) . ')' : ''));
    $p = ["Bonjour,", "Nous avons bien reçu votre demande de remboursement concernant $emp, et nous vous présentons nos excuses pour la gêne occasionnée.",
          "Votre dossier est enregistré sous la référence {$d['id']}. Nous recherchons à présent votre transaction $tx dans les relevés de paiement du distributeur, et nous vous répondrons sous {$c['delaiTraitement']} jours ouvrés."];
    $t = strtotime((string)($v['tx_date'] ?? ''));
    if ($t !== false && $t < strtotime('-' . (int)$c['delaiReclamation'] . ' days')) $p[] = "Votre demande porte sur une transaction de plus de {$c['delaiReclamation']} jours : nous vérifions qu'elle figure encore dans les relevés du distributeur, sans pouvoir vous le garantir.";
    $modes = ['especes' => "en espèces, à l'accueil ou auprès de votre référent site", 'compte' => 'sur le compte utilisé pour le paiement', 'virement' => 'par virement bancaire', 'appli' => 'sur votre application Pay4Vend ou Matipay'];
    $rb = (string)($v['rb_mode'] ?? '');
    if (isset($modes[$rb])) $p[] = 'Une fois la transaction retrouvée, le remboursement sera effectué ' . $modes[$rb] . '.' . ($rb === 'virement' ? ' Pour cela, merci de nous adresser votre RIB en réponse à ce message.' : '');
    if (in_array($v['paiement'] ?? '', ['cb', 'mobile'], true)) $p[] = 'Si vous avez déjà engagé une contestation auprès de votre banque pour ce paiement, merci de nous le signaler : le remboursement serait alors traité par votre banque, ce qui évite un double remboursement.';
    $p[] = "RÉCAPITULATIF DE VOTRE DEMANDE\n" . implode("\n", web_recap($d, false));
    $p[] = 'Pour votre sécurité, ne nous communiquez jamais votre numéro de carte complet ni le cryptogramme au dos : les 4 derniers chiffres suffisent.';
    $p[] = "Ceci est un accusé de réception automatique. Pour compléter votre demande, répondez simplement à ce message en rappelant la référence {$d['id']}.";
    $p[] = web_signature();
    return ['subject' => "Votre demande de remboursement – Réf. {$d['id']}", 'body' => implode("\n\n", $p) . "\n"];
}
/** les trois envois : demande → Monétique ; si elle est acceptée, accusé de réception → client ; puis confirmation → Monétique */
function web_mails(array &$d, bool $onlyMissing = false): void {
    global $ip;
    $c = read_settings(); $s = mail_cfg();
    $to = filter_var($c['webNotify'], FILTER_VALIDATE_EMAIL) ? $c['webNotify'] : $c['emailMonetique'];
    // lien personnel : l'accusé de réception part à l'adresse à laquelle le lien a été envoyé (pas à une adresse tapée dans le formulaire)
    $typed = (string)($d['v']['email'] ?? '');
    $client = (string)($d['web']['arTo'] ?? '') ?: $typed;
    $m = (array)($d['web']['mails'] ?? []);
    $now = date('c');
    if (!mail_ready()) { $d['web']['mails'] = $m + ['notify' => ['ok' => false, 't' => $now, 'err' => 'Envoi des e-mails non réglé (Administration › Sécurité & accès).']]; return; }
    if (!$onlyMissing || empty($m['notify']['ok'])) {
        $link = $s['appUrl'] !== '' ? "\n\nOuvrir le dossier dans l'outil Service clients :\n" . app_link('dossier', $d['id']) : '';
        $body = "Nouvelle demande de remboursement reçue par le formulaire en ligne, le " . date('d/m/Y à H:i', strtotime((string)($d['web']['submittedAt'] ?? $now))) . ".\n\n"
            . implode("\n", web_recap($d, true)) . $link . "\n\n"
            . (strcasecmp($typed, $client) !== 0 && $typed !== '' ? "Attention : le client a saisi l'adresse $typed, différente de celle à laquelle le lien lui a été envoyé ($client).\n\n" : '')
            . (!empty($c['webAR']) ? "Un accusé de réception va être envoyé automatiquement au client ($client) ; vous recevrez une confirmation de cet envoi." : "Accusé de réception automatique désactivé : répondez au client depuis l'outil.")
            . "\n\nRépondre à ce message écrit directement au client.\n";
        $err = send_mail($to, "[Formulaire en ligne] Demande de remboursement {$d['id']} – " . cut((string)($d['v']['nom'] ?? ''), 60), $body, $client);
        $m['notify'] = ['ok' => $err === null, 't' => $now, 'to' => $to, 'err' => $err];
        auth_log($err ? 'mail-fail' : 'web-notify', '', $d['id'] . ' → ' . $to . ($err ? ' : ' . $err : ''));
    }
    if (empty($m['notify']['ok']) || empty($c['webAR'])) { $d['web']['mails'] = $m; return; }
    if (!$onlyMissing || empty($m['ar']['ok'])) {
        $ar = web_ar($d);
        $err = send_mail($client, $ar['subject'], $ar['body'], $to);
        $m['ar'] = ['ok' => $err === null, 't' => $now, 'to' => $client, 'err' => $err];
        auth_log($err ? 'mail-fail' : 'web-ar', '', $d['id'] . ' → ' . $client . ($err ? ' : ' . $err : ''));
        $conf = $err === null
            ? ["[Formulaire en ligne] Accusé de réception envoyé – {$d['id']}", "L'accusé de réception de la demande {$d['id']} a été envoyé à $client le " . date('d/m/Y à H:i') . ".\n\nObjet : {$ar['subject']}\n\n----- Copie de l'accusé de réception -----\n\n{$ar['body']}"]
            : ["[Formulaire en ligne] ÉCHEC de l'accusé de réception – {$d['id']}", "L'accusé de réception de la demande {$d['id']} n'a pas pu être envoyé à $client.\n\nMotif : $err\n\nVérifiez l'adresse du client et répondez-lui depuis l'outil (dossier {$d['id']})."];
        $err2 = send_mail($to, $conf[0], $conf[1]);
        $m['confirm'] = ['ok' => $err2 === null, 't' => date('c'), 'to' => $to, 'err' => $err2];
    }
    $d['web']['mails'] = $m;
}
function web_link_base(): array {
    $c = read_settings(); $s = mail_cfg();
    if ($c['webPublicUrl'] !== '') return [$c['webPublicUrl'], true];
    $u = $s['appUrl'] !== '' ? preg_replace('#[^/]*$#', '', $s['appUrl']) : '';
    return [$u, false];
}
/** jeton anti-robots : horodatage signé (le formulaire doit avoir été affiché depuis au moins 3 secondes) */
function web_secret(): string {
    global $SECF, $SEC;
    if (empty($SEC['formSecret'])) { $SEC['formSecret'] = bin2hex(random_bytes(32)); write_json($SECF, $SEC); }
    return (string)$SEC['formSecret'];
}
function web_nonce(string $key): string { $t = (string)time(); return $t . '.' . hash_hmac('sha256', "$t|$key", web_secret()); }
function web_nonce_ok(string $n, string $key): bool {
    if (!preg_match('/^(\d{10})\.([a-f0-9]{64})$/', $n, $m)) return false;
    $age = time() - (int)$m[1];
    return $age >= 3 && $age <= 86400 && hash_equals(hash_hmac('sha256', "$m[1]|$key", web_secret()), $m[2]);
}
/** lien pour ce jeton, ou null (lecture seule : la purge se fait sous verrou, à la création d'un lien) */
function web_link_find(string $tok): ?array {
    global $WEBL;
    if (!preg_match('/^[a-f0-9]{32}$/', $tok)) return null;
    $x = read_json($WEBL)[hash('sha256', $tok)] ?? null;
    return is_array($x) ? $x + ['key' => hash('sha256', $tok)] : null;
}

/* =============================================================================
   Actions
   ========================================================================== */
$lock = fopen($LOCK, 'c');
if ($lock === false) out(500, ['error' => 'Verrou indisponible']);

/* --- sauvegardes programmées et durée de conservation : vérifiées à chaque échange courant ; tâche planifiée : ?a=cron&key=… --- */
if (in_array($action, ['ping', 'me', 'cron', 'dossiers'], true)) { auto_backup_tick(); purge_tick(); }
if ($action === 'cron') {
    session_write_close();
    throttle_check($FAILS, $ip, $MAX_FAILS, $FAIL_WINDOW);
    $k = (string)($SEC['cronKey'] ?? '');
    if (strlen($k) < 32 || !hash_equals($k, (string)($_GET['key'] ?? ''))) { throttle_fail($FAILS, $ip, $FAIL_WINDOW); out(403, ['error' => 'Clé invalide']); }
    [, $n] = backup_slots();
    out(200, ['ok' => true, 'last' => read_json("$DATA_DIR/backup-state.json"), 'next' => $n ? date('c', $n) : null, 'purge' => read_json("$DATA_DIR/purge-state.json")]);
}


/* =============================================================================
   Formulaire client en ligne (actions publiques, sans session)
   ========================================================================== */
if ($action === 'form-info' || $action === 'form-submit') {
    if (!$INTERNAL && !$https) out(403, ['error' => 'Connexion non sécurisée : ouvrez le formulaire avec une adresse https://.']);
    throttle_check($WEBT, $ip, $WEB_MAX_PER_IP * 3, 3600);
    $tok = strtolower((string)($action === 'form-info' ? ($_GET['t'] ?? '') : ''));
    $in = $action === 'form-submit' ? body_json(65536) : [];
    if ($action === 'form-submit') $tok = strtolower((string)($in['t'] ?? ''));
    $type = 'remb'; $link = null;
    if ($tok !== '') {
        $link = web_link_find($tok);
        if (!$link || (int)$link['exp'] < time() || !empty($link['used'])) {
            throttle_fail($WEBT, $ip, 3600);
            out(404, ['error' => $link && !empty($link['used']) ? 'Ce lien a déjà été utilisé : votre demande a bien été transmise. Pour une nouvelle demande, contactez-nous.' : 'Lien inconnu ou expiré : demandez un nouveau lien au service Monétique.']);
        }
        $type = (string)$link['type'];
    } else {
        $type = (string)($action === 'form-info' ? ($_GET['f'] ?? '') : ($in['f'] ?? ''));
        $type = $type === 'remboursement' ? 'remb' : $type;
        if (empty(read_settings()['webGeneric'])) out(403, ['error' => 'Ce formulaire s’ouvre avec le lien personnel reçu par e-mail. Contactez le service Monétique pour en recevoir un.']);
    }
    if (!isset(WEB_FORMS[$type]) || !web_enabled($type)) out(403, ['error' => 'Ce formulaire n’est pas disponible en ligne pour le moment. Écrivez-nous à ' . read_settings()['emailMonetique'] . '.']);
    $nkey = $link ? 'lien:' . $link['key'] : 'generique:' . $type;
    if ($action === 'form-info') {
        $c = read_settings();
        out(200, ['type' => $type, 'title' => WEB_FORMS[$type]['title'], 'nonce' => web_nonce($nkey), 'generic' => !$link,
                  'prefill' => $link ? ['email' => (string)($link['email'] ?? ''), 'nom' => (string)($link['nom'] ?? '')] : (object)[],
                  'expires' => $link ? date('c', (int)$link['exp']) : null,
                  'company' => ['societe' => $c['societe'], 'adresse' => $c['adresse'], 'telephone' => $c['telephone'], 'email' => $c['webNotify'] ?: $c['emailMonetique'],
                                'delaiReclamation' => $c['delaiReclamation'], 'delaiTraitement' => $c['delaiTraitement'], 'conservation' => $c['conservation'], 'logo' => $c['logo']]]);
    }
    // --- envoi de la demande
    if (!empty($in['site_web'])) { throttle_fail($WEBT, $ip, 3600); auth_log('web-bot', '', 'champ piège rempli'); out(200, ['ok' => true, 'ref' => '', 'ar' => false]); }
    if (!web_nonce_ok((string)($in['nonce'] ?? ''), $nkey)) { throttle_fail($WEBT, $ip, 3600); out(400, ['error' => 'Formulaire expiré : rechargez la page puis renvoyez votre demande.', 'reload' => true]); }
    throttle_check($WEBT, $ip, $WEB_MAX_PER_IP, 3600);
    [$v, $errs, $pan] = web_validate($type, (array)($in['v'] ?? []));
    if ($errs) out(400, ['error' => 'Certains champs sont à corriger.', 'fields' => $errs]);
    flock($lock, LOCK_EX);
    if ($link) {                                                          // lien à usage unique
        $all = read_json($WEBL);
        if (!empty($all[$link['key']]['used'])) { flock($lock, LOCK_UN); out(409, ['error' => 'Ce lien vient d’être utilisé : votre demande a déjà été transmise.']); }
    } else {
        $nF = "$DATA_DIR/web-nonces.json"; $nh = hash('sha256', (string)$in['nonce']);
        $used = array_filter(read_json($nF), function ($exp) { return (int)$exp > time(); });
        if (isset($used[$nh])) { flock($lock, LOCK_UN); throttle_fail($WEBT, $ip, 3600); out(400, ['error' => 'Formulaire déjà envoyé : rechargez la page pour une nouvelle demande.', 'reload' => true]); }
        $used[$nh] = time() + 86400; write_json($nF, $used);
        $dayF = "$DATA_DIR/web-daily.json"; $day = read_json($dayF);
        if (($day['day'] ?? '') !== date('Y-m-d')) $day = ['day' => date('Y-m-d'), 'n' => 0];
        if ((int)$day['n'] >= $WEB_MAX_PER_DAY) { flock($lock, LOCK_UN); auth_log('web-cap', '', 'limite quotidienne du lien générique atteinte'); out(429, ['error' => 'Trop de demandes aujourd’hui : réessayez demain ou écrivez-nous.']); }
        $day['n'] = (int)$day['n'] + 1; write_json($dayF, $day);
    }
    $now = date('c');
    $existing = $link && !empty($link['dossierId']) ? read_dossier((string)$link['dossierId']) : null;
    if ($existing && $existing['type'] === $type) {
        $d = $existing;
        foreach ($v as $k => $x) if ($x !== '' && $x !== false) $d['v'][$k] = $x;
        $d['rev'] = (int)$d['rev'] + 1;
        $d['history'][] = ['t' => $now, 'by' => 'Client (formulaire en ligne)', 'ev' => 'web', 'info' => 'demande complétée en ligne'];
    } else {
        $id = next_dossier_id($type);
        $d = ['id' => $id, 'type' => $type, 'rev' => 1, 'createdAt' => $now, 'createdBy' => 'Client (formulaire en ligne)', 'v' => $v,
              'history' => [['t' => $now, 'by' => 'Client (formulaire en ligne)', 'ev' => 'web', 'info' => $link ? 'lien personnel envoyé par ' . ($link['by'] ?? '?') : 'lien générique']]];
    }
    $d['v']['dossier'] = $d['id'];
    $d['origin'] = 'web';
    $d['web'] = ['submittedAt' => $now, 'ip' => $ip, 'via' => $link ? 'lien' : 'generique', 'pan' => $pan, 'handled' => false, 'mails' => [], 'arTo' => $link ? (string)($link['email'] ?? '') : ''];
    $d['updatedAt'] = $now; $d['updatedBy'] = 'Client (formulaire en ligne)';
    $f = (string)dossier_file($d['id']);
    if (!write_json($f, $d)) { flock($lock, LOCK_UN); out(500, ['error' => 'Enregistrement impossible : réessayez dans quelques minutes.']); }
    if ($link) { $all = read_json($WEBL); $all[$link['key']]['used'] = $now; $all[$link['key']]['dossierId'] = $d['id']; write_json($WEBL, $all); }
    flock($lock, LOCK_UN);
    throttle_fail($WEBT, $ip, 3600);                                        // chaque envoi compte dans la limite par adresse IP
    auth_log('web-submit', (string)$v['email'], $d['id'] . ($link ? ' (lien personnel)' : ' (lien générique)'));
    web_mails($d);
    flock($lock, LOCK_EX); $cur = read_dossier($d['id']) ?? $d; $cur['web']['mails'] = $d['web']['mails']; write_json($f, $cur); flock($lock, LOCK_UN);
    $ar = !empty($d['web']['mails']['ar']['ok']);
    out(200, ['ok' => true, 'ref' => $d['id'], 'ar' => $ar, 'email' => $ar ? (string)$d['web']['mails']['ar']['to'] : '', 'pan' => $pan]);
}

// appels automatiques (ping toutes les 30 s, actualisation du tableau de bord) : pas une activité, la déconnexion après inactivité s'applique
$me = auth_user($ACC, $SESSION_IDLE, $lock, $action !== 'ping' && !($action === 'dashboard' && !empty($_GET['auto'])));
$PUBLIC = ['ping', 'me', 'bootstrap-info', 'sa-init', 'invite-check', 'signup', 'login', 'logout', 'forgot', 'reset-check', 'reset-password', 'mfa-verify'];
if (!$me && !in_array($action, $PUBLIC, true)) out(401, ['auth' => false, 'error' => 'Connexion requise']);
// la session n'est plus modifiée ensuite : on la libère pour ne pas bloquer les requêtes parallèles
if (!in_array($action, ['signup', 'login', 'logout', 'password', 'sa-check', 'sa-change', 'reset-password', 'mfa-verify', 'mfa-setup', 'mfa-enable', 'kick-all'], true)) session_write_close();
$doc = read_users();
if ($me && !find_user($doc, (string)$me['userId'])) out(401, ['auth' => false, 'error' => 'Fiche supprimée : reconnectez-vous.']);
if ($me && (find_user($doc, (string)$me['userId'])['active'] ?? true) === false) out(401, ['auth' => false, 'error' => 'Compte désactivé.']);
$uidMe = $me ? (string)$me['userId'] : '';
$saOK = $me && (int)($_SESSION['sa_until'] ?? 0) > time();

/* --- qui suis-je ? --- */
if ($action === 'me') {
    if ($me) out(200, me_payload($me, $doc));
    out(401, ['auth' => false, 'policy' => pw_policy(), 'bootstrap' => !read_json($ACC), 'resetFor' => mail_ready() ? $RESET_FOR : 'none']);
}

/* --- mise en service : aucun accès n'existe encore --- */
if ($action === 'bootstrap-info') {
    flock($lock, LOCK_SH);
    $boot = !read_json($ACC);
    flock($lock, LOCK_UN);
    $supers = [];
    if ($boot) foreach ($doc['users'] as $u) if (($u['active'] ?? true) !== false && has_perm($doc, (string)$u['id'], 'super')) $supers[] = ['id' => (string)$u['id'], 'name' => (string)$u['name'], 'login' => (string)$u['login']];
    out(200, ['bootstrap' => $boot, 'saDefined' => superadmin_hash() !== '', 'local' => is_loopback($ip), 'supers' => $supers, 'policy' => pw_policy()]);
}
/* première ouverture sans mot de passe super administrateur : il se choisit uniquement depuis le serveur lui-même */
if ($action === 'sa-init') {
    $in = body_json();
    flock($lock, LOCK_EX);
    if (superadmin_hash() !== '') out(409, ['error' => 'Le mot de passe super administrateur est déjà défini.']);
    if (!is_loopback($ip)) out(403, ['error' => 'À faire depuis le serveur lui-même (http://localhost/service_clients/), ou avec le script d’installation.']);
    $next = (string)($in['next'] ?? '');
    if (strlen($next) < 12 || strlen($next) > 200) out(400, ['error' => 'Le mot de passe super administrateur doit faire au moins 12 caractères.']);
    if (!write_json($SAF, ['hash' => password_hash($next, PASSWORD_DEFAULT), 'changed' => date('c'), 'by' => 'mise en service (serveur)'])) out(500, ['error' => 'Écriture impossible']);
    flock($lock, LOCK_UN);
    auth_log('sa-init', '', 'mot de passe super administrateur défini depuis le serveur');
    out(200, ['ok' => true]);
}

/* --- super administrateur : confirmation par le mot de passe super administrateur (valable $SUPERADMIN_TTL s) --- */
if ($action === 'sa-check' || $action === 'sa-change') {
    $in = body_json();
    if (!has_any($doc, $uidMe, ['users', 'settings', 'security', 'super'])) out(403, ['error' => 'Réservé aux administrateurs']);
    if ($action === 'sa-change' && !has_perm($doc, $uidMe, 'super')) out(403, ['error' => 'Réservé au super administrateur']);
    flock($lock, LOCK_EX);
    throttle_check($FAILS, $ip, $MAX_FAILS, $FAIL_WINDOW);
    $h = superadmin_hash();
    // le mot de passe actuel est toujours exigé, super administrateur compris : une session laissée ouverte ne suffit pas
    if ($h === '' || !password_verify((string)($in['password'] ?? ''), $h)) {
        throttle_fail($FAILS, $ip, $FAIL_WINDOW);
        auth_log('sa-fail', $me['login']);
        out(403, ['error' => $h === '' ? 'Mot de passe super administrateur non défini : lancez le script d’installation.' : 'Mot de passe super administrateur incorrect.']);
    }
    throttle_clear($FAILS, $ip);
    if ($action === 'sa-change') {
        $next = (string)($in['next'] ?? '');
        if (strlen($next) < 12) out(400, ['error' => 'Le mot de passe super administrateur doit faire au moins 12 caractères.']);
        if (strlen($next) > 200) out(400, ['error' => 'Mot de passe trop long.']);
        if (!write_json($SAF, ['hash' => password_hash($next, PASSWORD_DEFAULT), 'changed' => date('c'), 'by' => $me['name']])) out(500, ['error' => 'Écriture impossible']);
    }
    flock($lock, LOCK_UN);
    auth_log($action === 'sa-change' ? 'sa-change' : 'sa-ok', $me['login']);
    $_SESSION['sa_until'] = time() + $SUPERADMIN_TTL;
    out(200, ['ok' => true, 'ttl' => $SUPERADMIN_TTL]);
}

/* --- invitation : vérification du code (page de création d'accès) --- */
if ($action === 'invite-check') {
    flock($lock, LOCK_EX);
    throttle_check($FAILS, $ip, $MAX_FAILS, $FAIL_WINDOW);
    $inv = invite_find($INV, (string)($_GET['code'] ?? ''));
    $u = $inv ? find_user($doc, $inv['userId']) : null;
    if (!$inv || !$u) {
        throttle_fail($FAILS, $ip, $FAIL_WINDOW);
        flock($lock, LOCK_UN);
        out(404, ['error' => 'Invitation inconnue, déjà utilisée ou expirée : demandez-en une nouvelle à un administrateur.']);
    }
    flock($lock, LOCK_UN);
    out(200, ['name' => (string)$u['name'], 'login' => (string)$u['login'], 'expires' => $inv['expires'], 'policy' => pw_policy()]);
}

/* --- création de son accès : mise en service (mot de passe super administrateur) ou invitation --- */
if ($action === 'signup') {
    $in = body_json();
    flock($lock, LOCK_EX);
    throttle_check($FAILS, $ip, $MAX_FAILS, $FAIL_WINDOW);
    $pass = (string)($in['password'] ?? '');
    $acc = read_json($ACC);
    $boot = !$acc;
    $code = (string)($in['invite'] ?? '');
    if ($boot) {
        // mise en service, ou remise en route quand plus aucun accès n'existe : réservée à qui connaît le mot de passe super administrateur
        $h = superadmin_hash();
        if ($h === '' || !password_verify((string)($in['sa'] ?? ''), $h)) {
            throttle_fail($FAILS, $ip, $FAIL_WINDOW);
            auth_log('fail', '', 'mise en service : mot de passe super administrateur incorrect');
            out(403, ['error' => $h === '' ? 'Mot de passe super administrateur non défini.' : 'Mot de passe super administrateur incorrect.']);
        }
        $u = find_user($doc, (string)($in['userId'] ?? ''));
        if (!$u || ($u['active'] ?? true) === false || !has_perm($doc, (string)$u['id'], 'super')) out(400, ['error' => 'Choisissez un super administrateur dans la liste.']);
    } else {
        $inv = invite_find($INV, $code);
        if (!$inv) {
            throttle_fail($FAILS, $ip, $FAIL_WINDOW);
            auth_log('fail', '', 'invitation inconnue, utilisée ou expirée');
            out(403, ['error' => 'Invitation inconnue, déjà utilisée ou expirée : demandez-en une nouvelle à un administrateur.']);
        }
        $u = find_user($doc, (string)$inv['userId']);
        if (!$u || ($u['active'] ?? true) === false) out(400, ['error' => 'Votre fiche a été supprimée ou désactivée entre-temps : contactez un administrateur.']);
    }
    $uid = (string)$u['id']; $login = clean_login($u['login']); $name = (string)$u['name'];
    if ($why = pw_problem($pass, $login)) out(400, ['error' => $why]);
    if (isset($acc[$login])) out(409, ['error' => 'Cet accès existe déjà : connectez-vous, ou demandez à un administrateur de le réinitialiser.']);
    $acc[$login] = ['userId' => $uid, 'hash' => password_hash($pass, PASSWORD_DEFAULT), 'stamp' => bin2hex(random_bytes(8)), 'created' => date('c'), 'lastLogin' => date('c')];
    if (!write_json($ACC, $acc)) out(500, ['error' => 'Écriture impossible']);
    if (!$boot) { $all = read_json($INV); unset($all[hash('sha256', invite_norm($code))]); write_json($INV, $all); }   // usage unique
    throttle_clear($FAILS, $ip);
    flock($lock, LOCK_UN);
    auth_log('signup', $login, $name . ($boot ? ' (mise en service)' : ' (invitation)'));
    out(200, finish_login($login, $acc, $name, false, $doc));
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
    $ok = password_verify($pass, is_array($a) ? (string)$a['hash'] : DUMMY_HASH);
    if (!is_array($a) || !$ok) {
        throttle_fail($FAILS, $ip, $FAIL_WINDOW);
        auth_log('fail', $login, is_array($a) ? ($sup ? 'mot de passe incorrect (super administrateur)' : 'mot de passe incorrect') : 'identifiant inconnu ou accès non créé');
        $e = $login !== '' ? lock_fail($LOCKF, $login, $att, $min) : ['n' => 0, 'until' => 0];
        $locked = (int)$e['until'] > time();
        if ($sup) {
            // e-mail au titulaire : au moment du blocage, avec un lien pour choisir un nouveau mot de passe ;
            // si le blocage est désactivé (0 essai), une alerte toutes les N erreurs
            $notify = $locked ? (int)$e['n'] === $att : ($att === 0 && (int)$e['n'] % max(1, $LOCK_ATTEMPTS) === 0);
            $tok = $notify && $locked && $SUPER_LOCK_MAIL && mail_ready() ? reset_issue($login, $sup) : null;
            flock($lock, LOCK_UN);
            if ($att === 0) usleep(800000);            // jamais bloqué : on ralentit les essais en série
            if ($notify && $SUPER_LOCK_MAIL && mail_ready()) {
                $when = date('d/m/Y à H:i');
                $body = "Bonjour {$sup['name']},\n\n" . (int)$e['n'] . " mots de passe erronés ont été saisis sur votre compte super administrateur (identifiant « $login »), le dernier depuis l'adresse $ip, le $when.\n\n";
                if ($locked) $body .= "Votre compte est maintenant bloqué" . ($min > 0 ? " jusqu'à " . date('H:i', (int)$e['until']) : '') . ".\n\n"
                    . ($tok ? "Pour le débloquer, choisissez un nouveau mot de passe avec ce lien (valable $RESET_MINUTES minutes, une seule fois) :\n" . app_link('reinit', $tok) . "\n\n"
                            : "Pour le débloquer, utilisez « Mot de passe oublié ? » sur l'écran de connexion : " . mail_cfg()['appUrl'] . "\n\n")
                    . "Si ce n'était pas vous, quelqu'un essaie votre mot de passe : changez-le et prévenez le service informatique.\n";
                else $body .= "Si ce n'était pas vous, changez votre mot de passe dès maintenant (menu en haut à droite › Changer mon mot de passe) ou utilisez « Mot de passe oublié ? » : " . mail_cfg()['appUrl'] . "\n";
                $err = send_mail($sup['email'], $locked ? 'Compte bloqué : mots de passe erronés – Service clients D8' : 'Alerte : mots de passe erronés sur votre compte – Service clients D8', $body);
                auth_log($err ? 'mail-fail' : 'super-alert', $login, $err ?: ($locked ? 'compte bloqué, lien de déblocage envoyé à ' : 'alerte envoyée à ') . $sup['email']);
            }
        }
        if ($locked) out(423, ['auth' => false, 'locked' => true, 'error' => lock_msg($e, (bool)$sup)]);
        $left = $att - (int)$e['n'];
        out(401, ['auth' => false, 'error' => 'Identifiant ou mot de passe incorrect.' . ($att > 0 && $left > 0 && $left < $att ? " Encore $left essai" . ($left > 1 ? 's' : '') . ' avant le blocage du compte.' : '')]);
    }
    lock_clear($LOCKF, $login);
    $u = find_user($doc, (string)$a['userId']);
    if (!$u || ($u['active'] ?? true) === false) { flock($lock, LOCK_UN); auth_log('disabled', $login); out(403, ['error' => 'Ce compte est désactivé. Contactez un administrateur.']); }
    $name = (string)$u['name'];
    // les échecs de l'adresse IP ne sont oubliés qu'une fois la connexion complète (double authentification comprise)
    if (empty($a['mfa']['on']) && !mfa_required($doc, (string)$a['userId'])) throttle_clear($FAILS, $ip);
    if (password_needs_rehash((string)$a['hash'], PASSWORD_DEFAULT)) $acc[$login]['hash'] = password_hash($pass, PASSWORD_DEFAULT);
    $acc[$login]['lastLogin'] = date('c');
    write_json($ACC, $acc);
    flock($lock, LOCK_UN);
    // double authentification si active ou exigée ; mot de passe ne respectant plus les règles : à changer
    out(200, finish_login($login, $acc, $name, (bool)pw_problem($pass, $login), $doc, 'login'));
}

/* --- mot de passe oublié : lien de réinitialisation par e-mail (tout le monde ou super administrateurs, selon le réglage) --- */
if ($action === 'forgot') {
    $in = body_json();
    $t0 = microtime(true);
    throttle_check($RFAILS, $ip, 10, 900);
    throttle_fail($RFAILS, $ip, 900);                 // chaque demande compte : 10 par quart d'heure et par adresse IP
    $login = clean_login($in['login'] ?? '');
    flock($lock, LOCK_EX);
    $tg = $login !== '' && $RESET_FOR !== 'none' ? reset_target($doc, read_json($ACC), $login, $RESET_FOR === 'super') : null;
    $send = $tg && mail_ready() ? reset_issue($login, $tg) : null;   // 3 e-mails par heure et par compte au plus
    flock($lock, LOCK_UN);
    if ($send) {
        $err = send_mail($tg['email'], 'Réinitialisation de votre mot de passe – Service clients D8',
            "Bonjour {$tg['name']},\n\nUne réinitialisation du mot de passe de votre accès à l'outil Service clients D8 (identifiant « $login ») a été demandée depuis l'adresse $ip, le " . date('d/m/Y à H:i') . ".\n\n"
            . "Pour choisir un nouveau mot de passe, ouvrez ce lien (valable $RESET_MINUTES minutes, une seule fois) :\n" . app_link('reinit', $send) . "\n\n"
            . "Si vous n'êtes pas à l'origine de cette demande, ignorez ce message : votre mot de passe actuel reste valable.\n");
        auth_log($err ? 'mail-fail' : 'forgot', $login, $err ?: 'lien envoyé à ' . $tg['email']);
    } else auth_log('forgot', $login, 'aucun envoi (accès inexistant ou non concerné, envoi non réglé ou limite atteinte)');
    $wait = 2.0 - (microtime(true) - $t0); if ($wait > 0) usleep((int)($wait * 1e6));   // même durée de réponse dans tous les cas
    out(200, ['ok' => true, 'message' => 'Si un accès existe pour cette adresse, un lien de réinitialisation vient d’y être envoyé (valable ' . $RESET_MINUTES . ' minutes). Pensez à vérifier le dossier des courriers indésirables.']);
}
if ($action === 'reset-check' || $action === 'reset-password') {
    $in = $action === 'reset-password' ? body_json() : [];
    throttle_check($RFAILS, $ip, 10, 900);
    $tok = strtolower((string)($action === 'reset-check' ? ($_GET['token'] ?? '') : ($in['token'] ?? '')));
    flock($lock, LOCK_EX);
    $r = reset_find($RESETS, $tok);
    $acc = read_json($ACC);
    $tg = $r ? reset_target($doc, $acc, (string)$r['login'], false) : null;
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
    if (mail_ready()) send_mail($tg['email'], 'Votre mot de passe a été changé – Service clients D8',
        "Bonjour {$tg['name']},\n\nLe mot de passe de votre accès (identifiant « $login ») vient d'être changé grâce au lien de réinitialisation, depuis l'adresse $ip, le " . date('d/m/Y à H:i') . ".\n\n"
        . "Si ce n'est pas vous, prévenez immédiatement le service informatique.\n");
    out(200, finish_login($login, $acc, $tg['name'], false, $doc));      // le lien e-mail ne dispense pas de la double authentification
}
/* --- double authentification : code saisi après le mot de passe (ou mise en place obligatoire) --- */
if ($action === 'mfa-verify') {
    $in = body_json();
    throttle_check($FAILS, $ip, $MAX_FAILS, $FAIL_WINDOW);
    $p = $_SESSION['mfa_pending'] ?? null;
    if (!is_array($p) || time() - (int)$p['t'] > 300) { unset($_SESSION['mfa_pending']); out(401, ['auth' => false, 'restart' => true, 'error' => 'Délai dépassé : ressaisissez votre identifiant et votre mot de passe.']); }
    $code = strtoupper((string)preg_replace('/[\s-]/', '', (string)($in['code'] ?? '')));
    flock($lock, LOCK_EX);
    $acc = read_json($ACC); $login = (string)$p['login']; $a = $acc[$login] ?? null;
    if (!is_array($a)) { unset($_SESSION['mfa_pending']); flock($lock, LOCK_UN); out(401, ['auth' => false, 'restart' => true, 'error' => 'Compte introuvable.']); }
    // codes erronés comptés par compte, d'une connexion à l'autre
    $mk = 'mfa:' . $login; $ml = lock_state($LOCKF, $mk, 15);
    if ($ml && (int)($ml['until'] ?? 0) > time()) {
        unset($_SESSION['mfa_pending']); flock($lock, LOCK_UN);
        out(423, ['auth' => false, 'restart' => true, 'error' => 'Trop de codes erronés : double authentification bloquée jusqu’à ' . date('H:i', (int)$ml['until']) . '. Un administrateur peut la débloquer avant (Utilisateurs › Débloquer).']);
    }
    $ok = false; $usedRecovery = false; $recovery = null;
    if (!empty($p['enroll'])) {                                   // mise en place : on vérifie le code produit par la nouvelle clé
        if ($st = totp_check($p['enroll'], $code)) {
            [$recovery, $hashes] = recovery_codes();
            $acc[$login]['mfa'] = ['on' => true, 'secret' => $p['enroll'], 'since' => date('c'), 'last' => $st, 'recovery' => $hashes];
            $ok = true; auth_log('mfa-on', $login, $p['name']);
        }
    } elseif (!empty($a['mfa']['on'])) {
        if ($st = totp_check((string)$a['mfa']['secret'], $code, (int)($a['mfa']['last'] ?? 0))) { $acc[$login]['mfa']['last'] = $st; $ok = true; }
        elseif (strlen($code) === 8 && ($k = array_search(hash('sha256', $code), (array)($a['mfa']['recovery'] ?? []), true)) !== false) {
            array_splice($acc[$login]['mfa']['recovery'], $k, 1); $ok = true; $usedRecovery = true;
            auth_log('mfa-recovery', $login, 'code de secours utilisé, ' . count($acc[$login]['mfa']['recovery']) . ' restant(s)');
        }
    }
    if (!$ok) {
        throttle_fail($FAILS, $ip, $FAIL_WINDOW);
        lock_fail($LOCKF, $mk, $MFA_MAX_FAILS, 15);
        $_SESSION['mfa_pending']['n'] = (int)$p['n'] + 1;
        auth_log('mfa-fail', $login, 'code erroné');
        flock($lock, LOCK_UN);
        if ($_SESSION['mfa_pending']['n'] >= 5) { unset($_SESSION['mfa_pending']); out(401, ['auth' => false, 'restart' => true, 'error' => 'Trop de codes erronés : ressaisissez votre identifiant et votre mot de passe.']); }
        $replay = !empty($a['mfa']['on']) && empty($p['enroll']) && totp_check((string)$a['mfa']['secret'], $code) > 0;
        out(401, ['auth' => false, 'error' => $replay ? 'Ce code vient déjà d’être utilisé : attendez le code suivant (il change toutes les 30 secondes).'
            : 'Code incorrect. Vérifiez que l’heure du téléphone est exacte, ou utilisez un code de secours.']);
    }
    write_json($ACC, $acc);
    lock_clear($LOCKF, $mk);
    flock($lock, LOCK_UN);
    throttle_clear($FAILS, $ip);
    unset($_SESSION['mfa_pending']);
    auth_log('login', $login, $p['name'] . ' (double authentification)');
    $s = open_session($login, $acc[$login], (string)$p['name']);
    if (!empty($p['weak'])) { $_SESSION['auth']['weak'] = true; $s = $_SESSION['auth']; }
    out(200, me_payload($s, $doc) + ['recovery' => $recovery, 'recoveryLeft' => count((array)($acc[$login]['mfa']['recovery'] ?? [])), 'usedRecovery' => $usedRecovery]);
}

/* --- double authentification : gestion par la personne connectée --- */
if (in_array($action, ['mfa-status', 'mfa-setup', 'mfa-enable', 'mfa-disable', 'mfa-recovery'], true)) {
    $in = $action === 'mfa-status' ? [] : body_json();
    $login = (string)$me['login'];
    flock($lock, LOCK_EX);
    $acc = read_json($ACC); $m = $acc[$login]['mfa'] ?? [];
    $req = mfa_required($doc, $uidMe);
    if ($action === 'mfa-status') { flock($lock, LOCK_UN); out(200, ['on' => !empty($m['on']), 'since' => $m['since'] ?? null, 'recoveryLeft' => count((array)($m['recovery'] ?? [])), 'required' => $req, 'policy' => $MFA_REQUIRED]); }
    if ($action === 'mfa-setup') {
        if (!empty($m['on'])) { flock($lock, LOCK_UN); out(409, ['error' => 'La double authentification est déjà active.']); }
        $_SESSION['mfa_setup'] = ['secret' => b32_encode(random_bytes(20)), 't' => time()];
        flock($lock, LOCK_UN);
        out(200, ['secret' => $_SESSION['mfa_setup']['secret'], 'uri' => otp_uri($login, $_SESSION['mfa_setup']['secret'])]);
    }
    if ($action === 'mfa-enable') {
        $su = $_SESSION['mfa_setup'] ?? null;
        if (!is_array($su) || time() - (int)$su['t'] > 900) { flock($lock, LOCK_UN); out(400, ['error' => 'Délai dépassé : recommencez la mise en place.']); }
        $st = totp_check((string)$su['secret'], (string)preg_replace('/\s/', '', (string)($in['code'] ?? '')));
        if (!$st) { flock($lock, LOCK_UN); throttle_fail($FAILS, $ip, $FAIL_WINDOW); out(400, ['error' => 'Code incorrect : saisissez le code affiché par l’application (vérifiez l’heure du téléphone).']); }
        [$codes, $hashes] = recovery_codes();
        $acc[$login]['mfa'] = ['on' => true, 'secret' => $su['secret'], 'since' => date('c'), 'last' => $st, 'recovery' => $hashes];
        write_json($ACC, $acc); flock($lock, LOCK_UN);
        unset($_SESSION['mfa_setup']);
        auth_log('mfa-on', $login, $me['name']);
        out(200, ['ok' => true, 'recovery' => $codes]);
    }
    // désactiver ou regénérer les codes de secours : mot de passe exigé
    throttle_check($FAILS, $ip, $MAX_FAILS, $FAIL_WINDOW);
    if (!password_verify((string)($in['password'] ?? ''), (string)($acc[$login]['hash'] ?? ''))) { flock($lock, LOCK_UN); throttle_fail($FAILS, $ip, $FAIL_WINDOW); out(403, ['error' => 'Mot de passe incorrect.']); }
    if (empty($m['on'])) { flock($lock, LOCK_UN); out(409, ['error' => 'La double authentification n’est pas active.']); }
    if ($action === 'mfa-disable') {
        if ($req) { flock($lock, LOCK_UN); out(403, ['error' => 'La double authentification est obligatoire pour votre rôle : elle ne peut pas être désactivée.']); }
        unset($acc[$login]['mfa']); write_json($ACC, $acc); flock($lock, LOCK_UN);
        auth_log('mfa-off', $login, $me['name']);
        out(200, ['ok' => true]);
    }
    [$codes, $hashes] = recovery_codes();
    $acc[$login]['mfa']['recovery'] = $hashes; write_json($ACC, $acc); flock($lock, LOCK_UN);
    auth_log('mfa-codes', $login, 'nouveaux codes de secours');
    out(200, ['ok' => true, 'recovery' => $codes]);
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

/* --- sa propre signature (fonction, téléphone direct) --- */
if ($action === 'profile-set') {
    $in = body_json();
    flock($lock, LOCK_EX);
    $d = read_users();
    foreach ($d['users'] as &$u) if ((string)$u['id'] === $uidMe) {
        $u['sigFonction'] = clean_text($in['sigFonction'] ?? '', 80);
        $u['sigTel'] = clean_text($in['sigTel'] ?? '', 40);
        $u['updated'] = date('c');
    }
    unset($u);
    if (!write_json($USERS, $d)) out(500, ['error' => 'Écriture impossible']);
    flock($lock, LOCK_UN);
    out(200, ['ok' => true]);
}

/* --- réglages partagés de l'outil : lecture (tout le monde), modification (droit « settings ») --- */
if ($action === 'settings') out(200, ['settings' => read_settings(), 'maintenance' => $MAINTENANCE]);
if ($action === 'settings-set') {
    $in = body_json(1024 * 1024);
    $canAll = has_perm($doc, $uidMe, 'settings'); $canWeb = has_perm($doc, $uidMe, 'web');
    if (!$canAll && !$canWeb) out(403, ['error' => 'Réservé aux personnes ayant le droit « Réglages de l’outil »']);
    $new = [];
    foreach (SETTINGS_DEFAULTS as $k => $def) {
        if (!array_key_exists($k, $in)) continue;
        if (!$canAll && !in_array($k, WEB_SETTINGS, true)) out(403, ['error' => 'Votre rôle ne permet de modifier que les réglages du formulaire en ligne.']);
        if (is_bool($def)) $new[$k] = !empty($in[$k]);
        elseif ($k === 'conservation') { $n = (int)$in[$k]; if ($n !== 0 && ($n < 6 || $n > 120)) out(400, ['error' => 'Durée de conservation : 0 (jamais d’effacement) ou de 6 à 120 mois.']); $new[$k] = $n; }
        elseif (is_int($def)) $new[$k] = max(0, min(3650, (int)$in[$k]));
        elseif ($k === 'webPublicUrl') {
            $u = clean_text($in[$k], 300);
            if ($u !== '' && !preg_match('~^https://[^\s"<>?#]+$~i', $u)) out(400, ['error' => 'L’adresse publique doit commencer par https:// (ex. https://sav.d8.fr/service_clients/).']);
            $new[$k] = $u === '' ? '' : rtrim($u, '/') . '/';
        }
        elseif ($k === 'logo') {
            $l = (string)$in[$k];
            if ($l !== '' && (!preg_match('#^data:image/(png|jpeg);base64,[A-Za-z0-9+/=]+$#', $l) || strlen($l) > 600000)) out(400, ['error' => 'Logo invalide (PNG ou JPEG, 400 Ko maximum).']);
            $new[$k] = $l;
        } else $new[$k] = clean_text($in[$k], 200);
    }
    foreach (['emailGeneral', 'emailMonetique', 'emailSav', 'emailCommandes', 'expediteur', 'webNotify'] as $k)
        if (($new[$k] ?? '') !== '' && !filter_var($new[$k], FILTER_VALIDATE_EMAIL)) out(400, ['error' => "Adresse e-mail invalide : {$new[$k]}"]);
    flock($lock, LOCK_EX);
    $cur = read_json($SETF);
    if (!write_json($SETF, array_merge($cur, $new, ['changed' => date('c'), 'by' => $me['name']]))) out(500, ['error' => 'Écriture impossible']);
    flock($lock, LOCK_UN);
    auth_log('settings', $me['login'], implode(', ', array_keys(array_filter($new, function ($v, $k) use ($cur) { return ($cur[$k] ?? SETTINGS_DEFAULTS[$k]) !== $v; }, ARRAY_FILTER_USE_BOTH))) ?: 'aucun changement');
    out(200, ['ok' => true, 'settings' => read_settings()]);
}

/* =============================================================================
   Dossiers : le serveur n'accepte que les types autorisés par le rôle
   ========================================================================== */
if ($action === 'dossiers') {
    $type = (string)($_GET['type'] ?? '');
    $q = mb_strtolower(clean_text($_GET['q'] ?? '', 80), 'UTF-8');
    $status = (string)($_GET['status'] ?? 'all');
    $allowedTypes = array_values(array_filter(TYPES, function ($t) use ($doc, $uidMe) { return has_perm($doc, $uidMe, $t); }));
    if ($type !== '' && !in_array($type, $allowedTypes, true)) out(403, ['error' => 'Type de demande non autorisé pour votre rôle']);
    $types = $type !== '' ? [$type] : $allowedTypes;
    $list = [];
    flock($lock, LOCK_SH);
    foreach (glob("$DOS/*.json") ?: [] as $f) {
        $d = json_decode((string)file_get_contents($f), true);
        if (!is_array($d) || !in_array($d['type'] ?? '', $types, true)) continue;
        $s = dossier_summary($d);
        if ($status === 'open' && $s['closed']) continue;
        if ($status === 'closed' && !$s['closed']) continue;
        if ($q !== '' && mb_strpos(mb_strtolower(implode(' ', [$s['id'], $s['client'], $s['site'], $s['email'], $s['matricule']]), 'UTF-8'), $q) === false) continue;
        $list[] = $s;
    }
    flock($lock, LOCK_UN);
    usort($list, function ($a, $b) { return strcmp((string)$b['updatedAt'], (string)$a['updatedAt']); });
    $total = count($list);
    out(200, ['dossiers' => array_slice($list, 0, 1000), 'total' => $total, 'types' => $allowedTypes]);
}
if ($action === 'dossier-get') {
    $d = read_dossier((string)($_GET['id'] ?? ''));
    if (!$d) out(404, ['error' => 'Dossier introuvable (effacé ou numéro erroné).']);
    if (!has_perm($doc, $uidMe, $d['type'])) out(403, ['error' => 'Ce type de dossier n’est pas autorisé pour votre rôle.']);
    out(200, ['dossier' => $d]);
}
if ($action === 'dossier-save') {
    $in = body_json($DOSSIER_BYTES);
    if (!empty($me['weak'])) out(403, ['error' => 'Votre mot de passe ne respecte plus les règles de sécurité : changez-le avant d’enregistrer.']);
    if ($MAINTENANCE && !has_any($doc, $uidMe, ['settings', 'super'])) out(403, ['error' => 'Mode maintenance : les enregistrements sont momentanément suspendus.']);
    $type = (string)($in['type'] ?? '');
    if (!in_array($type, TYPES, true)) out(400, ['error' => 'Type de demande inconnu']);
    if (!has_perm($doc, $uidMe, $type)) out(403, ['error' => 'Ce type de demande n’est pas autorisé pour votre rôle.']);
    $id = (string)($in['id'] ?? '');
    $now = date('c'); $by = (string)$me['name'];
    flock($lock, LOCK_EX);
    if ($id !== '') {
        $d = read_dossier($id);
        if (!$d) { flock($lock, LOCK_UN); out(404, ['error' => 'Dossier introuvable (effacé entre-temps ?).']); }
        if ($d['type'] !== $type) { flock($lock, LOCK_UN); out(400, ['error' => 'Le type d’un dossier ne peut pas changer.']); }
        if ((int)($in['rev'] ?? 0) !== (int)$d['rev']) { flock($lock, LOCK_UN); out(409, ['conflict' => true, 'error' => 'Ce dossier a été modifié entre-temps par ' . ($d['updatedBy'] ?? '?') . '.', 'dossier' => $d]); }
    } else {
        $id = next_dossier_id($type);
        $d = ['id' => $id, 'type' => $type, 'rev' => 0, 'createdAt' => $now, 'createdBy' => $by, 'history' => [['t' => $now, 'by' => $by, 'ev' => 'create', 'info' => clean_text($in['origin'] ?? '', 120)]]];
    }
    $v = clean_values((array)($in['v'] ?? []));
    $v['dossier'] = $id;
    $d['v'] = $v;
    $d['rev'] = (int)$d['rev'] + 1;
    $d['updatedAt'] = $now; $d['updatedBy'] = $by;
    $hist = (array)($d['history'] ?? []);
    if (array_key_exists('closed', $in) && (bool)$in['closed'] !== !empty($d['closed'])) {
        $d['closed'] = (bool)$in['closed'];
        $hist[] = ['t' => $now, 'by' => $by, 'ev' => $d['closed'] ? 'close' : 'reopen'];
    }
    $evt = $in['event'] ?? null;
    // la réponse a pu être rédigée avant l'attribution du numéro : le repère est remplacé par le numéro du dossier
    if (is_array($evt)) foreach (['subject', 'text'] as $k) if (isset($evt[$k]) && is_string($evt[$k])) $evt[$k] = str_replace('[n° de dossier]', $id, $evt[$k]);
    if (is_array($evt) && ($evt['ev'] ?? '') === 'reply' && in_array($evt['scen'] ?? '', SCENARIOS[$type], true)) {
        $via = in_array($evt['via'] ?? '', ['copie', 'eml', 'mailto'], true) ? $evt['via'] : 'copie';
        $d['lastScen'] = $evt['scen']; $d['lastAt'] = $now;
        $d['lastMail'] = ['scen' => $evt['scen'], 'via' => $via, 'at' => $now, 'by' => $by, 'to' => clean_text($evt['to'] ?? '', 200),
                          'subject' => mask_pan(clean_text($evt['subject'] ?? '', 300)), 'text' => mask_pan(clean_text($evt['text'] ?? '', 20000, true))];
        $hist[] = ['t' => $now, 'by' => $by, 'ev' => 'reply', 'scen' => $evt['scen'], 'via' => $via, 'info' => cut(mask_pan(clean_text($evt['subject'] ?? '', 300)), 300)];
    } elseif (is_array($evt) && ($evt['ev'] ?? '') === 'import') {
        $hist[] = ['t' => $now, 'by' => $by, 'ev' => 'import', 'info' => cut(clean_text($evt['info'] ?? '', 200), 200)];
    } elseif ((int)$d['rev'] > 1) {
        $last = end($hist);
        // enregistrements successifs de la même personne : une seule ligne d'historique par quart d'heure
        if (!$last || ($last['ev'] ?? '') !== 'save' || ($last['by'] ?? '') !== $by || strtotime((string)$last['t']) < time() - 900) $hist[] = ['t' => $now, 'by' => $by, 'ev' => 'save'];
        else $hist[count($hist) - 1]['t'] = $now;
    }
    $d['history'] = array_slice($hist, -150);
    $f = (string)dossier_file($id);
    if (!write_json($f, $d)) { flock($lock, LOCK_UN); out(500, ['error' => 'Écriture impossible']); }
    flock($lock, LOCK_UN);
    out(200, ['dossier' => $d]);
}
if ($action === 'dossier-delete') {
    $in = body_json();
    $id = (string)($in['id'] ?? '');
    flock($lock, LOCK_EX);
    $d = read_dossier($id);
    if (!$d) { flock($lock, LOCK_UN); out(404, ['error' => 'Dossier introuvable.']); }
    if (!has_perm($doc, $uidMe, $d['type']) || !has_perm($doc, $uidMe, 'delete')) { flock($lock, LOCK_UN); out(403, ['error' => 'Suppression réservée aux personnes ayant le droit « Supprimer des dossiers ».']); }
    if ($MAINTENANCE && !has_any($doc, $uidMe, ['settings', 'super'])) { flock($lock, LOCK_UN); out(403, ['error' => 'Mode maintenance.']); }
    @unlink((string)dossier_file($id));
    flock($lock, LOCK_UN);
    auth_log('dossier-delete', $me['login'], $id);
    out(200, ['ok' => true]);
}


/* =============================================================================
   Tableau de bord des administrateurs : chiffres agrégés, filtrés par les droits
   (aucune donnée nominative de client hors des dossiers « en attente » des types autorisés)
   ========================================================================== */
/** médiane d'une liste de nombres (null si vide) */
function median(array $a): ?float { if (!$a) return null; sort($a); $n = count($a); $m = intdiv($n, 2); return $n % 2 ? (float)$a[$m] : ($a[$m - 1] + $a[$m]) / 2; }
/** taille totale d'un dossier (octets) */
function dir_bytes(string $dir): int {
    $n = 0;
    try { foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) if ($f->isFile()) $n += (int)$f->getSize(); } catch (Throwable $e) { }
    return $n;
}
if ($action === 'dashboard') {
    if (!has_any($doc, $uidMe, ['users', 'security', 'settings', 'super'])) out(403, ['error' => 'Tableau de bord réservé aux administrateurs.']);
    $isSup = has_perm($doc, $uidMe, 'super'); $canSec = has_perm($doc, $uidMe, 'security'); $canUsers = has_perm($doc, $uidMe, 'users');
    $T = array_values(array_filter(TYPES, function ($t) use ($doc, $uidMe) { return has_perm($doc, $uidMe, $t); }));
    $canWeb = has_any($doc, $uidMe, ['web', 'remb']);
    $DAYS = 180; $now = time();
    $d0 = date('Y-m-d', strtotime('-' . ($DAYS - 1) . ' days', strtotime('today')));
    $idx = []; for ($i = 0; $i < $DAYS; $i++) $idx[date('Y-m-d', strtotime("$d0 +$i day"))] = $i;
    $di = function ($iso) use ($idx) { $t = is_string($iso) ? strtotime($iso) : false; return $t === false ? null : ($idx[date('Y-m-d', $t)] ?? null); };
    $zeros = array_fill(0, $DAYS, 0);
    $W = [7, 30, 90];
    $inWin = function (?int $i, int $w, bool $prev = false) use ($DAYS) { if ($i === null) return false; $hi = $DAYS - 1 - ($prev ? $w : 0); return $i <= $hi && $i > $hi - $w; };

    // --- dossiers (types autorisés) et demandes en ligne
    $by = []; $created = []; foreach ($T as $t) { $by[$t] = ['open' => 0, 'closed' => 0, 'noReply' => 0, 'total' => 0]; $created[$t] = $zeros; }
    $replies = $zeros; $closedDay = $zeros; $scen = ['7' => [], '30' => [], '90' => []]; $delays = []; $waiting = [];
    $people = []; foreach ($W as $w) $people[(string)$w] = [];
    $web = ['received' => $zeros, 'toHandle' => 0, 'mailFail' => 0, 'oldest' => []];
    $count = 0;
    flock($lock, LOCK_SH);
    foreach (glob("$DOS/*.json") ?: [] as $f) {
        $d = json_decode((string)@file_get_contents($f), true);
        if (!is_array($d) || !in_array($d['type'] ?? '', TYPES, true)) continue;
        $t = (string)$d['type']; $isWeb = ($d['origin'] ?? '') === 'web';
        if ($canWeb && $isWeb && $t === 'remb') {
            $w = (array)($d['web'] ?? []);
            if (($i = $di($w['submittedAt'] ?? null)) !== null) $web['received'][$i]++;
            if (empty($w['handled'])) { $web['toHandle']++; $web['oldest'][] = ['id' => (string)$d['id'], 'client' => cut((string)($d['v']['nom'] ?? ''), 80), 'submittedAt' => $w['submittedAt'] ?? null]; }
            $m = (array)($w['mails'] ?? []);
            if (empty($m['notify']['ok']) || (isset($m['ar']) && empty($m['ar']['ok'])) || (isset($m['confirm']) && empty($m['confirm']['ok']))) $web['mailFail']++;
        }
        if (!isset($by[$t])) continue;
        $count++;
        $closed = !empty($d['closed']); $hist = (array)($d['history'] ?? []);
        $by[$t]['total']++; $by[$t][$closed ? 'closed' : 'open']++;
        $ci = $di($d['createdAt'] ?? null);
        if ($ci !== null) $created[$t][$ci]++;
        $first = null;
        foreach ($hist as $h) {
            if (!is_array($h)) continue;
            $ev = (string)($h['ev'] ?? ''); $hi = $di($h['t'] ?? null); $who = cut((string)($h['by'] ?? ''), 80);
            if ($ev === 'reply') {
                if ($first === null) $first = strtotime((string)$h['t']);
                if ($hi !== null) {
                    $replies[$hi]++;
                    foreach ($W as $w) if ($inWin($hi, $w)) { $k = $t . '|' . (string)($h['scen'] ?? ''); $scen[(string)$w][$k] = ($scen[(string)$w][$k] ?? 0) + 1; }
                }
            }
            if ($ev === 'close' && $hi !== null) $closedDay[$hi]++;
            // activité par personne (super administrateur seulement) : réponses, créations, enregistrements
            if ($isSup && $hi !== null && $who !== '' && $who !== 'Client (formulaire en ligne)' && in_array($ev, ['reply', 'create', 'save', 'import', 'close'], true))
                foreach ($W as $w) if ($inWin($hi, $w)) {
                    $p = &$people[(string)$w][$who];
                    if (!$p) $p = ['name' => $who, 'replies' => 0, 'created' => 0, 'updates' => 0];
                    $p[$ev === 'reply' ? 'replies' : ($ev === 'create' ? 'created' : 'updates')]++;
                    unset($p);
                }
        }
        $ct = strtotime((string)($d['createdAt'] ?? ''));
        if ($first !== null && $ct !== false && $ci !== null) $delays[] = [$ci, max(0, ($first - $ct) / 3600)];
        if (!$closed && $first === null) {
            $by[$t]['noReply']++;
            $waiting[] = ['id' => (string)$d['id'], 'type' => $t, 'client' => cut((string)(dossier_summary($d)['client']), 80), 'origin' => $isWeb ? 'web' : '',
                          'createdAt' => $d['createdAt'] ?? null, 'updatedAt' => $d['updatedAt'] ?? null, 'updatedBy' => cut((string)($d['updatedBy'] ?? ''), 80)];
        }
    }
    flock($lock, LOCK_UN);
    usort($waiting, function ($a, $b) { return strcmp((string)$a['createdAt'], (string)$b['createdAt']); });
    $first = [];
    foreach ($W as $w) {
        $cur = []; $prev = [];
        foreach ($delays as [$i, $h]) { if ($inWin($i, $w)) $cur[] = $h; elseif ($inWin($i, $w, true)) $prev[] = $h; }
        $first[(string)$w] = ['cur' => median($cur), 'prev' => median($prev), 'nCur' => count($cur), 'nPrev' => count($prev)];
    }
    foreach ($people as $w => $list) { $list = array_values($list); usort($list, function ($a, $b) { return ($b['replies'] + $b['created'] + $b['updates']) <=> ($a['replies'] + $a['created'] + $a['updates']); }); $people[$w] = array_slice($list, 0, 15); }
    $out = ['now' => date('c'), 'day0' => $d0, 'days' => $DAYS,
            'scope' => ['types' => $T, 'web' => $canWeb, 'people' => $isSup, 'accounts' => $canUsers || $canSec, 'security' => $canSec, 'system' => $canSec],
            'dossiers' => ['total' => $count, 'byType' => (object)$by, 'created' => (object)$created, 'replies' => $replies, 'closed' => $closedDay, 'scen' => $scen,
                           'firstReply' => $first, 'waitingCount' => count($waiting), 'waitingOld' => count(array_filter($waiting, function ($x) use ($now) { return strtotime((string)$x['createdAt']) < $now - 172800; })),
                           'waiting' => array_slice($waiting, 0, 12)]];
    if ($isSup) $out['people'] = $people;

    // --- formulaire en ligne : demandes reçues, à traiter, liens envoyés et utilisés
    if ($canWeb) {
        usort($web['oldest'], function ($a, $b) { return strcmp((string)$a['submittedAt'], (string)$b['submittedAt']); });
        $web['oldest'] = array_slice($web['oldest'], 0, 6);
        $links = []; foreach ($W as $w) $links[(string)$w] = ['sent' => 0, 'used' => 0];
        foreach (read_json($WEBL) as $x) {
            if (!is_array($x)) continue;
            $i = $di(date('c', (int)($x['t'] ?? 0)));
            foreach ($W as $w) if ($inWin($i, $w)) { $links[(string)$w]['sent']++; if (!empty($x['used'])) $links[(string)$w]['used']++; }
        }
        $c = read_settings();
        $out['web'] = $web + ['links' => $links, 'enabled' => !empty($c['webRemb']), 'generic' => !empty($c['webGeneric']), 'public' => (string)$c['webPublicUrl'] !== '', 'ar' => !empty($c['webAR'])];
    }

    // --- comptes : accès, invitations, double authentification, blocages
    if ($canUsers || $canSec) {
        $acc = read_json($ACC); $inv = read_json($INV);
        $withAcc = []; $mfa = 0; foreach ($acc as $l => $a) { $withAcc[(string)($a['userId'] ?? '')] = true; if (!empty($a['mfa']['on'])) $mfa++; }
        $pending = []; foreach ($inv as $v) if (is_array($v) && (int)($v['exp'] ?? 0) > $now) $pending[(string)($v['userId'] ?? '')] = true;
        $active = 0; $noAccess = 0; $supers = 0; $supersMfa = 0; $names = [];
        foreach ($doc['users'] as $u) {
            $uid = (string)$u['id']; $names[clean_login($u['login'] ?? '')] = (string)$u['name'];
            if (($u['active'] ?? true) === false) continue;
            $active++;
            if (empty($withAcc[$uid]) && empty($pending[$uid])) $noAccess++;
            if (has_perm($doc, $uid, 'super')) { $supers++; foreach ($acc as $a) if ((string)($a['userId'] ?? '') === $uid && !empty($a['mfa']['on'])) $supersMfa++; }
        }
        $locked = [];
        foreach (array_keys(read_json($LOCKF)) as $l) {
            $l = (string)$l; $e = lock_state($LOCKF, $l, strpos($l, 'mfa:') === 0 ? 15 : null);
            if (!$e || (int)($e['until'] ?? 0) <= $now) continue;
            $login = strpos($l, 'mfa:') === 0 ? substr($l, 4) : $l;
            if (!isset($names[$login])) continue;                       // identifiant inconnu (essais au hasard) : seulement dans le journal
            $locked[] = ['login' => $login, 'name' => $names[$login], 'mfa' => strpos($l, 'mfa:') === 0, 'until' => (int)$e['until'] >= PHP_INT_MAX - 1 ? null : date('c', (int)$e['until'])];
        }
        $out['accounts'] = ['users' => count($doc['users']), 'active' => $active, 'withAccess' => count($acc), 'invites' => count($pending), 'noAccess' => $noAccess,
                            'mfa' => $mfa, 'supers' => $supers, 'supersMfa' => $supersMfa, 'locked' => $locked];
    }

    // --- sécurité : connexions et échecs par jour (90 jours), adresses bloquées, derniers événements sensibles
    if ($canSec) {
        $logins = array_fill(0, 90, 0); $fails = array_fill(0, 90, 0); $o = $DAYS - 90; $oldest = null; $recent = [];
        $notable = ['locked', 'blocked', 'restore', 'sec-set', 'settings', 'user-delete', 'mfa-reset', 'reset', 'sa-fail', 'sa-change', 'dossier-delete', 'purge', 'unblock', 'unlock', 'mfa-off', 'backup-get', 'web-cap', 'kick'];
        foreach (read_json("$DATA_DIR/auth-log.json") as $e) {
            if (!is_array($e)) continue;
            $oldest = $e['t'] ?? $oldest;
            $i = $di($e['t'] ?? null); $ev = (string)($e['ev'] ?? '');
            if ($i !== null && $i >= $o) {
                if (in_array($ev, ['login', 'signup'], true)) $logins[$i - $o]++;
                elseif (in_array($ev, ['fail', 'mfa-fail', 'sa-fail'], true)) $fails[$i - $o]++;
            }
            if (count($recent) < 8 && in_array($ev, $notable, true)) $recent[] = ['t' => $e['t'] ?? null, 'ev' => $ev, 'login' => cut((string)($e['login'] ?? ''), 80), 'info' => cut((string)($e['info'] ?? ''), 160)];
        }
        $ips = 0; foreach (read_json($FAILS) as $e) if (is_array($e) && (int)($e['n'] ?? 0) >= $MAX_FAILS && $now - (int)($e['t'] ?? 0) < $FAIL_WINDOW) $ips++;
        $out['security'] = ['logins' => $logins, 'fails' => $fails, 'logFrom' => $oldest, 'blockedIps' => $ips, 'recent' => $recent];

        // --- état du système
        $files = glob("$DATA_DIR/backups/complet-*.json") ?: [];
        $last = null; foreach ($files as $f) if (!$last || filemtime($f) > filemtime($last)) $last = $f;
        [, $next] = backup_slots();
        $out['system'] = ['mailReady' => mail_ready(), 'https' => $https, 'maintenance' => $MAINTENANCE, 'saDefined' => superadmin_hash() !== '',
            'mfaRequired' => $MFA_REQUIRED, 'resetFor' => $RESET_FOR, 'superLockAttempts' => $SUPER_LOCK_ATTEMPTS, 'superLockMinutes' => $SUPER_LOCK_MINUTES,
            'backup' => ['count' => count($files), 'last' => $last ? date('c', filemtime($last)) : null, 'next' => $AUTO_BACKUP_TIMES && $next ? date('c', $next) : null, 'auto' => (bool)$AUTO_BACKUP_TIMES],
            'purge' => read_json("$DATA_DIR/purge-state.json"), 'conservation' => (int)(read_settings()['conservation'] ?? 0),
            'dataBytes' => dir_bytes($DATA_DIR), 'php' => PHP_VERSION, 'build' => page_build()];
    }
    out(200, $out);
}

/* =============================================================================
   Formulaire en ligne : liens envoyés aux clients, demandes reçues (droit « web », ou droit du type pour envoyer un lien)
   ========================================================================== */
if ($action === 'form-link' || $action === 'web-link-revoke') {
    $in = body_json();
    $type = (string)($in['type'] ?? 'remb');
    if (!isset(WEB_FORMS[$type])) out(400, ['error' => 'Formulaire inconnu']);
    if (!has_any($doc, $uidMe, ['web', $type])) out(403, ['error' => 'Réservé aux personnes chargées de ce type de demande ou des formulaires en ligne.']);
    if ($action === 'web-link-revoke') {
        $k = (string)($in['key'] ?? '');
        flock($lock, LOCK_EX); $all = read_json($WEBL);
        if (isset($all[$k])) { $all[$k]['exp'] = time() - 1; write_json($WEBL, $all); }
        flock($lock, LOCK_UN);
        auth_log('web-link-revoke', $me['login'], (string)($all[$k]['email'] ?? ''));
        out(200, ['ok' => true]);
    }
    if (!web_enabled($type)) out(400, ['error' => 'Le formulaire en ligne « ' . WEB_FORMS[$type]['title'] . ' » est désactivé (Formulaires en ligne › Réglages).']);
    $email = strtolower(clean_text($in['email'] ?? '', 120));
    if ($email === '' || strpos($email, '@') === false || !filter_var($email, FILTER_VALIDATE_EMAIL)) out(400, ['error' => 'Adresse e-mail du client invalide.']);
    $dossierId = (string)($in['dossierId'] ?? '');
    if ($dossierId !== '') {
        if (!has_perm($doc, $uidMe, $type)) out(403, ['error' => 'Rattacher un lien à un dossier existant est réservé aux personnes qui traitent ce type de demande.']);
        $dd = read_dossier($dossierId); if (!$dd || $dd['type'] !== $type) out(400, ['error' => 'Dossier introuvable pour ce lien.']);
    }
    [$base, $public] = web_link_base();
    if ($base === '') out(400, ['error' => 'Renseignez l’adresse publique du formulaire (Formulaires en ligne › Réglages) ou l’adresse de l’application (Sécurité & accès).']);
    $c = read_settings();
    $days = max(1, min(90, (int)$c['webLinkDays']));
    $tok = bin2hex(random_bytes(16));
    flock($lock, LOCK_EX);
    $all = array_filter(read_json($WEBL), function ($x) { return is_array($x) && (int)($x['exp'] ?? 0) > time() - 30 * 86400; });   // liens expirés depuis 30 jours : oubliés
    $all[hash('sha256', $tok)] = ['type' => $type, 'email' => $email, 'nom' => clean_text($in['nom'] ?? '', 80), 'dossierId' => $dossierId, 'by' => $me['name'], 't' => time(), 'exp' => time() + $days * 86400];
    if (!write_json($WEBL, $all)) { flock($lock, LOCK_UN); out(500, ['error' => 'Écriture impossible']); }
    flock($lock, LOCK_UN);
    $url = $base . 'formulaire.html?t=' . $tok;
    $sent = null; $mailErr = null;
    if (!empty($in['send'])) {
        if (!mail_ready()) $mailErr = 'Envoi des e-mails non réglé : copiez le lien dans votre message.';
        else {
            $nom = clean_text($in['nom'] ?? '', 80);
            $mailErr = send_mail($email, WEB_FORMS[$type]['title'] . ' – formulaire à compléter – ' . $c['societe'],
                "Bonjour" . ($nom !== '' ? " $nom" : '') . ",\n\nPour traiter votre " . mb_strtolower(WEB_FORMS[$type]['title'], 'UTF-8') . ", merci de compléter ce formulaire en ligne (2 minutes) :\n$url\n\n"
                . "Ce lien est personnel, valable $days jours et utilisable une seule fois. Dès l'envoi du formulaire, vous recevrez un accusé de réception avec le récapitulatif de votre demande.\n\n"
                . "Pour votre sécurité, ne nous communiquez jamais votre numéro de carte complet ni le cryptogramme : les 4 derniers chiffres suffisent.\n\n" . web_signature() . "\n",
                $c['webNotify'] ?: $c['emailMonetique']);
            $sent = $mailErr === null ? $email : null;
        }
    }
    auth_log('web-link', $me['login'], "$email" . ($dossierId !== '' ? " · $dossierId" : '') . ($sent ? ' (envoyé par e-mail)' : ''));
    out(200, ['link' => $url, 'public' => $public, 'expires' => date('c', time() + $days * 86400), 'days' => $days, 'sent' => $sent, 'mailError' => $mailErr]);
}
if (in_array($action, ['web-inbox', 'web-resend', 'web-handled', 'web-links'], true)) {
    if (!has_perm($doc, $uidMe, 'web')) out(403, ['error' => 'Réservé aux gestionnaires des formulaires en ligne']);
    if ($action === 'web-inbox') {
        $list = [];
        flock($lock, LOCK_SH);
        foreach (glob("$DOS/*.json") ?: [] as $f) {
            $d = json_decode((string)file_get_contents($f), true);
            if (!is_array($d) || ($d['origin'] ?? '') !== 'web') continue;
            $list[] = dossier_summary($d) + ['web' => $d['web'] ?? [], 'v' => array_intersect_key((array)$d['v'], WEB_FORMS[$d['type']]['fields'] ?? []), 'recap' => web_recap($d, true)];
        }
        flock($lock, LOCK_UN);
        usort($list, function ($a, $b) { return strcmp((string)($b['web']['submittedAt'] ?? ''), (string)($a['web']['submittedAt'] ?? '')); });
        [$base, $public] = web_link_base();
        out(200, ['items' => array_slice($list, 0, 500), 'mailReady' => mail_ready(), 'base' => $base, 'public' => $public, 'settings' => array_intersect_key(read_settings(), array_flip(WEB_SETTINGS)),
                  'genericUrl' => $base !== '' ? $base . 'formulaire.html?f=remboursement' : '']);
    }
    if ($action === 'web-links') {
        $list = [];
        foreach (read_json($WEBL) as $k => $x) if (is_array($x)) $list[] = ['key' => $k, 'email' => (string)($x['email'] ?? ''), 'nom' => (string)($x['nom'] ?? ''), 'type' => (string)($x['type'] ?? ''), 'dossierId' => (string)($x['dossierId'] ?? ''),
            'by' => (string)($x['by'] ?? ''), 'created' => date('c', (int)($x['t'] ?? 0)), 'expires' => date('c', (int)($x['exp'] ?? 0)), 'used' => $x['used'] ?? null, 'active' => empty($x['used']) && (int)($x['exp'] ?? 0) > time()];
        usort($list, function ($a, $b) { return strcmp($b['created'], $a['created']); });
        out(200, ['links' => array_slice($list, 0, 300)]);
    }
    $in = body_json();
    $id = (string)($in['id'] ?? '');
    flock($lock, LOCK_EX);
    $d = read_dossier($id);
    if (!$d || ($d['origin'] ?? '') !== 'web') { flock($lock, LOCK_UN); out(404, ['error' => 'Demande en ligne introuvable.']); }
    if ($action === 'web-handled') {
        $d['web']['handled'] = !empty($in['handled']) ? ['t' => date('c'), 'by' => $me['name']] : false;
        $d['history'][] = ['t' => date('c'), 'by' => $me['name'], 'ev' => 'web', 'info' => !empty($in['handled']) ? 'demande en ligne prise en charge' : 'demande en ligne remise à traiter'];
        write_json((string)dossier_file($id), $d); flock($lock, LOCK_UN);
        out(200, ['ok' => true]);
    }
    flock($lock, LOCK_UN);
    if (empty($in['all'])) web_mails($d, true); else { $d['web']['mails'] = []; web_mails($d); }
    flock($lock, LOCK_EX); $cur = read_dossier($id) ?? $d; $cur['web']['mails'] = $d['web']['mails']; $cur['history'][] = ['t' => date('c'), 'by' => $me['name'], 'ev' => 'web', 'info' => 'renvoi des e-mails du formulaire en ligne'];
    write_json((string)dossier_file($id), $cur); flock($lock, LOCK_UN);
    auth_log('web-resend', $me['login'], $id);
    out(200, ['ok' => true, 'mails' => $d['web']['mails']]);
}

/* =============================================================================
   Administration : personnes, rôles, accès
   ========================================================================== */
if ($action === 'admin-users') {
    if (!has_any($doc, $uidMe, ['users', 'security'])) out(403, ['error' => 'Réservé aux gestionnaires des utilisateurs']);
    flock($lock, LOCK_SH);
    $acc = read_json($ACC); $now = time();
    $accounts = [];
    foreach ($acc as $login => $a) $accounts[(string)$a['userId']] = ['login' => (string)$login, 'created' => $a['created'] ?? null, 'lastLogin' => $a['lastLogin'] ?? null, 'mfa' => !empty($a['mfa']['on'])];
    $invites = [];
    foreach (read_json($INV) as $v) if (is_array($v) && (int)($v['exp'] ?? 0) > $now) $invites[(string)$v['userId']] = ['by' => (string)($v['by'] ?? ''), 'created' => date('c', (int)($v['t'] ?? 0)), 'expires' => date('c', (int)$v['exp'])];
    $locks = [];
    foreach (array_keys(read_json($LOCKF)) as $l) {
        $e = lock_state($LOCKF, (string)$l); if (!$e) continue;
        $locks[(string)$l] = ['n' => (int)$e['n'], 'last' => date('c', (int)($e['t'] ?? 0)), 'locked' => (int)($e['until'] ?? 0) > $now,
                             'until' => (int)($e['until'] ?? 0) >= PHP_INT_MAX - 1 ? null : date('c', (int)$e['until'])];
    }
    flock($lock, LOCK_UN);
    out(200, ['users' => array_map('public_user', $doc['users']), 'roles' => $doc['roles'], 'accounts' => $accounts, 'invites' => $invites, 'locks' => $locks,
              'perms' => ALL_PERMS, 'mailReady' => mail_ready(), 'inviteDays' => $INVITE_DAYS, 'me' => $uidMe, 'isSuper' => has_perm($doc, $uidMe, 'super')]);
}
if ($action === 'user-save' || $action === 'user-delete') {
    $in = body_json();
    if (!has_perm($doc, $uidMe, 'users')) out(403, ['error' => 'Réservé aux gestionnaires des utilisateurs']);
    $isSuperMe = has_perm($doc, $uidMe, 'super');
    flock($lock, LOCK_EX);
    $d = read_users();
    $id = (string)($in['id'] ?? '');
    $old = $id !== '' ? find_user($d, $id) : null;
    if ($id !== '' && !$old) { flock($lock, LOCK_UN); out(404, ['error' => 'Personne introuvable (supprimée entre-temps ?).']); }
    if ($old && is_super_role(find_role($d, $old['roleId'] ?? null)) && !$isSuperMe) { flock($lock, LOCK_UN); out(403, ['error' => 'La fiche d’un super administrateur ne peut être modifiée que par un super administrateur.']); }
    if ($old && outranks($d, $uidMe, $id)) { flock($lock, LOCK_UN); out(403, ['error' => 'Cette personne a des droits que vous n’avez pas : seul un super administrateur peut modifier sa fiche.']); }
    $acc = read_json($ACC);
    if ($action === 'user-delete') {
        if (!$old) { flock($lock, LOCK_UN); out(404, ['error' => 'Personne introuvable.']); }
        if ($id === $uidMe) { flock($lock, LOCK_UN); out(400, ['error' => 'Vous ne pouvez pas supprimer votre propre fiche.']); }
        $d['users'] = array_values(array_filter($d['users'], function ($u) use ($id) { return (string)$u['id'] !== $id; }));
        if (active_supers($d) < 1) { flock($lock, LOCK_UN); out(400, ['error' => 'Il doit rester au moins un super administrateur actif.']); }
        $acc = array_filter($acc, function ($a) use ($id) { return (string)($a['userId'] ?? '') !== $id; });
        $inv = array_filter(read_json($INV), function ($v) use ($id) { return (string)($v['userId'] ?? '') !== $id; });
        $d['version'] = (int)($d['version'] ?? 0) + 1;
        if (!write_json($USERS, $d) || !write_json($ACC, $acc) || !write_json($INV, $inv)) { flock($lock, LOCK_UN); out(500, ['error' => 'Écriture impossible']); }
        flock($lock, LOCK_UN);
        auth_log('user-delete', $me['login'], (string)$old['name'] . ' (' . $old['login'] . ')');
        out(200, ['ok' => true]);
    }
    $login = clean_login($in['login'] ?? '');
    if (!login_ok($login)) { flock($lock, LOCK_UN); out(400, ['error' => 'Identifiant : une adresse e-mail valide est attendue (ex. prenom.nom@d8.fr).']); }
    foreach ($d['users'] as $u) if ((string)$u['id'] !== $id && clean_login($u['login']) === $login) { flock($lock, LOCK_UN); out(409, ['error' => 'Cette adresse est déjà utilisée par ' . $u['name'] . '.']); }
    $name = clean_text($in['name'] ?? '', 80);
    if ($name === '') $name = name_from_login($login);
    $role = find_role($d, (string)($in['roleId'] ?? ''));
    if (!$role) { flock($lock, LOCK_UN); out(400, ['error' => 'Choisissez un rôle.']); }
    if (is_super_role($role) && !$isSuperMe) { flock($lock, LOCK_UN); out(403, ['error' => 'Seul un super administrateur peut nommer un super administrateur.']); }
    if (!$isSuperMe && array_diff(role_perms($role), eff_perms($d, $uidMe))) { flock($lock, LOCK_UN); out(403, ['error' => 'Ce rôle donne des droits que vous n’avez pas vous-même : seul un super administrateur peut l’attribuer.']); }
    $active = !array_key_exists('active', $in) || !empty($in['active']);
    if ($id === $uidMe && (!$active || (string)$role['id'] !== (string)$old['roleId'])) { flock($lock, LOCK_UN); out(400, ['error' => 'Vous ne pouvez pas désactiver votre propre fiche ni changer votre propre rôle.']); }
    $rec = ['login' => $login, 'name' => $name, 'roleId' => (string)$role['id'], 'active' => $active,
            'sigFonction' => clean_text($in['sigFonction'] ?? ($old['sigFonction'] ?? ''), 80), 'sigTel' => clean_text($in['sigTel'] ?? ($old['sigTel'] ?? ''), 40), 'updated' => date('c')];
    if ($old) {
        foreach ($d['users'] as &$u) if ((string)$u['id'] === $id) $u = array_merge($u, $rec);
        unset($u);
        // adresse changée : l'accès suit (sessions fermées) ; fiche désactivée : sessions fermées
        $oldLogin = clean_login($old['login']);
        if ($oldLogin !== $login && isset($acc[$oldLogin])) { $acc[$login] = $acc[$oldLogin]; unset($acc[$oldLogin]); $acc[$login]['stamp'] = bin2hex(random_bytes(8)); }
        if (!$active && isset($acc[$login])) $acc[$login]['stamp'] = bin2hex(random_bytes(8));
    } else {
        $id = 'u' . bin2hex(random_bytes(6));
        $d['users'][] = array_merge(['id' => $id, 'created' => date('c')], $rec);
    }
    if (active_supers($d) < 1) { flock($lock, LOCK_UN); out(400, ['error' => 'Il doit rester au moins un super administrateur actif.']); }
    $d['version'] = (int)($d['version'] ?? 0) + 1;
    if (!write_json($USERS, $d) || !write_json($ACC, $acc)) { flock($lock, LOCK_UN); out(500, ['error' => 'Écriture impossible']); }
    flock($lock, LOCK_UN);
    auth_log($old ? 'user-edit' : 'user-add', $me['login'], "$name ($login) · " . $role['name'] . ($active ? '' : ' · désactivée'));
    out(200, ['ok' => true, 'id' => $id]);
}
if ($action === 'role-save' || $action === 'role-delete') {
    $in = body_json();
    if (!has_perm($doc, $uidMe, 'users')) out(403, ['error' => 'Réservé aux gestionnaires des utilisateurs']);
    $isSuperMe = has_perm($doc, $uidMe, 'super');
    flock($lock, LOCK_EX);
    $d = read_users();
    $id = (string)($in['id'] ?? '');
    $old = $id !== '' ? find_role($d, $id) : null;
    if ($id !== '' && !$old) { flock($lock, LOCK_UN); out(404, ['error' => 'Rôle introuvable.']); }
    if ($old && is_super_role($old) && !$isSuperMe) { flock($lock, LOCK_UN); out(403, ['error' => 'Seul un super administrateur peut modifier le rôle super administrateur.']); }
    if ($action === 'role-delete') {
        if (!$old) { flock($lock, LOCK_UN); out(404, ['error' => 'Rôle introuvable.']); }
        if (is_super_role($old) && count(array_filter($d['roles'], 'is_super_role')) <= 1) { flock($lock, LOCK_UN); out(400, ['error' => 'Le rôle super administrateur ne peut pas être supprimé.']); }
        if (!$isSuperMe && array_diff(role_perms($old), eff_perms($d, $uidMe))) { flock($lock, LOCK_UN); out(403, ['error' => 'Ce rôle comporte des droits que vous n’avez pas : seul un super administrateur peut le supprimer.']); }
        foreach ($d['users'] as $u) if (($u['roleId'] ?? '') === $id) { flock($lock, LOCK_UN); out(400, ['error' => 'Rôle attribué à ' . $u['name'] . ' : changez d’abord son rôle.']); }
        $d['roles'] = array_values(array_filter($d['roles'], function ($r) use ($id) { return ($r['id'] ?? '') !== $id; }));
    } else {
        $name = clean_text($in['name'] ?? '', 60);
        if ($name === '') { flock($lock, LOCK_UN); out(400, ['error' => 'Donnez un nom au rôle.']); }
        $perms = array_values(array_intersect(ALL_PERMS, array_filter((array)($in['perms'] ?? []), 'is_string')));
        if (in_array('super', $perms, true)) $perms = ['super'];
        if ($old && is_super_role($old)) $perms = ['super'];                // le rôle super administrateur garde tous les droits
        if (in_array('super', $perms, true) && !$isSuperMe) { flock($lock, LOCK_UN); out(403, ['error' => 'Seul un super administrateur peut donner le droit super administrateur.']); }
        if (!$isSuperMe && array_diff($perms, eff_perms($d, $uidMe))) { flock($lock, LOCK_UN); out(403, ['error' => 'Vous ne pouvez pas donner des droits que vous n’avez pas vous-même.']); }
        if (!$isSuperMe && $old && array_diff(role_perms($old), eff_perms($d, $uidMe))) { flock($lock, LOCK_UN); out(403, ['error' => 'Ce rôle comporte des droits que vous n’avez pas : seul un super administrateur peut le modifier.']); }
        if ($old) { foreach ($d['roles'] as &$r) if (($r['id'] ?? '') === $id) { $r['name'] = $name; $r['perms'] = $perms; } unset($r); }
        else { $id = 'r_' . bin2hex(random_bytes(5)); $d['roles'][] = ['id' => $id, 'name' => $name, 'perms' => $perms]; }
    }
    $d['version'] = (int)($d['version'] ?? 0) + 1;
    if (!write_json($USERS, $d)) { flock($lock, LOCK_UN); out(500, ['error' => 'Écriture impossible']); }
    flock($lock, LOCK_UN);
    auth_log($action, $me['login'], ($old['name'] ?? $in['name'] ?? $id) . ($action === 'role-save' ? ' : ' . implode(', ', $perms ?? []) : ''));
    out(200, ['ok' => true, 'id' => $id]);
}

/* --- administrateurs : inviter une personne à créer son accès (code à usage unique, e-mail facultatif), lister, annuler --- */
if ($action === 'invite' || $action === 'invite-revoke') {
    $in = body_json();
    if (!has_perm($doc, $uidMe, 'users')) out(403, ['error' => 'Réservé aux gestionnaires des utilisateurs']);
    flock($lock, LOCK_EX);
    $now = time();
    $all = array_filter(read_json($INV), function ($v) use ($now) { return is_array($v) && (int)($v['exp'] ?? 0) > $now; });
    $uid = (string)($in['userId'] ?? '');
    $u = find_user($doc, $uid);
    if (!$u) { flock($lock, LOCK_UN); out(404, ['error' => 'Personne inconnue : enregistrez d’abord sa fiche.']); }
    if (has_perm($doc, $uid, 'super') && !has_perm($doc, $uidMe, 'super')) { flock($lock, LOCK_UN); out(403, ['error' => 'Seul un super administrateur peut inviter un super administrateur.']); }
    if (outranks($doc, $uidMe, $uid)) { flock($lock, LOCK_UN); out(403, ['error' => 'Cette personne a des droits que vous n’avez pas : seul un super administrateur peut l’inviter.']); }
    $all = array_filter($all, function ($v) use ($uid) { return (string)$v['userId'] !== $uid; });   // une seule invitation en cours par personne
    if ($action === 'invite-revoke') {
        write_json($INV, $all); flock($lock, LOCK_UN);
        auth_log('invite-revoke', $me['login'], (string)$u['name']);
        out(200, ['ok' => true]);
    }
    if (($u['active'] ?? true) === false) { flock($lock, LOCK_UN); out(400, ['error' => 'Fiche désactivée : réactivez-la avant d’inviter.']); }
    if (isset(read_json($ACC)[clean_login($u['login'])])) { flock($lock, LOCK_UN); out(409, ['error' => 'Cette personne a déjà un accès : réinitialisez-le d’abord si besoin.']); }
    $code = invite_code();
    $all[hash('sha256', invite_norm($code))] = ['userId' => $uid, 'name' => (string)$u['name'], 'by' => $me['name'], 't' => $now, 'exp' => $now + $INVITE_DAYS * 86400];
    if (!write_json($INV, $all)) { flock($lock, LOCK_UN); out(500, ['error' => 'Écriture impossible']); }
    flock($lock, LOCK_UN);
    $link = mail_cfg()['appUrl'] !== '' ? app_link('invitation', $code) : '';
    $sent = null; $err = null;
    if (!empty($in['send'])) {
        if (!mail_ready()) $err = 'Envoi des e-mails non réglé (Administration › Sécurité & accès) : transmettez le code vous-même.';
        else {
            $err = send_mail((string)$u['login'], 'Votre accès à l’outil Service clients D8',
                "Bonjour {$u['name']},\n\n{$me['name']} vous a ouvert un accès à l'outil Service clients D8 (réponses et fiches SAV, remboursements, commandes).\n\n"
                . "Votre identifiant : {$u['login']}\n\nPour choisir votre mot de passe, ouvrez ce lien (valable $INVITE_DAYS jours, une seule fois) :\n$link\n\n"
                . "Code d'invitation, si le lien ne s'ouvre pas : $code\n\nSi vous n'attendiez pas ce message, ignorez-le.\n");
            $sent = $err === null ? (string)$u['login'] : null;
        }
    }
    auth_log('invite', $me['login'], (string)$u['name'] . ($sent ? ' (envoyée par e-mail)' : ''));
    out(200, ['code' => $code, 'link' => $link, 'expires' => date('c', $now + $INVITE_DAYS * 86400), 'days' => $INVITE_DAYS, 'name' => (string)$u['name'], 'login' => (string)$u['login'], 'sent' => $sent, 'mailError' => $err]);
}

/* --- administrateurs : réinitialiser l'accès d'une personne (elle devra être réinvitée) --- */
if ($action === 'reset') {
    $in = body_json();
    $uid = (string)($in['userId'] ?? '');
    if (!has_perm($doc, $uidMe, 'users')) out(403, ['error' => 'Réservé aux gestionnaires des utilisateurs']);
    if ($uid === $uidMe) out(400, ['error' => 'Pour votre propre accès, utilisez « Changer mon mot de passe ».']);
    if (has_perm($doc, $uid, 'super') && !has_perm($doc, $uidMe, 'super')) out(403, ['error' => 'Seul un super administrateur peut agir sur l’accès d’un super administrateur.']);
    if (outranks($doc, $uidMe, $uid)) out(403, ['error' => 'Cette personne a des droits que vous n’avez pas : seul un super administrateur peut réinitialiser son accès.']);
    flock($lock, LOCK_EX);
    $acc = read_json($ACC);
    $n = count($acc);
    $acc = array_filter($acc, function ($a) use ($uid) { return (string)($a['userId'] ?? '') !== $uid; });
    if (count($acc) !== $n && !write_json($ACC, $acc)) out(500, ['error' => 'Écriture impossible']);
    flock($lock, LOCK_UN);
    auth_log('reset', $me['login'], (string)(find_user($doc, $uid)['name'] ?? $uid));
    out(200, ['ok' => true, 'removed' => $n - count($acc)]);
}

/* --- administrateurs : réinitialiser la double authentification d'une personne (téléphone perdu) --- */
if ($action === 'mfa-reset') {
    $in = body_json();
    $uid = (string)($in['userId'] ?? '');
    if (!has_perm($doc, $uidMe, 'users')) out(403, ['error' => 'Réservé aux gestionnaires des utilisateurs']);
    // super administrateur : par un super administrateur, ou avec le mot de passe super administrateur
    if (has_perm($doc, $uid, 'super') && !has_perm($doc, $uidMe, 'super') && !$saOK)
        out(403, ['superadmin' => true, 'error' => 'Réinitialiser la double authentification d’un super administrateur exige le mot de passe super administrateur.']);
    if (!has_perm($doc, $uid, 'super') && outranks($doc, $uidMe, $uid)) out(403, ['error' => 'Cette personne a des droits que vous n’avez pas : seul un super administrateur peut réinitialiser sa double authentification.']);
    flock($lock, LOCK_EX);
    $acc = read_json($ACC); $n = 0;
    foreach ($acc as $l => $a) if ((string)($a['userId'] ?? '') === $uid && isset($a['mfa'])) {
        unset($acc[$l]['mfa']); $acc[$l]['stamp'] = bin2hex(random_bytes(8)); $n++;       // ses sessions ouvertes sont fermées
    }
    if ($n) write_json($ACC, $acc);
    flock($lock, LOCK_UN);
    auth_log('mfa-reset', $me['login'], (string)(find_user($doc, $uid)['name'] ?? $uid));
    out(200, ['ok' => true, 'removed' => $n]);
}

/* --- déconnecter une personne (gestion des utilisateurs) ou tout le monde (super administrateur) --- */
if ($action === 'kick' || $action === 'kick-all') {
    $in = body_json();
    $uid = (string)($in['userId'] ?? '');
    if ($action === 'kick' && !has_perm($doc, $uidMe, 'users')) out(403, ['error' => 'Réservé aux gestionnaires des utilisateurs']);
    if ($action === 'kick-all' && !has_perm($doc, $uidMe, 'super')) out(403, ['error' => 'Réservé au super administrateur']);
    if ($action === 'kick' && has_perm($doc, $uid, 'super') && !has_perm($doc, $uidMe, 'super')) out(403, ['error' => 'Seul un super administrateur peut déconnecter un super administrateur.']);
    if ($action === 'kick' && $uid === $uidMe) out(400, ['error' => 'Utilisez « Se déconnecter ».']);
    if ($action === 'kick' && outranks($doc, $uidMe, $uid)) out(403, ['error' => 'Cette personne a des droits que vous n’avez pas : seul un super administrateur peut la déconnecter.']);
    flock($lock, LOCK_EX);
    $acc = read_json($ACC); $n = 0;
    foreach ($acc as $login => $a) {
        if ($login === $me['login'] || ($action === 'kick' && (string)($a['userId'] ?? '') !== $uid)) continue;
        $acc[$login]['stamp'] = bin2hex(random_bytes(8)); $n++;
    }
    if ($n && !write_json($ACC, $acc)) out(500, ['error' => 'Écriture impossible']);
    flock($lock, LOCK_UN);
    auth_log($action, $me['login'], $action === 'kick' ? (string)(find_user($doc, $uid)['name'] ?? $uid) : $n . ' accès');
    out(200, ['ok' => true, 'sessions' => $n]);
}

/* --- comptes bloqués : déblocage (gestion des utilisateurs ou sécurité) --- */
if ($action === 'unlock-login') {
    $in = body_json();
    if (!has_any($doc, $uidMe, ['users', 'security'])) out(403, ['error' => 'Réservé aux gestionnaires des utilisateurs']);
    $l = clean_login($in['login'] ?? '');
    $t = super_target($doc, read_json($ACC), $l);
    if ($t && !has_perm($doc, $uidMe, 'super')) out(403, ['error' => 'Seul un super administrateur peut débloquer un super administrateur.']);
    $acL = read_json($ACC)[$l] ?? null;
    if (is_array($acL) && outranks($doc, $uidMe, (string)$acL['userId'])) out(403, ['error' => 'Cette personne a des droits que vous n’avez pas : seul un super administrateur peut la débloquer.']);
    flock($lock, LOCK_EX); lock_clear($LOCKF, $l); lock_clear($LOCKF, 'mfa:' . $l); flock($lock, LOCK_UN);
    auth_log('unlock', $me['login'], $l);
    out(200, ['ok' => true]);
}

/* --- super administrateur : e-mail de test --- */
if ($action === 'mail-test') {
    body_json();
    if (!has_perm($doc, $uidMe, 'super')) out(403, ['error' => 'Réservé au super administrateur']);
    $to = (string)$me['login'];
    $err = send_mail($to, 'E-mail de test – Service clients D8', "Bonjour,\n\nCet e-mail confirme que l'outil Service clients D8 peut envoyer des messages (invitations, mots de passe oubliés, alertes de connexion).\n\nLien de l'application : " . mail_cfg()['appUrl'] . "\n");
    auth_log($err ? 'mail-fail' : 'mail-test', $me['login'], $err ?: 'envoyé à ' . $to);
    if ($err) out(502, ['error' => $err]);
    out(200, ['ok' => true, 'to' => $to]);
}

/* =============================================================================
   Sécurité : journal, adresses bloquées, sauvegardes, réglages
   ========================================================================== */
if (in_array($action, ['auth-log', 'fails', 'unblock', 'backups', 'backup-get', 'backup-now', 'sec-get', 'sec-set', 'restore', 'purge-now'], true)) {
    if (!has_perm($doc, $uidMe, 'security')) out(403, ['error' => 'Réservé aux responsables de la sécurité']);
    $isSuper = has_perm($doc, $uidMe, 'super');
    $bdir = "$DATA_DIR/backups";
    if ($action === 'auth-log') out(200, ['events' => array_slice(read_json("$DATA_DIR/auth-log.json"), 0, 1000)]);
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
        foreach ((is_dir($bdir) ? (glob("$bdir/complet-*.json") ?: []) : []) as $f)
            $files[] = ['name' => basename($f), 'size' => filesize($f), 'date' => date('c', filemtime($f))];
        usort($files, function ($a, $b) { return strcmp($b['date'], $a['date']) ?: strcmp($b['name'], $a['name']); });
        [, $n] = backup_slots();
        out(200, ['files' => $files, 'auto' => ['times' => $AUTO_BACKUP_TIMES, 'days' => $AUTO_BACKUP_DAYS, 'keep' => $AUTO_BACKUP_KEEP, 'state' => read_json("$DATA_DIR/backup-state.json"),
                  'next' => $AUTO_BACKUP_TIMES && $n ? date('c', $n) : null, 'tz' => date_default_timezone_get()],
                  'purge' => read_json("$DATA_DIR/purge-state.json"), 'count' => count(glob("$DOS/*.json") ?: []), 'dataDir' => $isSuper ? realpath($DATA_DIR) : null]);
    }
    if ($action === 'backup-get') {
        // contient les mots de passe hachés, les clés de double authentification et le mot de passe SMTP
        if (!$isSuper) out(403, ['error' => 'Le téléchargement d’une sauvegarde complète est réservé au super administrateur.']);
        if (!$saOK) out(403, ['superadmin' => true, 'error' => 'Une sauvegarde complète contient les accès de tout le monde : son téléchargement exige le mot de passe super administrateur.']);
        $name = basename((string)($_GET['f'] ?? ''));
        if (!preg_match('/^complet-[\w-]+\.json$/', $name) || !is_file("$bdir/$name")) out(404, ['error' => 'Sauvegarde introuvable']);
        auth_log('backup-get', $me['login'], $name);
        header('Content-Disposition: attachment; filename="' . $name . '"');
        http_response_code(200); readfile("$bdir/$name"); exit;
    }
    if ($action === 'backup-now') {
        body_json();
        flock($lock, LOCK_SH);
        $name = full_backup('manuelle');
        flock($lock, LOCK_UN);
        if (!$name) out(500, ['error' => 'Copie impossible']);
        auth_log('backup-now', $me['login'], $name);
        out(200, ['ok' => true, 'name' => $name]);
    }
    if ($action === 'purge-now') {
        body_json();
        if (!$isSuper) out(403, ['error' => 'Réservé au super administrateur']);
        $n = purge_tick(true);
        out(200, ['ok' => true, 'removed' => (int)$n]);
    }
    if ($action === 'restore') {
        // remplacement complet des données : super administrateur ET mot de passe super administrateur retapé
        if (!$isSuper) out(403, ['error' => 'Réservé au super administrateur']);
        if (!$saOK) out(403, ['superadmin' => true, 'error' => 'La restauration exige le mot de passe super administrateur.']);
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') === false || !same_origin()) out(400, ['error' => 'Requête invalide']);
        $raw = (string)file_get_contents('php://input', false, null, 0, $MAX_BYTES + 1);
        if (strlen($raw) > $MAX_BYTES) out(413, ['error' => 'Sauvegarde trop volumineuse']);
        $b = json_decode($raw, true);
        if (!is_array($b) || ($b['kind'] ?? '') !== 'd8-service-clients' || !isset($b['users']['users'], $b['users']['roles']) || !is_array($b['dossiers'] ?? null))
            out(400, ['error' => 'Ce fichier n’est pas une sauvegarde complète de l’outil Service clients.']);
        flock($lock, LOCK_EX);
        $before = full_backup('avant-restauration');
        if (!$before) { flock($lock, LOCK_UN); out(500, ['error' => 'Copie de sécurité préalable impossible : restauration annulée.']); }
        $ok = write_json($USERS, $b['users']) && write_json($ACC, (array)($b['accounts'] ?? [])) && write_json($SECF, (array)($b['security'] ?? []))
           && write_json($SETF, (array)($b['settings'] ?? [])) && write_json($INV, (array)($b['invites'] ?? []));
        if ($ok && is_array($b['superadmin'] ?? null) && !empty($b['superadmin']['hash'])) $ok = write_json($SAF, $b['superadmin']);
        if ($ok) {
            foreach (glob("$DOS/*.json") ?: [] as $f) @unlink($f);
            foreach ($b['dossiers'] as $d) if (is_array($d) && ($f = dossier_file((string)($d['id'] ?? ''))) && in_array($d['type'] ?? '', TYPES, true)) $ok = write_json($f, $d) && $ok;
        }
        flock($lock, LOCK_UN);
        auth_log('restore', $me['login'], ($b['created'] ?? '?') . ' · ' . count($b['dossiers']) . ' dossier(s) · copie préalable ' . $before);
        if (!$ok) out(500, ['error' => 'Restauration incomplète : la copie ' . $before . ' permet de revenir en arrière.']);
        out(200, ['ok' => true, 'before' => $before, 'dossiers' => count($b['dossiers'])]);
    }
    if ($action === 'sec-get') {
        if ($isSuper && empty($SEC['cronKey'])) { $SEC['cronKey'] = bin2hex(random_bytes(20)); write_json($SECF, $SEC); }
        out(200, ['inviteDays' => $INVITE_DAYS, 'sessionHours' => (int)round($SESSION_IDLE / 3600),
            'minPassword' => $MIN_PASSWORD, 'pwDigit' => $PW_DIGIT, 'pwSpecial' => $PW_SPECIAL, 'pwUpper' => $PW_UPPER,
            'lockAttempts' => $LOCK_ATTEMPTS, 'lockMinutes' => $LOCK_MINUTES,
            'backupTimes' => $AUTO_BACKUP_TIMES, 'backupDays' => $AUTO_BACKUP_DAYS, 'backupKeep' => $AUTO_BACKUP_KEEP,
            'cronKey' => $isSuper ? (string)($SEC['cronKey'] ?? '') : '',
            'smtpHost' => (string)($SEC['smtpHost'] ?? ''), 'smtpPort' => (int)($SEC['smtpPort'] ?? 0), 'smtpSecure' => (string)($SEC['smtpSecure'] ?? 'tls'),
            'smtpUser' => $isSuper ? (string)($SEC['smtpUser'] ?? '') : '', 'smtpPassSet' => ($SEC['smtpPass'] ?? '') !== '',
            'mailFrom' => (string)($SEC['mailFrom'] ?? ''), 'mailFromName' => (string)($SEC['mailFromName'] ?? 'Service clients D8'), 'appUrl' => (string)($SEC['appUrl'] ?? ''),
            'mailReady' => mail_ready(), 'resetMinutes' => $RESET_MINUTES, 'resetFor' => $RESET_FOR,
            'superLockAttempts' => $SUPER_LOCK_ATTEMPTS, 'superLockMinutes' => $SUPER_LOCK_MINUTES, 'superLockMail' => $SUPER_LOCK_MAIL,
            'mfaRequired' => $MFA_REQUIRED, 'mfaCount' => count(array_filter(read_json($ACC), function ($a) { return !empty($a['mfa']['on']); })), 'accCount' => count(read_json($ACC)),
            'maxFails' => $MAX_FAILS, 'failWindow' => $FAIL_WINDOW, 'https' => $https, 'super' => $isSuper, 'maintenance' => $MAINTENANCE,
            'saDefined' => superadmin_hash() !== '', 'saChanged' => read_json($SAF)['changed'] ?? null, 'php' => PHP_VERSION]);
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
                'resetFor' => in_array($in['resetFor'] ?? '', ['all', 'super', 'none'], true) ? $in['resetFor'] : $RESET_FOR,
                'mfaRequired' => in_array($in['mfaRequired'] ?? '', ['none', 'super', 'admin', 'all'], true) ? $in['mfaRequired'] : $MFA_REQUIRED,
                'maintenance' => !empty($in['maintenance']),
                'backupTimes' => array_slice($times, 0, 12), 'backupDays' => $days ?: [1, 2, 3, 4, 5, 6, 7], 'backupKeep' => max(2, min(500, (int)($in['backupKeep'] ?? 60))),
                'changed' => date('c'), 'by' => $me['name']]);
        if (!empty($in['newCronKey'])) $new['cronKey'] = bin2hex(random_bytes(20));
        // envoi des e-mails (le mot de passe SMTP n'est remplacé que s'il est ressaisi)
        $clean = function ($v, int $n): string { return cut(trim(str_replace(["\r", "\n", "\0"], '', (string)$v)), $n); };
        if (array_key_exists('smtpHost', $in)) {
            $new['smtpHost'] = (string)preg_replace('/[^A-Za-z0-9.\-]/', '', $clean($in['smtpHost'], 120));
            $new['smtpPort'] = max(0, min(65535, (int)($in['smtpPort'] ?? 0)));
            $new['smtpSecure'] = in_array($in['smtpSecure'] ?? '', ['none', 'tls', 'ssl'], true) ? $in['smtpSecure'] : 'tls';
            $new['smtpUser'] = $clean($in['smtpUser'] ?? '', 120);
            if ((string)($in['smtpPass'] ?? '') !== '') $new['smtpPass'] = cut((string)$in['smtpPass'], 200);
            if (!empty($in['smtpPassClear'])) $new['smtpPass'] = '';
            $from = $clean($in['mailFrom'] ?? '', 120);
            if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) out(400, ['error' => 'Adresse d’expédition invalide.']);
            $new['mailFrom'] = $from;
            $new['mailFromName'] = $clean($in['mailFromName'] ?? 'Service clients D8', 60) ?: 'Service clients D8';
            $url = $clean($in['appUrl'] ?? '', 300);
            if ($url !== '' && !preg_match('#^https?://[^\s"<>]+$#i', $url)) out(400, ['error' => 'Adresse de l’application invalide (http://… ou https://…).']);
            $new['appUrl'] = (string)preg_replace('/[?#].*$/', '', $url);
        }
        if (!write_json($SECF, $new)) out(500, ['error' => 'Écriture impossible']);
        auth_log('sec-set', $me['login'], 'mots de passe ' . $new['minPassword'] . ' car.' . ($new['pwDigit'] ? ' + chiffre' : '') . ($new['pwSpecial'] ? ' + spécial' : '') . ($new['pwUpper'] ? ' + majuscule' : '')
            . ' · blocage après ' . $new['lockAttempts'] . ' essais (' . ($new['lockMinutes'] ?: '∞') . ' min), super admin ' . ($new['superLockAttempts'] ?: 'jamais') . ' · sauvegardes ' . (implode(', ', $new['backupTimes']) ?: 'désactivées')
            . ' · double authentification : ' . $new['mfaRequired'] . ' · session ' . $new['sessionHours'] . ' h · invitations ' . $new['inviteDays'] . ' j' . ($new['maintenance'] ? ' · MAINTENANCE' : ''));
        out(200, ['ok' => true]);
    }
}

/* --- état : version de la page, maintenance --- */
if ($action === 'ping') out(200, ['ok' => true, 'auth' => (bool)$me, 'build' => page_build(), 'maintenance' => $MAINTENANCE] + ($me ? ['php' => PHP_VERSION] : []));

out(400, ['error' => 'Action inconnue']);
