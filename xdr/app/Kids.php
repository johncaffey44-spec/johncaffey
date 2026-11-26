<?php
/*
 * Sentinelle XDR — suivi des appareils des enfants (contrôle parental, enfants informés).
 *
 * Ce que Sentinelle mesure, et comment :
 *  - « temps d'utilisation » : chaque minute où l'appareil est actif sur le réseau (trafic ou
 *    requêtes DNS) compte pour 1 minute, attribuée au service le plus sollicité des 5 dernières
 *    minutes (YouTube, TikTok, Roblox…). C'est une ESTIMATION : un appareil qui télécharge en
 *    arrière-plan compte aussi, une vidéo déjà en mémoire peut ne rien générer.
 *  - les services viennent des noms de domaine vus par AdGuard Home : pas le contenu, pas les
 *    messages, pas les recherches (chiffrés en HTTPS) ;
 *  - en 4G/5G, hors du Wi-Fi de la maison, rien n'est visible : utilisez en plus Google Family Link
 *    ou Temps d'écran (iPhone), qui mesurent le temps par application sur le téléphone lui-même.
 */
declare(strict_types=1);

final class Kids
{
    /** Indices de contournement du filtrage : DNS chiffré, VPN, relais privé. */
    private const BYPASS = [
        'mask.icloud.com' => 'Relais privé iCloud', 'mask-h2.icloud.com' => 'Relais privé iCloud',
        'dns.google' => 'DNS chiffré de Google', 'cloudflare-dns.com' => 'DNS chiffré Cloudflare', 'one.one.one.one' => 'DNS chiffré Cloudflare',
        'dns.quad9.net' => 'DNS chiffré Quad9', 'doh.opendns.com' => 'DNS chiffré OpenDNS', 'dns.nextdns.io' => 'NextDNS', 'dns.adguard-dns.com' => 'DNS chiffré AdGuard',
        'nordvpn.com' => 'VPN NordVPN', 'protonvpn.com' => 'VPN Proton', 'protonvpn.ch' => 'VPN Proton', 'expressvpn.com' => 'VPN ExpressVPN', 'surfshark.com' => 'VPN Surfshark',
        'windscribe.com' => 'VPN Windscribe', 'tunnelbear.com' => 'VPN TunnelBear', 'hotspotshield.com' => 'VPN Hotspot Shield', 'cyberghostvpn.com' => 'VPN CyberGhost',
        'hide.me' => 'VPN hide.me', 'psiphon.ca' => 'Psiphon', 'urban-vpn.com' => 'VPN Urban', 'vpnunlimited.com' => 'VPN Unlimited', 'warp.plus' => 'Cloudflare WARP',
        'cloudflareclient.com' => 'Cloudflare WARP', 'torproject.org' => 'Tor',
    ];
    /** Domaines techniques qui ne disent rien de ce que fait l'enfant. */
    private const INFRA = ['akamaihd.net', 'akamaized.net', 'akamai.net', 'edgekey.net', 'edgesuite.net', 'cloudfront.net', 'amazonaws.com', 'googleapis.com',
        'gstatic.com', 'doubleclick.net', 'googlesyndication.com', 'app-measurement.com', 'crashlytics.com', 'fastly.net', 'fbcdn.net', 'msftconnecttest.com',
        'apple-dns.net', 'aaplimg.com', 'cdn77.org', 'cloudflare.com', 'branch.io', 'adjust.com', 'appsflyer.com', 'sentry.io', 'in-addr.arpa', 'local'];
    /** Services bloquables (identifiants AdGuard Home) proposés en démonstration. */
    public const DEMO_CATALOG = [
        'tiktok' => 'TikTok', 'instagram' => 'Instagram', 'snapchat' => 'Snapchat', 'youtube' => 'YouTube', 'whatsapp' => 'WhatsApp',
        'discord' => 'Discord', 'roblox' => 'Roblox', 'epic_games' => 'Epic Games (Fortnite)', 'minecraft' => 'Minecraft', 'twitch' => 'Twitch',
        'netflix' => 'Netflix', 'facebook' => 'Facebook', 'twitter' => 'X (Twitter)', 'reddit' => 'Reddit', 'steam' => 'Steam', 'telegram' => 'Telegram',
    ];
    /** Libellé de service (Dns::service) → identifiant AdGuard Home. */
    public const SERVICE_IDS = [
        'TikTok' => 'tiktok', 'Instagram' => 'instagram', 'Snapchat' => 'snapchat', 'YouTube' => 'youtube', 'WhatsApp' => 'whatsapp', 'Discord' => 'discord',
        'Roblox' => 'roblox', 'Fortnite / Epic' => 'epic_games', 'Minecraft' => 'minecraft', 'Twitch' => 'twitch', 'Netflix' => 'netflix',
        'Facebook' => 'facebook', 'X (Twitter)' => 'twitter', 'Steam' => 'steam',
    ];

    /* ------------------------------------------------------------------ appareils des enfants */

    /** @return array<string, list<array{mac:string,name:string,last_ip:string,type:string}>> prénom → appareils */
    public static function owners(): array
    {
        $out = [];
        foreach (Db::all("SELECT mac, name, last_ip, type, owner FROM devices WHERE owner != '' ORDER BY name") as $r) {
            $out[(string)$r['owner']][] = ['mac' => $r['mac'], 'name' => $r['name'], 'last_ip' => $r['last_ip'], 'type' => $r['type']];
        }
        ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return $out;
    }

    public static function setOwner(string $mac, string $owner): void
    {
        $mac = mac_norm($mac);
        if ($mac === '') throw new InvalidArgumentException('Adresse MAC invalide.');
        $owner = clean_text($owner, 30);
        Db::q('UPDATE devices SET owner = ? WHERE mac = ?', [$owner, $mac]);
        Db::event('owner', $mac, $owner !== '' ? "Appareil attribué à $owner" : 'Appareil retiré du suivi des enfants');
    }

    public static function nightHours(): array
    {
        [$sh] = array_map('intval', explode(':', (string)Settings::get('night_start')));
        [$eh] = array_map('intval', explode(':', (string)Settings::get('night_end')));
        $hours = [];
        for ($h = $sh; $h !== $eh; $h = ($h + 1) % 24) {
            $hours[] = $h;
            if (count($hours) > 23) break;
        }
        return $hours;
    }

    /** Nom lisible d'un domaine : service connu, sinon domaine principal (hors domaines techniques). */
    public static function site(string $domain): ?string
    {
        $svc = Dns::service($domain);
        if ($svc !== null) return $svc;
        $parts = explode('.', strtolower(rtrim($domain, '.')));
        if (count($parts) < 2) return null;
        $n = in_array($parts[count($parts) - 2], ['co', 'com', 'gouv', 'org', 'net', 'ac', 'gov'], true) && count($parts) > 2 ? 3 : 2;
        $base = implode('.', array_slice($parts, -$n));
        foreach (self::INFRA as $infra) {
            if ($base === $infra || str_ends_with($base, '.' . $infra)) return null;
        }
        return $base;
    }

    public static function bypassLabel(string $domain): ?string
    {
        $d = strtolower(rtrim($domain, '.'));
        foreach (self::BYPASS as $suffix => $label) {
            if ($d === $suffix || str_ends_with($d, '.' . $suffix)) return $label;
        }
        return null;
    }

    /* ------------------------------------------------------------------ mesure (chaque minute) */

    public static function account(array $devices): void
    {
        $owners = [];
        foreach (Db::all("SELECT mac, owner FROM devices WHERE owner != ''") as $r) $owners[$r['mac']] = $r['owner'];
        if (!$owners) return;
        $now = time();
        $minute = $now - $now % 60;
        if ((int)Db::kv('usage_last', '0') >= $minute) return;
        Db::setKv('usage_last', (string)$minute);
        $log = [];
        if (Dns::enabled()) {
            try {
                $log = cache_get('dns-recent', 8, fn() => Dns::recent(500));
            } catch (Throwable) {
                $log = [];
            }
        }
        $known = [];
        $other = [];
        $lastMinute = [];
        foreach ($log as $e) {
            if ($e['t'] < $now - 300 || $e['blocked']) continue;
            if ($e['t'] >= $now - 60) $lastMinute[$e['ip']] = true;
            if ($e['service'] !== null) {
                $known[$e['ip']][$e['service']] = ($known[$e['ip']][$e['service']] ?? 0) + 1;
            } elseif (($site = self::site($e['domain'])) !== null) {
                $other[$e['ip']][$site] = ($other[$e['ip']][$site] ?? 0) + 1;
            }
        }
        $day = date('Y-m-d', $now);
        $hour = (int)date('G', $now);
        foreach ($devices as $d) {
            if (!isset($owners[$d['mac']]) || !$d['online']) continue;
            $ip = $d['ipv4'];
            $active = ($d['rate']['down'] + $d['rate']['up']) >= 15_000 || isset($lastMinute[$ip]);
            if (!$active) continue;
            $pool = $known[$ip] ?? $other[$ip] ?? [];
            arsort($pool);
            $service = $pool ? (string)array_key_first($pool) : ($log ? 'Autre' : '');
            Db::q('INSERT INTO usage(day, hour, mac, service, minutes) VALUES(?, ?, ?, ?, 1)
                   ON CONFLICT(day, hour, mac, service) DO UPDATE SET minutes = minutes + 1', [$day, $hour, $d['mac'], $service]);
        }
    }

    /* ------------------------------------------------------------------ synthèse */

    public static function summary(string $range): array
    {
        $days = $range === 'week' ? 7 : 1;
        $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        $night = self::nightHours();
        $protect = self::protectionsAll();
        $out = [];
        foreach (self::owners() as $owner => $devs) {
            $macs = array_column($devs, 'mac');
            $in = implode(',', array_fill(0, count($macs), '?'));
            $services = array_map(fn($r) => ['name' => $r['service'] !== '' ? $r['service'] : 'Activité (service inconnu)', 'minutes' => (int)$r['m']],
                Db::all("SELECT service, SUM(minutes) AS m FROM usage WHERE day >= ? AND mac IN ($in) GROUP BY service ORDER BY m DESC LIMIT 12", [$from, ...$macs]));
            $hours = array_fill(0, 24, 0);
            foreach (Db::all("SELECT hour, SUM(minutes) AS m FROM usage WHERE day >= ? AND mac IN ($in) GROUP BY hour", [$from, ...$macs]) as $r) $hours[(int)$r['hour']] = (int)$r['m'];
            $perDay = [];
            for ($i = $days - 1; $i >= 0; $i--) $perDay[date('Y-m-d', strtotime("-$i days"))] = 0;
            foreach (Db::all("SELECT day, SUM(minutes) AS m FROM usage WHERE day >= ? AND mac IN ($in) GROUP BY day", [$from, ...$macs]) as $r) {
                if (isset($perDay[$r['day']])) $perDay[$r['day']] = (int)$r['m'];
            }
            $perDevice = [];
            foreach (Db::all("SELECT mac, SUM(minutes) AS m FROM usage WHERE day >= ? AND mac IN ($in) GROUP BY mac", [$from, ...$macs]) as $r) $perDevice[$r['mac']] = (int)$r['m'];
            $nightTop = Db::all("SELECT service, SUM(minutes) AS m FROM usage WHERE day >= ? AND mac IN ($in) AND hour IN (" . implode(',', array_map('intval', $night ?: [-1])) . ")
                                 GROUP BY service ORDER BY m DESC LIMIT 3", [$from, ...$macs]);
            $out[] = [
                'owner' => $owner,
                'devices' => array_map(fn($d) => ['mac' => $d['mac'], 'name' => $d['name'], 'type' => $d['type'], 'ip' => $d['last_ip'], 'minutes' => $perDevice[$d['mac']] ?? 0], $devs),
                'total' => array_sum($hours),
                'night' => array_sum(array_map(fn($h) => $hours[$h], $night)),
                'night_services' => array_map(fn($r) => ['name' => $r['service'] ?: 'service inconnu', 'minutes' => (int)$r['m']], $nightTop),
                'services' => $services,
                'hours' => $hours,
                'days' => array_map(fn($k, $v) => ['day' => $k, 'minutes' => $v], array_keys($perDay), $perDay),
                'protect' => $protect[$owner] ?? null,
                'alerts' => array_map(fn($a) => ['id' => (int)$a['id'], 'severity' => $a['severity'], 'title' => $a['title'], 'detail' => $a['detail'], 'last' => (int)$a['last_ts'], 'status' => $a['status']],
                    Db::all("SELECT * FROM alerts WHERE kind = 'parent' AND status = 'open' AND fp LIKE ? ORDER BY last_ts DESC LIMIT 6", ['%|' . $owner . '|%'])),
            ];
        }
        return $out;
    }

    /** Dernières requêtes DNS des appareils d'un enfant (fusionnées, plus récentes d'abord). */
    public static function activity(string $owner): array
    {
        $devs = self::owners()[$owner] ?? [];
        $out = [];
        foreach ($devs as $d) {
            if ($d['last_ip'] === '') continue;
            foreach (Dns::recent(150, $d['last_ip']) as $e) {
                if ($e['ip'] !== $d['last_ip']) continue;
                $e['device'] = $d['name'];
                $e['site'] = self::site($e['domain']);
                $e['bypass'] = self::bypassLabel($e['domain']);
                $out[] = $e;
            }
        }
        usort($out, fn($a, $b) => $b['t'] <=> $a['t']);
        return array_slice($out, 0, 250);
    }

    /* ------------------------------------------------------------------ protections (AdGuard Home) */

    public static function clientName(string $owner): string
    {
        return 'Sentinelle · ' . $owner;
    }

    private static function ownerIps(string $owner): array
    {
        $ips = [];
        foreach (self::owners()[$owner] ?? [] as $d) {
            if ($d['last_ip'] !== '' && filter_var($d['last_ip'], FILTER_VALIDATE_IP)) $ips[] = $d['last_ip'];
        }
        return array_values(array_unique($ips));
    }

    /** Protections par enfant, lues dans AdGuard Home (clients « Sentinelle · prénom »). */
    public static function protectionsAll(): array
    {
        if (!Dns::enabled()) return [];
        try {
            $clients = Dns::clients();
        } catch (Throwable) {
            return [];
        }
        $out = [];
        foreach ($clients as $c) {
            $name = (string)($c['name'] ?? '');
            if (!str_starts_with($name, 'Sentinelle · ')) continue;
            $out[substr($name, strlen('Sentinelle · '))] = [
                'parental' => (bool)($c['parental_enabled'] ?? false),
                'safesearch' => (bool)($c['safe_search']['enabled'] ?? false),
                'blocked' => array_values(array_map('strval', (array)($c['blocked_services'] ?? []))),
                'ips' => array_values(array_map('strval', (array)($c['ids'] ?? []))),
            ];
        }
        return $out;
    }

    public static function protect(string $owner, bool $parental, bool $safesearch, array $blocked): array
    {
        if (!Dns::enabled()) throw new RuntimeException('Configurez d\'abord AdGuard Home (Paramètres › Navigation).', 409);
        $owner = clean_text($owner, 30);
        $ips = self::ownerIps($owner);
        if (!$ips) throw new InvalidArgumentException("Aucune adresse IP connue pour les appareils de $owner : allumez-les puis réessayez.");
        $catalog = Dns::serviceCatalog();
        $blocked = array_values(array_filter(array_map('strval', $blocked), fn($id) => isset($catalog[$id])));
        $name = self::clientName($owner);
        $existing = Dns::client($name);
        $keep = ['blocked_services_schedule', 'upstreams', 'tags', 'ignore_querylog', 'ignore_statistics', 'upstreams_cache_enabled', 'upstreams_cache_size'];
        $client = array_intersect_key($existing ?? [], array_flip($keep)) + [
            'name' => $name,
            'ids' => $ips,
            'use_global_settings' => false,
            'filtering_enabled' => true,
            'safebrowsing_enabled' => true,
            'parental_enabled' => $parental,
            'safe_search' => ['enabled' => $safesearch, 'bing' => true, 'duckduckgo' => true, 'ecosia' => true, 'google' => true,
                'pixabay' => true, 'yandex' => true, 'youtube' => $safesearch],
            'use_global_blocked_services' => false,
            'blocked_services' => $blocked,
            'upstreams' => [],
            'tags' => [],
            'ignore_querylog' => false,
            'ignore_statistics' => false,
        ];
        Dns::saveClient($client, $existing !== null);
        Db::event('protect', '', "Protections de $owner : filtre adulte " . ($parental ? 'actif' : 'inactif') . ', recherche sécurisée '
            . ($safesearch ? 'active' : 'inactive') . ($blocked ? ', bloqués : ' . implode(', ', array_map(fn($id) => $catalog[$id], $blocked)) : ''));
        return ['ok' => true, 'ips' => $ips];
    }

    /** Toutes les 10 minutes : si l'adresse IP d'un appareil d'enfant a changé, on met AdGuard Home à jour. */
    public static function syncClients(): void
    {
        if (!Dns::enabled() || time() - (int)Db::kv('kids_sync', '0') < 600) return;
        Db::setKv('kids_sync', (string)time());
        try {
            foreach (self::protectionsAll() as $owner => $p) {
                $ips = self::ownerIps($owner);
                sort($ips);
                $cur = $p['ips'];
                sort($cur);
                if ($ips && $ips !== $cur) self::protect($owner, $p['parental'], $p['safesearch'], $p['blocked']);
            }
        } catch (Throwable $e) {
            Db::event('protect', '', 'Mise à jour AdGuard Home impossible : ' . $e->getMessage());
        }
    }

    /* ------------------------------------------------------------------ rapport de la semaine */

    public static function weeklyReport(): void
    {
        $now = time();
        $n = (array)Settings::get('notify');
        if (!Settings::get('kids_report') || ($n['type'] ?? 'none') === 'none') return;
        if ((int)date('N', $now) !== 7 || (int)date('G', $now) < 19) return;
        $week = date('o-W', $now);
        if (Db::kv('kids_report_week') === $week) return;
        Db::setKv('kids_report_week', $week);
        $lines = [];
        foreach (self::summary('week') as $k) {
            $top = implode(', ', array_map(fn($s) => $s['name'] . ' ' . self::hm($s['minutes']), array_slice($k['services'], 0, 3)));
            $lines[] = "{$k['owner']} : " . self::hm($k['total']) . ' en ligne' . ($top ? " ($top)" : '') . ($k['night'] ? ', dont ' . self::hm($k['night']) . ' la nuit' : '');
        }
        if (!$lines) return;
        try {
            Notify::send('Sentinelle · la semaine des enfants', implode("\n", $lines) . "\nTemps estimés à partir du réseau de la maison (hors 4G/5G).", 'info');
        } catch (Throwable $e) {
            Db::event('notify', '', 'Rapport de la semaine non envoyé : ' . $e->getMessage());
        }
    }

    public static function hm(int $minutes): string
    {
        return $minutes >= 60 ? intdiv($minutes, 60) . ' h ' . str_pad((string)($minutes % 60), 2, '0', STR_PAD_LEFT) : $minutes . ' min';
    }

    /* ------------------------------------------------------------------ démonstration */

    /** Sept jours d'historique fictif pour que l'onglet Enfants soit parlant dès l'ouverture. */
    public static function seedDemo(): void
    {
        if ((int)Db::val('SELECT COUNT(*) FROM usage') > 0) return;
        $plans = [
            'phone'    => ['TikTok' => 5, 'Instagram' => 3, 'Snapchat' => 3, 'YouTube' => 2, 'WhatsApp' => 1],
            'tablet'   => ['YouTube' => 4, 'Netflix' => 2, 'Roblox' => 1],
            'handheld' => ['Nintendo' => 4, 'Roblox' => 2, 'YouTube' => 1],
            'console'  => ['Fortnite / Epic' => 4, 'PlayStation Network' => 2, 'Discord' => 1],
        ];
        $now = time();
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        foreach (DemoBox::DEVICES as $d) {
            if (empty($d['owner']) || !isset($plans[$d['kind']])) continue;
            $plan = $plans[$d['kind']];
            $weights = array_sum($plan);
            for ($i = 6; $i >= 0; $i--) {
                $ts = strtotime("-$i days", $now);
                $day = date('Y-m-d', $ts);
                $weekend = (int)date('N', $ts) >= 6;
                for ($h = 0; $h < 24; $h++) {
                    if ($i === 0 && $h > (int)date('G', $now)) break;
                    $base = match (true) {
                        $h >= 7 && $h < 8 => $weekend ? 0 : 8,
                        $h >= 10 && $h < 12 => $weekend ? 35 : 0,
                        $h >= 12 && $h < 14 => 14,
                        $h >= 14 && $h < 16 => $weekend ? 40 : 0,
                        $h >= 16 && $h < 19 => 32,
                        $h >= 19 && $h < 21 => 22,
                        $h >= 21 && $h < 23 => 28,
                        $h === 23 => $d['kind'] === 'phone' && $i % 2 === 0 ? 22 : 0,
                        $h === 0 => $d['kind'] === 'phone' && $i % 3 === 0 ? 12 : 0,
                        default => 0,
                    };
                    $noise = DemoBox::noise($d['mac'] . 'u' . $day, $h);
                    $min = (int)round($base * (0.25 + 0.55 * $noise));
                    if ($d['kind'] === 'console' && !$weekend && $h < 17) $min = 0;
                    if ($min <= 0) continue;
                    $left = min(60, $min);
                    foreach ($plan as $svc => $w) {
                        $part = (int)round($left * $w / $weights);
                        if ($part > 0) {
                            Db::q('INSERT INTO usage(day, hour, mac, service, minutes) VALUES(?, ?, ?, ?, ?) ON CONFLICT(day, hour, mac, service) DO UPDATE SET minutes = minutes + excluded.minutes',
                                [$day, $h, $d['mac'], $svc, $part]);
                        }
                    }
                }
            }
        }
        $pdo->commit();
    }
}
