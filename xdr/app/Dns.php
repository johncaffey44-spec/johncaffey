<?php
/*
 * Sentinelle XDR — visibilité DNS : « sur quoi naviguent les appareils ».
 *
 * La Freebox ne donne pas l'historique des sites visités. Le seul moyen réaliste (et légal pour
 * un foyer informé) est de voir les NOMS DE DOMAINE demandés par chaque appareil à un serveur DNS
 * local : AdGuard Home (gratuit, fonctionne sous Windows). Le contenu des pages, chiffré en HTTPS,
 * reste invisible, et un appareil qui utilise son propre DNS chiffré (DoH, VPN, relais privé
 * iCloud) échappe à cette vue.
 */
declare(strict_types=1);

final class Dns
{
    /** Domaines → service lisible (« ce qu'ils font en ce moment »). */
    private const SERVICES = [
        'youtube.com' => 'YouTube', 'googlevideo.com' => 'YouTube', 'ytimg.com' => 'YouTube',
        'netflix.com' => 'Netflix', 'nflxvideo.net' => 'Netflix', 'nflxso.net' => 'Netflix',
        'tiktok.com' => 'TikTok', 'tiktokv.com' => 'TikTok', 'tiktokcdn.com' => 'TikTok', 'byteoversea.com' => 'TikTok',
        'instagram.com' => 'Instagram', 'cdninstagram.com' => 'Instagram',
        'facebook.com' => 'Facebook', 'fbcdn.net' => 'Facebook', 'whatsapp.net' => 'WhatsApp', 'whatsapp.com' => 'WhatsApp',
        'snapchat.com' => 'Snapchat', 'sc-cdn.net' => 'Snapchat', 'discord.com' => 'Discord', 'discord.gg' => 'Discord', 'discordapp.com' => 'Discord',
        'twitch.tv' => 'Twitch', 'ttvnw.net' => 'Twitch', 'spotify.com' => 'Spotify', 'scdn.co' => 'Spotify',
        'disneyplus.com' => 'Disney+', 'bamgrid.com' => 'Disney+', 'primevideo.com' => 'Prime Video', 'aiv-cdn.net' => 'Prime Video',
        'roblox.com' => 'Roblox', 'rbxcdn.com' => 'Roblox', 'epicgames.com' => 'Fortnite / Epic', 'fortnite.com' => 'Fortnite / Epic',
        'playstation.net' => 'PlayStation Network', 'playstation.com' => 'PlayStation Network', 'xboxlive.com' => 'Xbox Live',
        'nintendo.net' => 'Nintendo', 'nintendo.com' => 'Nintendo', 'steampowered.com' => 'Steam', 'steamcontent.com' => 'Steam',
        'minecraft.net' => 'Minecraft', 'mojang.com' => 'Minecraft', 'icloud.com' => 'iCloud', 'apple.com' => 'Apple',
        'windowsupdate.com' => 'Windows Update', 'update.microsoft.com' => 'Windows Update', 'office.com' => 'Microsoft 365',
        'google.com' => 'Google', 'gstatic.com' => 'Google', 'twimg.com' => 'X (Twitter)', 'x.com' => 'X (Twitter)',
        'free.fr' => 'Free', 'freebox.fr' => 'Free', 'oqee.net' => 'Oqee (TV Free)', 'canalplus.com' => 'Canal+',
        'ezvizlife.com' => 'Cloud EZVIZ (caméra)', 'tplinkcloud.com' => 'Cloud TP-Link', 'amazonalexa.com' => 'Alexa',
        'alexa.amazon.com' => 'Alexa', 'synology.com' => 'Synology', 'quad9.net' => 'DNS Quad9',
    ];

    public static function enabled(): bool
    {
        if (!Settings::get('dns_visibility')) return false;
        if (Network::isDemo()) return true;
        $a = Settings::get('adguard');
        return !empty($a['enabled']) && !empty($a['url']);
    }

    public static function service(string $domain): ?string
    {
        $domain = strtolower(rtrim($domain, '.'));
        foreach (self::SERVICES as $suffix => $label) {
            if ($domain === $suffix || str_ends_with($domain, '.' . $suffix)) return $label;
        }
        return null;
    }

    /** « 127.0.0.1:3000 » saisi sans http:// devient « http://127.0.0.1:3000 » (au lieu d'être refusé sans un mot). */
    public static function normalizeUrl(string $u): string
    {
        $u = trim($u);
        if ($u !== '' && !preg_match('~^[a-z][a-z0-9+.-]*://~i', $u) && preg_match('~^[a-z0-9.\-\[\]:]+(/\S*)?$~i', $u)) $u = 'http://' . $u;
        return rtrim($u, '/');
    }

    /** Appel à l'API d'AdGuard Home (authentification de base). $cfg : réglages à essayer (sinon ceux enregistrés). */
    private static function call(string $method, string $path, ?array $body = null, ?array $cfg = null): mixed
    {
        $a = $cfg ?? Settings::get('adguard');
        $url = rtrim((string)$a['url'], '/') . $path;
        $r = Http::request($method, $url, $body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES),
            $body === null ? ['Accept: application/json'] : ['Accept: application/json', 'Content-Type: application/json'],
            ['timeout' => 5, 'no_proxy' => true, 'userpwd' => ($a['user'] ?? '') !== '' ? $a['user'] . ':' . ($a['pass'] ?? '') : '']);
        if ($r['error'] !== '') throw new RuntimeException('AdGuard Home injoignable (' . $r['error'] . ') : le service « AdGuard Home » est-il démarré, et le port est-il le bon (3000) ?');
        if ($r['status'] === 401 || $r['status'] === 403) throw new RuntimeException('AdGuard Home refuse l\'identifiant ou le mot de passe : ce sont ceux choisis dans son assistant d\'installation.');
        if ($r['status'] === 404) throw new RuntimeException('Rien qui ressemble à AdGuard Home à cette adresse (HTTP 404) : vérifiez le port, par exemple http://127.0.0.1:3000.');
        if ($r['status'] >= 400) throw new RuntimeException("AdGuard Home a répondu HTTP {$r['status']}.");
        $j = json_decode($r['body'], true);
        return $j ?? $r['body'];
    }

    /**
     * Essaie les réglages SAISIS dans le formulaire (avant même « Enregistrer ») ; sans adresse saisie, ceux
     * enregistrés. Un mot de passe vide ou masqué reprend celui enregistré. Jamais de faux « OK » de démonstration
     * pour une vraie adresse.
     */
    public static function test(array $form = []): array
    {
        $saved = Settings::get('adguard');
        $url = self::normalizeUrl((string)($form['url'] ?? ''));
        $typed = $url !== '';
        if (!$typed) {
            if (Network::isDemo() && (string)$saved['url'] === '') return ['ok' => true, 'version' => 'démo', 'protection' => true];
            $url = (string)$saved['url'];
        }
        if (!preg_match('~^https?://[^\s"<>]+$~i', $url)) throw new InvalidArgumentException('Adresse d\'AdGuard Home invalide : saisissez par exemple http://127.0.0.1:3000.');
        $pass = (string)($form['pass'] ?? '');
        if ($pass === '' || $pass === '••••••••') $pass = (string)($saved['pass'] ?? '');
        $user = $typed ? (string)($form['user'] ?? '') : (string)($saved['user'] ?? '');
        $s = self::call('GET', '/control/status', null, ['url' => $url, 'user' => $user, 'pass' => $pass]);
        if (!is_array($s) || !isset($s['version'])) throw new RuntimeException('Cette adresse répond, mais pas comme AdGuard Home : vérifiez le port (3000 ?).');
        return ['ok' => true, 'version' => (string)$s['version'], 'protection' => (bool)($s['protection_enabled'] ?? false), 'url' => $url];
    }

    /** Dernières requêtes DNS, normalisées. */
    public static function recent(int $limit = 500, string $search = ''): array
    {
        if (!self::enabled()) return [];
        if (Network::isDemo()) return self::demoLog($limit, $search);
        $q = http_build_query(array_filter(['limit' => $limit, 'search' => $search], fn($v) => $v !== ''));
        $res = self::call('GET', '/control/querylog?' . $q);
        $out = [];
        foreach ((array)($res['data'] ?? []) as $e) {
            $domain = (string)($e['question']['name'] ?? $e['question']['host'] ?? '');
            if ($domain === '') continue;
            $reason = (string)($e['reason'] ?? '');
            $out[] = [
                't'       => strtotime((string)($e['time'] ?? '')) ?: time(),
                'ip'      => normalize_ip((string)($e['client'] ?? '')),
                'domain'  => mb_substr($domain, 0, 253),
                'type'    => (string)($e['question']['type'] ?? ''),
                'blocked' => str_starts_with($reason, 'Filtered'),
                'reason'  => $reason,
                'status'  => (string)($e['status'] ?? ''),
                'service' => self::service($domain),
            ];
        }
        return $out;
    }

    /** Activité récente par adresse IP : services et domaines des dernières minutes. */
    public static function activityByIp(int $window = 180): array
    {
        if (!self::enabled()) return [];
        try {
            $log = cache_get('dns-recent', 8, fn() => self::recent(500));
        } catch (Throwable) {
            return [];
        }
        $since = time() - $window;
        $by = [];
        foreach ($log as $e) {
            if ($e['t'] < $since || $e['ip'] === '') continue;
            $a = &$by[$e['ip']];
            $a ??= ['services' => [], 'domains' => [], 'blocked' => 0, 'last' => 0];
            if ($e['service']) $a['services'][$e['service']] = ($a['services'][$e['service']] ?? 0) + 1;
            $a['domains'][$e['domain']] = ($a['domains'][$e['domain']] ?? 0) + 1;
            if ($e['blocked']) $a['blocked']++;
            $a['last'] = max($a['last'], $e['t']);
            unset($a);
        }
        foreach ($by as &$a) {
            arsort($a['services']);
            arsort($a['domains']);
            $a['services'] = array_slice(array_keys($a['services']), 0, 3);
            $a['domains'] = array_slice(array_keys($a['domains']), 0, 5);
        }
        unset($a);
        return $by;
    }

    /** Bloque ou rétablit la résolution DNS d'un client (liste « clients refusés » d'AdGuard Home). */
    public static function setClientDenied(string $ip, bool $deny): string
    {
        if ($ip === '') return 'adresse IP inconnue';
        if (Network::isDemo()) {
            $box = new DemoBox();
            $list = (array)$box->state('dns_denied');
            $list = $deny ? array_values(array_unique([...$list, $ip])) : array_values(array_diff($list, [$ip]));
            $box->setState('dns_denied', $list);
            return $deny ? 'résolution DNS refusée' : 'résolution DNS rétablie';
        }
        $acc = (array)self::call('GET', '/control/access/list');
        $dis = array_values(array_filter((array)($acc['disallowed_clients'] ?? []), 'is_string'));
        $dis = $deny ? array_values(array_unique([...$dis, $ip])) : array_values(array_diff($dis, [$ip]));
        self::call('POST', '/control/access/set', [
            'allowed_clients'    => array_values((array)($acc['allowed_clients'] ?? [])),
            'disallowed_clients' => $dis,
            'blocked_hosts'      => array_values((array)($acc['blocked_hosts'] ?? [])),
        ]);
        return $deny ? 'résolution DNS refusée par AdGuard Home' : 'résolution DNS rétablie';
    }

    /* ------------------------------------------------------------------ clients AdGuard Home (protections par enfant) */

    public static function clients(): array
    {
        if (Network::isDemo()) return array_values((array)(new DemoBox())->state('dns_clients'));
        $r = self::call('GET', '/control/clients');
        return array_values(array_filter((array)($r['clients'] ?? []), 'is_array'));
    }

    public static function client(string $name): ?array
    {
        foreach (self::clients() as $c) {
            if (($c['name'] ?? '') === $name) return $c;
        }
        return null;
    }

    public static function saveClient(array $c, bool $exists): void
    {
        if (Network::isDemo()) {
            $box = new DemoBox();
            $all = (array)$box->state('dns_clients');
            $all[$c['name']] = $c;
            $box->setState('dns_clients', $all);
            return;
        }
        $exists ? self::call('POST', '/control/clients/update', ['name' => $c['name'], 'data' => $c]) : self::call('POST', '/control/clients/add', $c);
    }

    /** Services bloquables proposés par AdGuard Home : identifiant → nom. */
    public static function serviceCatalog(): array
    {
        if (Network::isDemo()) return Kids::DEMO_CATALOG;
        return cache_get('dns-services', 86400, function (): array {
            $r = self::call('GET', '/control/blocked_services/all');
            $out = [];
            foreach ((array)($r['blocked_services'] ?? []) as $s) {
                if (isset($s['id'], $s['name'])) $out[(string)$s['id']] = clean_text($s['name'], 40);
            }
            asort($out, SORT_NATURAL | SORT_FLAG_CASE);
            return $out;
        });
    }

    /* ------------------------------------------------------------------ démonstration */

    private const DEMO_PROFILES = [
        'phone'    => ['i.instagram.com', 'scontent-cdg4-1.cdninstagram.com', 'v16-webapp.tiktok.com', 'api16-normal-c-useast1a.tiktokv.com', 'mmg.whatsapp.net', 'www.snapchat.com', 'gateway.icloud.com'],
        'laptop'   => ['www.youtube.com', 'rr3---sn-25ge7nsd.googlevideo.com', 'outlook.office.com', 'www.google.com', 'fonts.gstatic.com', 'github.com'],
        'tablet'   => ['www.youtube.com', 'i.ytimg.com'],
        'pc'       => ['steamcdn-a.akamaihd.net', 'store.steampowered.com', 'cdn.discordapp.com', 'gateway.discord.gg', 'settings-win.data.microsoft.com'],
        'player'   => ['api.oqee.net', 'live-cdn.oqee.net', 'www.free.fr'],
        'tv'       => ['occ-0-1234-56.1.nflxvideo.net', 'api-global.netflix.com', 'samsungcloud.tv'],
        'console'  => ['np.playstation.net', 'gs2.ww.prod.dl.playstation.net', 'fortnite-public-service-prod11.ol.epicgames.com'],
        'handheld' => ['ctest.cdn.nintendo.net', 'api.accounts.nintendo.com', 'www.roblox.com'],
        'camera'   => ['devicegw.ezvizlife.com', 'iot.ezvizlife.com'],
        'printer'  => ['xmpp006.hpeprint.com'],
        'speaker'  => ['avs-alexa-eu.amazon.com', 'api.amazonalexa.com', 'audio-ak.spotify.com.edgesuite.net'],
        'iot'      => ['n-device-api.tplinkcloud.com'],
        'nas'      => ['global.quickconnect.to', 'update.synology.com'],
        'server'   => ['www.universfreebox.com', 'www.freenews.fr', 'mafreebox.freebox.fr'],
        'guest'    => ['www.google.com', 'i.instagram.com', 'mmg.whatsapp.net'],
        'rogue'    => ['pool.supportxmr.com', 'cnc.mirai-sample.net'],
    ];

    private static function demoLog(int $limit, string $search): array
    {
        $box = new DemoBox();
        $denied = (array)$box->state('dns_denied');
        $blockedFor = [];
        foreach ((array)$box->state('dns_clients') as $c) {
            foreach ((array)($c['ids'] ?? []) as $ip) $blockedFor[$ip] = (array)($c['blocked_services'] ?? []);
        }
        $now = time();
        $out = [];
        for ($t = $now - $now % 10; $t > $now - 900 && count($out) < $limit * 3; $t -= 10) {
            foreach (DemoBox::DEVICES as $d) {
                $ip = '192.168.1.' . $d['ip'];
                if (!$box->online($d) || in_array($ip, $denied, true)) continue;
                [$down, $up] = $box->devRates($d, $t);
                if ($down + $up < 20_000 && DemoBox::noise($d['mac'] . 'q', intdiv($t, 10)) > 0.08) continue;
                $list = self::DEMO_PROFILES[$d['kind']] ?? [];
                if (!$list) continue;
                $pick = $list[crc32($d['mac'] . $t) % count($list)];
                $blocked = false;
                $reason = 'NotFilteredNotFound';
                $status = 'NOERROR';
                if ($d['kind'] === 'rogue') {
                    // module compromis : rafales de domaines générés (DGA) + domaine malveillant bloqué
                    $pick = DemoBox::noise($d['mac'] . 'x', intdiv($t, 10)) < 0.65
                        ? substr(md5($d['mac'] . $t), 0, 12) . '.' . ['top', 'xyz', 'ru', 'biz'][crc32((string)$t) % 4]
                        : $pick;
                    if (!str_contains($pick, '.supportxmr.') && !str_contains($pick, 'mirai')) {
                        $status = 'NXDOMAIN';
                    } else {
                        $blocked = true;
                        $reason = str_contains($pick, 'mirai') ? 'FilteredSafeBrowsing' : 'FilteredBlackList';
                    }
                } elseif ($d['kind'] === 'phone' && crc32($pick . $t) % 17 === 0) {
                    $pick = 'ads.doubleclick.net';
                    $blocked = true;
                    $reason = 'FilteredBlackList';
                } elseif (($d['owner'] ?? '') !== '' && $d['kind'] === 'phone' && crc32($d['mac'] . $t) % 23 === 0) {
                    $pick = 'mask.icloud.com'; // relais privé iCloud : contourne le filtrage
                }
                $svcId = Kids::SERVICE_IDS[self::service($pick) ?? ''] ?? null;
                if ($svcId !== null && in_array($svcId, $blockedFor[$ip] ?? [], true)) {
                    $blocked = true;
                    $reason = 'FilteredBlockedService';
                }
                if ($search !== '' && $search !== $ip && !str_contains($pick, $search)) continue;
                $out[] = ['t' => $t - (crc32($d['mac'] . $t) % 9), 'ip' => $ip, 'domain' => $pick, 'type' => 'A', 'blocked' => $blocked,
                    'reason' => $reason, 'status' => $status, 'service' => self::service($pick)];
            }
        }
        usort($out, fn($a, $b) => $b['t'] <=> $a['t']);
        return array_slice($out, 0, $limit);
    }
}
