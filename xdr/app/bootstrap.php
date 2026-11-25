<?php
/*
 * Sentinelle XDR — amorçage commun (API web, tâche planifiée, outils en ligne de commande).
 * -----------------------------------------------------------------------------------------
 * Ce dossier « app » contient le moteur. Il ne doit JAMAIS être servi par le serveur web :
 * le web.config (IIS) et le .htaccess (Apache) de la racine le masquent.
 */
declare(strict_types=1);

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('Sentinelle XDR demande PHP 8.1 ou plus (PHP ' . PHP_VERSION . ' détecté).');
}

const XDR_VERSION = '1.0.0';
define('XDR_ROOT', str_replace('\\', '/', dirname(__DIR__)));

/* ---------------------------------------------------------------------------------------
 * Configuration « figée » (config/config.php) : ce qui touche à la sécurité ne se modifie
 * pas depuis l'interface web (réseaux autorisés, emplacement des données…).
 * ------------------------------------------------------------------------------------- */
final class Cfg
{
    private static array $c = [];

    public static function load(): void
    {
        $defaults = [
            'data_dir'               => XDR_ROOT . '/data',
            // Adresses autorisées à ouvrir l'interface : réseau local uniquement.
            'allowed_networks'       => ['127.0.0.0/8', '::1/128', '10.0.0.0/8', '172.16.0.0/12',
                                         '192.168.0.0/16', '100.64.0.0/10', 'fc00::/7', 'fe80::/10'],
            // IPv6 : la Freebox donne une adresse publique à chaque appareil. On accepte en plus
            // les clients du même préfixe /64 que ce serveur (donc du même réseau local).
            'allow_same_ipv6_prefix' => true,
            'timezone'               => 'Europe/Paris',
            'session_idle_minutes'   => 30,
            'session_max_hours'      => 12,
        ];
        $file = XDR_ROOT . '/config/config.php';
        $user = is_file($file) ? (require $file) : [];
        self::$c = array_replace($defaults, is_array($user) ? $user : []);
        if (!@date_default_timezone_set((string)self::$c['timezone'])) {
            date_default_timezone_set('Europe/Paris');
        }
    }

    public static function get(string $k, mixed $default = null): mixed
    {
        return self::$c[$k] ?? $default;
    }
}

/* ---------------------------------------------------------------------------------------
 * Réglages modifiables depuis l'interface (data/settings.json), validés champ par champ.
 * ------------------------------------------------------------------------------------- */
final class Settings
{
    public const DEFAULTS = [
        'mode'                => 'auto',          // auto (démo tant que la Freebox n'est pas associée) | demo | freebox
        'freebox_url'         => 'https://mafreebox.freebox.fr',
        'freebox_http_fallback' => true,          // repli en HTTP local si le HTTPS échoue
        'api_version'         => 'auto',
        'poll_seconds'        => 5,
        'upload_spike_mbps'   => 8,               // envoi soutenu au-delà de N Mb/s…
        'upload_spike_factor' => 6,               // …et N fois au-dessus de l'habitude de l'appareil
        'night_start'         => '01:00',
        'night_end'           => '05:00',
        'retention_days'      => 30,
        'dns_visibility'      => true,
        'adguard'             => ['enabled' => false, 'url' => '', 'user' => '', 'pass' => ''],
        'notify'              => ['type' => 'none', 'url' => '', 'token' => '', 'chat_id' => '', 'min_severity' => 'elevee'],
        'news_sources'        => [
            ['name' => 'Univers Freebox', 'url' => 'https://www.universfreebox.com/feed/'],
            ['name' => 'Freenews',        'url' => 'https://www.freenews.fr/feed'],
        ],
    ];

    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            $saved = read_json(data_path('settings.json'));
            $s = self::DEFAULTS;
            foreach ($saved as $k => $v) {
                if (!array_key_exists($k, $s)) continue;
                $s[$k] = is_array($s[$k]) && is_array($v) && !array_is_list($s[$k]) ? array_replace($s[$k], $v) : $v;
            }
            self::$cache = $s;
        }
        return self::$cache;
    }

    public static function get(string $k): mixed
    {
        return self::all()[$k] ?? null;
    }

    /** Enregistre un sous-ensemble validé ; renvoie les réglages complets. */
    public static function save(array $in): array
    {
        $s = self::all();
        $str = static fn($v, int $max = 300) => mb_substr(trim((string)$v), 0, $max);
        $time = static fn($v, string $d) => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)$v) ? (string)$v : $d;
        $url = static function ($v): string {
            $v = trim((string)$v);
            return ($v === '' || preg_match('~^https?://[^\s"<>]+$~i', $v)) ? mb_substr($v, 0, 300) : '';
        };

        if (isset($in['mode']) && in_array($in['mode'], ['auto', 'demo', 'freebox'], true)) $s['mode'] = $in['mode'];
        if (isset($in['freebox_url'])) {
            $u = rtrim($url($in['freebox_url']), '/');
            if ($u !== '') $s['freebox_url'] = $u;
        }
        if (isset($in['freebox_http_fallback'])) $s['freebox_http_fallback'] = (bool)$in['freebox_http_fallback'];
        if (isset($in['api_version']) && preg_match('/^(auto|v?\d{1,2})$/', (string)$in['api_version'])) $s['api_version'] = (string)$in['api_version'];
        if (isset($in['poll_seconds'])) $s['poll_seconds'] = max(3, min(60, (int)$in['poll_seconds']));
        if (isset($in['upload_spike_mbps'])) $s['upload_spike_mbps'] = max(1, min(1000, (float)$in['upload_spike_mbps']));
        if (isset($in['upload_spike_factor'])) $s['upload_spike_factor'] = max(2, min(50, (float)$in['upload_spike_factor']));
        if (isset($in['night_start'])) $s['night_start'] = $time($in['night_start'], $s['night_start']);
        if (isset($in['night_end'])) $s['night_end'] = $time($in['night_end'], $s['night_end']);
        if (isset($in['retention_days'])) $s['retention_days'] = max(2, min(365, (int)$in['retention_days']));
        if (isset($in['dns_visibility'])) $s['dns_visibility'] = (bool)$in['dns_visibility'];

        if (isset($in['adguard']) && is_array($in['adguard'])) {
            $a = $in['adguard'];
            $s['adguard']['enabled'] = (bool)($a['enabled'] ?? $s['adguard']['enabled']);
            if (isset($a['url'])) $s['adguard']['url'] = rtrim($url($a['url']), '/');
            if (isset($a['user'])) $s['adguard']['user'] = $str($a['user'], 100);
            // Mot de passe : une valeur vide ou masquée ne remplace pas celui enregistré.
            if (isset($a['pass']) && $a['pass'] !== '' && $a['pass'] !== '••••••••') $s['adguard']['pass'] = $str($a['pass'], 200);
        }
        if (isset($in['notify']) && is_array($in['notify'])) {
            $n = $in['notify'];
            if (isset($n['type']) && in_array($n['type'], ['none', 'ntfy', 'gotify', 'discord', 'telegram', 'webhook'], true)) $s['notify']['type'] = $n['type'];
            if (isset($n['url'])) $s['notify']['url'] = $url($n['url']);
            if (isset($n['token']) && $n['token'] !== '••••••••') $s['notify']['token'] = $str($n['token'], 300);
            if (isset($n['chat_id'])) $s['notify']['chat_id'] = $str($n['chat_id'], 60);
            if (isset($n['min_severity']) && isset(Detect::WEIGHTS[$n['min_severity']])) $s['notify']['min_severity'] = $n['min_severity'];
        }
        if (isset($in['news_sources']) && is_array($in['news_sources'])) {
            $list = [];
            foreach (array_slice($in['news_sources'], 0, 8) as $src) {
                if (!is_array($src)) continue;
                $u = $url($src['url'] ?? '');
                $name = $str($src['name'] ?? '', 60);
                if ($u !== '' && str_starts_with(strtolower($u), 'https://') && $name !== '') $list[] = ['name' => $name, 'url' => $u];
            }
            if ($list) $s['news_sources'] = $list;
        }
        write_json(data_path('settings.json'), $s);
        self::$cache = $s;
        return $s;
    }

    /** Version affichable (secrets masqués). */
    public static function publicView(): array
    {
        $s = self::all();
        if ($s['adguard']['pass'] !== '') $s['adguard']['pass'] = '••••••••';
        if ($s['notify']['token'] !== '') $s['notify']['token'] = '••••••••';
        return $s;
    }
}

/* ------------------------------------------------------------------ chemins & fichiers */

function data_dir(): string
{
    static $d = null;
    return $d ??= rtrim(str_replace('\\', '/', (string)Cfg::get('data_dir')), '/');
}

function data_path(string $rel): string
{
    return data_dir() . '/' . ltrim($rel, '/');
}

/** Crée le dossier de données et ses protections (refus d'accès direct, Apache puis IIS). */
function ensure_data_dir(): void
{
    foreach (['', '/cache', '/sessions'] as $sub) {
        $d = data_dir() . $sub;
        if (!is_dir($d) && !@mkdir($d, 0770, true) && !is_dir($d)) {
            throw new RuntimeException("Impossible de créer le dossier de données ($d). Donnez au compte du serveur web (IIS_IUSRS et IUSR) le droit « Modification » sur le dossier.");
        }
    }
    if (!is_writable(data_dir())) {
        throw new RuntimeException('Le dossier de données n\'est pas accessible en écriture. Sous Windows : clic droit sur le dossier data › Propriétés › Sécurité › IIS_IUSRS et IUSR › Modification.');
    }
    $deny = data_path('web.config');
    if (!is_file($deny)) {
        @file_put_contents($deny, '<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer><security><requestFiltering>'
            . '<fileExtensions allowUnlisted="false" /></requestFiltering></security></system.webServer></configuration>');
    }
    if (!is_file(data_path('.htaccess'))) @file_put_contents(data_path('.htaccess'), "Require all denied\nDeny from all\n");
}

function read_json(string $file): array
{
    if (!is_file($file)) return [];
    $d = json_decode((string)@file_get_contents($file), true);
    return is_array($d) ? $d : [];
}

function write_json(string $file, array $data): bool
{
    $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $ok = @file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX) !== false;
    if ($ok && !@rename($tmp, $file)) {
        // Windows : un lecteur peut bloquer le remplacement, on retente une fois.
        usleep(50000);
        $ok = @rename($tmp, $file);
    }
    if (!$ok) @unlink($tmp);
    return $ok;
}

/** Petit cache disque (quelques secondes) pour absorber les appels simultanés. */
function cache_get(string $key, int $ttl, callable $producer): mixed
{
    $f = data_path('cache/' . preg_replace('/[^a-z0-9_.-]/i', '_', $key) . '.json');
    if ($ttl > 0 && is_file($f) && filemtime($f) > time() - $ttl) {
        $d = json_decode((string)@file_get_contents($f), true);
        if (is_array($d) && array_key_exists('v', $d)) return $d['v'];
    }
    $v = $producer();
    @file_put_contents($f, json_encode(['v' => $v], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    return $v;
}

function cache_drop(string ...$keys): void
{
    foreach ($keys as $key) @unlink(data_path('cache/' . preg_replace('/[^a-z0-9_.-]/i', '_', $key) . '.json'));
}

/* ------------------------------------------------------------------ réseau & adresses */

function ip_in(string $ip, string $cidr): bool
{
    [$net, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
    $ipb = @inet_pton($ip);
    $netb = @inet_pton((string)$net);
    if ($ipb === false || $netb === false || strlen($ipb) !== strlen($netb)) return false;
    $bits = $bits === null ? strlen($ipb) * 8 : (int)$bits;
    $bytes = intdiv($bits, 8);
    $rem = $bits % 8;
    if ($bytes > 0 && strncmp($ipb, $netb, $bytes) !== 0) return false;
    if ($rem === 0) return true;
    $mask = (0xff << (8 - $rem)) & 0xff;
    return (ord($ipb[$bytes]) & $mask) === (ord($netb[$bytes]) & $mask);
}

function normalize_ip(string $ip): string
{
    $ip = trim($ip);
    if (stripos($ip, '::ffff:') === 0 && filter_var(substr($ip, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return substr($ip, 7);
    return $ip;
}

function client_ip(): string
{
    return normalize_ip((string)($_SERVER['REMOTE_ADDR'] ?? ''));
}

function is_local_request(): bool
{
    $ip = client_ip();
    return $ip === '127.0.0.1' || $ip === '::1';
}

/** L'adresse du visiteur fait-elle partie du réseau local autorisé ? */
function ip_allowed(string $ip): bool
{
    foreach ((array)Cfg::get('allowed_networks', []) as $net) {
        if (ip_in($ip, (string)$net)) return true;
    }
    if (Cfg::get('allow_same_ipv6_prefix') && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $srv = normalize_ip((string)($_SERVER['SERVER_ADDR'] ?? $_SERVER['LOCAL_ADDR'] ?? ''));
        $a = @inet_pton($ip);
        $b = @inet_pton($srv);
        if ($a !== false && $b !== false && strlen($a) === 16 && strlen($b) === 16 && strncmp($a, $b, 8) === 0) return true;
    }
    return false;
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
}

/* ------------------------------------------------------------------ adresses MAC & divers */

function mac_norm(?string $mac): string
{
    $hex = strtoupper(preg_replace('/[^0-9a-f]/i', '', (string)$mac));
    return strlen($hex) === 12 ? implode(':', str_split($hex, 2)) : '';
}

/** Adresse MAC « privée » (aléatoire) : bit « administré localement » du premier octet. */
function mac_is_random(string $mac): bool
{
    $first = hexdec(substr(mac_norm($mac), 0, 2) ?: '00');
    return ($first & 0x02) === 0x02;
}

function str_limit(string $s, int $max): string
{
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max - 1)) . '…' : $s;
}

function clean_text(mixed $v, int $max = 120): string
{
    $s = html_entity_decode(strip_tags((string)$v), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = preg_replace('/[\x00-\x1F\x7F]/u', '', $s) ?? '';
    return str_limit($s, $max);
}

function json_out(int $code, array $body): never
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

/* ------------------------------------------------------------------ chargement */

foreach (['Db', 'Http', 'Freebox', 'DemoBox', 'Dns', 'Network', 'Detect', 'Schedules', 'Collector', 'News', 'Notify', 'Auth'] as $part) {
    require_once __DIR__ . '/' . $part . '.php';
}

Cfg::load();
ini_set('display_errors', '0');
ini_set('log_errors', '1');
