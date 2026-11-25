<?php
/*
 * Sentinelle XDR — API de l'interface (point d'entrée unique).
 * -----------------------------------------------------------------------------------------
 * Toutes les réponses sont en JSON : { ok: true, data: … } ou { ok: false, error: "…", hint: "…" }.
 * Lecture : GET  api.php?action=…        Actions : POST api.php?action=…  (corps JSON)
 *
 * Sécurité :
 *  - accessible uniquement depuis le réseau local (config/config.php › allowed_networks) ;
 *  - session obligatoire sauf « session », « setup » et « login » ;
 *  - toute action (POST) exige le jeton anti-CSRF envoyé dans l'en-tête X-CSRF-Token ;
 *  - la mise en service (création du mot de passe) se fait depuis le serveur lui-même ;
 *  - chaque action est inscrite au journal d'audit.
 */
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
header('X-Robots-Tag: noindex, nofollow');

$action = (string)($_GET['action'] ?? '');
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$in = [];

/** Lit un paramètre de requête (GET) ou du corps JSON (POST). */
function arg(string $k, mixed $default = ''): mixed
{
    global $in;
    return $in[$k] ?? $_GET[$k] ?? $default;
}

function need_post(): void
{
    global $method;
    if ($method !== 'POST') throw new RuntimeException('Méthode POST attendue.', 405);
}

try {
    if (!ip_allowed(client_ip())) {
        json_out(403, ['ok' => false, 'error' => 'Accès refusé : Sentinelle n\'est accessible que depuis le réseau local de la maison.']);
    }
    ensure_data_dir();
    ini_set('error_log', data_path('php-errors.log'));
    if (Db::kv('cron_key') === null) Db::setKv('cron_key', bin2hex(random_bytes(16)));

    /* ---------- tâche planifiée (Windows : tools\Installer-XDR.ps1) ---------- */
    if ($action === 'cron') {
        $key = (string)($_GET['key'] ?? '');
        if (!is_local_request() || !hash_equals((string)Db::kv('cron_key'), $key)) json_out(403, ['ok' => false, 'error' => 'Refusé.']);
        json_out(200, ['ok' => true, 'data' => Collector::run(Network::make(), 'cron')]);
    }

    Auth::start();
    if ($method === 'POST') {
        $raw = (string)file_get_contents('php://input', false, null, 0, 1_000_000);
        $in = $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($in)) json_out(400, ['ok' => false, 'error' => 'Requête invalide (JSON attendu).']);
    }

    /* ---------- routes publiques ---------- */
    if ($action === 'session') {
        $u = Auth::user();
        json_out(200, ['ok' => true, 'data' => [
            'authenticated' => $u !== null, 'needs_setup' => Auth::needsSetup(), 'setup_allowed' => is_local_request(),
            'totp' => Auth::totpEnabled(), 'csrf' => $u ? Auth::csrf() : null, 'mode' => Network::isDemo() ? 'demo' : 'freebox',
            'version' => XDR_VERSION, 'https' => is_https(), 'poll' => (int)Settings::get('poll_seconds'),
        ]]);
    }
    if ($action === 'setup') {
        need_post();
        if (!Auth::needsSetup()) json_out(409, ['ok' => false, 'error' => 'Sentinelle est déjà configurée.']);
        if (!is_local_request()) {
            json_out(403, ['ok' => false, 'error' => 'Pour des raisons de sécurité, la mise en service se fait depuis le serveur lui-même : ouvrez http://localhost/xdr/ sur ce PC.']);
        }
        Auth::setup((string)arg('password'));
        Auth::login((string)arg('password'), '');
        json_out(200, ['ok' => true, 'data' => ['csrf' => Auth::csrf()]]);
    }
    if ($action === 'login') {
        need_post();
        Auth::login((string)arg('password'), (string)arg('code'));
        json_out(200, ['ok' => true, 'data' => ['csrf' => Auth::csrf()]]);
    }

    /* ---------- au-delà : session obligatoire ---------- */
    if (Auth::user() === null) json_out(401, ['ok' => false, 'error' => 'Session expirée : reconnectez-vous.', 'code' => 'auth']);
    if ($method === 'POST') {
        Auth::checkCsrf();
    } else {
        session_write_close(); // libère la session : les rafraîchissements en parallèle ne s'attendent pas
    }
    if (Db::kv('ui_https') !== (is_https() ? '1' : '0')) Db::setKv('ui_https', is_https() ? '1' : '0');

    $mac = mac_norm((string)arg('mac'));
    $needMac = static function () use ($mac): string {
        if ($mac === '') throw new InvalidArgumentException('Adresse MAC manquante ou invalide.');
        return $mac;
    };
    $target = $mac;

    $data = match ($action) {
        /* ----- lecture ----- */
        'overview' => (function () {
            $n = Network::make();
            Collector::maybe($n);
            $devs = $n->devices(true);
            $sys = $n->system();
            $temps = array_map(fn($s) => (float)($s['value'] ?? 0), (array)($sys['sensors'] ?? []));
            $count = fn(callable $f) => count(array_filter($devs, $f));
            return [
                'mode' => Network::isDemo() ? 'demo' : 'freebox',
                'box' => ['model' => clean_text($sys['model_info']['pretty_name'] ?? ($n->fbx->info()['api']['box_model'] ?? 'Freebox'), 40),
                    'firmware' => clean_text($sys['firmware_version'] ?? '', 20), 'uptime' => (int)($sys['uptime_val'] ?? 0),
                    'temp' => $temps ? max($temps) : null],
                'link' => $n->link(),
                'devices' => $devs,
                'counts' => [
                    'total' => count($devs), 'online' => $count(fn($d) => $d['online']),
                    'new' => $count(fn($d) => $d['flags']['new']), 'blocked' => $count(fn($d) => $d['flags']['blocked']),
                    'isolated' => $count(fn($d) => $d['flags']['isolated']),
                    'wifi' => $count(fn($d) => $d['online'] && ($d['conn']['kind'] ?? '') === 'wifi'),
                    'ethernet' => $count(fn($d) => $d['online'] && ($d['conn']['kind'] ?? '') === 'ethernet'),
                ],
                'score' => Detect::score(),
                'alerts' => Detect::list('open', 6),
                'dns' => Dns::enabled(),
                'cron' => (int)Db::kv('cron_last', '0'),
                'ts' => time(),
            ];
        })(),
        'devices' => (function () {
            $n = Network::make();
            return ['devices' => $n->devices(true), 'profiles' => array_values($n->netControl()['profiles']), 'types' => Network::TYPES, 'dns' => Dns::enabled()];
        })(),
        'device' => Network::make()->deviceDetail($needMac()) + ['types' => Network::TYPES, 'dns_enabled' => Dns::enabled()],
        'traffic' => (function () {
            $n = Network::make();
            $range = in_array(arg('range'), ['hour', 'day', 'week'], true) ? (string)arg('range') : 'hour';
            return $n->trafficHistory($range) + ['link' => $n->link()];
        })(),
        'alerts' => ['alerts' => Detect::list((string)arg('status', 'active')), 'score' => Detect::score()],
        'access' => (function () {
            $n = Network::make();
            $nc = $n->netControl();
            $devs = $n->devices(false);
            $byMac = [];
            foreach ($devs as $d) $byMac[$d['mac']] = ['mac' => $d['mac'], 'name' => $d['name'], 'type' => $d['type'], 'online' => $d['online']];
            $profiles = [];
            foreach ($nc['profiles'] as $p) {
                $p['devices'] = array_values(array_map(fn($m) => $byMac[$m] ?? ['mac' => $m, 'name' => $m, 'type' => 'other', 'online' => false], $p['macs']));
                $profiles[] = $p;
            }
            return ['profiles' => $profiles, 'schedules' => Schedules::all(), 'error' => $nc['error'],
                'unassigned' => array_values(array_filter($byMac, fn($d) => !isset($nc['byMac'][$d['mac']])))];
        })(),
        'exposure' => Network::make()->exposure() + ['score' => Detect::score()],
        'wifi' => Network::make()->wifi(),
        'box' => Network::make()->box(),
        'player' => Network::make()->players(),
        'updates' => News::updates(clean_text(Network::make()->system()['firmware_version'] ?? '', 20)),
        'news' => News::get((bool)arg('refresh', false)),
        'kids' => (function () {
            $n = Network::make();
            $catalog = [];
            $catalogError = null;
            if (Dns::enabled()) {
                try {
                    $catalog = Dns::serviceCatalog();
                } catch (Throwable $e) {
                    $catalogError = $e->getMessage();
                }
            }
            $dhcp = [];
            try {
                $dhcp = $n->dhcpDns();
            } catch (Throwable) {
            }
            $candidates = array_map(fn($d) => ['mac' => $d['mac'], 'name' => $d['name'], 'type' => $d['type'], 'type_label' => $d['type_label'],
                'owner' => $d['owner'], 'online' => $d['online']], $n->devices(false));
            return [
                'kids' => Kids::summary(arg('range') === 'week' ? 'week' : 'today'),
                'dns' => Dns::enabled(),
                'catalog' => $catalog, 'catalog_error' => $catalogError,
                'night' => [Settings::get('night_start'), Settings::get('night_end')],
                'candidates' => $candidates,
                'dhcp' => ['dns' => $dhcp, 'server_ip' => server_lan_ip()],
                'report' => (bool)Settings::get('kids_report'),
            ];
        })(),
        'kid_activity' => ['activity' => Dns::enabled() ? Kids::activity(clean_text((string)arg('owner'), 30)) : []],
        'journal' => [
            'events' => Db::all('SELECT e.ts, e.kind, e.mac, e.text, COALESCE(d.name, \'\') AS name FROM events e LEFT JOIN devices d ON d.mac = e.mac ORDER BY e.ts DESC LIMIT 300'),
            'audit' => Db::all('SELECT ts, ip, action, target, ok, detail FROM audit ORDER BY ts DESC LIMIT 300'),
        ],
        'settings' => (function () {
            $demo = Network::isDemo();
            $real = new Freebox(new CurlTransport(), false);
            $perms = null;
            $permError = null;
            $fbx = $demo ? Freebox::make() : $real;
            try {
                if ($fbx->paired()) $perms = $fbx->permissions();
            } catch (Throwable $e) {
                $permError = $e->getMessage() . (($e instanceof FreeboxError && $e->hint) ? ' — ' . $e->hint : '');
            }
            return [
                'settings' => Settings::publicView(),
                'mode' => $demo ? 'demo' : 'freebox',
                'freebox' => $real->info(),
                'permissions' => $perms, 'permissions_error' => $permError, 'permission_labels' => Freebox::PERMISSIONS,
                'cron' => ['last' => (int)Db::kv('cron_last', '0'), 'key' => (string)Db::kv('cron_key'),
                    'path' => rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/') . '/api.php'],
                'totp' => Auth::totpEnabled(),
                'diag' => diagnostics(),
            ];
        })(),
        'freebox_pair_status' => (function () {
            $st = (new Freebox(new CurlTransport(), false))->pairStatus();
            if ($st['status'] === 'granted') {
                // nouvelle box : analyse complète dès le prochain rafraîchissement
                Db::setKv('collect_last', '0');
                Db::setKv('detect_last', '0');
                Network::resetMode();
            }
            return $st;
        })(),

        /* ----- actions ----- */
        'logout' => (function () {
            need_post();
            Db::audit('logout');
            Auth::logout();
            return ['ok' => true];
        })(),
        'device_block' => (need_post() ?? Network::make()->blockDevice($needMac(), max(0, min(10080, (int)arg('minutes', 0))),
            in_array(arg('scope'), ['profile', 'dedicated'], true) ? (string)arg('scope') : '')),
        'device_allow' => (need_post() ?? Network::make()->allowDevice($needMac(), (bool)arg('force', false))),
        'device_isolate' => (need_post() ?? Network::make()->isolate($needMac())),
        'device_release' => (need_post() ?? Network::make()->release($needMac())),
        'device_wake' => (need_post() ?? Network::make()->wake($needMac())),
        'device_rename' => (need_post() ?? Network::make()->rename($needMac(), (string)arg('name'), (string)arg('type'))),
        'device_approve' => (need_post() ?? Network::make()->approve($needMac(), (bool)arg('approved', true), (string)arg('note'))),
        'profile_mode' => (function () use (&$target) {
            need_post();
            $target = 'profil ' . (int)arg('id');
            return Network::make()->profileMode((int)arg('id'), (string)arg('mode'), max(0, min(10080, (int)arg('minutes', 0))));
        })(),
        'schedule_save' => (need_post() ?? Schedules::save($in)),
        'device_owner' => (function () use ($needMac) {
            need_post();
            Kids::setOwner($needMac(), (string)arg('owner'));
            return ['ok' => true];
        })(),
        'kid_protect' => (function () use (&$target) {
            need_post();
            $target = clean_text((string)arg('owner'), 30);
            return Kids::protect($target, (bool)arg('parental', false), (bool)arg('safesearch', false), (array)arg('blocked', []));
        })(),
        'dns_dhcp' => (function () {
            need_post();
            $mode = (string)arg('mode');
            $ip = (string)arg('ip') ?: server_lan_ip();
            $servers = match ($mode) {
                'adguard' => [$ip],
                'adguard_fallback' => [$ip, '192.168.1.254'],
                'freebox' => ['192.168.1.254'],
                default => throw new InvalidArgumentException('Choix inconnu.'),
            };
            if ($mode !== 'freebox' && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                throw new InvalidArgumentException('Adresse IP du serveur inconnue : ouvrez Sentinelle depuis un autre appareil (pas « localhost ») ou indiquez l\'IP du PC.');
            }
            return Network::make()->setDhcpDns($servers);
        })(),
        'schedule_delete' => (function () {
            need_post();
            Schedules::delete((int)arg('id'));
            return ['ok' => true];
        })(),
        'alert_status' => (function () use (&$target) {
            need_post();
            $target = 'alerte ' . (int)arg('id');
            Detect::setStatus((int)arg('id'), (string)arg('status'));
            return ['ok' => true, 'score' => Detect::score()];
        })(),
        'fix' => (function () use (&$target) {
            need_post();
            $target = (string)arg('fix') . (arg('id') !== '' ? ' #' . arg('id') : '');
            $n = Network::make();
            $r = $n->applyFix((string)arg('fix'), ['id' => (string)arg('id')]);
            Db::setKv('detect_last', '0');
            Detect::maybeRun($n, 0);
            return $r + ['score' => Detect::score()];
        })(),
        'wifi_filter' => (need_post() ?? Network::make()->wifiFilter((string)arg('op'), $in)),
        'detect_run' => (function () {
            need_post();
            $n = Network::make();
            $new = Detect::run($n);
            Notify::alerts($new);
            return ['new' => count($new), 'score' => Detect::score()];
        })(),
        'settings_save' => (function () {
            need_post();
            $before = Settings::get('mode');
            $s = Settings::save($in = (array)arg('settings', []));
            Network::resetMode();
            if ($before !== $s['mode']) {
                cache_drop('fbx-hosts', 'fbx-live', 'demo-hosts', 'demo-live', 'dns-recent');
                Db::setKv('collect_last', '0');
                Db::setKv('detect_last', '0');
            }
            return ['settings' => Settings::publicView()];
        })(),
        'password_change' => (function () {
            need_post();
            Auth::changePassword((string)arg('current'), (string)arg('next'));
            return ['ok' => true];
        })(),
        'totp_begin' => (need_post() ?? Auth::totpBegin()),
        'totp_enable' => (need_post() ?? Auth::totpEnable((string)arg('password'), (string)arg('code'))),
        'totp_disable' => (function () {
            need_post();
            Auth::totpDisable((string)arg('password'));
            return ['ok' => true];
        })(),
        'freebox_pair_start' => (function () {
            need_post();
            $name = clean_text(gethostname() ?: 'Serveur Sentinelle', 40);
            return (new Freebox(new CurlTransport(), false))->pairStart($name) + ['device_name' => $name];
        })(),
        'freebox_unpair' => (function () {
            need_post();
            Auth::confirm((string)arg('password'));
            (new Freebox(new CurlTransport(), false))->unpair();
            Network::resetMode();
            return ['ok' => true];
        })(),
        'notify_test' => (function () {
            need_post();
            Notify::send('Sentinelle · test', 'Les notifications fonctionnent. Vous serez prévenu des alertes importantes.', 'moyenne');
            return ['ok' => true];
        })(),
        'adguard_test' => (need_post() ?? Dns::test()),
        'box_reboot' => (function () {
            need_post();
            Auth::confirm((string)arg('password'));
            return Network::make()->reboot();
        })(),
        'demo_reset' => (function () {
            need_post();
            if (!Network::isDemo()) throw new RuntimeException('Disponible uniquement en mode démonstration.', 409);
            DemoBox::reset();
            foreach (['DELETE FROM devices', 'DELETE FROM alerts', 'DELETE FROM events', 'DELETE FROM traffic', 'DELETE FROM dev_traffic', 'DELETE FROM schedules'] as $sql) Db::q($sql);
            Db::setKv('collect_last', '0');
            Db::setKv('detect_last', '0');
            cache_drop('demo-hosts', 'demo-live', 'dns-recent');
            return ['ok' => true];
        })(),
        'cron_key_regen' => (function () {
            need_post();
            Db::setKv('cron_key', bin2hex(random_bytes(16)));
            return ['key' => Db::kv('cron_key')];
        })(),
        default => throw new RuntimeException('Action inconnue.', 404),
    };

    if ($method === 'POST' && !in_array($action, ['logout', 'detect_run'], true)) {
        $detail = is_array($data) ? json_encode(array_intersect_key($data, array_flip(['profile', 'until', 'still_denied', 'report', 'done', 'needs_choice'])), JSON_UNESCAPED_UNICODE) : '';
        Db::audit($action, $target, true, $detail === '[]' ? '' : (string)$detail);
    }
    json_out(200, ['ok' => true, 'data' => $data]);
} catch (FreeboxError $e) {
    if ($method === 'POST' && $action !== '') Db::audit($action, (string)($_GET['mac'] ?? ''), false, $e->getMessage());
    json_out($e->http, ['ok' => false, 'error' => $e->getMessage(), 'hint' => $e->hint, 'code' => $e->codeName]);
} catch (InvalidArgumentException $e) {
    json_out(400, ['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    $code = $e->getCode();
    $http = is_int($code) && $code >= 400 && $code < 600 ? $code : 500;
    if ($http === 500) error_log('Sentinelle : ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if ($method === 'POST' && !in_array($action, ['login', 'setup', ''], true)) Db::audit($action, '', false, $e->getMessage());
    json_out($http, ['ok' => false, 'error' => ($e instanceof RuntimeException || $e instanceof PDOException) ? $e->getMessage()
        : 'Erreur interne du serveur (détails dans data/php-errors.log).']);
}

/** Adresse IPv4 de ce serveur sur le réseau local (pour le DNS distribué par la box). */
function server_lan_ip(): string
{
    if (Network::isDemo()) return '192.168.1.51';
    $host = (string)parse_url((string)(Settings::get('adguard')['url'] ?? ''), PHP_URL_HOST);
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && !str_starts_with($host, '127.')) return $host;
    $srv = normalize_ip((string)($_SERVER['SERVER_ADDR'] ?? $_SERVER['LOCAL_ADDR'] ?? ''));
    return filter_var($srv, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && !str_starts_with($srv, '127.') ? $srv : '';
}

/** Vérifications de l'installation (page Paramètres › Diagnostic). */
function diagnostics(): array
{
    $ext = fn(string $e) => extension_loaded($e);
    $checks = [
        ['label' => 'PHP ' . PHP_VERSION, 'ok' => PHP_VERSION_ID >= 80100, 'fix' => 'PHP 8.1 ou plus est requis.'],
        ['label' => 'Extension curl', 'ok' => $ext('curl'), 'fix' => 'php.ini : extension=curl'],
        ['label' => 'Extension pdo_sqlite', 'ok' => $ext('pdo_sqlite'), 'fix' => 'php.ini : extension=pdo_sqlite (et extension=sqlite3)'],
        ['label' => 'Extension openssl', 'ok' => $ext('openssl'), 'fix' => 'php.ini : extension=openssl'],
        ['label' => 'Extension mbstring', 'ok' => $ext('mbstring'), 'fix' => 'php.ini : extension=mbstring'],
        ['label' => 'Lecture des flux RSS (SimpleXML)', 'ok' => $ext('simplexml'), 'fix' => 'php.ini : extension=simplexml'],
        ['label' => 'Dossier de données accessible en écriture', 'ok' => is_writable(data_dir()), 'fix' => 'Droit « Modification » pour IIS_IUSRS et IUSR sur le dossier data.'],
        ['label' => 'Connexion HTTPS à cette interface', 'ok' => is_https(), 'fix' => 'Recommandé : liaison HTTPS dans IIS (voir le guide).', 'warn' => true],
        ['label' => 'Tâche planifiée (surveillance continue)', 'ok' => (int)Db::kv('cron_last', '0') > time() - 900,
            'fix' => 'Lancez tools\\Installer-XDR.cmd en administrateur, ou créez la tâche décrite dans le guide.', 'warn' => true],
        ['label' => 'Autorités de certification pour HTTPS (actualités)', 'ok' => PHP_OS_FAMILY !== 'Windows' || (bool)ini_get('curl.cainfo') || defined('CURLSSLOPT_NATIVE_CA'),
            'fix' => 'php.ini : curl.cainfo = "C:\\PHP\\extras\\ssl\\cacert.pem" (fichier cacert.pem de curl.se)', 'warn' => true],
    ];
    return ['checks' => $checks, 'php' => PHP_VERSION, 'os' => PHP_OS_FAMILY, 'server' => clean_text($_SERVER['SERVER_SOFTWARE'] ?? '', 60),
        'data_dir' => data_dir(), 'client_ip' => client_ip()];
}
