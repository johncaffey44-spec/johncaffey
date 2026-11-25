<?php
/*
 * Sentinelle XDR — TV : guide des chaînes de la Freebox, télécommande du Player, applications.
 *
 * Commandes documentées par Free (aide de Freebox OS, reprise par la communauté) :
 *   POST player/{id}/api/v6/control/open      { "url": "app:fr.freebox.tv?channel=6" } (allume le Player si besoin)
 *   POST player/{id}/api/v6/control/mediactrl { "name": "next" | "previous" | "play_pause" … }
 *   PUT  player/{id}/api/v6/control/volume    { "volume": 0-100 } ou { "mute": true }
 * Guide : tv/channels/, tv/bouquets/freeboxtv/channels/, tv/epg/by_time/{horodatage}.
 *
 * Sentinelle ne diffuse pas les chaînes elle-même (droits et protections anticopie) : pour
 * regarder sur un téléphone, une tablette ou un ordinateur, l'outil officiel est Free TV
 * (anciennement Oqee by Free), en application ou sur tv.free.fr.
 */
declare(strict_types=1);

final class Tv
{
    /** Raccourcis documentés (ouvrent l'application sur le Player et l'allument si besoin). */
    public const SHORTCUTS = [
        'tv'      => ['name' => 'TV en direct', 'url' => 'app:fr.freebox.tv', 'icon' => 'tv'],
        'replay'  => ['name' => 'Replay', 'url' => 'vodservice://replay', 'icon' => 'play'],
        'netflix' => ['name' => 'Netflix', 'url' => 'https://www.netflix.com', 'icon' => 'play'],
        'youtube' => ['name' => 'YouTube', 'url' => 'https://www.youtube.com', 'icon' => 'play'],
        'prime'   => ['name' => 'Prime Video', 'url' => 'https://www.primevideo.com', 'icon' => 'play'],
        'pvr'     => ['name' => 'Enregistrements', 'url' => 'pvr://', 'icon' => 'calendar'],
        'radio'   => ['name' => 'Radio', 'url' => 'app:fr.freebox.radio', 'icon' => 'volume'],
    ];
    /** Applications dont l'ouverture passe mieux par leur adresse web que par leur paquet. */
    private const PACKAGE_URL = [
        'com.netflix.ninja' => 'https://www.netflix.com', 'com.netflix' => 'https://www.netflix.com',
        'com.google.android.youtube.tv' => 'https://www.youtube.com', 'com.amazon.amazonvideo.livingroom' => 'https://www.primevideo.com',
        'fr.freebox.tv' => 'app:fr.freebox.tv',
    ];

    /* ------------------------------------------------------------------ guide */

    public static function channels(Freebox $fbx): array
    {
        $key = ($fbx->isDemo() ? 'demo' : 'fbx') . '-tv-channels';
        $list = cache_get($key, 3600, function () use ($fbx): array {
            $all = (array)$fbx->get('tv/channels/');
            $bouquet = (array)$fbx->tryGet('tv/bouquets/freeboxtv/channels/', []);
            $out = [];
            foreach ($bouquet as $b) {
                $uuid = (string)($b['uuid'] ?? '');
                $c = $all[$uuid] ?? null;
                if ($c === null || isset($out[$uuid])) continue;
                if (array_key_exists('available', $b) && !$b['available']) continue;
                $out[$uuid] = ['uuid' => $uuid, 'number' => (int)($b['number'] ?? 0), 'name' => clean_text($c['name'] ?? $uuid, 50),
                    'short' => clean_text($c['short_name'] ?? $c['name'] ?? '', 20), 'abo' => (bool)($c['has_abo'] ?? true)];
            }
            if (!$out) {
                // pas de bouquet lisible : liste brute des chaînes disponibles
                foreach ($all as $uuid => $c) {
                    if (empty($c['available'])) continue;
                    $out[$uuid] = ['uuid' => (string)$uuid, 'number' => 0, 'name' => clean_text($c['name'] ?? $uuid, 50),
                        'short' => clean_text($c['short_name'] ?? '', 20), 'abo' => (bool)($c['has_abo'] ?? true)];
                }
            }
            usort($out, fn($a, $b) => [$a['number'] ?: 9999, $a['name']] <=> [$b['number'] ?: 9999, $b['name']]);
            return array_values($out);
        });
        return $list;
    }

    /** Programme en cours par chaîne (si le guide est accessible). */
    public static function now(Freebox $fbx): array
    {
        $key = ($fbx->isDemo() ? 'demo' : 'fbx') . '-tv-now';
        return cache_get($key, 300, function () use ($fbx): array {
            $t = time();
            $epg = (array)$fbx->tryGet('tv/epg/by_time/' . $t, []);
            $out = [];
            foreach ($epg as $uuid => $progs) {
                foreach ((array)$progs as $p) {
                    if (!is_array($p)) continue;
                    $start = (int)($p['date'] ?? 0);
                    $end = $start + (int)($p['duration'] ?? 0);
                    if ($start <= $t && $t < $end) {
                        $out[(string)$uuid] = ['title' => clean_text($p['title'] ?? '', 90), 'sub' => clean_text($p['sub_title'] ?? '', 90),
                            'category' => clean_text($p['category_name'] ?? '', 30), 'start' => $start, 'end' => $end];
                        break;
                    }
                }
            }
            return $out;
        });
    }

    /** Logo d'une chaîne (PNG de la box), mis en cache sur le disque. */
    public static function logo(Freebox $fbx, string $uuid): ?array
    {
        if (!preg_match('/^uuid-webtv-\d{1,6}$/', $uuid)) return null;
        $dir = data_path('cache/logos');
        if (!is_dir($dir)) @mkdir($dir, 0770, true);
        $file = "$dir/$uuid.png";
        if (is_file($file) && filemtime($file) > time() - 7 * 86400) return ['type' => 'image/png', 'body' => (string)file_get_contents($file)];
        try {
            $r = $fbx->binary("tv/img/channels/logos68x60/$uuid.png");
        } catch (Throwable) {
            return null;
        }
        $body = $r['body'];
        if ($r['status'] !== 200 || !str_starts_with($body, "\x89PNG")) return null;
        @file_put_contents($file, $body);
        return ['type' => 'image/png', 'body' => $body];
    }

    /* ------------------------------------------------------------------ applications */

    /** Retient les applications vues au premier plan du Player : ce sont « vos » applications. */
    public static function learn(string $package, string $name): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/i', $package)) return;
        $all = json_decode((string)Db::kv('tv_apps', '{}'), true) ?: [];
        $all[$package] = ['name' => clean_text($name, 40), 'last' => time(), 'count' => (int)($all[$package]['count'] ?? 0) + 1];
        Db::setKv('tv_apps', json_encode($all, JSON_UNESCAPED_UNICODE));
    }

    public static function apps(): array
    {
        $learned = json_decode((string)Db::kv('tv_apps', '{}'), true) ?: [];
        if (Network::isDemo()) { // démonstration : quelques applications « déjà vues » s'ajoutent à celles apprises
            foreach (['fr.freebox.tv' => 'TV Freebox', 'com.netflix.ninja' => 'Netflix', 'com.google.android.youtube.tv' => 'YouTube',
                         'com.disney.disneyplus' => 'Disney+', 'com.amazon.amazonvideo.livingroom' => 'Prime Video',
                         'com.canal.android.canal' => 'myCANAL', 'com.spotify.tv.android' => 'Spotify'] as $p => $n) {
                $learned[$p] ??= ['name' => $n, 'last' => time() - 3600, 'count' => 3];
            }
        }
        uasort($learned, fn($a, $b) => $b['count'] <=> $a['count']);
        $mine = [];
        foreach ($learned as $pkg => $a) {
            $mine[] = ['id' => 'pkg:' . $pkg, 'name' => $a['name'], 'package' => $pkg, 'last' => (int)$a['last'], 'count' => (int)$a['count']];
        }
        $short = [];
        foreach (self::SHORTCUTS as $id => $s) $short[] = ['id' => $id, 'name' => $s['name'], 'icon' => $s['icon']];
        return ['shortcuts' => $short, 'mine' => array_slice($mine, 0, 16)];
    }

    private static function urlFor(string $id): string
    {
        if (isset(self::SHORTCUTS[$id])) return self::SHORTCUTS[$id]['url'];
        if (str_starts_with($id, 'pkg:')) {
            $pkg = substr($id, 4);
            if (!preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/i', $pkg)) throw new InvalidArgumentException('Application inconnue.');
            return self::PACKAGE_URL[$pkg] ?? 'app:' . $pkg;
        }
        throw new InvalidArgumentException('Application inconnue.');
    }

    /* ------------------------------------------------------------------ télécommande */

    public static function command(Freebox $fbx, int $player, string $cmd, string $value = ''): array
    {
        if ($player <= 0) throw new InvalidArgumentException('Player inconnu.');
        $base = "player/$player/api/v6/control/";
        $open = static function (array $urls) use ($fbx, $base): void {
            $last = null;
            foreach ($urls as $u) {
                try {
                    $fbx->call('POST', $base . 'open', ['url' => $u]);
                    return;
                } catch (FreeboxError $e) {
                    $last = $e;
                    if ($e->codeName === 'insufficient_rights') throw $e;
                }
            }
            throw $last ?? new FreeboxError('Commande refusée par le Player.', 'player');
        };
        switch ($cmd) {
            case 'power':
                $open(['app:fr.freebox.tv', 'tv://']);
                break;
            case 'channel':
                $n = (int)$value;
                if ($n < 1 || $n > 9999) throw new InvalidArgumentException('Numéro de chaîne invalide.');
                $open(["app:fr.freebox.tv?channel=$n", "tv://?channel=$n"]);
                break;
            case 'uuid':
                if (!preg_match('/^uuid-webtv-\d{1,6}$/', $value)) throw new InvalidArgumentException('Chaîne inconnue.');
                $open(["app:fr.freebox.tv?uuid=$value", "tv://?uuid=$value"]);
                break;
            case 'next':
            case 'prev':
                try {
                    $fbx->call('POST', $base . 'mediactrl', ['name' => $cmd === 'next' ? 'next' : 'previous']);
                } catch (FreeboxError $e) {
                    if ($cmd !== 'prev' || $e->codeName === 'insufficient_rights') throw $e;
                    $fbx->call('POST', $base . 'mediactrl', ['name' => 'prev']);
                }
                break;
            case 'play_pause':
                $fbx->call('POST', $base . 'mediactrl', ['name' => 'play_pause']);
                break;
            case 'vol_up':
            case 'vol_down':
            case 'mute':
                $v = (array)$fbx->get($base . 'volume');
                $payload = $cmd === 'mute'
                    ? ['mute' => !(bool)($v['mute'] ?? false)]
                    : ['volume' => max(0, min(100, (int)($v['volume'] ?? 20) + ($cmd === 'vol_up' ? 5 : -5)))];
                $fbx->call('PUT', $base . 'volume', $payload);
                break;
            case 'app':
                $open([self::urlFor($value)]);
                break;
            case 'web':
                if (!preg_match('~^https://[^\s"<>]{3,300}$~i', $value)) throw new InvalidArgumentException('Adresse web invalide (https:// uniquement).');
                $open([$value]);
                break;
            default:
                throw new InvalidArgumentException('Commande inconnue.');
        }
        cache_drop($fbx->isDemo() ? 'demo-players' : 'fbx-players');
        return ['ok' => true];
    }
}
