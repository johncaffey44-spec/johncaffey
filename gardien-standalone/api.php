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
 *   3. Le dossier data/gardien contient tout (appareils, jetons, positions,
 *      photos) : placez data hors racine web si possible ($DATA_DIR), et
 *      sauvegardez-le.
 * -----------------------------------------------------------------------------
 */
declare(strict_types=1);

/* ------------------------------------------------------------------ réglages */
$DATA_DIR        = __DIR__ . '/data';          // partagé avec le planning ; idéalement hors racine web
$GDIR            = $DATA_DIR . '/gardien';     // sous-dossier propre à Gardien
$ALLOWED_NETS    = ['127.0.0.0/8', '::1/128', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16']; // dashboard
$DEVICE_OPEN     = true;                        // true : les appareils joignent le serveur depuis n'importe quelle IP
$SETUP_TOKEN     = '';                          // si non vide, exigé pour créer le 1er compte admin
$SESSION_IDLE    = 12 * 3600;                   // déconnexion admin après N s sans échange
$MIN_PASSWORD    = 8;
$MAX_FAILS       = 8;                            // échecs de connexion admin tolérés par IP…
$FAIL_WINDOW     = 900;                          // …sur cette durée (s)
$PAIR_MINUTES    = 30;                           // validité d'un code d'appairage
$HEARTBEAT_SEC   = 20;                           // intervalle de relève conseillé aux appareils
$DEVICE_STALE    = 120;                          // au-delà de N s sans relève, l'appareil est « hors ligne »
$LOC_HISTORY     = 500;                          // positions conservées par appareil
$EVENT_KEEP      = 1000;                         // lignes de journal conservées par appareil
$MAX_BYTES       = 10 * 1024 * 1024;             // taille maximale d'une requête (photos comprises)
$MAX_PHOTO_BYTES = 8 * 1024 * 1024;             // taille maximale d'une photo décodée
$PHOTO_KEEP      = 60;                           // photos conservées par appareil
$BATTERY_LOW     = 15;                           // seuil (%) d'alerte batterie faible
$TIMEZONE        = 'Europe/Paris';

date_default_timezone_set($TIMEZONE);
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

/* ------------------------------------------------------------------ utilitaires */
function out(int $code, array $body): never {
    header('Content-Type: application/json; charset=utf-8');
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
function client_ip(): string { return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'); }
function ip_in_cidr(string $ip, string $cidr): bool {
    if (!str_contains($cidr, '/')) return $ip === $cidr;
    [$net, $bits] = explode('/', $cidr, 2);
    $bits = (int)$bits;
    $ipb = @inet_pton($ip); $netb = @inet_pton($net);
    if ($ipb === false || $netb === false || strlen($ipb) !== strlen($netb)) return false;
    $bytes = intdiv($bits, 8); $rem = $bits % 8;
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
function username_ok(string $u): bool { return (bool)preg_match('/^[A-Za-z0-9._-]{3,32}$/', $u); }

/* ------------------------------------------------------------------ amorçage */
if (!is_dir($GDIR) && !@mkdir($GDIR, 0770, true) && !is_dir($GDIR)) {
    out(500, ['error' => 'Impossible de créer le dossier de données']);
}
if (!is_writable($GDIR)) out(500, ['error' => 'Dossier de données non accessible en écriture']);
// refus d'accès direct au dossier data (Apache puis IIS), comme api.php.
// Les photos sont servies par PHP (?img=…), jamais par accès direct : cette protection ne les gêne pas.
if (!file_exists("$DATA_DIR/.htaccess")) @file_put_contents("$DATA_DIR/.htaccess", "Require all denied\nDeny from all\n");
if (!file_exists("$DATA_DIR/web.config")) @file_put_contents("$DATA_DIR/web.config",
    '<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer><security><requestFiltering>'
  . '<fileExtensions allowUnlisted="false" /></requestFiltering></security></system.webServer></configuration>');

$ADMINS  = "$GDIR/admins.json";       // { username: {hash, role, createdAt} }  (role: owner|admin)
$DEVICES = "$GDIR/devices.json";
$PAIRS   = "$GDIR/pairings.json";
$FAILS   = "$GDIR/auth-fails.json";
$SESSDIR = "$GDIR/sessions";
$CMDDIR  = "$GDIR/commands";
$LOCDIR  = "$GDIR/locations";
$EVTDIR  = "$GDIR/events";
$PHOTODIR = "$GDIR/photos";           // photos/<deviceId>/<photoId>.jpg + _index.json
@is_dir($SESSDIR) || @mkdir($SESSDIR, 0770, true);

/* ============================ service d'IMAGES (GET) ======================= */
/* Les <img> du tableau de bord chargent les photos par GET ; le cookie de
   session admin est envoyé automatiquement (même origine). */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {

    // --- Téléchargement de l'application (APK déposée à côté de gardien-api.php) ---
    if (isset($_GET['apk'])) {
        $apk = __DIR__ . '/gardien.apk';
        if (!is_file($apk)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Application non deposee sur le serveur. Placez le fichier gardien.apk a cote de gardien-api.php.";
            exit;
        }
        header('Content-Type: application/vnd.android.package-archive');
        header('Content-Disposition: attachment; filename="gardien.apk"');
        header('Content-Length: ' . (string)filesize($apk));
        header('Cache-Control: private, max-age=3600');
        readfile($apk);
        exit;
    }

    // --- Page d'installation : ouverte quand on scanne le QR avec l'appareil photo du téléphone ---
    if (isset($_GET['install'])) {
        install_page(sstr($_GET['code'] ?? '', 20));
        exit; // install_page termine par exit
    }

    // --- Service des photos (le cookie admin est envoyé par les <img> en même origine) ---
    $img = sstr($_GET['img'] ?? '', 120);
    if ($img !== '') {
        if (!ip_allowed(client_ip(), $ALLOWED_NETS) || !current_session()) { http_response_code(403); exit; }
        if (!preg_match('#^([a-f0-9]{1,40})/([a-f0-9]{1,40})$#', $img, $m)) { http_response_code(400); exit; }
        $file = "$PHOTODIR/{$m[1]}/{$m[2]}.jpg";
        if (!is_file($file)) { http_response_code(404); exit; }
        header('Content-Type: image/jpeg');
        header('Cache-Control: private, max-age=86400');
        readfile($file);
        exit;
    }
    // sonde de présence pour le dashboard
    out(200, ['ok' => true, 'service' => 'gardien', 'setup' => is_file($ADMINS), 'heartbeat' => $HEARTBEAT_SEC]);
}

/* ------------------------------------------------------------------ requête POST */
$in     = body_in();
$action = sstr($in['action'] ?? '', 40);
$ip     = client_ip();

/* ======================= chemin APPAREIL (jeton par appareil) ============== */
if ($action === 'enroll' || $action === 'heartbeat' || $action === 'upload-photo') {
    if (!$DEVICE_OPEN && !ip_allowed($ip, $ALLOWED_NETS)) out(403, ['error' => 'Accès refusé']);
    device_endpoint($action, $in);
    exit;
}

/* ======================= chemin ADMIN (dashboard) ========================== */
if (!ip_allowed($ip, $ALLOWED_NETS)) out(403, ['error' => 'Accès au tableau de bord refusé depuis cette adresse']);

if ($action === 'status') {
    $me = current_session();
    out(200, ['setup' => is_file($ADMINS), 'needSetupToken' => $SETUP_TOKEN !== '', 'heartbeat' => $HEARTBEAT_SEC,
        'authed' => (bool)$me, 'user' => $me['user'] ?? null, 'role' => $me['role'] ?? null]);
}
if ($action === 'setup') {
    if (is_file($ADMINS)) out(409, ['error' => 'Déjà configuré']);
    if ($SETUP_TOKEN !== '' && !hash_equals($SETUP_TOKEN, sstr($in['setupToken'] ?? '', 200))) {
        out(403, ['error' => 'Jeton de mise en service invalide']);
    }
    $user = sstr($in['username'] ?? 'admin', 32) ?: 'admin';
    if (!username_ok($user)) out(400, ['error' => 'Identifiant invalide (3–32 caractères : lettres, chiffres . _ -)']);
    $pw = (string)($in['password'] ?? '');
    if (mb_strlen($pw) < $MIN_PASSWORD) out(400, ['error' => "Mot de passe trop court (≥ $MIN_PASSWORD caractères)"]);
    write_json($ADMINS, [$user => ['hash' => password_hash($pw, PASSWORD_DEFAULT), 'role' => 'owner', 'createdAt' => now_iso()]]);
    $sid = start_session($user, 'owner');
    out(200, ['ok' => true, 'sid' => $sid, 'user' => $user, 'role' => 'owner']);
}
if ($action === 'login') {
    fail_gate();
    if (!is_file($ADMINS)) out(409, ['error' => 'Non configuré']);
    $user = sstr($in['username'] ?? '', 32);
    $admins = read_json($ADMINS);
    $a = $admins[$user] ?? null;
    $ok = $a && password_verify((string)($in['password'] ?? ''), (string)$a['hash']);
    // comparaison à durée ~constante même si l'utilisateur n'existe pas
    if (!$a) password_verify((string)($in['password'] ?? ''), '$2y$12$0000000000000000000000.0000000000000000000000000000000000');
    if (!$ok) { fail_bump(); out(401, ['error' => 'Identifiant ou mot de passe incorrect']); }
    fail_reset();
    $sid = start_session($user, (string)($a['role'] ?? 'admin'));
    out(200, ['ok' => true, 'sid' => $sid, 'user' => $user, 'role' => $a['role'] ?? 'admin']);
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
                'id' => $id, 'name' => $d['name'] ?? $id, 'model' => $d['model'] ?? '',
                'online' => $last && (time() - $last) <= $DEVICE_STALE,
                'lastSeen' => $d['lastSeen'] ?? null, 'battery' => $d['battery'] ?? null,
                'charging' => $d['charging'] ?? null, 'location' => $d['location'] ?? null,
                'lowBattery' => isset($d['battery']) && $d['battery'] !== null && (int)$d['battery'] <= $BATTERY_LOW && empty($d['charging']),
                'pending' => count(pending_commands($id)),
            ];
        }
        usort($list, fn($a, $b) => strcmp((string)$b['lastSeen'], (string)$a['lastSeen']));
        out(200, ['devices' => $list, 'heartbeat' => $HEARTBEAT_SEC, 'stale' => $DEVICE_STALE, 'batteryLow' => $BATTERY_LOW]);
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
            'locations' => array_slice(read_json("$LOCDIR/$id.json"), -300),
            'events'    => array_slice(read_json("$EVTDIR/$id.json"), -100),
            'photos'    => array_slice(read_json("$PHOTODIR/$id/_index.json"), -$PHOTO_KEEP),
            'stale'     => $DEVICE_STALE, 'batteryLow' => $BATTERY_LOW,
        ]);
    }

    case 'new-pairing': {
        $name = sstr($in['name'] ?? '', 60) ?: 'Appareil';
        $code = pairing_code();
        mutate_json($PAIRS, function ($p) use ($code, $name, $PAIR_MINUTES) {
            $p = array_filter($p, fn($x) => ($x['expires'] ?? 0) > time());
            $p[sha($code)] = ['name' => $name, 'expires' => time() + $PAIR_MINUTES * 60, 'createdAt' => now_iso()];
            return $p;
        });
        // Le QR encode une vraie URL : scanné par l'appareil photo d'un téléphone vierge,
        // il ouvre la page d'installation ; scanné par l'app, le code et le serveur en sont extraits.
        $abs        = base_url();
        $installUrl = $abs . self_name() . '?install=1&code=' . rawurlencode($code);
        out(200, ['code' => $code, 'expiresIn' => $PAIR_MINUTES * 60,
            'qr'         => $installUrl,
            'installUrl' => $installUrl,
            'serverUrl'  => $abs . self_name(),
            'apkUrl'     => $abs . self_name() . '?apk=1']);
    }

    case 'rename-device': {
        $id = sstr($in['id'] ?? '', 64); $name = sstr($in['name'] ?? '', 60);
        mutate_json($DEVICES, function ($d) use ($id, $name) { if (isset($d[$id])) $d[$id]['name'] = $name ?: $d[$id]['name']; return $d; });
        out(200, ['ok' => true]);
    }

    case 'remove-device': {
        $id = sstr($in['id'] ?? '', 64);
        mutate_json($DEVICES, function ($d) use ($id) { unset($d[$id]); return $d; });
        @unlink("$CMDDIR/$id.json"); @unlink("$LOCDIR/$id.json"); @unlink("$EVTDIR/$id.json");
        rrmdir("$PHOTODIR/$id");
        out(200, ['ok' => true]);
    }

    case 'send-command': {
        $id = sstr($in['id'] ?? '', 64); $type = sstr($in['type'] ?? '', 24);
        $allowed = ['locate', 'ring', 'lock', 'message', 'photo', 'wipe'];
        if (!in_array($type, $allowed, true)) out(400, ['error' => 'Commande inconnue']);
        $devs = read_json($DEVICES);
        if (!isset($devs[$id])) out(404, ['error' => 'Appareil inconnu']);
        $params = [];
        if ($type === 'message') $params['text'] = sstr($in['text'] ?? '', 500);
        if ($type === 'ring')    $params['seconds'] = max(5, min(300, (int)($in['seconds'] ?? 30)));
        if ($type === 'photo')   $params['camera'] = in_array(($in['camera'] ?? 'front'), ['front', 'back'], true) ? $in['camera'] : 'front';
        if ($type === 'wipe') {
            if (sstr($in['confirm'] ?? '', 10) !== 'EFFACER') out(400, ['error' => 'Confirmation « EFFACER » requise']);
            $params['scope'] = ($in['scope'] ?? 'full') === 'external' ? 'external' : 'full'; // external = carte SD uniquement
        }
        $cmd = ['id' => token(8), 'type' => $type, 'params' => $params, 'status' => 'pending',
            'createdAt' => now_iso(), 'by' => $me['user']];
        mutate_json("$CMDDIR/$id.json", function ($q) use ($cmd) { $q[] = $cmd; return array_slice($q, -200); });
        log_event($id, 'command', "Commande « $type » envoyée par {$me['user']}");
        out(200, ['ok' => true, 'command' => $cmd]);
    }

    case 'change-password': {
        $cur = (string)($in['current'] ?? ''); $next = (string)($in['next'] ?? '');
        $admins = read_json($ADMINS); $u = $me['user'];
        if (!isset($admins[$u]) || !password_verify($cur, (string)$admins[$u]['hash'])) out(401, ['error' => 'Mot de passe actuel incorrect']);
        if (mb_strlen($next) < $MIN_PASSWORD) out(400, ['error' => "Nouveau mot de passe trop court (≥ $MIN_PASSWORD)"]);
        mutate_json($ADMINS, function ($a) use ($u, $next) { $a[$u]['hash'] = password_hash($next, PASSWORD_DEFAULT); return $a; });
        out(200, ['ok' => true]);
    }

    /* ------------------- gestion des administrateurs (propriétaire) -------- */
    case 'admins': {
        $admins = read_json($ADMINS);
        $list = [];
        foreach ($admins as $u => $a) $list[] = ['username' => $u, 'role' => $a['role'] ?? 'admin', 'createdAt' => $a['createdAt'] ?? null];
        out(200, ['admins' => $list, 'me' => $me['user'], 'role' => $me['role']]);
    }
    case 'add-admin': {
        if (($me['role'] ?? '') !== 'owner') out(403, ['error' => 'Réservé au propriétaire']);
        $u = sstr($in['username'] ?? '', 32); $pw = (string)($in['password'] ?? '');
        if (!username_ok($u)) out(400, ['error' => 'Identifiant invalide (3–32 : lettres, chiffres . _ -)']);
        if (mb_strlen($pw) < $MIN_PASSWORD) out(400, ['error' => "Mot de passe trop court (≥ $MIN_PASSWORD)"]);
        $admins = read_json($ADMINS);
        if (isset($admins[$u])) out(409, ['error' => 'Cet identifiant existe déjà']);
        $role = ($in['role'] ?? 'admin') === 'owner' ? 'owner' : 'admin';
        mutate_json($ADMINS, function ($a) use ($u, $pw, $role) {
            $a[$u] = ['hash' => password_hash($pw, PASSWORD_DEFAULT), 'role' => $role, 'createdAt' => now_iso()]; return $a;
        });
        out(200, ['ok' => true]);
    }
    case 'remove-admin': {
        if (($me['role'] ?? '') !== 'owner') out(403, ['error' => 'Réservé au propriétaire']);
        $u = sstr($in['username'] ?? '', 32);
        if ($u === $me['user']) out(400, ['error' => 'Impossible de supprimer son propre compte']);
        $admins = read_json($ADMINS);
        if (!isset($admins[$u])) out(404, ['error' => 'Compte inconnu']);
        $owners = array_filter($admins, fn($x) => ($x['role'] ?? '') === 'owner');
        if (($admins[$u]['role'] ?? '') === 'owner' && count($owners) <= 1) out(400, ['error' => 'Impossible de supprimer le dernier propriétaire']);
        mutate_json($ADMINS, function ($a) use ($u) { unset($a[$u]); return $a; });
        out(200, ['ok' => true]);
    }
}
out(400, ['error' => 'Action inconnue']);

/* ================================================================ fonctions */

function device_endpoint(string $action, array $in): never {
    global $DEVICES, $PAIRS, $CMDDIR, $LOCDIR, $PHOTODIR, $HEARTBEAT_SEC, $LOC_HISTORY,
           $MAX_PHOTO_BYTES, $PHOTO_KEEP, $BATTERY_LOW;

    if ($action === 'enroll') {
        $code  = strtoupper(sstr($in['code'] ?? '', 20));
        $model = sstr($in['model'] ?? '', 80);
        $name  = sstr($in['name'] ?? '', 60);
        $pairs = read_json($PAIRS);
        $rec   = $pairs[sha($code)] ?? null;
        if (!$rec || ($rec['expires'] ?? 0) < time()) out(403, ['error' => 'Code d’appairage invalide ou expiré']);
        mutate_json($PAIRS, function ($p) use ($code) { unset($p[sha($code)]); return $p; });
        $deviceId = token(10); $deviceToken = token(32);
        mutate_json($DEVICES, function ($d) use ($deviceId, $deviceToken, $model, $name, $rec) {
            $d[$deviceId] = ['name' => $name ?: ($rec['name'] ?? 'Appareil'), 'model' => $model,
                'token_hash' => sha($deviceToken), 'enrolledAt' => now_iso(), 'lastSeen' => now_iso(),
                'battery' => null, 'charging' => null, 'location' => null];
            return $d;
        });
        log_event($deviceId, 'enroll', 'Appareil appairé');
        out(200, ['ok' => true, 'deviceId' => $deviceId, 'deviceToken' => $deviceToken, 'heartbeat' => $HEARTBEAT_SEC]);
    }

    // authentification appareil (heartbeat + upload-photo)
    $deviceId = sstr($in['deviceId'] ?? '', 64);
    $tok      = sstr($in['deviceToken'] ?? '', 128);
    $devs     = read_json($DEVICES);
    $d        = $devs[$deviceId] ?? null;
    if (!$d || !hash_equals((string)($d['token_hash'] ?? ''), sha($tok))) out(401, ['error' => 'Appareil non reconnu']);

    if ($action === 'upload-photo') {
        $cmdId  = sstr($in['commandId'] ?? '', 32);
        $camera = in_array(($in['camera'] ?? 'front'), ['front', 'back'], true) ? $in['camera'] : 'front';
        $data   = base64_decode((string)($in['image'] ?? ''), true);
        if ($data === false || strlen($data) < 100) out(400, ['error' => 'Image invalide']);
        if (strlen($data) > $MAX_PHOTO_BYTES) out(413, ['error' => 'Photo trop volumineuse']);
        if (substr($data, 0, 2) !== "\xFF\xD8") out(400, ['error' => 'JPEG attendu']);
        $photoId = token(8);
        $dir = "$PHOTODIR/$deviceId";
        if (!is_dir($dir)) @mkdir($dir, 0770, true);
        if (@file_put_contents("$dir/$photoId.jpg", $data) === false) out(500, ['error' => 'Écriture impossible']);
        mutate_json("$dir/_index.json", function ($x) use ($photoId, $camera, $PHOTO_KEEP, $dir) {
            $x[] = ['id' => $photoId, 'camera' => $camera, 'at' => now_iso(), 'bytes' => @filesize("$dir/$photoId.jpg") ?: 0];
            while (count($x) > $PHOTO_KEEP) { $old = array_shift($x); @unlink("$dir/{$old['id']}.jpg"); }
            return $x;
        });
        if ($cmdId !== '') mark_command($deviceId, $cmdId, true, 'Photo reçue');
        log_event($deviceId, 'photo', "Photo reçue ($camera)");
        out(200, ['ok' => true, 'photoId' => $photoId]);
    }

    /* --- heartbeat --- */
    $patch = ['lastSeen' => now_iso()];
    if (isset($in['battery']))  $patch['battery']  = max(0, min(100, (int)$in['battery']));
    if (isset($in['charging'])) $patch['charging'] = (bool)$in['charging'];

    // alerte batterie faible (une seule fois par franchissement de seuil)
    if (isset($patch['battery'])) {
        $was = $d['battery'] ?? 101;
        if ($patch['battery'] <= $BATTERY_LOW && $was > $BATTERY_LOW && empty($patch['charging'])) {
            log_event($deviceId, 'battery', "Batterie faible : {$patch['battery']} %");
        }
    }

    $loc = $in['location'] ?? null;
    if (is_array($loc) && isset($loc['lat'], $loc['lng'])) {
        $point = ['lat' => round((float)$loc['lat'], 6), 'lng' => round((float)$loc['lng'], 6),
            'acc' => isset($loc['acc']) ? round((float)$loc['acc'], 1) : null, 'at' => now_iso()];
        $patch['location'] = $point;
        mutate_json("$LOCDIR/$deviceId.json", function ($h) use ($point, $LOC_HISTORY) { $h[] = $point; return array_slice($h, -$LOC_HISTORY); });
    }
    mutate_json($DEVICES, function ($all) use ($deviceId, $patch) {
        if (isset($all[$deviceId])) $all[$deviceId] = array_merge($all[$deviceId], $patch);
        return $all;
    });

    $results = is_array($in['results'] ?? null) ? $in['results'] : [];
    foreach ($results as $r) if (isset($r['id'])) mark_command($deviceId, (string)$r['id'], !empty($r['ok']), sstr($r['message'] ?? '', 300));

    out(200, ['ok' => true, 'commands' => pending_commands($deviceId), 'heartbeat' => $HEARTBEAT_SEC]);
}

function mark_command(string $deviceId, string $cmdId, bool $ok, string $message): void {
    global $CMDDIR;
    mutate_json("$CMDDIR/$deviceId.json", function ($q) use ($cmdId, $ok, $message, $deviceId) {
        foreach ($q as &$c) {
            if ($c['id'] === $cmdId && $c['status'] === 'pending') {
                $c['status'] = $ok ? 'done' : 'failed';
                $c['resultAt'] = now_iso();
                if ($message !== '') $c['result'] = $message;
                log_event($deviceId, 'result', "« {$c['type']} » : " . ($ok ? 'exécutée' : 'échec'));
            }
        }
        return $q;
    });
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

function rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $f) if ($f !== '.' && $f !== '..') { $p = "$dir/$f"; is_dir($p) ? rrmdir($p) : @unlink($p); }
    @rmdir($dir);
}

/** URL de base du dossier qui contient ce script (se termine par /). */
function base_url(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $dir = preg_replace('#/[^/]*$#', '/', (string)($_SERVER['REQUEST_URI'] ?? '/'));
    return $scheme . '://' . $host . $dir;
}

/** Nom réel du fichier servi (gardien-api.php, api.php…) : le backend marche sous n'importe quel nom. */
function self_name(): string {
    $s = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    return ($s !== '' && str_ends_with($s, '.php')) ? $s : 'gardien-api.php';
}

/**
 * Page d'installation, ouverte quand on scanne le QR avec l'appareil photo d'un téléphone.
 * Propose de télécharger l'app puis de l'ouvrir pré-remplie (lien profond gardien://pair).
 */
function install_page(string $code): never {
    $base      = base_url();
    $apkUrl    = $base . self_name() . '?apk=1';
    $serverUrl = $base . self_name();
    $apkExists = is_file(__DIR__ . '/gardien.apk');
    $deep      = 'gardien://pair?u=' . rawurlencode($serverUrl) . '&c=' . rawurlencode($code);
    $c         = htmlspecialchars($code, ENT_QUOTES);
    $s         = htmlspecialchars($serverUrl, ENT_QUOTES);
    $apkUrlEsc = htmlspecialchars($apkUrl, ENT_QUOTES);
    $deepEsc   = htmlspecialchars($deep, ENT_QUOTES);
    $downloadBtn = $apkExists
        ? '<a class="btn primary" id="dl" href="' . $apkUrlEsc . '" download="gardien.apk"><svg viewBox="0 0 24 24" class="ic"><path d="M12 4v10m0 0l-3.5-3.5M12 14l3.5-3.5M6 18.5h12"/></svg>Télécharger l\'application</a>'
        : '<div class="alert">L\'application n\'est pas encore déposée sur ce serveur. Demandez le fichier à votre administrateur.</div>';
    // Téléchargement automatique à l'ouverture (scan du QR) : iframe cachée vers l'APK (attachment),
    // un seul téléchargement ; le bouton visible reste en secours.
    $auto      = $apkExists ? '<iframe src="' . $apkUrlEsc . '" style="display:none" title="telechargement"></iframe>' : '';
    $codeBlock = $c !== '' ? '<div class="codewrap"><span class="lbl">Code d\'appairage</span><div class="code">' . $c . '</div></div>' : '';
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo <<<HTML
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<title>Installer Gardien</title>
<style>
  :root{--b:#1487c9;--b2:#0e6da4;--bg:#eef1f4;--card:#fff;--tx:#1a1f26;--mut:#6b7684;--bd:#e4e8ed;
    --ok:#2c8f55;--warn:#b4700c;--warn-bg:#fdf3e2;--sh:0 1px 2px rgba(16,24,40,.05),0 8px 24px rgba(16,24,40,.07)}
  @media (prefers-color-scheme:dark){:root{--bg:#0c0e12;--card:#161a20;--tx:#e8ebef;--mut:#95a0ad;--bd:#262b33;
    --b:#3ba4e0;--b2:#64b6e8;--warn:#e3a145;--warn-bg:#352611;--sh:0 1px 2px rgba(0,0,0,.4),0 12px 34px rgba(0,0,0,.55)}}
  *{box-sizing:border-box}
  body{margin:0;font-family:"Segoe UI Variable Text","Segoe UI",system-ui,-apple-system,Roboto,"Helvetica Neue",Arial,sans-serif;
    color:var(--tx);background:var(--bg);-webkit-font-smoothing:antialiased;line-height:1.5}
  .ic{width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;flex:none}
  .hero{background:linear-gradient(135deg,var(--b),var(--b2));color:#fff;padding:36px 22px 32px;text-align:center}
  .badge{width:66px;height:66px;margin:0 auto 14px;border-radius:19px;background:rgba(255,255,255,.17);
    display:flex;align-items:center;justify-content:center}
  .badge svg{width:36px;height:36px;fill:none;stroke:#fff;stroke-width:1.7;stroke-linecap:round;stroke-linejoin:round}
  .hero h1{margin:0;font-size:25px;font-weight:690;letter-spacing:-.02em}
  .hero p{margin:5px 0 0;opacity:.92;font-size:14px}
  .wrap{max-width:460px;margin:-18px auto 0;padding:0 16px 30px}
  .card{background:var(--card);border:1px solid var(--bd);border-radius:16px;padding:18px;margin:16px 0;box-shadow:var(--sh)}
  .head{display:flex;align-items:center;gap:12px;margin:0 0 12px}
  .num{width:30px;height:30px;border-radius:50%;background:var(--b);color:#fff;font-weight:700;font-size:15px;
    display:flex;align-items:center;justify-content:center;flex:none}
  .head h2{margin:0;font-size:17px;font-weight:650}
  .btn{display:flex;align-items:center;justify-content:center;gap:9px;width:100%;padding:15px;border-radius:12px;
    font-weight:620;font-size:16px;text-decoration:none;margin:10px 0;transition:transform .12s}
  .btn.primary{background:var(--b);color:#fff;box-shadow:0 6px 16px rgba(20,135,201,.28)}
  .btn.primary:active{transform:translateY(1px)}
  .btn.ghost{background:transparent;color:var(--b);border:1.5px solid var(--b)}
  .mut{color:var(--mut);font-size:13.5px;margin:8px 0 0}
  .srv{font-size:13.5px;margin:12px 0 0;color:var(--mut)}.srv b{color:var(--tx);word-break:break-all}
  .codewrap{margin:12px 0 0}
  .lbl{display:block;font-size:11.5px;text-transform:uppercase;letter-spacing:.06em;color:var(--mut);margin:0 0 6px}
  .code{font:700 27px/1.1 ui-monospace,"Cascadia Mono",Consolas,monospace;letter-spacing:.08em;text-align:center;
    background:var(--bg);border:2px dashed var(--bd);border-radius:12px;padding:16px;user-select:all;color:var(--tx)}
  .alert{background:var(--warn-bg);color:var(--warn);padding:13px;border-radius:12px;font-size:14px;font-weight:500}
  .foot{display:flex;gap:9px;align-items:flex-start;color:var(--mut);font-size:12.5px;margin:18px 4px 0;line-height:1.45}
  .foot svg{width:17px;height:17px;flex:none;margin-top:1px;stroke:var(--ok);fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
</style>
</head>
<body>
  <div class="hero">
    <div class="badge"><svg viewBox="0 0 24 24"><path d="M12 3l7 3v5c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6z"/></svg></div>
    <h1>Gardien</h1>
    <p>Installation de l'application antivol</p>
  </div>
  <div class="wrap">
    <div class="card">
      <div class="head"><div class="num">1</div><h2>Installer l'application</h2></div>
      <p class="mut">Le téléchargement démarre automatiquement.</p>
      {$downloadBtn}
      <p class="mut">S'il ne démarre pas, touchez le bouton. Si Android prévient (Play Protect ou « sources inconnues »), choisissez <b>Installer quand même</b>.</p>
    </div>
    <div class="card">
      <div class="head"><div class="num">2</div><h2>Ouvrir et appairer</h2></div>
      <a class="btn ghost" href="{$deepEsc}"><svg viewBox="0 0 24 24" class="ic"><path d="M14 5h5v5M19 5l-9 9M19 13v6H5V5h6"/></svg>Ouvrir dans Gardien</a>
      <p class="mut">Une fois l'app installée, ce bouton l'ouvre déjà remplie — l'appairage se fait tout seul. Sinon, saisissez à la main&nbsp;:</p>
      <p class="srv">Serveur&nbsp;: <b>{$s}</b></p>
      {$codeBlock}
    </div>
    <div class="foot">
      <svg viewBox="0 0 24 24"><path d="M12 3l7 3v5c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6z"/><path d="M9 12l2 2 4-4"/></svg>
      <span>Gardien reste <b>visible</b> sur l'appareil (icône + notification permanente). Pour vos propres appareils uniquement.</span>
    </div>
  </div>
  {$auto}
</body>
</html>
HTML;
    exit;
}

/* ---- sessions admin (cookie, fichiers) ---- */
function start_session(string $user, string $role): string {
    global $SESSDIR;
    $sid = token(32);
    write_json("$SESSDIR/$sid.json", ['created' => time(), 'last' => time(), 'ip' => client_ip(), 'user' => $user, 'role' => $role]);
    setcookie('gardien_sid', $sid, ['expires' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
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
    if ($f && ($f['until'] ?? 0) > time() && ($f['count'] ?? 0) >= $MAX_FAILS) out(429, ['error' => 'Trop de tentatives. Réessayez plus tard.']);
}
function fail_bump(): void {
    global $FAILS, $FAIL_WINDOW;
    mutate_json($FAILS, function ($all) use ($FAIL_WINDOW) {
        $ip = client_ip();
        $f = $all[$ip] ?? ['count' => 0, 'until' => 0];
        if (($f['until'] ?? 0) < time()) $f = ['count' => 0, 'until' => time() + $FAIL_WINDOW];
        $f['count']++; $all[$ip] = $f;
        foreach ($all as $k => $v) if (($v['until'] ?? 0) < time() - 3600) unset($all[$k]);
        return $all;
    });
}
function fail_reset(): void {
    global $FAILS;
    mutate_json($FAILS, function ($all) { unset($all[client_ip()]); return $all; });
}
