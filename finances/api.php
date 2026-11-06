<?php
/**
 * Boussole — finances du foyer · API
 * -----------------------------------------------------------------------------
 * Déposez le dossier « finances » (index.html, api.php, lib/, assets/, vendor/, .htaccess,
 * web.config) sur un serveur web avec PHP 7.4 ou plus et l'extension pdo_sqlite.
 * Ouvrez ensuite https://votre-serveur/finances/ : l'écran de première mise en service apparaît.
 *
 * Le dossier data/ est créé au premier lancement. Il contient la base (boussole.sqlite), les
 * documents CHIFFRÉS (vault/), la clé de chiffrement (secret.key) et les sessions.
 * Sauvegardez-le entièrement, clé comprise : sans secret.key, les documents sont illisibles.
 *
 * SÉCURITÉ — à lire avant la mise en service :
 *   1. Dès que l'outil est accessible hors de chez vous : HTTPS obligatoire ($REQUIRE_HTTPS = true).
 *   2. Placez data/ hors de la racine web si possible ($DATA_DIR). Sinon, l'application vérifie
 *      elle-même que data/ n'est pas lisible depuis le navigateur et affiche une alerte rouge si c'est le cas.
 *   3. Chaque personne a son propre mot de passe et son code de secours. Personne ne voit le détail
 *      des opérations de l'autre sans son accord (Réglages › Confidentialité).
 *   4. N'hébergez pas vos finances personnelles sur le serveur ou le NAS de votre employeur.
 * -----------------------------------------------------------------------------
 */
declare(strict_types=1);

/* ---------------------------------------------------------------- RÉGLAGES */
$DATA_DIR      = __DIR__ . '/data';   // idéalement hors de la racine web : '/var/boussole-data' ou 'D:\\boussole-data'
$TIMEZONE      = 'Europe/Paris';
$SESSION_IDLE  = 30 * 60;             // déconnexion après 30 minutes sans activité
$MAX_UPLOAD    = 25 * 1024 * 1024;    // taille maximale d'un fichier envoyé (dans la limite de PHP : upload_max_filesize)
$MIN_PASSWORD  = 10;                  // longueur minimale des mots de passe
$LOCK_ATTEMPTS = 5;                   // compte bloqué après N mots de passe erronés…
$LOCK_MINUTES  = 15;                  // …pendant N minutes
$MAX_FAILS_IP  = 20;                  // échecs de connexion tolérés par adresse IP sur 15 minutes
$REQUIRE_HTTPS = false;               // true : refuse tout accès sans HTTPS (indispensable sur Internet)
$MAX_USERS     = 8;                   // nombre maximal de personnes dans le foyer
$ALLOWED_NETS  = ['tout'];            // qui peut ouvrir l'application : ['tout'] ; ['local'] = réseau de la maison et VPN Tailscale ;
                                      // ou des plages précises : ['local', '203.0.113.7', '2001:db8::/48']
/* -------------------------------------------------------------------------- */
// Réglages propres à CETTE installation (écrits par l'installeur Windows, jamais publiés) : ils remplacent ceux du dessus.
if (is_file(__DIR__ . '/config.local.php')) require __DIR__ . '/config.local.php';

define('BOUSSOLE', '1.0.0');
if (PHP_VERSION_ID < 70400) { header('Content-Type: application/json; charset=utf-8'); http_response_code(500); echo '{"error":"PHP 7.4 ou plus est nécessaire (version actuelle : ' . PHP_VERSION . ')."}'; exit; }
if (!@date_default_timezone_set($TIMEZONE)) date_default_timezone_set('Europe/Paris');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

require __DIR__ . '/lib/util.php';
// filtrage réseau : sur le serveur de la maison, rien ne répond aux appareils extérieurs, même si un port est ouvert par erreur
if (!net_allowed((string)($_SERVER['REMOTE_ADDR'] ?? ''), (array)$ALLOWED_NETS, (string)($_SERVER['LOCAL_ADDR'] ?? $_SERVER['SERVER_ADDR'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['error' => 'Accès refusé : Boussole n’accepte que les appareils de la maison (adresse vue par le serveur : ' . ip_clean((string)($_SERVER['REMOTE_ADDR'] ?? '?'))
        . '). Pour y accéder de l’extérieur, passez par un VPN ou ajoutez l’adresse dans $ALLOWED_NETS (fichier config.local.php).', 'code' => 'reseau'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
require __DIR__ . '/lib/categorize.php';
require __DIR__ . '/lib/import.php';
require __DIR__ . '/lib/legal.php';
require __DIR__ . '/lib/analyse.php';
require __DIR__ . '/lib/alertes.php';
require __DIR__ . '/lib/vault.php';
require __DIR__ . '/lib/mail.php';
require __DIR__ . '/lib/zip.php';

class UserError extends RuntimeException {}

function out(int $code, array $body): void { http_response_code($code); echo json_enc($body); exit; }
function fail(int $code, string $msg, array $extra = []): void { out($code, ['error' => $msg] + $extra); }

set_exception_handler(function (Throwable $e) {
    global $DATA_DIR;
    if ($e instanceof UserError) fail($e->getCode() ?: 400, $e->getMessage());
    @file_put_contents($DATA_DIR . '/erreurs.log', date('c') . ' ' . get_class($e) . ' : ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n", FILE_APPEND | LOCK_EX);
    fail(500, 'Erreur interne du serveur. Le détail est noté dans data/erreurs.log.');
});

/* ========================================================================== */
/* Dossier de données, base, sessions                                          */
/* ========================================================================== */

function ensure_data(string $dir): void {
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) fail(500, 'Impossible de créer le dossier « data » : donnez au serveur web le droit d’écrire dans le dossier de l’application (ou réglez $DATA_DIR dans api.php).');
    foreach (['vault', 'sessions', 'tmp'] as $s) if (!is_dir($dir . '/' . $s)) @mkdir($dir . '/' . $s, 0750, true);
    $files = [
        '.htaccess' => "# Boussole : ce dossier ne doit jamais être servi par le serveur web\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order deny,allow\n  Deny from all\n</IfModule>\n",
        'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><security><requestFiltering><fileExtensions allowUnlisted=\"false\" /></requestFiltering></security></system.webServer></configuration>\n",
        'index.html' => '',
        'probe.txt' => 'boussole-data-exposed',
    ];
    foreach ($files as $f => $c) if (!is_file($dir . '/' . $f)) @file_put_contents($dir . '/' . $f, $c);
}
ensure_data($DATA_DIR);

function db(): PDO {
    static $pdo = null;
    global $DATA_DIR;
    if ($pdo !== null) return $pdo;
    if (!extension_loaded('pdo_sqlite')) fail(500, 'L’extension PHP « pdo_sqlite » est nécessaire : activez-la dans la configuration de PHP (php.ini ou panneau de l’hébergeur).');
    $pdo = new PDO('sqlite:' . $DATA_DIR . '/boussole.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('PRAGMA busy_timeout = 8000');
    $pdo->exec('PRAGMA foreign_keys = ON');
    migrate($pdo);
    return $pdo;
}

function migrate(PDO $db): void {
    $db->exec('CREATE TABLE IF NOT EXISTS meta (k TEXT PRIMARY KEY, v TEXT NOT NULL)');
    $v = (int)($db->query("SELECT v FROM meta WHERE k = 'schema'")->fetchColumn() ?: 0);
    if ($v < 1) {
    $db->exec("
        CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, login TEXT NOT NULL UNIQUE, name TEXT NOT NULL, pw TEXT NOT NULL DEFAULT '',
            invite TEXT NOT NULL DEFAULT '', invite_exp INTEGER NOT NULL DEFAULT 0, recovery TEXT NOT NULL DEFAULT '', profile TEXT NOT NULL DEFAULT '{}',
            fails INTEGER NOT NULL DEFAULT 0, locked_until INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL, last_login INTEGER NOT NULL DEFAULT 0);
        CREATE TABLE IF NOT EXISTS statements (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            bank TEXT NOT NULL DEFAULT '', account TEXT NOT NULL DEFAULT '', period_start TEXT, period_end TEXT, opening INTEGER, closing INTEGER,
            sum_debit INTEGER NOT NULL DEFAULT 0, sum_credit INTEGER NOT NULL DEFAULT 0, check_status TEXT NOT NULL DEFAULT 'inconnu', check_detail TEXT NOT NULL DEFAULT '',
            source TEXT NOT NULL DEFAULT '', filename TEXT NOT NULL DEFAULT '', doc_id INTEGER, n_new INTEGER NOT NULL DEFAULT 0, n_dup INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL);
        CREATE TABLE IF NOT EXISTS tx (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, statement_id INTEGER,
            date TEXT NOT NULL, amount INTEGER NOT NULL, label TEXT NOT NULL, norm TEXT NOT NULL, merchant TEXT NOT NULL, kind TEXT NOT NULL, category TEXT NOT NULL,
            nature TEXT NOT NULL, fee TEXT NOT NULL DEFAULT '', flags TEXT NOT NULL DEFAULT '', common INTEGER NOT NULL DEFAULT 0, manual INTEGER NOT NULL DEFAULT 0,
            note TEXT NOT NULL DEFAULT '', fp TEXT NOT NULL, created_at INTEGER NOT NULL, UNIQUE(user_id, fp));
        CREATE INDEX IF NOT EXISTS tx_ud ON tx(user_id, date);
        CREATE TABLE IF NOT EXISTS rules (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, pattern TEXT NOT NULL,
            sens TEXT NOT NULL DEFAULT '', category TEXT NOT NULL, nature TEXT NOT NULL DEFAULT '', common INTEGER, created_at INTEGER NOT NULL);
        CREATE TABLE IF NOT EXISTS budgets (user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, category TEXT NOT NULL, monthly INTEGER NOT NULL, PRIMARY KEY (user_id, category));
        CREATE TABLE IF NOT EXISTS subs (user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, mkey TEXT NOT NULL, state TEXT NOT NULL, t INTEGER NOT NULL, PRIMARY KEY (user_id, mkey));
        CREATE TABLE IF NOT EXISTS alerts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, cle TEXT NOT NULL, niveau TEXT NOT NULL,
            type TEXT NOT NULL, titre TEXT NOT NULL, texte TEXT NOT NULL, mois TEXT, data TEXT NOT NULL DEFAULT '{}', statut TEXT NOT NULL DEFAULT 'ouverte',
            created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL, UNIQUE(user_id, cle));
        CREATE TABLE IF NOT EXISTS docs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, shared INTEGER NOT NULL DEFAULT 0,
            type TEXT NOT NULL, title TEXT NOT NULL, doc_date TEXT, period TEXT, expiry TEXT, keep_until TEXT, amount INTEGER, meta TEXT NOT NULL DEFAULT '{}',
            fname TEXT NOT NULL, mime TEXT NOT NULL, size INTEGER NOT NULL, store TEXT NOT NULL, sha TEXT NOT NULL, created_at INTEGER NOT NULL);
        CREATE TABLE IF NOT EXISTS events (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, ev TEXT NOT NULL, ip TEXT NOT NULL DEFAULT '', ua TEXT NOT NULL DEFAULT '', info TEXT NOT NULL DEFAULT '', t INTEGER NOT NULL);
        CREATE TABLE IF NOT EXISTS throttle (ip TEXT PRIMARY KEY, n INTEGER NOT NULL, t INTEGER NOT NULL);
    ");
    $db->exec("INSERT OR REPLACE INTO meta (k, v) VALUES ('schema', '1')");
    $v = 1;
    }
    if ($v < 2) {
        // identifiant = adresse e-mail, lien « mot de passe oublié », alertes envoyées par e-mail
        $cols = array_column($db->query('PRAGMA table_info(users)')->fetchAll(), 'name');
        if (!in_array('email', $cols, true)) $db->exec("ALTER TABLE users ADD COLUMN email TEXT NOT NULL DEFAULT ''");
        if (!in_array('reset_hash', $cols, true)) $db->exec("ALTER TABLE users ADD COLUMN reset_hash TEXT NOT NULL DEFAULT ''");
        if (!in_array('reset_exp', $cols, true)) $db->exec("ALTER TABLE users ADD COLUMN reset_exp INTEGER NOT NULL DEFAULT 0");
        $acols = array_column($db->query('PRAGMA table_info(alerts)')->fetchAll(), 'name');
        if (!in_array('mailed', $acols, true)) $db->exec("ALTER TABLE alerts ADD COLUMN mailed INTEGER NOT NULL DEFAULT 0");
        $db->exec('UPDATE alerts SET mailed = 1');
        $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS users_email ON users(email) WHERE email <> ''");
        $db->exec("INSERT OR REPLACE INTO meta (k, v) VALUES ('schema', '2')");
    }
}

function is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}
if ($REQUIRE_HTTPS && !is_https()) fail(403, 'Connexion non sécurisée : ouvrez l’application en https://');

function client_ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45); }
function ua(): string { return clean_str($_SERVER['HTTP_USER_AGENT'] ?? '', 160); }

function session_open(): void {
    global $DATA_DIR;
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_save_path($DATA_DIR . '/sessions');
    session_name('BOUSSOLE');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', '86400');
    $path = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/') . '/';
    session_set_cookie_params(['lifetime' => 0, 'path' => $path, 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Strict']);
    session_start();
}

function ev(?int $uid, string $ev, string $info = ''): void {
    db()->prepare('INSERT INTO events (user_id, ev, ip, ua, info, t) VALUES (?, ?, ?, ?, ?, ?)')->execute([$uid, $ev, client_ip(), ua(), cut($info, 300), time()]);
}

/** Corps JSON d'une requête POST (un formulaire d'un autre site ne peut pas l'envoyer). */
function body(): array {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fail(405, 'POST attendu.');
    if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') === false) fail(415, 'JSON attendu.');
    $raw = (string)file_get_contents('php://input', false, null, 0, 8 * 1024 * 1024);
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}
function need_post(): void { if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fail(405, 'POST attendu.'); }

/* ========================================================================== */
/* Utilisateurs, profil, foyer                                                 */
/* ========================================================================== */

function slug(string $name): string { return strtolower(str_replace(' ', '', norm_text($name))); }
function new_code(int $n = 12): string {
    $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $s = '';
    for ($i = 0; $i < $n; $i++) $s .= $abc[random_int(0, strlen($abc) - 1)];
    return implode('-', str_split($s, 4));
}
function code_norm(string $c): string { return strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', $c)); }
function pw_problem(string $pw, string $name): ?string {
    global $MIN_PASSWORD;
    $len = function_exists('mb_strlen') ? mb_strlen($pw, 'UTF-8') : strlen($pw);
    if ($len < $MIN_PASSWORD) return 'Mot de passe trop court : ' . $MIN_PASSWORD . ' caractères au moins (une phrase courte fonctionne très bien).';
    if (stripos($pw, slug($name)) !== false && strlen(slug($name)) >= 3) return 'Le mot de passe ne doit pas contenir votre prénom.';
    if (count(array_unique(str_split($pw))) < 5) return 'Mot de passe trop répétitif.';
    if (preg_match('/^(azerty|qwerty|password|motdepasse|123456|000000|abcdef)/i', $pw)) return 'Mot de passe trop courant.';
    return null;
}

function users(): array { return db()->query('SELECT * FROM users ORDER BY id')->fetchAll(); }
function user_by_id(int $id): ?array { $s = db()->prepare('SELECT * FROM users WHERE id = ?'); $s->execute([$id]); $u = $s->fetch(); return $u ?: null; }
function partner_of(int $uid): ?array { $s = db()->prepare('SELECT * FROM users WHERE id <> ? ORDER BY id LIMIT 1'); $s->execute([$uid]); $u = $s->fetch(); return $u ?: null; }

function profile(array $u): array {
    $p = json_dec($u['profile'] ?? '{}');
    return $p + ['nom_complet' => '', 'adresse' => '', 'ville' => '', 'banque' => '', 'compte' => '', 'alias_moi' => [], 'alias_partenaire' => [], 'alias_employeur' => [],
        'jour_paie' => 0, 'solde' => null, 'solde_date' => null, 'epargne_dispo' => null, 'fragile' => 'non', 'partage_details' => false, 'partage_totaux' => true, 'natures' => [],
        'alertes_email' => 'importantes', 'email_contenu' => 'titres'];
}
function profile_save(int $uid, array $p): void { db()->prepare('UPDATE users SET profile = ? WHERE id = ?')->execute([json_enc($p), $uid]); }

function foyer(): array {
    $v = db()->query("SELECT v FROM meta WHERE k = 'foyer'")->fetchColumn();
    return json_dec($v ?: '{}') + ['nom' => 'Notre foyer', 'mode' => 'prorata', 'communes' => COMMON_DEFAULT];
}

function email_norm($e): string { return strtolower(clean_str($e, 120)); }
/** Compte désigné par son adresse e-mail (identifiant) ou, à défaut, par son prénom. */
function user_by_ident(string $ident): ?array {
    $ident = trim($ident);
    if (strpos($ident, '@') !== false) { $s = db()->prepare('SELECT * FROM users WHERE email = ?'); $s->execute([email_norm($ident)]); }
    else { $s = db()->prepare('SELECT * FROM users WHERE login = ?'); $s->execute([slug($ident)]); }
    $u = $s->fetch();
    return $u ?: null;
}
function email_taken(string $email, int $exceptId = 0): bool { $s = db()->prepare('SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?'); $s->execute([$email, $exceptId]); return (int)$s->fetchColumn() > 0; }

/** Réglages d'envoi des e-mails ; le mot de passe SMTP n'est déchiffré qu'au moment d'envoyer. */
function mail_cfg(bool $withPass = false): array {
    global $DATA_DIR;
    $c = json_dec((string)(db()->query("SELECT v FROM meta WHERE k = 'mail'")->fetchColumn() ?: '{}'));
    $c += ['mode' => 'off', 'from' => '', 'from_name' => 'Boussole', 'host' => '', 'port' => 587, 'secure' => 'tls', 'user' => '', 'pass_enc' => '', 'app_url' => ''];
    if ($withPass) {
        $c['pass'] = '';
        if ($c['pass_enc'] !== '') { try { $c['pass'] = vault_decrypt((string)base64_decode((string)$c['pass_enc']), vault_key($DATA_DIR)); } catch (RuntimeException $e) { $c['pass'] = ''; } }
    }
    return $c;
}
function mail_ready(): bool { $c = mail_cfg(); return ($c['mode'] === 'smtp' || ($c['mode'] === 'php' && mail_php_ok())) && valid_email((string)$c['from']); }
/** mail() de PHP : utilisable sur un hébergement Linux ; sous Windows, il ne sait pas s'authentifier auprès d'un serveur SMTP. */
function mail_php_ok(): bool { return function_exists('mail') && PHP_OS_FAMILY !== 'Windows'; }
function current_url(): string {
    $host = (string)preg_replace('/[^A-Za-z0-9.:\-\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $path = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/') . '/';
    return (is_https() ? 'https' : 'http') . '://' . ($host ?: 'localhost') . $path;
}
/** Adresse de l'application pour les liens des e-mails : réglée à la main, sinon mémorisée lors d'une connexion. */
function app_url(): string {
    $c = mail_cfg();
    if (!empty($c['app_url'])) return (string)$c['app_url'];
    $saved = db()->query("SELECT v FROM meta WHERE k = 'app_url'")->fetchColumn();
    return $saved ? (string)$saved : current_url();
}
function mail_to_user(array $u, string $subject, string $text, ?string $html): ?string {
    if (!mail_ready()) return 'L’envoi des e-mails n’est pas configuré (Réglages › Envoi des e-mails).';
    if (!valid_email((string)$u['email'])) return 'Aucune adresse e-mail valide pour ce compte.';
    $err = mail_send(mail_cfg(true), (string)$u['email'], $subject, $text, $html);
    if ($err !== null) ev((int)$u['id'], 'email_echec', cut($err, 200));
    return $err;
}
/** Récapitulatif des NOUVELLES alertes, selon les préférences de la personne. Chaque alerte n'est traitée qu'une fois. */
function notify_alerts(int $uid): void {
    $u = user_by_id($uid);
    if (!$u) return;
    $s = db()->prepare("SELECT id, niveau, titre FROM alerts WHERE user_id = ? AND mailed = 0 AND statut = 'ouverte' ORDER BY CASE niveau WHEN 'critique' THEN 0 WHEN 'attention' THEN 1 WHEN 'info' THEN 2 ELSE 3 END, id");
    $s->execute([$uid]);
    $rows = $s->fetchAll();
    if (!$rows) return;
    db()->prepare('UPDATE alerts SET mailed = 1 WHERE user_id = ? AND mailed = 0')->execute([$uid]);
    $p = profile($u);
    $want = ['critiques' => ['critique'], 'importantes' => ['critique', 'attention'], 'toutes' => ['critique', 'attention', 'info', 'bravo']][(string)$p['alertes_email']] ?? [];
    $send = array_values(array_filter($rows, function ($r) use ($want) { return in_array($r['niveau'], $want, true); }));
    if (!$send || !mail_ready() || !valid_email((string)$u['email'])) return;
    [$sub, $txt, $html] = mail_alerts((string)$u['name'], array_slice($send, 0, 15), app_url() . '#/alertes', (string)$p['email_contenu']);
    if (mail_to_user($u, $sub, $txt, $html) === null) ev($uid, 'email_alertes', count($send) . ' alerte(s)');
}
/** Veille automatique : au plus toutes les 6 heures, recalcule les alertes de chacun (découvert prévu, papiers qui expirent…). */
function veille_tick(bool $force = false): int {
    global $DATA_DIR;
    $last = (int)(db()->query("SELECT v FROM meta WHERE k = 'veille'")->fetchColumn() ?: 0);
    if (!$force && time() - $last < 6 * 3600) return 0;
    $lock = @fopen($DATA_DIR . '/veille.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return 0;
    db()->prepare("INSERT OR REPLACE INTO meta (k, v) VALUES ('veille', ?)")->execute([(string)time()]);
    $n = 0;
    foreach (users() as $u) if ($u['pw'] !== '') { refresh_alerts((int)$u['id']); $n++; }
    flock($lock, LOCK_UN);
    fclose($lock);
    return $n;
}

/** Contexte de catégorisation d'une personne : règles, alias, natures personnalisées, dépenses communes. */
function class_ctx(array $u): array {
    $p = profile($u);
    $s = db()->prepare('SELECT * FROM rules WHERE user_id = ? ORDER BY length(pattern) DESC, id DESC');
    $s->execute([(int)$u['id']]);
    $rules = [];
    foreach ($s->fetchAll() as $r) $rules[] = ['p' => $r['pattern'], 'sens' => $r['sens'], 'category' => $r['category'], 'nature' => $r['nature'], 'common' => $r['common'] === null ? null : (int)$r['common']];
    foreach ($rules as &$r) if ($r['common'] === null) unset($r['common']);
    unset($r);
    return ['rules' => $rules, 'me' => alias_list($p['alias_moi']), 'partner' => alias_list($p['alias_partenaire']), 'employer' => alias_list($p['alias_employeur']),
        'nature_over' => (array)$p['natures'], 'common_cats' => (array)foyer()['communes']];
}

/** Opérations d'une personne, montants en entiers (SQLite renvoie des chaînes avant PHP 8.1). */
function tx_load(int $uid, ?string $from = null, ?string $to = null): array {
    $sql = 'SELECT * FROM tx WHERE user_id = ?'; $args = [$uid];
    if ($from !== null) { $sql .= ' AND date >= ?'; $args[] = $from; }
    if ($to !== null) { $sql .= ' AND date <= ?'; $args[] = $to; }
    $s = db()->prepare($sql . ' ORDER BY date, id');
    $s->execute($args);
    $rows = $s->fetchAll();
    foreach ($rows as &$r) foreach (['id', 'user_id', 'amount', 'common', 'manual'] as $k) $r[$k] = (int)$r[$k];
    unset($r);
    return $rows;
}

function tx_public(array $t, array $cats, bool $mine = true): array {
    return ['id' => (int)$t['id'], 'user_id' => (int)$t['user_id'], 'date' => $t['date'], 'montant' => (int)$t['amount'], 'libelle' => $t['label'], 'contrepartie' => $t['merchant'],
        'type' => $t['kind'], 'categorie' => $t['category'], 'categorie_label' => $cats[$t['category']][0] ?? $t['category'], 'icone' => $cats[$t['category']][2] ?? '❔',
        'nature' => $t['nature'], 'frais' => $t['fee'], 'drapeaux' => $t['flags'] === '' ? [] : explode(',', $t['flags']), 'commune' => (int)$t['common'] === 1,
        'manuel' => (int)$t['manual'] === 1, 'note' => $t['note'], 'a_moi' => $mine];
}

/** Point de départ de la projection : solde saisi s'il est plus récent que le dernier relevé. */
function start_balance(array $u): ?array {
    $p = profile($u);
    $s = db()->prepare('SELECT period_end, closing FROM statements WHERE user_id = ? AND closing IS NOT NULL ORDER BY period_end DESC LIMIT 1');
    $s->execute([(int)$u['id']]);
    $st = $s->fetch();
    $manual = ($p['solde'] !== null && !empty($p['solde_date'])) ? ['solde' => (int)$p['solde'], 'date' => (string)$p['solde_date'], 'source' => 'saisi'] : null;
    $stmt = $st ? ['solde' => (int)$st['closing'], 'date' => (string)$st['period_end'], 'source' => 'releve'] : null;
    if ($manual && $stmt) return $manual['date'] >= $stmt['date'] ? $manual : $stmt;
    return $manual ?: $stmt;
}

function subs_state(int $uid): array {
    $s = db()->prepare('SELECT mkey, state, t FROM subs WHERE user_id = ?'); $s->execute([$uid]);
    $o = [];
    foreach ($s->fetchAll() as $r) $o[$r['mkey']] = ['state' => $r['state'], 't' => (int)$r['t']];
    return $o;
}
function budgets_of(int $uid): array {
    $s = db()->prepare('SELECT category, monthly FROM budgets WHERE user_id = ?'); $s->execute([$uid]);
    $o = [];
    foreach ($s->fetchAll() as $r) $o[$r['category']] = (int)$r['monthly'];
    return $o;
}
function docs_visible(int $uid): array {
    $s = db()->prepare('SELECT * FROM docs WHERE user_id = ? OR shared = 1 ORDER BY COALESCE(period, doc_date) DESC, id DESC');
    $s->execute([$uid]);
    return $s->fetchAll();
}
function avg_essentials(array $txs): int {
    $months = array_slice(an_months($txs), -3);
    if (!$months) return 0;
    $tot = 0;
    foreach ($months as $m) $tot += an_summary(an_month($txs, $m))['nature']['essentiel'];
    return (int)round($tot / count($months));
}
function avg_income(array $txs): int {
    $months = array_slice(an_months($txs), -3);
    if (!$months) return 0;
    $tot = 0;
    foreach ($months as $m) $tot += an_summary(an_month($txs, $m))['revenus'];
    return (int)round($tot / count($months));
}

/** Recalcule et enregistre les alertes d'une personne (les alertes traitées ou ignorées gardent leur statut). */
function refresh_alerts(int $uid): void {
    $u = user_by_id($uid);
    if (!$u) return;
    $p = profile($u);
    $from = date('Y-m-d', strtotime('-15 months'));
    $txs = tx_load($uid, $from);
    $rec = an_recurring($txs);
    $partner = partner_of($uid);
    $pRec = null;
    if ($partner && profile($partner)['partage_details'] && $p['partage_details']) $pRec = an_recurring(tx_load((int)$partner['id'], $from));
    $alerts = $txs ? alerts_build($txs, [
        'profile' => $p, 'fees' => an_fees($txs, $p), 'recurring' => $rec, 'forecast' => an_forecast($txs, $rec, $p, start_balance($u), date('Y-m-d')),
        'docs' => array_values(array_filter(docs_visible($uid), function ($d) use ($uid) { return (int)$d['user_id'] === $uid; })),
        'budgets' => budgets_of($uid), 'subs' => subs_state($uid), 'partner_recurring' => $pRec, 'partner_name' => $partner['name'] ?? null,
        'today' => date('Y-m-d'), 'revenu_moyen' => avg_income($txs),
    ]) : [];
    $db = db();
    $db->beginTransaction();
    $keep = [];
    $sel = $db->prepare('SELECT id FROM alerts WHERE user_id = ? AND cle = ?');
    $ins = $db->prepare('INSERT INTO alerts (user_id, cle, niveau, type, titre, texte, mois, data, statut, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'ouverte\', ?, ?)');
    $upd = $db->prepare('UPDATE alerts SET niveau = ?, type = ?, titre = ?, texte = ?, mois = ?, data = ?, updated_at = ? WHERE id = ?');
    foreach ($alerts as $a) {
        $data = json_enc(['actions' => $a['actions'], 'tx_ids' => $a['tx_ids']]);
        $sel->execute([$uid, $a['cle']]);
        $id = $sel->fetchColumn();
        if ($id) $upd->execute([$a['niveau'], $a['type'], $a['titre'], $a['texte'], $a['mois'], $data, time(), (int)$id]);
        else $ins->execute([$uid, $a['cle'], $a['niveau'], $a['type'], $a['titre'], $a['texte'], $a['mois'], $data, time(), time()]);
        $keep[] = $a['cle'];
    }
    // les alertes encore ouvertes dont la cause a disparu (opération reclassée…) sont retirées
    $s = $db->prepare("SELECT id, cle FROM alerts WHERE user_id = ? AND statut = 'ouverte'");
    $s->execute([$uid]);
    $del = $db->prepare('DELETE FROM alerts WHERE id = ?');
    $keepSet = array_flip($keep);
    foreach ($s->fetchAll() as $r) if (!isset($keepSet[$r['cle']])) $del->execute([(int)$r['id']]);
    $db->commit();
    notify_alerts($uid);
}

/* ========================================================================== */
/* Authentification                                                            */
/* ========================================================================== */

function throttle_check(): void {
    global $MAX_FAILS_IP;
    $s = db()->prepare('SELECT n, t FROM throttle WHERE ip = ?'); $s->execute([client_ip()]);
    $r = $s->fetch();
    if ($r && time() - (int)$r['t'] < 900 && (int)$r['n'] >= $MAX_FAILS_IP) fail(429, 'Trop de tentatives depuis cette adresse. Réessayez dans 15 minutes.');
}
function throttle_fail(): void {
    $s = db()->prepare('SELECT n, t FROM throttle WHERE ip = ?'); $s->execute([client_ip()]);
    $r = $s->fetch();
    $n = ($r && time() - (int)$r['t'] < 900) ? (int)$r['n'] + 1 : 1;
    db()->prepare('INSERT OR REPLACE INTO throttle (ip, n, t) VALUES (?, ?, ?)')->execute([client_ip(), $n, $n === 1 ? time() : (int)$r['t']]);
}

function login_session(array $u): array {
    session_open();
    session_regenerate_id(true);
    $_SESSION = ['uid' => (int)$u['id'], 'csrf' => bin2hex(random_bytes(24)), 'last' => time(), 'since' => time()];
    db()->prepare('UPDATE users SET fails = 0, locked_until = 0, last_login = ? WHERE id = ?')->execute([time(), (int)$u['id']]);
    db()->prepare('DELETE FROM throttle WHERE ip = ?')->execute([client_ip()]);
    return $_SESSION;
}

/** Utilisateur connecté (ou réponse 401). Vérifie l'inactivité et, pour les écritures, le jeton anti-CSRF. */
function auth(): array {
    global $SESSION_IDLE;
    session_open();
    $uid = (int)($_SESSION['uid'] ?? 0);
    if (!$uid) fail(401, 'Connexion requise.', ['code' => 'auth']);
    if (time() - (int)($_SESSION['last'] ?? 0) > $SESSION_IDLE) { $_SESSION = []; session_destroy(); fail(401, 'Session expirée après inactivité : reconnectez-vous.', ['code' => 'auth']); }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $h = (string)($_SERVER['HTTP_X_CSRF'] ?? '');
        if ($h === '' || !hash_equals((string)($_SESSION['csrf'] ?? ''), $h)) fail(403, 'Jeton de sécurité invalide : rechargez la page.', ['code' => 'csrf']);
    }
    $_SESSION['last'] = time();
    $u = user_by_id($uid);
    if (!$u || $u['pw'] === '') { $_SESSION = []; fail(401, 'Compte introuvable.', ['code' => 'auth']); }
    session_write_close();                     // libère la session : les requêtes parallèles ne se bloquent pas
    return $u;
}

/** Contrôle d'un fichier envoyé : un refus donne un message clair (erreur 400), pas une « erreur interne ». */
function upload_check(string $tmp, string $name, int $size): array {
    global $MAX_UPLOAD;
    try { return doc_check_upload($tmp, $name, $size, $MAX_UPLOAD); }
    catch (RuntimeException $e) { fail(400, $e->getMessage()); }
    return [];
}
/** Titre proposé : le nom du fichier s'il est parlant, sinon « type + date » (IMG_2041.jpg ne dit rien). */
function doc_default_title(string $fname, string $typeLabel, ?string $period, string $date): string {
    $base = trim((string)preg_replace('/[_\s]+/', ' ', pathinfo($fname, PATHINFO_FILENAME)));
    $generic = preg_match('/^(img|dsc|dscn|pxl|scan|scanned|screenshot|capture|photo|image|document|doc|file|fichier|sans titre|untitled|whatsapp image)\b[\s\d-]*/i', $base)
        || !preg_match('/\p{L}{3}/u', $base);
    if (!$generic) return cut($base, 120);
    return $typeLabel . ($period ? ' ' . fr_month($period) : ' du ' . fr_date($date));
}

/** Envoie au navigateur une archive ZIP des documents (déchiffrés à la volée, jamais écrits en clair sur le disque). */
function send_docs_zip(string $filename, array $list): void {
    global $DATA_DIR;
    $key = vault_key($DATA_DIR);
    $types = doc_types();
    $noms = [];
    foreach (users() as $x) $noms[(int)$x['id']] = $x['name'];
    @set_time_limit(0);
    while (ob_get_level() > 0) ob_end_clean();
    header_remove('Content-Type');
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]+/', '-', norm_text(pathinfo($filename, PATHINFO_FILENAME))) . '.zip"; filename*=UTF-8\'\'' . rawurlencode($filename));
    $zip = new ZipFlux(fopen('php://output', 'wb'));
    $inv = "\xEF\xBB\xBFFichier;Type;Titre;Date;Période;Expiration;À conserver jusqu'au;Personne;Partagé avec le foyer;Taille (Ko);Empreinte SHA-256\r\n";
    $csv = function (array $cols): string { return implode(';', array_map(function ($c) { $c = (string)$c; return preg_match('/[;"\r\n]/', $c) ? '"' . str_replace('"', '""', $c) . '"' : $c; }, $cols)) . "\r\n"; };
    $manquants = [];
    foreach ($list as $item) {
        $d = $item['doc'];
        $path = $DATA_DIR . '/vault/' . basename((string)$d['store']);
        $raw = null;
        if (is_file($path)) { try { $raw = vault_decrypt((string)file_get_contents($path), $key); } catch (RuntimeException $e) { $raw = null; } }
        if ($raw === null) { $manquants[] = (string)$d['title']; continue; }
        $ext = strtolower(pathinfo((string)$d['fname'], PATHINFO_EXTENSION));
        $nom = $zip->add($item['nom'] . ($ext !== '' ? '.' . $ext : ''), $raw, (int)$d['created_at']);
        $inv .= $csv([$nom, $types[$d['type']][0] ?? $d['type'], $d['title'], fr_date($d['doc_date']), $d['period'] ? fr_month((string)$d['period']) : '', fr_date($d['expiry']),
            fr_date($d['keep_until']), $noms[(int)$d['user_id']] ?? '', (int)$d['shared'] === 1 ? 'oui' : 'non', (string)max(1, (int)round(strlen($raw) / 1024)), hash('sha256', $raw)]);
        unset($raw);
    }
    if ($manquants) $inv .= "\r\nIllisibles ou absents du coffre : " . implode(', ', $manquants) . "\r\n";
    $zip->add('inventaire.csv', $inv);
    $zip->finish();
    exit;
}

/* ========================================================================== */
/* Routeur                                                                     */
/* ========================================================================== */

$a = (string)($_GET['a'] ?? '');
$cats = categories();

switch ($a) {

/* ---------------------------------------------------------------- public */
case 'status': {
    session_open();
    $installed = (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
    $uid = (int)($_SESSION['uid'] ?? 0);
    $me = null;
    if ($uid && time() - (int)($_SESSION['last'] ?? 0) <= $SESSION_IDLE && ($u = user_by_id($uid)) && $u['pw'] !== '') {
        $_SESSION['last'] = time();
        $me = ['id' => (int)$u['id'], 'nom' => $u['name']];
    }
    $pending = [];
    foreach (users() as $u) if ($u['pw'] === '' && $u['invite'] !== '') $pending[] = $u['name'];
    $toBytes = function (string $v): int { $v = trim($v); $n = (int)$v; $u = strtolower(substr($v, -1)); return $u === 'g' ? $n << 30 : ($u === 'm' ? $n << 20 : ($u === 'k' ? $n << 10 : $n)); };
    if ($installed) veille_tick();
    out(200, ['version' => BOUSSOLE, 'installe' => $installed, 'moi' => $me, 'csrf' => $me ? $_SESSION['csrf'] : null, 'https' => is_https(), 'mail' => $installed && mail_ready(),
        'chiffrement' => vault_cipher(), 'invitation_en_attente' => $pending, 'foyer' => $installed ? foyer()['nom'] : null,
        'max_upload' => min($MAX_UPLOAD, $toBytes((string)ini_get('upload_max_filesize')), $toBytes((string)ini_get('post_max_size'))),
        'zip' => true, 'pdftotext' => imp_find_pdftotext() !== null, 'min_password' => $MIN_PASSWORD]);
}

case 'setup': {
    $in = body();
    if ((int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) fail(409, 'L’application est déjà installée.');
    $foyerNom = clean_str($in['foyer'] ?? '', 60) ?: 'Notre foyer';
    $n1 = clean_str($in['nom'] ?? '', 40); $pw = (string)($in['mot_de_passe'] ?? ''); $n2 = clean_str($in['nom_partenaire'] ?? '', 40);
    $email = email_norm($in['email'] ?? '');
    if ($n1 === '' || slug($n1) === '') fail(400, 'Indiquez votre prénom.');
    if (!valid_email($email)) fail(400, 'Indiquez une adresse e-mail valide : elle sert d’identifiant et reçoit les alertes.');
    if (isset($in['confirmation']) && (string)$in['confirmation'] !== $pw) fail(400, 'Les deux mots de passe ne correspondent pas.');
    if (($m = pw_problem($pw, $n1)) !== null) fail(400, $m);
    if ($n2 !== '' && slug($n2) === slug($n1)) fail(400, 'Les deux prénoms doivent être différents.');
    $db = db();
    $db->beginTransaction();
    $db->prepare("INSERT OR REPLACE INTO meta (k, v) VALUES ('foyer', ?)")->execute([json_enc(['nom' => $foyerNom, 'mode' => 'prorata', 'communes' => COMMON_DEFAULT])]);
    $rec = new_code(16);
    $p1 = ['alias_moi' => [$n1], 'alias_partenaire' => $n2 !== '' ? [$n2] : []];
    $db->prepare('INSERT INTO users (login, name, email, pw, recovery, profile, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([slug($n1), $n1, $email, password_hash($pw, PASSWORD_DEFAULT), password_hash(code_norm($rec), PASSWORD_DEFAULT), json_enc($p1), time()]);
    $uid = (int)$db->lastInsertId();
    $invite = null;
    if ($n2 !== '') {
        $invite = new_code(12);
        $db->prepare('INSERT INTO users (login, name, pw, invite, invite_exp, profile, created_at) VALUES (?, ?, \'\', ?, ?, ?, ?)')
            ->execute([slug($n2), $n2, password_hash(code_norm($invite), PASSWORD_DEFAULT), time() + 30 * 86400, json_enc(['alias_moi' => [$n2], 'alias_partenaire' => [$n1]]), time()]);
    }
    $db->commit();
    vault_key($DATA_DIR);
    $u = user_by_id($uid);
    $s = login_session($u);
    ev($uid, 'installation', 'foyer ' . $foyerNom);
    db()->prepare("INSERT OR REPLACE INTO meta (k, v) VALUES ('app_url', ?)")->execute([current_url()]);
    out(200, ['ok' => true, 'csrf' => $s['csrf'], 'code_secours' => $rec, 'invitation' => $invite, 'partenaire' => $n2, 'email' => $email]);
}

case 'login': {
    $in = body();
    throttle_check();
    $u = user_by_ident((string)($in['identifiant'] ?? $in['nom'] ?? ''));
    $pw = (string)($in['mot_de_passe'] ?? '');
    if ($u && (int)$u['locked_until'] > time()) fail(423, 'Compte bloqué après plusieurs erreurs. Réessayez dans ' . (int)ceil(((int)$u['locked_until'] - time()) / 60) . ' minute(s), ou utilisez votre code de secours.');
    if (!$u || $u['pw'] === '' || !password_verify($pw, $u['pw'])) {
        throttle_fail();
        if ($u && $u['pw'] !== '') {
            $f = (int)$u['fails'] + 1;
            db()->prepare('UPDATE users SET fails = ?, locked_until = ? WHERE id = ?')->execute([$f >= $LOCK_ATTEMPTS ? 0 : $f, $f >= $LOCK_ATTEMPTS ? time() + $LOCK_MINUTES * 60 : 0, (int)$u['id']]);
            ev((int)$u['id'], 'echec_connexion');
            if ($f >= $LOCK_ATTEMPTS) {
                ev((int)$u['id'], 'compte_bloque', $LOCK_MINUTES . ' min');
                if (mail_ready()) { [$ms, $mt, $mh] = mail_lock((string)$u['name'], $LOCK_MINUTES, client_ip(), app_url()); mail_to_user($u, $ms, $mt, $mh); }
            }
        }
        if ($u && $u['pw'] === '' && $u['invite'] !== '') fail(401, 'Ce compte n’est pas encore activé : utilisez le code d’invitation.');
        fail(401, 'Adresse e-mail ou mot de passe incorrect.');
    }
    if (password_needs_rehash($u['pw'], PASSWORD_DEFAULT)) db()->prepare('UPDATE users SET pw = ? WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), (int)$u['id']]);
    $sess = login_session($u);
    ev((int)$u['id'], 'connexion');
    out(200, ['ok' => true, 'csrf' => $sess['csrf']]);
}

case 'activate': {
    $in = body();
    throttle_check();
    $code = code_norm((string)($in['code'] ?? ''));
    $pw = (string)($in['mot_de_passe'] ?? '');
    $found = null;
    foreach (users() as $u) if ($u['pw'] === '' && $u['invite'] !== '' && (int)$u['invite_exp'] > time() && password_verify($code, $u['invite'])) { $found = $u; break; }
    if (!$found) { throttle_fail(); fail(400, 'Code d’invitation invalide ou expiré.'); }
    $email = email_norm($in['email'] ?? '');
    if (!valid_email($email)) fail(400, 'Indiquez une adresse e-mail valide : elle sert d’identifiant et reçoit vos alertes.');
    if (email_taken($email, (int)$found['id'])) fail(409, 'Cette adresse est déjà utilisée par l’autre compte du foyer.');
    if (isset($in['confirmation']) && (string)$in['confirmation'] !== $pw) fail(400, 'Les deux mots de passe ne correspondent pas.');
    if (($m = pw_problem($pw, $found['name'])) !== null) fail(400, $m);
    $rec = new_code(16);
    db()->prepare('UPDATE users SET pw = ?, email = ?, invite = \'\', invite_exp = 0, recovery = ? WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $email, password_hash(code_norm($rec), PASSWORD_DEFAULT), (int)$found['id']]);
    $sess = login_session(user_by_id((int)$found['id']));
    ev((int)$found['id'], 'activation');
    out(200, ['ok' => true, 'csrf' => $sess['csrf'], 'code_secours' => $rec, 'nom' => $found['name']]);
}

case 'recover': {
    $in = body();
    throttle_check();
    $u = user_by_ident((string)($in['identifiant'] ?? $in['nom'] ?? ''));
    if (!$u || $u['recovery'] === '' || !password_verify(code_norm((string)($in['code'] ?? '')), $u['recovery'])) { throttle_fail(); if ($u) ev((int)$u['id'], 'echec_code_secours'); fail(400, 'Adresse e-mail ou code de secours incorrect.'); }
    $pw = (string)($in['mot_de_passe'] ?? '');
    if (($m = pw_problem($pw, $u['name'])) !== null) fail(400, $m);
    $rec = new_code(16);
    db()->prepare('UPDATE users SET pw = ?, recovery = ?, fails = 0, locked_until = 0 WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), password_hash(code_norm($rec), PASSWORD_DEFAULT), (int)$u['id']]);
    $sess = login_session(user_by_id((int)$u['id']));
    ev((int)$u['id'], 'mot_de_passe_reinitialise', 'par code de secours');
    out(200, ['ok' => true, 'csrf' => $sess['csrf'], 'code_secours' => $rec]);
}

case 'forgot': {
    $in = body();
    throttle_check();
    throttle_fail();                          // chaque demande compte : pas d'envois en rafale depuis une même adresse IP
    if (!mail_ready()) fail(503, 'L’envoi des e-mails n’est pas configuré sur ce serveur : utilisez votre code de secours.');
    $u = user_by_ident((string)($in['identifiant'] ?? ''));
    if ($u && $u['pw'] !== '' && valid_email((string)$u['email']) && (int)$u['reset_exp'] < time() + 25 * 60) {
        $tok = bin2hex(random_bytes(24));
        db()->prepare('UPDATE users SET reset_hash = ?, reset_exp = ? WHERE id = ?')->execute([hash('sha256', $tok), time() + 30 * 60, (int)$u['id']]);
        [$ms, $mt, $mh] = mail_reset((string)$u['name'], app_url() . '#/reinit?jeton=' . $tok);
        mail_to_user($u, $ms, $mt, $mh);
        ev((int)$u['id'], 'reinit_demande');
    }
    // même réponse que le compte existe ou non : on ne révèle pas quelles adresses sont inscrites
    out(200, ['ok' => true, 'message' => 'Si un compte correspond à cette adresse, un e-mail vient de partir avec un lien valable 30 minutes. Regardez aussi dans les courriers indésirables.']);
}

case 'reset': {
    $in = body();
    throttle_check();
    $tok = (string)($in['jeton'] ?? '');
    if (!preg_match('/^[a-f0-9]{48}$/', $tok)) { throttle_fail(); fail(400, 'Lien invalide.'); }
    $s = db()->prepare('SELECT * FROM users WHERE reset_hash = ? AND reset_exp > ?'); $s->execute([hash('sha256', $tok), time()]);
    $u = $s->fetch();
    if (!$u) { throttle_fail(); fail(400, 'Lien invalide ou expiré : refaites une demande depuis l’écran de connexion.'); }
    $pw = (string)($in['mot_de_passe'] ?? '');
    if (isset($in['confirmation']) && (string)$in['confirmation'] !== $pw) fail(400, 'Les deux mots de passe ne correspondent pas.');
    if (($m = pw_problem($pw, $u['name'])) !== null) fail(400, $m);
    db()->prepare("UPDATE users SET pw = ?, reset_hash = '', reset_exp = 0, fails = 0, locked_until = 0 WHERE id = ?")->execute([password_hash($pw, PASSWORD_DEFAULT), (int)$u['id']]);
    $sess = login_session(user_by_id((int)$u['id']));
    ev((int)$u['id'], 'mot_de_passe_reinitialise', 'par lien e-mail');
    out(200, ['ok' => true, 'csrf' => $sess['csrf']]);
}

case 'veille': {
    // pour une tâche planifiée (cron) : https://…/api.php?a=veille&cle=… (clé visible dans Réglages › Envoi des e-mails).
    // Depuis le serveur lui-même (tâche planifiée Windows : http://localhost/…), la clé n'est pas nécessaire.
    $key = (string)($_GET['cle'] ?? '');
    $saved = (string)(db()->query("SELECT v FROM meta WHERE k = 'veille_cle'")->fetchColumn() ?: '');
    $fromServer = ip_is_loopback(client_ip()) && empty($_SERVER['HTTP_X_FORWARDED_FOR']) && empty($_SERVER['HTTP_FORWARDED']);
    if (!$fromServer && ($saved === '' || !hash_equals($saved, $key))) { throttle_check(); throttle_fail(); fail(403, 'Clé de veille invalide.'); }
    out(200, ['ok' => true, 'comptes' => veille_tick(true)]);
}

case 'logout': {
    need_post();
    session_open();
    if (!empty($_SESSION['uid'])) ev((int)$_SESSION['uid'], 'deconnexion');
    $_SESSION = [];
    if (ini_get('session.use_cookies')) { $p = session_get_cookie_params(); setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Strict']); }
    session_destroy();
    out(200, ['ok' => true]);
}

/* ---------------------------------------------------------------- profil */
case 'me': {
    $u = auth();
    $p = profile($u);
    $partner = partner_of((int)$u['id']);
    db()->prepare("INSERT OR REPLACE INTO meta (k, v) VALUES ('app_url', ?)")->execute([current_url()]);
    out(200, ['moi' => ['id' => (int)$u['id'], 'nom' => $u['name'], 'email' => $u['email'], 'derniere_connexion' => (int)$u['last_login']], 'profil' => $p, 'foyer' => foyer(), 'mail_pret' => mail_ready(),
        'partenaire' => $partner ? ['id' => (int)$partner['id'], 'nom' => $partner['name'], 'active' => $partner['pw'] !== '', 'partage_details' => (bool)profile($partner)['partage_details'], 'partage_totaux' => (bool)profile($partner)['partage_totaux']] : null,
        'categories' => array_map(function ($c, $id) { return ['id' => $id, 'label' => $c[0], 'nature' => $c[1], 'icone' => $c[2]]; }, $cats, array_keys($cats)),
        'natures' => NATURES, 'types_frais' => fee_types(), 'types_docs' => array_map(function ($t, $id) { return ['id' => $id, 'label' => $t[0], 'icone' => $t[1], 'conservation' => $t[2], 'champs' => $t[5]]; }, doc_types(), array_keys(doc_types())),
        'courriers' => letters_catalog(), 'budgets' => budgets_of((int)$u['id']), 'verifie' => LEGAL_VERIFIE]);
}

case 'me.save': {
    $u = auth(); $in = body();
    $p = profile($u);
    foreach (['nom_complet' => 80, 'ville' => 60, 'banque' => 60, 'compte' => 40] as $k => $n) if (array_key_exists($k, $in)) $p[$k] = clean_str($in[$k], $n);
    if (array_key_exists('adresse', $in)) $p['adresse'] = trim(cut(str_replace("\r", '', (string)preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F]/', '', (string)$in['adresse'])), 200));
    foreach (['alias_moi', 'alias_partenaire', 'alias_employeur'] as $k) if (array_key_exists($k, $in)) {
        $p[$k] = array_values(array_filter(array_map(function ($x) { return clean_str($x, 60); }, array_slice((array)$in[$k], 0, 10)), function ($x) { return strlen(norm_text($x)) >= 3; }));
    }
    if (array_key_exists('jour_paie', $in)) $p['jour_paie'] = max(0, min(31, (int)$in['jour_paie']));
    if (array_key_exists('solde', $in)) { $p['solde'] = $in['solde'] === null || $in['solde'] === '' ? null : (int)$in['solde']; $p['solde_date'] = $p['solde'] === null ? null : (date_parse_any((string)($in['solde_date'] ?? '')) ?? date('Y-m-d')); }
    if (array_key_exists('epargne_dispo', $in)) $p['epargne_dispo'] = $in['epargne_dispo'] === null || $in['epargne_dispo'] === '' ? null : max(0, (int)$in['epargne_dispo']);
    if (array_key_exists('fragile', $in) && in_array($in['fragile'], ['non', 'fragile', 'ocf', 'sbb'], true)) $p['fragile'] = $in['fragile'];
    foreach (['partage_details', 'partage_totaux'] as $k) if (array_key_exists($k, $in)) $p[$k] = (bool)$in[$k];
    if (array_key_exists('alertes_email', $in) && in_array($in['alertes_email'], ['aucune', 'critiques', 'importantes', 'toutes'], true)) $p['alertes_email'] = $in['alertes_email'];
    if (array_key_exists('email_contenu', $in) && in_array($in['email_contenu'], ['titres', 'discret'], true)) $p['email_contenu'] = $in['email_contenu'];
    if (array_key_exists('natures', $in)) {
        $nat = [];
        foreach ((array)$in['natures'] as $c => $n) if (isset($cats[$c]) && isset(NATURES[$n]) && $n !== $cats[$c][1]) $nat[$c] = $n;
        $p['natures'] = $nat;
    }
    profile_save((int)$u['id'], $p);
    $reclass = !empty($in['reclasser']);
    if ($reclass) reclassify((int)$u['id']);
    refresh_alerts((int)$u['id']);
    ev((int)$u['id'], 'reglages');
    out(200, ['ok' => true, 'profil' => $p]);
}

case 'foyer.save': {
    $u = auth(); $in = body();
    $f = foyer();
    if (array_key_exists('nom', $in)) $f['nom'] = clean_str($in['nom'], 60) ?: 'Notre foyer';
    if (array_key_exists('mode', $in) && in_array($in['mode'], ['prorata', 'egal'], true)) $f['mode'] = $in['mode'];
    if (array_key_exists('communes', $in)) $f['communes'] = array_values(array_filter((array)$in['communes'], function ($c) use ($cats) { return is_string($c) && isset($cats[$c]); }));
    db()->prepare("INSERT OR REPLACE INTO meta (k, v) VALUES ('foyer', ?)")->execute([json_enc($f)]);
    if (array_key_exists('communes', $in)) foreach (users() as $x) reclassify((int)$x['id'], true);
    ev((int)$u['id'], 'reglages_foyer');
    out(200, ['ok' => true, 'foyer' => $f]);
}

case 'email.change': {
    $u = auth(); $in = body();
    if (!password_verify((string)($in['mot_de_passe'] ?? ''), $u['pw'])) fail(400, 'Mot de passe incorrect.');
    $email = email_norm($in['email'] ?? '');
    if (!valid_email($email)) fail(400, 'Adresse e-mail invalide.');
    if (email_taken($email, (int)$u['id'])) fail(409, 'Cette adresse est déjà utilisée par l’autre compte du foyer.');
    $old = (string)$u['email'];
    db()->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$email, (int)$u['id']]);
    ev((int)$u['id'], 'email_change');
    if ($old !== $email && valid_email($old) && mail_ready()) {   // prévient l'ANCIENNE adresse : utile si quelqu'un a pris la main sur le compte
        [$ms, $mt, $mh] = mail_changed((string)$u['name'], $email, app_url());
        $tmp = $u; $tmp['email'] = $old;
        mail_to_user($tmp, $ms, $mt, $mh);
    }
    out(200, ['ok' => true, 'email' => $email]);
}

case 'mail.get': {
    auth();
    $c = mail_cfg();
    $key = (string)(db()->query("SELECT v FROM meta WHERE k = 'veille_cle'")->fetchColumn() ?: '');
    if ($key === '') { $key = bin2hex(random_bytes(16)); db()->prepare("INSERT OR REPLACE INTO meta (k, v) VALUES ('veille_cle', ?)")->execute([$key]); }
    $presets = [];
    foreach (mail_presets() as $id => $pr) $presets[] = ['id' => $id, 'label' => $pr[0], 'valeurs' => $pr[1]];
    out(200, ['mode' => $c['mode'], 'from' => $c['from'], 'from_name' => $c['from_name'], 'host' => $c['host'], 'port' => (int)$c['port'], 'secure' => $c['secure'], 'user' => $c['user'],
        'mot_de_passe_enregistre' => $c['pass_enc'] !== '', 'app_url' => $c['app_url'], 'url_detectee' => current_url(), 'pret' => mail_ready(), 'prereglages' => $presets,
        'veille' => current_url() . 'api.php?a=veille&cle=' . $key, 'mail_php' => mail_php_ok()]);
}

case 'mail.save': {
    $u = auth(); $in = body();
    $c = mail_cfg();
    if (isset($in['mode']) && in_array($in['mode'], ['off', 'php', 'smtp'], true)) $c['mode'] = $in['mode'];
    if ($c['mode'] === 'php' && !mail_php_ok()) fail(400, 'La fonction mail() de PHP ne sait pas s’authentifier (Windows) : choisissez « Serveur SMTP ».');
    if (array_key_exists('from', $in)) { $f = email_norm($in['from']); if ($f !== '' && !valid_email($f)) fail(400, 'Adresse d’expédition invalide.'); $c['from'] = $f; }
    if (array_key_exists('from_name', $in)) $c['from_name'] = clean_header(clean_str($in['from_name'], 60)) ?: 'Boussole';
    if (array_key_exists('host', $in)) $c['host'] = (string)preg_replace('/[^A-Za-z0-9.\-]/', '', clean_str($in['host'], 120));
    if (array_key_exists('port', $in)) $c['port'] = max(1, min(65535, (int)$in['port']));
    if (isset($in['secure']) && in_array($in['secure'], ['tls', 'ssl', 'none'], true)) $c['secure'] = $in['secure'];
    if (array_key_exists('user', $in)) $c['user'] = clean_header(clean_str($in['user'], 120));
    if (!empty($in['mot_de_passe'])) $c['pass_enc'] = base64_encode(vault_encrypt((string)$in['mot_de_passe'], vault_key($DATA_DIR)));
    if (!empty($in['effacer_mot_de_passe'])) $c['pass_enc'] = '';
    if (array_key_exists('app_url', $in)) {
        $url = trim(clean_str($in['app_url'], 200));
        if ($url !== '' && (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url))) fail(400, 'Adresse de l’application invalide (exemple : https://mon-site.fr/finances/).');
        $c['app_url'] = $url === '' ? '' : rtrim($url, '/') . '/';
    }
    if ($c['mode'] !== 'off' && !valid_email((string)$c['from'])) fail(400, 'Indiquez l’adresse d’expédition des e-mails.');
    if ($c['mode'] === 'smtp' && $c['host'] === '') fail(400, 'Indiquez le serveur SMTP.');
    unset($c['pass']);
    db()->prepare("INSERT OR REPLACE INTO meta (k, v) VALUES ('mail', ?)")->execute([json_enc($c)]);
    ev((int)$u['id'], 'reglages_email', $c['mode']);
    out(200, ['ok' => true, 'pret' => mail_ready()]);
}

case 'mail.test': {
    $u = auth(); body();
    [$ms, $mt, $mh] = mail_test((string)$u['name'], app_url());
    $err = mail_to_user($u, $ms, $mt, $mh);
    if ($err !== null) fail(502, $err);
    ev((int)$u['id'], 'email_test');
    out(200, ['ok' => true, 'message' => 'E-mail de test envoyé à ' . $u['email'] . '. S’il n’arrive pas d’ici quelques minutes, regardez dans les courriers indésirables.']);
}

case 'password': {
    $u = auth(); $in = body();
    if (!password_verify((string)($in['ancien'] ?? ''), $u['pw'])) fail(400, 'Mot de passe actuel incorrect.');
    $pw = (string)($in['nouveau'] ?? '');
    if (($m = pw_problem($pw, $u['name'])) !== null) fail(400, $m);
    db()->prepare('UPDATE users SET pw = ? WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), (int)$u['id']]);
    ev((int)$u['id'], 'mot_de_passe_change');
    out(200, ['ok' => true]);
}

case 'recovery.new': {
    $u = auth(); $in = body();
    if (!password_verify((string)($in['mot_de_passe'] ?? ''), $u['pw'])) fail(400, 'Mot de passe incorrect.');
    $rec = new_code(16);
    db()->prepare('UPDATE users SET recovery = ? WHERE id = ?')->execute([password_hash(code_norm($rec), PASSWORD_DEFAULT), (int)$u['id']]);
    ev((int)$u['id'], 'nouveau_code_secours');
    out(200, ['ok' => true, 'code_secours' => $rec]);
}

case 'invite.new': {
    // seulement si le compte du partenaire n'a JAMAIS été activé : on ne peut pas prendre la main sur un compte existant
    $u = auth(); body();
    $p = partner_of((int)$u['id']);
    if (!$p) fail(404, 'Aucun second compte dans ce foyer.');
    if ($p['pw'] !== '') fail(409, $p['name'] . ' a déjà activé son compte : en cas d’oubli, son code de secours permet de choisir un nouveau mot de passe.');
    $invite = new_code(12);
    db()->prepare('UPDATE users SET invite = ?, invite_exp = ? WHERE id = ?')->execute([password_hash(code_norm($invite), PASSWORD_DEFAULT), time() + 30 * 86400, (int)$p['id']]);
    ev((int)$u['id'], 'invitation', 'pour ' . $p['name']);
    out(200, ['ok' => true, 'invitation' => $invite, 'nom' => $p['name']]);
}

case 'partner.add': {
    $u = auth(); $in = body();
    if (count(users()) >= $MAX_USERS) fail(409, 'Le foyer compte déjà ' . $MAX_USERS . ' personnes.');
    $n2 = clean_str($in['nom'] ?? '', 40);
    if (slug($n2) === '' || slug($n2) === $u['login']) fail(400, 'Prénom invalide.');
    $invite = new_code(12);
    db()->prepare('INSERT INTO users (login, name, pw, invite, invite_exp, profile, created_at) VALUES (?, ?, \'\', ?, ?, ?, ?)')
        ->execute([slug($n2), $n2, password_hash(code_norm($invite), PASSWORD_DEFAULT), time() + 30 * 86400, json_enc(['alias_moi' => [$n2], 'alias_partenaire' => [$u['name']]]), time()]);
    $p = profile($u); $p['alias_partenaire'] = array_values(array_unique(array_merge($p['alias_partenaire'], [$n2]))); profile_save((int)$u['id'], $p);
    ev((int)$u['id'], 'invitation', 'pour ' . $n2);
    out(200, ['ok' => true, 'invitation' => $invite, 'nom' => $n2]);
}

/* ---------------------------------------------------------------- import des relevés */
case 'import.parse': {
    $u = auth(); need_post();
    $payload = json_dec((string)($_POST['payload'] ?? ''));
    $file = $_FILES['file'] ?? null;
    $token = null; $fname = '';
    if ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) fail(413, 'Fichier plus gros que la limite du serveur (upload_max_filesize = ' . ini_get('upload_max_filesize') . ').');
        if ($file['error'] !== UPLOAD_ERR_OK) fail(400, 'Envoi du fichier interrompu (code ' . (int)$file['error'] . ').');
        [$mime] = upload_check($file['tmp_name'], (string)$file['name'], (int)$file['size']);
        $raw = (string)file_get_contents($file['tmp_name']);
        $fname = clean_str($file['name'], 120);
        $token = bin2hex(random_bytes(16));
        file_put_contents($DATA_DIR . '/tmp/' . $token . '.bin', vault_encrypt(json_enc(['name' => $fname, 'mime' => $mime, 'uid' => (int)$u['id'], 't' => time()]) . "\n" . $raw, vault_key($DATA_DIR)));
    }
    foreach (glob($DATA_DIR . '/tmp/*.bin') ?: [] as $old) if (filemtime($old) < time() - 7200) @unlink($old);
    $fmt = (string)($payload['format'] ?? '');
    if ($fmt === 'lignes') {
        $lines = [];
        foreach (array_slice((array)($payload['lignes'] ?? []), 0, 30000) as $ln) {
            $items = [];
            foreach (array_slice((array)($ln['i'] ?? []), 0, 200) as $it) if (is_array($it) && count($it) >= 3) $items[] = ['x' => (float)$it[0], 'w' => (float)$it[1], 's' => clean_str($it[2], 300)];
            $lines[] = ['page' => (int)($ln['p'] ?? 0), 'items' => $items];
        }
        $res = imp_parse_layout($lines);
    } elseif ($fmt === 'texte') {
        $res = imp_parse_text(clean_str(str_replace("\r", '', (string)($payload['texte'] ?? '')), 2000000) . "\n");
    } elseif ($token !== null) {
        $res = imp_parse_file($raw, $fname);
    } else fail(400, 'Aucun relevé reçu.');

    // aperçu : catégorie proposée et doublons déjà importés
    $ctx = class_ctx($u);
    $existing = [];
    if ($res['rows']) {
        $s = db()->prepare('SELECT id, date, amount, merchant, fp FROM tx WHERE user_id = ? AND date >= ? AND date <= ?');
        $s->execute([(int)$u['id'], date_add_days($res['rows'][0]['date'], -4), date_add_days($res['rows'][count($res['rows']) - 1]['date'], 4)]);
        $existing = $s->fetchAll();
    }
    $fpSet = []; foreach ($existing as $e) $fpSet[$e['fp']] = true;
    $occ = [];
    foreach ($res['rows'] as $i => &$r) {
        $c = cat_classify($r['label'], $r['amount'], $ctx);
        $k = $r['date'] . '|' . $r['amount'] . '|' . $c['norm'];
        $occ[$k] = ($occ[$k] ?? 0) + 1;
        $fp = sha1($k . '|' . $occ[$k]);
        $dup = isset($fpSet[$fp]) ? 'exact' : null;
        if ($dup === null) foreach ($existing as $e) {
            if ((int)$e['amount'] !== $r['amount'] || abs(days_between($e['date'], $r['date'])) > 3) continue;
            $a1 = merchant_key($c['merchant']); $a2 = merchant_key((string)$e['merchant']);
            similar_text($c['merchant'], (string)$e['merchant'], $pc);
            if ($a1 === $a2 || $pc >= 60) { $dup = 'probable'; break; }
        }
        $r += ['i' => $i, 'categorie' => $c['category'], 'nature' => $c['nature'], 'type' => $c['kind'], 'frais' => $c['fee'], 'contrepartie' => $c['merchant'], 'doublon' => $dup];
    }
    unset($r);
    out(200, $res + ['token' => $token, 'fichier' => $fname]);
}

case 'import.commit': {
    $u = auth(); $in = body();
    $uid = (int)$u['id'];
    $rows = array_slice((array)($in['rows'] ?? []), 0, 20000);
    $meta = (array)($in['meta'] ?? []);
    $ctx = class_ctx($u);
    $clean = [];
    foreach ($rows as $r) {
        $date = date_parse_any((string)($r['date'] ?? ''));
        $amt = (int)($r['amount'] ?? 0);
        $label = clean_str($r['label'] ?? '', 300);
        if ($date === null || $amt === 0 || $label === '') continue;
        $clean[] = ['date' => $date, 'amount' => $amt, 'label' => $label, 'include' => !isset($r['include']) || (bool)$r['include'],
            'categorie' => isset($r['categorie'], $cats[$r['categorie']]) ? $r['categorie'] : null, 'nature' => isset($r['nature'], NATURES[$r['nature']]) ? $r['nature'] : null];
    }
    if (!$clean) fail(400, 'Aucune opération valide à enregistrer.');
    $db = db();
    $db->beginTransaction();
    $st = $db->prepare('INSERT INTO statements (user_id, bank, account, period_start, period_end, opening, closing, sum_debit, sum_credit, check_status, check_detail, source, filename, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $deb = 0; $cre = 0; foreach ($clean as $r) if ($r['include']) { if ($r['amount'] < 0) $deb -= $r['amount']; else $cre += $r['amount']; }
    $dates = array_column($clean, 'date'); sort($dates);
    $chk = (array)($in['check'] ?? []);
    $st->execute([$uid, clean_str($meta['bank'] ?? '', 60), clean_str($meta['account'] ?? '', 40), date_parse_any((string)($meta['period_start'] ?? '')) ?? $dates[0],
        date_parse_any((string)($meta['period_end'] ?? '')) ?? $dates[count($dates) - 1], isset($meta['opening']) && is_numeric($meta['opening']) ? (int)$meta['opening'] : null,
        isset($meta['closing']) && is_numeric($meta['closing']) ? (int)$meta['closing'] : null, $deb, $cre, in_array($chk['status'] ?? '', ['ok', 'ecart', 'partiel', 'inconnu'], true) ? $chk['status'] : 'inconnu',
        clean_str($chk['detail'] ?? '', 300), clean_str($in['format'] ?? '', 20), clean_str($in['fichier'] ?? '', 120), time()]);
    $sid = (int)$db->lastInsertId();
    $ins = $db->prepare('INSERT OR IGNORE INTO tx (user_id, statement_id, date, amount, label, norm, merchant, kind, category, nature, fee, flags, common, manual, fp, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $occ = []; $new = 0; $dup = 0;
    foreach ($clean as $r) {
        $c = cat_classify($r['label'], $r['amount'], $ctx);
        $k = $r['date'] . '|' . $r['amount'] . '|' . $c['norm'];
        $occ[$k] = ($occ[$k] ?? 0) + 1;
        if (!$r['include']) continue;
        $manual = 0;
        if ($r['categorie'] !== null && $r['categorie'] !== $c['category']) { $c['category'] = $r['categorie']; $c['nature'] = $r['nature'] ?? ($ctx['nature_over'][$r['categorie']] ?? $cats[$r['categorie']][1]); $manual = 1; }
        elseif ($r['nature'] !== null && $r['nature'] !== $c['nature']) { $c['nature'] = $r['nature']; $manual = 1; }
        if ($r['amount'] > 0 && in_array($c['nature'], SPEND_NATURES, true)) $c['nature'] = 'entree';
        $ins->execute([$uid, $sid, $r['date'], $r['amount'], $r['label'], $c['norm'], $c['merchant'], $c['kind'], $c['category'], $c['nature'], (string)$c['fee'], implode(',', $c['flags']),
            $r['amount'] < 0 && in_array($c['category'], $ctx['common_cats'], true) ? 1 : 0, $manual, sha1($k . '|' . $occ[$k]), time()]);
        if ($ins->rowCount() > 0) $new++; else $dup++;
    }
    $db->prepare('UPDATE statements SET n_new = ?, n_dup = ? WHERE id = ?')->execute([$new, $dup, $sid]);
    // relevé d'origine conservé (chiffré) dans le coffre-fort
    $docId = null;
    $tok = (string)($in['token'] ?? '');
    if (!empty($in['garder_fichier']) && preg_match('/^[a-f0-9]{32}$/', $tok) && is_file($DATA_DIR . '/tmp/' . $tok . '.bin')) {
        $blob = vault_decrypt((string)file_get_contents($DATA_DIR . '/tmp/' . $tok . '.bin'), vault_key($DATA_DIR));
        [$head, $raw] = explode("\n", $blob, 2);
        $h = json_dec($head);
        if ((int)($h['uid'] ?? 0) === $uid) {
            $store = bin2hex(random_bytes(16)) . '.bin';
            file_put_contents($DATA_DIR . '/vault/' . $store, vault_encrypt($raw, vault_key($DATA_DIR)));
            $pe = date_parse_any((string)($meta['period_end'] ?? '')) ?? $dates[count($dates) - 1];
            $db->prepare('INSERT INTO docs (user_id, shared, type, title, doc_date, period, keep_until, meta, fname, mime, size, store, sha, created_at) VALUES (?, 0, \'releve\', ?, ?, ?, ?, \'{}\', ?, ?, ?, ?, ?, ?)')
                ->execute([$uid, 'Relevé ' . fr_month(substr($pe, 0, 7)) . (!empty($meta['bank']) ? ' — ' . clean_str($meta['bank'], 40) : ''), $pe, substr($pe, 0, 7), doc_keep_until('releve', $pe),
                    clean_str($h['name'] ?? 'releve', 120), (string)($h['mime'] ?? 'application/octet-stream'), strlen($raw), $store, hash('sha256', $raw), time()]);
            $docId = (int)$db->lastInsertId();
            $db->prepare('UPDATE statements SET doc_id = ? WHERE id = ?')->execute([$docId, $sid]);
        }
        @unlink($DATA_DIR . '/tmp/' . $tok . '.bin');
    }
    $db->commit();
    ev($uid, 'import', $new . ' opération(s), ' . $dup . ' doublon(s)');
    refresh_alerts($uid);
    if ($p = partner_of($uid)) refresh_alerts((int)$p['id']);
    $s = db()->prepare("SELECT COUNT(*) FROM alerts WHERE user_id = ? AND statut = 'ouverte' AND niveau = 'critique'"); $s->execute([$uid]);
    out(200, ['ok' => true, 'releve' => $sid, 'nouvelles' => $new, 'doublons' => $dup, 'document' => $docId, 'alertes_critiques' => (int)$s->fetchColumn()]);
}

case 'statements': {
    $u = auth();
    $s = db()->prepare('SELECT * FROM statements WHERE user_id = ? ORDER BY period_end DESC, id DESC');
    $s->execute([(int)$u['id']]);
    out(200, ['releves' => $s->fetchAll()]);
}

case 'statement.delete': {
    $u = auth(); $in = body();
    $id = (int)($in['id'] ?? 0);
    $s = db()->prepare('SELECT * FROM statements WHERE id = ? AND user_id = ?'); $s->execute([$id, (int)$u['id']]);
    if (!$s->fetch()) fail(404, 'Relevé introuvable.');
    db()->prepare('DELETE FROM tx WHERE statement_id = ? AND user_id = ?')->execute([$id, (int)$u['id']]);
    db()->prepare('DELETE FROM statements WHERE id = ?')->execute([$id]);
    ev((int)$u['id'], 'suppression_releve', '#' . $id);
    refresh_alerts((int)$u['id']);
    out(200, ['ok' => true]);
}

/* ---------------------------------------------------------------- opérations */
case 'tx': {
    $u = auth();
    $uid = (int)$u['id'];
    $who = (string)($_GET['qui'] ?? 'moi');
    $ids = [$uid];
    $partner = partner_of($uid);
    if ($who !== 'moi' && $partner && profile($partner)['partage_details']) $ids = $who === 'partenaire' ? [(int)$partner['id']] : [$uid, (int)$partner['id']];
    elseif ($who !== 'moi') fail(403, ($partner['name'] ?? 'Votre partenaire') . ' ne partage pas le détail de ses opérations.');
    $sql = 'SELECT * FROM tx WHERE user_id IN (' . implode(',', array_map('intval', $ids)) . ')'; $args = [];
    $m = (string)($_GET['mois'] ?? '');
    if (preg_match('/^\d{4}-\d{2}$/', $m)) { $sql .= ' AND date LIKE ?'; $args[] = $m . '-%'; }
    if (!empty($_GET['cat']) && isset($cats[$_GET['cat']])) { $sql .= ' AND category = ?'; $args[] = $_GET['cat']; }
    if (!empty($_GET['nature']) && isset(NATURES[$_GET['nature']])) { $sql .= ' AND nature = ?'; $args[] = $_GET['nature']; }
    if (!empty($_GET['ids'])) { $l = array_slice(array_filter(array_map('intval', explode(',', (string)$_GET['ids']))), 0, 200); if ($l) $sql .= ' AND id IN (' . implode(',', $l) . ')'; }
    $q = norm_text((string)($_GET['q'] ?? ''));
    if ($q !== '') { $sql .= ' AND norm LIKE ?'; $args[] = '%' . $q . '%'; }
    $sql .= ' ORDER BY date DESC, id DESC LIMIT 3000';
    $s = db()->prepare($sql); $s->execute($args);
    $out = [];
    foreach ($s->fetchAll() as $t) $out[] = tx_public($t, $cats, (int)$t['user_id'] === $uid);
    out(200, ['operations' => $out]);
}

case 'tx.update': {
    $u = auth(); $in = body();
    $uid = (int)$u['id'];
    $s = db()->prepare('SELECT * FROM tx WHERE id = ? AND user_id = ?'); $s->execute([(int)($in['id'] ?? 0), $uid]);
    $t = $s->fetch();
    if (!$t) fail(404, 'Opération introuvable.');
    $cat = isset($in['categorie'], $cats[$in['categorie']]) ? $in['categorie'] : $t['category'];
    $nat = isset($in['nature'], NATURES[$in['nature']]) ? $in['nature'] : ($cat !== $t['category'] ? (profile($u)['natures'][$cat] ?? $cats[$cat][1]) : $t['nature']);
    if ((int)$t['amount'] > 0 && in_array($nat, SPEND_NATURES, true)) $nat = 'entree';
    $common = array_key_exists('commune', $in) ? ((bool)$in['commune'] ? 1 : 0) : (int)$t['common'];
    $note = array_key_exists('note', $in) ? clean_str($in['note'], 200) : $t['note'];
    db()->prepare('UPDATE tx SET category = ?, nature = ?, common = ?, note = ?, manual = 1 WHERE id = ?')->execute([$cat, $nat, $common, $note, (int)$t['id']]);
    $applied = 0;
    if (!empty($in['regle'])) {
        $pat = norm_text((string)($in['motif'] ?? '')) ?: (string)$t['merchant'];
        if (strlen($pat) < 3) fail(400, 'Motif trop court pour une règle (3 caractères au moins).');
        $sens = (int)$t['amount'] > 0 ? 'credit' : 'debit';
        db()->prepare('INSERT INTO rules (user_id, pattern, sens, category, nature, common, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$uid, $pat, $sens, $cat, $nat, array_key_exists('commune', $in) ? $common : null, time()]);
        $q = db()->prepare('UPDATE tx SET category = ?, nature = ?, common = CASE WHEN ? IS NULL THEN common ELSE ? END WHERE user_id = ? AND manual = 0 AND norm LIKE ? AND ' . ($sens === 'credit' ? 'amount > 0' : 'amount < 0'));
        $cv = array_key_exists('commune', $in) ? $common : null;
        $q->execute([$cat, $nat, $cv, $cv, $uid, '%' . $pat . '%']);
        $applied = $q->rowCount();
    }
    refresh_alerts($uid);
    out(200, ['ok' => true, 'appliquees' => $applied]);
}

case 'rules': {
    $u = auth();
    $s = db()->prepare('SELECT * FROM rules WHERE user_id = ? ORDER BY id DESC'); $s->execute([(int)$u['id']]);
    out(200, ['regles' => $s->fetchAll()]);
}
case 'rule.delete': {
    $u = auth(); $in = body();
    db()->prepare('DELETE FROM rules WHERE id = ? AND user_id = ?')->execute([(int)($in['id'] ?? 0), (int)$u['id']]);
    out(200, ['ok' => true]);
}

/* ---------------------------------------------------------------- tableau de bord */
case 'dashboard': {
    $u = auth();
    $uid = (int)$u['id'];
    $p = profile($u);
    $vue = ($_GET['vue'] ?? 'moi') === 'foyer' ? 'foyer' : 'moi';
    $partner = partner_of($uid);
    $from = date('Y-m-d', strtotime('-15 months'));
    $mine = tx_load($uid, $from);
    $theirs = ($partner && profile($partner)['partage_totaux'] && $p['partage_totaux']) ? tx_load((int)$partner['id'], $from) : [];
    $txs = $vue === 'foyer' ? array_merge($mine, $theirs) : $mine;
    $months = an_months($txs);
    $mois = (string)($_GET['mois'] ?? '');
    if (!in_array($mois, $months, true)) $mois = $months ? $months[count($months) - 1] : date('Y-m');
    $cur = an_summary(an_month($txs, $mois));
    $prev = an_summary(an_month($txs, month_add($mois, -1)));
    $trend = [];
    foreach (array_slice($months, -12) as $m) { $s = an_summary(an_month($txs, $m)); $trend[] = ['mois' => $m, 'revenus' => $s['revenus'], 'depenses' => $s['depenses'], 'epargne' => $s['epargne'], 'nature' => $s['nature'], 'reste' => $s['reste']]; }
    $epDispo = $p['epargne_dispo'];
    if ($vue === 'foyer' && $partner && $theirs) {
        $pe = profile($partner)['epargne_dispo'];
        $epDispo = ($epDispo === null && $pe === null) ? null : (int)$epDispo + (int)$pe;
    }
    $score = an_score($cur, ['epargne_dispo' => $epDispo, 'essentiel_moyen' => avg_essentials($txs)]);
    $fees = an_fees($mine, $p);
    $rec = an_recurring($mine);
    $fc = an_forecast($mine, $rec, $p, start_balance($u), date('Y-m-d'));
    $equity = null;
    if ($vue === 'foyer' && $partner && $theirs) {
        $people = [];
        foreach ([[$u, $mine], [$partner, $theirs]] as [$x, $list]) {
            $ml = an_month($list, $mois);
            $sm = an_summary($ml); $verse = 0; $recu = 0;
            foreach ($ml as $t) if ($t['category'] === 'virement_foyer') { if ($t['amount'] < 0) $verse -= $t['amount']; else $recu += $t['amount']; }
            $people[] = ['id' => (int)$x['id'], 'nom' => $x['name'], 'revenus' => $sm['revenus'], 'communes' => $sm['communes'], 'verse' => $verse, 'recu' => $recu];
        }
        $equity = an_equity($people, (string)foyer()['mode']);
    }
    $a = db()->prepare("SELECT * FROM alerts WHERE user_id = ? AND statut = 'ouverte' ORDER BY CASE niveau WHEN 'critique' THEN 0 WHEN 'attention' THEN 1 WHEN 'bravo' THEN 2 ELSE 3 END, updated_at DESC, id DESC LIMIT 6");
    $a->execute([$uid]);
    $alerts = array_map(function ($r) { $d = json_dec($r['data']); unset($r['data']); return $r + $d; }, $a->fetchAll());
    $cnt = db()->prepare("SELECT niveau, COUNT(*) n FROM alerts WHERE user_id = ? AND statut = 'ouverte' GROUP BY niveau"); $cnt->execute([$uid]);
    $counts = []; foreach ($cnt->fetchAll() as $r) $counts[$r['niveau']] = (int)$r['n'];
    $budgets = [];
    foreach (budgets_of($uid) as $c => $lim) $budgets[] = ['categorie' => $c, 'label' => $cats[$c][0] ?? $c, 'icone' => $cats[$c][2] ?? '', 'limite' => $lim, 'depense' => (int)(an_summary(an_month($mine, $mois))['cats'][$c]['montant'] ?? 0)];
    // la prochaine meilleure action : une seule, la plus utile
    $next = null;
    if ($fees['a_reclamer'] > 0) $next = ['titre' => 'Réclamez ' . money_fmt($fees['a_reclamer']) . ' de frais prélevés au-delà des plafonds légaux', 'lien' => '#/frais'];
    elseif ($fc && $fc['premier_negatif'] && !$fc['ancien']) $next = ['titre' => 'Évitez le découvert prévu le ' . fr_date($fc['premier_negatif']) . ' : un virement ou un prélèvement décalé suffit', 'lien' => '#/alertes'];
    elseif ($cur['a_classer'] >= 5) $next = ['titre' => 'Classez ' . $cur['a_classer'] . ' opérations pour des conseils plus justes', 'lien' => '#/operations?nature=inconnu'];
    elseif ($score['composantes'][4]['points'] < 10) $next = ['titre' => $p['epargne_dispo'] === null ? 'Indiquez votre épargne disponible pour mesurer votre coussin de sécurité' : 'Construisez un coussin de 3 mois de dépenses essentielles, par un virement automatique', 'lien' => '#/reglages'];
    elseif ($cur['taux_epargne'] !== null && $cur['taux_epargne'] < 10) $next = ['titre' => 'Programmez un virement automatique vers l’épargne le lendemain de la paie', 'lien' => '#/reglages'];
    else $next = ['titre' => 'Tout est en ordre : passez vos abonnements en revue une fois par trimestre', 'lien' => '#/abonnements'];
    $abos = array_values(array_filter($rec, function ($r) { return $r['actif']; }));
    $fixes = 0; foreach ($abos as $r) $fixes += $r['mensuel'];
    out(200, ['vue' => $vue, 'mois' => $mois, 'mois_disponibles' => $months, 'resume' => $cur, 'precedent' => $prev, 'tendance' => $trend, 'score' => $score,
        'frais' => ['annee' => $fees['annee'], 'a_reclamer' => $fees['a_reclamer'], 'mois' => $fees['months'][$mois] ?? null], 'projection' => $vue === 'moi' ? $fc : null,
        'equite' => $equity, 'alertes' => $alerts, 'alertes_compte' => $counts, 'budgets' => $budgets, 'prochaine_action' => $next,
        'recurrents' => ['n' => count($abos), 'mensuel' => $fixes, 'prochains' => array_slice(array_values(array_filter($fc['echeances'] ?? [], function ($e) { return $e['montant'] < 0; })), 0, 6)],
        'partage' => ['partenaire' => $partner['name'] ?? null, 'inclus' => (bool)$theirs], 'solde_depart' => start_balance($u), 'derniere_operation' => an_last_date($mine)]);
}

/* ---------------------------------------------------------------- alertes */
case 'alerts': {
    $u = auth();
    $st = (string)($_GET['statut'] ?? 'ouverte');
    $sql = 'SELECT * FROM alerts WHERE user_id = ?' . ($st === 'tout' ? '' : ' AND statut = ?') . " ORDER BY CASE statut WHEN 'ouverte' THEN 0 ELSE 1 END, CASE niveau WHEN 'critique' THEN 0 WHEN 'attention' THEN 1 WHEN 'info' THEN 2 ELSE 3 END, COALESCE(mois, '9999') DESC, id DESC LIMIT 500";
    $s = db()->prepare($sql); $s->execute($st === 'tout' ? [(int)$u['id']] : [(int)$u['id'], in_array($st, ['ouverte', 'traitee', 'ignoree'], true) ? $st : 'ouverte']);
    out(200, ['alertes' => array_map(function ($r) { $d = json_dec($r['data']); unset($r['data']); return $r + $d; }, $s->fetchAll())]);
}
case 'alert.set': {
    $u = auth(); $in = body();
    $st = (string)($in['statut'] ?? '');
    if (!in_array($st, ['ouverte', 'traitee', 'ignoree'], true)) fail(400, 'Statut inconnu.');
    db()->prepare('UPDATE alerts SET statut = ?, updated_at = ? WHERE id = ? AND user_id = ?')->execute([$st, time(), (int)($in['id'] ?? 0), (int)$u['id']]);
    out(200, ['ok' => true]);
}
case 'alerts.refresh': {
    $u = auth(); body();
    refresh_alerts((int)$u['id']);
    out(200, ['ok' => true]);
}

/* ---------------------------------------------------------------- abonnements, frais, budgets */
case 'recurring': {
    $u = auth();
    $uid = (int)$u['id'];
    $rec = an_recurring(tx_load($uid, date('Y-m-d', strtotime('-15 months'))));
    $subs = subs_state($uid);
    foreach ($rec as &$r) { $r['etat'] = $subs[$r['key']]['state'] ?? ''; $r['categorie_label'] = $cats[$r['category']][0] ?? $r['category']; $r['icone'] = $cats[$r['category']][2] ?? ''; }
    unset($r);
    out(200, ['recurrents' => $rec]);
}
case 'sub.set': {
    $u = auth(); $in = body();
    $key = clean_str($in['cle'] ?? '', 80);
    $st = (string)($in['etat'] ?? '');
    if ($key === '') fail(400, 'Abonnement inconnu.');
    if ($st === '') db()->prepare('DELETE FROM subs WHERE user_id = ? AND mkey = ?')->execute([(int)$u['id'], $key]);
    elseif (in_array($st, ['utile', 'inutile', 'resilie'], true)) db()->prepare('INSERT OR REPLACE INTO subs (user_id, mkey, state, t) VALUES (?, ?, ?, ?)')->execute([(int)$u['id'], $key, $st, time()]);
    else fail(400, 'État inconnu.');
    refresh_alerts((int)$u['id']);
    out(200, ['ok' => true]);
}
case 'fees': {
    $u = auth();
    $txs = tx_load((int)$u['id'], date('Y-m-d', strtotime('-25 months')));
    $f = an_fees($txs, profile($u));
    $R = legal_refs();
    foreach ($f['opportunites'] as &$o) $o['refs_detail'] = array_map(function ($r) use ($R) { return ['code' => $r, 'titre' => $R[$r][0] ?? $r, 'texte' => $R[$r][1] ?? '']; }, $o['refs']);
    unset($o);
    out(200, $f + ['verifie' => LEGAL_VERIFIE]);
}
case 'budget.set': {
    $u = auth(); $in = body();
    $c = (string)($in['categorie'] ?? '');
    if (!isset($cats[$c])) fail(400, 'Catégorie inconnue.');
    $v = (int)($in['montant'] ?? 0);
    if ($v <= 0) db()->prepare('DELETE FROM budgets WHERE user_id = ? AND category = ?')->execute([(int)$u['id'], $c]);
    else db()->prepare('INSERT OR REPLACE INTO budgets (user_id, category, monthly) VALUES (?, ?, ?)')->execute([(int)$u['id'], $c, $v]);
    refresh_alerts((int)$u['id']);
    out(200, ['ok' => true, 'budgets' => budgets_of((int)$u['id'])]);
}

/* ---------------------------------------------------------------- courriers et droits */
case 'letter': {
    $u = auth(); $in = body();
    $id = (string)($in['id'] ?? '');
    $ctx = [];
    if (!empty($in['alerte'])) {
        $s = db()->prepare('SELECT data FROM alerts WHERE id = ? AND user_id = ?'); $s->execute([(int)$in['alerte'], (int)$u['id']]);
        $d = json_dec((string)$s->fetchColumn());
        foreach ((array)($d['actions'] ?? []) as $act) if (($act['type'] ?? '') === 'courrier' && $act['id'] === $id) { $ctx = (array)$act['ctx']; break; }
    } elseif (!empty($in['opportunite'])) {
        $txs = tx_load((int)$u['id'], date('Y-m-d', strtotime('-25 months')));
        $f = an_fees($txs, profile($u));
        $byId = []; foreach ($txs as $t) $byId[$t['id']] = $t;
        foreach ($f['opportunites'] as $o) if ($o['cle'] === $in['opportunite']) {
            $ctx = ['items' => letter_items($o['tx_ids'], $byId), 'montant' => $o['montant'], 'mois' => $o['mois'], 'motifs' => [$o['motif'] ?? $o['detail']], 'refs' => $o['refs'], 'signature' => $o['titre']];
            $ctx['total'] = array_sum(array_column($ctx['items'], 'montant'));
            $ctx['n_incidents'] = (int)($f['months'][$o['mois']]['n_incidents'] ?? 0);
            break;
        }
    } else {
        $c = (array)($in['ctx'] ?? []);
        foreach (['date', 'label', 'type', 'creancier', 'mois'] as $k) if (isset($c[$k])) $ctx[$k] = clean_str($c[$k], 200);
        foreach (['montant', 'jour', 'annee', 'total'] as $k) if (isset($c[$k])) $ctx[$k] = (int)$c[$k];
        if (isset($c['hausse']['avant'], $c['hausse']['apres'])) $ctx['hausse'] = ['avant' => (int)$c['hausse']['avant'], 'apres' => (int)$c['hausse']['apres']];
        if (!empty($ctx['label']) && empty($c['tx_ids'])) $ctx['items'] = [['date' => date_parse_any((string)($ctx['date'] ?? '')) ?? '', 'label' => $ctx['label'], 'montant' => (int)($ctx['montant'] ?? 0)]];
        if (!empty($c['tx_ids'])) {
            $ids = array_slice(array_map('intval', (array)$c['tx_ids']), 0, 50);
            $txs = tx_load((int)$u['id']);
            $byId = []; foreach ($txs as $t) $byId[$t['id']] = $t;
            $ctx['items'] = letter_items($ids, $byId);
            $ctx['total'] = array_sum(array_column($ctx['items'], 'montant'));
        }
    }
    out(200, letter_render($id, $ctx, profile($u)));
}
case 'legal': {
    auth();
    out(200, ['fiches' => legal_kb(), 'references' => legal_refs(), 'courriers' => letters_catalog(), 'verifie' => LEGAL_VERIFIE,
        'plafonds' => ['ci_op' => LEGAL_CI_OP, 'ci_mois' => LEGAL_CI_MOIS, 'rejet' => LEGAL_REJET_PRLV, 'cheque_bas' => LEGAL_CHEQUE_BAS, 'cheque_haut' => LEGAL_CHEQUE_HAUT, 'fragile' => LEGAL_FRAGILE_MOIS, 'sbi' => LEGAL_SBI]]);
}

/* ---------------------------------------------------------------- coffre-fort */
case 'docs': {
    $u = auth();
    $uid = (int)$u['id'];
    $types = doc_types();
    $noms = [];
    foreach (users() as $x) $noms[(int)$x['id']] = $x['name'];
    $list = [];
    foreach (docs_visible($uid) as $d) {
        $meta = json_dec($d['meta']);
        $list[] = ['id' => (int)$d['id'], 'a_moi' => (int)$d['user_id'] === $uid, 'partage' => (int)$d['shared'] === 1, 'type' => $d['type'], 'type_label' => $types[$d['type']][0] ?? $d['type'],
            'icone' => $types[$d['type']][1] ?? '📁', 'titre' => $d['title'], 'date' => $d['doc_date'], 'periode' => $d['period'], 'expiration' => $d['expiry'], 'conserver_jusqu' => $d['keep_until'],
            'conservation' => $types[$d['type']][2] ?? '', 'montant' => $d['amount'] === null ? null : (int)$d['amount'], 'meta' => (object)$meta, 'fichier' => $d['fname'], 'mime' => $d['mime'], 'taille' => (int)$d['size'],
            'ajoute' => (int)$d['created_at'], 'a_trier' => $d['keep_until'] !== null && $d['keep_until'] < date('Y-m-d'),
            'ext' => strtolower(pathinfo((string)$d['fname'], PATHINFO_EXTENSION)), 'apercu' => doc_inline_ext((string)$d['fname']), 'proprietaire' => $noms[(int)$d['user_id']] ?? ''];
    }
    // rapprochement bulletins de paie ↔ virements de salaire
    $allTx = tx_load($uid, date('Y-m-d', strtotime('-25 months')));
    $lastTx = an_last_date($allTx);
    $sal = array_values(array_filter($allTx, function ($t) { return $t['amount'] > 0 && $t['category'] === 'salaire'; }));
    foreach ($list as &$d) {
        $m = (array)$d['meta'];
        if ($d['type'] !== 'paie' || !$d['a_moi'] || empty($d['periode']) || empty($m['net'])) continue;
        $from = date_add_days($d['periode'] . '-' . days_in_month($d['periode']), -7); $to = month_add($d['periode'], 1) . '-12';
        $got = 0; foreach ($sal as $t) if ($t['date'] >= $from && $t['date'] <= $to) $got += $t['amount'];
        $d['rapprochement'] = ['recu' => $got, 'ecart' => $got - (int)$m['net'], 'attente' => $got === 0 && ($lastTx === null || $lastTx < date_add_days($to, -5))];
    }
    unset($d);
    out(200, ['documents' => $list]);
}

case 'doc.upload': {
    $u = auth(); need_post();
    $f = $_FILES['file'] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) fail(400, 'Aucun fichier reçu.');
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) fail(413, 'Fichier plus gros que la limite du serveur (upload_max_filesize = ' . ini_get('upload_max_filesize') . ').');
    if ($f['error'] !== UPLOAD_ERR_OK) fail(400, 'Envoi interrompu (code ' . (int)$f['error'] . ').');
    [$mime] = upload_check($f['tmp_name'], (string)$f['name'], (int)$f['size']);
    $types = doc_types();
    $type = isset($types[$_POST['type'] ?? '']) ? (string)$_POST['type'] : 'autre';
    $date = date_parse_any((string)($_POST['date'] ?? '')) ?? date('Y-m-d');
    $period = preg_match('/^\d{4}-\d{2}$/', (string)($_POST['periode'] ?? '')) ? (string)$_POST['periode'] : null;
    $expiry = date_parse_any((string)($_POST['expiration'] ?? ''));
    $meta = [];
    foreach (['net', 'net_imposable', 'brut', 'revenu_fiscal'] as $k) if (isset($_POST[$k]) && $_POST[$k] !== '') { $v = money_parse((string)$_POST[$k]); if ($v !== null) $meta[$k] = abs($v); }
    foreach (['employeur', 'annee_revenus', 'note'] as $k) if (!empty($_POST[$k])) $meta[$k] = clean_str($_POST[$k], 120);
    $amount = isset($_POST['montant']) && $_POST['montant'] !== '' ? money_parse((string)$_POST['montant']) : null;
    $title = clean_str($_POST['titre'] ?? '', 120) ?: doc_default_title((string)$f['name'], $types[$type][0], $period, $date);
    $raw = (string)file_get_contents($f['tmp_name']);
    $store = bin2hex(random_bytes(16)) . '.bin';
    if (file_put_contents($DATA_DIR . '/vault/' . $store, vault_encrypt($raw, vault_key($DATA_DIR))) === false) fail(500, 'Écriture impossible dans data/vault.');
    db()->prepare('INSERT INTO docs (user_id, shared, type, title, doc_date, period, expiry, keep_until, amount, meta, fname, mime, size, store, sha, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([(int)$u['id'], !empty($_POST['partage']) && $_POST['partage'] !== '0' ? 1 : 0, $type, $title, $date, $period, $expiry, doc_keep_until($type, $date), $amount === null ? null : abs($amount), json_enc($meta),
            clean_str($f['name'], 120), $mime, strlen($raw), $store, hash('sha256', $raw), time()]);
    $id = (int)db()->lastInsertId();
    ev((int)$u['id'], 'document_ajoute', $types[$type][0]);
    refresh_alerts((int)$u['id']);
    out(200, ['ok' => true, 'id' => $id]);
}

case 'doc.update': {
    $u = auth(); $in = body();
    $s = db()->prepare('SELECT * FROM docs WHERE id = ? AND user_id = ?'); $s->execute([(int)($in['id'] ?? 0), (int)$u['id']]);
    $d = $s->fetch();
    if (!$d) fail(404, 'Document introuvable (ou appartenant à votre partenaire).');
    $types = doc_types();
    $type = isset($in['type'], $types[$in['type']]) ? $in['type'] : $d['type'];
    $date = array_key_exists('date', $in) ? (date_parse_any((string)$in['date']) ?? $d['doc_date']) : $d['doc_date'];
    $meta = json_dec($d['meta']);
    foreach (['net', 'net_imposable', 'brut', 'revenu_fiscal'] as $k) if (array_key_exists($k, $in)) { $v = money_parse((string)$in[$k]); if ($v === null) unset($meta[$k]); else $meta[$k] = abs($v); }
    foreach (['employeur', 'annee_revenus', 'note'] as $k) if (array_key_exists($k, $in)) $meta[$k] = clean_str($in[$k], 120);
    db()->prepare('UPDATE docs SET type = ?, title = ?, doc_date = ?, period = ?, expiry = ?, keep_until = ?, shared = ?, meta = ? WHERE id = ?')->execute([
        $type, clean_str($in['titre'] ?? $d['title'], 120) ?: $d['title'], $date,
        array_key_exists('periode', $in) ? (preg_match('/^\d{4}-\d{2}$/', (string)$in['periode']) ? $in['periode'] : null) : $d['period'],
        array_key_exists('expiration', $in) ? date_parse_any((string)$in['expiration']) : $d['expiry'], doc_keep_until($type, $date),
        array_key_exists('partage', $in) ? ((bool)$in['partage'] ? 1 : 0) : (int)$d['shared'], json_enc($meta), (int)$d['id']]);
    refresh_alerts((int)$u['id']);
    out(200, ['ok' => true]);
}

case 'doc.delete': {
    $u = auth(); $in = body();
    $s = db()->prepare('SELECT * FROM docs WHERE id = ? AND user_id = ?'); $s->execute([(int)($in['id'] ?? 0), (int)$u['id']]);
    $d = $s->fetch();
    if (!$d) fail(404, 'Document introuvable (ou appartenant à votre partenaire).');
    @unlink($DATA_DIR . '/vault/' . basename((string)$d['store']));
    db()->prepare('DELETE FROM docs WHERE id = ?')->execute([(int)$d['id']]);
    db()->prepare('UPDATE statements SET doc_id = NULL WHERE doc_id = ?')->execute([(int)$d['id']]);
    ev((int)$u['id'], 'document_supprime', $d['title']);
    refresh_alerts((int)$u['id']);
    out(200, ['ok' => true]);
}

case 'doc.get': {
    $u = auth();
    $s = db()->prepare('SELECT * FROM docs WHERE id = ? AND (user_id = ? OR shared = 1)'); $s->execute([(int)($_GET['id'] ?? 0), (int)$u['id']]);
    $d = $s->fetch();
    if (!$d) fail(404, 'Document introuvable.');
    $path = $DATA_DIR . '/vault/' . basename((string)$d['store']);
    if (!is_file($path)) fail(404, 'Fichier absent du coffre (dossier vault incomplet : restaurez la dernière sauvegarde).');
    $raw = vault_decrypt((string)file_get_contents($path), vault_key($DATA_DIR));
    ev((int)$u['id'], 'document_ouvert', $d['title']);
    [$type, $affichable] = doc_serve_type((string)$d['fname'], $raw);
    $inline = $affichable && empty($_GET['dl']);
    header_remove('Content-Type');
    header('Content-Type: ' . $type);
    header('Content-Length: ' . strlen($raw));
    $fn = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)$d['fname']) ?: 'document';
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $fn . '"; filename*=UTF-8\'\'' . rawurlencode((string)$d['fname']));
    echo $raw;
    exit;
}

case 'dossiers': {
    $u = auth();
    $docs = docs_visible((int)$u['id']);
    $out = [];
    foreach (array_keys(dossiers()) as $id) $out[] = dossier_status($id, $docs, date('Y-m-d'));
    out(200, ['dossiers' => $out, 'zip' => true]);
}

case 'dossier.zip': {
    $u = auth();
    $st = dossier_status((string)($_GET['id'] ?? ''), docs_visible((int)$u['id']), date('Y-m-d'));
    if ($st === null) fail(404, 'Dossier inconnu.');
    $ids = [];
    foreach ($st['exigences'] as $r) foreach ($r['docs'] as $d) $ids[] = (int)$d['id'];
    if (!$ids) fail(404, 'Aucun document à rassembler pour ce dossier.');
    $byId = [];
    foreach (docs_visible((int)$u['id']) as $d) $byId[(int)$d['id']] = $d;
    $list = [];
    foreach (array_values(array_unique($ids)) as $i => $id) if (isset($byId[$id])) $list[] = ['doc' => $byId[$id], 'nom' => sprintf('%02d - ', $i + 1) . zip_safe_name((string)$byId[$id]['title'])];
    ev((int)$u['id'], 'dossier_telecharge', $st['titre']);
    send_docs_zip(zip_safe_name($st['titre']) . '.zip', $list);
}

case 'docs.zip': {
    // tous les documents visibles (les miens et ceux que le foyer partage), rangés par type, avec un inventaire
    $u = auth();
    $docs = docs_visible((int)$u['id']);
    if (!$docs) fail(404, 'Le coffre est vide.');
    $types = doc_types();
    $list = [];
    foreach ($docs as $d) {
        $quand = (string)($d['period'] ?: $d['doc_date'] ?: date('Y-m-d', (int)$d['created_at']));
        $list[] = ['doc' => $d, 'nom' => zip_safe_name($types[$d['type']][0] ?? 'Autres', 60) . '/' . zip_safe_name($quand . ' ' . $d['title'])];
    }
    ev((int)$u['id'], 'coffre_telecharge', count($list) . ' document(s)');
    send_docs_zip('boussole-coffre-' . date('Y-m-d') . '.zip', $list);
}

/* ---------------------------------------------------------------- sécurité, export, effacement */
case 'security': {
    $u = auth();
    $s = db()->prepare('SELECT ev, ip, ua, info, t FROM events WHERE user_id = ? ORDER BY id DESC LIMIT 60'); $s->execute([(int)$u['id']]);
    out(200, ['journal' => $s->fetchAll(), 'https' => is_https(), 'chiffrement' => vault_cipher()]);
}

case 'export': {
    $u = auth();
    $txs = tx_load((int)$u['id']);
    ev((int)$u['id'], 'export_csv', count($txs) . ' opérations');
    header_remove('Content-Type');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="boussole-operations-' . date('Y-m-d') . '.csv"');
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['Date', 'Libellé', 'Montant', 'Type', 'Catégorie', 'Nature', 'Frais bancaire', 'Dépense commune', 'Note'], ';', '"', '\\');
    $ft = fee_types();
    foreach ($txs as $t) fputcsv($o, [fr_date($t['date']), $t['label'], str_replace('.', ',', sprintf('%.2f', $t['amount'] / 100)), $t['kind'], $cats[$t['category']][0] ?? $t['category'], NATURES[$t['nature']][0] ?? $t['nature'], $t['fee'] !== '' ? ($ft[$t['fee']][0] ?? $t['fee']) : '', $t['common'] ? 'oui' : '', $t['note']], ';', '"', '\\');
    fclose($o);
    exit;
}

case 'me.purge': {
    $u = auth(); $in = body();
    if (!password_verify((string)($in['mot_de_passe'] ?? ''), $u['pw'])) fail(400, 'Mot de passe incorrect.');
    $uid = (int)$u['id'];
    $s = db()->prepare('SELECT store FROM docs WHERE user_id = ?'); $s->execute([$uid]);
    foreach ($s->fetchAll() as $d) @unlink($DATA_DIR . '/vault/' . basename((string)$d['store']));
    foreach (['tx', 'statements', 'docs', 'alerts', 'rules', 'budgets', 'subs'] as $t) db()->prepare('DELETE FROM ' . $t . ' WHERE user_id = ?')->execute([$uid]);
    ev($uid, 'donnees_effacees');
    out(200, ['ok' => true]);
}

default:
    fail(404, 'Action inconnue.');
}

/** Recatégorise toutes les opérations non modifiées à la main (après un changement d'alias, de natures, de dépenses communes). */
function reclassify(int $uid, bool $commonOnly = false): void {
    $u = user_by_id($uid);
    if (!$u) return;
    $ctx = class_ctx($u);
    $db = db();
    $db->beginTransaction();
    $upd = $db->prepare('UPDATE tx SET merchant = ?, kind = ?, category = ?, nature = ?, fee = ?, flags = ?, common = ? WHERE id = ?');
    $updC = $db->prepare('UPDATE tx SET common = ? WHERE id = ?');
    foreach (tx_load($uid) as $t) {
        if ($commonOnly || $t['manual']) {
            $updC->execute([$t['amount'] < 0 && in_array($t['category'], $ctx['common_cats'], true) ? 1 : 0, $t['id']]);
            continue;
        }
        $c = cat_classify($t['label'], $t['amount'], $ctx);
        $upd->execute([$c['merchant'], $c['kind'], $c['category'], $c['nature'], (string)$c['fee'], implode(',', $c['flags']), $c['common'], $t['id']]);
    }
    $db->commit();
}
