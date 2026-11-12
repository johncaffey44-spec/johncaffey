<?php
/**
 * Gardien · Antivol — API partagée (sibling de api.php)
 * -----------------------------------------------------------------------------
 * Déposez ce fichier À CÔTÉ de gardien.html sur le même serveur web interne que
 * le planning (PHP 7.4+). Le dashboard le détecte seul et bascule en base
 * partagée. Stockage : des documents JSON versionnés dans data/gardien/, dans
 * le même esprit que api.php — pas de base SQL à installer.
 *
 * CE QUE FAIT GARDIEN, ET CE QU'IL NE FAIT PAS
 *   Gardien est un antivol pour VOS PROPRES appareils, en clair et avec votre
 *   accord. Sur le téléphone, l'application est VISIBLE : icône dans le menu,
 *   notification permanente « Gardien actif », et chaque photo prise à distance
 *   déclenche une notification sur l'appareil. Un appareil ne rejoint le parc
 *   qu'avec un CODE D'APPAIRAGE à usage unique que vous générez ici — personne
 *   ne peut enrôler un téléphone à l'insu de son porteur. Il n'existe pas de
 *   mode caché, pas d'activation silencieuse du micro ni de capture d'écran à
 *   l'insu de l'utilisateur : ce ne serait pas de l'antivol, ce serait de la
 *   surveillance clandestine, et c'est illégal (art. 226-1 et s. du Code pénal).
 *
 * SÉCURITÉ — à lire avant la mise en service :
 *   1. Servez en HTTPS : sans cela, codes et jetons circulent en clair.
 *   2. $ALLOWED_NETS limite l'accès du DASHBOARD aux plages IP internes. Les
 *      appareils, eux, peuvent joindre le serveur depuis l'extérieur (mobile),
 *      donc leur authentification repose sur un jeton par appareil, pas sur l'IP.
 *   3. Le dossier data/gardien contient tout (appareils, jetons, positions) :
 *      placez data hors racine web si possible ($DATA_DIR) et sauvegardez-le.
 * -----------------------------------------------------------------------------
 */
declare(strict_types=1);

/* ------------------------------------------------------------------ réglages */
$DATA_DIR       = __DIR__ . '/data';          // partagé avec le planning ; idéalement hors racine web
$GDIR           = $DATA_DIR . '/gardien';     // sous-dossier propre à Gardien
$ALLOWED_NETS   = ['127.0.0.0/8', '::1/128', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16']; // dashboard
$DEVICE_OPEN    = true;                        // true : les appareils joignent le serveur depuis n'importe quelle IP (réseau mobile)
$SETUP_TOKEN    = '';                          // si non vide, exigé pour créer le 1er compte admin (sécurise la mise en service)
$SESSION_IDLE   = 12 * 3600;                   // déconnexion admin après N s sans échange
$MIN_PASSWORD   = 8;
$MAX_FAILS      = 8;                            // échecs de connexion admin tolérés par IP…
$FAIL_WINDOW    = 900;                          // …sur cette durée (s)
$PAIR_MINUTES   = 30;                           // validité d'un code d'appairage
$HEARTBEAT_SEC  = 20;                           // intervalle de relève conseillé aux appareils
$DEVICE_STALE   = 120;                          // au-delà de N s sans relève, l'appareil est « hors ligne »
$LOC_HISTORY    = 500;                          // positions conservées par appareil
$EVENT_KEEP     = 1000;                         // lignes de journal conservées par appareil
$MAX_BYTES      = 8 * 1024 * 1024;              // taille maximale d'une requête (photos : voir note CameraX)
$TIMEZONE       = 'Europe/Paris';

date_default_timezone_set($TIMEZONE);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

/* ------------------------------------------------------------------ utilitaires */
function out(int $code, array $body): never {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function body_in(): array {
    global $MAX_BYTES;
    $raw = file_get_contents('php://input', false, null, 0, $MAX_BYTES + 1) ?: '';
    if (strlen($raw) > $MAX_BYTES) out(413, ['error' => 'Requête trop volumineuse']);
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}
function read_json(string $path): array {
    if (!is_file($path)) return [];
    $h = @fopen($path, 'r');
    if (!$h) return [];
    @flock($h, LOCK_SH);
    $raw = stream_get_contents($h) ?: '';
    @flock($h, LOCK_UN);
    fclose($h);
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}
function write_json(string $path, array $data): bool {
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) return false;
    $tmp = $path . '.tmp' . bin2hex(random_bytes(4));
    $ok  = @file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
    if ($ok === false) { @unlink($tmp); return false; }
    if (!@rename($tmp, $path)) { @unlink($tmp); return false; }
    return true;
}
/** Mutation atomique d'un fichier JSON sous verrou exclusif. */
function mutate_json(string $path, callable $fn): array {
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0770, true);
    $h = fopen($path, 'c+');
    if (!$h) out(500, ['error' => 'Stockage inaccessible']);
    flock($h, LOCK_EX);
    $raw = stream_get_contents($h) ?: '';
    $data = json_decode($raw, true);
    if (!is_array($data)) $data = [];
    $data = $fn($data);
    rewind($h);
    ftruncate($h, 0);
    fwrite($h, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    fflush($h);
    flock($h, LOCK_UN);
    fclose($h);
    return $data;
}
function client_ip(): string {
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}
function ip_in_cidr(string $ip, string $cidr): bool {
    if (!str_contains($cidr, '/')) return $ip === $cidr;
    [$net, $bits] = explode('/', $cidr, 2);
    $bits = (int)$bits;
    $ipb = @inet_pton($ip);
    $netb = @inet_pton($net);
    if ($ipb === false || $netb === false || strlen($ipb) !== strlen($netb)) return false;
    $bytes = intdiv($bits, 8);
    $rem   = $bits % 8;
    if ($bytes && strncmp($ipb, $netb, $bytes) !== 0) return false;
    if ($rem) {
        $mask = chr(0xFF << (8 - $rem) & 0xFF);
        if ((ord($ipb[$bytes]) & ord($mask)) !== (ord($netb[$bytes]) & ord($mask))) return false;
    }
    return true;
}
function ip_allowed(string $ip, array $nets): bool {
    foreach ($nets as $c) if (ip_in_cidr($ip, $c)) return true;
    return false;
}
function now_iso(): string { return date('c'); }
function token(int $bytes = 32): string { return bin2hex(random_bytes($bytes)); }
function sha(string $s): string { return hash('sha256', $s); }
/** Code d'appairage lisible : 8 caractères base32 sans voyelles ambiguës. */
function pairing_code(): string {
    $alphabet = '0123456789BCDFGHJKLMNPQRSTVWXZ';
    $c = '';
    for ($i = 0; $i < 8; $i++) $c .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    return substr($c, 0, 4) . '-' . substr($c, 4);
}
function sstr(mixed $v, int $max = 200): string {
    $s = is_string($v) ? $v : '';
    $s = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s) ?? '');
    return mb_substr($s, 0, $max);
}

/* ------------------------------------------------------------------ amorçage */
if (!is_dir($GDIR) && !@mkdir($GDIR, 0770, true) && !is_dir($GDIR)) {
    out(500, ['error' => 'Impossible de créer le dossier de données']);
}
if (!is_writable($GDIR)) out(500, ['error' => 'Dossier de données non accessible en écriture']);
// On verrouille le dossier data côté serveur web (Apache/IIS) comme le fait api.php.
if (!is_file("$DATA_DIR/.htaccess")) @file_put_contents("$DATA_DIR/.htaccess", "Require all denied\nDeny from all\n");

$ADMIN   = "$GDIR/admin.json";        // { hash, createdAt }
$DEVICES = "$GDIR/devices.json";      // { deviceId: {...} }
$PAIRS   = "$GDIR/pairings.json";     // { codeHash: {name, expires, createdAt} }
$FAILS   = "$GDIR/auth-fails.json";   // { ip: {count, until} }
$SESSDIR = "$GDIR/sessions";
$CMDDIR  = "$GDIR/commands";          // commands/<deviceId>.json : [ {id,type,...} ]
$LOCDIR  = "$GDIR/locations";         // locations/<deviceId>.json : [ {lat,lng,acc,at} ]
$EVTDIR  = "$GDIR/events";            // events/<deviceId>.json : [ {at,kind,text} ]
@is_dir($SESSDIR) || @mkdir($SESSDIR, 0770, true);

/* ------------------------------------------------------------------ requête */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    // Un GET sert juste de sonde de présence pour le dashboard (comme api.php).
    out(200, ['ok' => true, 'service' => 'gardien', 'setup' => is_file($ADMIN), 'heartbeat' => $HEARTBEAT_SEC]);
}
$in     = body_in();
$action = sstr($in['action'] ?? '', 40);
$ip     = client_ip();

/* ======================= chemin APPAREIL (jeton par appareil) ============== */
/* Ces actions viennent du téléphone : authentification par jeton, pas par IP. */
if ($action === 'enroll' || $action === 'heartbeat') {
    if (!$DEVICE_OPEN && !ip_allowed($ip, $ALLOWED_NETS)) out(403, ['error' => 'Accès refusé']);
    device_endpoint($action, $in);
    exit; // device_endpoint termine toujours par out()
}

/* ======================= chemin ADMIN (dashboard) ========================== */
if (!ip_allowed($ip, $ALLOWED_NETS)) out(403, ['error' => 'Accès au tableau de bord refusé depuis cette adresse']);

/* --- première mise en service : créer le compte admin --- */
if ($action === 'status') {
    out(200, ['setup' => is_file($ADMIN), 'needSetupToken' => $SETUP_TOKEN !== '', 'heartbeat' => $HEARTBEAT_SEC, 'authed' => (bool)current_session()]);
}
if ($action === 'setup') {
    if (is_file($ADMIN)) out(409, ['error' => 'Déjà configuré']);
    if ($SETUP_TOKEN !== '' && !hash_equals($SETUP_TOKEN, sstr($in['setupToken'] ?? '', 200))) {
        out(403, ['error' => 'Jeton de mise en service invalide']);
    }
    $pw = (string)($in['password'] ?? '');
    if (mb_strlen($pw) < $MIN_PASSWORD) out(400, ['error' => "Mot de passe trop court (≥ $MIN_PASSWORD caractères)"]);
    write_json($ADMIN, ['hash' => password_hash($pw, PASSWORD_DEFAULT), 'createdAt' => now_iso()]);
    $sid = start_session();
    out(200, ['ok' => true, 'sid' => $sid]);
}

/* --- connexion admin --- */
if ($action === 'login') {
    fail_gate();
    if (!is_file($ADMIN)) out(409, ['error' => 'Non configuré']);
    $admin = read_json($ADMIN);
    if (!password_verify((string)($in['password'] ?? ''), (string)($admin['hash'] ?? ''))) {
        fail_bump();
        out(401, ['error' => 'Mot de passe incorrect']);
    }
    fail_reset();
    $sid = start_session();
    out(200, ['ok' => true, 'sid' => $sid]);
}

/* --- à partir d'ici : session admin obligatoire --- */
$me = current_session();
if (!$me) out(401, ['error' => 'Connexion requise']);

switch ($action) {
    case 'logout':
        end_session();
        out(200, ['ok' => true]);

    case 'list-devices': {
        $devs = read_json($DEVICES);
        $list = [];
        foreach ($devs as $id => $d) {
            $last = strtotime((string)($d['lastSeen'] ?? '')) ?: 0;
            $list[] = [
                'id'       => $id,
                'name'     => $d['name'] ?? $id,
                'model'    => $d['model'] ?? '',
                'online'   => $last && (time() - $last) <= $DEVICE_STALE,
                'lastSeen' => $d['lastSeen'] ?? null,
                'battery'  => $d['battery'] ?? null,
                'charging' => $d['charging'] ?? null,
                'location' => $d['location'] ?? null,
                'pending'  => count(pending_commands($id)),
            ];
        }
        usort($list, fn($a, $b) => strcmp((string)$b['lastSeen'], (string)$a['lastSeen']));
        out(200, ['devices' => $list, 'heartbeat' => $HEARTBEAT_SEC, 'stale' => $DEVICE_STALE]);
    }

    case 'device': {
        $id = sstr($in['id'] ?? '', 64);
        $devs = read_json($DEVICES);
        if (!isset($devs[$id])) out(404, ['error' => 'Appareil inconnu']);
        $d = $devs[$id];
        unset($d['token_hash']);
        out(200, [
            'device'    => ['id' => $id] + $d,
            'commands'  => array_slice(read_json("$CMDDIR/$id.json"), -50),
            'locations' => array_slice(read_json("$LOCDIR/$id.json"), -200),
            'events'    => array_slice(read_json("$EVTDIR/$id.json"), -100),
            'stale'     => $DEVICE_STALE,
        ]);
    }

    case 'new-pairing': {
        $name = sstr($in['name'] ?? '', 60) ?: 'Appareil';
        $code = pairing_code();
        mutate_json($PAIRS, function ($p) use ($code, $name, $PAIR_MINUTES) {
            // purge des codes expirés au passage
            $p = array_filter($p, fn($x) => ($x['expires'] ?? 0) > time());
            $p[sha($code)] = ['name' => $name, 'expires' => time() + $PAIR_MINUTES * 60, 'createdAt' => now_iso()];
            return $p;
        });
        // Charge utile du QR : l'appli y lit l'URL du serveur et le code.
        $base = (($_SERVER['HTTPS'] ?? '') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
              . preg_replace('#/[^/]*$#', '/', (string)($_SERVER['REQUEST_URI'] ?? '/'));
        out(200, ['code' => $code, 'expiresIn' => $PAIR_MINUTES * 60, 'qr' => json_encode(['v' => 1, 'url' => $base . 'gardien-api.php', 'code' => $code], JSON_UNESCAPED_SLASHES)]);
    }

    case 'rename-device': {
        $id = sstr($in['id'] ?? '', 64);
        $name = sstr($in['name'] ?? '', 60);
        mutate_json($DEVICES, function ($d) use ($id, $name) {
            if (isset($d[$id])) $d[$id]['name'] = $name ?: $d[$id]['name'];
            return $d;
        });
        out(200, ['ok' => true]);
    }

    case 'remove-device': {
        $id = sstr($in['id'] ?? '', 64);
        mutate_json($DEVICES, function ($d) use ($id) { unset($d[$id]); return $d; });
        @unlink("$CMDDIR/$id.json"); @unlink("$LOCDIR/$id.json"); @unlink("$EVTDIR/$id.json");
        out(200, ['ok' => true]);
    }

    case 'send-command': {
        $id   = sstr($in['id'] ?? '', 64);
        $type = sstr($in['type'] ?? '', 24);
        $allowed = ['locate', 'ring', 'lock', 'message', 'photo', 'wipe'];
        if (!in_array($type, $allowed, true)) out(400, ['error' => 'Commande inconnue']);
        $devs = read_json($DEVICES);
        if (!isset($devs[$id])) out(404, ['error' => 'Appareil inconnu']);
        $params = [];
        if ($type === 'message') $params['text'] = sstr($in['text'] ?? '', 500);
        if ($type === 'ring')    $params['seconds'] = max(5, min(300, (int)($in['seconds'] ?? 30)));
        if ($type === 'photo')   $params['camera'] = in_array(($in['camera'] ?? 'front'), ['front', 'back'], true) ? $in['camera'] : 'front';
        if ($type === 'wipe') {
            // Garde-fou : l'effacement d'usine est irréversible.
            if (sstr($in['confirm'] ?? '', 10) !== 'EFFACER') out(400, ['error' => 'Confirmation « EFFACER » requise']);
        }
        $cmd = [
            'id'        => token(8),
            'type'      => $type,
            'params'    => $params,
            'status'    => 'pending',
            'createdAt' => now_iso(),
            'by'        => 'admin',
        ];
        mutate_json("$CMDDIR/$id.json", function ($q) use ($cmd) { $q[] = $cmd; return array_slice($q, -200); });
        log_event($id, 'command', "Commande « $type » envoyée");
        out(200, ['ok' => true, 'command' => $cmd]);
    }

    case 'change-password': {
        $cur = (string)($in['current'] ?? '');
        $next = (string)($in['next'] ?? '');
        $admin = read_json($ADMIN);
        if (!password_verify($cur, (string)($admin['hash'] ?? ''))) out(401, ['error' => 'Mot de passe actuel incorrect']);
        if (mb_strlen($next) < $MIN_PASSWORD) out(400, ['error' => "Nouveau mot de passe trop court (≥ $MIN_PASSWORD)"]);
        $admin['hash'] = password_hash($next, PASSWORD_DEFAULT);
        write_json($ADMIN, $admin);
        out(200, ['ok' => true]);
    }
}
out(400, ['error' => 'Action inconnue']);

/* ================================================================ fonctions */

/** Point d'entrée des appareils (enrôlement + relève). Termine par out(). */
function device_endpoint(string $action, array $in): never {
    global $DEVICES, $PAIRS, $CMDDIR, $LOCDIR, $HEARTBEAT_SEC, $LOC_HISTORY;

    if ($action === 'enroll') {
        $code  = strtoupper(sstr($in['code'] ?? '', 20));
        $model = sstr($in['model'] ?? '', 80);
        $name  = sstr($in['name'] ?? '', 60);
        $pairs = read_json($PAIRS);
        $rec   = $pairs[sha($code)] ?? null;
        if (!$rec || ($rec['expires'] ?? 0) < time()) out(403, ['error' => 'Code d’appairage invalide ou expiré']);
        // Consomme le code (usage unique).
        mutate_json($PAIRS, function ($p) use ($code) { unset($p[sha($code)]); return $p; });
        $deviceId    = token(10);
        $deviceToken = token(32);
        mutate_json($DEVICES, function ($d) use ($deviceId, $deviceToken, $model, $name, $rec) {
            $d[$deviceId] = [
                'name'       => $name ?: ($rec['name'] ?? 'Appareil'),
                'model'      => $model,
                'token_hash' => sha($deviceToken),
                'enrolledAt' => now_iso(),
                'lastSeen'   => now_iso(),
                'battery'    => null,
                'charging'   => null,
                'location'   => null,
            ];
            return $d;
        });
        log_event($deviceId, 'enroll', 'Appareil appairé');
        out(200, ['ok' => true, 'deviceId' => $deviceId, 'deviceToken' => $deviceToken, 'heartbeat' => $HEARTBEAT_SEC]);
    }

    /* --- heartbeat : l'appareil pousse son état + récupère les commandes --- */
    $deviceId = sstr($in['deviceId'] ?? '', 64);
    $tok      = sstr($in['deviceToken'] ?? '', 128);
    $devs     = read_json($DEVICES);
    $d        = $devs[$deviceId] ?? null;
    if (!$d || !hash_equals((string)($d['token_hash'] ?? ''), sha($tok))) out(401, ['error' => 'Appareil non reconnu']);

    // État
    $patch = ['lastSeen' => now_iso()];
    if (isset($in['battery']))  $patch['battery']  = max(0, min(100, (int)$in['battery']));
    if (isset($in['charging'])) $patch['charging'] = (bool)$in['charging'];

    // Position (le cas échéant)
    $loc = $in['location'] ?? null;
    if (is_array($loc) && isset($loc['lat'], $loc['lng'])) {
        $point = [
            'lat' => round((float)$loc['lat'], 6),
            'lng' => round((float)$loc['lng'], 6),
            'acc' => isset($loc['acc']) ? round((float)$loc['acc'], 1) : null,
            'at'  => now_iso(),
        ];
        $patch['location'] = $point;
        mutate_json("$LOCDIR/$deviceId.json", function ($h) use ($point, $LOC_HISTORY) {
            $h[] = $point; return array_slice($h, -$LOC_HISTORY);
        });
    }
    mutate_json($DEVICES, function ($all) use ($deviceId, $patch) {
        if (isset($all[$deviceId])) $all[$deviceId] = array_merge($all[$deviceId], $patch);
        return $all;
    });

    // Résultats des commandes exécutées
    $results = is_array($in['results'] ?? null) ? $in['results'] : [];
    if ($results) {
        mutate_json("$CMDDIR/$deviceId.json", function ($q) use ($results, $deviceId) {
            $byId = [];
            foreach ($results as $r) if (isset($r['id'])) $byId[(string)$r['id']] = $r;
            foreach ($q as &$c) {
                if (isset($byId[$c['id']]) && $c['status'] === 'pending') {
                    $r = $byId[$c['id']];
                    $c['status'] = !empty($r['ok']) ? 'done' : 'failed';
                    $c['resultAt'] = now_iso();
                    if (isset($r['message'])) $c['result'] = sstr($r['message'], 300);
                    log_event($deviceId, 'result', "« {$c['type']} » : " . ($c['status'] === 'done' ? 'exécutée' : 'échec'));
                }
            }
            return $q;
        });
    }

    // Commandes en attente à renvoyer
    out(200, ['ok' => true, 'commands' => pending_commands($deviceId), 'heartbeat' => $HEARTBEAT_SEC]);
}

function pending_commands(string $id): array {
    global $CMDDIR;
    $q = read_json("$CMDDIR/$id.json");
    return array_values(array_filter($q, fn($c) => ($c['status'] ?? '') === 'pending'));
}

function log_event(string $id, string $kind, string $text): void {
    global $EVTDIR, $EVENT_KEEP;
    mutate_json("$EVTDIR/$id.json", function ($e) use ($kind, $text, $EVENT_KEEP) {
        $e[] = ['at' => now_iso(), 'kind' => $kind, 'text' => $text];
        return array_slice($e, -$EVENT_KEEP);
    });
}

/* ---- sessions admin (cookie, fichiers — même principe qu'api.php) ---- */
function start_session(): string {
    global $SESSDIR;
    $sid = token(32);
    write_json("$SESSDIR/$sid.json", ['created' => time(), 'last' => time(), 'ip' => client_ip()]);
    $secure = !empty($_SERVER['HTTPS']);
    setcookie('gardien_sid', $sid, [
        'expires' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict', 'secure' => $secure,
    ]);
    return $sid;
}
function current_session(): ?array {
    global $SESSDIR, $SESSION_IDLE;
    $sid = sstr($_COOKIE['gardien_sid'] ?? '', 128);
    if ($sid === '' || !preg_match('/^[a-f0-9]{64}$/', $sid)) return null;
    $f = "$SESSDIR/$sid.json";
    $s = read_json($f);
    if (!$s || (time() - (int)($s['last'] ?? 0)) > $SESSION_IDLE) { @unlink($f); return null; }
    $s['last'] = time();
    write_json($f, $s);
    return $s + ['sid' => $sid];
}
function end_session(): void {
    global $SESSDIR;
    $sid = sstr($_COOKIE['gardien_sid'] ?? '', 128);
    if (preg_match('/^[a-f0-9]{64}$/', $sid)) @unlink("$SESSDIR/$sid.json");
    setcookie('gardien_sid', '', ['expires' => time() - 3600, 'path' => '/']);
}

/* ---- limitation des tentatives de connexion admin, par IP ---- */
function fail_gate(): void {
    global $FAILS, $MAX_FAILS;
    $f = read_json($FAILS)[client_ip()] ?? null;
    if ($f && ($f['until'] ?? 0) > time() && ($f['count'] ?? 0) >= $MAX_FAILS) {
        out(429, ['error' => 'Trop de tentatives. Réessayez plus tard.']);
    }
}
function fail_bump(): void {
    global $FAILS, $FAIL_WINDOW;
    mutate_json($FAILS, function ($all) use ($FAIL_WINDOW) {
        $ip = client_ip();
        $f = $all[$ip] ?? ['count' => 0, 'until' => 0];
        if (($f['until'] ?? 0) < time()) $f = ['count' => 0, 'until' => time() + $FAIL_WINDOW];
        $f['count']++;
        $all[$ip] = $f;
        // purge
        foreach ($all as $k => $v) if (($v['until'] ?? 0) < time() - 3600) unset($all[$k]);
        return $all;
    });
}
function fail_reset(): void {
    global $FAILS;
    mutate_json($FAILS, function ($all) { unset($all[client_ip()]); return $all; });
}
