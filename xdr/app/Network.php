<?php
/*
 * Sentinelle XDR — vue « réseau » : appareils normalisés, débits en direct, profils d'accès,
 * exposition sur Internet, et actions de réponse (couper, isoler, réveiller, renommer…).
 */
declare(strict_types=1);

final class Network
{
    public const TYPES = [
        'smartphone' => 'Smartphone', 'tablet' => 'Tablette', 'laptop' => 'Ordinateur portable', 'workstation' => 'Ordinateur',
        'printer' => 'Imprimante', 'vg_console' => 'Console de jeux', 'television' => 'Téléviseur', 'nas' => 'Stockage réseau (NAS)',
        'ip_camera' => 'Caméra', 'ip_phone' => 'Téléphone IP', 'freebox_player' => 'Player Freebox', 'freebox_hd' => 'Freebox HD',
        'freebox_crystal' => 'Freebox Crystal', 'freebox_mini' => 'Freebox Mini 4K', 'freebox_delta' => 'Freebox Delta',
        'freebox_one' => 'Freebox One', 'freebox_wifi' => 'Répéteur Wi-Fi Freebox', 'freebox_pop' => 'Freebox Pop',
        'networking_device' => 'Équipement réseau', 'multimedia_device' => 'Appareil multimédia', 'car' => 'Voiture',
        'smart_home' => 'Objet connecté', 'speaker' => 'Enceinte connectée', 'server' => 'Serveur', 'other' => 'Autre',
    ];

    /** Applications Android TV fréquentes sur le Player (paquet → nom lisible). */
    private const APPS = [
        'fr.freebox.tv' => 'TV Freebox', 'fr.freebox.player' => 'Freebox Player', 'net.oqee.androidtv' => 'Oqee',
        'com.netflix.ninja' => 'Netflix', 'com.google.android.youtube.tv' => 'YouTube', 'com.disney.disneyplus' => 'Disney+',
        'com.amazon.amazonvideo.livingroom' => 'Prime Video', 'com.canal.android.canal' => 'myCANAL', 'com.spotify.tv.android' => 'Spotify',
        'fr.francetv.pluzz' => 'france.tv', 'com.arte.android.tv' => 'Arte', 'com.plexapp.android' => 'Plex', 'org.xbmc.kodi' => 'Kodi',
        'com.google.android.tvlauncher' => 'Accueil', 'com.google.android.apps.tv.launcherx' => 'Accueil', 'com.molotov.tv' => 'Molotov',
        'tv.twitch.android.app' => 'Twitch', 'com.apple.atve.androidtv.appletv' => 'Apple TV+',
    ];

    private static ?bool $demo = null;
    private ?array $hostsCache = null;
    private ?array $ncCache = null;

    public function __construct(public Freebox $fbx)
    {
    }

    public static function make(): self
    {
        return new self(Freebox::make());
    }

    /** Démonstration tant qu'aucune Freebox n'est associée (mode « auto »), ou si forcée. */
    public static function isDemo(): bool
    {
        if (self::$demo !== null) return self::$demo;
        return self::$demo = match (Settings::get('mode')) {
            'demo'    => true,
            'freebox' => false,
            default   => empty(read_json(data_path('freebox.json'))['app_token']),
        };
    }

    public static function resetMode(): void
    {
        self::$demo = null;
    }

    private function key(string $k): string
    {
        return ($this->fbx->isDemo() ? 'demo-' : 'fbx-') . $k;
    }

    private function dropCaches(): void
    {
        $this->hostsCache = null;
        $this->ncCache = null;
        cache_drop($this->key('live'), $this->key('hosts'), 'dns-recent');
    }

    /* ------------------------------------------------------------------ appareils */

    public function hosts(): array
    {
        if ($this->hostsCache !== null) return $this->hostsCache;
        return $this->hostsCache = cache_get($this->key('hosts'), 2, function (): array {
            $ifaces = (array)$this->fbx->tryGet('lan/browser/interfaces/', [['name' => 'pub']]);
            $all = [];
            foreach ($ifaces as $if) {
                $name = (string)($if['name'] ?? '');
                if (!preg_match('/^[a-z0-9_-]{1,20}$/i', $name)) continue;
                if (isset($if['host_count']) && (int)$if['host_count'] === 0) continue;
                $list = $name === 'pub' ? $this->fbx->get("lan/browser/$name/") : $this->fbx->tryGet("lan/browser/$name/", []);
                foreach ((array)$list as $h) {
                    if (!is_array($h)) continue;
                    $h['interface'] = (string)($h['interface'] ?? $name);
                    $all[] = $h;
                }
            }
            return $all;
        });
    }

    /** Débits en direct par adresse MAC : stations Wi-Fi + ports Ethernet de la box. */
    public function liveRates(): array
    {
        return cache_get($this->key('live'), 3, function (): array {
            $out = [];
            foreach ((array)$this->fbx->tryGet('wifi/ap/', []) as $ap) {
                if (!isset($ap['id'])) continue;
                $id = (int)$ap['id'];
                $band = self::band((string)($ap['config']['band'] ?? ''));
                foreach ((array)$this->fbx->tryGet("wifi/ap/$id/stations/", []) as $s) {
                    $mac = mac_norm($s['mac'] ?? '');
                    if ($mac === '') continue;
                    $out[$mac] = [
                        'down' => (float)($s['tx_rate'] ?? 0),   // émis par la box vers l'appareil
                        'up'   => (float)($s['rx_rate'] ?? 0),   // reçu de l'appareil
                        'conn' => [
                            'kind' => 'wifi', 'band' => $band, 'ap' => (string)($ap['name'] ?? ''),
                            'signal' => isset($s['signal']) ? (int)$s['signal'] : null,
                            'phy_down' => isset($s['last_tx']['bitrate']) ? (int)round($s['last_tx']['bitrate'] / 10) : null,
                            'phy_up' => isset($s['last_rx']['bitrate']) ? (int)round($s['last_rx']['bitrate'] / 10) : null,
                            'since' => (int)($s['conn_duration'] ?? 0),
                            'bytes_down' => (float)($s['tx_bytes'] ?? 0), 'bytes_up' => (float)($s['rx_bytes'] ?? 0),
                        ],
                    ];
                }
            }
            foreach ((array)$this->fbx->tryGet('switch/status/', []) as $p) {
                if (($p['link'] ?? '') !== 'up') continue;
                $macs = array_values(array_filter(array_map(fn($e) => mac_norm(is_array($e) ? ($e['mac'] ?? '') : (string)$e), (array)($p['mac_list'] ?? []))));
                if (!$macs) continue;
                $pid = (int)($p['id'] ?? 0);
                $st = (array)$this->fbx->tryGet("switch/port/$pid/stats", []);
                foreach ($macs as $mac) {
                    if (isset($out[$mac])) continue;
                    $out[$mac] = [
                        'down' => (float)($st['tx_bytes_rate'] ?? 0),
                        'up'   => (float)($st['rx_bytes_rate'] ?? 0),
                        'conn' => ['kind' => 'ethernet', 'port' => $pid, 'port_name' => clean_text($p['name'] ?? "Port $pid", 30),
                            'speed' => (string)($p['speed'] ?? ''), 'shared' => count($macs) > 1, 'neighbours' => count($macs) - 1],
                    ];
                }
            }
            return $out;
        });
    }

    public static function band(string $b): string
    {
        return match (strtolower($b)) {
            '2d4g', '2.4g', '2g4' => '2,4 GHz',
            '5g', '5g1', '5g2' => '5 GHz',
            '6g' => '6 GHz',
            '60g' => '60 GHz',
            default => $b,
        };
    }

    public static function iconType(string $boxType, string $vendor, string $name): string
    {
        if ($boxType !== '' && $boxType !== 'other') return $boxType;
        $v = strtolower($vendor . ' ' . $name);
        $has = static function (string ...$needles) use ($v): bool {
            foreach ($needles as $n) {
                if (str_contains($v, $n)) return true;
            }
            return false;
        };
        return match (true) {
            $has('espressif', 'tuya', 'tapo', 'shelly', 'sonoff', 'philips lighting', 'signify', 'meross', 'netatmo', 'xiaomi iot') => 'smart_home',
            $has('ezviz', 'hikvision', 'reolink', 'arlo', 'camera', 'caméra', 'wyze', 'imou') => 'ip_camera',
            $has('sonos', 'echo', 'homepod', 'nest audio', 'bose') => 'speaker',
            $has('playstation', 'sony interactive', 'nintendo', 'xbox') => 'vg_console',
            $has('synology', 'qnap', 'western digital') => 'nas',
            $has('raspberry') => 'server',
            $has('printer', 'epson', 'brother', 'canon', 'hp inc', 'lexmark') => 'printer',
            $has('iphone', 'galaxy', 'pixel', 'android', 'oneplus', 'redmi') => 'smartphone',
            default => 'other',
        };
    }

    /** Profils Freebox (contrôle d'accès) avec leur état. Ne fait jamais échouer la page. */
    public function netControl(): array
    {
        if ($this->ncCache !== null) return $this->ncCache;
        $errors = [];
        $profiles = (array)$this->fbx->tryGet('profile/', [], $errors);
        $list = (array)$this->fbx->tryGet('network_control/', [], $errors);
        $byId = [];
        $byMac = [];
        foreach ($profiles as $p) {
            if (!isset($p['id'])) continue;
            $byId[(int)$p['id']] = ['id' => (int)$p['id'], 'name' => clean_text($p['name'] ?? '', 40), 'icon' => (string)($p['icon'] ?? ''),
                'mode' => 'allowed', 'override' => false, 'override_mode' => '', 'until' => 0, 'next_change' => 0, 'macs' => [], 'count' => 0];
        }
        foreach ($list as $nc) {
            if (!isset($nc['profile_id'])) continue;
            $id = (int)$nc['profile_id'];
            $macs = array_values(array_filter(array_map('mac_norm', (array)($nc['macs'] ?? []))));
            $byId[$id] = array_replace($byId[$id] ?? ['id' => $id, 'name' => "Profil $id", 'icon' => ''], [
                'mode' => (string)($nc['current_mode'] ?? 'allowed'), 'override' => (bool)($nc['override'] ?? false),
                'override_mode' => (string)($nc['override_mode'] ?? ''), 'until' => (int)($nc['override_until'] ?? 0),
                'next_change' => (int)($nc['next_change'] ?? 0), 'rule_mode' => (string)($nc['rule_mode'] ?? ''),
                'macs' => $macs, 'count' => count($macs),
            ]);
            foreach ($macs as $m) $byMac[$m] = $id;
        }
        return $this->ncCache = ['profiles' => $byId, 'byMac' => $byMac, 'error' => $errors ? implode(' ', array_unique($errors)) : null];
    }

    public function devices(bool $live = true): array
    {
        $hosts = $this->hosts();
        $rates = $live ? $this->liveRates() : [];
        $inv = [];
        foreach (Db::all('SELECT * FROM devices') as $r) $inv[$r['mac']] = $r;
        $dns = $live ? Dns::activityByIp() : [];
        $nc = $this->netControl();
        $sched = Schedules::byProfile();
        $out = [];
        $seen = [];
        foreach ($hosts as $h) {
            $mac = mac_norm($h['l2ident']['id'] ?? '');
            if ($mac === '' || isset($seen[$mac])) continue;
            $seen[$mac] = true;
            $out[] = $this->normalize($h, $inv[$mac] ?? null, $rates[$mac] ?? null, $dns, $nc, $sched);
        }
        usort($out, fn($a, $b) => [$b['online'], $b['rate']['down'] + $b['rate']['up'], $a['name']]
            <=> [$a['online'], $a['rate']['down'] + $a['rate']['up'], $b['name']]);
        return $out;
    }

    private function normalize(array $h, ?array $inv, ?array $live, array $dns, array $nc, array $sched): array
    {
        $mac = mac_norm($h['l2ident']['id'] ?? '');
        $v4 = [];
        $v6 = [];
        foreach ((array)($h['l3connectivities'] ?? []) as $l3) {
            $addr = (string)($l3['addr'] ?? '');
            if ($addr === '' || !filter_var($addr, FILTER_VALIDATE_IP)) continue;
            $e = ['addr' => $addr, 'active' => (bool)($l3['active'] ?? false), 'last' => (int)($l3['last_activity'] ?? 0)];
            if (($l3['af'] ?? '') === 'ipv4') $v4[] = $e;
            elseif (!str_starts_with(strtolower($addr), 'fe80')) $v6[] = $e;
        }
        usort($v4, fn($a, $b) => [$b['active'], $b['last']] <=> [$a['active'], $a['last']]);
        $online = (bool)($h['active'] ?? false) || (bool)($h['reachable'] ?? false);
        $name = clean_text($h['primary_name'] ?? '', 60);
        if ($name === '') $name = (string)($inv['name'] ?? '') ?: $mac;
        $vendor = clean_text($h['vendor_name'] ?? '', 60);
        $boxType = (string)($h['host_type'] ?? 'other');
        $type = ($inv['custom_type'] ?? '') !== '' ? (string)$inv['custom_type'] : self::iconType($boxType, $vendor, $name);
        $pid = $nc['byMac'][$mac] ?? (isset($h['network_control']['profile_id']) ? (int)$h['network_control']['profile_id'] : null);
        $prof = null;
        if ($pid !== null) {
            $p = $nc['profiles'][$pid] ?? ['id' => $pid, 'name' => clean_text($h['network_control']['name'] ?? '', 40),
                'mode' => (string)($h['network_control']['current_mode'] ?? 'allowed'), 'override' => false, 'until' => 0, 'next_change' => 0, 'count' => 1];
            $prof = ['id' => (int)$p['id'], 'name' => $p['name'], 'mode' => $p['mode'], 'override' => (bool)($p['override'] ?? false),
                'until' => (int)($p['until'] ?? 0), 'next_change' => (int)($p['next_change'] ?? 0), 'count' => (int)($p['count'] ?? 1)];
        }
        $conn = $live['conn'] ?? null;
        if ($conn === null && isset($h['access_point']['connectivity_type'])) {
            // Freebox OS récents : informations de connexion directement dans l'hôte
            $ap = $h['access_point'];
            $conn = ($ap['connectivity_type'] ?? '') === 'wifi'
                ? ['kind' => 'wifi', 'band' => self::band((string)($ap['wifi_information']['band'] ?? '')), 'signal' => $ap['wifi_information']['signal'] ?? null]
                : ['kind' => 'ethernet'];
            $live = ['down' => (float)($ap['tx_rate'] ?? 0), 'up' => (float)($ap['rx_rate'] ?? 0)];
        }
        $ip = $v4[0]['addr'] ?? '';
        $names = [];
        foreach ((array)($h['names'] ?? []) as $n) {
            $nm = clean_text($n['name'] ?? '', 80);
            if ($nm !== '') $names[] = ['name' => $nm, 'source' => clean_text($n['source'] ?? '', 20)];
        }
        $approved = (bool)($inv['approved'] ?? false);
        return [
            'mac' => $mac,
            'id' => (string)($h['id'] ?? ''),
            'iface' => (string)($h['interface'] ?? 'pub'),
            'name' => $name,
            'vendor' => $vendor,
            'type' => $type,
            'type_label' => self::TYPES[$type] ?? 'Autre',
            'box_type' => $boxType,
            'online' => $online,
            'last_activity' => (int)($h['last_activity'] ?? 0),
            'first_seen' => (int)($inv['first_seen'] ?? ($h['first_activity'] ?? 0)),
            'ipv4' => $ip,
            'ipv4_all' => array_column($v4, 'addr'),
            'ipv6' => array_slice(array_column($v6, 'addr'), 0, 4),
            'names' => $names,
            'conn' => $conn ?? ['kind' => 'unknown'],
            'rate' => ['down' => round((float)($live['down'] ?? 0)), 'up' => round((float)($live['up'] ?? 0))],
            'profile' => $prof,
            'schedules' => $prof ? ($sched[$prof['id']] ?? []) : [],
            'flags' => [
                'approved' => $approved,
                'isolated' => (bool)($inv['isolated'] ?? false),
                'new' => !$approved && (int)($inv['first_seen'] ?? time()) > time() - 7 * 86400,
                'random_mac' => mac_is_random($mac),
                'guest' => ($h['interface'] ?? '') === 'wifiguest',
                'blocked' => $prof !== null && $prof['mode'] === 'denied',
            ],
            'activity' => ($ip !== '' && isset($dns[$ip])) ? $dns[$ip] : null,
            'note' => (string)($inv['note'] ?? ''),
            'owner' => (string)($inv['owner'] ?? ''),
        ];
    }

    public function device(string $mac): array
    {
        $mac = mac_norm($mac);
        foreach ($this->devices(true) as $d) {
            if ($d['mac'] === $mac) return $d;
        }
        throw new FreeboxError('Appareil introuvable sur le réseau.', 'noent', 'Il a peut-être été retiré de la liste de la Freebox.', 404);
    }

    public function deviceDetail(string $mac): array
    {
        $d = $this->device($mac);
        $d['dns'] = [];
        $d['dns_error'] = null;
        if ($d['ipv4'] !== '' && Dns::enabled()) {
            try {
                $d['dns'] = array_slice(Dns::recent(120, $d['ipv4']), 0, 120);
            } catch (Throwable $e) {
                $d['dns_error'] = $e->getMessage();
            }
        }
        $d['events'] = Db::all('SELECT ts, kind, text FROM events WHERE mac = ? ORDER BY ts DESC LIMIT 40', [$d['mac']]);
        $d['alerts'] = Db::all("SELECT id, severity, title, status, last_ts FROM alerts WHERE mac = ? AND status != 'resolved' ORDER BY last_ts DESC", [$d['mac']]);
        $d['history'] = array_map(fn($r) => [(int)$r['ts'], (float)$r['down'], (float)$r['up']],
            Db::all('SELECT ts, down, up FROM dev_traffic WHERE mac = ? AND ts >= ? ORDER BY ts', [$d['mac'], time() - 6 * 3600]));
        $inv = Db::one('SELECT isolation FROM devices WHERE mac = ?', [$d['mac']]);
        $d['isolation'] = $inv && $inv['isolation'] !== '' ? json_decode($inv['isolation'], true) : null;
        return $d;
    }

    /* ------------------------------------------------------------------ actions : accès Internet */

    /** PUT d'un objet network_control complet (la Freebox exige la liste des MAC à chaque fois). */
    private function putNc(int $id, array $cur, array $changes): array
    {
        $payload = array_replace([
            'profile_id' => $id,
            'macs' => array_values(array_filter(array_map('mac_norm', (array)($cur['macs'] ?? [])))),
            'cdayranges' => array_values((array)($cur['cdayranges'] ?? [])),
            'override' => (bool)($cur['override'] ?? false),
            'override_mode' => (string)($cur['override_mode'] ?? '') ?: 'allowed',
            'override_until' => (int)($cur['override_until'] ?? 0),
        ], $changes);
        $this->fbx->call('PUT', "network_control/$id", $payload);
        $after = (array)$this->fbx->get("network_control/$id");
        $this->dropCaches();
        return $after;
    }

    /** mode : denied | allowed | clear. */
    public function setOverride(int $id, string $mode, int $until = 0): array
    {
        $cur = (array)$this->fbx->get("network_control/$id");
        $changes = $mode === 'clear'
            ? ['override' => false, 'override_until' => 0]
            : ['override' => true, 'override_mode' => $mode, 'override_until' => max(0, $until)];
        $after = $this->putNc($id, $cur, $changes);
        if ((bool)($after['override'] ?? false) !== ($mode !== 'clear')) {
            throw new FreeboxError('La Freebox n\'a pas appliqué le changement d\'accès.', 'not_applied',
                'Vérifiez les droits de Sentinelle dans Freebox OS (Gestion des accès › Applications).');
        }
        return $after;
    }

    private function ensureDedicatedProfile(array $dev): array
    {
        $name = 'Sentinelle · ' . mb_substr($dev['name'], 0, 24);
        $res = (array)$this->fbx->call('POST', 'profile/', ['name' => $name, 'icon' => '/resources/images/profile/profile_01.png']);
        $id = (int)($res['id'] ?? 0);
        if ($id <= 0) {
            throw new FreeboxError('La Freebox n\'a pas créé de profil pour cet appareil.', 'profile_failed',
                'Créez un profil pour cet appareil dans Freebox OS (Contrôle parental › Profils), puis réessayez.');
        }
        if ($dev['profile']) {
            $old = (array)$this->fbx->get('network_control/' . $dev['profile']['id']);
            $this->putNc($dev['profile']['id'], $old, ['macs' => array_values(array_diff(array_map('mac_norm', (array)($old['macs'] ?? [])), [$dev['mac']]))]);
        }
        $this->putNc($id, (array)$this->fbx->get("network_control/$id"), ['macs' => [$dev['mac']]]);
        Db::event('profile', $dev['mac'], "Profil dédié « $name » créé sur la Freebox");
        return ['id' => $id, 'name' => $name, 'count' => 1];
    }

    /**
     * Coupe Internet pour un appareil. scope : '' (demander si le profil est partagé),
     * 'profile' (tout le profil) ou 'dedicated' (profil dédié à l'appareil).
     */
    public function blockDevice(string $mac, int $minutes = 0, string $scope = ''): array
    {
        $dev = $this->device($mac);
        $prof = $dev['profile'];
        if ($prof && $prof['count'] > 1 && $scope === '') {
            return ['needs_choice' => true, 'profile' => $prof['name'], 'count' => $prof['count']];
        }
        if (!$prof || ($scope === 'dedicated' && $prof['count'] > 1)) $prof = $this->ensureDedicatedProfile($dev);
        $until = $minutes > 0 ? time() + $minutes * 60 : 0;
        $this->setOverride((int)$prof['id'], 'denied', $until);
        $txt = $until ? 'jusqu\'à ' . date('H:i', $until) : 'jusqu\'à nouvel ordre';
        Db::event('block', $dev['mac'], "Internet coupé ($txt) — profil « {$prof['name']} »");
        return ['ok' => true, 'profile' => $prof['name'], 'until' => $until];
    }

    public function allowDevice(string $mac, bool $force = false): array
    {
        $dev = $this->device($mac);
        $prof = $dev['profile'];
        if (!$prof) return ['ok' => true, 'note' => 'Cet appareil n\'est rattaché à aucun profil : son accès n\'est pas restreint.'];
        $after = $force
            ? $this->setOverride($prof['id'], 'allowed', $prof['next_change'] > time() ? $prof['next_change'] : time() + 3600)
            : $this->setOverride($prof['id'], 'clear');
        Schedules::skipActive($prof['id']);
        $still = ($after['current_mode'] ?? 'allowed') === 'denied';
        Db::event('allow', $dev['mac'], $force ? 'Accès Internet autorisé exceptionnellement' : 'Accès Internet rétabli');
        return ['ok' => true, 'profile' => $prof['name'], 'still_denied' => $still, 'next_change' => (int)($after['next_change'] ?? 0)];
    }

    public function profileMode(int $id, string $mode, int $minutes = 0): array
    {
        if (!in_array($mode, ['denied', 'allowed', 'clear'], true)) throw new InvalidArgumentException('Mode inconnu.');
        $after = $this->setOverride($id, $mode, $minutes > 0 ? time() + $minutes * 60 : 0);
        if ($mode !== 'denied') Schedules::skipActive($id);
        return ['ok' => true, 'mode' => (string)($after['current_mode'] ?? '')];
    }

    /* ------------------------------------------------------------------ actions : isolement */

    public function isolate(string $mac): array
    {
        $dev = $this->device($mac);
        $report = ['internet' => null, 'wifi' => null, 'dns' => null, 'lan' => null];
        $revert = [];
        try {
            $r = $this->blockDevice($dev['mac'], 0, 'dedicated');
            $report['internet'] = ['ok' => true, 'detail' => 'Internet coupé (profil « ' . $r['profile'] . ' »)'];
        } catch (Throwable $e) {
            $report['internet'] = ['ok' => false, 'detail' => $e->getMessage() . (($e instanceof FreeboxError && $e->hint) ? ' — ' . $e->hint : '')];
        }
        if (($dev['conn']['kind'] ?? '') !== 'ethernet') {
            try {
                $w = $this->wifiDeny($dev['mac']);
                $revert['wifi'] = $w['revert'];
                $report['wifi'] = ['ok' => true, 'detail' => $w['detail']];
            } catch (Throwable $e) {
                $report['wifi'] = ['ok' => false, 'detail' => $e->getMessage()];
            }
        } else {
            $report['wifi'] = ['ok' => null, 'detail' => 'Appareil filaire : pas de filtrage Wi-Fi possible'];
        }
        if (Dns::enabled() && $dev['ipv4'] !== '') {
            try {
                $report['dns'] = ['ok' => true, 'detail' => Dns::setClientDenied($dev['ipv4'], true)];
                $revert['dns_ip'] = $dev['ipv4'];
            } catch (Throwable $e) {
                $report['dns'] = ['ok' => false, 'detail' => $e->getMessage()];
            }
        } else {
            $report['dns'] = ['ok' => null, 'detail' => 'AdGuard Home non configuré'];
        }
        $report['lan'] = ['ok' => null, 'detail' => ($dev['conn']['kind'] ?? '') === 'ethernet'
            ? 'La Freebox ne sait pas séparer un appareil filaire du reste du réseau local : débranchez son câble.'
            : 'Une fois refusé sur le Wi-Fi, l\'appareil ne joint plus les autres appareils de la maison.'];
        $rec = ['ts' => time(), 'report' => $report, 'revert' => $revert];
        Db::q('UPDATE devices SET isolated = 1, isolation = ? WHERE mac = ?', [json_encode($rec, JSON_UNESCAPED_UNICODE), $dev['mac']]);
        Db::event('isolate', $dev['mac'], 'Appareil isolé : ' . implode(' · ', array_filter(array_map(fn($x) => $x && $x['ok'] ? $x['detail'] : null, $report))));
        $this->dropCaches();
        return ['ok' => true, 'report' => $report];
    }

    public function release(string $mac): array
    {
        $mac = mac_norm($mac);
        $row = Db::one('SELECT isolation FROM devices WHERE mac = ?', [$mac]);
        $rec = $row && $row['isolation'] !== '' ? (array)json_decode($row['isolation'], true) : [];
        $revert = (array)($rec['revert'] ?? []);
        $done = [];
        try {
            $this->allowDevice($mac);
            $done[] = 'Internet rétabli';
        } catch (Throwable $e) {
            $done[] = 'Internet : ' . $e->getMessage();
        }
        if (!empty($revert['wifi'])) {
            try {
                $this->wifiAllow($mac, (array)$revert['wifi']);
                $done[] = 'Wi-Fi rétabli';
            } catch (Throwable $e) {
                $done[] = 'Wi-Fi : ' . $e->getMessage();
            }
        }
        if (!empty($revert['dns_ip'])) {
            try {
                Dns::setClientDenied((string)$revert['dns_ip'], false);
                $done[] = 'DNS rétabli';
            } catch (Throwable $e) {
                $done[] = 'DNS : ' . $e->getMessage();
            }
        }
        Db::q("UPDATE devices SET isolated = 0, isolation = '' WHERE mac = ?", [$mac]);
        Db::event('release', $mac, 'Fin d\'isolement : ' . implode(' · ', $done));
        $this->dropCaches();
        return ['ok' => true, 'done' => $done];
    }

    private function wifiDeny(string $mac): array
    {
        $cfg = (array)$this->fbx->get('wifi/config/');
        $state = (string)($cfg['mac_filter_state'] ?? 'disabled');
        $filters = (array)$this->fbx->tryGet('wifi/mac_filter/', []);
        $revert = ['state_before' => $state, 'created' => null, 'removed' => null];
        if ($state === 'whitelist') {
            foreach ($filters as $f) {
                if (mac_norm($f['mac'] ?? '') === $mac && ($f['type'] ?? '') === 'whitelist') {
                    $this->fbx->call('DELETE', 'wifi/mac_filter/' . rawurlencode((string)$f['id']));
                    $revert['removed'] = ['mac' => $mac, 'type' => 'whitelist', 'comment' => (string)($f['comment'] ?? '')];
                }
            }
            return ['detail' => 'retiré de la liste des appareils autorisés sur le Wi-Fi', 'revert' => $revert];
        }
        $exists = false;
        foreach ($filters as $f) {
            if (mac_norm($f['mac'] ?? '') === $mac && ($f['type'] ?? '') === 'blacklist') $exists = true;
        }
        if (!$exists) {
            $res = (array)$this->fbx->call('POST', 'wifi/mac_filter/', ['mac' => $mac, 'type' => 'blacklist', 'comment' => 'Sentinelle : appareil isolé']);
            $revert['created'] = (string)($res['id'] ?? ($mac . '-blacklist'));
        }
        if ($state === 'disabled') $this->fbx->call('PUT', 'wifi/config/', ['mac_filter_state' => 'blacklist']);
        return ['detail' => 'refusé sur le Wi-Fi (liste noire de la Freebox)', 'revert' => $revert];
    }

    private function wifiAllow(string $mac, array $revert): void
    {
        if (!empty($revert['created'])) {
            $this->fbx->call('DELETE', 'wifi/mac_filter/' . rawurlencode((string)$revert['created']));
        }
        if (!empty($revert['removed'])) {
            $this->fbx->call('POST', 'wifi/mac_filter/', (array)$revert['removed']);
        }
        if (($revert['state_before'] ?? '') === 'disabled') {
            $left = array_filter((array)$this->fbx->tryGet('wifi/mac_filter/', []), fn($f) => ($f['type'] ?? '') === 'blacklist');
            if (!$left) $this->fbx->call('PUT', 'wifi/config/', ['mac_filter_state' => 'disabled']);
        }
    }

    /* ------------------------------------------------------------------ actions : divers */

    public function wake(string $mac): array
    {
        $dev = $this->device($mac);
        $this->fbx->call('POST', 'lan/wol/' . ($dev['iface'] ?: 'pub') . '/', ['mac' => $dev['mac'], 'password' => '']);
        Db::event('wol', $dev['mac'], 'Réveil à distance (Wake-on-LAN) envoyé');
        return ['ok' => true];
    }

    public function rename(string $mac, string $name, string $type = ''): array
    {
        $dev = $this->device($mac);
        $name = clean_text($name, 60);
        if ($name === '') throw new InvalidArgumentException('Le nom ne peut pas être vide.');
        $payload = ['id' => $dev['id'], 'primary_name' => $name, 'host_type' => $dev['box_type'] ?: 'other'];
        if ($type !== '' && isset(self::TYPES[$type]) && !in_array($type, ['smart_home', 'speaker', 'server'], true)) $payload['host_type'] = $type;
        if (!preg_match('/^[A-Za-z0-9:._-]{1,80}$/', $dev['id'])) throw new InvalidArgumentException('Identifiant d\'appareil inattendu.');
        $this->fbx->call('PUT', 'lan/browser/' . ($dev['iface'] ?: 'pub') . '/' . $dev['id'], $payload);
        Db::q('UPDATE devices SET name = ?, custom_type = ? WHERE mac = ?', [$name, $type !== '' && isset(self::TYPES[$type]) ? $type : '', $dev['mac']]);
        $this->dropCaches();
        return ['ok' => true];
    }

    public function approve(string $mac, bool $approved, string $note = ''): array
    {
        $mac = mac_norm($mac);
        Db::q('UPDATE devices SET approved = ?, note = CASE WHEN ? = \'\' THEN note ELSE ? END WHERE mac = ?', [$approved ? 1 : 0, $note, clean_text($note, 200), $mac]);
        if ($approved) {
            Db::q("UPDATE alerts SET status = 'resolved', resolved_ts = ? WHERE mac = ? AND rule IN ('new_device', 'unknown_device') AND status != 'resolved'", [time(), $mac]);
        }
        Db::event('approve', $mac, $approved ? 'Appareil marqué comme connu' : 'Appareil marqué comme inconnu');
        return ['ok' => true];
    }

    /* ------------------------------------------------------------------ exposition sur Internet */

    public function exposure(): array
    {
        $err = [];
        $redirs = (array)$this->fbx->tryGet('fw/redir/', [], $err);
        $dmz = (array)$this->fbx->tryGet('fw/dmz/', [], $err);
        $upnp = (array)$this->fbx->tryGet('upnpigd/config/', [], $err);
        $upnpRedirs = !empty($upnp['enabled']) ? (array)$this->fbx->tryGet('upnpigd/redir/', [], $err) : [];
        $cfg = (array)$this->fbx->tryGet('connection/config/', [], $err);
        $v6 = (array)$this->fbx->tryGet('connection/ipv6/config/', [], $err);
        $ftp = (array)$this->fbx->tryGet('ftp/config/', [], $err);
        $smb = (array)$this->fbx->tryGet('netshare/samba/', [], $err);
        $conn = (array)$this->fbx->tryGet('connection/', [], $err);
        $byIp = [];
        try {
            foreach ($this->devices(false) as $d) {
                foreach ($d['ipv4_all'] as $ip) $byIp[$ip] ??= $d;
            }
        } catch (Throwable) {
        }
        $label = static fn(string $ip) => isset($byIp[$ip]) ? ['name' => $byIp[$ip]['name'], 'type' => $byIp[$ip]['type'], 'mac' => $byIp[$ip]['mac']] : null;
        $fw = [];
        foreach ($redirs as $r) {
            $ip = (string)($r['lan_ip'] ?? '');
            $dev = $label($ip);
            $start = (int)($r['wan_port_start'] ?? 0);
            $end = (int)($r['wan_port_end'] ?? $start);
            $fw[] = [
                'id' => (int)($r['id'] ?? 0), 'enabled' => (bool)($r['enabled'] ?? false), 'proto' => strtoupper((string)($r['ip_proto'] ?? '')),
                'wan' => $start === $end ? (string)$start : "$start-$end", 'lan_ip' => $ip, 'lan_port' => (int)($r['lan_port'] ?? 0),
                'src' => (string)($r['src_ip'] ?? '0.0.0.0'), 'comment' => clean_text($r['comment'] ?? '', 80),
                'host' => $dev['name'] ?? clean_text($r['hostname'] ?? '', 60), 'host_type' => $dev['type'] ?? '',
                'risk' => self::portRisk((int)($r['lan_port'] ?? $start), $dev['type'] ?? ''),
            ];
        }
        $up = [];
        foreach ($upnpRedirs as $r) {
            $ip = (string)($r['int_ip'] ?? $r['lan_ip'] ?? '');
            $dev = $label($ip);
            $port = (int)($r['int_port'] ?? $r['lan_port'] ?? 0);
            $up[] = [
                'id' => (string)($r['id'] ?? ''), 'enabled' => (bool)($r['enabled'] ?? true),
                'proto' => strtoupper((string)($r['proto'] ?? $r['ip_proto'] ?? '')),
                'ext_port' => (int)($r['ext_port'] ?? $r['wan_port'] ?? $r['ext_port_start'] ?? 0), 'int_ip' => $ip, 'int_port' => $port,
                'desc' => clean_text($r['desc'] ?? $r['description'] ?? $r['comment'] ?? '', 80),
                'host' => $dev['name'] ?? '', 'host_type' => $dev['type'] ?? '', 'mac' => $dev['mac'] ?? '',
                'risk' => self::portRisk($port, $dev['type'] ?? ''),
            ];
        }
        return [
            'redirs' => $fw,
            'dmz' => ['enabled' => (bool)($dmz['enabled'] ?? false), 'ip' => (string)($dmz['ip'] ?? ''), 'host' => $label((string)($dmz['ip'] ?? ''))['name'] ?? ''],
            'upnp' => ['enabled' => (bool)($upnp['enabled'] ?? false), 'redirs' => $up],
            'remote' => [
                'remote_access' => (bool)($cfg['remote_access'] ?? false), 'port' => (int)($cfg['remote_access_port'] ?? 0),
                'secure_pass' => (bool)($cfg['is_secure_pass'] ?? true), 'api_remote_access' => (bool)($cfg['api_remote_access'] ?? false),
                'ping' => (bool)($cfg['ping'] ?? false), 'wol' => (bool)($cfg['wol'] ?? false),
                'allow_token_request' => (bool)($cfg['allow_token_request'] ?? false), 'known' => $cfg !== [],
            ],
            'ipv6' => ['enabled' => (bool)($v6['ipv6_enabled'] ?? false), 'firewall' => (bool)($v6['ipv6_firewall'] ?? false),
                'known' => array_key_exists('ipv6_firewall', $v6),
                'prefixes' => array_values(array_filter(array_map(fn($x) => (string)($x['prefix'] ?? ''), (array)($v6['delegations'] ?? []))))],
            'ftp' => ['enabled' => (bool)($ftp['enabled'] ?? false), 'remote' => (bool)($ftp['allow_remote_access'] ?? false),
                'anonymous' => (bool)($ftp['allow_anonymous'] ?? false), 'anonymous_write' => (bool)($ftp['allow_anonymous_write'] ?? false)],
            'samba' => ['enabled' => (bool)($smb['file_share_enabled'] ?? false), 'logon' => (bool)($smb['logon_enabled'] ?? false)],
            'wan' => ['ipv4' => (string)($conn['ipv4'] ?? ''), 'ipv6' => (string)($conn['ipv6'] ?? ''), 'state' => (string)($conn['state'] ?? '')],
            'errors' => array_values(array_unique($err)),
        ];
    }

    /** Niveau de risque d'un port ouvert depuis Internet. */
    public static function portRisk(int $port, string $hostType): array
    {
        $why = match (true) {
            in_array($port, [23, 2323], true) => ['critique', 'Telnet : protocole sans chiffrement, cible n° 1 des botnets (Mirai)'],
            in_array($port, [445, 139, 137, 138], true) => ['critique', 'Partage de fichiers Windows (SMB) : vecteur des rançongiciels'],
            $port === 3389 => ['critique', 'Bureau à distance Windows : très attaqué par force brute'],
            in_array($port, [5900, 5901], true) => ['critique', 'Prise de main à distance (VNC)'],
            in_array($port, [1433, 3306, 5432, 6379, 9200, 27017, 11211], true) => ['critique', 'Base de données exposée'],
            in_array($port, [21, 69], true) => ['elevee', 'Transfert de fichiers non chiffré'],
            $port === 22 => ['elevee', 'Accès SSH : à protéger par clé, jamais par simple mot de passe'],
            in_array($port, [80, 8080, 8000, 8888, 81], true) => ['moyenne', 'Interface web non chiffrée'],
            in_array($port, [443, 8443, 5001], true) => ['moyenne', 'Interface web : vérifiez mises à jour et mot de passe'],
            in_array($port, [554, 8554, 37777, 34567], true) => ['elevee', 'Flux vidéo de caméra'],
            in_array($port, [1194, 51820, 500, 4500], true) => ['faible', 'VPN : exposition attendue si vous l\'utilisez'],
            default => ['faible', 'Service exposé : à garder seulement s\'il est utile'],
        };
        if (in_array($hostType, ['ip_camera', 'smart_home'], true) && $why[0] !== 'critique') {
            $why = [$why[0] === 'faible' ? 'moyenne' : 'elevee', $why[1] . ' — sur un objet connecté, rarement mis à jour'];
        }
        return ['level' => $why[0], 'why' => $why[1]];
    }

    /** Corrections en un clic proposées par les alertes et la page Exposition. */
    public function applyFix(string $fix, array $args = []): array
    {
        $id = (string)($args['id'] ?? '');
        $res = match ($fix) {
            'ipv6_firewall_on'  => $this->fbx->call('PUT', 'connection/ipv6/config/', ['ipv6_firewall' => true]),
            'upnp_off'          => $this->fbx->call('PUT', 'upnpigd/config/', ['enabled' => false]),
            'upnp_redir_delete' => $this->fbx->call('DELETE', 'upnpigd/redir/' . rawurlencode($id)),
            'dmz_off'           => $this->fbx->call('PUT', 'fw/dmz/', ['enabled' => false]),
            'redir_disable'     => $this->fbx->call('PUT', 'fw/redir/' . (int)$id, ['enabled' => false]),
            'redir_enable'      => $this->fbx->call('PUT', 'fw/redir/' . (int)$id, ['enabled' => true]),
            'redir_delete'      => $this->fbx->call('DELETE', 'fw/redir/' . (int)$id),
            'remote_access_off' => $this->fbx->call('PUT', 'connection/config/', ['remote_access' => false]),
            'token_request_off' => $this->fbx->call('PUT', 'connection/config/', ['allow_token_request' => false]),
            'ping_off'          => $this->fbx->call('PUT', 'connection/config/', ['ping' => false]),
            'wol_off'           => $this->fbx->call('PUT', 'connection/config/', ['wol' => false]),
            'ftp_remote_off'    => $this->fbx->call('PUT', 'ftp/config/', ['allow_remote_access' => false]),
            default             => throw new InvalidArgumentException('Correction inconnue.'),
        };
        // Vérification : certaines versions de Freebox OS ignorent silencieusement une modification partielle.
        $check = match ($fix) {
            'ipv6_firewall_on'  => fn() => (bool)(($this->fbx->get('connection/ipv6/config/'))['ipv6_firewall'] ?? false) === true,
            'upnp_off'          => fn() => (bool)(($this->fbx->get('upnpigd/config/'))['enabled'] ?? true) === false,
            'dmz_off'           => fn() => (bool)(($this->fbx->get('fw/dmz/'))['enabled'] ?? true) === false,
            'remote_access_off' => fn() => (bool)(($this->fbx->get('connection/config/'))['remote_access'] ?? true) === false,
            default             => null,
        };
        if ($check !== null && !$check()) {
            throw new FreeboxError('La Freebox n\'a pas enregistré la modification.', 'not_applied',
                'Faites-la dans Freebox OS (Paramètres de la Freebox), puis relancez l\'analyse.');
        }
        $this->dropCaches();
        cache_drop($this->key('exposure'));
        return ['ok' => true, 'result' => is_array($res) ? $res : null];
    }

    /* ------------------------------------------------------------------ box, Player, Wi-Fi, trafic */

    public function link(): array
    {
        $c = (array)$this->fbx->get('connection/');
        return [
            'state' => (string)($c['state'] ?? ''), 'media' => (string)($c['media'] ?? ''), 'type' => (string)($c['type'] ?? ''),
            'ipv4' => (string)($c['ipv4'] ?? ''), 'ipv6' => (string)($c['ipv6'] ?? ''),
            'down' => (float)($c['rate_down'] ?? 0), 'up' => (float)($c['rate_up'] ?? 0),
            'bw_down' => (float)($c['bandwidth_down'] ?? $c['bandwith_down'] ?? 0), 'bw_up' => (float)($c['bandwidth_up'] ?? $c['bandwith_up'] ?? 0),
            'bytes_down' => (float)($c['bytes_down'] ?? 0), 'bytes_up' => (float)($c['bytes_up'] ?? 0),
        ];
    }

    public function system(): array
    {
        return cache_get($this->key('system'), 30, fn() => (array)$this->fbx->tryGet('system/', []));
    }

    public function box(): array
    {
        $err = [];
        $sys = (array)$this->fbx->tryGet('system/', [], $err);
        $conn = (array)$this->fbx->tryGet('connection/', [], $err);
        $media = (string)($conn['media'] ?? '');
        $ftth = $media === 'ftth' ? (array)$this->fbx->tryGet('connection/ftth/', [], $err) : [];
        $xdsl = $media === 'xdsl' ? (array)$this->fbx->tryGet('connection/xdsl/', [], $err) : [];
        $lan = (array)$this->fbx->tryGet('lan/config/', [], $err);
        $dhcp = (array)$this->fbx->tryGet('dhcp/config/', [], $err);
        $leases = (array)$this->fbx->tryGet('dhcp/static_lease/', [], $err);
        $ports = (array)$this->fbx->tryGet('switch/status/', [], $err);
        $serial = (string)($sys['serial'] ?? '');
        if (strlen($serial) > 8) $serial = substr($serial, 0, 4) . str_repeat('•', strlen($serial) - 8) . substr($serial, -4);
        return [
            'model' => clean_text($sys['model_info']['pretty_name'] ?? ($this->fbx->info()['api']['box_model'] ?? 'Freebox'), 60),
            'firmware' => clean_text($sys['firmware_version'] ?? '', 30),
            'uptime' => (int)($sys['uptime_val'] ?? 0),
            'serial' => $serial,
            'mac' => mac_norm($sys['mac'] ?? ''),
            'board' => clean_text($sys['board_name'] ?? '', 30),
            'disk' => clean_text($sys['disk_status'] ?? '', 30),
            'sensors' => array_map(fn($s) => ['name' => clean_text($s['name'] ?? $s['id'] ?? '', 40), 'value' => (float)($s['value'] ?? 0)], (array)($sys['sensors'] ?? [])),
            'fans' => array_map(fn($s) => ['name' => clean_text($s['name'] ?? $s['id'] ?? '', 40), 'value' => (float)($s['value'] ?? 0)], (array)($sys['fans'] ?? [])),
            'connection' => [
                'state' => (string)($conn['state'] ?? ''), 'media' => $media, 'type' => (string)($conn['type'] ?? ''),
                'ipv4' => (string)($conn['ipv4'] ?? ''), 'ipv6' => (string)($conn['ipv6'] ?? ''),
                'bw_down' => (float)($conn['bandwidth_down'] ?? $conn['bandwith_down'] ?? 0), 'bw_up' => (float)($conn['bandwidth_up'] ?? $conn['bandwith_up'] ?? 0),
                'bytes_down' => (float)($conn['bytes_down'] ?? 0), 'bytes_up' => (float)($conn['bytes_up'] ?? 0),
            ],
            'ftth' => $ftth ? [
                'link' => (bool)($ftth['link'] ?? false), 'sfp' => (bool)($ftth['sfp_present'] ?? false),
                'rx_dbm' => isset($ftth['sfp_pwr_rx']) ? round($ftth['sfp_pwr_rx'] / 100, 2) : null,
                'tx_dbm' => isset($ftth['sfp_pwr_tx']) ? round($ftth['sfp_pwr_tx'] / 100, 2) : null,
                'model' => clean_text(trim(($ftth['sfp_vendor'] ?? '') . ' ' . ($ftth['sfp_model'] ?? '')), 60),
            ] : null,
            'xdsl' => $xdsl ? ['status' => clean_text($xdsl['status']['status'] ?? '', 30), 'modulation' => clean_text($xdsl['status']['modulation'] ?? '', 30),
                'rate_down' => (int)($xdsl['down']['rate'] ?? 0), 'rate_up' => (int)($xdsl['up']['rate'] ?? 0),
                'snr_down' => (float)($xdsl['down']['snr'] ?? 0), 'attn_down' => (float)($xdsl['down']['attn'] ?? 0)] : null,
            'lan' => ['ip' => (string)($lan['ip'] ?? ''), 'name' => clean_text($lan['name'] ?? '', 60), 'mode' => (string)($lan['mode'] ?? '')],
            'dhcp' => ['enabled' => (bool)($dhcp['enabled'] ?? false), 'range' => trim(($dhcp['ip_range_start'] ?? '') . ' → ' . ($dhcp['ip_range_end'] ?? ''), ' →'),
                'dns' => array_values(array_filter(array_map('strval', (array)($dhcp['dns'] ?? [])))), 'sticky' => (bool)($dhcp['sticky_assign'] ?? false),
                'static' => array_map(fn($l) => ['mac' => mac_norm($l['mac'] ?? ''), 'ip' => (string)($l['ip'] ?? ''),
                    'name' => clean_text($l['hostname'] ?? $l['comment'] ?? '', 60)], $leases)],
            'ports' => array_map(fn($p) => ['id' => (int)($p['id'] ?? 0), 'name' => clean_text($p['name'] ?? '', 30), 'link' => (string)($p['link'] ?? ''),
                'speed' => (string)($p['speed'] ?? ''), 'devices' => array_map(fn($e) => clean_text(is_array($e) ? ($e['hostname'] ?? $e['mac'] ?? '') : (string)$e, 40), (array)($p['mac_list'] ?? []))], $ports),
            'link' => $this->fbx->info(),
            'errors' => array_values(array_unique($err)),
        ];
    }

    public function players(): array
    {
        $err = [];
        $list = (array)$this->fbx->tryGet('player/', [], $err);
        $hosts = [];
        try {
            foreach ($this->devices(false) as $d) $hosts[$d['mac']] = $d;
        } catch (Throwable) {
        }
        $out = [];
        foreach ($list as $p) {
            $id = (int)($p['id'] ?? 0);
            $status = null;
            $volume = null;
            if (!empty($p['api_available']) && !empty($p['reachable'])) {
                $status = $this->fbx->tryGet("player/$id/api/v6/status/", null, $err);
                $volume = $this->fbx->tryGet("player/$id/api/v6/control/volume", null, $err);
            }
            $fg = is_array($status) ? ($status['foreground_app'] ?? null) : null;
            $pkg = (string)($fg['package'] ?? '');
            $ch = $fg['context']['channel'] ?? null;
            $mac = mac_norm($p['mac'] ?? '');
            $h = $hosts[$mac] ?? null;
            $out[] = [
                'id' => $id, 'name' => clean_text($p['device_name'] ?? 'Player', 60), 'model' => clean_text($p['device_model'] ?? '', 60),
                'api_version' => clean_text($p['api_version'] ?? '', 10), 'reachable' => (bool)($p['reachable'] ?? false),
                'api_available' => (bool)($p['api_available'] ?? false), 'mac' => $mac, 'stb_type' => clean_text($p['stb_type'] ?? '', 20),
                'last_seen' => (int)($p['last_time_reachable'] ?? 0),
                'power' => is_array($status) ? (string)($status['power_state'] ?? '') : '',
                'app' => $pkg !== '' ? ['package' => clean_text($pkg, 80), 'name' => self::APPS[$pkg] ?? ucfirst((string)substr(strrchr('.' . $pkg, '.') ?: $pkg, 1))] : null,
                'channel' => is_array($ch) ? ['name' => clean_text($ch['channelName'] ?? '', 60), 'number' => (int)($ch['channelNumber'] ?? 0),
                    'bouquet' => clean_text($ch['bouquetName'] ?? '', 40)] : null,
                'playback' => clean_text($fg['context']['player']['playbackState'] ?? '', 20),
                'volume' => is_array($volume) ? ['level' => (int)($volume['volume'] ?? 0), 'mute' => (bool)($volume['mute'] ?? false)] : null,
                'host' => $h ? ['ip' => $h['ipv4'], 'conn' => $h['conn'], 'rate' => $h['rate'], 'name' => $h['name']] : null,
            ];
        }
        return ['players' => $out, 'errors' => array_values(array_unique($err))];
    }

    public function wifi(): array
    {
        $err = [];
        $cfg = (array)$this->fbx->tryGet('wifi/config/', [], $err);
        $aps = (array)$this->fbx->tryGet('wifi/ap/', [], $err);
        $bss = (array)$this->fbx->tryGet('wifi/bss/', [], $err);
        $filters = (array)$this->fbx->tryGet('wifi/mac_filter/', [], $err);
        $live = $this->liveRates();
        $count = [];
        foreach ($live as $l) {
            if (($l['conn']['kind'] ?? '') === 'wifi') $count[$l['conn']['band']] = ($count[$l['conn']['band']] ?? 0) + 1;
        }
        return [
            'enabled' => (bool)($cfg['enabled'] ?? false),
            'mac_filter_state' => (string)($cfg['mac_filter_state'] ?? 'disabled'),
            'aps' => array_map(function ($a) use ($count) {
                $band = self::band((string)($a['config']['band'] ?? ''));
                return ['id' => (int)($a['id'] ?? 0), 'name' => clean_text($a['name'] ?? '', 20), 'band' => $band, 'status' => (string)($a['status'] ?? ''),
                    'channel' => (int)($a['config']['primary_channel'] ?? 0), 'width' => (int)($a['config']['channel_width'] ?? 0), 'stations' => $count[$band] ?? 0];
            }, $aps),
            'bss' => array_map(function ($b) {
                $c = (array)($b['config'] ?? $b['bss_params'] ?? $b['shared_bss_settings'] ?? []);
                return ['id' => (string)($b['id'] ?? ''), 'ssid' => clean_text($c['ssid'] ?? '', 40), 'enabled' => (bool)($c['enabled'] ?? true),
                    'hidden' => (bool)($c['hide_ssid'] ?? false), 'encryption' => (string)($c['encryption'] ?? ''),
                    'security' => self::wifiSecurity((string)($c['encryption'] ?? '')), 'wps' => (bool)($c['wps_enabled'] ?? false),
                    'stations' => (int)($b['status']['sta_count'] ?? 0), 'phy' => (int)($b['phy_id'] ?? 0)];
                // la clé Wi-Fi (« key ») n'est jamais renvoyée au navigateur
            }, $bss),
            'filters' => array_map(fn($f) => ['id' => (string)($f['id'] ?? ''), 'mac' => mac_norm($f['mac'] ?? ''), 'type' => (string)($f['type'] ?? ''),
                'comment' => clean_text($f['comment'] ?? '', 60), 'hostname' => clean_text($f['hostname'] ?? '', 60)], $filters),
            'errors' => array_values(array_unique($err)),
        ];
    }

    /** Qualité du chiffrement Wi-Fi. */
    public static function wifiSecurity(string $enc): array
    {
        $e = strtolower($enc);
        return match (true) {
            $e === '' => ['level' => 'inconnu', 'label' => 'Inconnu'],
            in_array($e, ['none', 'open'], true) => ['level' => 'critique', 'label' => 'Réseau ouvert, sans mot de passe'],
            str_contains($e, 'wep') => ['level' => 'critique', 'label' => 'WEP : cassable en quelques minutes'],
            str_contains($e, 'tkip') || str_starts_with($e, 'wpa_') => ['level' => 'elevee', 'label' => 'WPA/TKIP : obsolète'],
            str_contains($e, 'wpa3') || str_contains($e, 'wpa23') || str_contains($e, 'sae') => ['level' => 'ok', 'label' => str_contains($e, 'wpa23') ? 'WPA2/WPA3 (mixte)' : 'WPA3'],
            str_contains($e, 'wpa2') || str_contains($e, 'wpa12') => ['level' => 'info', 'label' => 'WPA2 : correct, WPA3 recommandé'],
            default => ['level' => 'inconnu', 'label' => $enc],
        };
    }

    public function wifiFilter(string $op, array $in): array
    {
        if ($op === 'add') {
            $mac = mac_norm($in['mac'] ?? '');
            $type = in_array($in['type'] ?? '', ['whitelist', 'blacklist'], true) ? (string)$in['type'] : '';
            if ($mac === '' || $type === '') throw new InvalidArgumentException('Adresse MAC ou type de liste invalide.');
            $this->fbx->call('POST', 'wifi/mac_filter/', ['mac' => $mac, 'type' => $type, 'comment' => clean_text($in['comment'] ?? '', 60)]);
        } elseif ($op === 'delete') {
            $this->fbx->call('DELETE', 'wifi/mac_filter/' . rawurlencode((string)($in['id'] ?? '')));
        } elseif ($op === 'mode') {
            $mode = (string)($in['mode'] ?? '');
            if (!in_array($mode, ['disabled', 'whitelist', 'blacklist'], true)) throw new InvalidArgumentException('Mode de filtrage inconnu.');
            if ($mode === 'whitelist') {
                $wl = array_filter((array)$this->fbx->tryGet('wifi/mac_filter/', []), fn($f) => ($f['type'] ?? '') === 'whitelist');
                if (!$wl && empty($in['confirm_empty'])) {
                    throw new InvalidArgumentException('La liste blanche est vide : l\'activer couperait TOUS les appareils Wi-Fi (y compris celui que vous utilisez peut-être). Ajoutez d\'abord vos appareils.');
                }
            }
            $this->fbx->call('PUT', 'wifi/config/', ['mac_filter_state' => $mode]);
        } else {
            throw new InvalidArgumentException('Opération inconnue.');
        }
        $this->dropCaches();
        return ['ok' => true];
    }

    public function trafficHistory(string $range): array
    {
        $span = ['hour' => 3600, 'day' => 86400, 'week' => 7 * 86400][$range] ?? 3600;
        $end = time();
        $start = $end - $span;
        $points = [];
        $source = 'freebox';
        try {
            $res = (array)$this->fbx->call('POST', 'rrd/', ['db' => 'net', 'date_start' => $start, 'date_end' => $end,
                'fields' => ['time', 'rate_down', 'rate_up'], 'precision' => 10]);
            foreach ((array)($res['data'] ?? []) as $p) {
                if (isset($p['time'])) $points[] = [(int)$p['time'], (float)($p['rate_down'] ?? 0), (float)($p['rate_up'] ?? 0)];
            }
        } catch (Throwable) {
            $points = [];
        }
        if (!$points) {
            $source = 'sentinelle';
            foreach (Db::all('SELECT ts, down, up FROM traffic WHERE ts >= ? ORDER BY ts', [$start]) as $r) {
                $points[] = [(int)$r['ts'], (float)$r['down'], (float)$r['up']];
            }
        }
        // Top des appareils sur la période (échantillons de la collecte)
        $top = Db::all('SELECT t.mac, AVG(t.down) AS down, AVG(t.up) AS up, MAX(t.up) AS peak_up, COALESCE(d.name, t.mac) AS name
            FROM dev_traffic t LEFT JOIN devices d ON d.mac = t.mac WHERE t.ts >= ? GROUP BY t.mac ORDER BY AVG(t.down + t.up) DESC LIMIT 8', [$start]);
        return ['range' => $range, 'source' => $source, 'points' => self::downsample($points, 240),
            'top' => array_map(fn($r) => ['mac' => $r['mac'], 'name' => $r['name'], 'down' => round((float)$r['down']), 'up' => round((float)$r['up']),
                'peak_up' => round((float)$r['peak_up'])], $top)];
    }

    private static function downsample(array $points, int $max): array
    {
        $n = count($points);
        if ($n <= $max) return $points;
        $bucket = (int)ceil($n / $max);
        $out = [];
        for ($i = 0; $i < $n; $i += $bucket) {
            $slice = array_slice($points, $i, $bucket);
            $c = count($slice);
            $out[] = [$slice[0][0], array_sum(array_column($slice, 1)) / $c, array_sum(array_column($slice, 2)) / $c];
        }
        return $out;
    }

    /** Serveurs DNS distribués par le DHCP de la Freebox. */
    public function dhcpDns(): array
    {
        $cfg = (array)$this->fbx->get('dhcp/config/');
        return array_values(array_filter(array_map('strval', (array)($cfg['dns'] ?? [])), fn($x) => $x !== ''));
    }

    public function setDhcpDns(array $servers): array
    {
        $servers = array_values(array_filter($servers, fn($x) => filter_var($x, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)));
        if (!$servers) throw new InvalidArgumentException('Adresse de serveur DNS invalide.');
        $this->fbx->call('PUT', 'dhcp/config/', ['dns' => array_slice(array_pad($servers, 5, ''), 0, 5)]);
        $now = $this->dhcpDns();
        if (($now[0] ?? '') !== $servers[0]) {
            throw new FreeboxError('La Freebox n\'a pas enregistré le serveur DNS.', 'not_applied', 'Faites-le dans Freebox OS › Paramètres de la Freebox › DHCP.');
        }
        Db::event('dhcp', '', 'DNS distribué par la Freebox : ' . implode(', ', $servers));
        return ['ok' => true, 'dns' => $now];
    }

    public function reboot(): array
    {
        $this->fbx->call('POST', 'system/reboot/');
        return ['ok' => true];
    }
}
