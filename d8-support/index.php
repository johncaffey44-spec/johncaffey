<?php
/**
 * ============================================================================
 *  D8 Support — outil de ticketing interne — FICHIER UNIQUE
 * ============================================================================
 *
 *  Tout l'outil tient dans ce seul fichier PHP, à nommer index.php.
 *    - IIS    : double-cliquer sur INSTALLER.cmd (livré à côté de ce fichier) :
 *               IIS, PHP, droits sur data\, sauvegarde quotidienne, tout est
 *               fait automatiquement. Voir LISEZ-MOI / README.md.
 *    - Apache : C:\xampp\htdocs\ticketing\index.php (XAMPP).
 *    Puis ouvrir http://<serveur>/ticketing/index.php
 *  La base de données et le dossier data/ se créent tout seuls au 1er accès.
 *
 *  Pourquoi .php et non .html : un .html seul ne peut pas partager les tickets
 *  entre plusieurs postes, ni gérer les comptes, ni envoyer d'e-mail. Il faut
 *  un langage côté serveur ; PHP est présent sur XAMPP/WampServer sans rien
 *  installer de plus.
 *
 *  Sécurité : toutes les données (base, sessions, pièces jointes, journaux,
 *  code d'installation) vivent dans data/ sous des noms préfixés « .ht_ »
 *  qu'Apache refuse de servir par défaut ; un .htaccess et un web.config y sont
 *  aussi déposés automatiquement (pour IIS et Apache « AllowOverride All »).
 *  Les sessions sont stockées DANS la base, jamais dans des fichiers.
 *
 *  Vérifier l'installation : http://<serveur>/ticketing/index.php?page=verification
 *  Sauvegarde planifiée    : php index.php sauvegarde [dossier] [nb à garder]
 * ============================================================================
 */
declare(strict_types=1);

/* ===================== Couche données + sessions (base) ==================== */
/**
 * D8 Support — couche base de données (SQLite)
 * Aucune configuration nécessaire : la base est créée automatiquement
 * au premier lancement dans data/ticketing.sqlite
 */

date_default_timezone_set('Europe/Paris');

/*
 * Les fichiers de données portent le préfixe « .ht » : Apache refuse de
 * servir les fichiers .ht* dans sa configuration par défaut (Ubuntu comme
 * XAMPP), même si le .htaccess du dossier data/ était ignoré. Double filet
 * de sécurité, en plus du .htaccess (Apache) et du web.config (IIS).
 */
define('DB_DIR', __DIR__ . '/data');
define('DB_PATH', DB_DIR . '/.ht_ticketing.sqlite');
define('UPLOAD_DIR', DB_DIR . '/uploads');

/**
 * Gestionnaire de sessions stockées DANS LA BASE (table « sessions »).
 *
 * Pourquoi : la version en fichiers écrivait les sessions dans data/.ht_sessions/.
 * Or, sous la configuration Apache par défaut (« AllowOverride None », celle de
 * XAMPP/WampServer), le .htaccess du dossier data/ est ignoré ; les fichiers de
 * session, dont le nom ne commence pas par « .ht », pouvaient alors être listés
 * et lus depuis le réseau — de quoi usurper une session ouverte. En stockant les
 * sessions dans le fichier .ht_ticketing.sqlite (qu'Apache refuse de servir par
 * défaut, sans dépendre du .htaccess), il n'existe plus aucun fichier de session
 * à exposer. La protection ne dépend plus de la configuration du serveur.
 */
final class SessionSqlite implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    private const DUREE = 12 * 3600; // inactivité maximale : 12 h

    public function open($chemin, $nom): bool { return true; }
    public function close(): bool { return true; }

    #[\ReturnTypeWillChange]
    public function read($id): string
    {
        $st = db()->prepare('SELECT data FROM sessions WHERE id = ? AND updated_at >= ?');
        $st->execute([$id, time() - self::DUREE]);
        $d = $st->fetchColumn();
        return $d === false ? '' : (string) $d;
    }

    public function write($id, $data): bool
    {
        $st = db()->prepare('INSERT INTO sessions (id, data, updated_at) VALUES (?, ?, ?)
                             ON CONFLICT(id) DO UPDATE SET data = excluded.data, updated_at = excluded.updated_at');
        return $st->execute([$id, $data, time()]);
    }

    public function destroy($id): bool
    {
        db()->prepare('DELETE FROM sessions WHERE id = ?')->execute([$id]);
        return true;
    }

    #[\ReturnTypeWillChange]
    public function gc($max_lifetime): int|false
    {
        $st = db()->prepare('DELETE FROM sessions WHERE updated_at < ?');
        $st->execute([time() - max(60, (int) $max_lifetime)]);
        return $st->rowCount();
    }

    // Mode strict : PHP demande au gestionnaire si un identifiant existe déjà,
    // ce qui empêche la « fixation » de session (un identifiant imposé de
    // l'extérieur qui deviendrait valide).
    public function validateId($id): bool
    {
        $st = db()->prepare('SELECT 1 FROM sessions WHERE id = ? AND updated_at >= ?');
        $st->execute([$id, time() - self::DUREE]);
        return (bool) $st->fetchColumn();
    }

    public function updateTimestamp($id, $data): bool
    {
        db()->prepare('UPDATE sessions SET updated_at = ? WHERE id = ?')->execute([time(), $id]);
        return true;
    }
}

/**
 * Démarrage de session maîtrisé.
 *
 * - Les sessions vivent dans la base (voir SessionSqlite) : rien sur le disque
 *   web, donc rien à exposer, quelle que soit la configuration du serveur.
 * - Durée de vie propre à l'outil (12 h), indépendante du nettoyage global de
 *   PHP qui, sous XAMPP, déconnectait les gens au bout de 24 minutes.
 * - Nom de cookie propre à chaque installation (deux copies test/production sur
 *   le même serveur ne se marchent plus dessus).
 * - Le cookie ne dépasse pas le dossier de l'application.
 */
function demarrer_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    db(); // garantit l'existence de la base et de la table « sessions »
    session_set_save_handler(new SessionSqlite(), true);

    ini_set('session.gc_maxlifetime', (string) (12 * 3600));
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '200');
    ini_set('session.use_strict_mode', '1');
    session_name('tk_' . substr(md5(__DIR__), 0, 8));

    $chemin = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/') . '/';
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'path'     => $chemin,
        'secure'   => (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
                      || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
    ]);
    session_start();
}

/** Chemin web de l'application (pour les cookies de préférences côté navigateur). */
function chemin_application(): string
{
    return rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/') . '/';
}

/** Connexion PDO unique (créée à la demande). */
function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    if (!is_dir(DB_DIR)) {
        @mkdir(DB_DIR, 0775, true);
    }
    if (!is_dir(UPLOAD_DIR)) {
        @mkdir(UPLOAD_DIR, 0775, true);
    }
    // Empêche le listage du contenu des dossiers si l'index de répertoire est actif.
    foreach ([DB_DIR, UPLOAD_DIR] as $dir) {
        if (is_dir($dir) && !file_exists($dir . '/index.html')) {
            @file_put_contents($dir . '/index.html', '');
        }
    }

    // Interdiction d'accès web au dossier data/ — recréée si elle manque.
    //  - Apache « AllowOverride None » (défaut XAMPP) : le préfixe .ht_ suffit
    //    déjà (règle serveur « <FilesMatch ^\.ht> Require all denied »).
    //  - Apache « AllowOverride All » : le .htaccess ci-dessous refuse tout.
    //  - IIS : pas de convention .ht_ ; on dépose un web.config, mais SEULEMENT
    //    quand on tourne réellement sous IIS (sinon un fichier inutile — et
    //    servi — traînerait sous Apache). Filtrage de requêtes uniquement :
    //    module de base toujours présent, aucune erreur possible.
    if (is_dir(DB_DIR)) {
        $htaccess = DB_DIR . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess,
                "# Dossier de donnees : aucun acces web.\n"
                . "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
        }
        $sousIIS = stripos((string) ($_SERVER['SERVER_SOFTWARE'] ?? ''), 'IIS') !== false;
        $webconfig = DB_DIR . '/web.config';
        if ($sousIIS && !file_exists($webconfig)) {
            @file_put_contents($webconfig,
                "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
                . "<configuration>\n  <system.webServer>\n"
                . "    <directoryBrowse enabled=\"false\" />\n"
                . "    <security><requestFiltering>\n"
                . "      <fileExtensions>\n"
                . "        <add fileExtension=\".sqlite\" allowed=\"false\" />\n"
                . "        <add fileExtension=\".log\" allowed=\"false\" />\n"
                . "        <add fileExtension=\".txt\" allowed=\"false\" />\n"
                . "        <add fileExtension=\".zip\" allowed=\"false\" />\n"
                . "        <add fileExtension=\".tmp\" allowed=\"false\" />\n"
                . "      </fileExtensions>\n"
                . "      <hiddenSegments>\n"
                . "        <add segment=\"uploads\" />\n"
                . "        <add segment=\"sauvegardes\" />\n"
                . "      </hiddenSegments>\n"
                . "    </requestFiltering></security>\n"
                . "  </system.webServer>\n</configuration>\n");
        }
    }
    if (!is_dir(DB_DIR) || !is_writable(DB_DIR)) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'error' => "Le dossier data/ n'est pas accessible en écriture. Donnez les droits d'écriture au serveur web sur ce dossier.",
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $premierLancement = !file_exists(DB_PATH);

    $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 10,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    // Deux personnes qui enregistrent en même temps : au lieu d'échouer
    // immédiatement sur « database is locked », on patiente 10 secondes.
    $pdo->exec('PRAGMA busy_timeout = 10000');
    try {
        $pdo->exec('PRAGMA journal_mode = WAL');
    } catch (Throwable $e) {
        // WAL indisponible sur certains partages réseau : on continue en mode normal.
    }

    // Table des sessions : stockées en base, jamais dans des fichiers web.
    // « IF NOT EXISTS » : couvre une base neuve comme une base déjà créée par
    // l'ancienne version en plusieurs fichiers.
    $pdo->exec('CREATE TABLE IF NOT EXISTS sessions (
        id TEXT PRIMARY KEY, data TEXT NOT NULL, updated_at INTEGER NOT NULL)');

    // Fonction « sansaccent » utilisable dans les requêtes : SQLite ignore
    // les accents et ne sait comparer que l'ASCII sans tenir compte de la
    // casse. Sans elle, chercher « deregle » ne trouvait pas « déréglée »,
    // ce qui est le cas courant quand on tape vite.
    if (method_exists($pdo, 'sqliteCreateFunction')) {
        @$pdo->sqliteCreateFunction('sansaccent', 'sans_accent', 1);
    }

    if ($premierLancement) {
        init_schema($pdo);
    }

    return $pdo;
}

/**
 * Version comparable d'un texte : minuscules et sans accents.
 * Utilisée par la recherche pour que « deregle », « Déréglé » et
 * « DÉRÉGLÉE » se retrouvent mutuellement.
 */
function sans_accent(?string $texte): string
{
    if ($texte === null || $texte === '') {
        return '';
    }
    // La table couvre les majuscules ET les minuscules accentuées, et rend
    // directement des minuscules : la fonction reste juste même si
    // l'extension mbstring n'est pas chargée sur le serveur, ce qui arrive.
    static $accents = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
        'À' => 'a', 'Á' => 'a', 'Â' => 'a', 'Ã' => 'a', 'Ä' => 'a', 'Å' => 'a',
        'ç' => 'c', 'Ç' => 'c',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'È' => 'e', 'É' => 'e', 'Ê' => 'e', 'Ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'Ì' => 'i', 'Í' => 'i', 'Î' => 'i', 'Ï' => 'i',
        'ñ' => 'n', 'Ñ' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o',
        'Ò' => 'o', 'Ó' => 'o', 'Ô' => 'o', 'Õ' => 'o', 'Ö' => 'o', 'Ø' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
        'Ù' => 'u', 'Ú' => 'u', 'Û' => 'u', 'Ü' => 'u',
        'ý' => 'y', 'ÿ' => 'y', 'Ý' => 'y',
        'œ' => 'oe', 'Œ' => 'oe', 'æ' => 'ae', 'Æ' => 'ae', 'ß' => 'ss',
    ];
    // Les accents d'abord (des deux casses), puis l'ASCII en minuscules.
    return strtolower(strtr($texte, $accents));
}

/** Date/heure courante au format stocké en base. */
function now(): string
{
    return date('Y-m-d H:i:s');
}

/**
 * Même horodatage, à la microseconde. Réservé aux champs qui servent à
 * comparer « qu'est-ce qui est arrivé après quoi » : la date de dernière
 * modification d'un ticket et la date de dernière consultation. À la
 * seconde près, une réponse arrivant dans la même seconde qu'une lecture
 * passait pour déjà vue et le demandeur n'était pas prévenu.
 */
function now_us(): string
{
    $t = microtime(true);
    return date('Y-m-d H:i:s', (int) $t) . sprintf('.%06d', (int) round(($t - floor($t)) * 1000000));
}

/** Création des tables et des réglages par défaut (adaptés à D8). */
function init_schema(PDO $pdo): void
{
    $pdo->exec(<<<SQL
CREATE TABLE users (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT NOT NULL,
    -- L'identifiant de connexion et l'adresse email sont deux choses
    -- différentes : dans un annuaire d'entreprise on se connecte avec
    -- « m.lefevre », pas avec son adresse. Les mélanger rendait les comptes
    -- venus de l'annuaire impossibles à modifier.
    login       TEXT NOT NULL DEFAULT '' COLLATE NOCASE,
    email       TEXT NOT NULL DEFAULT '' COLLATE NOCASE,
    password    TEXT NOT NULL,
    role        TEXT NOT NULL DEFAULT 'employe' CHECK (role IN ('employe','admin')),
    phone       TEXT NOT NULL DEFAULT '',
    -- 'local' : mot de passe géré ici. 'annuaire' : vérifié auprès d'Active
    -- Directory à chaque connexion, aucun mot de passe stocké.
    auth        TEXT NOT NULL DEFAULT 'local' CHECK (auth IN ('local','annuaire')),
    active      INTEGER NOT NULL DEFAULT 1,
    created_at  TEXT NOT NULL
);

CREATE UNIQUE INDEX idx_users_login ON users(login) WHERE login != '';
CREATE UNIQUE INDEX idx_users_email ON users(email) WHERE email != '';

CREATE TABLE tickets (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    ref         TEXT UNIQUE,
    title       TEXT NOT NULL,
    description TEXT NOT NULL,
    category    TEXT NOT NULL,
    site        TEXT NOT NULL DEFAULT '',
    priority    TEXT NOT NULL DEFAULT 'normale' CHECK (priority IN ('basse','normale','haute','critique')),
    status      TEXT NOT NULL DEFAULT 'nouveau' CHECK (status IN ('nouveau','en_cours','en_attente','resolu','ferme')),
    created_by  INTEGER NOT NULL REFERENCES users(id),
    assigned_to INTEGER REFERENCES users(id),
    created_at  TEXT NOT NULL,
    updated_at  TEXT NOT NULL,
    closed_at   TEXT,
    -- Temps passé sur le ticket, en minutes. Sert au suivi de charge du
    -- service : c'est le chiffre qui manque quand il faut justifier
    -- l'activité informatique devant une direction.
    time_spent  INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX idx_tickets_status   ON tickets(status);
CREATE INDEX idx_tickets_creator  ON tickets(created_by);
CREATE INDEX idx_tickets_assignee ON tickets(assigned_to);
CREATE INDEX idx_tickets_updated  ON tickets(updated_at);

CREATE TABLE comments (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    ticket_id   INTEGER NOT NULL REFERENCES tickets(id) ON DELETE CASCADE,
    user_id     INTEGER NOT NULL REFERENCES users(id),
    body        TEXT NOT NULL,
    is_system   INTEGER NOT NULL DEFAULT 0,
    is_internal INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT NOT NULL
);
CREATE INDEX idx_comments_ticket ON comments(ticket_id);

CREATE TABLE attachments (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    ticket_id   INTEGER NOT NULL REFERENCES tickets(id) ON DELETE CASCADE,
    comment_id  INTEGER REFERENCES comments(id) ON DELETE CASCADE,
    orig_name   TEXT NOT NULL,
    stored_name TEXT NOT NULL,
    mime        TEXT NOT NULL,
    size        INTEGER NOT NULL,
    uploaded_by INTEGER NOT NULL REFERENCES users(id),
    created_at  TEXT NOT NULL
);
CREATE INDEX idx_attach_ticket ON attachments(ticket_id);

-- Dernière consultation d'un ticket par un utilisateur : sert au repérage
-- des nouveautés (lignes en gras, compteur du menu, son de notification).
CREATE TABLE views (
    user_id   INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    ticket_id INTEGER NOT NULL REFERENCES tickets(id) ON DELETE CASCADE,
    seen_at   TEXT NOT NULL,
    PRIMARY KEY (user_id, ticket_id)
);

-- Journal des connexions : repérage des comptes inutilisés et des
-- tentatives d'accès infructueuses.
CREATE TABLE logins (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER,
    email      TEXT NOT NULL,
    success    INTEGER NOT NULL,
    ip         TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL
);
CREATE INDEX idx_logins_date ON logins(created_at);

-- Procédures : fiches rédigées par le service informatique. Elles servent
-- à deux choses avec un seul entretien — consultables par le personnel
-- (« Aide »), et insérables en un clic dans une réponse.
CREATE TABLE procedures (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    title       TEXT NOT NULL,
    body        TEXT NOT NULL,
    category    TEXT NOT NULL DEFAULT '',
    public      INTEGER NOT NULL DEFAULT 1,   -- visible par le personnel
    modele      INTEGER NOT NULL DEFAULT 0,   -- proposée comme réponse type
    position    INTEGER NOT NULL DEFAULT 0,
    updated_at  TEXT NOT NULL,
    updated_by  INTEGER
);
CREATE INDEX idx_procedures_ordre ON procedures(position, id);

CREATE TABLE settings (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL
);
SQL);

    // Réglages par défaut — modifiables ensuite dans l'écran « Réglages ».
    $categories = [
        'Matériel (PC, écran, imprimante…)',
        'Logiciel / Bureautique',
        'Réseau / Internet',
        'Messagerie / Microsoft 365',
        'ERP Vega',
        'GED Zeendoc',
        'Téléphonie',
        'PDA / Terminaux mobiles',
        'Autre',
    ];
    $sites = ['Vitry-sur-Seine', 'Saint-Maximin', 'Jargeau'];

    $defauts = [
        'app_name'            => 'D8 Support',
        'ref_prefix'          => 'D8',
        'categories'          => json_encode($categories, JSON_UNESCAPED_UNICODE),
        'sites'               => json_encode($sites, JSON_UNESCAPED_UNICODE),
        'stale_days'          => '3',   // ticket sans activité au-delà : signalé
        'auto_close_days'     => '7',   // ticket résolu depuis N jours : fermé (0 = jamais)
        'idle_minutes'        => '0',   // déconnexion après inactivité (0 = jamais)
        'purge_months'        => '0',   // suppression des tickets fermés anciens (0 = jamais)
        'ldap_enabled'        => '0',
        'ldap_host'           => '',
        'ldap_port'           => '389',
        'ldap_secure'         => 'none',
        'ldap_bind_format'    => '',
        'ldap_base_dn'        => '',
        'ldap_login_attr'     => 'userPrincipalName',
        'ldap_autocreate'     => '1',
        'allow_user_password' => '0',   // les employés peuvent-ils changer leur mot de passe
        'mail_enabled'        => '0',
        'mail_host'           => '',
        'mail_port'           => '587',
        'mail_secure'         => 'tls',
        'mail_user'           => '',
        'mail_pass'           => '',
        'mail_from'           => '',
        'mail_from_name'      => 'Support informatique',
        'base_url'            => '',    // adresse de l'outil, reprise dans les emails
        'last_autoclose'      => '',
    ];
    $ins = $pdo->prepare('INSERT INTO settings ("key", value) VALUES (?, ?)');
    foreach ($defauts as $k => $v) {
        $ins->execute([$k, $v]);
    }

    $fiches = [
        ['Prise en charge', '', 0, 1,
         "Bonjour,\n\nJe prends votre demande en charge et je reviens vers vous dès que j'ai avancé.\n\nCordialement"],
        ['Demande de précision', '', 0, 1,
         "Bonjour,\n\nPour avancer, pouvez-vous me préciser :\n- à quel moment le problème apparaît ;\n- le message exact affiché à l'écran (une photo suffit) ;\n- si d'autres personnes du même bureau rencontrent la même chose.\n\nMerci d'avance"],
        ['Intervention planifiée', '', 0, 1,
         "Bonjour,\n\nJe passe sur votre poste dans la journée pour régler le problème. Merci de laisser l'ordinateur allumé et la session ouverte.\n\nCordialement"],
        ['Résolution', '', 0, 1,
         "Bonjour,\n\nLe problème est corrigé de mon côté. Pouvez-vous vérifier que tout fonctionne et fermer le ticket si c'est bon ?\n\nCordialement"],
        ['Mon ordinateur est lent : les premiers réflexes', 'Matériel (PC, écran, imprimante…)', 1, 0,
         "1. Enregistrez votre travail, puis redémarrez le poste (Démarrer > Redémarrer, et non « Arrêter »).\n2. Fermez les programmes que vous n'utilisez pas, en particulier les onglets du navigateur.\n3. Si la lenteur revient chaque jour à la même heure, notez-le dans votre ticket : c'est souvent la sauvegarde ou une mise à jour.\n\nSi rien n'y fait, ouvrez un ticket en précisant depuis quand et sur quelles applications."],
        ['Une imprimante ne répond plus', 'Matériel (PC, écran, imprimante…)', 1, 0,
         "1. Vérifiez que le voyant est vert fixe et qu'il reste du papier.\n2. Éteignez l'imprimante, attendez dix secondes, rallumez-la.\n3. Relancez l'impression depuis le document.\n\nSi le voyant clignote en orange, notez la couleur et le rythme dans votre ticket : cela indique la panne."],
    ];
    $ip = $pdo->prepare('INSERT INTO procedures (title, category, public, modele, body, position, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach ($fiches as $i => $f) {
        $ip->execute([$f[0], $f[1], $f[2], $f[3], $f[4], $i, date('Y-m-d H:i:s')]);
    }
}

/** Lit un réglage simple, avec cache pour éviter de relire la table. */
function setting_get(string $key, string $defaut = ''): string
{
    if (!isset($GLOBALS['__settings'])) {
        $GLOBALS['__settings'] = [];
        foreach (db()->query('SELECT "key", value FROM settings') as $r) {
            $GLOBALS['__settings'][$r['key']] = $r['value'];
        }
    }
    return array_key_exists($key, $GLOBALS['__settings']) ? $GLOBALS['__settings'][$key] : $defaut;
}

/** Écrit un réglage simple. */
function setting_set(string $key, string $value): void
{
    db()->prepare('INSERT INTO settings ("key", value) VALUES (?, ?)
                   ON CONFLICT("key") DO UPDATE SET value = excluded.value')
        ->execute([$key, $value]);
    unset($GLOBALS['__settings']);
}

/** Lecture d'un réglage de type liste (categories, sites). */
function setting_list(string $key): array
{
    $st = db()->prepare('SELECT value FROM settings WHERE "key" = ?');
    $st->execute([$key]);
    $raw = $st->fetchColumn();
    if ($raw === false) {
        return [];
    }
    $arr = json_decode((string) $raw, true);
    return is_array($arr) ? $arr : [];
}

/** Écriture d'un réglage de type liste. */
function setting_save_list(string $key, array $values): void
{
    db()->prepare('INSERT INTO settings ("key", value) VALUES (?, ?)
                   ON CONFLICT("key") DO UPDATE SET value = excluded.value')
        ->execute([$key, json_encode(array_values($values), JSON_UNESCAPED_UNICODE)]);
    unset($GLOBALS['__settings']);
}

/* ============================ Envoi d'e-mails ============================= */
/**
 * D8 Support — envoi d'emails
 * Client SMTP minimal (aucune bibliothèque à installer).
 * Gère SMTP simple, STARTTLS et SSL implicite, avec authentification AUTH LOGIN.
 * Toute erreur est renvoyée en texte : l'application ne doit jamais planter
 * parce qu'un serveur de messagerie ne répond pas.
 */

/** Lit une réponse SMTP complète (gère les réponses multi-lignes). */
function smtp_lire($sock, string &$trace): string
{
    $reponse = '';
    while (($ligne = fgets($sock, 1024)) !== false) {
        $reponse .= $ligne;
        $trace .= '< ' . rtrim($ligne) . "\n";
        // Dernière ligne : « 250 texte » et non « 250-texte »
        if (strlen($ligne) >= 4 && $ligne[3] === ' ') {
            break;
        }
    }
    return $reponse;
}

/** Envoie une commande et vérifie le code de retour attendu. */
function smtp_cmd($sock, string $cmd, array $codesOk, string &$trace, bool $secret = false): void
{
    $trace .= '> ' . ($secret ? '(masqué)' : rtrim($cmd)) . "\n";
    fwrite($sock, $cmd . "\r\n");
    $r = smtp_lire($sock, $trace);
    $code = substr($r, 0, 3);
    if (!in_array($code, $codesOk, true)) {
        throw new RuntimeException('Le serveur a répondu : ' . trim($r));
    }
}

/**
 * Envoie un email. Renvoie ['ok' => bool, 'error' => string, 'trace' => string].
 * Ne lève jamais d'exception vers l'appelant.
 */
function smtp_envoyer(array $cfg, string $destinataire, string $sujet, string $corps): array
{
    $trace = '';
    $sock = null;
    try {
        if (empty($cfg['host']) || empty($cfg['from'])) {
            throw new RuntimeException('Serveur ou adresse d\'expédition non renseignés.');
        }
        // Dernier rempart : aucune valeur reprise dans un en-tête ne doit
        // contenir de saut de ligne, sinon un destinataire caché pourrait
        // être ajouté au message.
        foreach ([$destinataire, $sujet, (string) $cfg['from'], (string) ($cfg['from_name'] ?? ''),
                  (string) ($cfg['reply_to'] ?? '')] as $valeur) {
            if (preg_match('/[\r\n]/', $valeur)) {
                throw new RuntimeException('Valeur invalide dans un en-tête du message.');
            }
        }
        $port   = (int) ($cfg['port'] ?: 587);
        $secure = $cfg['secure'] ?? 'tls';
        $hote   = ($secure === 'ssl' ? 'ssl://' : '') . $cfg['host'];

        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $sock = @stream_socket_client($hote . ':' . $port, $errno, $errstr, 8, STREAM_CLIENT_CONNECT, $ctx);
        if (!$sock) {
            throw new RuntimeException("Connexion impossible à {$cfg['host']}:{$port} ({$errstr})");
        }
        stream_set_timeout($sock, 10);
        smtp_lire($sock, $trace);

        $nomLocal = gethostname() ?: 'localhost';
        smtp_cmd($sock, 'EHLO ' . $nomLocal, ['250'], $trace);

        if ($secure === 'tls') {
            smtp_cmd($sock, 'STARTTLS', ['220'], $trace);
            if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Le chiffrement TLS a échoué. Essayez « SSL » ou « Aucun ».');
            }
            $trace .= "  (TLS activé)\n";
            smtp_cmd($sock, 'EHLO ' . $nomLocal, ['250'], $trace);
        }

        if (!empty($cfg['user'])) {
            smtp_cmd($sock, 'AUTH LOGIN', ['334'], $trace);
            smtp_cmd($sock, base64_encode((string) $cfg['user']), ['334'], $trace, true);
            smtp_cmd($sock, base64_encode((string) $cfg['pass']), ['235'], $trace, true);
        }

        smtp_cmd($sock, 'MAIL FROM:<' . $cfg['from'] . '>', ['250'], $trace);
        smtp_cmd($sock, 'RCPT TO:<' . $destinataire . '>', ['250', '251'], $trace);
        smtp_cmd($sock, 'DATA', ['354'], $trace);

        $nomExp  = $cfg['from_name'] ?: 'Support informatique';
        $entetes = [
            'From: =?UTF-8?B?' . base64_encode($nomExp) . '?= <' . $cfg['from'] . '>',
            'Reply-To: <' . ($cfg['reply_to'] ?: $cfg['from']) . '>',
            'To: <' . $destinataire . '>',
            'Subject: =?UTF-8?B?' . base64_encode($sujet) . '?=',
            'Date: ' . date('r'),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'Auto-Submitted: auto-generated',
        ];
        // Un point seul en début de ligne doit être doublé (RFC 5321).
        $texte = preg_replace('/^\./m', '..', str_replace("\r\n", "\n", $corps));
        $texte = str_replace("\n", "\r\n", (string) $texte);
        fwrite($sock, implode("\r\n", $entetes) . "\r\n\r\n" . $texte . "\r\n.\r\n");
        $trace .= "> (corps du message)\n";
        $r = smtp_lire($sock, $trace);
        if (substr($r, 0, 3) !== '250') {
            throw new RuntimeException('Message refusé : ' . trim($r));
        }

        smtp_cmd($sock, 'QUIT', ['221'], $trace);
        fclose($sock);
        return ['ok' => true, 'error' => '', 'trace' => $trace];

    } catch (Throwable $e) {
        if (is_resource($sock)) {
            @fclose($sock);
        }
        return ['ok' => false, 'error' => $e->getMessage(), 'trace' => $trace];
    }
}

/* ================== Authentification annuaire (LDAP/AD) =================== */
/**
 * D8 Support — authentification par annuaire (Active Directory / LDAP)
 *
 * Objectif : ne plus recréer à la main des comptes qui existent déjà dans
 * l'annuaire. Chacun se connecte avec son identifiant et son mot de passe
 * Windows habituels ; le compte de l'outil est créé tout seul à la première
 * connexion, avec le nom et le site lus dans l'annuaire. Un compte désactivé
 * dans l'annuaire perd immédiatement l'accès, sans intervention.
 *
 * Le compte administrateur créé à l'installation reste local : si l'annuaire
 * tombe, l'outil reste administrable.
 */

/** L'authentification annuaire est-elle utilisable sur ce serveur ? */
function annuaire_disponible(): bool
{
    return function_exists('ldap_connect');
}

function annuaire_config(): array
{
    return [
        'actif'   => setting_get('ldap_enabled') === '1',
        'hote'    => setting_get('ldap_host'),
        'port'    => (int) setting_get('ldap_port', '389'),
        'chiffre' => setting_get('ldap_secure', 'none'),   // none | starttls | ssl
        'modele'  => setting_get('ldap_bind_format'),      // ex. %s@d8-infra.local
        'base'    => setting_get('ldap_base_dn'),          // ex. DC=D8-INFRA,DC=LOCAL
        'attr'    => setting_get('ldap_login_attr', 'userPrincipalName'),
        'creer'   => setting_get('ldap_autocreate', '1') === '1',
    ];
}

/**
 * Ouvre une connexion à l'annuaire. Renvoie la ressource ou null.
 */
function annuaire_connexion(array $cfg, string &$trace)
{
    $schema = $cfg['chiffre'] === 'ssl' ? 'ldaps://' : 'ldap://';
    $uri    = $schema . $cfg['hote'] . ':' . ($cfg['port'] ?: ($cfg['chiffre'] === 'ssl' ? 636 : 389));
    $trace .= "Connexion à $uri\n";

    $lien = @ldap_connect($uri);
    if (!$lien) {
        $trace .= "Échec : adresse de l'annuaire invalide.\n";
        return null;
    }
    @ldap_set_option($lien, LDAP_OPT_PROTOCOL_VERSION, 3);
    @ldap_set_option($lien, LDAP_OPT_REFERRALS, 0);          // indispensable avec Active Directory
    @ldap_set_option($lien, LDAP_OPT_NETWORK_TIMEOUT, 6);

    if ($cfg['chiffre'] === 'starttls') {
        if (!@ldap_start_tls($lien)) {
            $trace .= "Échec de STARTTLS : " . ldap_error($lien) . "\n";
            return null;
        }
        $trace .= "TLS activé\n";
    }
    return $lien;
}

/**
 * Vérifie un identifiant et un mot de passe auprès de l'annuaire.
 * Renvoie ['ok' => bool, 'nom' => string, 'site' => string, 'email' => string,
 *          'telephone' => string, 'error' => string, 'trace' => string].
 */
function annuaire_verifier(string $identifiant, string $motdepasse, array $sitesConnus = []): array
{
    $trace = '';
    $vide  = ['ok' => false, 'nom' => '', 'site' => '', 'email' => '',
              'telephone' => '', 'error' => '', 'trace' => ''];

    if (!annuaire_disponible()) {
        return array_merge($vide, ['error' => "L'extension LDAP de PHP n'est pas activée sur ce serveur.",
                                   'trace' => "extension ldap absente\n"]);
    }
    $cfg = annuaire_config();
    if ($cfg['hote'] === '' || $cfg['modele'] === '') {
        return array_merge($vide, ['error' => "Annuaire non configuré (serveur ou format d'identifiant manquant).",
                                   'trace' => "configuration incomplète\n"]);
    }
    // Un mot de passe vide fait réussir un bind anonyme sur beaucoup
    // d'annuaires : ce serait accepter n'importe qui.
    if ($motdepasse === '' || $identifiant === '') {
        return array_merge($vide, ['error' => 'Identifiant ou mot de passe vide.', 'trace' => "refus immédiat\n"]);
    }
    // Un identifiant contenant des caractères de contrôle pourrait fausser
    // le DN construit à partir du modèle.
    if (preg_match('/[\x00-\x1F\\\\]/', $identifiant)) {
        return array_merge($vide, ['error' => 'Identifiant invalide.', 'trace' => "caractères interdits\n"]);
    }

    $lien = annuaire_connexion($cfg, $trace);
    if (!$lien) {
        return array_merge($vide, ['error' => "Annuaire injoignable.", 'trace' => $trace]);
    }

    // On essaie le modèle configuré ; si la personne a tapé son adresse
    // complète au lieu de son identifiant court, on retente tel quel :
    // Active Directory accepte les deux formes.
    $tentatives = [str_replace('%s', $identifiant, $cfg['modele'])];
    if (strpos($identifiant, '@') !== false && !in_array($identifiant, $tentatives, true)) {
        $tentatives[] = $identifiant;
    }
    $lie = false;
    foreach ($tentatives as $dn) {
        $trace .= "Vérification de l'identité : " . preg_replace('/^[^,@]+/', '(identifiant)', $dn) . "\n";
        if (@ldap_bind($lien, $dn, $motdepasse)) {
            $lie = true;
            break;
        }
        $trace .= "Refus de l'annuaire : " . ldap_error($lien) . "\n";
    }
    if (!$lie) {
        // ldap_connect n'ouvre rien : la panne se manifeste seulement ici.
        // Sans distinguer les deux cas, un port erroné était annoncé comme
        // un mauvais mot de passe et la recherche partait dans le décor.
        $numero = @ldap_errno($lien);
        $texte  = @ldap_err2str($numero);
        @ldap_unbind($lien);
        if ($numero === 49) {   // LDAP_INVALID_CREDENTIALS
            return array_merge($vide, ['error' => 'Identifiant ou mot de passe refusé par l\'annuaire.',
                                       'trace' => $trace]);
        }
        $trace .= "Code annuaire : $numero ($texte)\n";
        return array_merge($vide, [
            'error' => 'Annuaire injoignable ou mal configuré (' . $texte . '). Vérifiez le serveur, '
                     . 'le port et le format d\'identifiant.',
            'trace' => $trace,
        ]);
    }
    $trace .= "Identité confirmée par l'annuaire\n";

    $resultat = array_merge($vide, ['ok' => true, 'trace' => $trace]);

    // On lit ensuite la fiche de la personne pour reprendre son nom, son
    // adresse et son site : c'est ce qui évite toute ressaisie.
    if ($cfg['base'] !== '') {
        $attr   = preg_replace('/[^A-Za-z0-9-]/', '', $cfg['attr']) ?: 'userPrincipalName';
        $valeur = ldap_escape($identifiant, '', LDAP_ESCAPE_FILTER);
        $filtre = '(|(' . $attr . '=' . $valeur . ')(sAMAccountName=' . $valeur
                . ')(uid=' . $valeur . ')(mail=' . $valeur . '))';
        $rech = @ldap_search($lien, $cfg['base'], $filtre,
                             ['cn', 'displayName', 'mail', 'userPrincipalName', 'telephoneNumber',
                              'mobile', 'department', 'physicalDeliveryOfficeName'], 0, 1, 8);
        if ($rech) {
            $entrees = @ldap_get_entries($lien, $rech);
            if (!empty($entrees['count'])) {
                $e = $entrees[0];
                $lire = function (string $k) use ($e): string {
                    return isset($e[$k][0]) ? trim((string) $e[$k][0]) : '';
                };
                $resultat['nom']       = $lire('displayname') ?: $lire('cn');
                $resultat['email']     = $lire('mail') ?: $lire('userprincipalname');
                $resultat['telephone'] = $lire('telephonenumber') ?: $lire('mobile');

                // Le site se déduit de l'unité d'organisation qui contient la
                // fiche : OU=Vitry, OU=Jargeau… C'est exactement la façon dont
                // les annuaires d'entreprise sont découpés.
                $dnTrouve = (string) ($e['dn'] ?? '');
                $trace .= "Fiche trouvée : " . $dnTrouve . "\n";
                foreach ($sitesConnus as $site) {
                    $court = preg_split('/[\s-]+/u', $site)[0] ?? $site;
                    if ($court !== '' && stripos($dnTrouve, 'ou=' . $court) !== false) {
                        $resultat['site'] = $site;
                        break;
                    }
                }
                if ($resultat['site'] === '') {
                    $bureau = $lire('physicaldeliveryofficename') ?: $lire('department');
                    foreach ($sitesConnus as $site) {
                        if ($bureau !== '' && stripos($site, $bureau) !== false) {
                            $resultat['site'] = $site;
                            break;
                        }
                    }
                }
            } else {
                $trace .= "Aucune fiche trouvée dans " . $cfg['base'] . " (le nom sera à compléter)\n";
            }
        } else {
            $trace .= "Recherche impossible : " . ldap_error($lien) . "\n";
        }
    }

    @ldap_unbind($lien);
    $resultat['trace'] = $trace;
    return $resultat;
}

/* ===================== Ressources CSS / JavaScript ======================= */
function app_css(): string
{
    return <<<'CSS_D8_9f3a7c21'
/* ============================================================
   D8 Support — feuille de style
   Principe : papier chaud, chrome froid, signaux saturés.
     Chrome     #16303A  bleu pétrole profond (barre latérale)
     Papier     #F1F2EE  fond clair légèrement vert-gris
     Encre      #1C2A31  texte
     Primaire   #3550C4  action principale
     Accent     #E8B04B  ambre, uniquement décoratif
     Alerte     #B42318  suppression et priorité critique
   Chaque étape du cycle de vie a sa propre teinte ; les priorités
   utilisent une autre forme (contour + point) pour ne jamais être
   confondues avec un statut. Corps 17 px, cibles 44 px, contrastes AA.
   ============================================================ */

:root {
  --chrome: #16303A;
  --chrome-2: #22414C;
  --fond: #F1F2EE;
  --surface: #FFFFFF;
  --surface-2: #F7F8F5;
  --encre: #1C2A31;
  --muted: #626C74;
  --ligne: #E1E5E1;
  --primaire: #3550C4;
  --primaire-fonce: #2A41A6;
  --accent: #E8B04B;
  --danger: #B42318;
  --danger-fonce: #94190F;

  --r-carte: 8px;
  --r-champ: 6px;
  /* Les cartes ne flottent plus : un simple filet suffit à les séparer du
     papier. L'ombre est réservée à ce qui est réellement au-dessus du
     contenu (fenêtres, messages éphémères). Sans cela, tout l'écran a le
     même relief et plus rien ne ressort. */
  --ombre: none;
  --ombre-flottant: 0 12px 32px rgba(28, 42, 49, .18);
  --focus: 0 0 0 3px rgba(53, 80, 196, .32);
}

* { box-sizing: border-box; }

html { font-size: 17px; }
/* Cinq crans de lecture, réglés au curseur dans le menu.
   Confort réel sur un écran d'entrepôt ou après 45 ans. */
html.txt-0 { font-size: 15px; }
html.txt-1 { font-size: 17px; }
html.txt-2 { font-size: 19px; }
html.txt-3 { font-size: 21px; }
html.txt-4 { font-size: 23px; }

body {
  margin: 0;
  font-family: "Segoe UI", system-ui, -apple-system, Roboto, "Helvetica Neue", Arial, sans-serif;
  background: var(--fond);
  color: var(--encre);
  line-height: 1.55;
}

.hidden { display: none !important; }

h1 { font-size: 1.5rem; font-weight: 700; margin: 0; letter-spacing: -.015em; line-height: 1.25; }
h2 { font-size: 1.05rem; font-weight: 700; margin: 0 0 .7rem; letter-spacing: -.005em; }
p  { margin: .4rem 0; }
a  { color: var(--primaire); }

button { font: inherit; cursor: pointer; }
/* Supprime le délai de 300 ms et le voile gris au toucher sur mobile. */
button, .btn, .nav-link, a, summary, label.check, .prio-card {
  touch-action: manipulation;
  -webkit-tap-highlight-color: rgba(53, 80, 196, .12);
}
input, select, textarea { font: inherit; color: inherit; }

:focus-visible { outline: none; box-shadow: var(--focus); border-radius: var(--r-champ); }

/* ------------------------------------------------ boutons */

.btn {
  display: inline-flex; align-items: center; justify-content: center; gap: .5rem;
  min-height: 44px; padding: .55rem 1.1rem;
  border-radius: var(--r-champ);
  border: 1.5px solid var(--ligne);
  background: var(--surface);
  color: var(--encre);
  font-weight: 600;
  text-decoration: none;
}
.btn:hover { background: var(--surface-2); border-color: #CDD3CD; }

.btn-primary {
  background: var(--primaire); border-color: var(--primaire); color: #FFF;
}
.btn-primary:hover { background: var(--primaire-fonce); border-color: var(--primaire-fonce); }

.btn-danger { background: var(--danger); border-color: var(--danger); color: #FFF; }
.btn-danger:hover { background: var(--danger-fonce); border-color: var(--danger-fonce); }

.btn-ghost { border-color: transparent; background: transparent; }
.btn-ghost:hover { background: rgba(53, 80, 196, .09); border-color: transparent; }

.btn:disabled { opacity: .55; cursor: not-allowed; }

/* ------------------------------------------------ formulaires */

.field { margin-bottom: 1.05rem; }
.field label { display: block; font-weight: 600; margin-bottom: .35rem; }
.field .aide { font-size: .92rem; color: var(--muted); margin-top: .3rem; }

.input, select.input, textarea.input {
  width: 100%;
  /* Sous 16 px, Safari sur iPhone agrandit la page à chaque fois qu'on
     touche un champ, et l'utilisateur doit repincer pour tout relire. */
  font-size: max(16px, 1rem);
  min-height: 46px;
  padding: .6rem .75rem;
  border: 1.5px solid var(--ligne);
  border-radius: var(--r-champ);
  background: var(--surface);
}
textarea.input { min-height: 130px; resize: vertical; }
.input:focus { border-color: var(--primaire); box-shadow: var(--focus); outline: none; }

select.input {
  appearance: none;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath d='M6 9l6 6 6-6' fill='none' stroke='%23626C74' stroke-width='2.2' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: right .7rem center;
  background-size: 1.1rem;
  padding-right: 2.4rem;
}

.check { display: flex; align-items: center; gap: .55rem; font-weight: 600; }
.check input { width: 1.25rem; height: 1.25rem; accent-color: var(--primaire); }

/* Sélecteur de priorité en cartes cliquables */
.prio-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: .7rem; }
.prio-card {
  display: block; cursor: pointer;
  border: 1.5px solid var(--ligne); border-radius: var(--r-champ);
  background: var(--surface); padding: .7rem .8rem;
}
.prio-card input { position: absolute; opacity: 0; }
.prio-card .prio-nom { font-weight: 700; }
.prio-card .prio-desc { font-size: .92rem; color: var(--muted); }
/* La carte choisie est marquée par le script (classe .choisie) : le sélecteur
   :has() manque encore à certains navigateurs d'entreprise. */
.prio-card.choisie { border-color: var(--primaire); background: #EDF1FD; box-shadow: inset 0 0 0 1px var(--primaire); }
.prio-card:focus-within { box-shadow: var(--focus); }

/* ------------------------------------------------ pastilles statut / priorité */

.chip {
  display: inline-block;
  padding: .22rem .7rem;
  border-radius: 999px;
  font-size: .93rem;
  font-weight: 600;
  border: 1px solid transparent;
  white-space: nowrap;
}

/* Statuts : pilules pleines, une teinte par étape du parcours. */
.st-nouveau    { background: #E4EAFD; color: #2A3FA0; border-color: #C6D2F8; }
.st-en_cours   { background: #DCF0F3; color: #12626E; border-color: #B4DFE6; }
.st-en_attente { background: #F0E9FA; color: #583293; border-color: #DACAF4; }
.st-resolu     { background: #DFF2E7; color: #13654A; border-color: #BBE3CD; }
.st-ferme      { background: #E9ECF0; color: #4F5966; border-color: #D4D9E1; }

/* Priorités : contour et point, jamais la même forme qu'un statut.
   Basse et Normale restent volontairement discrètes : si tout est
   coloré, plus rien ne ressort. Seules les exceptions se voient. */
.pr-basse, .pr-normale, .pr-haute, .pr-critique {
  display: inline-flex; align-items: center; gap: .42rem;
  background: var(--surface);
}
.pr-basse::before, .pr-normale::before,
.pr-haute::before, .pr-critique::before {
  content: ""; flex: 0 0 auto;
  width: .5rem; height: .5rem; border-radius: 50%;
  background: currentColor;
}
.pr-basse    { color: #6B7482; border-color: #D8DDE3; }
.pr-normale  { color: #3F4A57; border-color: #C9D0D8; }
.pr-haute    { color: #96490A; border-color: #F0CB9C; background: #FDF4E7; }
.pr-critique { color: #FFFFFF; border-color: #B42318; background: #B42318; font-weight: 700; }

.role-tag { font-size: .85rem; color: var(--muted); }

/* ------------------------------------------------ écran de connexion */

.auth {
  min-height: 100vh;
  min-height: 100dvh;   /* la barre d'adresse mobile fait varier 100vh */
  display: flex; align-items: center; justify-content: center;
  padding: 1.5rem;
  /* Fond uni : les dégradés de couleur relèvent de la page vitrine, pas
     d'un outil que l'on ouvre quinze fois par jour. */
  background: var(--fond);
}
.auth-card {
  width: 100%; max-width: 440px;
  background: var(--surface);
  border: 1px solid var(--ligne);
  border-radius: 16px;
  box-shadow: 0 20px 48px rgba(28, 42, 49, .10);
  padding: 2.1rem 1.9rem;
}
.auth-brand { display: flex; align-items: center; gap: .8rem; margin-bottom: 1.4rem; }

.auth-brand .brand-name { font-size: 1.3rem; font-weight: 700; line-height: 1.2; }
.auth-brand .brand-sub { color: var(--muted); font-size: .95rem; }
.auth-card .btn { width: 100%; margin-top: .4rem; }
.auth-note { margin-top: 1rem; font-size: .93rem; color: var(--muted); }

/* ------------------------------------------------ structure de l'application */

.app { display: flex; min-height: 100vh; }

.sidebar {
  width: 248px; flex: 0 0 248px;
  background: var(--chrome);
  color: #E9EEF0;
  display: flex; flex-direction: column;
  padding: 1.1rem .85rem;
  position: sticky; top: 0;
  height: 100vh;
  height: 100dvh;
  /* Le menu est plus haut que l'écran sur un portable : sans défilement
     propre, les dernières entrées et tout le pied débordaient hors de la
     fenêtre et devenaient impossibles à atteindre. */
  overflow: hidden;
}
.brand { flex: 0 0 auto; }
.brand {
  display: flex; align-items: center; gap: .65rem;
  padding: .5rem .45rem; margin-bottom: .5rem;
  border-radius: 9px; text-decoration: none; color: inherit;
}
.brand:hover { background: var(--chrome-2); }
.topbar-title {
  display: flex; align-items: center; min-width: 0;
  min-height: 44px;
}
/* Sur mobile : le nom de l'outil (la barre latérale est masquée).
   Sur grand écran : le fil d'Ariane, puisque le nom figure déjà à gauche. */
.tt-app {
  color: inherit; text-decoration: none; font-weight: 700; font-size: 1.05rem;
  display: none; padding: 0 .2rem;
}
.tt-page {
  display: flex; align-items: center; gap: .45rem; min-width: 0;
  font-size: .98rem; color: #A9C0C8; white-space: nowrap; overflow: hidden;
}
.tt-page a { color: #CFDDE2; text-decoration: none; border-radius: 5px; padding: .15rem .25rem; }
.tt-page a:hover { color: #FFF; background: var(--chrome-2); }
.tt-page [aria-current="page"] {
  color: #FFF; font-weight: 700; overflow: hidden; text-overflow: ellipsis;
}
.fa-accueil { display: inline-flex; align-items: center; }
.fa-accueil .ico, .fa-accueil .ico svg { width: 18px; height: 18px; }
.fa-sep { color: #5F7D88; }

/* Lien d'évitement : invisible jusqu'à la première tabulation. */
.skip {
  position: absolute; left: -9999px; top: 0; z-index: 100;
  background: var(--primaire); color: #FFF;
  padding: .7rem 1.1rem; border-radius: 0 0 8px 0; font-weight: 600;
}
.skip:focus { left: 0; }
.brand-mark {
  width: 38px; height: 38px; flex: 0 0 auto;
  display: inline-flex; align-items: center; justify-content: center;
  border-radius: 7px;
  background: #E9EEF0; color: var(--chrome);
  font-weight: 800; font-size: .95rem; letter-spacing: .02em;
  font-variant-numeric: tabular-nums;
}
.brand-mark-grand {
  width: 48px; height: 48px; border-radius: 9px; font-size: 1.15rem;
  background: var(--chrome); color: #E9EEF0;
}
.brand-name { font-weight: 700; font-size: 1.12rem; line-height: 1.25; }
.brand-sub { font-size: .85rem; color: #8FA6AF; }

.nav {
  display: flex; flex-direction: column; gap: .25rem; margin-top: .3rem;
  flex: 1 1 auto; min-height: 0;
  overflow-y: auto; overscroll-behavior: contain;
  padding-right: .2rem;
}
/* Ascenseur discret, lisible sur le fond sombre. */
.nav::-webkit-scrollbar { width: 8px; }
.nav::-webkit-scrollbar-thumb { background: #3E5E6B; border-radius: 4px; }
.nav::-webkit-scrollbar-thumb:hover { background: #4E7583; }
.nav::-webkit-scrollbar-track { background: transparent; }
.nav { scrollbar-width: thin; scrollbar-color: #3E5E6B transparent; }
.nav-link {
  display: flex; align-items: center; gap: .7rem;
  width: 100%; text-align: left;
  padding: .68rem .8rem; min-height: 46px;
  border: 0; border-radius: 9px;
  background: transparent; color: #E9EEF0;
  font-weight: 600; font-size: 1rem;
  text-decoration: none;
}
.nav-link:hover { background: var(--chrome-2); }
.nav-link.active { background: var(--chrome-2); box-shadow: inset 3px 0 0 #7FA8B5; }
.nav-link .ico { display: inline-flex; width: 22px; height: 22px; flex: 0 0 auto; }
.nav-link .ico svg { width: 22px; height: 22px; }
.nav-sep { height: 1px; background: rgba(233, 238, 240, .18); margin: .6rem .4rem; }

/* Le pied de la barre latérale a été supprimé : le bloc utilisateur est
   passé en haut à droite, ce qui rend toute la hauteur à la navigation. */


.main-col { flex: 1; min-width: 0; display: flex; flex-direction: column; }

.topbar {
  display: flex;
  align-items: center; gap: .8rem;
  background: var(--chrome); color: #E9EEF0;
  padding: .55rem .9rem;
  position: sticky; top: 0; z-index: 30;
}
.btn-menu { display: none; background: transparent; border: 0; color: inherit; padding: .35rem; border-radius: 8px; }


/* Toute la largeur disponible : plafonnée et non centrée, la page restait collée
   à gauche sur les grands écrans. */
.main { padding: 1.6rem 1.8rem 3rem; width: 100%; }

.scrim {
  position: fixed; inset: 0; background: rgba(15, 34, 42, .50); z-index: 40;
}

/* ------------------------------------------------ en-têtes de page */

.page-head {
  display: flex; align-items: center; justify-content: space-between;
  gap: 1rem; flex-wrap: wrap; margin-bottom: 1.2rem;
}
.page-head .sous-titre { color: var(--muted); margin-top: .15rem; }
.page-actions { display: flex; gap: .6rem; flex-wrap: wrap; }

/* ------------------------------------------------ cartes & stats */

.card {
  background: var(--surface);
  border: 1px solid var(--ligne);
  border-radius: var(--r-carte);
  padding: 1.25rem 1.4rem;
  margin-bottom: 1.1rem;
}
/* Titres de section : petits, en capitales espacées. Ils annoncent sans
   concurrencer le contenu, qui est ce qu'on vient lire. */
.card > h2 {
  font-size: .82rem;
  letter-spacing: .09em;
  text-transform: uppercase;
  color: var(--muted);
  font-weight: 700;
  margin-bottom: .9rem;
}

.stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: .9rem; margin-bottom: 1.2rem; }
.stat {
  background: var(--surface);
  border: 1px solid var(--ligne);
  border-radius: var(--r-carte);
  padding: .85rem 1rem;
  text-align: left;
}
button.stat:hover { border-color: var(--primaire); }
.stat-num { font-size: 2rem; font-weight: 700; line-height: 1.1; font-variant-numeric: tabular-nums; }
.stat-lbl { color: var(--muted); font-weight: 600; font-size: .93rem; margin-top: .1rem; }
/* Chaque compteur reprend la teinte du statut qu'il ouvre : la couleur
   sert de raccourci de lecture, pas de décoration. */
.stat { border-left-width: 5px; }
.stat-nouveau    { border-left-color: #3D5CD6; }
.stat-nouveau    .stat-num { color: #2A3FA0; }
.stat-en_cours   { border-left-color: #0E7C8C; }
.stat-en_cours   .stat-num { color: #12626E; }
.stat-en_attente { border-left-color: #7A4FC4; }
.stat-en_attente .stat-num { color: #583293; }
.stat-critique   { border-left-color: #B42318; }
.stat-critique   .stat-num { color: #B42318; }
.stat-nonassigne { border-left-color: #E8B04B; }
.stat-nonassigne .stat-num { color: #8A5A00; }
.stat-moi        { border-left-color: #1B8A5F; }
.stat-moi        .stat-num { color: #13654A; }
.stat.alerte     { background: #FEF3F2; }

.mini-liste { display: flex; flex-wrap: wrap; gap: .45rem .9rem; color: var(--muted); }
.mini-liste b { color: var(--encre); }

/* ------------------------------------------------ filtres */

.filtres {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
  gap: .7rem;
  background: var(--surface);
  border: 1px solid var(--ligne);
  border-radius: var(--r-carte);
  padding: .9rem 1rem;
  margin-bottom: 1rem;
}
.filtres .field { margin: 0; }
.filtres label { font-size: .9rem; color: var(--muted); }
.filtres .input, .filtres select.input { min-height: 44px; }

/* ------------------------------------------------ tableau des tickets */

.tbl-wrap { background: var(--surface); border: 1px solid var(--ligne); border-radius: var(--r-carte); overflow: hidden; }
table.tbl { width: 100%; border-collapse: collapse; }
.tbl th {
  text-align: left; font-size: .92rem; color: var(--muted); font-weight: 600;
  padding: .75rem .9rem; border-bottom: 1px solid var(--ligne);
  background: var(--surface-2);
  position: sticky; top: 0;
}
.tbl td { padding: .8rem .9rem; border-bottom: 1px solid var(--ligne); vertical-align: middle; }
.tbl tbody tr:last-child td { border-bottom: 0; }
.tbl tbody tr { cursor: pointer; }
.tbl tbody tr:hover { background: #F4F7FA; }
.tbl tbody tr:focus-visible { outline: none; box-shadow: inset 0 0 0 3px rgba(53, 80, 196, .38); }
.t-ref {
  font-weight: 700; white-space: nowrap;
  font-variant-numeric: tabular-nums;
  letter-spacing: .01em;
  color: var(--muted);
}
.tbl tbody tr:hover .t-ref { color: var(--encre); }
.t-titre { font-weight: 600; overflow-wrap: anywhere; }
.t-sub { display: block; font-size: .88rem; color: var(--muted); font-weight: 400; }
.t-date { white-space: nowrap; color: var(--muted); font-size: .95rem; }

.pagination { display: flex; align-items: center; justify-content: space-between; gap: 1rem; margin-top: .9rem; color: var(--muted); flex-wrap: wrap; }
.pagination .pages { display: flex; gap: .5rem; }

.vide {
  text-align: center; color: var(--muted);
  padding: 2.6rem 1rem;
}
.vide .btn { margin-top: .9rem; }

.chargement {
  display: flex; align-items: center; gap: .7rem;
  color: var(--muted); padding: 2.2rem 0;
}
.roue {
  width: 20px; height: 20px; flex: 0 0 auto;
  border: 2.5px solid var(--ligne); border-top-color: var(--primaire);
  border-radius: 50%;
  animation: tourner .8s linear infinite;
}
@keyframes tourner { to { transform: rotate(360deg); } }

/* ------------------------------------------------ fiche ticket */

.ticket-entete { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
.ticket-entete .chips { display: flex; gap: .5rem; flex-wrap: wrap; margin-top: .5rem; }
.ticket-ref {
  color: var(--muted); font-weight: 700;
  font-variant-numeric: tabular-nums; letter-spacing: .06em;
  font-size: .9rem; text-transform: uppercase;
}

.meta-grille {
  display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
  gap: .8rem 1.4rem; margin-top: 1rem;
}
.meta-item .meta-lbl { font-size: .9rem; color: var(--muted); }
.meta-item .meta-val { font-weight: 600; }

.description-bloc { white-space: pre-wrap; overflow-wrap: anywhere; }

/* fil de discussion — conteneur et corps des messages (la présentation des
   messages eux-mêmes est définie plus bas, section « fil de discussion ») */
.fil { display: flex; flex-direction: column; gap: .9rem; }
.msg-corps { white-space: pre-wrap; overflow-wrap: anywhere; }
.msg-systeme .quoi { white-space: pre-line; }

.pj-liste { display: flex; flex-direction: column; gap: .4rem; margin-top: .5rem; }
.pj {
  display: inline-flex; align-items: center; gap: .5rem;
  color: var(--primaire); font-weight: 600; text-decoration: none;
  min-height: 40px; overflow-wrap: anywhere;
}
.pj:hover { text-decoration: underline; }
.pj .pj-taille { color: var(--muted); font-weight: 400; font-size: .9rem; }

.fichiers-choisis { font-size: .93rem; color: var(--muted); margin-top: .35rem; }

/* ------------------------------------------------ utilisateurs */

.u-inactif { opacity: .55; }

/* ------------------------------------------------ modales */

.modal-fond {
  position: fixed; inset: 0; z-index: 60;
  background: rgba(15, 34, 42, .55);
  display: flex; align-items: center; justify-content: center;
  padding: 1rem;
}
.modal {
  background: var(--surface);
  border-radius: 14px;
  border: 1px solid var(--ligne);
  box-shadow: 0 18px 50px rgba(28, 42, 49, .30);
  width: 100%; max-width: 520px;
  max-height: 92vh; overflow: auto;
  padding: 1.4rem 1.5rem;
}
.modal h2 { margin-bottom: 1rem; }
.modal-actions { display: flex; justify-content: flex-end; gap: .6rem; margin-top: 1.2rem; flex-wrap: wrap; }

/* ------------------------------------------------ toasts */

#toast-root {
  position: fixed; bottom: 1.2rem; left: 50%; transform: translateX(-50%);
  display: flex; flex-direction: column; gap: .5rem; z-index: 80;
  width: min(480px, calc(100vw - 2rem));
}
.toast {
  background: var(--chrome); color: #EDF1F2;
  border-radius: 10px; padding: .8rem 1.1rem;
  box-shadow: var(--ombre-flottant);
  font-weight: 600; text-align: center;
}
.toast.erreur { background: var(--danger); color: #FFF; }

/* ------------------------------------------------ responsive */

@media (max-width: 920px) {
  .btn-menu { display: block; }
  .tt-app { display: inline-flex; align-items: center; min-height: 44px; }
  .tt-page { display: none; }
  .sidebar {
    position: fixed; z-index: 50; left: 0; top: 0; bottom: 0;
    height: 100dvh;
    transform: translateX(-100%);
    transition: transform .18s ease;
  }
  .sidebar.ouvert { transform: translateX(0); }
  .main { padding: 1.1rem 1rem 2.4rem; }

  /* tableau -> liste empilée */
  .tbl thead { display: none; }
  .tbl, .tbl tbody, .tbl tr, .tbl td { display: block; width: 100%; }
  .tbl tr { border-bottom: 6px solid var(--fond); padding: .35rem 0; }
  .tbl td { border: 0; padding: .3rem .95rem; }
  .tbl td[data-l]::before {
    content: attr(data-l) " : ";
    color: var(--muted); font-size: .9rem;
  }
  .tbl td.sans-label::before { content: none; }
}

@media (prefers-reduced-motion: reduce) {
  * { transition: none !important; animation: none !important; }
}

@media print {
  .sidebar, .topbar, .page-actions, .filtres, .btn { display: none !important; }
  .main { padding: 0; max-width: none; }
  body { background: #FFF; }
  .card, .tbl-wrap { box-shadow: none; }
}


/* ------------------------------------------------ ajouts : nouveautés, tri, stats */

/* compteur de nouveautés dans le menu */
.nav-link { position: relative; }
.nav-lbl { flex: 1; }
.badge {
  background: var(--primaire); color: #FFF;
  font-weight: 700; font-size: .82rem;
  border-radius: 999px; padding: .05rem .5rem;
  min-width: 1.5rem; text-align: center;
}

/* lignes comportant du nouveau, et tickets qui dorment */
.tbl tbody tr.non-lu .t-titre,
.tbl tbody tr.non-lu .t-ref { font-weight: 800; }
.tbl tbody tr.non-lu td:first-child { box-shadow: inset 4px 0 0 var(--primaire); }
.tbl tbody tr.dormant td:first-child { box-shadow: inset 4px 0 0 #D97706; }
.tbl tbody tr.non-lu.dormant td:first-child {
  box-shadow: inset 4px 0 0 var(--primaire), inset 8px 0 0 #D97706;
}
.t-alerte { display: block; color: #96490A; font-weight: 600; font-size: .9rem; }

.stat-dormant { border-left-color: #D97706; }
.stat-dormant .stat-num { color: #96490A; }

/* en-têtes de colonnes cliquables pour le tri */
.tbl th { padding: 0; }
.tbl th .tri {
  display: block; width: 100%; text-align: left;
  padding: .75rem .9rem; border: 0; background: transparent;
  font: inherit; font-size: .92rem; font-weight: 600; color: var(--muted);
}
.tbl th .tri:hover { background: #ECEFEA; color: var(--encre); }
.tbl th .tri.actif { color: var(--primaire); }

/* cases à cocher des filtres */
.filtres-cases {
  grid-column: 1 / -1;
  display: flex; flex-wrap: wrap; align-items: center; gap: .5rem 1.3rem;
  padding-top: .2rem;
}
.filtres-cases .btn { margin-left: auto; }

/* mise en page à deux colonnes dans les réglages */
.deux-col { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 0 1.4rem; }
.mdp-ligne { display: flex; gap: .6rem; }
.mdp-ligne .input { flex: 1; }

/* barres des statistiques */
.barres { display: flex; flex-direction: column; gap: .5rem; }
.barre-ligne { display: grid; grid-template-columns: minmax(120px, 34%) 1fr auto; gap: .8rem; align-items: center; }
.barre-lbl { font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.barre-piste { background: #E8EBE6; border-radius: 999px; height: 1.1rem; overflow: hidden; }
.barre-jauge { background: var(--primaire); height: 100%; border-radius: 999px; min-width: 3px; }
.barre-val { font-weight: 700; min-width: 2.2rem; text-align: right; }

@media (max-width: 620px) {
  .barre-ligne { grid-template-columns: 1fr auto; }
  .barre-piste { grid-column: 1 / -1; }
}

@media print {
  .no-print, .badge { display: none !important; }
}

/* ------------------------------------------------ ajouts : brouillons, vignettes, guide */

/* champ dont le contenu vient d'être restauré : signalé brièvement */
.brouillon-restaure {
  border-color: var(--accent) !important;
  background: #FFFBF2;
}

/* vignettes des captures d'écran jointes */
.pj-vignettes {
  display: flex; flex-wrap: wrap; gap: .6rem;
  margin-top: .8rem;
}
.pj-vignette {
  display: block; width: 148px; height: 108px;
  border: 1px solid var(--ligne); border-radius: 10px;
  overflow: hidden; background: var(--surface-2);
}
.pj-vignette img { width: 100%; height: 100%; object-fit: cover; display: block; }
.pj-vignette:hover { border-color: var(--primaire); }

/* bloc « premiers pas » du tableau de bord vide */
.guide { border-left: 5px solid var(--accent); }
.guide-liste { margin: 0; padding-left: 1.3rem; }
.guide-liste li { margin-bottom: .55rem; }

@media print {
  .pj-vignettes { gap: .4rem; }
  .pj-vignette { width: 120px; height: 88px; }
}


/* ------------------------------------------------ curseur de taille du texte */

.reglage-texte {
  display: flex; align-items: center; gap: .6rem;
  padding: .5rem .8rem .7rem;
  color: #8FA6AF;
}
.reglage-texte .txt-petit { font-size: .8rem; font-weight: 700; }
.reglage-texte .txt-gros  { font-size: 1.25rem; font-weight: 700; }
.reglage-texte input[type=range] {
  flex: 1; min-width: 0; height: 26px;
  accent-color: var(--accent);
  background: transparent; cursor: pointer;
}
.reglage-texte input[type=range]:focus-visible {
  outline: none; box-shadow: 0 0 0 3px rgba(232, 176, 75, .45); border-radius: 6px;
}

/* encart du code d'installation, sur l'écran de bienvenue */
.encart-code {
  background: #FDF6E7; border: 1px solid #EBD9AD;
  border-radius: var(--r-champ); padding: .8rem .9rem;
  margin-bottom: 1.2rem; font-size: .95rem; line-height: 1.5;
}
.encart-code code {
  background: #FFF; border: 1px solid var(--ligne); border-radius: 5px;
  padding: .05rem .35rem; font-size: .93rem;
}


/* ------------------------------------------------ fil de discussion */

/* Traitement documentaire : un filet de couleur et une étiquette d'origine
   suffisent à distinguer qui parle. Les bulles et avatars ronds relèvent
   d'une messagerie grand public, pas d'un dossier de suivi. */
.msg {
  border: 1px solid var(--ligne);
  border-left: 3px solid #C6CEC6;
  border-radius: var(--r-champ);
  background: var(--surface);
  padding: .75rem .95rem;
}
.msg-origine {
  font-size: .74rem; font-weight: 700;
  letter-spacing: .08em; text-transform: uppercase;
  color: var(--muted);
}
.msg.du-support { border-left-color: var(--primaire); background: #F7F9FE; }
.msg.du-support .msg-origine { color: #2A3FA0; }
.msg.du-demandeur { border-left-color: #9AA6AE; }
.msg.interne { border-left-color: #D97706; background: #FEF9EF; }
.msg.interne .msg-origine { color: #8A5A00; }
.msg-tete {
  display: flex; align-items: baseline; gap: .55rem;
  flex-wrap: wrap; margin-bottom: .35rem;
}
.msg-auteur { font-weight: 600; overflow-wrap: anywhere; }
.msg-date { color: var(--muted); font-size: .88rem; margin-left: auto; }
.msg-systeme { padding: .1rem 0; text-align: center; color: var(--muted); font-size: .93rem; }

/* ------------------------------------------------ barre de traitement */

/* Les trois gestes quotidiens sur une seule ligne ; le reste se déplie. */
.barre-traitement {
  background: var(--surface);
  border: 1px solid var(--ligne);
  border-left: 4px solid var(--primaire);
  border-radius: var(--r-carte);
  padding: .9rem 1.1rem;
  margin-bottom: 1.1rem;
}
.bt-champs { display: flex; flex-wrap: wrap; gap: .7rem; align-items: end; }
.bt-champ { display: flex; flex-direction: column; gap: .2rem; flex: 1 1 170px; min-width: 0; }
.bt-champ > span {
  font-size: .8rem; font-weight: 700; letter-spacing: .05em;
  text-transform: uppercase; color: var(--muted);
}
.bt-champ .input { min-height: 44px; }
.bt-valider { flex: 0 0 auto; }
.bt-plus { margin-top: .9rem; border-top: 1px solid var(--ligne); padding-top: .7rem; }
.bt-plus summary {
  cursor: pointer; font-weight: 600; color: var(--primaire);
  list-style: none; min-height: 32px; display: flex; align-items: center;
}
.bt-plus summary::-webkit-details-marker { display: none; }
.bt-plus summary::before { content: "▸ "; margin-right: .35rem; }
.bt-plus[open] summary::before { content: "▾ "; }
.bt-plus .bt-champs { margin-top: .7rem; }

@media (max-width: 620px) {
  .bt-valider { width: 100%; }
}


/* ------------------------------------------------ hiérarchie du tableau de bord */

/* Trois chiffres portent la décision du matin ; les autres informent. */
.stats-cles { grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); margin-bottom: .7rem; }
.stats-suite { grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); margin-bottom: 1.3rem; }
.stat-petit { padding: .6rem .8rem; }
.stat-petit .stat-num { font-size: 1.35rem; }
.stat-petit .stat-lbl { font-size: .87rem; }
.stats-cles .stat { border-left-width: 6px; }
.stats-cles .stat-num { font-size: 2.3rem; }

/* ------------------------------------------------ réseau interrompu */

.bandeau-reseau {
  position: fixed; left: 0; right: 0; bottom: 0; z-index: 90;
  background: #96490A; color: #FFF;
  padding: .75rem 1rem; text-align: center;
  font-weight: 600; font-size: .95rem;
  box-shadow: 0 -6px 20px rgba(28, 42, 49, .18);
}

/* ------------------------------------------------ confort tactile */

@media (pointer: coarse) {
  /* Doigt ganté sur un PDA d'entrepôt : on élargit ce qui se touche. */
  .btn, .input, select.input { min-height: 48px; }
  .tbl tbody tr { min-height: 56px; }
  .nav-link { min-height: 50px; }
  .nav-sous-lien { min-height: 44px; padding-top: .6rem; padding-bottom: .6rem; }
  .reglage-texte input[type=range] { height: 44px; }
  .pj { min-height: 44px; }
}

@media (max-width: 480px) {
  .page-actions { width: 100%; }
  .page-actions .btn, .page-actions a.btn { flex: 1 1 auto; justify-content: center; }
  .modal { padding: 1.1rem 1rem; border-radius: 12px; }
  .modal-actions .btn { flex: 1 1 auto; justify-content: center; }
  .stats-cles .stat-num { font-size: 2rem; }
}

/* trace technique du test d'annuaire */
.trace-ldap {
  margin-top: .9rem; padding: .8rem 1rem;
  background: var(--chrome); color: #D6E2E6;
  border-radius: var(--r-champ);
  font-size: .86rem; line-height: 1.5;
  white-space: pre-wrap; overflow-wrap: anywhere;
  max-height: 260px; overflow: auto;
}


/* Le monogramme du menu reste lisible sur le chrome sombre. */
.brand:hover .brand-mark { background: #FFF; }

/* ------------------------------------------------ menu en sections */

.sr-only {
  position: absolute; width: 1px; height: 1px; overflow: hidden;
  clip: rect(0 0 0 0); white-space: nowrap;
}

.nav-recherche {
  display: flex; align-items: center; gap: .5rem;
  background: var(--chrome-2); border-radius: 8px;
  padding: 0 .7rem; margin-bottom: .7rem;
  color: #8FA6AF;
}
.nav-recherche .ico { width: 18px; height: 18px; flex: 0 0 auto; }
.nav-recherche .ico svg { width: 18px; height: 18px; }
.nav-recherche input {
  flex: 1; min-width: 0; border: 0; background: transparent;
  color: #E9EEF0; padding: .6rem 0; min-height: 42px;
  font-size: max(16px, .96rem);
}
.nav-recherche input::placeholder { color: #7D949D; }
.nav-recherche input:focus { outline: none; }
.nav-recherche:focus-within { box-shadow: 0 0 0 2px #7FA8B5; }

.nav-groupe .nav-tete { justify-content: flex-start; }
.nav-groupe .nav-tete .ico:last-child {
  margin-left: auto; width: 18px; height: 18px;
  transition: transform .15s ease;
}
.nav-groupe .nav-tete .ico:last-child svg { width: 18px; height: 18px; }
.nav-groupe.ouvert .nav-tete .ico:last-child { transform: rotate(90deg); }
.nav-sous { display: none; padding: .15rem 0 .35rem; }
.nav-groupe.ouvert .nav-sous { display: block; }
.nav-sous-lien {
  display: block; width: 100%; text-align: left;
  background: transparent; border: 0; color: #BFD0D6;
  padding: .42rem .8rem .42rem 2.5rem; min-height: 36px;
  font: inherit; font-size: .95rem; text-decoration: none;
  border-radius: 7px;
}
.nav-sous-lien:hover { background: var(--chrome-2); color: #E9EEF0; }
.nav-sous-lien.actif { color: #FFF; font-weight: 700; box-shadow: inset 3px 0 0 #7FA8B5; }

/* ------------------------------------------------ compteurs du haut */

.topbar-compteurs { display: flex; gap: .5rem; margin-left: auto; }
.tb-compteur {
  display: flex; align-items: baseline; gap: .4rem;
  background: var(--chrome-2); border: 1px solid rgba(233,238,240,.14);
  color: #E9EEF0; border-radius: 7px; padding: .3rem .7rem; min-height: 40px;
}
.tb-compteur:hover { background: #2B4E5B; }
.tb-num { font-weight: 800; font-size: 1.05rem; font-variant-numeric: tabular-nums; }
.tb-lbl { font-size: .85rem; color: #A9C0C8; }
.tb-critique .tb-num { color: #FF9C8F; }
.tb-nonassigne .tb-num { color: #F2C879; }

/* ------------------------------------------------ temps passé */

.bt-temps { flex: 0 0 130px; }
.bt-temps-ligne { display: flex; align-items: center; gap: .4rem; }
.bt-temps-ligne .input { min-width: 0; }
.bt-unite { color: var(--muted); font-weight: 600; font-size: .9rem; }

/* ------------------------------------------------ fiches de procédure */

.fiche { border-top: 1px solid var(--ligne); padding: 1rem 0; }
.fiche:first-of-type { border-top: 0; padding-top: .2rem; }
.fiche-tete { display: flex; align-items: baseline; gap: .8rem; flex-wrap: wrap; }
.fiche-tete h3 { margin: 0; font-size: 1.02rem; font-weight: 700; }
.fiche-tags { display: flex; gap: .4rem; flex-wrap: wrap; margin-left: auto; }
.fiche-corps { white-space: pre-wrap; overflow-wrap: anywhere; margin-top: .5rem; }
.fiche-actions { display: flex; gap: .5rem; margin-top: .7rem; }
.fiche-interne { background: var(--surface-2); border-radius: var(--r-champ); padding: 1rem; }

/* ------------------------------------------------ journaux et informations */

.log-bloc {
  background: var(--chrome); color: #D6E2E6; border-radius: var(--r-champ);
  padding: .8rem 1rem; max-height: 340px; overflow: auto;
  font-size: .86rem; line-height: 1.55;
}
.log-ligne { overflow-wrap: anywhere; padding: .12rem 0; border-bottom: 1px solid rgba(233,238,240,.08); }
.log-ligne:last-child { border-bottom: 0; }

.info-ligne {
  display: grid; grid-template-columns: minmax(160px, 34%) 1fr; gap: .8rem;
  padding: .55rem 0; border-bottom: 1px solid var(--ligne);
}
.info-ligne:last-child { border-bottom: 0; }
.info-lbl { color: var(--muted); font-weight: 600; }
.info-val { overflow-wrap: anywhere; }

@media (max-width: 620px) {
  .info-ligne { grid-template-columns: 1fr; gap: .1rem; }
  .topbar-compteurs { display: none; }
}


/* ------------------------------------------------ bloc utilisateur (barre du haut) */

.topbar-user { position: relative; flex: 0 0 auto; margin-left: .4rem; }
.tu-bouton {
  display: flex; align-items: center; gap: .55rem;
  background: transparent; border: 1px solid transparent;
  color: #E9EEF0; border-radius: 8px;
  padding: .25rem .5rem; min-height: 44px;
}
.tu-bouton:hover, .topbar-user.ouvert .tu-bouton { background: var(--chrome-2); border-color: rgba(233,238,240,.16); }
.tu-initiales {
  width: 32px; height: 32px; border-radius: 50%;
  display: inline-flex; align-items: center; justify-content: center;
  background: #E9EEF0; color: var(--chrome);
  font-weight: 800; font-size: .8rem; flex: 0 0 auto;
}
.tu-textes { display: flex; flex-direction: column; align-items: flex-start; line-height: 1.2; }
.tu-nom { font-weight: 700; font-size: .95rem; }
.tu-role { font-size: .8rem; color: #A9C0C8; }
.tu-bouton .ico { width: 16px; height: 16px; opacity: .7; }
.tu-bouton .ico svg { width: 16px; height: 16px; }
.topbar-user.ouvert .tu-bouton .ico { transform: rotate(90deg); }

.tu-panneau {
  position: absolute; right: 0; top: calc(100% + .4rem); z-index: 60;
  width: 264px; padding: .5rem;
  background: var(--surface); color: var(--encre);
  border: 1px solid var(--ligne); border-radius: 10px;
  box-shadow: var(--ombre-flottant);
}
.tu-section { padding: .3rem .5rem .5rem; border-bottom: 1px solid var(--ligne); margin-bottom: .3rem; }
.tu-titre {
  font-size: .76rem; font-weight: 700; letter-spacing: .07em;
  text-transform: uppercase; color: var(--muted); margin-bottom: .2rem;
}
.tu-section .reglage-texte { padding: 0; color: var(--muted); }
.tu-lien {
  display: flex; align-items: center; gap: .6rem;
  width: 100%; text-align: left;
  background: transparent; border: 0; color: var(--encre);
  padding: .55rem .6rem; min-height: 44px; border-radius: 7px;
  font: inherit; font-weight: 600;
}
.tu-lien:hover { background: var(--surface-2); }
.tu-lien .ico { width: 20px; height: 20px; flex: 0 0 auto; color: var(--muted); }
.tu-lien .ico svg { width: 20px; height: 20px; }
.tu-sortie { color: var(--danger); }
.tu-sortie .ico { color: var(--danger); }

@media (max-width: 620px) {
  .tu-textes { display: none; }
  .tu-panneau { width: min(280px, calc(100vw - 1.5rem)); }
}

/* ------------------------------------------------ traitement par lot */

.col-choix { width: 42px; text-align: center; }
.col-choix input { width: 1.15rem; height: 1.15rem; accent-color: var(--primaire); cursor: pointer; }
.tbl th.col-choix { padding: .75rem .5rem; }
.tbl tbody tr.choisi { background: #EDF1FD; }
.tbl tbody tr.choisi td:first-child { box-shadow: inset 4px 0 0 var(--primaire); }

.barre-lot {
  display: flex; flex-wrap: wrap; align-items: center; gap: .6rem;
  background: var(--chrome); color: #E9EEF0;
  border-radius: var(--r-carte); padding: .7rem .9rem; margin-bottom: .8rem;
  position: sticky; top: 0; z-index: 20;
}
.lot-nb { font-weight: 700; margin-right: .3rem; }
.barre-lot .input { flex: 1 1 170px; min-width: 0; min-height: 42px; }
.barre-lot .btn-ghost { color: #BFD0D6; }
.barre-lot .btn-ghost:hover { background: var(--chrome-2); }

/* ------------------------------------------------ raccourcis clavier */

.raccourcis { display: flex; flex-direction: column; gap: .1rem; }
.rac-ligne {
  display: grid; grid-template-columns: 120px 1fr; gap: .8rem; align-items: center;
  padding: .45rem 0; border-bottom: 1px solid var(--ligne);
}
.rac-ligne:last-child { border-bottom: 0; }
kbd {
  display: inline-block; font: inherit; font-size: .86rem; font-weight: 700;
  background: var(--surface-2); border: 1px solid var(--ligne);
  border-bottom-width: 2px; border-radius: 5px; padding: .12rem .5rem;
  text-align: center;
}
.btn-mini { min-height: 32px; padding: .1rem .55rem; font-size: .9rem; margin-left: .5rem; }

/* ------------------------------------------------ affiche imprimable */

.affiche {
  background: var(--surface); border: 1px solid var(--ligne);
  border-radius: var(--r-carte); padding: 2.4rem 2.6rem;
  max-width: 780px;
}
.affiche-marque { display: flex; align-items: center; gap: 1rem; margin-bottom: 1.8rem; }
.affiche-titre { font-size: 1.8rem; font-weight: 800; letter-spacing: -.02em; }
.affiche-sous { color: var(--muted); font-size: 1.05rem; }
.affiche-adresse {
  border: 2px solid var(--primaire); border-radius: var(--r-carte);
  padding: 1rem 1.2rem; margin-bottom: 1.6rem; text-align: center;
}
.affiche-adresse span { display: block; color: var(--muted); font-size: .95rem; }
.affiche-adresse b {
  display: block; font-size: 1.5rem; color: var(--primaire);
  overflow-wrap: anywhere; margin-top: .2rem;
}
.affiche-etapes { margin: 0 0 1.5rem; padding-left: 1.4rem; font-size: 1.05rem; line-height: 1.65; }
.affiche-etapes li { margin-bottom: .7rem; }
.affiche-conseil {
  background: var(--surface-2); border-left: 4px solid var(--accent);
  border-radius: var(--r-champ); padding: .9rem 1.1rem; margin-bottom: 1.4rem;
}
.affiche-pied { color: var(--muted); font-style: italic; text-align: center; }

@media print {
  .affiche { border: 0; padding: 0; max-width: none; }
  .affiche-adresse { border-color: #000; }
}

@media (max-width: 620px) {
  .affiche { padding: 1.3rem 1.2rem; }
  .affiche-titre { font-size: 1.4rem; }
  .affiche-adresse b { font-size: 1.15rem; }
  .rac-ligne { grid-template-columns: 96px 1fr; }
}

CSS_D8_9f3a7c21;
}

function app_js(): string
{
    return <<<'JS_D8_9f3a7c21'
'use strict';
/* Nom du fichier de l'application (index.php), fourni par la page via
   <meta name="d8-app">. Tous les appels le citent explicitement. */
const D8_APP = (function () {
  const m = document.querySelector('meta[name="d8-app"]');
  const n = m ? (m.getAttribute('content') || '') : '';
  return /^[A-Za-z0-9._-]+\.php$/.test(n) ? n : 'index.php';
})();
/* ============================================================
   D8 Support — script de l'application
   Tout est en JavaScript « vanilla » : aucune dépendance,
   aucun appel vers l'extérieur (fonctionne sur un réseau local).
   ============================================================ */

/* ------------------------------------------------ état global */

const S = {
  user: null,
  csrf: '',
  appName: 'D8 Support',
  categories: [],
  sites: [],
  templates: [],
  assignables: [],
  unread: 0,
  staleDays: 3,
  vueToken: 0,     // incrémenté à chaque navigation (voir vueActuelle)
  canChangePassword: false,
  filtres: null,
};

const STATUTS = {
  nouveau:    { lbl: 'Nouveau' },
  en_cours:   { lbl: 'En cours' },
  en_attente: { lbl: 'En attente' },
  resolu:     { lbl: 'Résolu' },
  ferme:      { lbl: 'Fermé' },
};
const PRIORITES = {
  basse:    { lbl: 'Basse',    desc: 'Peut attendre quelques jours' },
  normale:  { lbl: 'Normale',  desc: 'Gêne le travail sans le bloquer' },
  haute:    { lbl: 'Haute',    desc: 'Bloque une tâche importante' },
  critique: { lbl: 'Critique', desc: 'Travail arrêté, plusieurs personnes bloquées' },
};
const ROLES = { employe: 'Employé', admin: 'Administrateur' };

/* ------------------------------------------------ petits utilitaires */

const $ = (sel, root) => (root || document).querySelector(sel);

function esc(s) {
  return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  }[c]));
}

function fmtDate(s) {
  if (!s) return '';
  const [d, t] = String(s).split(' ');
  const p = d.split('-');
  if (p.length !== 3) return s;
  return p[2] + '/' + p[1] + '/' + p[0] + (t ? ' ' + t.slice(0, 5) : '');
}

function fmtSize(n) {
  n = Number(n) || 0;
  if (n >= 1048576) return (n / 1048576).toFixed(1).replace('.', ',') + ' Mo';
  return Math.max(1, Math.round(n / 1024)) + ' ko';
}

/* Durée en minutes rendue lisible : « 1 h 45 » plutôt que « 105 ». */
function fmtDuree(minutes) {
  minutes = Math.max(0, Math.round(Number(minutes) || 0));
  if (minutes < 60) return minutes + ' min';
  const h = Math.floor(minutes / 60), m = minutes % 60;
  return h + ' h' + (m ? ' ' + String(m).padStart(2, '0') : '');
}

/* « il y a 3 jours », plus parlant qu'une date pour juger de l'ancienneté */
function depuis(jours) {
  jours = Number(jours) || 0;
  if (jours < 1) return "aujourd'hui";
  if (jours === 1) return 'hier';
  return 'il y a ' + jours + ' jours';
}

function toast(msg, erreur) {
  const el = document.createElement('div');
  el.className = 'toast' + (erreur ? ' erreur' : '');
  el.textContent = msg;
  $('#toast-root').appendChild(el);
  setTimeout(() => el.remove(), erreur ? 5200 : 3200);
}

function estStaff() { return S.user && S.user.role === 'admin'; }
function estAdmin() { return estStaff(); }

function chipStatut(s) { const o = STATUTS[s]; return '<span class="chip st-' + esc(s) + '">' + esc(o ? o.lbl : s) + '</span>'; }
function chipPrio(p)   { const o = PRIORITES[p]; return '<span class="chip pr-' + esc(p) + '">' + esc(o ? o.lbl : p) + '</span>'; }

function options(list, selected, placeholder) {
  let h = placeholder != null ? '<option value="">' + esc(placeholder) + '</option>' : '';
  for (const v of list) {
    h += '<option value="' + esc(v) + '"' + (v === selected ? ' selected' : '') + '>' + esc(v) + '</option>';
  }
  return h;
}
function optionsMap(map, selected, placeholder) {
  let h = placeholder != null ? '<option value="">' + esc(placeholder) + '</option>' : '';
  for (const k of Object.keys(map)) {
    h += '<option value="' + k + '"' + (k === selected ? ' selected' : '') + '>' + esc(map[k].lbl) + '</option>';
  }
  return h;
}

/* ------------------------------------------------ préférences locales (cookies) */

function pref(nom, valeur) {
  if (valeur === undefined) {
    const m = document.cookie.match(new RegExp('(?:^|; )d8_' + nom + '=([^;]*)'));
    return m ? decodeURIComponent(m[1]) : null;
  }
  const chemin = S.chemin || (location.pathname.replace(/[^/]*$/, '') || '/');
  document.cookie = 'd8_' + nom + '=' + encodeURIComponent(valeur) + ';path=' + chemin +
                    ';max-age=31536000;samesite=Lax';
  return valeur;
}

/* ------------------------------------------------ son de notification */

/* Trois notes douces (mi–sol#–si), synthétisées à la volée : aucun fichier
   audio à héberger, et le son reste identique sur tous les postes.
   Enveloppe lente à l'attaque et longue à la chute = carillon, pas alarme. */
const Son = {
  ctx: null,
  actif: pref('son') !== '0',

  init() {
    if (!this.ctx) {
      const AC = window.AudioContext || window.webkitAudioContext;
      if (AC) this.ctx = new AC();
    }
    if (this.ctx && this.ctx.state === 'suspended') this.ctx.resume();
    return this.ctx;
  },

  note(freq, debut, duree, volume) {
    const ctx = this.ctx;
    const t = ctx.currentTime + debut;
    const g = ctx.createGain();
    g.connect(ctx.destination);
    g.gain.setValueAtTime(0.0001, t);
    g.gain.exponentialRampToValueAtTime(volume, t + 0.06);
    g.gain.exponentialRampToValueAtTime(0.0001, t + duree);

    // Fondamentale sinus + octave très discrète : donne un timbre de carillon.
    [[freq, 1], [freq * 2, 0.22]].forEach(([f, part]) => {
      const o = ctx.createOscillator();
      o.type = 'sine';
      o.frequency.value = f;
      const gg = ctx.createGain();
      gg.gain.value = part;
      o.connect(gg); gg.connect(g);
      o.start(t); o.stop(t + duree + 0.05);
    });
  },

  jouer(type) {
    if (!this.actif) return;
    try {
      if (!this.init()) return;
      if (type === 'succes') {
        // Deux notes montantes, très brèves : une confirmation, pas une alerte.
        this.note(659.25, 0,    0.5, 0.10);
        this.note(987.77, 0.09, 0.6, 0.09);
      } else {
        // Arpège mi – sol# – si : accord majeur, sonorité chaude et posée.
        this.note(659.25, 0,    0.9, 0.11);
        this.note(830.61, 0.11, 0.9, 0.10);
        this.note(987.77, 0.22, 1.3, 0.10);
      }
    } catch (e) { /* audio indisponible : on continue en silence */ }
  },

  basculer() {
    this.actif = !this.actif;
    pref('son', this.actif ? '1' : '0');
    if (this.actif) this.jouer('succes');
    majBoutonSon();
  },
};

/* ------------------------------------------------ taille du texte */

/* Cinq crans de lecture, réglés au curseur. Le niveau 1 est la taille de
   référence ; on peut descendre d'un cran pour voir plus de lignes, ou
   monter jusqu'à 23 px pour un écran d'atelier ou une presbytie.
   L'état vit en mémoire — le cookie ne sert qu'à le retrouver au prochain
   démarrage — pour que le réglage fonctionne même sans cookies. */
const TAILLES = ['15px', '17px', '19px', '21px', '23px'];
const TAILLES_LBL = ['plus petit', 'normal', 'grand', 'très grand', 'maximum'];
let tailleCourante = 1;

function appliquerTaille(n) {
  n = Math.min(TAILLES.length - 1, Math.max(0, parseInt(n, 10) || 0));
  tailleCourante = n;
  for (let i = 0; i < TAILLES.length; i++) document.documentElement.classList.remove('txt-' + i);
  document.documentElement.classList.add('txt-' + n);
  pref('taille', String(n));
  const c = $('#curseur-taille');
  if (c) {
    if (Number(c.value) !== n) c.value = String(n);
    c.setAttribute('aria-valuetext', 'Texte ' + TAILLES_LBL[n]);
    c.setAttribute('title', 'Taille du texte : ' + TAILLES_LBL[n]);
  }
}
appliquerTaille(pref('taille') != null ? pref('taille') : 1);

/* ------------------------------------------------ icônes (SVG intégrés) */

const I = (d) => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + d + '</svg>';
const ICONES = {
  dash:   I('<rect x="3.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="13.5" width="7" height="7" rx="1.5"/>'),
  ticket: I('<path d="M4 5.5h16v4a2.5 2.5 0 0 0 0 5v4H4v-4a2.5 2.5 0 0 0 0-5v-4z"/><path d="M14 5.5v13" stroke-dasharray="2.4 2.6"/>'),
  plus:   I('<circle cx="12" cy="12" r="8.5"/><path d="M12 8.5v7M8.5 12h7"/>'),
  users:  I('<circle cx="9" cy="8.5" r="3.2"/><path d="M3.5 19c.6-3 2.8-4.6 5.5-4.6s4.9 1.6 5.5 4.6"/><circle cx="17" cy="9.5" r="2.4"/><path d="M16 14.6c2.3.2 4 1.6 4.5 4.4"/>'),
  gear:   I('<circle cx="12" cy="12" r="3.2"/><path d="M12 3.2v2.2M12 18.6v2.2M3.2 12h2.2M18.6 12h2.2M5.8 5.8l1.6 1.6M16.6 16.6l1.6 1.6M18.2 5.8l-1.6 1.6M7.4 16.6l-1.6 1.6"/>'),
  stats:  I('<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>'),
  key:    I('<circle cx="8" cy="14" r="4"/><path d="M11 11l8-8M16 6l2.5 2.5M13.5 8.5l2 2"/>'),
  out:    I('<path d="M14 4H6.5A1.5 1.5 0 0 0 5 5.5v13A1.5 1.5 0 0 0 6.5 20H14"/><path d="M10 12h10M17 8.5L20.5 12 17 15.5"/>'),
  maj:    I('<path d="M4.5 12a7.5 7.5 0 0 1 13-5.1L20 9"/><path d="M20 4.5V9h-4.5"/><path d="M19.5 12a7.5 7.5 0 0 1-13 5.1L4 15"/><path d="M4 19.5V15h4.5"/>'),
  retour: I('<path d="M15 5l-7 7 7 7"/>'),
  clip:   I('<path d="M8.5 12.5l6.2-6.2a3 3 0 0 1 4.2 4.2l-7.8 7.8a5 5 0 0 1-7-7l7.4-7.4"/>'),
  son:    I('<path d="M5 9.5h3.2L12.5 6v12l-4.3-3.5H5z"/><path d="M16 9.2a4 4 0 0 1 0 5.6M18.6 6.6a7.6 7.6 0 0 1 0 10.8"/>'),
  muet:   I('<path d="M5 9.5h3.2L12.5 6v12l-4.3-3.5H5z"/><path d="M16.5 9.5l5 5M21.5 9.5l-5 5"/>'),
  texte:  I('<path d="M4 19l5.5-14L15 19M6 14.5h7"/><path d="M17 19l3-8 3 8M18 16.5h4"/>'),
  save:   I('<path d="M12 3.5v10M8.5 10L12 13.5 15.5 10"/><path d="M4.5 15.5v3A1.5 1.5 0 0 0 6 20h12a1.5 1.5 0 0 0 1.5-1.5v-3"/>'),
  print:  I('<path d="M7 9V4h10v5"/><rect x="3.5" y="9" width="17" height="7" rx="1.5"/><path d="M7 14h10v6H7z"/>'),
  livre:  I('<path d="M4 5.5A1.5 1.5 0 0 1 5.5 4H11v16H5.5A1.5 1.5 0 0 1 4 18.5v-13z"/><path d="M20 5.5A1.5 1.5 0 0 0 18.5 4H13v16h5.5a1.5 1.5 0 0 0 1.5-1.5v-13z"/>'),
  journal: I('<path d="M6 3.5h12v17H6z"/><path d="M9 8h6M9 12h6M9 16h4"/>'),
  info:   I('<circle cx="12" cy="12" r="8.5"/><path d="M12 11v5.5M12 7.8v.6"/>'),
  liste:  I('<path d="M9 6h11M9 12h11M9 18h11M4.5 6h.01M4.5 12h.01M4.5 18h.01"/>'),
  loupe:  I('<circle cx="11" cy="11" r="6.5"/><path d="M16 16l4 4"/>'),
  chevron: I('<path d="M9 6l6 6-6 6"/>'),
  hand:   I('<path d="M9 11V5.5a1.5 1.5 0 0 1 3 0V11"/><path d="M12 11V4.5a1.5 1.5 0 0 1 3 0V11"/><path d="M15 11V6.5a1.5 1.5 0 0 1 3 0V15a5 5 0 0 1-5 5h-1.5a5 5 0 0 1-4.4-2.6L5 13.5a1.5 1.5 0 0 1 2.4-1.8L9 13.5V11"/>'),
};
function ico(nom) { return '<span class="ico">' + (ICONES[nom] || '') + '</span>'; }

/* Monogramme sobre construit à partir du nom de l'outil : il reste juste
   si l'application est renommée, et ne dépend d'aucune illustration. */
function initialesMarque(nom) {
  const mots = String(nom || 'Support').trim().split(/[\s.-]+/).filter(Boolean);
  if (mots.length >= 2) return (mots[0][0] + mots[1][0]).toUpperCase();
  const seul = mots[0] || 'S';
  const majInterne = seul.slice(1).match(/[A-Z0-9]/);
  return (seul[0] + (majInterne ? majInterne[0] : (seul[1] || ''))).toUpperCase();
}

function marqueHTML(nom, classe) {
  return '<span class="' + (classe || 'brand-mark') + '">' + esc(initialesMarque(nom)) + '</span>';
}

/* ------------------------------------------------ brouillons

   Un texte en cours de saisie ne doit jamais disparaître parce qu'on a
   cliqué à côté ou fermé l'onglet par erreur. Les brouillons vivent en
   mémoire le temps de la session et sont restaurés au retour. */

const Brouillons = {
  data: {},
  lire(cle) { return this.data[cle] || ''; },
  ecrire(cle, texte) {
    if (texte && texte.trim()) this.data[cle] = texte; else delete this.data[cle];
  },
  vider(cle) { delete this.data[cle]; },
  /* Un poste partagé (quai, atelier) passe d'une personne à l'autre : les
     brouillons de la précédente ne doivent jamais réapparaître chez la
     suivante. Tout est effacé à la déconnexion. */
  toutEffacer() { this.data = {}; },
  enCours() { return Object.keys(this.data).length > 0; },

  /* Branche un champ : sauvegarde à la frappe, restauration à l'affichage. */
  brancher(champ, cle) {
    if (!champ) return;
    const existant = this.lire(cle);
    if (existant && !champ.value) {
      champ.value = existant;
      champ.classList.add('brouillon-restaure');
      setTimeout(() => champ.classList.remove('brouillon-restaure'), 2500);
    }
    champ.addEventListener('input', () => this.ecrire(cle, champ.value));
  },
};

window.addEventListener('beforeunload', (e) => {
  if (Brouillons.enCours()) { e.preventDefault(); e.returnValue = ''; }
});

/* ------------------------------------------------ état du réseau

   Sur le wifi d'un entrepôt ou depuis un PDA, la liaison décroche. Mieux
   vaut le dire clairement que laisser croire à une panne de l'outil. */

function majReseau() {
  const horsLigne = navigator.onLine === false;
  let bandeau = $('#hors-ligne');
  if (horsLigne && !bandeau) {
    bandeau = document.createElement('div');
    bandeau.id = 'hors-ligne';
    bandeau.className = 'bandeau-reseau';
    bandeau.setAttribute('role', 'status');
    bandeau.textContent = 'Connexion perdue — votre texte est conservé, il repartira une fois le réseau revenu.';
    document.body.appendChild(bandeau);
  } else if (!horsLigne && bandeau) {
    bandeau.remove();
  }
}
window.addEventListener('online', majReseau);
window.addEventListener('offline', majReseau);
majReseau();

/* ------------------------------------------------ appels à l'API */

async function api(action, data, files, options) {
  // « silencieux » : pour l'interrogation périodique, qui ne doit pas
  // afficher un message d'erreur toutes les 45 secondes si le réseau tousse.
  const silencieux = !!(options && options.silencieux);
  const opt = { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF': S.csrf } };
  if (files && files.length) {
    const fd = new FormData();
    Object.entries(data || {}).forEach(([k, v]) => fd.append(k, v == null ? '' : v));
    files.forEach(f => fd.append('files[]', f));
    opt.body = fd;
  } else {
    opt.headers['Content-Type'] = 'application/json';
    opt.body = JSON.stringify(data || {});
  }

  let r, texte, j = null;
  try {
    r = await fetch('' + D8_APP + '?action=' + encodeURIComponent(action), opt);
    texte = await r.text();
  } catch (e) {
    majReseau();
    if (!silencieux) {
      toast(navigator.onLine === false
        ? 'Vous êtes hors connexion. Votre texte est conservé : réessayez dès que le réseau revient.'
        : 'Impossible de joindre le serveur. Vérifiez le réseau puis réessayez.', true);
    }
    throw e;
  }
  try {
    j = JSON.parse(texte);
  } catch (e) {
    // Réponse qui n'est pas du JSON : presque toujours une erreur PHP affichée
    // en clair. Le message générique « problème réseau » induisait en erreur.
    console.error('Réponse inattendue du serveur :', texte.slice(0, 800));
    if (!silencieux) {
      toast("Le serveur a renvoyé une réponse inattendue. Prévenez le service informatique " +
            "(détail dans la console du navigateur, touche F12).", true);
    }
    throw e;
  }
  if (!j.ok) {
    if (r.status === 401) {
      // Session perdue : on le dit clairement au lieu de renvoyer
      // l'utilisateur sur l'écran de connexion sans explication.
      // Plusieurs appels peuvent échouer en même temps : on ne le fait qu'une fois.
      if (S.user) {
        S.user = null;
        S.filtres = null;
        showAuth('login');
        toast('Votre session a pris fin. Reconnectez-vous.', true);
      }
    } else if (!silencieux) {
      toast(j.error || 'Une erreur est survenue.', true);
    }
    throw new Error(j.error || 'api');
  }
  return j.data;
}

/* ------------------------------------------------ démarrage */

async function boot() {
  try {
    const d = await api('boot');
    appliquerBoot(d);
    if (d.need_setup) { showAuth('setup'); return; }
    if (!d.user) { showAuth('login'); return; }
    S.user = d.user;
    enterApp();
  } catch (e) { /* le toast a déjà été affiché */ }
}

function appliquerBoot(d) {
  S.csrf = d.csrf;
  S.appName = d.app_name || 'D8 Support';
  S.categories = d.categories || [];
  S.sites = d.sites || [];
  S.templates = d.templates || [];
  S.assignables = d.assignables || [];
  S.unread = d.unread || 0;
  S.staleDays = d.stale_days || 3;
  S.canChangePassword = !!d.can_change_password;
  S.codeFichier = d.code_fichier || '';
  S.annuaire = !!d.annuaire_actif;
  S.chemin = d.chemin || S.chemin || '';
  document.title = S.appName + ' — Tickets informatiques';
}

/* ------------------------------------------------ écrans de connexion */

function showAuth(mode) {
  $('#app').classList.add('hidden');
  const tu = $('#topbar-user');
  if (tu) tu.innerHTML = '';
  arreterVeille();
  const root = $('#screen-auth');
  root.classList.remove('hidden');

  const marque =
    '<div class="auth-brand">' + marqueHTML(S.appName, 'brand-mark brand-mark-grand') +
    '<div><div class="brand-name">' + esc(S.appName) + '</div>' +
    '<div class="brand-sub">Assistance informatique</div></div></div>';

  if (mode === 'setup') {
    root.innerHTML =
      '<div class="auth-card">' + marque +
      "<p><b>Bienvenue !</b> L'application démarre pour la première fois.<br>Créez le compte administrateur du service informatique.</p>" +
      '<div class="encart-code"><b>Code d\'installation</b><br>' +
      'Ouvrez le fichier <code>' + esc(S.codeFichier || 'data\\.ht_installation.txt') + '</code> ' +
      'dans le dossier de l\'application, sur le serveur, et recopiez le code qu\'il contient. ' +
      'Il garantit que seule la personne qui a installé l\'outil peut créer ce compte.</div>' +
      '<form id="f-setup" novalidate>' +
      '<div class="field"><label for="s-code">Code d\'installation</label>' +
      '<input id="s-code" class="input" type="text" autocomplete="off" maxlength="6" ' +
      'style="text-transform:uppercase;letter-spacing:.25em;font-weight:700"></div>' +
      '<div class="field"><label for="s-nom">Votre nom complet</label><input id="s-nom" class="input" type="text" autocomplete="name"></div>' +
      '<div class="field"><label for="s-email">Adresse email</label><input id="s-email" class="input" type="email" autocomplete="username"></div>' +
      '<div class="field"><label for="s-mdp">Mot de passe (8 caractères minimum)</label><input id="s-mdp" class="input" type="password" autocomplete="new-password"></div>' +
      '<div class="field"><label for="s-mdp2">Confirmez le mot de passe</label><input id="s-mdp2" class="input" type="password" autocomplete="new-password"></div>' +
      '<button class="btn btn-primary" type="submit">Créer le compte administrateur</button>' +
      '</form></div>';

    $('#f-setup').addEventListener('submit', async (e) => {
      e.preventDefault();
      const mdp = $('#s-mdp').value;
      if (mdp !== $('#s-mdp2').value) { toast('Les deux mots de passe ne sont pas identiques.', true); return; }
      const btn = e.target.querySelector('button');
      btn.disabled = true;
      try {
        const d = await api('setup', { code: $('#s-code').value.trim(), name: $('#s-nom').value.trim(),
                                       email: $('#s-email').value.trim(), password: mdp });
        S.user = d.user; S.csrf = d.csrf;
        appliquerBoot(await api('boot'));
        toast('Compte créé. Bienvenue !');
        enterApp();
      } catch (err) { btn.disabled = false; }
    });
    return;
  }

  root.innerHTML =
    '<div class="auth-card">' + marque +
    '<form id="f-login" novalidate>' +
    '<div class="field"><label for="l-email">' +
    (S.annuaire ? 'Identifiant Windows ou adresse email' : 'Adresse email') + '</label>' +
    '<input id="l-email" class="input" type="text" autocomplete="username"></div>' +
    '<div class="field"><label for="l-mdp">Mot de passe' +
    (S.annuaire ? ' de votre session Windows' : '') + '</label>' +
    '<input id="l-mdp" class="input" type="password" autocomplete="current-password"></div>' +
    '<button class="btn btn-primary" type="submit">Se connecter</button>' +
    '</form>' +
    '<p class="auth-note">' + (S.annuaire
      ? 'Utilisez les mêmes identifiants que pour ouvrir votre session sur votre poste.'
      : 'Mot de passe oublié ? Adressez-vous au service informatique.') + '</p></div>';

  $('#l-email').focus();
  $('#f-login').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = e.target.querySelector('button');
    btn.disabled = true; btn.textContent = 'Connexion…';
    try {
      const d = await api('login', { email: $('#l-email').value.trim(), password: $('#l-mdp').value });
      S.user = d.user; S.csrf = d.csrf;
      appliquerBoot(await api('boot'));
      Son.init(); // le clic de connexion autorise l'audio pour la suite
      enterApp();
    } catch (err) {
      btn.disabled = false; btn.textContent = 'Se connecter';
    }
  });
}

/* ------------------------------------------------ coquille de l'application */

let shellPret = false;

function enterApp() {
  $('#screen-auth').classList.add('hidden');
  $('#app').classList.remove('hidden');
  document.querySelectorAll('.brand-name, .tt-app').forEach(e => { e.textContent = S.appName; });
  const mark = $('#brand-mark');
  if (mark) mark.textContent = initialesMarque(S.appName);
  buildNav();
  buildFooter();
  const compteurs = $('#topbar-compteurs');
  if (compteurs) compteurs.innerHTML = '';
  demarrerVeille();
  veille();   // remplit immédiatement les compteurs du haut

  if (!shellPret) {
    shellPret = true;
    // Le lien d'évitement pointait sur #main : le routeur y voyait une
    // adresse inconnue et renvoyait l'utilisateur sur la page d'accueil.
    const skip = document.querySelector('.skip');
    if (skip) skip.addEventListener('click', (e) => {
      e.preventDefault();
      const zone = $('#main');
      if (zone) { zone.setAttribute('tabindex', '-1'); zone.focus(); }
    });
    $('#btn-menu').addEventListener('click', () => ouvrirMenu(true));
    $('#scrim').addEventListener('click', () => ouvrirMenu(false));
    window.addEventListener('hashchange', route);
    // Le navigateur n'autorise le son qu'après une action de l'utilisateur.
    document.addEventListener('click', () => Son.init(), { once: true });
    brancherRaccourcis();
  }

  const defaut = estStaff() ? '#/dashboard' : '#/tickets';
  if (!location.hash || location.hash === '#') {
    location.hash = defaut;
  } else {
    route();
  }
}

/* Le pied du menu est reconstruit à chaque entrée : un employé n'a pas
   d'élément « changer mon mot de passe » caché en CSS, il n'existe tout
   simplement pas dans la page. */
function buildFooter() {
  /* Le bloc utilisateur vit en haut à droite, comme dans les outils de
     gestion : la barre latérale entière reste disponible pour la navigation.
     Sur un portable, elle n'offrait plus que six entrées visibles sur vingt. */
  const zone = $('#topbar-user');
  if (!zone) return;

  zone.innerHTML =
    '<button type="button" class="tu-bouton" id="tu-bouton" aria-expanded="false" aria-haspopup="true">' +
    '<span class="tu-initiales" aria-hidden="true">' + esc(initialesMarque(S.user.name)) + '</span>' +
    '<span class="tu-textes"><span class="tu-nom" id="me-name">' + esc(S.user.name) + '</span>' +
    '<span class="tu-role" id="me-role">' + esc(ROLES[S.user.role] || S.user.role) + '</span></span>' +
    ico('chevron') + '</button>' +
    '<div class="tu-panneau hidden" id="tu-panneau">' +
    '<div class="tu-section"><div class="tu-titre">Taille du texte</div>' +
    '<div class="reglage-texte" title="Taille du texte">' +
    '<span class="txt-petit" aria-hidden="true">A</span>' +
    '<input type="range" id="curseur-taille" min="0" max="4" step="1" value="' + tailleCourante + '" ' +
    'aria-label="Taille du texte">' +
    '<span class="txt-gros" aria-hidden="true">A</span></div></div>' +
    '<button type="button" class="tu-lien" id="btn-son"></button>' +
    (S.canChangePassword
      ? '<button type="button" class="tu-lien" id="btn-account">' + ico('key') +
        '<span class="nav-lbl">Changer mon mot de passe</span></button>'
      : '') +
    '<button type="button" class="tu-lien tu-sortie" id="btn-logout">' + ico('out') +
    '<span class="nav-lbl">Se déconnecter</span></button>' +
    '</div>';

  const bouton = $('#tu-bouton');
  const panneau = $('#tu-panneau');
  const ouvrir = (v) => {
    panneau.classList.toggle('hidden', !v);
    bouton.setAttribute('aria-expanded', v ? 'true' : 'false');
    zone.classList.toggle('ouvert', v);
  };
  bouton.addEventListener('click', (e) => {
    e.stopPropagation();
    ouvrir(panneau.classList.contains('hidden'));
  });
  panneau.addEventListener('click', (e) => e.stopPropagation());
  if (!document.body.dataset.tuFerme) {
    document.body.dataset.tuFerme = '1';
    document.addEventListener('click', () => {
      const p = $('#tu-panneau');
      if (p && !p.classList.contains('hidden')) {
        p.classList.add('hidden');
        const b = $('#tu-bouton');
        if (b) b.setAttribute('aria-expanded', 'false');
        $('#topbar-user').classList.remove('ouvert');
      }
    });
    document.addEventListener('keydown', (e) => {
      if (e.key !== 'Escape') return;
      const p = $('#tu-panneau');
      if (p && !p.classList.contains('hidden')) {
        p.classList.add('hidden');
        const b = $('#tu-bouton');
        if (b) { b.setAttribute('aria-expanded', 'false'); b.focus(); }
        $('#topbar-user').classList.remove('ouvert');
      }
    });
  }

  const curseur = $('#curseur-taille');
  curseur.addEventListener('input', () => appliquerTaille(curseur.value));

  $('#btn-son').addEventListener('click', () => Son.basculer());
  majBoutonSon();

  const compte = $('#btn-account');
  if (compte) compte.addEventListener('click', () => { ouvrir(false); modaleMotDePasse(); });

  $('#btn-logout').addEventListener('click', async () => {
    try { await api('logout'); } catch (e) {}
    S.user = null; S.filtres = null;
    Brouillons.toutEffacer();
    try {
      history.replaceState(null, '', location.pathname + location.search);
    } catch (e) {
      // Certains navigateurs refusent replaceState hors serveur web : sans gravité.
    }
    showAuth('login');
  });
}

function buildNav() {
  const staff = estStaff();

  /* Menu en sections dépliables : chaque vue courante du service est à un
     clic, sans avoir à composer les filtres. Les sections mémorisent leur
     état d'ouverture d'une visite à l'autre. */
  const ouvert = (cle, defaut) => {
    const v = pref('menu_' + cle);
    return v === null ? defaut : v === '1';
  };

  const mesVues = [
    { f: {}, lbl: 'Toutes mes demandes' },
    { f: { open_only: true }, lbl: 'En cours de traitement' },
    { f: { status: 'resolu' }, lbl: 'À confirmer' },
    { f: { unread_only: true }, lbl: 'Avec du nouveau' },
  ];
  const vuesService = [
    { f: { open_only: true }, lbl: 'Tickets ouverts' },
    { f: { assigned: String(S.user.id), open_only: true }, lbl: 'Qui me sont assignés' },
    { f: { assigned: 'none', open_only: true }, lbl: 'Non assignés' },
    { f: { status: 'nouveau' }, lbl: 'Nouveaux' },
    { f: { status: 'en_attente' }, lbl: 'En attente' },
    { f: { stale_only: true }, lbl: 'Sans réponse' },
    { f: { priority: 'critique', open_only: true }, lbl: 'Critiques' },
    { f: { status: 'ferme' }, lbl: 'Archivés' },
  ];

  const lien = (h, ic, lbl, badge) =>
    '<a class="nav-link" href="' + h + '">' + ico(ic) + '<span class="nav-lbl">' + esc(lbl) + '</span>' +
    (badge ? '<span class="badge hidden" id="badge-nouveautes">0</span>' : '') + '</a>';

  const section = (cle, ic, titre, vues, defautOuvert) =>
    '<div class="nav-groupe' + (ouvert(cle, defautOuvert) ? ' ouvert' : '') + '" data-groupe="' + cle + '">' +
    '<button type="button" class="nav-link nav-tete" aria-expanded="' + (ouvert(cle, defautOuvert) ? 'true' : 'false') + '">' +
    ico(ic) + '<span class="nav-lbl">' + esc(titre) + '</span>' + ico('chevron') + '</button>' +
    '<div class="nav-sous">' + vues.map((v, i) =>
      '<button type="button" class="nav-sous-lien" data-vue="' + cle + ':' + i + '">' + esc(v.lbl) + '</button>'
    ).join('') + '</div></div>';

  let html = '';
  if (staff) {
    html += lien('#/dashboard', 'dash', 'Tableau de bord');
    html += section('service', 'ticket', 'Tickets', vuesService, true);
    html += lien('#/tickets', 'liste', 'Recherche avancée');
  } else {
    html += section('mes', 'ticket', 'Mes demandes', mesVues, true);
  }
  html += lien('#/nouveau', 'plus', 'Nouveau ticket');
  html += lien('#/procedures', 'livre', staff ? 'Procédures' : 'Aide et procédures');
  html += '<div class="nav-sep"></div>';
  if (staff) {
    html += lien('#/statistiques', 'stats', 'Statistiques');
    // Tout est visible d'emblée : une entrée qu'il faut deviner derrière un
    // chevron est une entrée qu'on ne trouve pas. Chacun peut replier
    // ensuite, et l'outil s'en souvient.
    html += '<div class="nav-groupe' + (ouvert('admin', true) ? ' ouvert' : '') + '" data-groupe="admin">' +
      '<button type="button" class="nav-link nav-tete" aria-expanded="' + (ouvert('admin', true) ? 'true' : 'false') + '">' +
      ico('gear') + '<span class="nav-lbl">Administration</span>' + ico('chevron') + '</button>' +
      '<div class="nav-sous">' +
      '<a class="nav-sous-lien" href="#/parametres">Paramètres</a>' +
      '<a class="nav-sous-lien" href="#/listes">Listes</a>' +
      '<a class="nav-sous-lien" href="#/utilisateurs">Utilisateurs</a>' +
      '<a class="nav-sous-lien" href="#/journaux">Journaux</a>' +
      '<a class="nav-sous-lien" href="#/informations">Informations</a>' +
      '<a class="nav-sous-lien" href="' + D8_APP + '?page=verification">Vérifier l\'installation</a>' +
      '</div></div>';
  }
  $('#nav').innerHTML =
    '<div class="nav-recherche"><label class="sr-only" for="nav-q">Rechercher un ticket</label>' +
    ico('loupe') + '<input id="nav-q" type="search" placeholder="Rechercher un ticket…" ' +
    'autocomplete="off"></div>' + html;

  // Une recherche depuis le menu emmène directement à la liste filtrée.
  const champ = $('#nav-q');
  champ.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter') return;
    const q = champ.value.trim();
    if (!q) return;
    S.filtres = Object.assign(filtresDefaut(), { q, open_only: false, page: 1 });
    champ.value = '';
    ouvrirMenu(false);
    if (location.hash === '#/tickets') vueTickets(); else location.hash = '#/tickets';
  });

  // Ouverture et fermeture des sections.
  document.querySelectorAll('#nav .nav-tete').forEach(b => {
    b.addEventListener('click', () => {
      const g = b.parentElement;
      const v = !g.classList.contains('ouvert');
      g.classList.toggle('ouvert', v);
      b.setAttribute('aria-expanded', v ? 'true' : 'false');
      pref('menu_' + g.dataset.groupe, v ? '1' : '0');
    });
  });

  // Les vues préréglées appliquent un jeu de filtres et affichent la liste.
  document.querySelectorAll('#nav .nav-sous-lien[data-vue]').forEach(b => {
    b.addEventListener('click', () => {
      const [cle, i] = b.dataset.vue.split(':');
      const vues = cle === 'mes' ? mesVues : vuesService;
      S.filtres = Object.assign(filtresDefaut(), { open_only: false }, vues[Number(i)].f, { page: 1 });
      S.vueMenu = b.dataset.vue;
      ouvrirMenu(false);
      if (location.hash === '#/tickets') vueTickets(); else location.hash = '#/tickets';
      document.querySelectorAll('#nav .nav-sous-lien').forEach(x => x.classList.remove('actif'));
      b.classList.add('actif');
    });
  });

  // Sans ce gestionnaire, toucher l'entrée de la page déjà affichée ne
  // provoque aucun changement d'adresse : le menu mobile restait ouvert,
  // voile compris, et il fallait deviner qu'il fallait toucher à côté.
  // Posé une seule fois : buildNav est rappelé à chaque connexion.
  const nav = $('#nav');
  if (!nav.dataset.ferme) {
    nav.dataset.ferme = '1';
    nav.addEventListener('click', (e) => {
      if (e.target.closest('a')) ouvrirMenu(false);
    });
  }

  majBadge();
}

/* Compteurs permanents en haut d'écran : l'état du service sans avoir à
   revenir au tableau de bord. */
function majBarreHaut(d) {
  const zone = $('#topbar-compteurs');
  if (!zone) return;
  // Un employé qui prend la main sur le même poste ne doit pas hériter des
  // compteurs du service affichés par la session précédente.
  if (!estStaff()) { zone.innerHTML = ''; return; }
  const item = (n, lbl, cls, filtres) =>
    '<button type="button" class="tb-compteur ' + cls + '" data-f=\'' + JSON.stringify(filtres) + '\'>' +
    '<span class="tb-num">' + n + '</span><span class="tb-lbl">' + esc(lbl) + '</span></button>';
  zone.innerHTML =
    item(d.open || 0, 'à traiter', 'tb-ouvert', { open_only: true }) +
    item(d.unassigned || 0, 'non assignés', 'tb-nonassigne', { assigned: 'none', open_only: true }) +
    item(d.critical || 0, 'critiques', 'tb-critique', { priority: 'critique', open_only: true });
  zone.querySelectorAll('.tb-compteur').forEach(b => {
    b.addEventListener('click', () => {
      S.filtres = Object.assign(filtresDefaut(), JSON.parse(b.dataset.f), { open_only: false, page: 1 });
      if (location.hash === '#/tickets') vueTickets(); else location.hash = '#/tickets';
    });
  });
}

function majBadge() {
  // Le titre de l'onglet porte aussi le compteur : visible même quand
  // l'utilisateur travaille dans une autre fenêtre.
  document.title = (S.unread ? '(' + S.unread + ') ' : '') + S.appName + ' — Tickets informatiques';
  const b = $('#badge-nouveautes');
  if (!b) return;
  b.textContent = S.unread > 99 ? '99+' : String(S.unread);
  b.classList.toggle('hidden', !S.unread);
}

function majBoutonSon() {
  const b = $('#btn-son');
  if (!b) return;
  b.innerHTML = ico(Son.actif ? 'son' : 'muet') +
    '<span class="nav-lbl">' + (Son.actif ? 'Son activé' : 'Son coupé') + '</span>';
}

function ouvrirMenu(ouvert) {
  $('#sidebar').classList.toggle('ouvert', !!ouvert);
  $('#scrim').hidden = !ouvert;
}

/* ------------------------------------------------ raccourcis clavier

   Pour la personne qui ouvre l'outil cinquante fois par jour, gagner trois
   secondes à chaque fois compte davantage qu'une fonction de plus. Les
   raccourcis restent inactifs dès qu'on est en train de saisir du texte. */

function enSaisie() {
  const e = document.activeElement;
  if (!e) return false;
  return ['INPUT', 'TEXTAREA', 'SELECT'].includes(e.tagName) || e.isContentEditable;
}

function brancherRaccourcis() {
  document.addEventListener('keydown', (e) => {
    if (!S.user || e.ctrlKey || e.metaKey || e.altKey || enSaisie()) return;
    if (document.querySelector('.modal-fond')) return;   // une fenêtre est ouverte

    switch (e.key) {
      case '/':
        e.preventDefault();
        ouvrirMenu(true);
        if ($('#nav-q')) $('#nav-q').focus();
        break;
      case 'n':
        e.preventDefault();
        location.hash = '#/nouveau';
        break;
      case 'r':
        if ($('#msg-corps')) { e.preventDefault(); $('#msg-corps').focus(); }
        break;
      case 't':
        e.preventDefault();
        location.hash = '#/tickets';
        break;
      case '?':
        e.preventDefault();
        modaleRaccourcis();
        break;
    }
  });
}

function modaleRaccourcis() {
  const touches = [
    ['/', 'Rechercher un ticket'],
    ['N', 'Nouveau ticket'],
    ['T', 'Revenir à la liste'],
    ['R', 'Répondre (sur une fiche ouverte)'],
    ['Ctrl + Entrée', 'Envoyer le message en cours'],
    ['Échap', 'Fermer la fenêtre ouverte'],
    ['?', 'Afficher cette aide'],
  ];
  const m = modale(
    '<h2>Raccourcis clavier</h2>' +
    '<p class="sous-titre">Ils ne s\'activent pas pendant que vous écrivez.</p>' +
    '<div class="raccourcis">' + touches.map(t =>
      '<div class="rac-ligne"><kbd>' + esc(t[0]) + '</kbd><span>' + esc(t[1]) + '</span></div>').join('') +
    '</div><div class="modal-actions"><button type="button" class="btn btn-primary" data-a="ok">Fermer</button></div>'
  );
  m.el.querySelector('[data-a="ok"]').addEventListener('click', m.close);
}

/* ------------------------------------------------ veille des nouveautés */

let minuteurVeille = null;
let veilleEnCours = false;
let listeSeq = 0;   // numéro d'ordre des chargements de liste

function demarrerVeille() {
  arreterVeille();
  minuteurVeille = setInterval(veille, 45000);
}
function arreterVeille() {
  if (minuteurVeille) { clearInterval(minuteurVeille); minuteurVeille = null; }
}

/* Interroge le serveur toutes les 45 s : compteur du menu et son de
   notification quand du nouveau arrive pendant qu'on regarde ailleurs. */
async function veille() {
  // Sur un réseau lent, une interrogation pouvait démarrer avant que la
  // précédente ne réponde ; elles s'empilaient.
  if (!S.user || document.hidden || veilleEnCours) return;
  veilleEnCours = true;
  let d;
  try {
    d = await api('ping', {}, null, { silencieux: true });
  } catch (e) {
    return;
  } finally {
    veilleEnCours = false;
  }

  if (!d.connected) {
    S.user = null;
    S.filtres = null;
    Brouillons.toutEffacer();   // poste partagé : rien ne doit rester à l'écran suivant
    showAuth('login');
    toast("Vous avez été déconnecté après une période d'inactivité.", true);
    return;
  }

  if (d.unread > S.unread) {
    Son.jouer('notification');
    const n = d.unread - S.unread;
    toast(n === 1 ? 'Nouvelle activité sur un ticket.' : n + ' tickets ont du nouveau.');
    // Rafraîchit la vue courante si elle affiche une liste.
    if (location.hash.startsWith('#/tickets') && $('#liste')) chargerListe();
    if (location.hash.startsWith('#/dashboard')) vueDashboard();
  }
  // Le rôle peut changer pendant la session (promotion, rétrogradation) :
  // le menu doit suivre, sinon on continue de voir des entrées qui répondent
  // « accès refusé ».
  if (d.role && d.role !== S.user.role) {
    try {
      appliquerBoot(await api('boot'));
      S.user.role = d.role;
      buildNav();
      buildFooter();
      toast('Vos droits ont changé. Le menu a été mis à jour.');
      route();
    } catch (e) { /* on réessaiera au prochain passage */ }
  }

  S.unread = d.unread;
  majBadge();
  majBarreHaut(d);
}

/* ------------------------------------------------ routage (#/vue/argument) */

function route() {
  if (!S.user) return;
  nouveauToken();
  ouvrirMenu(false);
  const h = (location.hash || '').replace(/^#\/?/, '');
  const [vue, arg] = h.split('/');
  const cle = vue === 'ticket' ? 'tickets' : vue;
  document.querySelectorAll('#nav .nav-sous-lien[href]').forEach(a => {
    a.classList.toggle('actif', a.getAttribute('href') === '#/' + vue);
  });
  document.querySelectorAll('#nav .nav-link').forEach(a => {
    const actif = a.getAttribute('href') === '#/' + cle;
    a.classList.toggle('active', actif);
    // Repère lu par les lecteurs d'écran, en plus du repère visuel.
    if (actif) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
  });
  if (vue === 'ticket') filAriane(['Tickets', 'Ticket']);
  else if (vue === 'procedures') filAriane([estStaff() ? 'Procédures' : 'Aide et procédures']);
  else if (vue === 'tickets') filAriane([estStaff() ? 'Tickets' : 'Mes demandes']);
  else filAriane(TITRES[vue] || []);

  switch (vue) {
    case 'dashboard':     if (estStaff()) { vueDashboard(); return; } break;
    case 'tickets':       vueTickets(); return;
    case 'ticket':        vueTicket(parseInt(arg, 10) || 0); return;
    case 'nouveau':       vueNouveau(); return;
    case 'statistiques':  if (estAdmin()) { vueStats(); return; } break;
    case 'utilisateurs':  if (estAdmin()) { vueUtilisateurs(); return; } break;
    case 'procedures':    vueProcedures(); return;
    case 'parametres':    if (estAdmin()) { vueParametres(); return; } break;
    case 'listes':        if (estAdmin()) { vueListes(); return; } break;
    case 'journaux':      if (estAdmin()) { vueJournaux(); return; } break;
    case 'informations':  if (estAdmin()) { vueInformations(); return; } break;
    case 'affiche':       if (estAdmin()) { vueAffiche(); return; } break;
    case 'reglages':      if (estAdmin()) { location.hash = '#/parametres'; return; } break;
  }
  location.hash = estStaff() ? '#/dashboard' : '#/tickets';
}

function chargement() {
  return '<div class="chargement" role="status"><span class="roue" aria-hidden="true"></span>Chargement…</div>';
}

/* Fil d'Ariane de la barre supérieure : on sait toujours où l'on est et on
   remonte d'un clic, comme dans les outils de gestion établis. Sur mobile,
   la barre affiche le nom de l'outil à la place. */
const TITRES = {
  dashboard: ['Tableau de bord'],
  tickets: ['Tickets'],
  nouveau: ['Nouveau ticket'],
  procedures: ['Procédures'],
  statistiques: ['Statistiques'],
  utilisateurs: ['Administration', 'Utilisateurs'],
  parametres: ['Administration', 'Paramètres'],
  listes: ['Administration', 'Listes'],
  journaux: ['Administration', 'Journaux'],
  informations: ['Administration', 'Informations'],
  affiche: ['Administration', 'Informations', 'Affiche'],
};

function filAriane(segments) {
  const zone = $('#fil-ariane');
  if (!zone) return;
  const liens = { Tickets: '#/tickets', Informations: '#/informations' };
  zone.innerHTML = '<a href="#/" class="fa-accueil" title="Accueil">' + ico('dash') + '</a>' +
    segments.map((seg, i) => {
      const dernier = i === segments.length - 1;
      const cible = !dernier && liens[seg];
      return '<span class="fa-sep" aria-hidden="true">›</span>' +
        (cible ? '<a href="' + cible + '">' + esc(seg) + '</a>'
               : '<span' + (dernier ? ' aria-current="page"' : '') + '>' + esc(seg) + '</span>');
    }).join('');
}

/* Deux clics rapprochés lançaient deux chargements ; celui qui répondait en
   dernier écrasait l'autre et pouvait afficher le mauvais ticket. Chaque vue
   prend un jeton et n'écrit dans la page que si elle est toujours d'actualité. */
function nouveauToken() { return ++S.vueToken; }
function encoreValide(token) { return token === S.vueToken; }

/* ================================================ tableau de bord */

async function vueDashboard() {
  const token = S.vueToken;
  const main = $('#main');
  main.innerHTML =
    '<div class="page-head"><div><h1>Tableau de bord</h1>' +
    "<p class=\"sous-titre\">Vue d'ensemble des demandes en cours</p></div>" +
    '<div class="page-actions"><button type="button" class="btn" id="btn-maj">' + ico('maj') + 'Actualiser</button></div></div>' +
    '<div id="dash">' + chargement() + '</div>';
  $('#btn-maj').addEventListener('click', vueDashboard);

  let d;
  try { d = await api('dashboard'); } catch (e) { return; }
  if (!encoreValide(token)) return;

  const bs = d.by_status || {};
  const stats = [
    { num: bs.nouveau || 0,    lbl: 'Nouveaux',            cls: 'stat-nouveau',    f: { status: 'nouveau', open_only: false } },
    { num: bs.en_cours || 0,   lbl: 'En cours',            cls: 'stat-en_cours',   f: { status: 'en_cours', open_only: false } },
    { num: bs.en_attente || 0, lbl: 'En attente',          cls: 'stat-en_attente', f: { status: 'en_attente', open_only: false } },
    { num: d.critical,         lbl: 'Critiques ouverts',   cls: 'stat-critique',   f: { priority: 'critique', open_only: true }, alerte: d.critical > 0 },
    { num: d.unassigned,       lbl: 'Non assignés',        cls: 'stat-nonassigne', f: { assigned: 'none', open_only: true } },
    { num: d.stale,            lbl: 'Sans réponse > ' + d.stale_days + ' j', cls: 'stat-dormant', f: { stale_only: true }, alerte: d.stale > 0 },
    { num: d.mine,             lbl: 'Mes tickets ouverts', cls: 'stat-moi',        f: { assigned: String(S.user.id), open_only: true } },
  ];
  const charge = fmtDuree(d.charge_mois || 0);

  const parSite = (d.by_site || []).map(s => '<span>' + esc(s.site) + ' <b>' + s.count + '</b></span>').join('');

  const lignes = (d.recent || []).map(r =>
    '<tr tabindex="0" data-id="' + r.id + '"' + (Number(r.unread) ? ' class="non-lu"' : '') + '>' +
    '<td class="t-ref sans-label">' + esc(r.ref) + '</td>' +
    '<td class="sans-label"><span class="t-titre">' + esc(r.title) + '</span>' +
    '<span class="t-sub">' + esc(r.creator_name) + '</span></td>' +
    '<td data-l="Statut">' + chipStatut(r.status) + '</td>' +
    '<td data-l="Priorité">' + chipPrio(r.priority) + '</td>' +
    '<td class="t-date" data-l="Mis à jour">' + fmtDate(r.updated_at) + '</td></tr>'
  ).join('');

  const premiersPas = (d.nb_tickets === 0)
    ? '<div class="card guide"><h2>Premiers pas</h2>' +
      '<ol class="guide-liste">' +
      '<li><b>Vérifiez les listes.</b> Dans <a href="#/reglages">Réglages</a>, ajustez les catégories ' +
      'et les agences pour qu\'elles correspondent à votre organisation.</li>' +
      '<li><b>Créez les comptes.</b> Dans <a href="#/utilisateurs">Utilisateurs</a>, ajoutez le personnel ' +
      'en rôle « Employé »' + (d.nb_users < 2 ? ' — il n\'y a pour l\'instant que le vôtre.' : '.') + '</li>' +
      '<li><b>Diffusez l\'adresse.</b> Communiquez le lien de cette page et, si possible, ' +
      'posez un raccourci sur le bureau des postes.</li>' +
      '</ol><p class="sous-titre">Ce bloc disparaîtra dès le premier ticket créé.</p></div>'
    : '';

  // Trois compteurs portent la décision du matin ; les autres sont des
  // repères. Les mettre tous au même niveau revenait à n'en souligner aucun.
  const carte = (s, i, petit) =>
    '<button type="button" class="stat ' + s.cls + (petit ? ' stat-petit' : '') +
    (s.alerte ? ' alerte' : '') + '" data-i="' + i + '">' +
    '<div class="stat-num">' + s.num + '</div><div class="stat-lbl">' + esc(s.lbl) + '</div></button>';
  const principaux = [3, 4, 5];   // critiques, non assignés, dormants
  $('#dash').innerHTML = premiersPas +
    '<div class="stats stats-cles">' + principaux.map(i => carte(stats[i], i, false)).join('') + '</div>' +
    '<div class="stats stats-suite">' +
      stats.map((s, i) => principaux.includes(i) ? '' : carte(s, i, true)).join('') + '</div>' +
    '<div class="card"><h2>Charge du mois</h2>' +
    '<p><b>' + esc(charge) + '</b> de temps enregistré sur les tickets créés ce mois-ci.' +
    (d.charge_mois ? '' : ' Renseignez le temps passé au moment d\'enregistrer un ticket : ' +
      'c\'est ce chiffre qui manque quand il faut expliquer l\'activité du service.') + '</p></div>' +
    ((d.by_site || []).length ? '<div class="card"><h2>Tickets ouverts par site</h2><div class="mini-liste">' + parSite + '</div></div>' : '') +
    '<div class="card"><h2>Dernière activité</h2>' +
    ((d.recent || []).length
      ? '<div class="tbl-wrap"><table class="tbl"><tbody>' + lignes + '</tbody></table></div>'
      : '<p class="sous-titre">Aucun ticket pour le moment.</p>') +
    '</div>';

  document.querySelectorAll('#dash .stat').forEach(btn => {
    btn.addEventListener('click', () => {
      S.filtres = Object.assign(filtresDefaut(), stats[Number(btn.dataset.i)].f, { page: 1 });
      location.hash = '#/tickets';
    });
  });
  brancherLignes('#dash');
}

/* Sélection multiple : la barre d'actions n'apparaît qu'une fois au moins
   un ticket coché, pour ne pas encombrer l'écran le reste du temps. */
function brancherSelection() {
  const cases = () => Array.from(document.querySelectorAll('#liste .choix'));
  const choisis = () => cases().filter(c => c.checked).map(c => Number(c.dataset.id));

  const majBarre = () => {
    const n = choisis().length;
    const barre = $('#barre-lot');
    if (!barre) return;
    barre.classList.toggle('hidden', n === 0);
    document.querySelectorAll('#liste tbody tr').forEach(tr => {
      const c = tr.querySelector('.choix');
      tr.classList.toggle('choisi', !!(c && c.checked));
    });
    if (!n) return;
    barre.innerHTML =
      '<span class="lot-nb">' + n + (n > 1 ? ' tickets sélectionnés' : ' ticket sélectionné') + '</span>' +
      '<select class="input" id="lot-statut"><option value="">Changer le statut…</option>' +
      optionsMap(STATUTS, '') + '</select>' +
      '<select class="input" id="lot-prio"><option value="">Changer la priorité…</option>' +
      optionsMap(PRIORITES, '') + '</select>' +
      '<select class="input" id="lot-assigne"><option value="">Assigner à…</option>' +
      '<option value="aucun">Retirer l\'assignation</option>' +
      S.assignables.map(a => '<option value="' + a.id + '">' + esc(a.name) + '</option>').join('') +
      '</select>' +
      '<button type="button" class="btn btn-primary" id="lot-ok">Appliquer</button>' +
      '<button type="button" class="btn btn-ghost" id="lot-annuler">Annuler</button>';

    $('#lot-annuler').addEventListener('click', () => {
      cases().forEach(c => { c.checked = false; });
      const tout = $('#choix-tout');
      if (tout) { tout.checked = false; tout.indeterminate = false; }
      majBarre();
    });
    $('#lot-ok').addEventListener('click', async () => {
      const ids = choisis();
      const statut = $('#lot-statut').value;
      const prio = $('#lot-prio').value;
      const assigne = $('#lot-assigne').value;
      if (!statut && !prio && !assigne) {
        toast('Choisissez au moins une modification à appliquer.', true);
        return;
      }
      const resume = [statut ? 'statut « ' + STATUTS[statut].lbl + ' »' : '',
                      prio ? 'priorité « ' + PRIORITES[prio].lbl + ' »' : '',
                      assigne ? 'assignation' : ''].filter(Boolean).join(', ');
      if (!await modaleConfirm('Appliquer ' + resume + ' à ' + ids.length +
          (ids.length > 1 ? ' tickets' : ' ticket') + ' ?', { ok: 'Appliquer' })) return;
      const btn = $('#lot-ok');
      btn.disabled = true; btn.textContent = 'Application…';
      try {
        const donnees = { ids };
        if (statut) donnees.status = statut;
        if (prio) donnees.priority = prio;
        if (assigne) donnees.assigned_to = assigne === 'aucun' ? '' : assigne;
        const r = await api('tickets_bulk', donnees);
        Son.jouer('succes');
        toast(r.traites + (r.traites > 1 ? ' tickets modifiés' : ' ticket modifié') +
              (r.ignores ? ', ' + r.ignores + ' déjà à jour' : '') + '.');
        chargerListe();
      } catch (e) {
        btn.disabled = false; btn.textContent = 'Appliquer';
      }
    });
  };

  cases().forEach(c => c.addEventListener('change', () => {
    const tout = $('#choix-tout');
    if (tout) {
      const n = choisis().length;
      tout.checked = n === cases().length && n > 0;
      tout.indeterminate = n > 0 && n < cases().length;
    }
    majBarre();
  }));
  const tout = $('#choix-tout');
  if (tout) tout.addEventListener('change', () => {
    cases().forEach(c => { c.checked = tout.checked; });
    tout.indeterminate = false;
    majBarre();
  });
  // Une case cochée ne doit pas ouvrir le ticket.
  document.querySelectorAll('#liste .col-choix').forEach(td =>
    td.addEventListener('click', e => e.stopPropagation()));
}

function brancherLignes(scope) {
  document.querySelectorAll(scope + ' tr[data-id]').forEach(tr => {
    const aller = (e) => {
      if (e && e.target && e.target.closest('.col-choix')) return;
      location.hash = '#/ticket/' + tr.dataset.id;
    };
    tr.addEventListener('click', aller);
    tr.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); aller(); } });
  });
}

/* ================================================ statistiques */

async function vueStats() {
  const token = S.vueToken;
  const main = $('#main');
  main.innerHTML =
    '<div class="page-head"><div><h1>Statistiques</h1>' +
    "<p class=\"sous-titre\">Activité du service informatique — utile pour un point mensuel</p></div>" +
    '<div class="page-actions"><button type="button" class="btn" id="btn-print">' + ico('print') + 'Imprimer</button></div></div>' +
    '<div id="stats">' + chargement() + '</div>';
  $('#btn-print').addEventListener('click', () => window.print());

  let d;
  try { d = await api('stats'); } catch (e) { return; }
  if (!encoreValide(token)) return;

  const barres = (lignes, cleLbl, cleVal) => {
    const max = Math.max(1, ...lignes.map(l => Number(l[cleVal]) || 0));
    return '<div class="barres">' + lignes.map(l =>
      '<div class="barre-ligne"><div class="barre-lbl">' + esc(l[cleLbl] || '(non précisé)') + '</div>' +
      '<div class="barre-piste"><div class="barre-jauge" style="width:' +
      Math.round((Number(l[cleVal]) || 0) / max * 100) + '%"></div></div>' +
      '<div class="barre-val">' + (Number(l[cleVal]) || 0) + '</div></div>').join('') + '</div>';
  };

  const moisLbl = (m) => {
    const [a, mo] = String(m).split('-');
    return ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août',
            'sept.', 'oct.', 'nov.', 'déc.'][Number(mo) - 1] + ' ' + a.slice(2);
  };
  const mois = (d.par_mois || []).map(m => ({ lbl: moisLbl(m.m), c: m.c }));

  const delai = d.delai_moyen == null ? '—'
    : (d.delai_moyen < 1
        ? Math.round(d.delai_moyen * 24) + ' h'
        : String(d.delai_moyen).replace('.', ',') + ' jours');

  const ages = d.ages || {};
  const agesLignes = [
    { lbl: "Moins d'un jour", c: ages.j0 || 0 },
    { lbl: '1 à 3 jours',     c: ages.j1 || 0 },
    { lbl: '3 à 7 jours',     c: ages.j3 || 0 },
    { lbl: 'Plus de 7 jours', c: ages.j7 || 0 },
  ];

  $('#stats').innerHTML =
    '<div class="stats">' +
    '<div class="stat stat-nouveau"><div class="stat-num">' + d.total + '</div><div class="stat-lbl">Tickets au total</div></div>' +
    '<div class="stat stat-moi"><div class="stat-num">' + d.nb_clos + '</div><div class="stat-lbl">Tickets clos</div></div>' +
    '<div class="stat stat-en_cours"><div class="stat-num">' + delai + '</div><div class="stat-lbl">Délai moyen de résolution</div></div>' +
    '</div>' +
    '<div class="card"><h2>Tickets créés par mois</h2>' + barres(mois, 'lbl', 'c') + '</div>' +
    '<div class="card"><h2>Ancienneté des tickets ouverts</h2>' + barres(agesLignes, 'lbl', 'c') + '</div>' +
    '<div class="card"><h2>Par catégorie</h2>' + barres(d.par_categorie || [], 'category', 'total') + '</div>' +
    '<div class="card"><h2>Par site</h2>' + barres(d.par_site || [], 'site', 'total') + '</div>' +
    '<div class="card"><h2>Principaux demandeurs</h2>' + barres(d.demandeurs || [], 'name', 'total') + '</div>';
}

/* ================================================ liste des tickets */

function filtresDefaut() {
  return { status: '', priority: '', category: '', site: '', assigned: '', q: '',
           open_only: estStaff(), unread_only: false, stale_only: false,
           sort: 'updated_at', dir: 'desc', page: 1 };
}

function vueTickets() {
  if (!S.filtres) S.filtres = filtresDefaut();
  const f = S.filtres;
  const staff = estStaff();
  const main = $('#main');

  const optAssigne =
    '<option value=""' + (f.assigned === '' ? ' selected' : '') + '>Tous</option>' +
    '<option value="none"' + (f.assigned === 'none' ? ' selected' : '') + '>Non assigné</option>' +
    S.assignables.map(a =>
      '<option value="' + a.id + '"' + (String(f.assigned) === String(a.id) ? ' selected' : '') + '>' + esc(a.name) + '</option>'
    ).join('');

  main.innerHTML =
    '<div class="page-head"><div><h1>' + (staff ? 'Tickets' : 'Mes demandes') + '</h1>' +
    '<p class="sous-titre">' + (staff ? 'Toutes les demandes du personnel' : 'Vos demandes auprès du service informatique') + '</p></div>' +
    '<div class="page-actions">' +
    (staff ? '<button type="button" class="btn" id="btn-export">Exporter en CSV</button>' : '') +
    '<button type="button" class="btn" id="btn-maj">' + ico('maj') + 'Actualiser</button>' +
    '<a class="btn btn-primary" href="#/nouveau">' + ico('plus') + 'Nouveau ticket</a>' +
    '</div></div>' +

    '<div class="filtres">' +
    '<div class="field"><label for="fl-q">Recherche</label><input id="fl-q" class="input" type="search" placeholder="Réf, titre, mots-clés…" value="' + esc(f.q) + '"></div>' +
    '<div class="field"><label for="fl-statut">Statut</label><select id="fl-statut" class="input">' + optionsMap(STATUTS, f.status, 'Tous') + '</select></div>' +
    (staff ?
      '<div class="field"><label for="fl-prio">Priorité</label><select id="fl-prio" class="input">' + optionsMap(PRIORITES, f.priority, 'Toutes') + '</select></div>' +
      '<div class="field"><label for="fl-cat">Catégorie</label><select id="fl-cat" class="input">' + options(S.categories, f.category, 'Toutes') + '</select></div>' +
      '<div class="field"><label for="fl-site">Site</label><select id="fl-site" class="input">' + options(S.sites, f.site, 'Tous') + '</select></div>' +
      '<div class="field"><label for="fl-assigne">Assigné à</label><select id="fl-assigne" class="input">' + optAssigne + '</select></div>'
      : '') +
    '<div class="filtres-cases">' +
    (staff ? '<label class="check"><input type="checkbox" id="fl-ouverts"' + (f.open_only ? ' checked' : '') + '> Ouverts uniquement</label>' : '') +
    '<label class="check"><input type="checkbox" id="fl-nouveautes"' + (f.unread_only ? ' checked' : '') + '> Avec du nouveau</label>' +
    (staff ? '<label class="check"><input type="checkbox" id="fl-dormants"' + (f.stale_only ? ' checked' : '') + '> Sans réponse depuis ' + S.staleDays + ' j</label>' : '') +
    '<button type="button" class="btn" id="fl-raz">Réinitialiser</button>' +
    '</div></div>' +

    '<div id="liste">' + chargement() + '</div>';

  const relire = () => { f.page = 1; chargerListe(); };
  let minuteur = null;
  $('#fl-q').addEventListener('input', () => {
    f.q = $('#fl-q').value.trim();
    clearTimeout(minuteur); minuteur = setTimeout(relire, 350);
  });
  $('#fl-statut').addEventListener('change', () => {
    f.status = $('#fl-statut').value;
    // Demander « Résolu » ou « Fermé » alors que « Ouverts uniquement » est coché
    // ne peut donner qu'une liste vide : on décoche pour éviter l'incompréhension.
    if ((f.status === 'resolu' || f.status === 'ferme') && f.open_only) {
      f.open_only = false;
      const c = $('#fl-ouverts');
      if (c) c.checked = false;
    }
    relire();
  });
  $('#fl-nouveautes').addEventListener('change', () => { f.unread_only = $('#fl-nouveautes').checked; relire(); });
  if (staff) {
    $('#fl-prio').addEventListener('change', () => { f.priority = $('#fl-prio').value; relire(); });
    $('#fl-cat').addEventListener('change', () => { f.category = $('#fl-cat').value; relire(); });
    $('#fl-site').addEventListener('change', () => { f.site = $('#fl-site').value; relire(); });
    $('#fl-assigne').addEventListener('change', () => { f.assigned = $('#fl-assigne').value; relire(); });
    $('#fl-ouverts').addEventListener('change', () => { f.open_only = $('#fl-ouverts').checked; relire(); });
    $('#fl-dormants').addEventListener('change', () => { f.stale_only = $('#fl-dormants').checked; relire(); });
    $('#btn-export').addEventListener('click', () => {
      // L'export doit contenir exactement ce que l'écran affiche : on reprend
      // tous les filtres, y compris « assigné à » et « sans réponse ».
      const p = new URLSearchParams();
      ['status', 'priority', 'category', 'site', 'q', 'assigned'].forEach(k => { if (f[k]) p.set(k, f[k]); });
      if (f.open_only) p.set('open_only', '1');
      if (f.stale_only) p.set('stale_only', '1');
      if (f.unread_only) p.set('unread_only', '1');
      window.location.href = '' + D8_APP + '?action=export_csv&' + p.toString();
    });
  }
  $('#fl-raz').addEventListener('click', () => { S.filtres = filtresDefaut(); vueTickets(); });
  $('#btn-maj').addEventListener('click', chargerListe);

  chargerListe();
}

async function chargerListe() {
  const token = S.vueToken;
  const seq = ++listeSeq;   // deux filtres enchaînés : seul le dernier compte
  const f = S.filtres;
  const staff = estStaff();
  const zone = $('#liste');
  if (!zone) return;

  let d;
  try { d = await api('tickets_list', f); } catch (e) { return; }
  if (!encoreValide(token) || seq !== listeSeq || !$('#liste')) return;

  if (!d.rows.length) {
    const filtresActifs = f.q || f.status || f.priority || f.category || f.site ||
                          f.assigned || f.unread_only || f.stale_only || (staff && f.open_only);
    zone.innerHTML =
      '<div class="card vide">' +
      (filtresActifs
        ? '<p>Aucun ticket ne correspond à ces critères.</p>'
        : '<p>Aucune demande pour le moment.</p><a class="btn btn-primary" href="#/nouveau">Créer un ticket</a>') +
      '</div>';
    return;
  }

  const lignes = d.rows.map(r => {
    const dormant = staff && Number(r.age_jours) > d.stale_days &&
                    r.status !== 'resolu' && r.status !== 'ferme';
    return '<tr tabindex="0" data-id="' + r.id + '" class="' +
      (Number(r.unread) ? 'non-lu ' : '') + (dormant ? 'dormant' : '') + '">' +
    (staff ? '<td class="col-choix sans-label"><input type="checkbox" class="choix" data-id="' + r.id +
             '" aria-label="Sélectionner ' + esc(r.ref) + '"></td>' : '') +
    '<td class="t-ref sans-label">' + esc(r.ref) + '</td>' +
    '<td class="sans-label"><span class="t-titre">' + esc(r.title) + '</span>' +
    (staff ? '<span class="t-sub">' + esc(r.creator_name) + ' &nbsp;|&nbsp; ' + esc(r.category) + '</span>' : '') +
    '</td>' +
    '<td data-l="Statut">' + chipStatut(r.status) + '</td>' +
    (staff
      ? '<td data-l="Priorité">' + chipPrio(r.priority) + '</td>' +
        '<td data-l="Site">' + esc(r.site || '—') + '</td>' +
        '<td data-l="Assigné à">' + (r.assignee_name ? esc(r.assignee_name) : '<span class="role-tag">Non assigné</span>') + '</td>'
      : '') +
    '<td class="t-date" data-l="Mis à jour">' + fmtDate(r.updated_at) +
      (dormant ? '<span class="t-alerte">' + esc(depuis(r.age_jours)) + '</span>' : '') + '</td></tr>';
  }).join('');

  const th = (cle, lbl) => {
    const actif = d.sort === cle;
    const fleche = actif ? (d.dir === 'asc' ? ' ▲' : ' ▼') : '';
    return '<th><button type="button" class="tri' + (actif ? ' actif' : '') + '" data-tri="' + cle + '">' +
           esc(lbl) + fleche + '</button></th>';
  };
  const entetes = staff
    ? '<tr><th class="col-choix"><input type="checkbox" id="choix-tout" aria-label="Tout sélectionner"></th>' +
      th('ref', 'Réf') + th('title', 'Titre') + th('status', 'Statut') + th('priority', 'Priorité') +
      th('site', 'Site') + th('creator', 'Demandeur') + th('updated_at', 'Mis à jour') + '</tr>'
    : '<tr>' + th('ref', 'Réf') + th('title', 'Titre') + th('status', 'Statut') + th('updated_at', 'Mis à jour') + '</tr>';

  const nbPages = Math.max(1, Math.ceil(d.total / d.per_page));
  const pagination = nbPages > 1
    ? '<div class="pagination"><span>' + d.total + ' tickets, page ' + d.page + ' sur ' + nbPages + '</span>' +
      '<div class="pages">' +
      '<button type="button" class="btn" id="pg-prec"' + (d.page <= 1 ? ' disabled' : '') + '>Page précédente</button>' +
      '<button type="button" class="btn" id="pg-suiv"' + (d.page >= nbPages ? ' disabled' : '') + '>Page suivante</button>' +
      '</div></div>'
    : '<div class="pagination"><span>' + d.total + (d.total > 1 ? ' tickets' : ' ticket') + '</span></div>';

  zone.innerHTML =
    (staff ? '<div class="barre-lot hidden" id="barre-lot"></div>' : '') +
    '<div class="tbl-wrap"><table class="tbl"><thead>' + entetes + '</thead><tbody>' + lignes + '</tbody></table></div>' +
    pagination;

  if (staff) brancherSelection();
  brancherLignes('#liste');
  document.querySelectorAll('#liste .tri').forEach(b => {
    b.addEventListener('click', (e) => {
      e.stopPropagation();
      const cle = b.dataset.tri;
      f.dir = (f.sort === cle && f.dir === 'desc') ? 'asc' : 'desc';
      f.sort = cle;
      f.page = 1;
      chargerListe();
    });
  });
  const prec = $('#pg-prec'), suiv = $('#pg-suiv');
  if (prec) prec.addEventListener('click', () => { f.page--; chargerListe(); });
  if (suiv) suiv.addEventListener('click', () => { f.page++; chargerListe(); });
}

/* ================================================ fiche d'un ticket */

async function vueTicket(id) {
  const token = S.vueToken;
  const main = $('#main');
  main.innerHTML = chargement();

  let d;
  try {
    d = await api('ticket_get', { id });
  } catch (e) {
    if (encoreValide(token)) {
      main.innerHTML = '<div class="card vide"><p>Ce ticket est introuvable.</p>' +
        '<a class="btn" href="#/tickets">Retour à la liste</a></div>';
    }
    return;
  }
  if (!encoreValide(token)) return;
  const t = d.ticket;
  const staff = estStaff();
  S.unread = d.unread; majBadge();
  filAriane([staff ? 'Tickets' : 'Mes demandes', t.ref]);

  const pjTicket = d.attachments.filter(a => a.comment_id == null);
  const pjParCommentaire = {};
  d.attachments.forEach(a => {
    if (a.comment_id != null) (pjParCommentaire[a.comment_id] = pjParCommentaire[a.comment_id] || []).push(a);
  });

  const lienPj = (a) =>
    '<a class="pj" href="' + D8_APP + '?action=attachment_get&id=' + a.id + '" target="_blank" rel="noopener">' +
    ico('clip') + esc(a.orig_name) + ' <span class="pj-taille">(' + fmtSize(a.size) + ')</span></a>';

  /* Les captures d'écran s'affichent directement : dans neuf cas sur dix,
     l'image répond à la question sans qu'on ait besoin de l'ouvrir. */
  const blocPj = (liste) => {
    if (!liste.length) return '';
    const images = liste.filter(a => String(a.mime).startsWith('image/'));
    const vignettes = images.length
      ? '<div class="pj-vignettes">' + images.map(a =>
          '<a class="pj-vignette" href="' + D8_APP + '?action=attachment_get&id=' + a.id + '" ' +
          'target="_blank" rel="noopener" title="' + esc(a.orig_name) + '">' +
          '<img src="' + D8_APP + '?action=attachment_get&id=' + a.id + '" alt="' + esc(a.orig_name) + '" loading="lazy"></a>'
        ).join('') + '</div>'
      : '';
    return vignettes + '<div class="pj-liste">' + liste.map(lienPj).join('') + '</div>';
  };

  const cats = S.categories.includes(t.category) ? S.categories : [t.category].concat(S.categories);
  const sites = (t.site && !S.sites.includes(t.site)) ? [t.site].concat(S.sites) : S.sites;

  // Barre de traitement : les trois gestes quotidiens (statut, priorité,
  // responsable) sont directement accessibles ; le classement, plus rare,
  // se déplie à la demande. Cinq listes déroulantes empilées faisaient de
  // l'action la plus fréquente un parcours du regard.
  const carteTech = staff ?
    '<div class="barre-traitement no-print">' +
    '<div class="bt-champs">' +
    '<label class="bt-champ"><span>Statut</span><select id="tk-statut" class="input">' + optionsMap(STATUTS, t.status) + '</select></label>' +
    '<label class="bt-champ"><span>Priorité</span><select id="tk-prio" class="input">' + optionsMap(PRIORITES, t.priority) + '</select></label>' +
    '<label class="bt-champ"><span>Responsable</span><select id="tk-assigne" class="input">' +
      '<option value=""' + (!t.assigned_to ? ' selected' : '') + '>Non assigné</option>' +
      d.assignables.map(a => '<option value="' + a.id + '"' + (Number(t.assigned_to) === Number(a.id) ? ' selected' : '') + '>' + esc(a.name) + '</option>').join('') +
    '</select></label>' +
    '<label class="bt-champ bt-temps"><span>Temps passé</span>' +
    '<span class="bt-temps-ligne"><input id="tk-temps" class="input" type="number" min="0" max="1440" ' +
    'step="5" placeholder="0" inputmode="numeric"><span class="bt-unite">min</span></span></label>' +
    '<button type="button" class="btn btn-primary bt-valider" id="tk-save">Enregistrer</button>' +
    (!t.assigned_to ? '<button type="button" class="btn" id="tk-prendre">' + ico('hand') + 'Prendre en charge</button>' : '') +
    '</div>' +
    '<details class="bt-plus"><summary>Classement et suppression</summary>' +
    '<div class="bt-champs">' +
    '<label class="bt-champ"><span>Catégorie</span><select id="tk-cat" class="input">' + options(cats, t.category) + '</select></label>' +
    '<label class="bt-champ"><span>Site</span><select id="tk-site" class="input">' + options(sites, t.site, t.site ? null : '—') + '</select></label>' +
    (estAdmin() ? '<button type="button" class="btn btn-danger" id="tk-suppr">Supprimer le ticket</button>' : '') +
    '</div></details></div>'
    : '';

  let carteDemandeur = '';
  if (!staff) {
    if (t.status === 'resolu') {
      carteDemandeur =
        '<div class="card"><h2>Votre problème est-il réglé ?</h2>' +
        '<p>Le service informatique a marqué ce ticket comme résolu.</p>' +
        '<div class="page-actions" style="margin-top:.6rem">' +
        '<button type="button" class="btn btn-primary" id="tk-fermer">Oui, fermer le ticket</button>' +
        '<button type="button" class="btn" id="tk-rouvrir">Non, le problème persiste</button>' +
        '</div></div>';
    } else if (t.status === 'ferme') {
      carteDemandeur =
        '<div class="card"><p>Ce ticket est fermé. Si le problème revient, vous pouvez le rouvrir.</p>' +
        '<p style="margin-top:.6rem"><button type="button" class="btn" id="tk-rouvrir">Rouvrir ce ticket</button></p></div>';
    }
  }

  const fil = d.comments.map(c => {
    if (Number(c.is_system)) {
      return '<div class="msg-systeme"><span class="quoi">' + esc(c.body) + '</span> <span class="msg-date">' + fmtDate(c.created_at) + '</span></div>';
    }
    const pjs = (pjParCommentaire[c.id] || []).length;
    // Un fil de discussion doit se lire d'un coup d'œil : les messages du
    // service informatique sont décalés et teintés, ceux du demandeur restent
    // à gauche sur fond neutre. On voit qui parle sans lire les noms.
    const duSupport = c.user_role === 'admin';
    const categorie = Number(c.is_internal) ? 'interne' : (duSupport ? 'du-support' : 'du-demandeur');
    const etiquette = Number(c.is_internal) ? 'Note interne'
                    : (duSupport ? 'Service informatique' : 'Demandeur');
    return '<div class="msg ' + categorie + '">' +
      '<div class="msg-tete">' +
      '<span class="msg-origine">' + esc(etiquette) + '</span>' +
      '<span class="msg-auteur">' + esc(c.user_name) + '</span>' +
      '<span class="msg-date">' + fmtDate(c.created_at) + '</span></div>' +
      '<div class="msg-corps">' + esc(c.body) + '</div>' +
      (pjs ? blocPj(pjParCommentaire[c.id]) : '') +
      '</div>';
  }).join('');

  const modeles = (staff && d.templates.length)
    ? '<div class="field"><label for="msg-modele">Réponse type</label>' +
      '<select id="msg-modele" class="input"><option value="">Insérer une réponse type…</option>' +
      d.templates.map((m, i) => '<option value="' + i + '">' + esc(m.title) + '</option>').join('') +
      '</select></div>'
    : '';

  main.innerHTML =
    '<p class="no-print"><a class="btn btn-ghost" href="#/tickets">' + ico('retour') + 'Retour à la liste</a>' +
    '<button type="button" class="btn btn-ghost" id="tk-print">' + ico('print') + 'Imprimer</button></p>' +

    '<div class="card"><div class="ticket-entete"><div>' +
    '<span class="ticket-ref">' + esc(t.ref) + '</span>' +
    '<h1>' + esc(t.title) + '</h1>' +
    '<div class="chips">' + chipStatut(t.status) + chipPrio(t.priority) + '</div>' +
    '</div></div>' +
    '<div class="meta-grille">' +
    '<div class="meta-item"><div class="meta-lbl">Demandeur</div><div class="meta-val">' + esc(t.creator_name) + '</div>' +
    (staff ? '<div class="role-tag">' + esc(t.creator_email) +
             (t.creator_phone ? ' &nbsp;·&nbsp; ' + esc(t.creator_phone) : '') + '</div>' : '') + '</div>' +
    '<div class="meta-item"><div class="meta-lbl">Site</div><div class="meta-val">' + esc(t.site || '—') + '</div></div>' +
    '<div class="meta-item"><div class="meta-lbl">Catégorie</div><div class="meta-val">' + esc(t.category) + '</div></div>' +
    '<div class="meta-item"><div class="meta-lbl">Assigné à</div><div class="meta-val">' + (t.assignee_name ? esc(t.assignee_name) : 'Non assigné') + '</div></div>' +
    '<div class="meta-item"><div class="meta-lbl">Créé le</div><div class="meta-val">' + fmtDate(t.created_at) + '</div></div>' +
    (t.closed_at ? '<div class="meta-item"><div class="meta-lbl">Fermé le</div><div class="meta-val">' + fmtDate(t.closed_at) + '</div></div>' : '') +
    (staff && Number(t.time_spent) ? '<div class="meta-item"><div class="meta-lbl">Temps passé</div>' +
      '<div class="meta-val">' + esc(fmtDuree(Number(t.time_spent))) + '</div></div>' : '') +
    '</div></div>' +

    carteTech + carteDemandeur +

    '<div class="card"><h2>Description</h2>' +
    '<div class="description-bloc">' + esc(t.description) + '</div>' +
    blocPj(pjTicket) +
    '</div>' +

    '<div class="card"><h2>Échanges</h2>' +
    '<div class="fil">' + (fil || '<p class="sous-titre">Aucun échange pour le moment.</p>') + '</div>' +
    '<form id="f-msg" class="no-print" style="margin-top:1.1rem" novalidate>' +
    modeles +
    '<div class="field"><label for="msg-corps">Votre message</label>' +
    '<textarea id="msg-corps" class="input" rows="4" placeholder="Écrivez votre message ici…"></textarea>' +
    '<div class="aide">Astuce : Ctrl + Entrée envoie le message.</div></div>' +
    '<div class="field"><label for="msg-pj">Joindre des fichiers (facultatif)</label>' +
    '<input id="msg-pj" class="input" type="file" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.pdf">' +
    '<div class="aide">Images ou PDF. 3 fichiers maximum, 5 Mo chacun.</div></div>' +
    (staff ? '<div class="field"><label class="check"><input type="checkbox" id="msg-interne"> Note interne (invisible pour le demandeur)</label></div>' : '') +
    '<button class="btn btn-primary" type="submit">Envoyer le message</button>' +
    '</form></div>';

  $('#tk-print').addEventListener('click', () => window.print());

  // Le message en cours est conservé si on quitte la fiche par erreur.
  Brouillons.brancher($('#msg-corps'), 'ticket:' + id);
  // Ctrl+Entrée envoie, comme dans une messagerie.
  $('#msg-corps').addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
      e.preventDefault();
      $('#f-msg').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    }
  });
  // Les vignettes qui ne se chargent pas ne doivent pas laisser d'image cassée.
  document.querySelectorAll('.pj-vignette img').forEach(img => {
    img.addEventListener('error', () => { const p = img.parentElement; if (p) p.remove(); });
  });

  if (staff) {
    const prendre = $('#tk-prendre');
    if (prendre) prendre.addEventListener('click', async () => {
      prendre.disabled = true;
      try { await api('ticket_claim', { id }); toast('Ticket pris en charge.'); vueTicket(id); }
      catch (e) { prendre.disabled = false; }
    });
    $('#tk-save').addEventListener('click', async () => {
      const btn = $('#tk-save');
      btn.disabled = true;
      try {
        const r = await api('ticket_update', {
          id,
          version: t.updated_at,   // permet au serveur de détecter un conflit
          status: $('#tk-statut').value,
          priority: $('#tk-prio').value,
          assigned_to: $('#tk-assigne').value,
          category: $('#tk-cat').value,
          site: $('#tk-site').value,
          time_add: Number($('#tk-temps').value) || 0,
        });
        toast(r.changed ? 'Modifications enregistrées.' : 'Aucun changement à enregistrer.');
        if (r.changed) { Son.jouer('succes'); vueTicket(id); } else btn.disabled = false;
      } catch (e) {
        btn.disabled = false;
        // Conflit : on recharge pour montrer l'état réel avant de refaire.
        if (/modifié par quelqu/.test(String(e.message || ''))) setTimeout(() => vueTicket(id), 1200);
      }
    });
    const suppr = $('#tk-suppr');
    if (suppr) suppr.addEventListener('click', async () => {
      const okv = await modaleConfirm(
        'Supprimer définitivement le ticket ' + t.ref + ' ? Les messages et pièces jointes seront perdus.',
        { danger: true, ok: 'Supprimer' });
      if (!okv) return;
      try { await api('ticket_delete', { id }); toast('Ticket supprimé.'); location.hash = '#/tickets'; } catch (e) {}
    });
    const sel = $('#msg-modele');
    if (sel) sel.addEventListener('change', () => {
      // Sans ce test, choisir la ligne d'invite (valeur vide) insérait le
      // premier modèle, car Number('') vaut 0.
      if (sel.value === '') return;
      const m = d.templates[Number(sel.value)];
      if (!m) return;
      const zone = $('#msg-corps');
      zone.value = zone.value.trim() ? zone.value.trim() + '\n\n' + m.body : m.body;
      zone.focus();
      sel.value = '';
    });
  } else {
    const fermer = $('#tk-fermer');
    if (fermer) fermer.addEventListener('click', async () => {
      try { await api('ticket_close_own', { id }); Son.jouer('succes'); toast('Merci ! Ticket fermé.'); vueTicket(id); } catch (e) {}
    });
    const rouvrir = $('#tk-rouvrir');
    if (rouvrir) rouvrir.addEventListener('click', () => modaleRouvrir(id));
  }

  $('#f-msg').addEventListener('submit', async (e) => {
    e.preventDefault();
    const corps = $('#msg-corps').value.trim();
    if (!corps) { toast("Écrivez un message avant d'envoyer.", true); return; }
    if (corps.length > 20000) { toast('Le message est trop long. Mettez le détail en pièce jointe.', true); return; }
    const inp = $('#msg-pj');
    const fichiers = Array.from(inp.files || []);
    if (fichiers.length > 3) { toast('3 pièces jointes maximum.', true); return; }
    if (fichiers.some(f => f.size > 5 * 1048576)) { toast('Un fichier dépasse 5 Mo.', true); return; }
    const interne = staff && $('#msg-interne').checked ? 1 : '';
    const btn = e.target.querySelector('button[type="submit"]');
    btn.disabled = true; btn.textContent = 'Envoi…';
    try {
      const rep = await api('comment_add', { ticket_id: id, body: corps, is_internal: interne }, fichiers);
      Brouillons.vider('ticket:' + id);
      Son.jouer('succes');
      if (rep && rep.avertissement) toast(rep.avertissement, true);
      else if (rep && rep.rouvert) toast('Message envoyé — le ticket a été rouvert.');
      else toast('Message envoyé.');
      vueTicket(id);
    } catch (err) {
      btn.disabled = false; btn.textContent = 'Envoyer le message';
    }
  });
}

function modaleRouvrir(id) {
  const m = modale(
    '<h2>Rouvrir le ticket</h2>' +
    '<div class="field"><label for="mr-raison">Que se passe-t-il ? (facultatif)</label>' +
    '<textarea id="mr-raison" class="input" rows="4" placeholder="Le problème est revenu ce matin…"></textarea></div>' +
    '<div class="modal-actions">' +
    '<button type="button" class="btn" data-a="annuler">Annuler</button>' +
    '<button type="button" class="btn btn-primary" data-a="ok">Rouvrir le ticket</button></div>'
  );
  m.el.querySelector('[data-a="annuler"]').addEventListener('click', m.close);
  m.el.querySelector('[data-a="ok"]').addEventListener('click', async () => {
    const reason = m.el.querySelector('#mr-raison').value.trim();
    try {
      await api('ticket_reopen_own', { id, reason });
      m.close();
      toast('Ticket rouvert. Le service informatique est prévenu.');
      vueTicket(id);
    } catch (e) {}
  });
}

/* ================================================ nouveau ticket */

function vueNouveau() {
  const main = $('#main');
  const prio = Object.keys(PRIORITES).map(k =>
    '<label class="prio-card"><input type="radio" name="nt-prio" value="' + k + '"' + (k === 'normale' ? ' checked' : '') + '>' +
    '<span class="prio-nom">' + esc(PRIORITES[k].lbl) + '</span><br>' +
    '<span class="prio-desc">' + esc(PRIORITES[k].desc) + '</span></label>'
  ).join('');

  main.innerHTML =
    '<div class="page-head"><div><h1>Nouveau ticket</h1>' +
    '<p class="sous-titre">Décrivez votre problème, le service informatique prend le relais</p></div></div>' +

    '<div class="card"><form id="f-nouveau" novalidate>' +
    '<div class="field"><label for="nt-titre">Titre</label>' +
    '<input id="nt-titre" class="input" type="text" maxlength="150" placeholder="Exemple : l\'imprimante du quai ne répond plus">' +
    "<div class=\"aide\">En quelques mots, pour reconnaître le ticket d'un coup d'œil.</div></div>" +

    '<div class="field"><label for="nt-cat">Catégorie</label>' +
    '<select id="nt-cat" class="input">' + options(S.categories, '', 'Choisir une catégorie…') + '</select></div>' +

    '<div class="field"><label for="nt-site">Votre site</label>' +
    '<select id="nt-site" class="input">' + options(S.sites, S.sites.length === 1 ? S.sites[0] : '', S.sites.length === 1 ? null : 'Choisir un site…') + '</select></div>' +

    '<div class="field"><label>Priorité</label><div class="prio-grid">' + prio + '</div></div>' +

    '<div class="field"><label for="nt-desc">Description</label>' +
    '<textarea id="nt-desc" class="input" rows="6" placeholder="Que faisiez-vous ? Quel message s\'affiche ? Depuis quand ?"></textarea>' +
    '<div class="aide">Plus la description est précise, plus la résolution est rapide.</div></div>' +

    '<div class="field"><label for="nt-pj">Pièces jointes (facultatif)</label>' +
    '<input id="nt-pj" class="input" type="file" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.pdf">' +
    "<div class=\"aide\">Photos d'écran ou PDF. 3 fichiers maximum, 5 Mo chacun.</div>" +
    '<div class="fichiers-choisis" id="nt-pj-liste"></div></div>' +

    '<button class="btn btn-primary" type="submit">Créer le ticket</button>' +
    '</form></div>';

  Brouillons.brancher($('#nt-titre'), 'nouveau:titre');
  Brouillons.brancher($('#nt-desc'), 'nouveau:desc');

  // Le sélecteur CSS :has() manque encore à certains navigateurs d'entreprise
  // (Firefox ESR 115) : la carte choisie est marquée ici, en script.
  const majPrio = () => document.querySelectorAll('.prio-card').forEach(c => {
    c.classList.toggle('choisie', !!c.querySelector('input:checked'));
  });
  document.querySelectorAll('input[name="nt-prio"]').forEach(r => r.addEventListener('change', majPrio));
  majPrio();

  const inp = $('#nt-pj');
  inp.addEventListener('change', () => {
    if ((inp.files || []).length > 3) { toast('3 pièces jointes maximum.', true); inp.value = ''; }
    for (const f of Array.from(inp.files || [])) {
      if (f.size > 5 * 1048576) { toast('« ' + f.name + ' » dépasse 5 Mo.', true); inp.value = ''; break; }
    }
    const restants = Array.from(inp.files || []);
    $('#nt-pj-liste').textContent = restants.length
      ? restants.map(f => f.name + ' (' + fmtSize(f.size) + ')').join(', ') : '';
  });

  $('#f-nouveau').addEventListener('submit', async (e) => {
    e.preventDefault();
    const titre = $('#nt-titre').value.trim();
    const cat = $('#nt-cat').value;
    const site = $('#nt-site').value;
    const desc = $('#nt-desc').value.trim();
    const prioChoisie = (main.querySelector('input[name="nt-prio"]:checked') || {}).value || 'normale';

    if (titre.length < 5) { toast('Le titre est trop court : décrivez le problème en quelques mots.', true); $('#nt-titre').focus(); return; }
    if (!cat) { toast('Choisissez une catégorie.', true); $('#nt-cat').focus(); return; }
    if (S.sites.length && !site) { toast('Choisissez votre site.', true); $('#nt-site').focus(); return; }
    if (desc.length < 10) { toast('Décrivez le problème plus en détail.', true); $('#nt-desc').focus(); return; }
    if (desc.length > 20000) { toast('La description est trop longue. Mettez le détail en pièce jointe.', true); return; }

    const btn = e.target.querySelector('button[type="submit"]');
    btn.disabled = true; btn.textContent = 'Création…';
    try {
      const d = await api('ticket_create',
        { title: titre, description: desc, category: cat, site, priority: prioChoisie },
        Array.from(inp.files || []));
      Brouillons.vider('nouveau:titre');
      Brouillons.vider('nouveau:desc');
      Son.jouer('succes');
      if (d.avertissement) toast(d.avertissement, true);
      else toast('Ticket ' + d.ref + ' créé.');
      location.hash = '#/ticket/' + d.id;
    } catch (err) {
      btn.disabled = false; btn.textContent = 'Créer le ticket';
    }
  });
}

/* ================================================ utilisateurs (admin) */

async function vueUtilisateurs() {
  const token = S.vueToken;
  const main = $('#main');
  main.innerHTML =
    '<div class="page-head"><div><h1>Utilisateurs</h1>' +
    "<p class=\"sous-titre\">Comptes du personnel ayant accès à l'outil</p></div>" +
    '<div class="page-actions">' +
    '<button type="button" class="btn" id="btn-journal">Journal des connexions</button>' +
    '<button type="button" class="btn btn-primary" id="btn-ajout">' + ico('plus') + 'Ajouter un utilisateur</button></div></div>' +
    '<div id="zone-u">' + chargement() + '</div>';

  $('#btn-ajout').addEventListener('click', () => modaleUtilisateur(null));
  $('#btn-journal').addEventListener('click', modaleJournal);

  let rows;
  try { rows = await api('users_list'); } catch (e) { return; }
  if (!encoreValide(token)) return;

  const lignes = rows.map(u =>
    '<tr class="' + (Number(u.active) ? '' : 'u-inactif') + '">' +
    '<td class="sans-label"><span class="t-titre">' + esc(u.name) + '</span>' +
    (u.phone ? '<span class="t-sub">' + esc(u.phone) + '</span>' : '') + '</td>' +
    '<td data-l="Identifiant">' + esc(u.login || u.email) +
    (u.auth === 'annuaire' ? '<span class="t-sub">compte Windows</span>' : '') + '</td>' +
    '<td data-l="Rôle">' + esc(ROLES[u.role] || u.role) + '</td>' +
    '<td data-l="Tickets">' + u.ticket_count + '</td>' +
    '<td data-l="Dernière connexion">' + (u.last_login ? fmtDate(u.last_login) : '<span class="role-tag">jamais</span>') + '</td>' +
    '<td data-l="Compte">' + (Number(u.active) ? '<span class="chip st-resolu">Actif</span>' : '<span class="chip st-ferme">Désactivé</span>') + '</td>' +
    '<td class="sans-label"><button type="button" class="btn" data-id="' + u.id + '">Modifier</button></td>' +
    '</tr>'
  ).join('');

  $('#zone-u').innerHTML =
    '<div class="tbl-wrap"><table class="tbl">' +
    '<thead><tr><th>Nom</th><th>Email</th><th>Rôle</th><th>Tickets</th><th>Dernière connexion</th><th>Compte</th><th></th></tr></thead>' +
    '<tbody>' + lignes + '</tbody></table></div>' +
    '<p class="sous-titre" style="margin-top:.8rem">La colonne « Dernière connexion » aide à repérer les comptes ' +
    "d'anciens salariés qui n'ont jamais été désactivés.</p>";

  document.querySelectorAll('#zone-u button[data-id]').forEach(btn => {
    btn.addEventListener('click', () => modaleUtilisateur(rows.find(x => Number(x.id) === Number(btn.dataset.id))));
  });
}

function modaleUtilisateur(u) {
  const creation = !u;
  const optRoles = Object.keys(ROLES).map(k =>
    '<option value="' + k + '"' + (u && u.role === k ? ' selected' : '') + '>' + esc(ROLES[k]) + '</option>'
  ).join('');

  const m = modale(
    '<h2>' + (creation ? 'Ajouter un utilisateur' : 'Modifier ' + esc(u.name)) + '</h2>' +
    '<div class="field"><label for="mu-nom">Nom complet</label><input id="mu-nom" class="input" type="text" value="' + (u ? esc(u.name) : '') + '"></div>' +
    (u && u.auth === 'annuaire'
      ? '<div class="encart-code">Ce compte vient de l\'annuaire : identifiant <b>' + esc(u.login) + '</b>. ' +
        'Son mot de passe est celui de sa session Windows et se gère dans Active Directory. ' +
        'Vous pouvez ici changer son rôle ou désactiver son accès à l\'outil.</div>'
      : '') +
    '<div class="field"><label for="mu-email">Adresse email</label><input id="mu-email" class="input" type="email" value="' + (u ? esc(u.email) : '') + '"></div>' +
    '<div class="field"><label for="mu-tel">Téléphone ou poste (facultatif)</label><input id="mu-tel" class="input" type="text" value="' + (u ? esc(u.phone || '') : '') + '">' +
    '<div class="aide">Affiché sur ses tickets : permet de rappeler la personne sans chercher.</div></div>' +
    '<div class="field"><label for="mu-role">Rôle</label><select id="mu-role" class="input">' + optRoles + '</select>' +
    '<div class="aide">Employé : crée et suit ses propres demandes. Administrateur : voit et traite tous les tickets, gère les comptes et les réglages.</div></div>' +
    (u && u.auth === 'annuaire' ? '' :
    '<div class="field"><label for="mu-mdp">' + (creation ? 'Mot de passe (8 caractères minimum)' : 'Nouveau mot de passe') + '</label>' +
    '<div class="mdp-ligne"><input id="mu-mdp" class="input" type="text" autocomplete="new-password">' +
    '<button type="button" class="btn" id="mu-gen">Générer</button></div>' +
    (creation ? '<div class="aide">Notez-le : il devra être communiqué à la personne.</div>'
              : '<div class="aide">Laissez vide pour ne pas le changer. Les employés ne pouvant pas le modifier eux-mêmes, c\'est ici que vous le réinitialisez.</div>') + '</div>') +
    '<div class="field"><label class="check"><input type="checkbox" id="mu-actif"' + (creation || Number(u.active) ? ' checked' : '') + '> Compte actif</label>' +
    "<div class=\"aide\">Décochez pour bloquer l'accès (départ d'un salarié) sans perdre l'historique.</div></div>" +
    '<div class="modal-actions">' +
    '<button type="button" class="btn" data-a="annuler">Annuler</button>' +
    '<button type="button" class="btn btn-primary" data-a="ok">' + (creation ? 'Créer le compte' : 'Enregistrer') + '</button></div>'
  );

  // Mot de passe lisible et facile à dicter : deux mots + deux chiffres.
  const gen = m.el.querySelector('#mu-gen');
  if (gen) gen.addEventListener('click', () => {
    const mots = ['cafe', 'tasse', 'moulin', 'grain', 'arome', 'filtre', 'vapeur', 'sucre',
                  'tempo', 'orage', 'sable', 'pivoine', 'ruche', 'lampe', 'saison', 'velours'];
    const p = mots[Math.floor(Math.random() * mots.length)] + '-' +
              mots[Math.floor(Math.random() * mots.length)] + '-' +
              String(Math.floor(Math.random() * 90) + 10);
    m.el.querySelector('#mu-mdp').value = p;
  });

  m.el.querySelector('[data-a="annuler"]').addEventListener('click', m.close);
  m.el.querySelector('[data-a="ok"]').addEventListener('click', async () => {
    try {
      await api('user_save', {
        id: u ? u.id : 0,
        name: m.el.querySelector('#mu-nom').value.trim(),
        email: m.el.querySelector('#mu-email').value.trim(),
        phone: m.el.querySelector('#mu-tel').value.trim(),
        role: m.el.querySelector('#mu-role').value,
        active: m.el.querySelector('#mu-actif').checked ? 1 : '',
        password: m.el.querySelector('#mu-mdp') ? m.el.querySelector('#mu-mdp').value : '',
      });
      m.close();
      toast(creation ? 'Compte créé.' : 'Compte enregistré.');
      vueUtilisateurs();
    } catch (e) {}
  });
}

async function modaleJournal() {
  let rows;
  try { rows = await api('logins_list'); } catch (e) { return; }
  const lignes = rows.map(r =>
    '<tr><td>' + fmtDate(r.created_at) + '</td><td>' + esc(r.name || r.email) + '</td>' +
    '<td>' + (Number(r.success) ? '<span class="chip st-resolu">Réussie</span>' : '<span class="chip pr-critique">Échouée</span>') + '</td>' +
    '<td class="role-tag">' + esc(r.ip) + '</td></tr>').join('');
  const m = modale(
    '<h2>Journal des connexions</h2>' +
    '<p class="sous-titre">Les 100 dernières tentatives.</p>' +
    '<div class="tbl-wrap" style="max-height:52vh;overflow:auto"><table class="tbl">' +
    '<thead><tr><th>Date</th><th>Compte</th><th>Résultat</th><th>Poste</th></tr></thead>' +
    '<tbody>' + (lignes || '<tr><td colspan="4">Aucune connexion enregistrée.</td></tr>') + '</tbody></table></div>' +
    '<div class="modal-actions"><button type="button" class="btn" data-a="fermer">Fermer</button></div>'
  );
  m.el.querySelector('[data-a="fermer"]').addEventListener('click', m.close);
}

/* ================================================ réglages (admin) */

async function vueParametres() {
  const token = S.vueToken;
  const main = $('#main');
  main.innerHTML = chargement();
  let c;
  try { c = await api('settings_get'); } catch (e) { return; }
  if (!encoreValide(token)) return;

  const secure = (v) => ['tls', 'ssl', 'none'].map(k =>
    '<option value="' + k + '"' + (c.mail_secure === k ? ' selected' : '') + '>' +
    ({ tls: 'STARTTLS (port 587, le plus courant)', ssl: 'SSL (port 465)', none: 'Aucun chiffrement' })[k] + '</option>').join('');

  main.innerHTML =
    '<div class="page-head"><div><h1>Paramètres</h1>' +
    "<p class=\"sous-titre\">Identité, fonctionnement, messagerie et annuaire</p></div>" +
    '<div class="page-actions">' +
    '<a class="btn" href="' + D8_APP + '?page=verification">Vérifier l\'installation</a>' +
    '<a class="btn" href="' + D8_APP + '?action=backup">' + ico('save') +
    'Sauvegarde complète (base + pièces jointes)</a></div></div>' +

    '<form id="f-reglages" novalidate>' +

    '<div class="card"><h2>Identité de l\'outil</h2>' +
    '<div class="deux-col">' +
    '<div class="field"><label for="rg-nom">Nom affiché</label>' +
    '<input id="rg-nom" class="input" type="text" value="' + esc(c.app_name) + '">' +
    '<div class="aide">Apparaît dans le menu, sur l\'écran de connexion et dans les emails.</div></div>' +
    '<div class="field"><label for="rg-prefixe">Préfixe des références</label>' +
    '<input id="rg-prefixe" class="input" type="text" maxlength="6" value="' + esc(c.ref_prefix) + '">' +
    '<div class="aide">Les tickets seront numérotés ' + esc(c.ref_prefix) + '-0001, ' + esc(c.ref_prefix) + '-0002… ' +
    'Les tickets déjà créés gardent leur référence.</div></div>' +
    '</div></div>' +

    '<div class="card"><h2>Fonctionnement</h2><div class="deux-col">' +
    '<div class="field"><label for="rg-dormant">Signaler un ticket sans réponse après (jours)</label>' +
    '<input id="rg-dormant" class="input" type="number" min="1" max="60" value="' + esc(c.stale_days) + '"></div>' +
    '<div class="field"><label for="rg-autoclose">Fermer un ticket résolu après (jours)</label>' +
    '<input id="rg-autoclose" class="input" type="number" min="0" max="90" value="' + esc(c.auto_close_days) + '">' +
    '<div class="aide">0 = ne jamais fermer automatiquement.</div></div>' +
    '<div class="field"><label for="rg-idle">Déconnexion après inactivité (minutes)</label>' +
    '<input id="rg-idle" class="input" type="number" min="0" max="480" value="' + esc(c.idle_minutes) + '">' +
    '<div class="aide">0 = jamais. Utile si des postes partagés (atelier, quai) restent ouverts ' +
    'sans surveillance ; inutilement pénible sur des postes individuels.</div></div>' +
    '<div class="field"><label for="rg-purge">Supprimer les tickets fermés après (mois)</label>' +
    '<input id="rg-purge" class="input" type="number" min="0" max="120" value="' + esc(c.purge_months) + '">' +
    '<div class="aide"><b>0 = ne jamais supprimer.</b> Au-delà de zéro, les tickets fermés depuis ' +
    'plus longtemps sont effacés définitivement, avec leurs messages et leurs pièces jointes. ' +
    'Utile pour ne pas conserver indéfiniment des noms, des adresses et des captures d\'écran. ' +
    'La suppression est irréversible : gardez une sauvegarde.</div></div>' +
    '</div>' +
    '<div class="field"><label class="check"><input type="checkbox" id="rg-mdp"' + (c.allow_user_password === '1' ? ' checked' : '') + '> ' +
    'Autoriser les employés à changer leur mot de passe</label>' +
    "<div class=\"aide\">Décoché : seul l'administrateur réinitialise les mots de passe depuis l'écran Utilisateurs.</div></div></div>" +

    '<div class="card"><h2>Connexion par l\'annuaire de l\'entreprise</h2>' +
    (c.ldap_disponible
      ? '<p class="sous-titre">Chacun se connecte avec son identifiant et son mot de passe Windows. ' +
        'Le compte se crée tout seul à la première connexion, avec le nom et le site lus dans l\'annuaire. ' +
        'Votre compte administrateur reste local : si l\'annuaire tombe, l\'outil reste administrable.</p>' +
        '<div class="field"><label class="check"><input type="checkbox" id="rg-ldap"' +
        (c.ldap_enabled === '1' ? ' checked' : '') + '> Activer la connexion par l\'annuaire</label></div>' +
        '<div class="deux-col">' +
        '<div class="field"><label for="rg-lhost">Contrôleur de domaine</label>' +
        '<input id="rg-lhost" class="input" type="text" placeholder="ad-oncloud.mon-domaine.local" value="' + esc(c.ldap_host) + '"></div>' +
        '<div class="field"><label for="rg-lport">Port</label>' +
        '<input id="rg-lport" class="input" type="number" value="' + esc(c.ldap_port) + '">' +
        '<div class="aide">389 sans chiffrement ou avec STARTTLS, 636 en SSL.</div></div>' +
        '<div class="field"><label for="rg-lsec">Chiffrement</label><select id="rg-lsec" class="input">' +
        ['none', 'starttls', 'ssl'].map(k => '<option value="' + k + '"' + (c.ldap_secure === k ? ' selected' : '') + '>' +
          ({ none: 'Aucun (réseau interne)', starttls: 'STARTTLS', ssl: 'SSL (LDAPS)' })[k] + '</option>').join('') +
        '</select></div>' +
        '<div class="field"><label for="rg-lfmt">Format d\'identifiant</label>' +
        '<input id="rg-lfmt" class="input" type="text" placeholder="%s@mon-domaine.local" value="' + esc(c.ldap_bind_format) + '">' +
        '<div class="aide"><code>%s</code> est remplacé par ce que tape la personne. Avec Active Directory, ' +
        '<code>%s@mon-domaine.local</code> fonctionne dans la quasi-totalité des cas ; ' +
        '<code>MONDOMAINE\\%s</code> est l\'autre forme acceptée.</div></div>' +
        '<div class="field"><label for="rg-lbase">Où chercher les fiches</label>' +
        '<input id="rg-lbase" class="input" type="text" placeholder="OU=Mes-Utilisateurs,DC=mon-domaine,DC=local" value="' + esc(c.ldap_base_dn) + '">' +
        '<div class="aide">Sert à reprendre le nom, l\'adresse et le site. Le site est déduit de ' +
        'l\'unité d\'organisation qui contient la fiche.</div></div>' +
        '<div class="field"><label for="rg-lattr">Attribut d\'identifiant</label>' +
        '<input id="rg-lattr" class="input" type="text" value="' + esc(c.ldap_login_attr) + '">' +
        '<div class="aide">userPrincipalName avec Active Directory, uid avec OpenLDAP.</div></div>' +
        '</div>' +
        '<div class="field"><label class="check"><input type="checkbox" id="rg-lauto"' +
        (c.ldap_autocreate === '1' ? ' checked' : '') + '> Créer le compte automatiquement à la première connexion</label>' +
        '<div class="aide">Décoché : seules les personnes dont vous avez créé le compte ici peuvent entrer.</div></div>' +
        '<p class="sous-titre">Enregistrez avant de tester, puis essayez avec un vrai compte du domaine.</p>' +
        '<div class="deux-col">' +
        '<div class="field"><label for="rg-ltest-l">Identifiant de test</label><input id="rg-ltest-l" class="input" type="text" autocomplete="off"></div>' +
        '<div class="field"><label for="rg-ltest-p">Mot de passe de test</label><input id="rg-ltest-p" class="input" type="password" autocomplete="new-password"></div>' +
        '</div><button type="button" class="btn" id="rg-ltest">Tester la connexion à l\'annuaire</button>' +
        '<pre class="trace-ldap hidden" id="rg-ltrace"></pre>'
      : '<p class="sous-titre">L\'extension LDAP de PHP n\'est pas activée sur ce serveur. ' +
        'Pour utiliser les comptes Windows, ouvrez php.ini, retirez le point-virgule devant ' +
        '<code>extension=ldap</code> puis redémarrez le serveur web (Apache, ou IIS : commande ' +
        '<code>iisreset</code>).</p>') +
    '</div>' +

    '<div class="card"><h2>Notifications par email</h2>' +
    '<div class="field"><label class="check"><input type="checkbox" id="rg-mail"' + (c.mail_enabled === '1' ? ' checked' : '') + '> ' +
    'Envoyer des emails (nouveau ticket, réponse, résolution)</label></div>' +
    '<div class="deux-col">' +
    '<div class="field"><label for="rg-host">Serveur SMTP</label><input id="rg-host" class="input" type="text" placeholder="smtp.office365.com" value="' + esc(c.mail_host) + '"></div>' +
    '<div class="field"><label for="rg-port">Port</label><input id="rg-port" class="input" type="number" value="' + esc(c.mail_port) + '"></div>' +
    '<div class="field"><label for="rg-secure">Chiffrement</label><select id="rg-secure" class="input">' + secure() + '</select></div>' +
    '<div class="field"><label for="rg-user">Identifiant</label><input id="rg-user" class="input" type="text" autocomplete="off" value="' + esc(c.mail_user) + '"></div>' +
    '<div class="field"><label for="rg-pass">Mot de passe</label><input id="rg-pass" class="input" type="password" autocomplete="new-password" value="' + esc(c.mail_pass) + '">' +
    '<div class="aide">Laissez tel quel pour ne pas le changer.</div></div>' +
    '<div class="field"><label for="rg-from">Adresse d\'expédition</label><input id="rg-from" class="input" type="email" value="' + esc(c.mail_from) + '"></div>' +
    '<div class="field"><label for="rg-fromname">Nom de l\'expéditeur</label><input id="rg-fromname" class="input" type="text" value="' + esc(c.mail_from_name) + '"></div>' +
    '<div class="field"><label for="rg-url">Adresse de l\'outil</label><input id="rg-url" class="input" type="text" placeholder="http://support/" value="' + esc(c.base_url) + '">' +
    '<div class="aide">Ajoutée en bas des emails pour que les gens cliquent directement.</div></div>' +
    '</div>' +
    '<p class="sous-titre">Enregistrez avant de tester.</p>' +
    '<button type="button" class="btn" id="rg-test">Envoyer un email de test</button></div>' +

    '<button class="btn btn-primary" type="submit">Enregistrer les réglages</button>' +
    '</form>';

  const btnLdap = $('#rg-ltest');
  if (btnLdap) btnLdap.addEventListener('click', async () => {
    const zone = $('#rg-ltrace');
    btnLdap.disabled = true; btnLdap.textContent = 'Test en cours…';
    try {
      const r = await api('ldap_test', { login: $('#rg-ltest-l').value.trim(),
                                         password: $('#rg-ltest-p').value });
      zone.classList.remove('hidden');
      zone.textContent = r.trace + (r.reussi
        ? '\nRésultat : identité confirmée.\n  Nom : ' + (r.nom || '(non trouvé)') +
          '\n  Adresse : ' + (r.email || '(non trouvée)') +
          '\n  Site déduit : ' + (r.site || '(non déduit)')
        : '\nRésultat : échec — ' + r.erreur);
      toast(r.reussi ? 'Annuaire joignable, identité confirmée.' : 'Échec : ' + r.erreur, !r.reussi);
    } catch (e) {} finally {
      btnLdap.disabled = false; btnLdap.textContent = 'Tester la connexion à l\'annuaire';
    }
  });

  $('#rg-test').addEventListener('click', async () => {
    const btn = $('#rg-test');
    btn.disabled = true; btn.textContent = 'Envoi…';
    try {
      await api('mail_test', { to: S.user.email });
      toast('Email de test envoyé à ' + S.user.email + '. Vérifiez votre boîte.');
    } catch (e) {} finally {
      btn.disabled = false; btn.textContent = 'Envoyer un email de test';
    }
  });

  $('#f-reglages').addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      const d = await api('settings_save', {
        app_name: $('#rg-nom').value.trim(),
        ref_prefix: $('#rg-prefixe').value.trim(),
        stale_days: $('#rg-dormant').value,
        auto_close_days: $('#rg-autoclose').value,
        idle_minutes: $('#rg-idle').value,
        purge_months: $('#rg-purge').value,
        allow_user_password: $('#rg-mdp').checked ? '1' : '0',
        mail_enabled: $('#rg-mail').checked ? '1' : '0',
        mail_host: $('#rg-host').value.trim(),
        mail_port: $('#rg-port').value,
        mail_secure: $('#rg-secure').value,
        mail_user: $('#rg-user').value.trim(),
        mail_pass: $('#rg-pass').value,
        mail_from: $('#rg-from').value.trim(),
        mail_from_name: $('#rg-fromname').value.trim(),
        base_url: $('#rg-url').value.trim(),
        ldap_enabled: $('#rg-ldap') ? ($('#rg-ldap').checked ? '1' : '0') : undefined,
        ldap_host: $('#rg-lhost') ? $('#rg-lhost').value.trim() : undefined,
        ldap_port: $('#rg-lport') ? $('#rg-lport').value : undefined,
        ldap_secure: $('#rg-lsec') ? $('#rg-lsec').value : undefined,
        ldap_bind_format: $('#rg-lfmt') ? $('#rg-lfmt').value.trim() : undefined,
        ldap_base_dn: $('#rg-lbase') ? $('#rg-lbase').value.trim() : undefined,
        ldap_login_attr: $('#rg-lattr') ? $('#rg-lattr').value.trim() : undefined,
        ldap_autocreate: $('#rg-lauto') ? ($('#rg-lauto').checked ? '1' : '0') : undefined,
      });
      S.categories = d.categories;
      S.sites = d.sites;
      S.appName = d.app_name;
      // Certains réglages changent ce que l'on a le droit de faire (par
      // exemple le mot de passe des employés) : on relit tout et on
      // reconstruit le menu, au lieu d'attendre un rechargement de page.
      try {
        appliquerBoot(await api('boot'));
        buildNav();
        buildFooter();
      } catch (e) { /* l'enregistrement a réussi, seul l'affichage attendra */ }
      document.querySelectorAll('.brand-name, .tt-app').forEach(e => { e.textContent = S.appName; });
      majBadge();
      Son.jouer('succes');
      toast('Réglages enregistrés.');
    } catch (err) {}
  });
}


/* ================================================ listes */

async function vueListes() {
  const token = S.vueToken;
  const main = $('#main');
  main.innerHTML = chargement();
  let c;
  try { c = await api('settings_get'); } catch (e) { return; }
  if (!encoreValide(token)) return;

  main.innerHTML =
    '<div class="page-head"><div><h1>Listes</h1>' +
    '<p class="sous-titre">Ce que le personnel voit dans les menus déroulants du formulaire</p></div></div>' +
    '<form id="f-listes" novalidate>' +
    '<div class="card"><h2>Catégories de tickets</h2>' +
    '<div class="field"><label for="li-cat">Une par ligne</label>' +
    '<textarea id="li-cat" class="input" rows="10">' + esc((c.categories || []).join('\n')) + '</textarea>' +
    '<div class="aide">Retirer une catégorie n\'affecte pas les tickets qui l\'utilisent : ils la conservent ' +
    'et restent retrouvables.</div></div></div>' +
    '<div class="card"><h2>Sites et agences</h2>' +
    '<div class="field"><label for="li-sites">Un par ligne</label>' +
    '<textarea id="li-sites" class="input" rows="5">' + esc((c.sites || []).join('\n')) + '</textarea>' +
    '<div class="aide">Si la connexion par l\'annuaire est active, le site de chacun est déduit ' +
    'automatiquement de son unité d\'organisation : les noms doivent donc se ressembler.</div></div></div>' +
    '<button class="btn btn-primary" type="submit">Enregistrer les listes</button>' +
    '</form>';

  $('#f-listes').addEventListener('submit', async (e) => {
    e.preventDefault();
    const lister = (v) => v.split('\n').map(x => x.trim()).filter(x => x !== '');
    try {
      const d = await api('settings_save', {
        categories: lister($('#li-cat').value),
        sites: lister($('#li-sites').value),
      });
      S.categories = d.categories;
      S.sites = d.sites;
      Son.jouer('succes');
      toast('Listes enregistrées.');
    } catch (err) {}
  });
}

/* ================================================ procédures */

async function vueProcedures() {
  const token = S.vueToken;
  const staff = estStaff();
  const main = $('#main');
  main.innerHTML = chargement();

  let fiches;
  try { fiches = await api('procedures_list'); } catch (e) { return; }
  if (!encoreValide(token)) return;

  const groupes = {};
  fiches.filter(f => staff || Number(f.public)).forEach(f => {
    const g = f.category || 'Général';
    (groupes[g] = groupes[g] || []).push(f);
  });

  const carteFiche = (f) =>
    '<article class="fiche' + (Number(f.public) ? '' : ' fiche-interne') + '">' +
    '<div class="fiche-tete"><h3>' + esc(f.title) + '</h3>' +
    '<div class="fiche-tags">' +
    (Number(f.public) ? '<span class="chip st-resolu">Visible du personnel</span>'
                      : '<span class="chip st-ferme">Interne</span>') +
    (Number(f.modele) ? '<span class="chip st-nouveau">Réponse type</span>' : '') +
    '</div></div>' +
    '<div class="fiche-corps">' + esc(f.body) + '</div>' +
    (staff ? '<div class="fiche-actions">' +
      '<button type="button" class="btn" data-mod="' + f.id + '">Modifier</button>' +
      '<button type="button" class="btn btn-danger" data-supp="' + f.id + '">Supprimer</button>' +
      '</div>' : '') +
    '</article>';

  const corps = Object.keys(groupes).sort().map(g =>
    '<div class="card"><h2>' + esc(g) + '</h2>' + groupes[g].map(carteFiche).join('') + '</div>'
  ).join('');

  main.innerHTML =
    '<div class="page-head"><div><h1>' + (staff ? 'Procédures' : 'Aide et procédures') + '</h1>' +
    '<p class="sous-titre">' + (staff
      ? 'Les fiches servent deux fois : consultables par le personnel, et insérables en un clic dans une réponse.'
      : 'Les gestes simples à essayer avant d\'ouvrir un ticket.') + '</p></div>' +
    (staff ? '<div class="page-actions"><button type="button" class="btn btn-primary" id="fiche-new">' +
      ico('plus') + 'Nouvelle fiche</button></div>' : '') + '</div>' +
    (corps || '<div class="card vide"><p>Aucune fiche pour le moment.</p></div>');

  if (staff) {
    $('#fiche-new').addEventListener('click', () => modaleFiche(null));
    document.querySelectorAll('[data-mod]').forEach(b => b.addEventListener('click',
      () => modaleFiche(fiches.find(f => String(f.id) === b.dataset.mod))));
    document.querySelectorAll('[data-supp]').forEach(b => b.addEventListener('click', async () => {
      const f = fiches.find(x => String(x.id) === b.dataset.supp);
      if (!await modaleConfirm('Supprimer la fiche « ' + f.title + ' » ?', { danger: true, ok: 'Supprimer' })) return;
      try { await api('procedure_delete', { id: f.id }); toast('Fiche supprimée.'); vueProcedures(); } catch (e) {}
    }));
  }
}

function modaleFiche(f) {
  const cats = S.categories.slice();
  const m = modale(
    '<h2>' + (f ? 'Modifier la fiche' : 'Nouvelle fiche') + '</h2>' +
    '<div class="field"><label for="fi-titre">Titre</label>' +
    '<input id="fi-titre" class="input" type="text" value="' + (f ? esc(f.title) : '') + '"></div>' +
    '<div class="field"><label for="fi-cat">Rubrique</label>' +
    '<select id="fi-cat" class="input"><option value="">Général</option>' +
    cats.map(c => '<option value="' + esc(c) + '"' + (f && f.category === c ? ' selected' : '') + '>' + esc(c) + '</option>').join('') +
    '</select></div>' +
    '<div class="field"><label for="fi-corps">Contenu</label>' +
    '<textarea id="fi-corps" class="input" rows="10">' + (f ? esc(f.body) : '') + '</textarea></div>' +
    '<div class="field"><label class="check"><input type="checkbox" id="fi-public"' +
    (!f || Number(f.public) ? ' checked' : '') + '> Visible par le personnel dans « Aide »</label></div>' +
    '<div class="field"><label class="check"><input type="checkbox" id="fi-modele"' +
    (f && Number(f.modele) ? ' checked' : '') + '> Proposée comme réponse type dans les tickets</label>' +
    '<div class="aide">Une fiche peut être les deux : la même explication sert au personnel et à vos réponses.</div></div>' +
    '<div class="modal-actions"><button type="button" class="btn" data-a="annuler">Annuler</button>' +
    '<button type="button" class="btn btn-primary" data-a="ok">Enregistrer</button></div>'
  );
  m.el.querySelector('[data-a="annuler"]').addEventListener('click', m.close);
  m.el.querySelector('[data-a="ok"]').addEventListener('click', async () => {
    try {
      await api('procedure_save', {
        id: f ? f.id : 0,
        title: m.el.querySelector('#fi-titre').value.trim(),
        category: m.el.querySelector('#fi-cat').value,
        body: m.el.querySelector('#fi-corps').value.trim(),
        public: m.el.querySelector('#fi-public').checked ? 1 : '',
        modele: m.el.querySelector('#fi-modele').checked ? 1 : '',
      });
      m.close(); toast('Fiche enregistrée.'); vueProcedures();
    } catch (e) {}
  });
}

/* ================================================ journaux */

async function vueJournaux() {
  const token = S.vueToken;
  const main = $('#main');
  main.innerHTML = chargement();
  let connexions, erreurs;
  try {
    connexions = await api('logins_list');
    erreurs = await api('logs_errors');
  } catch (e) { return; }
  if (!encoreValide(token)) return;

  const lignesCo = connexions.map(r =>
    '<tr><td class="t-date">' + fmtDate(r.created_at) + '</td>' +
    '<td>' + esc(r.name || r.email) + '</td>' +
    '<td>' + (Number(r.success) ? '<span class="chip st-resolu">Réussie</span>'
                                : '<span class="chip pr-critique">Échouée</span>') + '</td>' +
    '<td class="role-tag">' + esc(r.ip) + '</td></tr>').join('');

  const lignesErr = (erreurs.lignes || []).map(l =>
    '<div class="log-ligne">' + esc(l) + '</div>').join('');

  main.innerHTML =
    '<div class="page-head"><div><h1>Journaux</h1>' +
    '<p class="sous-titre">Ce qu\'il faut regarder en premier quand quelque chose cloche.</p></div>' +
    '<div class="page-actions"><button type="button" class="btn" id="btn-maj">' + ico('maj') + 'Actualiser</button></div></div>' +

    '<div class="card"><h2>Incidents techniques</h2>' +
    (lignesErr
      ? '<p class="sous-titre">Les 200 derniers, du plus récent au plus ancien. ' +
        'Fichier : data/.ht_erreurs.log (' + fmtSize(erreurs.taille) + ').</p>' +
        '<div class="log-bloc">' + lignesErr + '</div>'
      : '<p class="sous-titre">Aucun incident enregistré. C\'est la situation normale.</p>') + '</div>' +

    '<div class="card"><h2>Connexions</h2>' +
    '<p class="sous-titre">Les 100 dernières tentatives. Une rafale d\'échecs depuis un même poste ' +
    'mérite un coup d\'œil.</p>' +
    '<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Date</th><th>Compte</th>' +
    '<th>Résultat</th><th>Poste</th></tr></thead><tbody>' +
    (lignesCo || '<tr><td colspan="4">Aucune connexion enregistrée.</td></tr>') +
    '</tbody></table></div></div>';
  $('#btn-maj').addEventListener('click', vueJournaux);
}

/* ================================================ informations */

async function vueInformations() {
  const token = S.vueToken;
  const main = $('#main');
  main.innerHTML = chargement();
  let d;
  try { d = await api('system_info'); } catch (e) { return; }
  if (!encoreValide(token)) return;

  const oui = (v) => v ? '<span class="chip st-resolu">Oui</span>' : '<span class="chip st-ferme">Non</span>';
  const ligne = (lbl, val) => '<div class="info-ligne"><div class="info-lbl">' + esc(lbl) + '</div>' +
                              '<div class="info-val">' + val + '</div></div>';

  main.innerHTML =
    '<div class="page-head"><div><h1>Informations</h1>' +
    '<p class="sous-titre">L\'état de l\'installation, à copier tel quel si vous devez décrire ' +
    'l\'environnement à quelqu\'un.</p></div>' +
    '<div class="page-actions"><a class="btn" href="#/affiche">Affiche pour le personnel</a>' +
    '<a class="btn" href="' + D8_APP + '?page=verification">Vérifier l\'installation</a>' +
    '<button type="button" class="btn" id="btn-print">' + ico('print') + 'Imprimer</button></div></div>' +

    '<div class="card"><h2>Contenu</h2>' +
    ligne('Tickets', d.nb_tickets + (d.premier ? ' — depuis le ' + fmtDate(d.premier).slice(0, 10) : '')) +
    ligne('Messages échangés', d.nb_messages) +
    ligne('Comptes', d.nb_actifs + ' actifs sur ' + d.nb_comptes) +
    ligne('Pièces jointes', d.nb_pj + ' fichiers, ' + fmtSize(d.taille_pj)) +
    ligne('Base de données', fmtSize(d.taille_base) +
      ' <button type="button" class="btn btn-ghost btn-mini" id="btn-compact">Compacter</button>') +
    (d.disque_libre != null ? ligne('Espace disque libre', fmtSize(d.disque_libre)) : '') + '</div>' +

    '<div class="card"><h2>Options actives</h2>' +
    ligne('Connexion par l\'annuaire', oui(d.annuaire)) +
    ligne('Notifications par email', oui(d.mail)) +
    ligne('Connexion chiffrée (HTTPS)', oui(d.https)) + '</div>' +

    '<div class="card"><h2>Serveur</h2>' +
    ligne('Application', esc(d.app_name)) +
    ligne('PHP', esc(d.php)) +
    ligne('Serveur web', esc(d.serveur)) +
    ligne('SQLite', esc(d.sqlite)) +
    ligne('Fuseau horaire', esc(d.fuseau) + ' — il est ' + esc(d.heure)) +
    ligne('Taille d\'envoi maximale', esc(d.upload_max) + ' par fichier, ' + esc(d.post_max) + ' au total') +
    ligne('Extensions', Object.keys(d.extensions).map(k =>
      '<span class="chip ' + (d.extensions[k] ? 'st-resolu' : 'st-ferme') + '">' + esc(k) + '</span>'
    ).join(' ')) + '</div>';
  $('#btn-print').addEventListener('click', () => window.print());
  $('#btn-compact').addEventListener('click', async () => {
    const b = $('#btn-compact');
    b.disabled = true; b.textContent = 'Compactage…';
    try {
      const r = await api('db_optimize');
      toast(r.gagne > 0 ? fmtSize(r.gagne) + ' libérés.' : 'La base était déjà compacte.');
      vueInformations();
    } catch (e) { b.disabled = false; b.textContent = 'Compacter'; }
  });
}

/* ================================================ affiche pour le personnel */

function vueAffiche() {
  // Une feuille A4 à imprimer et à afficher : l'adoption d'un outil interne
  // tient plus à ce qu'on sache qu'il existe qu'à ses fonctions.
  const adresse = (location.origin && location.origin !== 'null'
    ? location.origin + location.pathname.replace(/index\.php$/, '')
    : 'http://votre-serveur/').replace(/\/$/, '') + '/';
  $('#main').innerHTML =
    '<div class="page-head no-print"><div><h1>Affiche pour le personnel</h1>' +
    '<p class="sous-titre">À imprimer et à poser près des postes, ou à envoyer par email. ' +
    'L\'adresse ci-dessous est celle par laquelle vous consultez l\'outil.</p></div>' +
    '<div class="page-actions"><button type="button" class="btn btn-primary" id="aff-print">' +
    ico('print') + 'Imprimer</button>' +
    '<a class="btn" href="#/informations">Retour</a></div></div>' +

    '<div class="affiche" id="affiche">' +
    '<div class="affiche-marque">' + marqueHTML(S.appName, 'brand-mark brand-mark-grand') +
    '<div><div class="affiche-titre">' + esc(S.appName) + '</div>' +
    '<div class="affiche-sous">Un souci informatique ? Voici où le signaler.</div></div></div>' +

    '<div class="affiche-adresse"><span>Depuis votre navigateur, allez sur</span><b>' + esc(adresse) + '</b></div>' +

    '<ol class="affiche-etapes">' +
    '<li><b>Connectez-vous</b> avec ' + (S.annuaire
      ? 'vos identifiants Windows habituels, les mêmes que pour ouvrir votre session.'
      : 'l\'adresse email et le mot de passe fournis par le service informatique.') + '</li>' +
    '<li><b>Cliquez sur « Nouveau ticket »</b> et décrivez le problème : ce que vous faisiez, ' +
    'ce qui s\'affiche, depuis quand. Une photo de l\'écran aide énormément.</li>' +
    '<li><b>Suivez la réponse</b> dans « Mes demandes ». Vous êtes prévenu à chaque message.</li>' +
    '<li><b>Confirmez quand c\'est réglé</b>, ou dites-le si le problème revient.</li>' +
    '</ol>' +

    '<div class="affiche-conseil"><b>Avant d\'ouvrir un ticket</b>, jetez un œil à la rubrique ' +
    '« Aide et procédures » : les pannes les plus courantes s\'y règlent en deux minutes.</div>' +

    '<div class="affiche-pied">Un ticket écrit vaut mieux qu\'un mot dans le couloir : ' +
    'il n\'est jamais oublié, et vous savez où il en est.</div>' +
    '</div>';
  $('#aff-print').addEventListener('click', () => window.print());
}

/* ================================================ modales génériques */

function modale(html) {
  const root = $('#modal-root');
  const avant = document.activeElement;
  root.innerHTML = '<div class="modal-fond"><div class="modal" role="dialog" aria-modal="true">' + html + '</div></div>';
  const fond = root.firstElementChild;
  const boite = fond.firstElementChild;
  // On ne se fie pas à la mise en page pour repérer les champs atteignables :
  // offsetParent vaut null dans plusieurs contextes légitimes et le clavier
  // se retrouvait alors sans point d'entrée.
  const focusables = () => Array.from(boite.querySelectorAll(
    'input:not([disabled]):not([type=hidden]), textarea:not([disabled]), ' +
    'select:not([disabled]), button:not([disabled]), a[href]'
  )).filter(el => !el.closest('.hidden'));

  // Le clavier reste enfermé dans la fenêtre tant qu'elle est ouverte,
  // et retourne à son point de départ à la fermeture (navigation clavier).
  const onKey = (e) => {
    if (e.key === 'Escape') { close(); return; }
    if (e.key !== 'Tab') return;
    const liste = focusables();
    if (!liste.length) return;
    const premier = liste[0], dernier = liste[liste.length - 1];
    if (e.shiftKey && document.activeElement === premier) { e.preventDefault(); dernier.focus(); }
    else if (!e.shiftKey && document.activeElement === dernier) { e.preventDefault(); premier.focus(); }
  };
  function close() {
    root.innerHTML = '';
    document.removeEventListener('keydown', onKey);
    if (avant && avant.focus) avant.focus();
  }
  document.addEventListener('keydown', onKey);
  fond.addEventListener('mousedown', (e) => { if (e.target === fond) close(); });
  const premier = focusables()[0];
  if (premier) premier.focus();
  return { el: fond, close };
}

function modaleConfirm(message, opts) {
  opts = opts || {};
  return new Promise((resolve) => {
    const m = modale(
      '<h2>' + esc(opts.titre || 'Confirmation') + '</h2>' +
      '<p>' + esc(message) + '</p>' +
      '<div class="modal-actions">' +
      '<button type="button" class="btn" data-a="non">Annuler</button>' +
      '<button type="button" class="btn ' + (opts.danger ? 'btn-danger' : 'btn-primary') + '" data-a="oui">' + esc(opts.ok || 'Confirmer') + '</button>' +
      '</div>'
    );
    m.el.querySelector('[data-a="non"]').addEventListener('click', () => { m.close(); resolve(false); });
    m.el.querySelector('[data-a="oui"]').addEventListener('click', () => { m.close(); resolve(true); });
  });
}

function modaleMotDePasse() {
  const m = modale(
    '<h2>Changer mon mot de passe</h2>' +
    '<div class="field"><label for="mp-actuel">Mot de passe actuel</label><input id="mp-actuel" class="input" type="password" autocomplete="current-password"></div>' +
    '<div class="field"><label for="mp-nouveau">Nouveau mot de passe (8 caractères minimum)</label><input id="mp-nouveau" class="input" type="password" autocomplete="new-password"></div>' +
    '<div class="field"><label for="mp-confirme">Confirmez le nouveau mot de passe</label><input id="mp-confirme" class="input" type="password" autocomplete="new-password"></div>' +
    '<div class="modal-actions">' +
    '<button type="button" class="btn" data-a="annuler">Annuler</button>' +
    '<button type="button" class="btn btn-primary" data-a="ok">Changer le mot de passe</button></div>'
  );
  m.el.querySelector('[data-a="annuler"]').addEventListener('click', m.close);
  m.el.querySelector('[data-a="ok"]').addEventListener('click', async () => {
    const nouveau = m.el.querySelector('#mp-nouveau').value;
    if (nouveau.length < 8) { toast('Le nouveau mot de passe doit contenir au moins 8 caractères.', true); return; }
    if (nouveau !== m.el.querySelector('#mp-confirme').value) { toast('Les deux nouveaux mots de passe ne sont pas identiques.', true); return; }
    try {
      await api('password_change', { current: m.el.querySelector('#mp-actuel').value, new: nouveau });
      m.close();
      toast('Mot de passe modifié.');
    } catch (e) {}
  });
}

/* ------------------------------------------------ c'est parti */

boot();

JS_D8_9f3a7c21;
}

/* ===================== Sauvegarde (ligne de commande) ==================== */

/**
 * Sauvegarde automatique (Planificateur de tâches Windows / cron) :
 *
 *   php.exe C:\xampp\htdocs\support\index.php sauvegarde [dossier] [nombre à garder]
 *
 * Produit une archive datée (base + pièces jointes) et fait le ménage.
 */
function lancer_sauvegarde_cli(array $argv): int
{
    $destination = $argv[2] ?? (DB_DIR . '/sauvegardes');
    $aGarder     = max(1, (int) ($argv[3] ?? 14));
    $dire = static function (string $m): void { echo date('H:i:s') . '  ' . $m . PHP_EOL; };

    $dire('Sauvegarde de ' . setting_get('app_name', 'D8 Support'));
    if (!is_dir($destination) && !@mkdir($destination, 0775, true)) {
        $dire('ERREUR : impossible de créer ' . $destination); return 1;
    }
    if (!is_writable($destination)) {
        $dire('ERREUR : écriture refusée dans ' . $destination); return 1;
    }
    $horodatage = date('Y-m-d_H-i-s');
    $temporaire = $destination . DIRECTORY_SEPARATOR . 'base_' . $horodatage . '.tmp';
    try {
        db()->exec('VACUUM INTO ' . db()->quote($temporaire));
    } catch (Throwable $e) {
        try { db()->exec('PRAGMA wal_checkpoint(TRUNCATE)'); } catch (Throwable $e2) {}
        if (!@copy(DB_PATH, $temporaire)) {
            $dire('ERREUR : copie de la base impossible — ' . $e->getMessage()); return 1;
        }
        $dire('SQLite ancien : copie directe utilisée');
    }
    $dire('Base copiée (' . round(filesize($temporaire) / 1024) . ' Ko)');

    if (class_exists('ZipArchive')) {
        $archive = $destination . DIRECTORY_SEPARATOR . 'sauvegarde_' . $horodatage . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $dire("ERREUR : création de l'archive impossible"); @unlink($temporaire); return 1;
        }
        $zip->addFile($temporaire, 'ticketing.sqlite');
        $nb = 0;
        foreach (glob(UPLOAD_DIR . '/.ht_*') ?: [] as $pj) {
            if (is_file($pj)) { $zip->addFile($pj, 'uploads/' . basename($pj)); $nb++; }
        }
        $zip->addFromString('LISEZ-MOI.txt',
            "Sauvegarde automatique du " . date('d/m/Y à H:i') . "\r\n\r\n"
            . "  ticketing.sqlite  la base (tickets, messages, comptes, réglages)\r\n"
            . "  uploads/          les $nb pièce(s) jointe(s)\r\n\r\n"
            . "Restauration : arrêter le serveur web, remplacer le contenu de data/\r\n"
            . "en renommant ticketing.sqlite en .ht_ticketing.sqlite, replacer uploads/,\r\n"
            . "puis redémarrer.\r\n");
        $zip->close();
        @unlink($temporaire);
        $dire('Archive créée : ' . basename($archive) . ' (' . round(filesize($archive) / 1024) . ' Ko, ' . $nb . ' pièce(s) jointe(s))');
    } else {
        $archive = $destination . DIRECTORY_SEPARATOR . 'base_seule_' . $horodatage . '.sqlite';
        rename($temporaire, $archive);
        $dire('ATTENTION : extension zip absente — seule la base est sauvegardée, pas les pièces jointes.');
        $dire('Fichier créé : ' . basename($archive));
    }

    $anciennes = array_merge(
        glob($destination . DIRECTORY_SEPARATOR . 'sauvegarde_*.zip') ?: [],
        glob($destination . DIRECTORY_SEPARATOR . 'base_seule_*.sqlite') ?: []
    );
    usort($anciennes, fn($a, $b) => filemtime($b) <=> filemtime($a));
    $supprimees = 0;
    foreach (array_slice($anciennes, $aGarder) as $vieille) {
        if (@unlink($vieille)) { $supprimees++; }
    }
    if ($supprimees) { $dire($supprimees . ' ancienne(s) sauvegarde(s) supprimée(s), ' . $aGarder . ' conservée(s)'); }
    $dire('Terminé.');
    return 0;
}

/* =============================== Routeur ================================== */

// Sauvegarde planifiée : « php index.php sauvegarde … »
if (PHP_SAPI === 'cli') {
    $sous = $argv[1] ?? '';
    if ($sous === 'sauvegarde' || $sous === 'backup') {
        exit(lancer_sauvegarde_cli($argv));
    }
    fwrite(STDERR, "Usage : php index.php sauvegarde [dossier] [nombre à garder]\n");
    exit(2);
}

$__action = (string) ($_GET['action'] ?? '');
$__page   = (string) ($_GET['page'] ?? '');

// Nom de ce fichier (index.php en principe). La page le transmet au script,
// qui l'utilise dans tous ses appels : aucune dépendance au « document par
// défaut » du serveur web (IIS ou Apache).
$__self = basename(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '')));
if (!preg_match('/^[A-Za-z0-9._-]+\.php$/', $__self)) {
    $__self = 'index.php';
}
$__selfH = htmlspecialchars($__self, ENT_QUOTES, 'UTF-8');

// Ressources statiques servies par le fichier lui-même (CSP « self » conservée,
// et mises en cache par le navigateur).
if (isset($_GET['css'])) {
    header('Content-Type: text/css; charset=utf-8');
    header('Cache-Control: public, max-age=86400');
    echo app_css();
    exit;
}
if (isset($_GET['js'])) {
    header('Content-Type: application/javascript; charset=utf-8');
    header('Cache-Control: public, max-age=86400');
    echo app_js();
    exit;
}

if ($__action === '') {

    /* ---- Page « Vérification de l'installation » (?page=verification) ---- */
    if ($__page === 'verification') {
/**
 * D8 Support — vérification de l'installation
 *
 * À ouvrir juste après avoir copié le dossier sur le serveur. Contrôle en
 * quelques secondes tout ce qui, sur une machine réelle, empêche l'outil de
 * fonctionner correctement : version de PHP, extensions, droits d'écriture,
 * limites d'envoi, fuseau horaire, et protection effective du dossier data/.
 *
 * Accès : libre tant qu'aucun compte n'existe (le temps de l'installation),
 * réservé aux administrateurs ensuite.
 */


demarrer_session();

header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; "
     . "script-src 'self' 'unsafe-inline'; connect-src 'self'; frame-ancestors 'self'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

$installe = false;
try {
    $installe = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
} catch (Throwable $e) {
    $installe = false;
}

if ($installe) {
    $autorise = false;
    if (!empty($_SESSION['uid'])) {
        $st = db()->prepare("SELECT COUNT(*) FROM users WHERE id = ? AND role = 'admin' AND active = 1");
        $st->execute([(int) $_SESSION['uid']]);
        $autorise = (int) $st->fetchColumn() > 0;
    }
    if (!$autorise) {
        http_response_code(403);
        echo '<!doctype html><html lang="fr"><meta charset="utf-8">'
           . '<title>Vérification</title><link rel="stylesheet" href="' . $__selfH . '?css=1">'
           . '<div class="auth"><div class="auth-card"><h1>Accès réservé</h1>'
           . '<p>Cette page est réservée aux administrateurs. '
           . '<a href="' . $__selfH . '">Connectez-vous</a>, puis revenez ici.</p></div></div>';
        exit;
    }
}

/* ------------------------------------------------------------ contrôles */

$resultats = [];
function controle(string $titre, string $etat, string $detail, string $action = ''): void
{
    $GLOBALS['resultats'][] = compact('titre', 'etat', 'detail', 'action');
}

function en_octets(string $valeur): int
{
    $valeur = trim($valeur);
    if ($valeur === '') {
        return 0;
    }
    $unites = ['K' => 1024, 'M' => 1048576, 'G' => 1073741824];
    $suffixe = strtoupper(substr($valeur, -1));
    return (int) $valeur * ($unites[$suffixe] ?? 1);
}

// Les consignes de correction diffèrent selon le serveur web.
$sousIIS = stripos((string) ($_SERVER['SERVER_SOFTWARE'] ?? ''), 'IIS') !== false;
$redemarrer = $sousIIS ? 'redémarrez IIS (commande iisreset)' : 'redémarrez Apache';

// --- PHP
$php = PHP_VERSION;
controle('Version de PHP', version_compare($php, '8.0', '>=') ? 'ok' : 'ko', $php,
    version_compare($php, '8.0', '>=') ? '' : 'PHP 8.0 minimum est requis. '
        . ($sousIIS ? 'Relancez INSTALLER.cmd pour installer un PHP récent.' : 'Mettez XAMPP à jour.'));

foreach ([['pdo_sqlite', true, 'Sans elle, aucune donnée ne peut être enregistrée.'],
          ['fileinfo', true, 'Sans elle, les pièces jointes ne peuvent pas être vérifiées.'],
          ['zip', false, "Sans elle, la sauvegarde ne contiendra pas les pièces jointes."],
          ['ldap', false, "Sans elle, impossible d'utiliser les comptes Active Directory."],
          ['openssl', false, "Sans elle, les emails ne peuvent pas être envoyés en TLS."]] as [$ext, $obligatoire, $sinon]) {
    $present = extension_loaded($ext);
    controle('Extension ' . $ext, $present ? 'ok' : ($obligatoire ? 'ko' : 'attention'),
        $present ? 'chargée' : 'absente',
        $present ? '' : $sinon . ' Activez-la dans php.ini (ligne extension=' . $ext . ') puis ' . $redemarrer . '.');
}

// --- Dossier de données
$ecrivable = is_dir(DB_DIR) && is_writable(DB_DIR);
controle('Dossier data/ accessible en écriture', $ecrivable ? 'ok' : 'ko',
    // Chemin relatif : cette page est consultable avant l'installation, il
    // est inutile d'y annoncer l'arborescence du serveur.
    'data' . DIRECTORY_SEPARATOR . ($ecrivable ? '(écriture possible)' : '— écriture refusée'),
    $ecrivable ? '' : "Donnez les droits d'écriture au compte du serveur web sur ce dossier.");

// Les sessions sont stockées dans la base (table « sessions »), jamais dans
// des fichiers : il n'y a donc aucun fichier de session à exposer sur le réseau.
$sessTable = false;
try {
    $sessTable = (bool) db()->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='sessions'")->fetchColumn();
} catch (Throwable $e) { $sessTable = false; }
controle('Sessions', $sessTable ? 'ok' : 'attention',
    $sessTable ? 'stockées en base (durée : 12 h sans activité) — aucun fichier exposé'
               : 'la table des sessions sera créée au premier accès',
    '');

$baseOk = file_exists(DB_PATH);
controle('Base de données', $baseOk ? 'ok' : 'attention',
    $baseOk ? 'présente (' . round(filesize(DB_PATH) / 1024) . ' Ko)' : 'pas encore créée',
    $baseOk ? '' : "Elle sera créée automatiquement au premier accès à l'application.");

$libre = @disk_free_space(DB_DIR);
if ($libre !== false) {
    $go = $libre / 1073741824;
    controle('Espace disque disponible', $go < 0.5 ? 'ko' : ($go < 2 ? 'attention' : 'ok'),
        number_format($go, 1, ',', ' ') . ' Go',
        $go < 2 ? "Faites de la place : sans espace libre, les pièces jointes seront refusées." : '');
}

// --- Limites d'envoi
$upl = ini_get('upload_max_filesize') ?: '';
$post = ini_get('post_max_size') ?: '';
$assez = en_octets($upl) >= 5242880 && en_octets($post) >= 8388608;
controle("Taille maximale d'envoi", $assez ? 'ok' : 'attention',
    'upload_max_filesize = ' . $upl . ', post_max_size = ' . $post,
    $assez ? '' : "Mettez au moins upload_max_filesize=8M et post_max_size=10M dans php.ini, "
                . "sinon les pièces jointes de 5 Mo seront refusées.");

// --- Affichage des erreurs
$affiche = filter_var(ini_get('display_errors'), FILTER_VALIDATE_BOOL);
controle('Affichage des erreurs PHP', $affiche ? 'attention' : 'ok',
    $affiche ? 'activé' : 'désactivé',
    $affiche ? "Mettez display_errors = Off dans php.ini : sinon un incident affiche "
             . "les chemins du serveur à l'écran des utilisateurs." : '');

// --- Fuseau horaire (le décalage fausse les délais)
$phpTz  = date_default_timezone_get();
$phpH   = date('H:i');
$sysH   = @trim((string) @shell_exec(stripos(PHP_OS, 'WIN') === 0 ? 'time /t' : 'date +%H:%M'));
$coherent = $sysH === '' || substr($sysH, 0, 2) === substr($phpH, 0, 2);
controle('Fuseau horaire', $coherent ? 'ok' : 'attention',
    'PHP : ' . $phpH . ' (' . $phpTz . ')' . ($sysH !== '' ? ' — système : ' . $sysH : ''),
    $coherent ? '' : "PHP et le système n'affichent pas la même heure. L'outil se fie à "
                   . "l'heure de PHP ; vérifiez qu'elle correspond bien à l'heure française.");

// --- HTTPS
$https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off');
controle('Connexion chiffrée (HTTPS)', $https ? 'ok' : 'attention',
    $https ? 'activée' : 'non — la page est servie en HTTP',
    $https ? '' : "Acceptable sur un réseau local fermé. Indispensable si l'outil devient "
               . "accessible depuis l'extérieur.");

// --- Serveur web
$serveur = $_SERVER['SERVER_SOFTWARE'] ?? 'inconnu';
controle('Serveur web', stripos($serveur, 'Development Server') !== false ? 'ko' : 'ok', $serveur,
    stripos($serveur, 'Development Server') !== false
        ? "Le serveur intégré de PHP n'applique aucune protection de fichiers. "
        . "Utilisez Apache (XAMPP) ou IIS en production." : '');

$compte = 0;
try { $compte = (int) db()->query('SELECT COUNT(*) FROM tickets')->fetchColumn(); } catch (Throwable $e) {}
controle('Contenu actuel', 'info',
    $compte . ' ticket(s), ' . (int) (db()->query('SELECT COUNT(*) FROM users')->fetchColumn()) . ' compte(s)');

$nb = ['ok' => 0, 'attention' => 0, 'ko' => 0];
foreach ($resultats as $r) {
    if (isset($nb[$r['etat']])) { $nb[$r['etat']]++; }
}
$verdict = $nb['ko'] ? 'ko' : ($nb['attention'] ? 'attention' : 'ok');
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Vérification de l'installation</title>
<link rel="stylesheet" href="<?= $__selfH ?>?css=1">
<style>
  body { padding: 0; }
  .verif { max-width: 900px; margin: 0 auto; padding: 2rem 1.2rem 3rem; }
  .verif-ligne {
    display: grid; grid-template-columns: 2rem 1fr; gap: .8rem;
    padding: .9rem 0; border-bottom: 1px solid var(--ligne);
  }
  .verif-ligne:last-child { border-bottom: 0; }
  .pastille { font-size: 1.3rem; line-height: 1.2; text-align: center; }
  .e-ok .pastille { color: #1B8A5F; }
  .e-attention .pastille { color: #D97706; }
  .e-ko .pastille { color: var(--danger); }
  .e-info .pastille { color: var(--muted); }
  .verif-titre { font-weight: 700; }
  .verif-detail { color: var(--muted); }
  .verif-action { margin-top: .3rem; }
  .bandeau { border-radius: var(--r-carte); padding: 1.1rem 1.3rem; margin-bottom: 1.4rem; font-weight: 600; }
  .b-ok { background: #DFF2E7; color: #13654A; border: 1px solid #BBE3CD; }
  .b-attention { background: #FDF4E7; color: #96490A; border: 1px solid #F0CB9C; }
  .b-ko { background: #FAE6E4; color: #8E1F16; border: 1px solid #F0C0BA; }
</style>
</head>
<body>
<div class="verif">
  <p><a class="btn btn-ghost" href="<?= $__selfH ?>">&larr; Retour à l'application</a></p>
  <h1>Vérification de l'installation</h1>
  <p class="sous-titre">À faire une fois, après avoir copié le dossier sur le serveur.</p>

  <div class="bandeau b-<?= $verdict ?>">
    <?php if ($verdict === 'ok'): ?>
      Tout est en ordre : l'installation est prête à être utilisée.
    <?php elseif ($verdict === 'attention'): ?>
      L'outil fonctionnera, mais <?= $nb['attention'] ?> point(s) méritent votre attention ci-dessous.
    <?php else: ?>
      <?= $nb['ko'] ?> problème(s) empêchent l'outil de fonctionner correctement. Corrigez-les d'abord.
    <?php endif; ?>
  </div>

  <div class="card">
    <?php foreach ($resultats as $r): ?>
      <div class="verif-ligne e-<?= htmlspecialchars($r['etat'], ENT_QUOTES) ?>">
        <div class="pastille"><?= ['ok' => '&#10003;', 'attention' => '!', 'ko' => '&#10007;', 'info' => 'i'][$r['etat']] ?? '' ?></div>
        <div>
          <div class="verif-titre"><?= htmlspecialchars($r['titre'], ENT_QUOTES) ?></div>
          <div class="verif-detail"><?= htmlspecialchars($r['detail'], ENT_QUOTES) ?></div>
          <?php if ($r['action']): ?>
            <div class="verif-action"><?= htmlspecialchars($r['action'], ENT_QUOTES) ?></div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="card">
    <h2>Protection des données depuis le navigateur</h2>
    <p class="sous-titre">Ce contrôle est fait par votre navigateur, en interrogeant réellement le
      serveur : c'est le seul moyen de savoir si le fichier .htaccess est pris en compte.</p>
    <div id="protection" class="verif-ligne e-info">
      <div class="pastille">…</div><div><div class="verif-titre">Contrôle en cours</div></div>
    </div>
  </div>
</div>

<script>
/* On demande au serveur des fichiers qui ne doivent JAMAIS être servis.
   Une réponse 200 signifie que la base ou les pièces jointes sont
   téléchargeables par n'importe qui sur le réseau. */
(async function () {
  const IIS = <?= stripos((string) ($_SERVER['SERVER_SOFTWARE'] ?? ''), 'IIS') !== false ? 'true' : 'false' ?>;
  const cibles = [
    ['la base de données', 'data/.ht_ticketing.sqlite'],
    ['le journal des erreurs', 'data/.ht_erreurs.log'],
    ['le code d\'installation', 'data/.ht_installation.txt'],
  ];
  const exposes = [];
  for (const [nom, url] of cibles) {
    try {
      const r = await fetch(url, { method: 'GET', cache: 'no-store' });
      if (r.status === 200) exposes.push(nom);
    } catch (e) { /* bloqué : c'est ce qu'on veut */ }
  }
  const zone = document.getElementById('protection');
  if (exposes.length === 0) {
    zone.className = 'verif-ligne e-ok';
    zone.innerHTML = '<div class="pastille">&#10003;</div><div>' +
      '<div class="verif-titre">Les fichiers sensibles sont bien protégés</div>' +
      '<div class="verif-detail">La base, les pièces jointes et le code interne ne sont pas ' +
      'téléchargeables depuis un navigateur.</div></div>';
  } else {
    zone.className = 'verif-ligne e-ko';
    zone.innerHTML = '<div class="pastille">&#10007;</div><div>' +
      '<div class="verif-titre">Fichiers accessibles à tous : ' + exposes.join(', ') + '</div>' +
      '<div class="verif-detail">' + (IIS
        ? 'Le fichier web.config livré avec index.php (il masque le dossier data) est absent ou ' +
          'ignoré. Relancez INSTALLER.cmd, qui le remet en place et redonne le droit « Modifier » ' +
          'sur data à IUSR et IIS_IUSRS, puis rechargez cette page.'
        : 'Le fichier .htaccess n\'est pas pris en compte par Apache. Dans httpd.conf, remplacez ' +
          '« AllowOverride None » par « AllowOverride All » pour le dossier concerné, puis ' +
          'redémarrez Apache et rechargez cette page.') + '</div></div>';
  }
})();
</script>
</body>
</html>
        <?php
        exit;
    }

    /* ------------------------- Page de l'application ---------------------- */
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; "
     . "style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'; "
     . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    ?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="d8-app" content="<?= $__selfH ?>">
<title>D8 Support — Tickets informatiques</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='6' fill='%2316303A'/%3E%3Cpath d='M7 11h18v3.2a2.8 2.8 0 0 0 0 5.6V23H7v-3.2a2.8 2.8 0 0 0 0-5.6V11z' fill='%23E9EEF0'/%3E%3Cpath d='M19 11v12' stroke='%2316303A' stroke-width='1.4' stroke-dasharray='2 2.4'/%3E%3C/svg%3E">
<link rel="stylesheet" href="<?= $__selfH ?>?css=1">
</head>
<body>

<!-- Raccourci clavier : première tabulation, on saute la navigation -->
<a class="skip" href="#main">Aller au contenu</a>

<!-- Écran de connexion / première configuration (rempli par le script) -->
<div id="screen-auth" class="auth hidden" role="main"></div>

<!-- Application -->
<div id="app" class="app hidden">
  <div id="scrim" class="scrim" hidden></div>

  <aside id="sidebar" class="sidebar" aria-label="Navigation principale">
    <a class="brand" href="#/" title="Revenir à l'accueil">
      <span class="brand-mark" id="brand-mark" aria-hidden="true"></span>
      <div>
        <div class="brand-name">D8 Support</div>
        <div class="brand-sub">Tickets informatiques</div>
      </div>
    </a>

    <nav class="nav" id="nav"></nav>

  </aside>

  <div class="main-col">
    <header class="topbar">
      <button type="button" id="btn-menu" class="btn-menu" aria-label="Ouvrir le menu">
        <svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>
      </button>
      <div class="topbar-title">
        <a class="tt-app" href="#/">D8 Support</a>
        <nav class="tt-page" id="fil-ariane" aria-label="Vous êtes ici"></nav>
      </div>
      <div class="topbar-compteurs" id="topbar-compteurs"></div>
      <div class="topbar-user" id="topbar-user"></div>
    </header>
    <main id="main" class="main" role="main" tabindex="-1"></main>
  </div>
</div>

<div id="modal-root"></div>
<div id="toast-root" aria-live="polite"></div>

<script src="<?= $__selfH ?>?js=1"></script>
</body>
</html>
    <?php
    exit;
}

/* ================================ API ===================================== */
/* À partir d'ici : $__action est non vide. Le code de l'API (ex-api.php) est
 * au niveau racine du fichier (les « const » y sont donc autorisées) mais ne
 * s'exécute que pour les appels ?action=… puisque tout le reste sort plus haut. */
/**
 * D8 Support — API (JSON)
 * Toutes les actions passent par api.php?action=...
 * Sessions PHP + jeton anti-CSRF sur les actions qui modifient des données.
 */


// Tampon de sortie : permet de renvoyer la réponse au navigateur AVANT
// d'ouvrir la connexion au serveur de messagerie (voir envoyer_les_mails).
ob_start();

/**
 * Filet de sécurité général. Quoi qu'il arrive — base verrouillée, disque
 * plein, bogue de programmation — le navigateur reçoit du JSON exploitable
 * et non une page d'erreur PHP. Le détail part dans data/.ht_erreurs.log,
 * jamais à l'écran de l'utilisateur.
 */
function journal_erreur(string $texte): void
{
    $fichier = DB_DIR . '/.ht_erreurs.log';
    // On borne le fichier pour qu'il ne remplisse jamais le disque.
    if (is_file($fichier) && filesize($fichier) > 1048576) {
        @rename($fichier, DB_DIR . '/.ht_erreurs.1.log');
    }
    @file_put_contents($fichier, '[' . date('Y-m-d H:i:s') . '] ' . $texte . "\n", FILE_APPEND);
}

function erreur_fatale(string $detail): void
{
    journal_erreur($detail);
    while (ob_get_level() > 0) {
        ob_end_clean(); // écarte une éventuelle sortie d'erreur PHP
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'ok'    => false,
        'error' => "Le serveur a rencontré une erreur interne. L'incident a été enregistré ; "
                 . "signalez-le au service informatique en précisant l'heure.",
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

set_exception_handler(function (Throwable $e) {
    erreur_fatale(get_class($e) . ' : ' . $e->getMessage()
        . ' (' . $e->getFile() . ':' . $e->getLine() . ')');
});

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        erreur_fatale('Erreur fatale : ' . $e['message'] . ' (' . $e['file'] . ':' . $e['line'] . ')');
    }
});

/*
 * Un envoi trop volumineux fait perdre à PHP le contenu du formulaire :
 * $_POST et $_FILES arrivent vides et l'utilisateur lisait « le titre est
 * trop court » au lieu de la vraie cause.
 */
$limitePost = ini_get('post_max_size');
if ($limitePost) {
    $unites = ['K' => 1024, 'M' => 1048576, 'G' => 1073741824];
    $suffixe = strtoupper(substr(trim($limitePost), -1));
    $octets  = (int) $limitePost * ($unites[$suffixe] ?? 1);
    $envoye  = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($octets > 0 && $envoye > $octets && empty($_POST)) {
        http_response_code(413);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' =>
            "L'envoi est trop volumineux pour ce serveur (limite : " . $limitePost
            . "). Réduisez le nombre ou la taille des pièces jointes, ou demandez au "
            . "service informatique d'augmenter post_max_size dans php.ini."],
            JSON_UNESCAPED_UNICODE);
        exit;
    }
}

demarrer_session();

// Sous Apache, la compression (mod_deflate) retient la réponse jusqu'à la fin
// du script : l'utilisateur attendrait alors l'envoi des emails. On la
// désactive pour l'API, dont les réponses sont de toute façon minuscules.
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}

const STATUSES   = ['nouveau', 'en_cours', 'en_attente', 'resolu', 'ferme'];
const PRIORITIES = ['basse', 'normale', 'haute', 'critique'];
const ROLES      = ['employe', 'admin'];
const MAX_FILE   = 5242880; // 5 Mo
const MAX_FILES_TICKET  = 3;
const MAX_FILES_COMMENT = 3;
const TRIS = ['ref' => 't.id', 'title' => 't.title', 'status' => 't.status',
              'priority' => "CASE t.priority WHEN 'critique' THEN 4 WHEN 'haute' THEN 3 WHEN 'normale' THEN 2 ELSE 1 END",
              'site' => 't.site', 'category' => 't.category',
              'creator' => 'c.name', 'created_at' => 't.created_at', 'updated_at' => 't.updated_at'];

function status_label(string $s): string
{
    return ['nouveau' => 'Nouveau', 'en_cours' => 'En cours', 'en_attente' => 'En attente',
            'resolu' => 'Résolu', 'ferme' => 'Fermé'][$s] ?? $s;
}
function priority_label(string $p): string
{
    return ['basse' => 'Basse', 'normale' => 'Normale', 'haute' => 'Haute', 'critique' => 'Critique'][$p] ?? $p;
}
function role_label(string $r): string
{
    return ['employe' => 'Employé', 'admin' => 'Administrateur'][$r] ?? $r;
}

/* ---------------------------------------------------------------- helpers */

/** Longueur d'une chaîne UTF-8, sans dépendre de l'extension mbstring. */
function len(string $s): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($s);
    }
    if (function_exists('iconv_strlen')) {
        $n = iconv_strlen($s, 'UTF-8');
        if ($n !== false) {
            return $n;
        }
    }
    return strlen($s);
}

function json_out(array $payload, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}
function ok($data = null): void
{
    json_out(['ok' => true, 'data' => $data]);
}
function fail(string $message, int $code = 400): void
{
    json_out(['ok' => false, 'error' => $message], $code);
}

/** Corps de la requête : JSON ou formulaire (multipart pour les fichiers). */
function body(): array
{
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
        $data = json_decode((string) file_get_contents('php://input'), true);
        return is_array($data) ? $data : [];
    }
    return $_POST;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}
function check_csrf(): void
{
    $sent = $_SERVER['HTTP_X_CSRF'] ?? '';
    if ($sent === '' || !hash_equals(csrf_token(), $sent)) {
        fail('Votre session a expiré. Rechargez la page (touche F5) puis réessayez.', 403);
    }
}

function current_user(): ?array
{
    if (empty($_SESSION['uid'])) {
        return null;
    }
    // Déconnexion après inactivité — utile sur les postes partagés de l'atelier.
    // L'interrogation périodique (ping) ne compte pas comme une activité :
    // sinon un poste laissé ouvert resterait connecté indéfiniment.
    $minutes = (int) setting_get('idle_minutes', '0');
    $estPing = (($_GET['action'] ?? '') === 'ping');
    if ($minutes > 0) {
        $dernier = (int) ($_SESSION['last_seen'] ?? time());
        if (time() - $dernier > $minutes * 60) {
            $_SESSION = [];
            session_destroy();
            return null;
        }
    }
    if (!$estPing) {
        $_SESSION['last_seen'] = time();
    }
    $st = db()->prepare('SELECT id, name, login, email, role, phone, auth, active FROM users WHERE id = ?');
    $st->execute([(int) $_SESSION['uid']]);
    $u = $st->fetch();
    if (!$u || !(int) $u['active']) {
        unset($_SESSION['uid']);
        return null;
    }
    return $u;
}
function require_auth(): array
{
    $u = current_user();
    if (!$u) {
        fail('Vous devez être connecté.', 401);
    }
    return $u;
}
function require_role(array $user, array $roles): void
{
    if (!in_array($user['role'], $roles, true)) {
        fail("Vous n'avez pas les droits nécessaires pour cette action.", 403);
    }
}
/** Le service informatique, c'est-à-dire les administrateurs. */
function is_staff(array $user): bool
{
    return $user['role'] === 'admin';
}

function users_count(): int
{
    return (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
}

define('CODE_INSTALL', DB_DIR . '/.ht_installation.txt');

/**
 * Entre le moment où les fichiers sont copiés sur le serveur et celui où le
 * compte administrateur est créé, n'importe qui sur le réseau pouvait ouvrir
 * la page et s'emparer de ce compte. On exige donc un code déposé dans un
 * fichier du serveur : seule la personne qui a installé l'outil peut le lire.
 * Le fichier est supprimé dès que le compte est créé.
 */
function code_installation(): string
{
    if (is_file(CODE_INSTALL)) {
        $code = trim((string) @file_get_contents(CODE_INSTALL));
        if ($code !== '') {
            return $code;
        }
    }
    $code = strtoupper(bin2hex(random_bytes(3)));
    @file_put_contents(CODE_INSTALL,
        "Code d'installation de " . setting_get('app_name', 'D8 Support') . "\r\n\r\n"
        . "    " . $code . "\r\n\r\n"
        . "Recopiez ce code dans l'écran de bienvenue pour créer le compte\r\n"
        . "administrateur. Ce fichier disparaîtra automatiquement ensuite.\r\n");
    return $code;
}

/** Extrait le code du fichier, quel que soit le texte qui l'entoure. */
function code_attendu(): string
{
    if (!is_file(CODE_INSTALL)) {
        return '';
    }
    $contenu = (string) @file_get_contents(CODE_INSTALL);
    return preg_match('/\b([0-9A-F]{6})\b/', $contenu, $m) ? $m[1] : '';
}

function ticket_row(int $id): ?array
{
    $st = db()->prepare(
        'SELECT t.*, c.name AS creator_name, c.email AS creator_email, c.phone AS creator_phone,
                a.name AS assignee_name
         FROM tickets t
         JOIN users c ON c.id = t.created_by
         LEFT JOIN users a ON a.id = t.assigned_to
         WHERE t.id = ?'
    );
    $st->execute([$id]);
    $t = $st->fetch();
    return $t ?: null;
}

function sys_comment(int $ticketId, int $userId, string $text): void
{
    db()->prepare('INSERT INTO comments (ticket_id, user_id, body, is_system, is_internal, created_at)
                   VALUES (?, ?, ?, 1, 0, ?)')
        ->execute([$ticketId, $userId, $text, now()]);
}
function touch_ticket(int $ticketId): void
{
    db()->prepare('UPDATE tickets SET updated_at = ? WHERE id = ?')->execute([now_us(), $ticketId]);
}
/** Marque un ticket comme lu par un utilisateur. */
function mark_seen(int $userId, int $ticketId): void
{
    db()->prepare('INSERT INTO views (user_id, ticket_id, seen_at) VALUES (?, ?, ?)
                   ON CONFLICT(user_id, ticket_id) DO UPDATE SET seen_at = excluded.seen_at')
        ->execute([$userId, $ticketId, now_us()]);
}

/** Nombre de tickets comportant du nouveau pour cet utilisateur. */
function unread_count(array $user): int
{
    $sql = "SELECT COUNT(*) FROM tickets t
            LEFT JOIN views v ON v.ticket_id = t.id AND v.user_id = ?
            WHERE t.status != 'ferme' AND (v.seen_at IS NULL OR v.seen_at < t.updated_at)";
    $params = [(int) $user['id']];
    if (!is_staff($user)) {
        $sql .= ' AND t.created_by = ?';
        $params[] = (int) $user['id'];
    }
    $st = db()->prepare($sql);
    $st->execute($params);
    return (int) $st->fetchColumn();
}

/** Liste des administrateurs actifs (destinataires des demandes). */
function staff_users(): array
{
    return db()->query("SELECT id, name, email FROM users WHERE active = 1 AND role = 'admin'
                        ORDER BY name COLLATE NOCASE")->fetchAll();
}

/**
 * Destinataires proposés pour un ticket donné. On y ajoute la personne déjà
 * assignée même si elle n'est plus administrateur : sinon la liste ne la
 * contient pas, le menu retombe sur « Non assigné » et un simple
 * enregistrement désassignait le ticket sans prévenir.
 */
function assignables_pour(?int $assigneActuel): array
{
    $liste = staff_users();
    if ($assigneActuel && !in_array($assigneActuel, array_map('intval', array_column($liste, 'id')), true)) {
        $st = db()->prepare('SELECT id, name, email FROM users WHERE id = ?');
        $st->execute([$assigneActuel]);
        $u = $st->fetch();
        if ($u) {
            $u['name'] .= ' (n\'est plus au support)';
            array_unshift($liste, $u);
        }
    }
    return $liste;
}

/* -------------------------------------------------------------- courriels */

function mail_config(): array
{
    return [
        'host'      => setting_get('mail_host'),
        'port'      => (int) setting_get('mail_port', '587'),
        'secure'    => setting_get('mail_secure', 'tls'),
        'user'      => setting_get('mail_user'),
        'pass'      => setting_get('mail_pass'),
        'from'      => setting_get('mail_from'),
        'from_name' => setting_get('mail_from_name', 'Support informatique'),
        // Une réponse à la notification arrive dans la boîte du support
        // plutôt que de se perdre.
        'reply_to'  => setting_get('mail_from'),
    ];
}

/** Adresse directe d'un ticket, à mettre dans les emails. */
function ticket_url(int $id): string
{
    $url = trim(setting_get('base_url'));
    return $url === '' ? '' : rtrim($url, '/') . '/#/ticket/' . $id;
}

/**
 * Met une notification en file d'attente. Rien n'est envoyé maintenant :
 * l'envoi a lieu une fois la réponse rendue au navigateur, pour que
 * personne n'attende un serveur de messagerie lent.
 */
function notify(string $email, string $sujet, string $corps, int $ticketId = 0): void
{
    if (setting_get('mail_enabled') !== '1' || $email === '') {
        return;
    }
    $lien = $ticketId ? ticket_url($ticketId) : rtrim(trim(setting_get('base_url')), '/');
    if ($lien !== '') {
        $corps .= "\n\nVoir le ticket : " . $lien;
    }
    $corps .= "\n\n--\nMessage automatique envoyé par " . setting_get('app_name', 'D8 Support') . '.';
    $GLOBALS['__mails'][] = ['to' => $email, 'sujet' => $sujet, 'corps' => $corps];
}

/**
 * Envoie la file d'attente après avoir rendu la main au navigateur.
 * Appelée automatiquement à la fin de la requête.
 */
function envoyer_les_mails(): void
{
    if (empty($GLOBALS['__mails'])) {
        return;
    }
    $file = $GLOBALS['__mails'];
    $GLOBALS['__mails'] = [];

    // La session est libérée maintenant : PHP verrouille la session d'un
    // utilisateur pendant toute la requête, et sa requête suivante (le ping,
    // l'ouverture du ticket créé) attendrait la fin des envois.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    // On termine la réponse HTTP d'abord ; le reste se fait hors du temps
    // d'attente de l'utilisateur.
    @ignore_user_abort(true);
    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
    } else {
        if (!headers_sent()) {
            @header('Connection: close');
            @header('Content-Length: ' . (string) ob_get_length());
        }
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @flush();
    }

    $cfg = mail_config();
    foreach ($file as $i => $m) {
        try {
            $r = smtp_envoyer($cfg, $m['to'], $m['sujet'], $m['corps']);
            if (!$r['ok']) {
                journal_erreur('Email à ' . $m['to'] . ' non envoyé : ' . $r['error']);
                // Serveur injoignable : inutile de retenter pour chaque
                // destinataire, chaque essai coûte le délai de connexion.
                if (stripos($r['error'], 'Connexion impossible') !== false) {
                    $restants = count($file) - $i - 1;
                    if ($restants > 0) {
                        journal_erreur('Serveur de messagerie injoignable : '
                            . $restants . ' notification(s) abandonnée(s).');
                    }
                    break;
                }
            }
        } catch (Throwable $e) {
            journal_erreur('Envoi email : ' . $e->getMessage());
        }
    }
}
register_shutdown_function('envoyer_les_mails');

/* ----------------------------------------------------------- entretien */

/** Ferme automatiquement les tickets résolus depuis N jours (au plus une fois par heure). */
function auto_close(): void
{
    $jours = (int) setting_get('auto_close_days', '7');
    if ($jours <= 0) {
        return;
    }
    $dernier = setting_get('last_autoclose');
    if ($dernier !== '' && (time() - strtotime($dernier)) < 3600) {
        return;
    }
    setting_set('last_autoclose', now());

    $limite = date('Y-m-d H:i:s', time() - $jours * 86400);
    // Traitement par lots : sur une base ancienne, on ne bloque pas une
    // connexion pour fermer trois mille tickets d'un coup.
    $st = db()->prepare("SELECT id FROM tickets WHERE status = 'resolu' AND updated_at < ? LIMIT 200");
    $st->execute([$limite]);
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        db()->prepare("UPDATE tickets SET status = 'ferme', closed_at = ?, updated_at = ? WHERE id = ?")
            ->execute([now(), now_us(), (int) $id]);
        db()->prepare('INSERT INTO comments (ticket_id, user_id, body, is_system, is_internal, created_at)
                       SELECT ?, created_by, ?, 1, 0, ? FROM tickets WHERE id = ?')
            ->execute([(int) $id, 'Fermé automatiquement : résolu depuis plus de ' . $jours . ' jours sans retour.', now(), (int) $id]);
    }
}

/**
 * Neutralise une cellule de tableur. Un titre commençant par =, +, - ou @
 * est interprété comme une FORMULE par Excel et LibreOffice : ouvrir
 * l'export suffirait alors à exécuter ce qu'un utilisateur a écrit dans le
 * titre de son ticket. L'apostrophe force la lecture en texte.
 */
function csv_sur($valeur): string
{
    $v = (string) $valeur;
    if ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) {
        return "'" . $v;
    }
    return $v;
}

/**
 * Refuse les mots de passe qu'une attaque essaie en premier. Sans ce filtre,
 * « 12345678 » passait le contrôle des 8 caractères, et un mot de passe égal
 * à l'adresse email de la personne aussi.
 * Renvoie null si le mot de passe est acceptable, sinon la raison du refus.
 */
function mot_de_passe_faible(string $pass, string $nom, string $email): ?string
{
    $bas = strtolower($pass);

    $courants = [
        'password', 'motdepasse', 'azertyui', 'qwertyui', '12345678', '123456789',
        '1234567890', 'azerty123', 'qwerty123', 'admin123', 'adminadmin', 'passw0rd',
        'bonjour1', 'soleil123', 'chocolat', 'informatique', 'entreprise', 'bienvenue',
        'welcome1', 'motdepasse1', 'iloveyou', 'princesse', 'football', 'abcd1234',
        'abcdefgh', 'aaaaaaaa', '00000000', '11111111', 'changeme', 'letmein1',
    ];
    if (in_array($bas, $courants, true)) {
        return 'Ce mot de passe est trop courant. Choisissez-en un autre.';
    }

    // Suites simples : 12345678, abcdefgh, azertyuiop…
    $suites = ['0123456789', 'abcdefghijklmnopqrstuvwxyz', 'azertyuiopqsdfghjklmwxcvbn',
               'qwertyuiopasdfghjklzxcvbnm'];
    foreach ($suites as $suite) {
        if (strpos($suite, $bas) !== false || strpos(strrev($suite), $bas) !== false) {
            return 'Ce mot de passe est une suite trop simple à deviner.';
        }
    }

    // Un seul caractère répété.
    if (preg_match('/^(.)\1+$/u', $pass)) {
        return 'Ce mot de passe ne contient qu\'un seul caractère répété.';
    }

    // Le mot de passe ne doit pas être le nom ou l'adresse de la personne.
    $morceaux = preg_split('/[\s@._-]+/u', strtolower($nom . ' ' . $email)) ?: [];
    foreach ($morceaux as $m) {
        if (len($m) >= 4 && strpos($bas, $m) !== false) {
            return 'Le mot de passe ne doit pas contenir le nom ni l\'adresse email.';
        }
    }
    return null;
}

/** La comparaison sans accents n'est possible que si SQLite accepte nos fonctions. */
function search_available(): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            $ok = db()->query("SELECT sansaccent('é')")->fetchColumn() === 'e';
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    return $ok;
}

/**
 * Suppression définitive des vieux tickets fermés, si l'administrateur l'a
 * demandée. Sert à limiter la conservation des données personnelles
 * (noms, adresses, captures d'écran) au-delà de ce qui est nécessaire.
 */
function purger_anciens(): void
{
    $mois = (int) setting_get('purge_months', '0');
    if ($mois <= 0) {
        return;
    }
    $limite = date('Y-m-d H:i:s', strtotime('-' . $mois . ' months'));
    $st = db()->prepare("SELECT id, ref FROM tickets WHERE status = 'ferme'
                         AND closed_at IS NOT NULL AND closed_at < ? LIMIT 100");
    $st->execute([$limite]);
    $lignes = $st->fetchAll();
    if (!$lignes) {
        return;
    }
    foreach ($lignes as $l) {
        $pj = db()->prepare('SELECT stored_name FROM attachments WHERE ticket_id = ?');
        $pj->execute([(int) $l['id']]);
        foreach ($pj->fetchAll(PDO::FETCH_COLUMN) as $f) {
            @unlink(UPLOAD_DIR . '/' . $f);
        }
        db()->prepare('DELETE FROM tickets WHERE id = ?')->execute([(int) $l['id']]);
    }
    journal_erreur('Purge automatique : ' . count($lignes) . ' ticket(s) fermé(s) depuis plus de '
        . $mois . ' mois supprimé(s) — ' . implode(', ', array_column($lignes, 'ref')));
}

/** Fiches de procédure visibles selon le rôle. */
function procedures_pour(array $user, bool $modelesSeulement = false): array
{
    $sql = 'SELECT id, title, body, category, public, modele, position, updated_at FROM procedures';
    $cond = [];
    if (!is_staff($user)) {
        $cond[] = 'public = 1';
    }
    if ($modelesSeulement) {
        $cond[] = 'modele = 1';
    }
    if ($cond) {
        $sql .= ' WHERE ' . implode(' AND ', $cond);
    }
    $sql .= ' ORDER BY position, id';
    return db()->query($sql)->fetchAll();
}

/** Le journal des connexions ne grossit pas indéfiniment. */
function purger_journaux(): void
{
    $n = (int) db()->query('SELECT COUNT(*) FROM logins')->fetchColumn();
    if ($n > 600) {
        db()->exec('DELETE FROM logins WHERE id NOT IN
                    (SELECT id FROM logins ORDER BY id DESC LIMIT 500)');
    }
}

/** Types de fichiers acceptés en pièce jointe. */
function allowed_mimes(): array
{
    return [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/gif'       => 'gif',
        'image/webp'      => 'webp',
        'application/pdf' => 'pdf',
    ];
}

/** Valide les fichiers envoyés ($_FILES['files']) et renvoie une liste normalisée. */
function validate_uploads(int $max): array
{
    if (empty($_FILES['files'])) {
        return [];
    }
    $f     = $_FILES['files'];
    $names = is_array($f['name']) ? $f['name'] : [$f['name']];
    $tmps  = is_array($f['tmp_name']) ? $f['tmp_name'] : [$f['tmp_name']];
    $errs  = is_array($f['error']) ? $f['error'] : [$f['error']];
    $sizes = is_array($f['size']) ? $f['size'] : [$f['size']];

    $count = count($names);
    if ($count > $max) {
        fail("Maximum $max pièces jointes.");
    }

    // Disque presque plein : mieux vaut refuser proprement une pièce jointe
    // que corrompre la base au moment de l'écriture suivante.
    $libre = @disk_free_space(DB_DIR);
    if ($libre !== false && $libre < 209715200) { // 200 Mo
        journal_erreur('Espace disque faible : ' . round($libre / 1048576) . ' Mo restants.');
        fail("Le serveur manque d'espace disque : les pièces jointes sont temporairement "
             . "refusées. Prévenez le service informatique.", 507);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $map   = allowed_mimes();
    $list  = [];

    for ($i = 0; $i < $count; $i++) {
        $err = (int) ($errs[$i] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $name = (string) ($names[$i] ?? 'fichier');
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            fail("Le fichier « $name » dépasse la taille maximale (5 Mo).");
        }
        if ($err !== UPLOAD_ERR_OK) {
            fail("Échec de l'envoi du fichier « $name ». Réessayez.");
        }
        if ((int) $sizes[$i] > MAX_FILE) {
            fail("Le fichier « $name » dépasse la taille maximale (5 Mo).");
        }
        $mime = $finfo->file($tmps[$i]) ?: '';
        if (!isset($map[$mime])) {
            fail("Le fichier « $name » n'est pas accepté. Formats autorisés : images (JPG, PNG, GIF, WebP) et PDF.");
        }
        $list[] = ['orig' => $name, 'tmp' => $tmps[$i], 'mime' => $mime,
                   'ext' => $map[$mime], 'size' => (int) $sizes[$i]];
    }
    return $list;
}

/**
 * Enregistre des fichiers validés. Renvoie la liste des noms qui ont échoué :
 * un problème de disque ne doit pas faire perdre le ticket ou le message
 * déjà enregistré — on prévient simplement que la pièce jointe manque.
 */
function persist_uploads(array $files, int $ticketId, ?int $commentId, int $userId): array
{
    $echecs = [];
    foreach ($files as $file) {
        $stored = '.ht_' . bin2hex(random_bytes(16)) . '.' . $file['ext'];
        try {
            if (!@move_uploaded_file($file['tmp'], UPLOAD_DIR . '/' . $stored)) {
                throw new RuntimeException('déplacement impossible vers ' . UPLOAD_DIR);
            }
            db()->prepare('INSERT INTO attachments (ticket_id, comment_id, orig_name, stored_name, mime, size, uploaded_by, created_at)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$ticketId, $commentId, $file['orig'], $stored, $file['mime'], $file['size'], $userId, now()]);
        } catch (Throwable $e) {
            @unlink(UPLOAD_DIR . '/' . $stored);
            $echecs[] = $file['orig'];
            journal_erreur('Pièce jointe « ' . $file['orig'] . ' » non enregistrée : ' . $e->getMessage());
        }
    }
    return $echecs;
}

/* ------------------------------------------------------------------ router */

$action = (string) ($_GET['action'] ?? '');

// Les actions qui modifient quelque chose n'acceptent que POST. Le jeton
// anti-CSRF les protège déjà, mais une action modifiante atteignable par une
// simple adresse peut être déclenchée par un aspirateur de liens, un
// antivirus qui préouvre les URL ou une préconnexion du navigateur.
const ACTIONS_MODIFIANTES = [
    'setup', 'login', 'logout', 'password_change',
    'ticket_create', 'ticket_update', 'ticket_claim', 'ticket_delete',
    'ticket_close_own', 'ticket_reopen_own', 'comment_add',
    'user_save', 'settings_save', 'mail_test', 'ldap_test',
    'procedure_save', 'procedure_delete', 'tickets_bulk', 'db_optimize',
];
if (in_array($action, ACTIONS_MODIFIANTES, true) && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    fail('Cette action doit être envoyée en POST.', 405);
}

switch ($action) {

/* ============================== Session ================================= */

case 'boot': {
    $u = current_user();
    if ($u) {
        // L'entretien ne doit jamais empêcher quelqu'un de se connecter.
        try { auto_close(); } catch (Throwable $e) { journal_erreur('auto_close : ' . $e->getMessage()); }
        try { purger_journaux(); } catch (Throwable $e) { }
        try { purger_anciens(); } catch (Throwable $e) { journal_erreur('purge : ' . $e->getMessage()); }
    }
    ok([
        'user'        => $u,
        'csrf'        => csrf_token(),
        'need_setup'  => users_count() === 0,
        'code_fichier' => users_count() === 0 ? (code_installation() ? 'data' . DIRECTORY_SEPARATOR . '.ht_installation.txt' : '') : '',
        'chemin'       => chemin_application(),
        'app_name'    => setting_get('app_name', 'D8 Support'),
        'categories'  => setting_list('categories'),
        'sites'       => setting_list('sites'),
        'templates'   => $u && is_staff($u) ? procedures_pour($u, true) : [],
        'procedures'  => $u ? count(procedures_pour($u)) : 0,
        'assignables' => $u && is_staff($u) ? staff_users() : [],
        'unread'      => $u ? unread_count($u) : 0,
        'stale_days'  => (int) setting_get('stale_days', '3'),
        'can_change_password' => $u ? ($u['auth'] !== 'annuaire'
                                        && (is_staff($u) || setting_get('allow_user_password') === '1')) : false,
        'annuaire_actif' => setting_get('ldap_enabled') === '1',
    ]);
}

case 'setup': {
    if (users_count() > 0) {
        fail("L'application est déjà configurée.", 403);
    }
    $b     = body();
    $name  = trim((string) ($b['name'] ?? ''));
    $email = trim((string) ($b['email'] ?? ''));
    $pass  = (string) ($b['password'] ?? '');
    $code  = strtoupper(trim((string) ($b['code'] ?? '')));

    $attendu = code_attendu();
    if ($attendu === '' || !hash_equals($attendu, $code)) {
        usleep(400000);
        fail("Code d'installation incorrect. Il se trouve dans le fichier "
             . 'data' . DIRECTORY_SEPARATOR . '.ht_installation.txt, sur le serveur.', 403);
    }

    if ($name === '' || len($name) < 2) {
        fail('Indiquez votre nom complet.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail('Adresse email invalide.');
    }
    if (len($pass) < 8) {
        fail('Le mot de passe doit contenir au moins 8 caractères.');
    }
    if ($raison = mot_de_passe_faible($pass, $name, $email)) {
        fail($raison);
    }

    db()->prepare("INSERT INTO users (name, login, email, password, role, active, created_at)
                   VALUES (?, ?, ?, ?, 'admin', 1, ?)")
        ->execute([$name, $email, $email, password_hash($pass, PASSWORD_DEFAULT), now()]);

    @unlink(CODE_INSTALL);   // le code ne sert qu'une fois

    session_regenerate_id(true);
    $_SESSION['uid']  = (int) db()->lastInsertId();
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
    ok(['user' => current_user(), 'csrf' => csrf_token()]);
}

case 'login': {
    $b     = body();
    $email = trim((string) ($b['email'] ?? ''));
    $pass  = (string) ($b['password'] ?? '');
    $ip    = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    // Le compteur de session se contourne en supprimant un cookie : on
    // s'appuie donc sur le journal, qui compte les échecs par poste.
    $fails = (int) ($_SESSION['login_fails'] ?? 0);
    $last  = (int) ($_SESSION['login_last'] ?? 0);
    if ($fails >= 8 && (time() - $last) < 300) {
        fail('Trop de tentatives. Patientez 5 minutes puis réessayez.', 429);
    }
    if ($ip !== '') {
        $st = db()->prepare('SELECT COUNT(*) FROM logins WHERE ip = ? AND success = 0 AND created_at > ?');
        $st->execute([$ip, date('Y-m-d H:i:s', time() - 300)]);
        if ((int) $st->fetchColumn() >= 10) {
            fail('Trop de tentatives depuis ce poste. Patientez 5 minutes puis réessayez.', 429);
        }
    }

    // On accepte l'identifiant de connexion comme l'adresse email : selon la
    // configuration, les gens tapent « m.lefevre » ou « m.lefevre@d8.fr ».
    $st = db()->prepare("SELECT * FROM users WHERE (login != '' AND login = ?) OR (email != '' AND email = ?)");
    $st->execute([$email, $email]);
    $u = $st->fetch();

    $journal = db()->prepare('INSERT INTO logins (user_id, email, success, ip, created_at) VALUES (?, ?, ?, ?, ?)');

    /* --- Connexion par l'annuaire de l'entreprise -----------------------
     * Le compte administrateur créé à l'installation reste local, pour que
     * l'outil demeure administrable si l'annuaire est indisponible. Les
     * autres se connectent avec leur identifiant Windows habituel ; leur
     * compte est créé à la première connexion, sans ressaisie.
     */
    $cfgAnnuaire = annuaire_config();
    $viaAnnuaire = $cfgAnnuaire['actif']
                   && (!$u || $u['auth'] === 'annuaire')
                   && ($u || $cfgAnnuaire['creer']);
    if ($viaAnnuaire) {
        // Si le compte est déjà connu, on interroge l'annuaire avec son
        // identifiant enregistré : la personne peut donc taper indifféremment
        // « m.lefevre » ou son adresse complète.
        $identifiantAnnuaire = ($u && $u['login'] !== '') ? $u['login'] : $email;
        $r = annuaire_verifier($identifiantAnnuaire, $pass, setting_list('sites'));
        if ($r['ok']) {
            if (!$u) {
                // L'identifiant tapé devient le login ; l'adresse vient de
                // l'annuaire, pour que les notifications partent au bon endroit.
                $adresse = filter_var($r['email'], FILTER_VALIDATE_EMAIL) ? $r['email']
                           : (filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '');
                db()->prepare("INSERT INTO users (name, login, email, password, phone, role, auth, active, created_at)
                               VALUES (?, ?, ?, '', ?, 'employe', 'annuaire', 1, ?)")
                    ->execute([$r['nom'] ?: $identifiantAnnuaire, $identifiantAnnuaire, $adresse, $r['telephone'], now()]);
                $st = db()->prepare('SELECT * FROM users WHERE login = ?');
                $st->execute([$identifiantAnnuaire]);
                $u = $st->fetch();
                journal_erreur('Compte créé depuis l\'annuaire : ' . $email . ' (' . ($r['site'] ?: 'site inconnu') . ')');
            } elseif ($r['nom'] !== '' && $r['nom'] !== $u['name']) {
                // Le nom a changé dans l'annuaire : on suit.
                db()->prepare('UPDATE users SET name = ?, phone = COALESCE(NULLIF(?, \'\'), phone) WHERE id = ?')
                    ->execute([$r['nom'], $r['telephone'], (int) $u['id']]);
            }
            if (!(int) $u['active']) {
                $journal->execute([(int) $u['id'], $email, 0, $ip, now()]);
                usleep(400000);
                fail('Email ou mot de passe incorrect.', 401);
            }
            $journal->execute([(int) $u['id'], $email, 1, $ip, now()]);
            unset($_SESSION['login_fails'], $_SESSION['login_last']);
            session_regenerate_id(true);
            $_SESSION['uid']  = (int) $u['id'];
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
            ok(['user' => current_user(), 'csrf' => csrf_token(), 'site_annuaire' => $r['site']]);
        }
        if ($u && $u['auth'] === 'annuaire') {
            // Ce compte n'a pas de mot de passe local : inutile d'aller plus loin.
            $journal->execute([(int) $u['id'], $email, 0, $ip, now()]);
            $_SESSION['login_fails'] = $fails + 1;
            $_SESSION['login_last']  = time();
            usleep(400000);
            fail('Email ou mot de passe incorrect.', 401);
        }
    }

    // Un compte inexistant, un mot de passe faux et un compte désactivé
    // donnent exactement la même réponse : sinon l'écran de connexion permet
    // de découvrir quelles adresses existent dans l'entreprise. Le journal,
    // lui, distingue les trois cas pour l'administrateur.
    $motDePasseOk = $u && password_verify($pass, $u['password']);
    if (!$u) {
        // Calcul factice pour que la réponse prenne le même temps qu'une
        // vérification réelle (sinon la durée trahit les adresses connues).
        password_verify($pass, '$2y$10$usesomesillystringforsalt0000000000000000000000000000000000');
    }
    if (!$motDePasseOk || !(int) $u['active']) {
        $journal->execute([$u ? (int) $u['id'] : null, $email, 0, $ip, now()]);
        $_SESSION['login_fails'] = $fails + 1;
        $_SESSION['login_last']  = time();
        usleep(400000);
        fail('Email ou mot de passe incorrect.', 401);
    }

    $journal->execute([(int) $u['id'], $email, 1, $ip, now()]);
    // Si le coût du hachage a changé (mise à jour de PHP), on remet le mot de
    // passe au goût du jour à l'occasion de cette connexion réussie.
    if (password_needs_rehash($u['password'], PASSWORD_DEFAULT)) {
        db()->prepare('UPDATE users SET password = ? WHERE id = ?')
            ->execute([password_hash($pass, PASSWORD_DEFAULT), (int) $u['id']]);
    }
    unset($_SESSION['login_fails'], $_SESSION['login_last']);
    session_regenerate_id(true);
    $_SESSION['uid']  = (int) $u['id'];
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
    ok(['user' => current_user(), 'csrf' => csrf_token()]);
}

case 'logout': {
    check_csrf();
    $_SESSION = [];
    session_destroy();
    ok();
}

case 'password_change': {
    $me = require_auth();
    check_csrf();
    // Les employés ne changent pas leur mot de passe sauf si l'administrateur
    // l'autorise dans les réglages : c'est lui qui le réinitialise.
    if ($me['auth'] === 'annuaire') {
        fail('Votre mot de passe est celui de votre session Windows : il se change '
             . 'directement sur votre poste, pas ici.', 403);
    }
    if (!is_staff($me) && setting_get('allow_user_password') !== '1') {
        fail("Le changement de mot de passe est réservé au service informatique. Contactez-le pour en obtenir un nouveau.", 403);
    }
    $b       = body();
    $current = (string) ($b['current'] ?? '');
    $new     = (string) ($b['new'] ?? '');

    $st = db()->prepare('SELECT password FROM users WHERE id = ?');
    $st->execute([(int) $me['id']]);
    $hash = (string) $st->fetchColumn();

    if (!password_verify($current, $hash)) {
        fail('Le mot de passe actuel est incorrect.');
    }
    if (len($new) < 8) {
        fail('Le nouveau mot de passe doit contenir au moins 8 caractères.');
    }
    if ($raison = mot_de_passe_faible($new, $me['name'], $me['email'])) {
        fail($raison);
    }
    db()->prepare('UPDATE users SET password = ? WHERE id = ?')
        ->execute([password_hash($new, PASSWORD_DEFAULT), (int) $me['id']]);
    ok();
}

/* ===================== Interrogation périodique ========================= */

case 'ping': {
    // Appelée toutes les 45 secondes par le navigateur : sert au compteur
    // du menu et au son de notification. Volontairement très légère.
    $me = current_user();
    if (!$me) {
        ok(['connected' => false]);
    }
    $ouvert = "status NOT IN ('resolu','ferme')";
    $data = ['connected' => true, 'unread' => unread_count($me), 'role' => $me['role']];
    if (is_staff($me)) {
        $data['open']       = (int) db()->query("SELECT COUNT(*) FROM tickets WHERE $ouvert")->fetchColumn();
        $data['unassigned'] = (int) db()->query("SELECT COUNT(*) FROM tickets WHERE assigned_to IS NULL AND $ouvert")->fetchColumn();
        $data['critical']   = (int) db()->query("SELECT COUNT(*) FROM tickets WHERE priority='critique' AND $ouvert")->fetchColumn();
    }
    ok($data);
}

/* ============================ Tableau de bord =========================== */

case 'dashboard': {
    $me = require_auth();
    require_role($me, ['admin']);

    $byStatus = [];
    foreach (db()->query('SELECT status, COUNT(*) c FROM tickets GROUP BY status') as $row) {
        $byStatus[$row['status']] = (int) $row['c'];
    }

    $openCond = "status NOT IN ('resolu','ferme')";
    $critical   = (int) db()->query("SELECT COUNT(*) FROM tickets WHERE priority='critique' AND $openCond")->fetchColumn();
    $unassigned = (int) db()->query("SELECT COUNT(*) FROM tickets WHERE assigned_to IS NULL AND $openCond")->fetchColumn();

    $st = db()->prepare("SELECT COUNT(*) FROM tickets WHERE assigned_to = ? AND $openCond");
    $st->execute([(int) $me['id']]);
    $mine = (int) $st->fetchColumn();

    $stale = (int) setting_get('stale_days', '3');
    // Référence de temps toujours prise côté PHP : l'horloge de SQLite suit le
    // fuseau du système, celle de PHP est fixée à Europe/Paris. Les faire
    // diverger décalait le repérage des tickets dormants de plusieurs heures.
    $maintenant = db()->quote(now());
    $dormants = (int) db()->query(
        "SELECT COUNT(*) FROM tickets WHERE $openCond
         AND julianday($maintenant) - julianday(updated_at) > $stale"
    )->fetchColumn();

    $bySite = [];
    foreach (db()->query("SELECT site, COUNT(*) c FROM tickets WHERE $openCond GROUP BY site ORDER BY c DESC") as $row) {
        $bySite[] = ['site' => $row['site'] !== '' ? $row['site'] : '(non précisé)', 'count' => (int) $row['c']];
    }

    $st = db()->prepare(
        "SELECT t.id, t.ref, t.title, t.status, t.priority, t.updated_at,
                c.name AS creator_name, a.name AS assignee_name,
                CASE WHEN v.seen_at IS NULL OR v.seen_at < t.updated_at THEN 1 ELSE 0 END AS unread
         FROM tickets t
         JOIN users c ON c.id = t.created_by
         LEFT JOIN users a ON a.id = t.assigned_to
         LEFT JOIN views v ON v.ticket_id = t.id AND v.user_id = ?
         ORDER BY t.updated_at DESC LIMIT 8"
    );
    $st->execute([(int) $me['id']]);

    ok([
        'by_status'  => $byStatus,
        'critical'   => $critical,
        'unassigned' => $unassigned,
        'mine'       => $mine,
        'stale'      => $dormants,
        'stale_days' => $stale,
        'by_site'    => $bySite,
        'recent'     => $st->fetchAll(),
        'charge_mois' => (int) db()->query(
            "SELECT COALESCE(SUM(time_spent), 0) FROM tickets
             WHERE strftime('%Y-%m', created_at) = '" . date('Y-m') . "'")->fetchColumn(),
        'nb_users'   => users_count(),
        'nb_tickets' => (int) db()->query('SELECT COUNT(*) FROM tickets')->fetchColumn(),
    ]);
}

/* ============================= Statistiques ============================= */

case 'stats': {
    $me = require_auth();
    require_role($me, ['admin']);

    $mois = db()->query(
        "SELECT strftime('%Y-%m', created_at) m, COUNT(*) c,
                SUM(CASE WHEN status IN ('resolu','ferme') THEN 1 ELSE 0 END) fermes
         FROM tickets GROUP BY m ORDER BY m DESC LIMIT 6"
    )->fetchAll();

    $delai = db()->query(
        "SELECT AVG(julianday(closed_at) - julianday(created_at)) d, COUNT(*) n
         FROM tickets WHERE closed_at IS NOT NULL"
    )->fetch();

    $parCat = db()->query(
        "SELECT category, COUNT(*) total,
                SUM(CASE WHEN status NOT IN ('resolu','ferme') THEN 1 ELSE 0 END) ouverts
         FROM tickets GROUP BY category ORDER BY total DESC"
    )->fetchAll();

    $parSite = db()->query(
        "SELECT site, COUNT(*) total FROM tickets GROUP BY site ORDER BY total DESC"
    )->fetchAll();

    $demandeurs = db()->query(
        "SELECT u.name, COUNT(*) total FROM tickets t JOIN users u ON u.id = t.created_by
         GROUP BY u.id ORDER BY total DESC LIMIT 8"
    )->fetchAll();

    $mnt = db()->quote(now());
    $ages = db()->query(
        "SELECT
           SUM(CASE WHEN julianday($mnt) - julianday(created_at) < 1 THEN 1 ELSE 0 END) j0,
           SUM(CASE WHEN julianday($mnt) - julianday(created_at) >= 1 AND julianday($mnt) - julianday(created_at) < 3 THEN 1 ELSE 0 END) j1,
           SUM(CASE WHEN julianday($mnt) - julianday(created_at) >= 3 AND julianday($mnt) - julianday(created_at) < 7 THEN 1 ELSE 0 END) j3,
           SUM(CASE WHEN julianday($mnt) - julianday(created_at) >= 7 THEN 1 ELSE 0 END) j7
         FROM tickets WHERE status NOT IN ('resolu','ferme')"
    )->fetch();

    $prio = db()->query(
        "SELECT priority, COUNT(*) c FROM tickets WHERE status NOT IN ('resolu','ferme') GROUP BY priority"
    )->fetchAll();

    ok([
        'par_mois'      => array_reverse($mois),
        'delai_moyen'   => $delai && $delai['n'] ? round((float) $delai['d'], 2) : null,
        'nb_clos'       => $delai ? (int) $delai['n'] : 0,
        'par_categorie' => $parCat,
        'par_site'      => $parSite,
        'demandeurs'    => $demandeurs,
        'ages'          => $ages ?: [],
        'par_priorite'  => $prio,
        'total'         => (int) db()->query('SELECT COUNT(*) FROM tickets')->fetchColumn(),
        'temps_total'   => (int) db()->query('SELECT COALESCE(SUM(time_spent), 0) FROM tickets')->fetchColumn(),
        'temps_par_mois' => db()->query(
            "SELECT strftime('%Y-%m', created_at) m, COALESCE(SUM(time_spent), 0) minutes
             FROM tickets GROUP BY m ORDER BY m DESC LIMIT 6")->fetchAll(),
    ]);
}

/* ================================ Tickets =============================== */

case 'tickets_list': {
    $me = require_auth();
    $b  = body();

    $where  = [];
    $params = [(int) $me['id']]; // pour la jointure « vues »

    if (!is_staff($me)) {
        $where[]  = 't.created_by = ?';
        $params[] = (int) $me['id'];
    }
    foreach (['status' => 't.status', 'priority' => 't.priority', 'category' => 't.category', 'site' => 't.site'] as $key => $col) {
        $v = trim((string) ($b[$key] ?? ''));
        if ($v !== '') {
            $where[]  = "$col = ?";
            $params[] = $v;
        }
    }
    $assigned = (string) ($b['assigned'] ?? '');
    if ($assigned === 'none') {
        $where[] = 't.assigned_to IS NULL';
    } elseif ($assigned !== '' && ctype_digit($assigned)) {
        $where[]  = 't.assigned_to = ?';
        $params[] = (int) $assigned;
    }
    if (!empty($b['open_only'])) {
        $where[] = "t.status NOT IN ('resolu','ferme')";
    }
    if (!empty($b['unread_only'])) {
        $where[] = "(v.seen_at IS NULL OR v.seen_at < t.updated_at) AND t.status != 'ferme'";
    }
    if (!empty($b['stale_only'])) {
        // Seuil inséré tel quel (entier vérifié) : passé en paramètre, PDO
        // l'enverrait comme texte et SQLite jugerait tout nombre inférieur.
        $seuil    = (int) setting_get('stale_days', '3');
        $where[]  = "t.status NOT IN ('resolu','ferme')
                     AND julianday(" . db()->quote(now()) . ") - julianday(t.updated_at) > " . $seuil;
    }
    $q = trim((string) ($b['q'] ?? ''));
    if ($q !== '') {
        // On cherche aussi dans les échanges : un mot cité dans une réponse
        // (« AlwaysUp », un numéro de série) doit permettre de retrouver le ticket.
        // Les notes internes ne sont fouillées que pour le service informatique.
        $filtreNotes = is_staff($me) ? '' : ' AND cm.is_internal = 0';
        // % et _ sont des jokers SQL : saisis dans la recherche, ils donnaient
        // des résultats incohérents. On les neutralise.
        // La comparaison se fait sans accents ni majuscules des deux côtés.
        $col     = search_available() ? 'sansaccent(%s)' : '%s';
        $where[] = '(' . sprintf($col, 't.ref') . " LIKE ? ESCAPE '\\'
                     OR " . sprintf($col, 't.title') . " LIKE ? ESCAPE '\\'
                     OR " . sprintf($col, 't.description') . " LIKE ? ESCAPE '\\'
                     OR EXISTS (SELECT 1 FROM comments cm WHERE cm.ticket_id = t.id
                                AND cm.is_system = 0" . $filtreNotes . ' AND '
                   . sprintf($col, 'cm.body') . " LIKE ? ESCAPE '\\'))";
        $motif   = search_available() ? sans_accent($q) : $q;
        $like    = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $motif) . '%';
        array_push($params, $like, $like, $like, $like);
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $jointure = "FROM tickets t
                 JOIN users c ON c.id = t.created_by
                 LEFT JOIN users a ON a.id = t.assigned_to
                 LEFT JOIN views v ON v.ticket_id = t.id AND v.user_id = ?";

    $st = db()->prepare("SELECT COUNT(*) $jointure $whereSql");
    $st->execute($params);
    $total = (int) $st->fetchColumn();

    $tri  = (string) ($b['sort'] ?? 'updated_at');
    $sens = strtolower((string) ($b['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
    if (!isset(TRIS[$tri])) {
        $tri = 'updated_at';
    }
    $ordre = TRIS[$tri] . ' ' . $sens;

    $perPage = 50;
    $page    = max(1, (int) ($b['page'] ?? 1));
    $offset  = ($page - 1) * $perPage;

    $st = db()->prepare(
        "SELECT t.id, t.ref, t.title, t.status, t.priority, t.category, t.site,
                t.created_at, t.updated_at,
                c.name AS creator_name, a.name AS assignee_name,
                CASE WHEN v.seen_at IS NULL OR v.seen_at < t.updated_at THEN 1 ELSE 0 END AS unread,
                CAST(julianday(" . db()->quote(now()) . ") - julianday(t.updated_at) AS INTEGER) AS age_jours
         $jointure $whereSql
         ORDER BY $ordre
         LIMIT $perPage OFFSET $offset"
    );
    $st->execute($params);

    ok([
        'rows'       => $st->fetchAll(),
        'total'      => $total,
        'page'       => $page,
        'per_page'   => $perPage,
        'sort'       => $tri,
        'dir'        => strtolower($sens),
        'stale_days' => (int) setting_get('stale_days', '3'),
    ]);
}

case 'ticket_get': {
    $me = require_auth();
    $id = (int) (body()['id'] ?? 0);

    $t = ticket_row($id);
    // Même réponse pour « n'existe pas » et « ne vous appartient pas » :
    // deux messages différents permettraient de deviner quels numéros de
    // tickets existent chez les collègues.
    if (!$t || (!is_staff($me) && (int) $t['created_by'] !== (int) $me['id'])) {
        fail('Ticket introuvable.', 404);
    }

    $sqlComments = 'SELECT cm.id, cm.body, cm.is_system, cm.is_internal, cm.created_at,
                           u.name AS user_name, u.role AS user_role
                    FROM comments cm JOIN users u ON u.id = cm.user_id
                    WHERE cm.ticket_id = ?';
    if (!is_staff($me)) {
        $sqlComments .= ' AND cm.is_internal = 0';
    }
    $sqlComments .= ' ORDER BY cm.created_at ASC, cm.id ASC';
    $st = db()->prepare($sqlComments);
    $st->execute([$id]);
    $comments = $st->fetchAll();

    $sqlAttach = 'SELECT a.id, a.comment_id, a.orig_name, a.mime, a.size, a.created_at
                  FROM attachments a LEFT JOIN comments cm ON cm.id = a.comment_id
                  WHERE a.ticket_id = ?';
    if (!is_staff($me)) {
        $sqlAttach .= ' AND (a.comment_id IS NULL OR cm.is_internal = 0)';
    }
    $sqlAttach .= ' ORDER BY a.id ASC';
    $st = db()->prepare($sqlAttach);
    $st->execute([$id]);
    $attachments = $st->fetchAll();

    mark_seen((int) $me['id'], $id);

    ok([
        'ticket'      => $t,
        'comments'    => $comments,
        'attachments' => $attachments,
        'assignables' => is_staff($me) ? assignables_pour($t['assigned_to'] ? (int) $t['assigned_to'] : null) : [],
        'templates'   => is_staff($me) ? procedures_pour($me, true) : [],
        'unread'      => unread_count($me),
    ]);
}

case 'ticket_create': {
    $me = require_auth();
    check_csrf();
    $b = body();

    $title    = trim((string) ($b['title'] ?? ''));
    $desc     = trim((string) ($b['description'] ?? ''));
    $category = trim((string) ($b['category'] ?? ''));
    $site     = trim((string) ($b['site'] ?? ''));
    $priority = (string) ($b['priority'] ?? 'normale');

    if (len($title) < 5) {
        fail('Le titre est trop court : décrivez le problème en quelques mots.');
    }
    if (len($desc) < 10) {
        fail("Décrivez le problème plus en détail (que faisiez-vous ? quel message s'affiche ?).");
    }
    if (len($title) > 150) {
        fail('Le titre est trop long (150 caractères maximum).');
    }
    if (len($desc) > 20000) {
        fail('La description est trop longue (20 000 caractères maximum). Mettez le détail en pièce jointe.');
    }
    if (!in_array($category, setting_list('categories'), true)) {
        fail('Choisissez une catégorie dans la liste.');
    }
    $sites = setting_list('sites');
    if ($sites && !in_array($site, $sites, true)) {
        fail('Choisissez votre site dans la liste.');
    }
    if (!in_array($priority, PRIORITIES, true)) {
        fail('Priorité invalide.');
    }

    $files = validate_uploads(MAX_FILES_TICKET);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO tickets (title, description, category, site, priority, status, created_by, created_at, updated_at)
                       VALUES (?, ?, ?, ?, ?, 'nouveau', ?, ?, ?)")
            ->execute([$title, $desc, $category, $site, $priority, (int) $me['id'], now(), now_us()]);
        $id  = (int) $pdo->lastInsertId();
        $ref = setting_get('ref_prefix', 'D8') . '-' . sprintf('%04d', $id);
        $pdo->prepare('UPDATE tickets SET ref = ? WHERE id = ?')->execute([$ref, $id]);
        sys_comment($id, (int) $me['id'], 'Ticket créé.');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        fail('Erreur lors de la création du ticket.', 500);
    }

    $echecsPj = persist_uploads($files, $id, null, (int) $me['id']);
    mark_seen((int) $me['id'], $id);

    $entete = "Référence : $ref\nCatégorie : $category\nSite : $site\nPriorité : " . priority_label($priority);

    // Le demandeur reçoit une copie de sa propre demande : il garde une trace
    // écrite et surtout le numéro de référence à citer.
    notify($me['email'], "[$ref] Votre demande a bien été enregistrée — " . $title,
        "Bonjour {$me['name']},\n\nVotre demande a bien été enregistrée par le service " .
        "informatique. Vous recevrez un email à chaque réponse.\n\n" . $entete .
        "\n\nCe que vous avez écrit :\n" . $desc, $id);

    // Le service informatique est prévenu en copie.
    foreach (staff_users() as $admin) {
        if ((int) $admin['id'] === (int) $me['id']) {
            continue;
        }
        notify($admin['email'], "[$ref] Nouvelle demande — " . $title,
            "Nouvelle demande de {$me['name']}" .
            ($me['phone'] ? ' (' . $me['phone'] . ')' : '') . ".\n\n" . $entete .
            "\n\n" . $desc, $id);
    }

    ok(['id' => $id, 'ref' => $ref,
        'avertissement' => $echecsPj
            ? 'Ticket créé, mais ces pièces jointes n\'ont pas pu être enregistrées : '
              . implode(', ', $echecsPj) . '. Renvoyez-les dans un message.'
            : null]);
}

case 'ticket_update': {
    $me = require_auth();
    require_role($me, ['admin']);
    check_csrf();
    $b  = body();
    $id = (int) ($b['id'] ?? 0);

    $t = ticket_row($id);
    if (!$t) {
        fail('Ticket introuvable.', 404);
    }

    // Deux administrateurs sur le même ticket : sans ce contrôle, celui qui
    // enregistre en second écrase silencieusement le travail du premier avec
    // les valeurs qu'affichait sa page, périmée depuis. On refuse plutôt que
    // de perdre une modification sans que personne ne s'en aperçoive.
    $version = (string) ($b['version'] ?? '');
    if ($version !== '' && $version !== (string) $t['updated_at']) {
        fail('Ce ticket a été modifié par quelqu\'un d\'autre pendant que vous le consultiez. '
             . 'La page va se recharger : vérifiez et refaites votre changement si besoin.', 409);
    }

    $changes = [];
    $sets    = [];
    $params  = [];
    $nouveauStatut = null;

    if (array_key_exists('status', $b)) {
        $new = (string) $b['status'];
        if (!in_array($new, STATUSES, true)) {
            fail('Statut invalide.');
        }
        if ($new !== $t['status']) {
            $sets[]    = 'status = ?';
            $params[]  = $new;
            $changes[] = 'Statut : ' . status_label($t['status']) . ' → ' . status_label($new);
            $nouveauStatut = $new;
            if ($new === 'ferme') {
                $sets[]   = 'closed_at = ?';
                $params[] = now();
            } elseif ($t['status'] === 'ferme') {
                $sets[] = 'closed_at = NULL';
            }
        }
    }
    if (array_key_exists('priority', $b)) {
        $new = (string) $b['priority'];
        if (!in_array($new, PRIORITIES, true)) {
            fail('Priorité invalide.');
        }
        if ($new !== $t['priority']) {
            $sets[]    = 'priority = ?';
            $params[]  = $new;
            $changes[] = 'Priorité : ' . priority_label($t['priority']) . ' → ' . priority_label($new);
        }
    }
    if (array_key_exists('assigned_to', $b)) {
        $raw = $b['assigned_to'];
        $new = ($raw === '' || $raw === null) ? null : (int) $raw;
        $assigneeName = '';
        if ($new !== null) {
            // On accepte un administrateur actif, ou la personne déjà assignée
            // au ticket (cas d'un compte rétrogradé entre-temps).
            $st = db()->prepare("SELECT name FROM users WHERE id = ?
                                 AND (role = 'admin' AND active = 1 OR id = ?)");
            $st->execute([$new, $t['assigned_to'] !== null ? (int) $t['assigned_to'] : 0]);
            $assigneeName = $st->fetchColumn();
            if ($assigneeName === false) {
                fail('Destinataire invalide.');
            }
        }
        $old = $t['assigned_to'] !== null ? (int) $t['assigned_to'] : null;
        if ($new !== $old) {
            $sets[]    = 'assigned_to = ?';
            $params[]  = $new;
            $changes[] = $new === null
                ? 'Assignation retirée (ticket non assigné).'
                : 'Assigné à ' . $assigneeName . '.';
        }
    }
    if (array_key_exists('category', $b)) {
        $new = trim((string) $b['category']);
        if ($new !== $t['category']) {
            $sets[]    = 'category = ?';
            $params[]  = $new;
            $changes[] = 'Catégorie : ' . $t['category'] . ' → ' . $new;
        }
    }
    if (array_key_exists('site', $b)) {
        $new = trim((string) $b['site']);
        if ($new !== $t['site']) {
            $sets[]    = 'site = ?';
            $params[]  = $new;
            $changes[] = 'Site : ' . ($t['site'] ?: '—') . ' → ' . ($new ?: '—');
        }
    }
    if (array_key_exists('title', $b)) {
        $new = trim((string) $b['title']);
        if (len($new) < 5) {
            fail('Le titre est trop court.');
        }
        if ($new !== $t['title']) {
            $sets[]    = 'title = ?';
            $params[]  = $new;
            $changes[] = 'Titre modifié.';
        }
    }

    // Temps passé : additionné, jamais remplacé, pour que le cumul reflète
    // l'ensemble des interventions sur le ticket.
    $minutes = (int) ($b['time_add'] ?? 0);
    if ($minutes > 0) {
        if ($minutes > 24 * 60) {
            fail('Durée invalide (24 heures maximum en une saisie).');
        }
        db()->prepare('UPDATE tickets SET time_spent = time_spent + ? WHERE id = ?')
            ->execute([$minutes, $id]);
        $changes[] = 'Temps passé : +' . ($minutes >= 60
            ? intdiv($minutes, 60) . ' h ' . ($minutes % 60 ? ($minutes % 60) . ' min' : '')
            : $minutes . ' min');
        $sets[] = 'updated_at = ?';   // au moins un champ pour déclencher l'écriture
        $params[] = now_us();
        $params[] = $id;
        db()->prepare('UPDATE tickets SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
        sys_comment($id, (int) $me['id'], implode("\n", $changes));
        mark_seen((int) $me['id'], $id);
        ok(['changed' => true]);
    }

    if (!$sets) {
        ok(['changed' => false]);
    }

    $sets[]   = 'updated_at = ?';
    $params[] = now_us();
    $params[] = $id;
    db()->prepare('UPDATE tickets SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);

    sys_comment($id, (int) $me['id'], implode("\n", $changes));
    mark_seen((int) $me['id'], $id);

    if ($nouveauStatut === 'resolu') {
        notify($t['creator_email'], "[{$t['ref']}] Votre demande est résolue — " . $t['title'],
            "Bonjour {$t['creator_name']},\n\nVotre demande « {$t['title']} » vient d'être marquée comme résolue " .
            "par le service informatique.\n\nMerci de vérifier que tout fonctionne, puis de confirmer la fermeture " .
            "depuis l'outil. Si le problème persiste, vous pouvez rouvrir le ticket.", $id);
    }

    ok(['changed' => true]);
}

case 'ticket_claim': {
    // Prise en charge en un clic : s'assigner le ticket et le passer en cours.
    $me = require_auth();
    require_role($me, ['admin']);
    check_csrf();
    $id = (int) (body()['id'] ?? 0);
    $t  = ticket_row($id);
    if (!$t) {
        fail('Ticket introuvable.', 404);
    }
    $statut = $t['status'] === 'nouveau' ? 'en_cours' : $t['status'];
    db()->prepare('UPDATE tickets SET assigned_to = ?, status = ?, updated_at = ? WHERE id = ?')
        ->execute([(int) $me['id'], $statut, now_us(), $id]);
    sys_comment($id, (int) $me['id'], $me['name'] . ' a pris le ticket en charge.');
    mark_seen((int) $me['id'], $id);
    ok();
}

case 'ticket_close_own': {
    $me = require_auth();
    check_csrf();
    $id = (int) (body()['id'] ?? 0);
    $t  = ticket_row($id);
    if (!$t || ((int) $t['created_by'] !== (int) $me['id'] && !is_staff($me))) {
        fail('Ticket introuvable.', 404);
    }
    if ($t['status'] !== 'resolu') {
        fail("Ce ticket ne peut être fermé que lorsqu'il est marqué « Résolu ».");
    }
    db()->prepare("UPDATE tickets SET status = 'ferme', closed_at = ?, updated_at = ? WHERE id = ?")
        ->execute([now(), now_us(), $id]);
    sys_comment($id, (int) $me['id'], 'Le demandeur a confirmé la résolution. Ticket fermé.');
    mark_seen((int) $me['id'], $id);
    ok();
}

case 'ticket_reopen_own': {
    $me = require_auth();
    check_csrf();
    $b      = body();
    $id     = (int) ($b['id'] ?? 0);
    $reason = trim((string) ($b['reason'] ?? ''));

    $t = ticket_row($id);
    if (!$t || ((int) $t['created_by'] !== (int) $me['id'] && !is_staff($me))) {
        fail('Ticket introuvable.', 404);
    }
    if (!in_array($t['status'], ['resolu', 'ferme'], true)) {
        fail('Ce ticket est déjà ouvert.');
    }

    $newStatus = $t['assigned_to'] !== null ? 'en_cours' : 'nouveau';
    db()->prepare('UPDATE tickets SET status = ?, closed_at = NULL, updated_at = ? WHERE id = ?')
        ->execute([$newStatus, now_us(), $id]);
    if ($reason !== '') {
        db()->prepare('INSERT INTO comments (ticket_id, user_id, body, is_system, is_internal, created_at)
                       VALUES (?, ?, ?, 0, 0, ?)')
            ->execute([$id, (int) $me['id'], $reason, now()]);
    }
    sys_comment($id, (int) $me['id'], 'Le demandeur a rouvert le ticket (problème non résolu).');
    mark_seen((int) $me['id'], $id);

    foreach (staff_users() as $admin) {
        notify($admin['email'], "[{$t['ref']}] Ticket rouvert — " . $t['title'],
            "{$me['name']} a rouvert le ticket « {$t['title']} ».\n\n" . ($reason ?: '(sans commentaire)'), $id);
    }
    ok();
}

case 'ticket_delete': {
    $me = require_auth();
    require_role($me, ['admin']);
    check_csrf();
    $id = (int) (body()['id'] ?? 0);
    $t  = ticket_row($id);
    if (!$t) {
        fail('Ticket introuvable.', 404);
    }
    $st = db()->prepare('SELECT stored_name FROM attachments WHERE ticket_id = ?');
    $st->execute([$id]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $stored) {
        @unlink(UPLOAD_DIR . '/' . $stored);
    }
    db()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
    ok();
}

case 'tickets_bulk': {
    /* Traitement par lot. Quand vingt tickets s'accumulent après un week-end,
     * les ouvrir un par un pour changer un statut coûte un quart d'heure.
     * Chaque ticket reste journalisé individuellement : l'historique ne perd
     * rien à ce que l'action ait été groupée. */
    $me = require_auth();
    require_role($me, ['admin']);
    check_csrf();
    $b   = body();
    $ids = array_values(array_unique(array_map('intval', (array) ($b['ids'] ?? []))));
    $ids = array_filter($ids, fn($i) => $i > 0);

    if (!$ids) {
        fail('Sélectionnez au moins un ticket.');
    }
    if (count($ids) > 200) {
        fail('200 tickets au maximum par opération.');
    }

    $statut = array_key_exists('status', $b) ? (string) $b['status'] : null;
    $prio   = array_key_exists('priority', $b) ? (string) $b['priority'] : null;
    $assign = array_key_exists('assigned_to', $b) ? $b['assigned_to'] : null;

    if ($statut !== null && $statut !== '' && !in_array($statut, STATUSES, true)) {
        fail('Statut invalide.');
    }
    if ($prio !== null && $prio !== '' && !in_array($prio, PRIORITIES, true)) {
        fail('Priorité invalide.');
    }
    $nomAssigne = '';
    $idAssigne  = null;
    if ($assign !== null && $assign !== '' && $assign !== 'aucun') {
        $st = db()->prepare("SELECT name FROM users WHERE id = ? AND role = 'admin' AND active = 1");
        $st->execute([(int) $assign]);
        $nomAssigne = $st->fetchColumn();
        if ($nomAssigne === false) {
            fail('Destinataire invalide.');
        }
        $idAssigne = (int) $assign;
    }
    if (($statut === null || $statut === '') && ($prio === null || $prio === '') && $assign === null) {
        fail('Choisissez au moins une modification à appliquer.');
    }

    $traites = 0;
    $ignores = 0;
    foreach ($ids as $id) {
        $t = ticket_row($id);
        if (!$t) { $ignores++; continue; }

        $sets = [];
        $params = [];
        $changes = [];

        if ($statut !== null && $statut !== '' && $statut !== $t['status']) {
            $sets[] = 'status = ?';       $params[] = $statut;
            $changes[] = 'Statut : ' . status_label($t['status']) . ' → ' . status_label($statut);
            if ($statut === 'ferme') { $sets[] = 'closed_at = ?'; $params[] = now(); }
            elseif ($t['status'] === 'ferme') { $sets[] = 'closed_at = NULL'; }
        }
        if ($prio !== null && $prio !== '' && $prio !== $t['priority']) {
            $sets[] = 'priority = ?';     $params[] = $prio;
            $changes[] = 'Priorité : ' . priority_label($t['priority']) . ' → ' . priority_label($prio);
        }
        if ($assign !== null) {
            $ancien = $t['assigned_to'] !== null ? (int) $t['assigned_to'] : null;
            if ($idAssigne !== $ancien) {
                $sets[] = 'assigned_to = ?'; $params[] = $idAssigne;
                $changes[] = $idAssigne === null ? 'Assignation retirée (ticket non assigné).'
                                                 : 'Assigné à ' . $nomAssigne . '.';
            }
        }
        if (!$sets) { $ignores++; continue; }

        $sets[] = 'updated_at = ?'; $params[] = now_us(); $params[] = $id;
        db()->prepare('UPDATE tickets SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
        sys_comment($id, (int) $me['id'], implode("\n", $changes) . "\n(modification groupée)");
        mark_seen((int) $me['id'], $id);
        $traites++;

        if ($statut === 'resolu' && $t['status'] !== 'resolu') {
            notify($t['creator_email'], '[' . $t['ref'] . '] Votre demande est résolue — ' . $t['title'],
                "Bonjour {$t['creator_name']},\n\nVotre demande « {$t['title']} » vient d'être marquée "
                . "comme résolue. Merci de vérifier que tout fonctionne, puis de confirmer la fermeture "
                . 'depuis l\'outil.', $id);
        }
    }
    ok(['traites' => $traites, 'ignores' => $ignores]);
}

case 'db_optimize': {
    // Après des suppressions ou une purge, le fichier de base ne rend pas
    // l'espace tout seul. Un compactage manuel évite qu'il gonfle sans fin.
    $me = require_auth();
    require_role($me, ['admin']);
    check_csrf();
    $avant = is_file(DB_PATH) ? filesize(DB_PATH) : 0;
    try {
        db()->exec('VACUUM');
    } catch (Throwable $e) {
        fail('Compactage impossible : ' . $e->getMessage(), 500);
    }
    clearstatcache(true, DB_PATH);
    $apres = is_file(DB_PATH) ? filesize(DB_PATH) : 0;
    ok(['avant' => $avant, 'apres' => $apres, 'gagne' => max(0, $avant - $apres)]);
}

/* ============================ Commentaires ============================== */

case 'comment_add': {
    $me = require_auth();
    check_csrf();
    $b        = body();
    $ticketId = (int) ($b['ticket_id'] ?? 0);
    $text     = trim((string) ($b['body'] ?? ''));
    $internal = is_staff($me) && !empty($b['is_internal']) ? 1 : 0;

    $t = ticket_row($ticketId);
    if (!$t || (!is_staff($me) && (int) $t['created_by'] !== (int) $me['id'])) {
        fail('Ticket introuvable.', 404);
    }
    if ($text === '') {
        fail("Écrivez un message avant d'envoyer.");
    }
    if (len($text) > 20000) {
        fail('Le message est trop long (20 000 caractères maximum). Mettez le détail en pièce jointe.');
    }

    $files = validate_uploads(MAX_FILES_COMMENT);

    // Un demandeur qui écrit sur un ticket résolu ou fermé signale que le
    // problème n'est pas réglé. Sans réouverture, son message ne déclenchait
    // aucune notification et restait sans réponse.
    $rouvert = false;
    if (!is_staff($me) && in_array($t['status'], ['resolu', 'ferme'], true)) {
        $nouveau = $t['assigned_to'] !== null ? 'en_cours' : 'nouveau';
        db()->prepare('UPDATE tickets SET status = ?, closed_at = NULL WHERE id = ?')
            ->execute([$nouveau, $ticketId]);
        $rouvert = true;
    }

    db()->prepare('INSERT INTO comments (ticket_id, user_id, body, is_system, is_internal, created_at)
                   VALUES (?, ?, ?, 0, ?, ?)')
        ->execute([$ticketId, (int) $me['id'], $text, $internal, now()]);
    $commentId = (int) db()->lastInsertId();

    $echecsPj = persist_uploads($files, $ticketId, $commentId, (int) $me['id']);
    if ($rouvert) {
        sys_comment($ticketId, (int) $me['id'],
            'Ticket rouvert automatiquement : le demandeur a écrit après la clôture.');
    }
    touch_ticket($ticketId);
    mark_seen((int) $me['id'], $ticketId);

    if (!$internal) {
        if (is_staff($me)) {
            notify($t['creator_email'], "[{$t['ref']}] Réponse du service informatique — " . $t['title'],
                "Bonjour {$t['creator_name']},\n\n{$me['name']} a répondu à votre demande :\n\n" . $text, $ticketId);
        } else {
            foreach (staff_users() as $admin) {
                notify($admin['email'],
                    '[' . $t['ref'] . ']' . ($rouvert ? ' Ticket rouvert — ' : ' Nouveau message — ') . $t['title'],
                    $me['name'] . ($rouvert ? ' a écrit après la clôture du ticket' : ' a écrit sur le ticket')
                    . ' « ' . $t['title'] . " » :\n\n" . $text, $ticketId);
            }
        }
    }

    ok(['id' => $commentId,
        'rouvert' => $rouvert,
        'avertissement' => $echecsPj
            ? 'Message envoyé, mais ces pièces jointes ont échoué : ' . implode(', ', $echecsPj) . '.'
            : null]);
}

/* ============================ Pièces jointes ============================ */

case 'attachment_get': {
    $me = require_auth();
    $id = (int) ($_GET['id'] ?? 0);

    $st = db()->prepare(
        'SELECT a.*, t.created_by, cm.is_internal
         FROM attachments a
         JOIN tickets t ON t.id = a.ticket_id
         LEFT JOIN comments cm ON cm.id = a.comment_id
         WHERE a.id = ?'
    );
    $st->execute([$id]);
    $a = $st->fetch();
    if (!$a) {
        fail('Pièce jointe introuvable.', 404);
    }
    if (!is_staff($me)) {
        if ((int) $a['created_by'] !== (int) $me['id'] || (int) ($a['is_internal'] ?? 0) === 1) {
            fail('Pièce jointe introuvable.', 404);
        }
    }
    $path = UPLOAD_DIR . '/' . $a['stored_name'];
    if (!is_file($path)) {
        fail('Fichier manquant sur le serveur.', 404);
    }

    // On vide le tampon avant de servir un binaire : sinon un PDF de 5 Mo
    // est intégralement chargé en mémoire avant d'être envoyé.
    while (ob_get_level() > 0) { ob_end_clean(); }

    $ext      = pathinfo($a['stored_name'], PATHINFO_EXTENSION);
    $fallback = 'piece-jointe-' . (int) $a['id'] . '.' . $ext;
    header('Content-Type: ' . $a['mime']);
    header('X-Content-Type-Options: nosniff');
    // Un PDF peut embarquer du script : on le sert dans un bac à sable.
    header('Content-Security-Policy: sandbox; default-src \'none\'');
    header('X-Frame-Options: SAMEORIGIN');
    header('Content-Length: ' . (string) filesize($path));
    header('Content-Disposition: inline; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($a['orig_name']));
    readfile($path);
    exit;
}

/* ============================= Utilisateurs ============================= */

case 'users_list': {
    $me = require_auth();
    require_role($me, ['admin']);
    $rows = db()->query(
        'SELECT u.id, u.name, u.login, u.email, u.role, u.phone, u.auth, u.active, u.created_at,
                (SELECT COUNT(*) FROM tickets t WHERE t.created_by = u.id) AS ticket_count,
                (SELECT MAX(created_at) FROM logins l WHERE l.user_id = u.id AND l.success = 1) AS last_login
         FROM users u ORDER BY u.active DESC, u.name COLLATE NOCASE'
    )->fetchAll();
    ok($rows);
}

case 'user_save': {
    $me = require_auth();
    require_role($me, ['admin']);
    check_csrf();
    $b = body();

    $id     = (int) ($b['id'] ?? 0);
    $name   = trim((string) ($b['name'] ?? ''));
    $email  = trim((string) ($b['email'] ?? ''));
    $phone  = trim((string) ($b['phone'] ?? ''));
    $role   = (string) ($b['role'] ?? 'employe');
    $active = !empty($b['active']) ? 1 : 0;
    $pass   = (string) ($b['password'] ?? '');

    if (len($name) < 2) {
        fail('Indiquez le nom complet.');
    }
    if (!in_array($role, ROLES, true)) {
        fail('Rôle invalide.');
    }

    // Un compte venu de l'annuaire se connecte avec son identifiant Windows :
    // son adresse email peut être absente, il ne faut pas l'exiger.
    $venuDeLAnnuaire = false;
    if ($id > 0) {
        $st = db()->prepare('SELECT auth FROM users WHERE id = ?');
        $st->execute([$id]);
        $venuDeLAnnuaire = $st->fetchColumn() === 'annuaire';
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail('Adresse email invalide.');
    }
    if ($email === '' && !$venuDeLAnnuaire) {
        fail('Indiquez une adresse email.');
    }

    if ($email !== '') {
        $st = db()->prepare("SELECT id FROM users WHERE email = ? AND email != '' AND id != ?");
        $st->execute([$email, $id]);
        if ($st->fetch()) {
            fail('Cette adresse email est déjà utilisée par un autre compte.');
        }
    }

    if ($id > 0) {
        $st = db()->prepare('SELECT role, active FROM users WHERE id = ?');
        $st->execute([$id]);
        $target = $st->fetch();
        if (!$target) {
            fail('Utilisateur introuvable.', 404);
        }
        $etaitAdmin  = $target['role'] === 'admin' && (int) $target['active'] === 1;
        $resteAdmin  = $role === 'admin' && $active === 1;
        if ($etaitAdmin && !$resteAdmin) {
            $count = (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1")->fetchColumn();
            if ($count <= 1) {
                fail('Impossible : il doit toujours rester au moins un administrateur actif.');
            }
        }
        if ($id === (int) $me['id'] && !$active) {
            fail('Vous ne pouvez pas désactiver votre propre compte.');
        }
    }

    if ($id > 0) {
        if ($pass !== '') {
            if (len($pass) < 8) {
                fail('Le mot de passe doit contenir au moins 8 caractères.');
            }
            if ($raison = mot_de_passe_faible($pass, $name, $email)) {
                fail($raison);
            }
            if ($venuDeLAnnuaire) {
                fail('Ce compte se connecte avec le mot de passe de la session Windows : '
                     . 'il se change dans Active Directory, pas ici.');
            }
            db()->prepare('UPDATE users SET name=?, email=?, login=?, phone=?, role=?, active=?, password=? WHERE id=?')
                ->execute([$name, $email, $email, $phone, $role, $active, password_hash($pass, PASSWORD_DEFAULT), $id]);
        } elseif ($venuDeLAnnuaire) {
            // On ne touche ni au login ni au mode d'authentification.
            db()->prepare('UPDATE users SET name=?, email=?, phone=?, role=?, active=? WHERE id=?')
                ->execute([$name, $email, $phone, $role, $active, $id]);
        } else {
            db()->prepare('UPDATE users SET name=?, email=?, login=?, phone=?, role=?, active=? WHERE id=?')
                ->execute([$name, $email, $email, $phone, $role, $active, $id]);
        }
        ok(['id' => $id]);
    }

    if (len($pass) < 8) {
        fail('Le mot de passe doit contenir au moins 8 caractères.');
    }
    if ($raison = mot_de_passe_faible($pass, $name, $email)) {
        fail($raison);
    }
    db()->prepare('INSERT INTO users (name, login, email, password, phone, role, active, created_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$name, $email, $email, password_hash($pass, PASSWORD_DEFAULT), $phone, $role, $active, now()]);
    ok(['id' => (int) db()->lastInsertId()]);
}

case 'logins_list': {
    $me = require_auth();
    require_role($me, ['admin']);
    $rows = db()->query(
        'SELECT l.id, l.email, l.success, l.ip, l.created_at, u.name
         FROM logins l LEFT JOIN users u ON u.id = l.user_id
         ORDER BY l.id DESC LIMIT 100'
    )->fetchAll();
    ok($rows);
}

/* =============================== Réglages =============================== */

case 'settings_get': {
    $me = require_auth();
    require_role($me, ['admin']);
    ok([
        'app_name'            => setting_get('app_name', 'D8 Support'),
        'ref_prefix'          => setting_get('ref_prefix', 'D8'),
        'categories'          => setting_list('categories'),
        'sites'               => setting_list('sites'),
        'stale_days'          => setting_get('stale_days', '3'),
        'auto_close_days'     => setting_get('auto_close_days', '7'),
        'idle_minutes'        => setting_get('idle_minutes', '0'),
        'ldap_disponible'     => annuaire_disponible(),
        'ldap_enabled'        => setting_get('ldap_enabled', '0'),
        'ldap_host'           => setting_get('ldap_host'),
        'ldap_port'           => setting_get('ldap_port', '389'),
        'ldap_secure'         => setting_get('ldap_secure', 'none'),
        'ldap_bind_format'    => setting_get('ldap_bind_format'),
        'ldap_base_dn'        => setting_get('ldap_base_dn'),
        'ldap_login_attr'     => setting_get('ldap_login_attr', 'userPrincipalName'),
        'ldap_autocreate'     => setting_get('ldap_autocreate', '1'),
        'purge_months'        => setting_get('purge_months', '0'),
        'allow_user_password' => setting_get('allow_user_password', '0'),
        'mail_enabled'        => setting_get('mail_enabled', '0'),
        'mail_host'           => setting_get('mail_host'),
        'mail_port'           => setting_get('mail_port', '587'),
        'mail_secure'         => setting_get('mail_secure', 'tls'),
        'mail_user'           => setting_get('mail_user'),
        'mail_pass'           => setting_get('mail_pass') === '' ? '' : '********',
        'mail_from'           => setting_get('mail_from'),
        'mail_from_name'      => setting_get('mail_from_name', 'Support informatique'),
        'base_url'            => setting_get('base_url'),
    ]);
}

case 'settings_save': {
    $me = require_auth();
    require_role($me, ['admin']);
    check_csrf();
    $b = body();

    foreach (['categories', 'sites'] as $key) {
        if (!array_key_exists($key, $b)) {
            continue;
        }
        $values = is_array($b[$key]) ? $b[$key] : [];
        $clean  = [];
        foreach ($values as $v) {
            $v = trim((string) $v);
            if ($v !== '' && !in_array($v, $clean, true)) {
                $clean[] = $v;
            }
        }
        if (!$clean) {
            fail('La liste « ' . ($key === 'categories' ? 'catégories' : 'sites') . ' » ne peut pas être vide.');
        }
        setting_save_list($key, $clean);
    }

    $simples = ['app_name', 'ref_prefix', 'stale_days', 'auto_close_days', 'allow_user_password', 'idle_minutes', 'purge_months',
                'ldap_enabled', 'ldap_host', 'ldap_port', 'ldap_secure',
                'ldap_bind_format', 'ldap_base_dn', 'ldap_login_attr', 'ldap_autocreate',
                'mail_enabled', 'mail_host', 'mail_port', 'mail_secure', 'mail_user',
                'mail_from', 'mail_from_name', 'base_url'];
    foreach ($simples as $key) {
        if (!array_key_exists($key, $b)) {
            continue;
        }
        $v = trim((string) $b[$key]);
        // Un saut de ligne dans une valeur reprise en en-tête d'email
        // permettrait d'ajouter un destinataire caché.
        if (in_array($key, ['mail_from', 'mail_from_name', 'mail_host', 'mail_user', 'app_name', 'base_url',
                            'ldap_host', 'ldap_bind_format', 'ldap_base_dn', 'ldap_login_attr'], true)) {
            $v = str_replace(["\r", "\n", "\0"], '', $v);
        }
        if ($key === 'ref_prefix') {
            $v = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $v) ?: 'D8');
        }
        if (in_array($key, ['auto_close_days', 'mail_port', 'idle_minutes', 'purge_months', 'ldap_port'], true)) {
            $v = (string) max(0, (int) $v);
        }
        if ($key === 'stale_days') {
            // À 0, tous les tickets seraient signalés comme dormants.
            $v = (string) min(365, max(1, (int) $v));
        }
        if ($key === 'app_name' && $v === '') {
            $v = 'D8 Support';
        }
        setting_set($key, $v);
    }
    // Le mot de passe SMTP n'est réécrit que s'il a été modifié.
    if (array_key_exists('mail_pass', $b)) {
        $mp = (string) $b['mail_pass'];
        // « ******** » signifie « ne pas toucher » ; une chaîne vide efface
        // réellement le mot de passe (serveur SMTP sans authentification).
        if ($mp !== '********') {
            setting_set('mail_pass', $mp);
        }
    }

    ok(['categories' => setting_list('categories'), 'sites' => setting_list('sites'),
        'app_name' => setting_get('app_name')]);
}

case 'mail_test': {
    $me = require_auth();
    require_role($me, ['admin']);
    check_csrf();
    $dest = trim((string) (body()['to'] ?? $me['email']));
    if (!filter_var($dest, FILTER_VALIDATE_EMAIL)) {
        fail('Adresse de test invalide.');
    }
    session_write_close();   // ne bloque pas les autres requêtes de l'administrateur
    $r = smtp_envoyer(mail_config(), $dest,
        'Test — ' . setting_get('app_name', 'D8 Support'),
        "Ceci est un message de test envoyé depuis l'outil de tickets.\n\nSi vous le recevez, la configuration est correcte.");
    if (!$r['ok']) {
        fail("Échec de l'envoi : " . $r['error']);
    }
    ok(['trace' => $r['trace']]);
}

case 'ldap_test': {
    $me = require_auth();
    require_role($me, ['admin']);
    check_csrf();
    $b = body();
    $identifiant = trim((string) ($b['login'] ?? ''));
    $motdepasse  = (string) ($b['password'] ?? '');
    if ($identifiant === '' || $motdepasse === '') {
        fail('Indiquez un identifiant et un mot de passe existant dans l\'annuaire pour le test.');
    }
    session_write_close();
    $r = annuaire_verifier($identifiant, $motdepasse, setting_list('sites'));
    ok([
        'reussi'    => $r['ok'],
        'erreur'    => $r['error'],
        'nom'       => $r['nom'],
        'site'      => $r['site'],
        'email'     => $r['email'],
        'telephone' => $r['telephone'],
        'trace'     => $r['trace'],
    ]);
}

/* ============================== Procédures ============================== */

case 'procedures_list': {
    $me = require_auth();
    ok(procedures_pour($me));
}

case 'procedure_save': {
    $me = require_auth();
    require_role($me, ['admin']);
    check_csrf();
    $b = body();
    $id     = (int) ($b['id'] ?? 0);
    $titre  = trim((string) ($b['title'] ?? ''));
    $corps  = trim((string) ($b['body'] ?? ''));
    $cat    = trim((string) ($b['category'] ?? ''));
    $public = !empty($b['public']) ? 1 : 0;
    $modele = !empty($b['modele']) ? 1 : 0;

    if (len($titre) < 3) {
        fail('Donnez un titre à la procédure.');
    }
    if (len($corps) < 10) {
        fail('Le contenu de la procédure est trop court.');
    }
    if (len($corps) > 20000) {
        fail('Le contenu est trop long (20 000 caractères maximum).');
    }
    if (!$public && !$modele) {
        fail('Une fiche doit être au moins visible par le personnel ou proposée comme réponse type.');
    }

    if ($id > 0) {
        db()->prepare('UPDATE procedures SET title=?, body=?, category=?, public=?, modele=?,
                       updated_at=?, updated_by=? WHERE id=?')
            ->execute([$titre, $corps, $cat, $public, $modele, now(), (int) $me['id'], $id]);
    } else {
        $rang = (int) db()->query('SELECT COALESCE(MAX(position), 0) + 1 FROM procedures')->fetchColumn();
        db()->prepare('INSERT INTO procedures (title, body, category, public, modele, position, updated_at, updated_by)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$titre, $corps, $cat, $public, $modele, $rang, now(), (int) $me['id']]);
        $id = (int) db()->lastInsertId();
    }
    ok(['id' => $id]);
}

case 'procedure_delete': {
    $me = require_auth();
    require_role($me, ['admin']);
    check_csrf();
    $id = (int) (body()['id'] ?? 0);
    db()->prepare('DELETE FROM procedures WHERE id = ?')->execute([$id]);
    ok();
}

/* ============================== Journaux =============================== */

case 'logs_errors': {
    $me = require_auth();
    require_role($me, ['admin']);
    $fichier = DB_DIR . '/.ht_erreurs.log';
    $lignes = [];
    if (is_file($fichier)) {
        $tout = @file($fichier, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $lignes = array_slice(array_reverse($tout), 0, 200);
    }
    ok(['lignes' => $lignes, 'taille' => is_file($fichier) ? filesize($fichier) : 0]);
}

/* ============================= Informations ============================ */

case 'system_info': {
    $me = require_auth();
    require_role($me, ['admin']);

    $tailleUploads = 0;
    $nbFichiers = 0;
    foreach (glob(UPLOAD_DIR . '/.ht_*') ?: [] as $f) {
        if (is_file($f)) { $tailleUploads += filesize($f); $nbFichiers++; }
    }
    $libre = @disk_free_space(DB_DIR);

    ok([
        'app_name'    => setting_get('app_name', 'D8 Support'),
        'php'         => PHP_VERSION,
        'serveur'     => $_SERVER['SERVER_SOFTWARE'] ?? 'inconnu',
        'sqlite'      => db()->query('SELECT sqlite_version()')->fetchColumn(),
        'extensions'  => [
            'pdo_sqlite' => extension_loaded('pdo_sqlite'),
            'fileinfo'   => extension_loaded('fileinfo'),
            'zip'        => extension_loaded('zip'),
            'ldap'       => extension_loaded('ldap'),
            'openssl'    => extension_loaded('openssl'),
            'mbstring'   => extension_loaded('mbstring'),
        ],
        'https'       => (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off'),
        'fuseau'      => date_default_timezone_get(),
        'heure'       => date('d/m/Y H:i'),
        'taille_base' => is_file(DB_PATH) ? filesize(DB_PATH) : 0,
        'taille_pj'   => $tailleUploads,
        'nb_pj'       => $nbFichiers,
        'disque_libre' => $libre === false ? null : $libre,
        'upload_max'  => ini_get('upload_max_filesize'),
        'post_max'    => ini_get('post_max_size'),
        'nb_tickets'  => (int) db()->query('SELECT COUNT(*) FROM tickets')->fetchColumn(),
        'nb_messages' => (int) db()->query('SELECT COUNT(*) FROM comments')->fetchColumn(),
        'nb_comptes'  => users_count(),
        'nb_actifs'   => (int) db()->query('SELECT COUNT(*) FROM users WHERE active = 1')->fetchColumn(),
        'annuaire'    => setting_get('ldap_enabled') === '1',
        'mail'        => setting_get('mail_enabled') === '1',
        'premier'     => db()->query('SELECT MIN(created_at) FROM tickets')->fetchColumn(),
    ]);
}

/* ============================ Export & sauvegarde ======================= */

case 'export_csv': {
    $me = require_auth();
    require_role($me, ['admin']);

    // L'export doit rendre exactement ce que l'écran affiche : tous les
    // filtres de la liste sont donc repris, pas seulement une partie.
    $where  = [];
    $params = [];
    foreach (['status' => 't.status', 'priority' => 't.priority', 'category' => 't.category', 'site' => 't.site'] as $key => $col) {
        $v = trim((string) ($_GET[$key] ?? ''));
        if ($v !== '') {
            $where[]  = "$col = ?";
            $params[] = $v;
        }
    }
    if (!empty($_GET['open_only'])) {
        $where[] = "t.status NOT IN ('resolu','ferme')";
    }
    $assigned = (string) ($_GET['assigned'] ?? '');
    if ($assigned === 'none') {
        $where[] = 't.assigned_to IS NULL';
    } elseif ($assigned !== '' && ctype_digit($assigned)) {
        $where[]  = 't.assigned_to = ?';
        $params[] = (int) $assigned;
    }
    if (!empty($_GET['stale_only'])) {
        $seuilExport = (int) setting_get('stale_days', '3');
        $where[] = "t.status NOT IN ('resolu','ferme')
                    AND julianday(" . db()->quote(now()) . ") - julianday(t.updated_at) > " . $seuilExport;
    }
    if (!empty($_GET['unread_only'])) {
        $where[]  = "(SELECT seen_at FROM views v WHERE v.ticket_id = t.id AND v.user_id = ?) IS NULL
                     OR (SELECT seen_at FROM views v WHERE v.ticket_id = t.id AND v.user_id = ?) < t.updated_at";
        $params[] = (int) $me['id'];
        $params[] = (int) $me['id'];
    }
    $q = trim((string) ($_GET['q'] ?? ''));
    if ($q !== '') {
        $where[] = "(t.ref LIKE ? ESCAPE '\\' OR t.title LIKE ? ESCAPE '\\' OR t.description LIKE ? ESCAPE '\\')";
        $like    = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        array_push($params, $like, $like, $like);
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $st = db()->prepare(
        "SELECT t.ref, t.title, t.status, t.priority, t.category, t.site,
                c.name AS creator_name, a.name AS assignee_name,
                t.created_at, t.updated_at, t.closed_at, t.time_spent,
                ROUND(julianday(COALESCE(t.closed_at, " . db()->quote(now()) . ")) - julianday(t.created_at), 2) AS jours
         FROM tickets t
         JOIN users c ON c.id = t.created_by
         LEFT JOIN users a ON a.id = t.assigned_to
         $whereSql ORDER BY t.created_at DESC"
    );
    $st->execute($params);

    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="tickets_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Référence', 'Titre', 'Statut', 'Priorité', 'Catégorie', 'Site', 'Demandeur',
                   'Assigné à', 'Créé le', 'Mis à jour le', 'Fermé le', 'Durée (jours)', 'Temps passé (min)'], ';');
    foreach ($st as $r) {
        fputcsv($out, [
            csv_sur($r['ref']), csv_sur($r['title']),
            status_label($r['status']), priority_label($r['priority']),
            csv_sur($r['category']), csv_sur($r['site']),
            csv_sur($r['creator_name']), csv_sur($r['assignee_name'] ?? ''),
            $r['created_at'], substr((string) $r['updated_at'], 0, 19),
            $r['closed_at'] ?? '', $r['jours'], (int) $r['time_spent'],
        ], ';');
    }
    fclose($out);
    exit;
}

case 'backup': {
    // Sauvegarde complète : la base ET les pièces jointes. Ne livrer que la
    // base donnerait une fausse impression de sécurité — les photos d'écran
    // et les PDF joints aux tickets seraient perdus en cas de restauration.
    $me = require_auth();
    require_role($me, ['admin']);

    foreach (glob(DB_DIR . '/.ht_sauvegarde_*') ?: [] as $vieux) {
        if (filemtime($vieux) < time() - 3600) { @unlink($vieux); }
    }

    $base = DB_DIR . '/.ht_sauvegarde_' . bin2hex(random_bytes(6)) . '.sqlite';
    try {
        // VACUUM INTO produit une copie cohérente même pendant l'utilisation.
        db()->exec('VACUUM INTO ' . db()->quote($base));
    } catch (Throwable $e) {
        // SQLite antérieur à 3.27 : on force l'écriture du journal puis on copie.
        try {
            db()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        } catch (Throwable $e2) {
        }
        if (!@copy(DB_PATH, $base)) {
            fail('Sauvegarde impossible : ' . $e->getMessage(), 500);
        }
    }
    if (!is_file($base)) {
        fail('Sauvegarde impossible.', 500);
    }

    $horodatage = date('Y-m-d_H-i');

    if (class_exists('ZipArchive')) {
        $zipPath = DB_DIR . '/.ht_sauvegarde_' . bin2hex(random_bytes(6)) . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            $zip->addFile($base, 'ticketing.sqlite');
            $nbPj = 0;
            foreach (glob(UPLOAD_DIR . '/.ht_*') ?: [] as $pj) {
                if (is_file($pj)) {
                    $zip->addFile($pj, 'uploads/' . basename($pj));
                    $nbPj++;
                }
            }
            $zip->addFromString('LISEZ-MOI.txt',
                "Sauvegarde de " . setting_get('app_name', 'D8 Support') . "\r\n"
                . "Effectuée le " . date('d/m/Y à H:i') . "\r\n\r\n"
                . "Contenu :\r\n"
                . "  ticketing.sqlite  la base (tickets, messages, comptes, réglages)\r\n"
                . "  uploads/          les " . $nbPj . " pièce(s) jointe(s)\r\n\r\n"
                . "Restauration : arrêter le serveur web, remplacer le contenu du dossier\r\n"
                . "data/ de l'application par ces fichiers en renommant ticketing.sqlite\r\n"
                . "en .ht_ticketing.sqlite et en replaçant uploads/ tel quel, puis redémarrer.\r\n");
            $zip->close();
            @unlink($base);

            while (ob_get_level() > 0) { ob_end_clean(); }
            header('Content-Type: application/zip');
            header('Content-Length: ' . (string) filesize($zipPath));
            header('Content-Disposition: attachment; filename="sauvegarde_tickets_' . $horodatage . '.zip"');
            readfile($zipPath);
            @unlink($zipPath);
            exit;
        }
        @unlink($zipPath);
    }

    // Sans l'extension zip : on envoie la base seule, en le disant dans le
    // nom du fichier pour que personne ne croie avoir tout sauvegardé.
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . (string) filesize($base));
    header('Content-Disposition: attachment; filename="base_seule_sans_pieces_jointes_'
           . $horodatage . '.sqlite"');
    readfile($base);
    @unlink($base);
    exit;
}

/* ================================ Défaut ================================ */

default:
    fail('Action inconnue.', 404);
}
