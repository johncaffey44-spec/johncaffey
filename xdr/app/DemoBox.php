<?php
/*
 * Sentinelle XDR — Freebox simulée (mode démonstration).
 *
 * Elle répond aux mêmes adresses et avec les mêmes structures que l'API Freebox OS, si bien que
 * tout le reste de Sentinelle (normalisation, détections, actions) fonctionne à l'identique en
 * démonstration et sur une vraie box. L'état modifiable (profils, filtrage Wi-Fi, redirections…)
 * est conservé dans data/demo-state.json. Un incident est scénarisé : un module Wi-Fi inconnu
 * (Espressif) apparaît, ouvre un port telnet par UPnP, usurpe une adresse IP et envoie des rafales
 * de données vers Internet.
 */
declare(strict_types=1);

final class DemoBoxError extends RuntimeException
{
    public function __construct(public string $errCode, string $message)
    {
        parent::__construct($message);
    }
}

final class DemoBox implements Transport
{
    public const APP_TOKEN = 'demo-sentinelle-0a1b2c3d4e5f60718293a4b5c6d7e8f9';
    private const PREFIX6 = '2a01:e0a:2b4:7c10';

    /** Appareils simulés. ap : 0 = 2,4 GHz, 1 = 5 GHz, 2 = 6 GHz ; port : prise Ethernet de la box. */
    public const DEVICES = [
        ['mac' => '5A:1C:3E:92:7B:10', 'name' => 'iPhone de Léa', 'dhcp' => 'iPhone-de-Lea', 'type' => 'smartphone', 'vendor' => '', 'conn' => 'wifi', 'ap' => 1, 'ip' => 23, 'kind' => 'phone', 'sig' => -58],
        ['mac' => 'D0:C2:4E:11:A8:42', 'name' => 'Galaxy S24', 'dhcp' => 'Galaxy-S24', 'type' => 'smartphone', 'vendor' => 'Samsung Electronics Co.,Ltd', 'conn' => 'wifi', 'ap' => 2, 'ip' => 24, 'kind' => 'phone', 'sig' => -49],
        ['mac' => '3C:22:FB:6D:01:9E', 'name' => 'MacBook Air', 'dhcp' => 'MacBook-Air', 'type' => 'laptop', 'vendor' => 'Apple, Inc.', 'conn' => 'wifi', 'ap' => 1, 'ip' => 25, 'kind' => 'laptop', 'sig' => -55],
        ['mac' => 'A4:83:E7:2F:90:6C', 'name' => 'iPad', 'dhcp' => 'iPad', 'type' => 'tablet', 'vendor' => 'Apple, Inc.', 'conn' => 'wifi', 'ap' => 1, 'ip' => 26, 'kind' => 'tablet', 'sig' => -60, 'offline' => true],
        ['mac' => 'D8:BB:C1:4A:77:03', 'name' => 'PC-GAMER', 'dhcp' => 'PC-GAMER', 'type' => 'workstation', 'vendor' => 'Micro-Star INTL CO., LTD.', 'conn' => 'eth', 'port' => 1, 'ip' => 30, 'kind' => 'pc'],
        ['mac' => '34:27:92:5C:10:B1', 'name' => 'Player TV Free 4K', 'dhcp' => 'Freebox-Player', 'type' => 'freebox_player', 'vendor' => 'Freebox SAS', 'conn' => 'eth', 'port' => 2, 'ip' => 31, 'kind' => 'player'],
        ['mac' => '8C:79:F5:21:C4:6A', 'name' => 'TV Salon', 'dhcp' => 'Samsung-TV', 'type' => 'television', 'vendor' => 'Samsung Electronics Co.,Ltd', 'conn' => 'wifi', 'ap' => 1, 'ip' => 32, 'kind' => 'tv', 'sig' => -61],
        ['mac' => 'BC:33:29:7E:5A:F2', 'name' => 'PS5', 'dhcp' => 'PS5-123', 'type' => 'vg_console', 'vendor' => 'Sony Interactive Entertainment Inc.', 'conn' => 'eth', 'port' => 3, 'ip' => 33, 'kind' => 'console'],
        ['mac' => '98:41:5C:3B:2D:07', 'name' => 'Nintendo Switch', 'dhcp' => 'Nintendo-Switch', 'type' => 'vg_console', 'vendor' => 'Nintendo Co.,Ltd', 'conn' => 'wifi', 'ap' => 0, 'ip' => 34, 'kind' => 'handheld', 'sig' => -66],
        ['mac' => 'C0:6D:ED:8A:12:5B', 'name' => 'Caméra entrée', 'dhcp' => 'EZVIZ-C6N', 'type' => 'ip_camera', 'vendor' => 'Hangzhou Ezviz Software Co.,Ltd.', 'conn' => 'wifi', 'ap' => 0, 'ip' => 40, 'kind' => 'camera', 'sig' => -71],
        ['mac' => 'F8:0D:AC:63:9E:21', 'name' => 'Imprimante HP', 'dhcp' => 'HP-ENVY-6400', 'type' => 'printer', 'vendor' => 'HP Inc.', 'conn' => 'wifi', 'ap' => 0, 'ip' => 41, 'kind' => 'printer', 'sig' => -63],
        ['mac' => '44:65:0D:9F:3A:88', 'name' => 'Echo Dot cuisine', 'dhcp' => 'amazon-echo', 'type' => 'multimedia_device', 'vendor' => 'Amazon Technologies Inc.', 'conn' => 'wifi', 'ap' => 0, 'ip' => 42, 'kind' => 'speaker', 'sig' => -60],
        ['mac' => 'B0:4E:26:77:C3:19', 'name' => 'Prise connectée', 'dhcp' => 'Tapo_P110', 'type' => 'other', 'vendor' => 'TP-LINK TECHNOLOGIES CO.,LTD.', 'conn' => 'wifi', 'ap' => 0, 'ip' => 43, 'kind' => 'iot', 'sig' => -69],
        ['mac' => '3C:71:BF:E4:2A:90', 'name' => 'ESP-E42A90', 'dhcp' => 'ESP-E42A90', 'type' => 'other', 'vendor' => 'Espressif Inc.', 'conn' => 'wifi', 'ap' => 0, 'ip' => 47, 'kind' => 'rogue', 'sig' => -74, 'new' => true],
        ['mac' => '00:11:32:A7:5E:14', 'name' => 'NAS Synology', 'dhcp' => 'DS920', 'type' => 'nas', 'vendor' => 'Synology Incorporated', 'conn' => 'eth', 'port' => 4, 'ip' => 50, 'kind' => 'nas'],
        ['mac' => 'B4:A9:FC:05:6B:3D', 'name' => 'SERVEUR-XDR', 'dhcp' => 'SERVEUR-XDR', 'type' => 'workstation', 'vendor' => 'Acer Incorporated', 'conn' => 'eth', 'port' => 4, 'ip' => 51, 'kind' => 'server'],
        ['mac' => '6E:2B:91:0C:D4:5A', 'name' => 'Téléphone invité', 'dhcp' => 'Pixel-8', 'type' => 'smartphone', 'vendor' => '', 'conn' => 'wifi', 'ap' => 1, 'ip' => 60, 'kind' => 'guest', 'sig' => -57, 'iface' => 'wifiguest'],
    ];

    private const AP = [
        0 => ['name' => '2.4G', 'band' => '2d4g', 'width' => 40, 'channel' => 6, 'phy' => 287],
        1 => ['name' => '5G', 'band' => '5g', 'width' => 160, 'channel' => 36, 'phy' => 2402],
        2 => ['name' => '6G', 'band' => '6g', 'width' => 320, 'channel' => 37, 'phy' => 4804],
    ];

    private array $st;
    private bool $dirty = false;
    private int $now;

    public function __construct()
    {
        $this->now = time();
        $this->st = read_json(data_path('demo-state.json'));
        if (($this->st['v'] ?? 0) !== 2) {
            $this->st = self::initialState($this->now);
            $this->dirty = true;
            $this->persist();
        }
    }

    public static function reset(): void
    {
        @unlink(data_path('demo-state.json'));
        @unlink(data_path('demo-link.json'));
        @unlink(data_path('cache/demo-session.json'));
    }

    private static function initialState(int $now): array
    {
        return [
            'v' => 2, 'boot' => $now - 1_052_380, 'created' => $now,
            'names' => [], 'types' => [],
            'profiles' => [
                ['id' => 1, 'name' => 'Enfants', 'icon' => '/resources/images/profile/profile_03.png'],
                ['id' => 2, 'name' => 'Parents', 'icon' => '/resources/images/profile/profile_07.png'],
            ],
            'netctl' => [
                '1' => ['macs' => ['5A:1C:3E:92:7B:10', '98:41:5C:3B:2D:07', 'A4:83:E7:2F:90:6C'], 'override' => false, 'override_mode' => 'allowed', 'override_until' => 0, 'cdayranges' => [], 'native' => ['21:30', '07:00']],
                '2' => ['macs' => ['D0:C2:4E:11:A8:42', '3C:22:FB:6D:01:9E'], 'override' => false, 'override_mode' => 'allowed', 'override_until' => 0, 'cdayranges' => [], 'native' => null],
            ],
            'wifi' => ['enabled' => true, 'power_saving' => false, 'mac_filter_state' => 'disabled'],
            'macfilter' => [],
            'redirs' => [
                ['id' => 1, 'enabled' => true, 'ip_proto' => 'tcp', 'wan_port_start' => 3389, 'wan_port_end' => 3389, 'lan_ip' => '192.168.1.30', 'lan_port' => 3389, 'src_ip' => '0.0.0.0', 'comment' => 'Bureau à distance'],
                ['id' => 2, 'enabled' => true, 'ip_proto' => 'tcp', 'wan_port_start' => 5001, 'wan_port_end' => 5001, 'lan_ip' => '192.168.1.50', 'lan_port' => 5001, 'src_ip' => '0.0.0.0', 'comment' => 'NAS Synology (DSM)'],
                ['id' => 3, 'enabled' => false, 'ip_proto' => 'udp', 'wan_port_start' => 51820, 'wan_port_end' => 51820, 'lan_ip' => '192.168.1.51', 'lan_port' => 51820, 'src_ip' => '0.0.0.0', 'comment' => 'WireGuard (essai)'],
            ],
            'upnp' => ['enabled' => true, 'version' => 2],
            'upnp_redirs' => [
                ['id' => 'udp-3074', 'enabled' => true, 'proto' => 'udp', 'ext_port' => 3074, 'int_ip' => '192.168.1.33', 'int_port' => 3074, 'desc' => 'PS5 PSN', 'remote_host' => ''],
                ['id' => 'tcp-8000', 'enabled' => true, 'proto' => 'tcp', 'ext_port' => 8000, 'int_ip' => '192.168.1.40', 'int_port' => 8000, 'desc' => 'EZVIZ remote view', 'remote_host' => ''],
                ['id' => 'tcp-2323', 'enabled' => true, 'proto' => 'tcp', 'ext_port' => 2323, 'int_ip' => '192.168.1.47', 'int_port' => 23, 'desc' => 'miniupnpd', 'remote_host' => ''],
            ],
            'dmz' => ['enabled' => false, 'ip' => ''],
            'conn_cfg' => ['ping' => true, 'is_secure_pass' => true, 'remote_access' => true, 'remote_access_port' => 34567,
                'remote_access_min_port' => 1, 'remote_access_max_port' => 65535, 'remote_access_ip' => '82.64.123.45',
                'api_remote_access' => true, 'wol' => false, 'adblock' => false, 'adblock_not_set' => false,
                'allow_token_request' => true, 'sip_alg' => 'disabled'],
            'ipv6_cfg' => ['ipv6_enabled' => true, 'ipv6_firewall' => false, 'ipv6ll' => 'fe80::3627:92ff:fe5c:1',
                'delegations' => [['prefix' => self::PREFIX6 . '::/64', 'next_hop' => '']]],
            'ftp' => ['enabled' => false, 'allow_anonymous' => false, 'allow_anonymous_write' => false, 'allow_remote_access' => false,
                'port_ctrl' => 21, 'port_data' => 50000, 'remote_domain' => ''],
            'samba' => ['file_share_enabled' => true, 'print_share_enabled' => true, 'logon_enabled' => true, 'logon_user' => 'freebox',
                'workgroup' => 'WORKGROUP', 'smbv2_enabled' => true],
            'volume' => ['volume' => 28, 'mute' => false],
            'dns_denied' => [],
            'challenges' => [],
            'sessions' => [],
            'auth_polls' => 0,
        ];
    }

    private function persist(): void
    {
        if ($this->dirty) {
            write_json(data_path('demo-state.json'), $this->st);
            $this->dirty = false;
        }
    }

    public function state(string $key): mixed
    {
        return $this->st[$key] ?? null;
    }

    public function setState(string $key, mixed $value): void
    {
        $this->st[$key] = $value;
        $this->dirty = true;
        $this->persist();
    }

    /* ------------------------------------------------------------------ transport */

    public function send(string $method, string $url, ?string $body, array $headers): array
    {
        $path = rawurldecode((string)(parse_url($url, PHP_URL_PATH) ?? '/'));
        if (rtrim($path, '/') === '/api_version') {
            return ['status' => 200, 'body' => json_encode([
                'uid' => 'f1a2b3c4d5e6f708', 'device_name' => 'Freebox Server', 'api_version' => '16.0', 'api_base_url' => '/api/',
                'device_type' => 'FreeboxServer9,1', 'api_domain' => 'demo-sentinelle.fbxos.fr', 'https_available' => true,
                'https_port' => 443, 'box_model' => 'fbxgw9-r1', 'box_model_name' => 'Freebox Ultra',
            ])];
        }
        if (!preg_match('~^/api/v(\d+)/(.*)$~', $path, $m)) return $this->err('invalid_request', 'Chemin inconnu', 404);
        $p = trim($m[2], '/');
        $in = ($body !== null && $body !== '') ? json_decode($body, true) : [];
        if (!str_starts_with($p, 'login')) {
            $tok = '';
            foreach ($headers as $h) {
                if (stripos($h, 'X-Fbx-App-Auth:') === 0) $tok = trim(substr($h, 15));
            }
            $exp = (int)($this->st['sessions'][$tok] ?? 0);
            if ($tok === '' || $exp < $this->now) return $this->err('auth_required', 'Vous devez vous authentifier pour accéder à cette API', 403);
            if ($exp < $this->now + 900) {
                $this->st['sessions'][$tok] = $this->now + 1800;
                $this->dirty = true;
            }
        }
        try {
            $res = $this->route($method, $p, is_array($in) ? $in : []);
        } catch (DemoBoxError $e) {
            $this->persist();
            return $this->err($e->errCode, $e->getMessage());
        }
        $this->persist();
        return ['status' => 200, 'body' => json_encode($res === null ? ['success' => true] : ['success' => true, 'result' => $res], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
    }

    private function err(string $code, string $msg, int $status = 200): array
    {
        return ['status' => $status, 'body' => json_encode(['success' => false, 'error_code' => $code, 'msg' => $msg], JSON_UNESCAPED_UNICODE)];
    }

    /* ------------------------------------------------------------------ routes */

    private function route(string $method, string $p, array $in): mixed
    {
        $r = "$method $p";
        $m = [];
        switch (true) {
            /* --- authentification --- */
            case $r === 'GET login':
                $c = bin2hex(random_bytes(16));
                $this->st['challenges'] = array_slice(array_merge([$c], $this->st['challenges']), 0, 20);
                $this->dirty = true;
                return ['logged_in' => false, 'challenge' => $c, 'password_salt' => 'demo'];
            case $r === 'POST login/session':
                foreach ($this->st['challenges'] as $c) {
                    if (hash_equals(hash_hmac('sha1', $c, self::APP_TOKEN), (string)($in['password'] ?? ''))) {
                        $tok = bin2hex(random_bytes(24));
                        $this->st['sessions'] = array_filter($this->st['sessions'], fn($e) => $e > $this->now);
                        $this->st['sessions'][$tok] = $this->now + 1800;
                        $this->dirty = true;
                        return ['session_token' => $tok, 'challenge' => $c, 'permissions' => [
                            'settings' => true, 'parental' => true, 'profile' => true, 'player' => true, 'contacts' => false,
                            'calls' => false, 'explorer' => false, 'downloader' => false, 'tv' => false, 'pvr' => false, 'camera' => false, 'home' => false,
                        ]];
                    }
                }
                throw new DemoBoxError('invalid_token', 'Jeton d\'application invalide');
            case $r === 'POST login/authorize':
                $this->st['auth_polls'] = 0;
                $this->dirty = true;
                return ['app_token' => self::APP_TOKEN, 'track_id' => 42];
            case (bool)preg_match('~^GET login/authorize/(\d+)$~', $r, $m):
                $this->st['auth_polls']++;
                $this->dirty = true;
                return ['status' => $this->st['auth_polls'] < 3 ? 'pending' : 'granted', 'challenge' => bin2hex(random_bytes(8))];
            case $r === 'POST login/logout':
                return null;

            /* --- réseau local --- */
            case $r === 'GET lan/config':
                return ['ip' => '192.168.1.254', 'name' => 'Freebox Server', 'name_dns' => 'freebox-server', 'name_mdns' => 'Freebox-Server',
                    'name_netbios' => 'Freebox_Server', 'mode' => 'router'];
            case $r === 'GET lan/browser/interfaces':
                $pub = count(array_filter(self::DEVICES, fn($d) => ($d['iface'] ?? 'pub') === 'pub'));
                return [['name' => 'pub', 'host_count' => $pub], ['name' => 'wifiguest', 'host_count' => count(self::DEVICES) - $pub]];
            case (bool)preg_match('~^GET lan/browser/(pub|wifiguest)$~', $r, $m):
                return array_values(array_map(fn($d) => $this->host($d), array_filter(self::DEVICES, fn($d) => ($d['iface'] ?? 'pub') === $m[1])));
            case (bool)preg_match('~^GET lan/browser/(pub|wifiguest)/ether-([0-9a-f:]{17})$~', $r, $m):
                return $this->host($this->device($m[2]));
            case (bool)preg_match('~^PUT lan/browser/(pub|wifiguest)/ether-([0-9a-f:]{17})$~', $r, $m):
                $d = $this->device($m[2]);
                if (isset($in['primary_name']) && trim((string)$in['primary_name']) !== '') $this->st['names'][$d['mac']] = mb_substr(trim((string)$in['primary_name']), 0, 60);
                if (isset($in['host_type']) && is_string($in['host_type'])) $this->st['types'][$d['mac']] = $in['host_type'];
                $this->dirty = true;
                return $this->host($d);
            case (bool)preg_match('~^POST lan/wol/(pub|wifiguest)$~', $r, $m):
                if (mac_norm($in['mac'] ?? '') === '') throw new DemoBoxError('invalid_request', 'Adresse MAC invalide');
                return null;
            case $r === 'GET dhcp/config':
                return ['enabled' => true, 'sticky_assign' => true, 'gateway' => '192.168.1.254', 'netmask' => '255.255.255.0',
                    'ip_range_start' => '192.168.1.10', 'ip_range_end' => '192.168.1.99', 'always_broadcast' => false,
                    'dns' => ['192.168.1.254', '', '', '', '']];
            case $r === 'GET dhcp/static_lease':
                return [
                    ['id' => '00:11:32:a7:5e:14', 'mac' => '00:11:32:A7:5E:14', 'ip' => '192.168.1.50', 'comment' => 'NAS', 'hostname' => 'DS920'],
                    ['id' => 'b4:a9:fc:05:6b:3d', 'mac' => 'B4:A9:FC:05:6B:3D', 'ip' => '192.168.1.51', 'comment' => 'Serveur Sentinelle', 'hostname' => 'SERVEUR-XDR'],
                ];

            /* --- connexion Internet --- */
            case $r === 'GET connection':
                [$down, $up] = $this->totals($this->now);
                $age = $this->now - $this->st['boot'];
                return ['type' => 'ethernet', 'media' => 'ftth', 'state' => 'up', 'ipv4' => '82.64.123.45',
                    'ipv6' => self::PREFIX6 . '::1', 'rate_down' => (int)$down, 'rate_up' => (int)$up,
                    'bandwidth_down' => 8_000_000_000, 'bandwidth_up' => 8_000_000_000,
                    'bytes_down' => (int)($age * 1_150_000), 'bytes_up' => (int)($age * 260_000), 'ipv4_port_range' => [0, 65535]];
            case $r === 'GET connection/config':
                return $this->st['conn_cfg'];
            case $r === 'PUT connection/config':
                foreach (['ping', 'remote_access', 'api_remote_access', 'wol', 'adblock', 'allow_token_request'] as $k) {
                    if (array_key_exists($k, $in)) $this->st['conn_cfg'][$k] = (bool)$in[$k];
                }
                $this->dirty = true;
                return $this->st['conn_cfg'];
            case $r === 'GET connection/ipv6/config':
                return $this->st['ipv6_cfg'];
            case $r === 'PUT connection/ipv6/config':
                foreach (['ipv6_enabled', 'ipv6_firewall'] as $k) {
                    if (array_key_exists($k, $in)) $this->st['ipv6_cfg'][$k] = (bool)$in[$k];
                }
                $this->dirty = true;
                return $this->st['ipv6_cfg'];
            case $r === 'GET connection/ftth':
                return ['sfp_present' => true, 'sfp_alim_ok' => true, 'sfp_has_power_report' => true, 'sfp_has_signal' => true,
                    'link' => true, 'sfp_pwr_rx' => -1730 + (int)(self::noise('rx', intdiv($this->now, 30)) * 40), 'sfp_pwr_tx' => 290,
                    'sfp_vendor' => 'FREEBOX', 'sfp_model' => 'F-MDCONU5', 'sfp_serial' => 'FBX5U0123456'];
            case $r === 'POST rrd':
                return $this->rrd($in);

            /* --- système --- */
            case $r === 'GET system':
                $age = $this->now - $this->st['boot'];
                $t = intdiv($this->now, 20);
                return ['firmware_version' => '4.13.7', 'mac' => '34:27:92:5C:00:01', 'serial' => '821601T123456789',
                    'uptime' => self::human($age), 'uptime_val' => $age, 'board_name' => 'fbxgw9r', 'disk_status' => 'active',
                    'user_main_storage' => 'Disque interne',
                    'model_info' => ['name' => 'fbxgw9-r1', 'pretty_name' => 'Freebox Ultra', 'wifi_type' => '2d4_5g_6g', 'has_dect' => true,
                        'has_home_automation' => true, 'has_vm' => true, 'has_ext_telephony' => true, 'internal_hdd_size' => 0],
                    'sensors' => [
                        ['id' => 'temp_cpu', 'name' => 'Température processeur', 'value' => 56 + (int)(self::noise('cpu', $t) * 6)],
                        ['id' => 'temp_board', 'name' => 'Température carte', 'value' => 44 + (int)(self::noise('brd', $t) * 3)],
                        ['id' => 'temp_wifi', 'name' => 'Température Wi-Fi', 'value' => 61 + (int)(self::noise('wf', $t) * 4)],
                    ],
                    'fans' => [['id' => 'fan0_speed', 'name' => 'Ventilateur', 'value' => 1400 + (int)(self::noise('fan', $t) * 160)]]];
            case $r === 'POST system/reboot':
                return null;

            /* --- profils & contrôle d'accès --- */
            case $r === 'GET profile':
                return $this->st['profiles'];
            case $r === 'POST profile':
                $name = trim((string)($in['name'] ?? ''));
                if ($name === '') throw new DemoBoxError('invalid_request', 'Nom de profil manquant');
                $id = max(array_column($this->st['profiles'], 'id') ?: [0]) + 1;
                $prof = ['id' => $id, 'name' => mb_substr($name, 0, 40), 'icon' => (string)($in['icon'] ?? '/resources/images/profile/profile_01.png')];
                $this->st['profiles'][] = $prof;
                $this->st['netctl'][(string)$id] = ['macs' => [], 'override' => false, 'override_mode' => 'allowed', 'override_until' => 0, 'cdayranges' => [], 'native' => null];
                $this->dirty = true;
                return $prof;
            case (bool)preg_match('~^DELETE profile/(\d+)$~', $r, $m):
                $this->st['profiles'] = array_values(array_filter($this->st['profiles'], fn($pr) => (int)$pr['id'] !== (int)$m[1]));
                unset($this->st['netctl'][$m[1]]);
                $this->dirty = true;
                return null;
            case $r === 'GET network_control':
                return array_map(fn($id) => $this->netctl((int)$id), array_keys($this->st['netctl']));
            case (bool)preg_match('~^GET network_control/(\d+)$~', $r, $m):
                if (!isset($this->st['netctl'][$m[1]])) throw new DemoBoxError('noent', 'Profil inconnu');
                return $this->netctl((int)$m[1]);
            case (bool)preg_match('~^PUT network_control/(\d+)$~', $r, $m):
                if (!isset($this->st['netctl'][$m[1]])) throw new DemoBoxError('noent', 'Profil inconnu');
                if (!isset($in['macs']) || !is_array($in['macs'])) throw new DemoBoxError('invalid_request', 'Liste d\'adresses MAC manquante');
                $nc = &$this->st['netctl'][$m[1]];
                $macs = array_values(array_unique(array_filter(array_map('mac_norm', $in['macs']))));
                foreach ($this->st['netctl'] as $pid => &$other) {
                    if ((string)$pid !== $m[1]) $other['macs'] = array_values(array_diff($other['macs'], $macs));
                }
                unset($other);
                $nc['macs'] = $macs;
                if (isset($in['cdayranges']) && is_array($in['cdayranges'])) $nc['cdayranges'] = $in['cdayranges'];
                if (array_key_exists('override', $in)) $nc['override'] = (bool)$in['override'];
                if (isset($in['override_mode']) && in_array($in['override_mode'], ['allowed', 'denied', 'webonly'], true)) $nc['override_mode'] = $in['override_mode'];
                if (array_key_exists('override_until', $in)) $nc['override_until'] = max(0, (int)$in['override_until']);
                unset($nc);
                $this->dirty = true;
                return $this->netctl((int)$m[1]);

            /* --- Wi-Fi --- */
            case $r === 'GET wifi/config':
                return $this->st['wifi'];
            case $r === 'PUT wifi/config':
                if (isset($in['mac_filter_state']) && in_array($in['mac_filter_state'], ['disabled', 'whitelist', 'blacklist'], true)) $this->st['wifi']['mac_filter_state'] = $in['mac_filter_state'];
                if (array_key_exists('enabled', $in)) $this->st['wifi']['enabled'] = (bool)$in['enabled'];
                $this->dirty = true;
                return $this->st['wifi'];
            case $r === 'GET wifi/ap':
                return array_map(fn($id, $a) => ['id' => $id, 'name' => $a['name'], 'status' => 'active',
                    'config' => ['band' => $a['band'], 'channel_width' => $a['width'], 'primary_channel' => $a['channel'], 'secondary_channel' => 0, 'dfs_enabled' => $id === 1]],
                    array_keys(self::AP), self::AP);
            case (bool)preg_match('~^GET wifi/ap/(\d+)/stations$~', $r, $m):
                return $this->stations((int)$m[1]);
            case $r === 'GET wifi/bss':
                $enc = [0 => 'wpa2_psk_ccmp', 1 => 'wpa23_psk_ccmp', 2 => 'wpa3_psk_ccmp'];
                return array_map(fn($id) => ['id' => sprintf('34:27:92:5c:00:%02x', 0x10 + $id), 'phy_id' => $id,
                    'status' => ['state' => 'active', 'sta_count' => count($this->stations($id)), 'authorized_sta_count' => count($this->stations($id)), 'is_main_bss' => true],
                    'config' => ['enabled' => true, 'use_default_config' => true, 'ssid' => 'Freebox-5C1A2B', 'hide_ssid' => false,
                        'encryption' => $enc[$id], 'key' => 'mot-de-passe-wifi-demo', 'eapol_version' => 2, 'wps_enabled' => $id === 0]],
                    array_keys(self::AP));
            case $r === 'GET wifi/mac_filter':
                return array_values($this->st['macfilter']);
            case $r === 'POST wifi/mac_filter':
                $mac = mac_norm($in['mac'] ?? '');
                $type = in_array($in['type'] ?? '', ['whitelist', 'blacklist'], true) ? $in['type'] : '';
                if ($mac === '' || $type === '') throw new DemoBoxError('invalid_request', 'Adresse MAC ou type invalide');
                $id = $mac . '-' . $type;
                $this->st['macfilter'][$id] = ['id' => $id, 'mac' => $mac, 'type' => $type, 'comment' => mb_substr((string)($in['comment'] ?? ''), 0, 60), 'hostname' => $this->nameOf($mac)];
                $this->dirty = true;
                return $this->st['macfilter'][$id];
            case (bool)preg_match('~^DELETE wifi/mac_filter/(.+)$~', $r, $m):
                $id = rawurldecode($m[1]);
                if (!isset($this->st['macfilter'][$id])) throw new DemoBoxError('noent', 'Filtre introuvable');
                unset($this->st['macfilter'][$id]);
                $this->dirty = true;
                return null;

            /* --- commutateur Ethernet --- */
            case $r === 'GET switch/status':
                $out = [];
                for ($port = 1; $port <= 4; $port++) {
                    $macs = array_values(array_filter(self::DEVICES, fn($d) => ($d['port'] ?? 0) === $port && $this->online($d)));
                    $out[] = ['id' => $port, 'name' => 'Ethernet ' . $port, 'link' => $macs ? 'up' : 'down', 'speed' => $macs ? '2500' : '',
                        'duplex' => $macs ? 'full' : '', 'mode' => $macs ? '2500BaseT-FD' : '',
                        'mac_list' => array_map(fn($d) => ['mac' => $d['mac'], 'hostname' => $this->nameOf($d['mac'])], $macs)];
                }
                return $out;
            case (bool)preg_match('~^GET switch/port/(\d+)/stats$~', $r, $m):
                $down = $up = 0.0;
                foreach (self::DEVICES as $d) {
                    if (($d['port'] ?? 0) !== (int)$m[1] || !$this->online($d)) continue;
                    [$a, $b] = $this->devRates($d, $this->now);
                    $down += $a;
                    $up += $b;
                }
                return ['tx_bytes_rate' => (int)$down, 'rx_bytes_rate' => (int)$up, 'tx_packets_rate' => (int)($down / 1200),
                    'rx_packets_rate' => (int)($up / 900), 'rx_err_packets' => 0, 'rx_fcs_packets' => 0, 'tx_collisions' => 0];

            /* --- exposition : redirections, DMZ, UPnP, FTP, partages --- */
            case $r === 'GET fw/redir':
                return array_map(fn($x) => $x + ['hostname' => $this->nameByIp($x['lan_ip'])], $this->st['redirs']);
            case (bool)preg_match('~^PUT fw/redir/(\d+)$~', $r, $m):
                foreach ($this->st['redirs'] as &$x) {
                    if ($x['id'] === (int)$m[1]) {
                        if (array_key_exists('enabled', $in)) $x['enabled'] = (bool)$in['enabled'];
                        $this->dirty = true;
                        $res = $x;
                    }
                }
                unset($x);
                if (!isset($res)) throw new DemoBoxError('noent', 'Redirection introuvable');
                return $res;
            case (bool)preg_match('~^DELETE fw/redir/(\d+)$~', $r, $m):
                $this->st['redirs'] = array_values(array_filter($this->st['redirs'], fn($x) => $x['id'] !== (int)$m[1]));
                $this->dirty = true;
                return null;
            case $r === 'GET fw/dmz':
                return $this->st['dmz'];
            case $r === 'PUT fw/dmz':
                if (array_key_exists('enabled', $in)) $this->st['dmz']['enabled'] = (bool)$in['enabled'];
                $this->dirty = true;
                return $this->st['dmz'];
            case $r === 'GET upnpigd/config':
                return $this->st['upnp'];
            case $r === 'PUT upnpigd/config':
                if (array_key_exists('enabled', $in)) $this->st['upnp']['enabled'] = (bool)$in['enabled'];
                if (!$this->st['upnp']['enabled']) $this->st['upnp_redirs'] = [];
                $this->dirty = true;
                return $this->st['upnp'];
            case $r === 'GET upnpigd/redir':
                return $this->st['upnp']['enabled'] ? $this->st['upnp_redirs'] : [];
            case (bool)preg_match('~^DELETE upnpigd/redir/(.+)$~', $r, $m):
                $id = rawurldecode($m[1]);
                $this->st['upnp_redirs'] = array_values(array_filter($this->st['upnp_redirs'], fn($x) => $x['id'] !== $id));
                $this->dirty = true;
                return null;
            case $r === 'GET ftp/config':
                return $this->st['ftp'];
            case $r === 'PUT ftp/config':
                foreach (['enabled', 'allow_anonymous', 'allow_anonymous_write', 'allow_remote_access'] as $k) {
                    if (array_key_exists($k, $in)) $this->st['ftp'][$k] = (bool)$in[$k];
                }
                $this->dirty = true;
                return $this->st['ftp'];
            case $r === 'GET netshare/samba':
                return $this->st['samba'];

            /* --- Player TV --- */
            case $r === 'GET player':
                return [['id' => 1, 'device_name' => 'Player TV Free 4K', 'device_model' => 'Player TV Free 4K', 'api_version' => '14.0',
                    'api_available' => true, 'reachable' => true, 'uid' => '7a3c19e0b2d84f61', 'mac' => '34:27:92:5C:10:B1',
                    'stb_type' => 'stb_android', 'last_time_reachable' => $this->now]];
            case (bool)preg_match('~^GET player/(\d+)/api/v\d+/status$~', $r, $m):
                return $this->playerStatus();
            case (bool)preg_match('~^GET player/(\d+)/api/v\d+/control/volume$~', $r, $m):
                return $this->st['volume'];
        }
        throw new DemoBoxError('invalid_request', "Point d'API inconnu de la démonstration : $r");
    }

    /* ------------------------------------------------------------------ modèles */

    private function device(string $mac): array
    {
        $mac = mac_norm($mac);
        foreach (self::DEVICES as $d) {
            if ($d['mac'] === $mac) return $d;
        }
        throw new DemoBoxError('noent', 'Appareil inconnu');
    }

    private function nameOf(string $mac): string
    {
        foreach (self::DEVICES as $d) {
            if ($d['mac'] === mac_norm($mac)) return $this->st['names'][$d['mac']] ?? $d['name'];
        }
        return '';
    }

    private function nameByIp(string $ip): string
    {
        foreach (self::DEVICES as $d) {
            if ('192.168.1.' . $d['ip'] === $ip) return $this->nameOf($d['mac']);
        }
        return '';
    }

    public function online(array $d): bool
    {
        if (!empty($d['offline'])) return false;
        if ($d['conn'] === 'wifi') {
            if (!$this->st['wifi']['enabled']) return false;
            $state = $this->st['wifi']['mac_filter_state'];
            $listed = false;
            foreach ($this->st['macfilter'] as $e) {
                if ($e['mac'] === $d['mac'] && $e['type'] === $state) $listed = true;
            }
            if ($state === 'blacklist' && $listed) return false;
            if ($state === 'whitelist' && !$listed) return false;
        }
        return true;
    }

    /** Résumé du profil d'un appareil (sans la liste des hôtes, pour éviter les boucles). */
    private function profileOf(string $mac): ?array
    {
        foreach ($this->st['netctl'] as $id => $nc) {
            if (in_array($mac, $nc['macs'], true)) {
                $name = '';
                foreach ($this->st['profiles'] as $pr) {
                    if ((int)$pr['id'] === (int)$id) $name = $pr['name'];
                }
                return ['profile_id' => (int)$id, 'name' => $name, 'current_mode' => $this->mode((int)$id)];
            }
        }
        return null;
    }

    private function mode(int $id): string
    {
        $nc = &$this->st['netctl'][(string)$id];
        if ($nc['override'] && $nc['override_until'] > 0 && $nc['override_until'] <= $this->now) {
            $nc['override'] = false;
            $nc['override_until'] = 0;
            $this->dirty = true;
        }
        if ($nc['override']) return $nc['override_mode'];
        return ($nc['native'] && self::inWindow($nc['native'][0], $nc['native'][1], $this->now)) ? 'denied' : 'allowed';
    }

    private function netctl(int $id): array
    {
        $mode = $this->mode($id);
        $nc = $this->st['netctl'][(string)$id];
        $next = 0;
        if ($nc['override'] && $nc['override_until'] > 0) {
            $next = $nc['override_until'];
        } elseif ($nc['native']) {
            $next = self::nextBoundary($nc['native'], $this->now);
        }
        $hosts = [];
        foreach (self::DEVICES as $d) {
            if (in_array($d['mac'], $nc['macs'], true)) $hosts[] = $this->host($d);
        }
        return ['profile_id' => $id, 'macs' => $nc['macs'], 'hosts' => $hosts, 'override' => $nc['override'],
            'override_mode' => $nc['override_mode'], 'override_until' => $nc['override_until'], 'current_mode' => $mode,
            'rule_mode' => 'allowed', 'next_change' => $next, 'cdayranges' => $nc['cdayranges']];
    }

    private function host(array $d): array
    {
        $mac = $d['mac'];
        $online = $this->online($d);
        $jitter = (int)(self::noise($mac, intdiv($this->now, 7)) * 25);
        $last = $online ? $this->now - $jitter : $this->now - 93_000;
        $first = !empty($d['new']) ? $this->st['created'] - 1_260 : $this->st['boot'] - 86_400 * (30 + crc32($mac) % 300);
        $names = [['name' => $d['dhcp'], 'source' => 'dhcp']];
        if (str_contains($d['vendor'], 'Apple') || $d['type'] === 'smartphone') $names[] = ['name' => $d['dhcp'] . '.local', 'source' => 'mdns'];
        if ($d['type'] === 'workstation') $names[] = ['name' => $d['dhcp'], 'source' => 'netbios'];
        $l3 = [['addr' => '192.168.1.' . $d['ip'], 'af' => 'ipv4', 'active' => $online, 'reachable' => $online,
            'last_activity' => $last, 'last_time_reachable' => $last, 'model' => '']];
        if ($d['kind'] === 'rogue') {
            // usurpation : le module revendique aussi l'adresse du téléviseur
            $l3[] = ['addr' => '192.168.1.32', 'af' => 'ipv4', 'active' => $online, 'reachable' => $online,
                'last_activity' => $last, 'last_time_reachable' => $last, 'model' => ''];
        }
        if (in_array($d['type'], ['smartphone', 'laptop', 'workstation', 'tablet', 'television', 'freebox_player', 'nas'], true)) {
            $h = substr(md5($mac), 0, 16);
            $l3[] = ['addr' => self::PREFIX6 . ':' . implode(':', str_split($h, 4)), 'af' => 'ipv6', 'active' => $online,
                'reachable' => $online, 'last_activity' => $last, 'last_time_reachable' => $last, 'model' => ''];
            $l3[] = ['addr' => 'fe80::' . implode(':', str_split(substr($h, 0, 16), 4)), 'af' => 'ipv6', 'active' => $online,
                'reachable' => $online, 'last_activity' => $last, 'last_time_reachable' => $last, 'model' => ''];
        }
        $host = [
            'id' => 'ether-' . strtolower($mac),
            'primary_name' => $this->st['names'][$mac] ?? $d['name'],
            'host_type' => $this->st['types'][$mac] ?? $d['type'],
            'primary_name_manual' => isset($this->st['names'][$mac]) || $d['name'] !== $d['dhcp'],
            'l2ident' => ['id' => $mac, 'type' => 'mac_address'],
            'vendor_name' => $d['vendor'],
            'persistent' => false,
            'reachable' => $online,
            'last_time_reachable' => $last,
            'active' => $online,
            'last_activity' => $last,
            'first_activity' => $first,
            'names' => $names,
            'l3connectivities' => $l3,
            'interface' => $d['iface'] ?? 'pub',
        ];
        $prof = $this->profileOf($mac);
        if ($prof) $host['network_control'] = $prof;
        return $host;
    }

    private function stations(int $ap): array
    {
        $out = [];
        foreach (self::DEVICES as $d) {
            if ($d['conn'] !== 'wifi' || ($d['ap'] ?? -1) !== $ap || !$this->online($d)) continue;
            [$down, $up] = $this->devRates($d, $this->now);
            $slot = intdiv($this->now, 5);
            $phy = self::AP[$ap]['phy'];
            $sig = $d['sig'] + (int)round(self::noise($d['mac'] . 's', $slot) * 6 - 3);
            $dur = 3600 + crc32($d['mac']) % 50_000 + ($this->now % 3600);
            $out[] = [
                'id' => $d['mac'] . '-' . $ap, 'mac' => $d['mac'], 'bssid' => sprintf('34:27:92:5C:00:%02X', 0x10 + $ap),
                'hostname' => $this->nameOf($d['mac']), 'host' => $this->host($d), 'state' => 'authorized',
                'inactive' => (int)(self::noise($d['mac'] . 'i', $slot) * 3), 'conn_duration' => $dur,
                'rx_bytes' => (int)($up * $dur * 0.3), 'tx_bytes' => (int)($down * $dur * 0.3),
                'rx_rate' => (int)$up, 'tx_rate' => (int)$down, 'signal' => $sig,
                'last_rx' => ['bitrate' => (int)($phy * (0.55 + 0.4 * self::noise($d['mac'] . 'r', $slot)) * 10), 'mcs' => 9, 'width' => self::AP[$ap]['width']],
                'last_tx' => ['bitrate' => (int)($phy * (0.6 + 0.4 * self::noise($d['mac'] . 't', $slot)) * 10), 'mcs' => 11, 'width' => self::AP[$ap]['width']],
            ];
        }
        return $out;
    }

    private function playerStatus(): array
    {
        $h = (int)date('G', $this->now);
        $min = (int)date('i', $this->now);
        $tv = static fn(string $name, int $num) => ['package' => 'fr.freebox.tv', 'package_id' => 28, 'cur_url' => "tv://?channel=$num",
            'context' => ['channel' => ['channelName' => $name, 'channelNumber' => $num, 'bouquetName' => 'Freebox TV',
                'channelUuid' => 'uuid-webtv-' . $num], 'player' => ['playbackState' => 'PLAYING']]];
        $app = static fn(string $pkg) => ['package' => $pkg, 'package_id' => 0, 'cur_url' => '', 'context' => ['player' => ['playbackState' => 'PLAYING']]];
        $fg = match (true) {
            $h >= 7 && $h < 9     => $tv('franceinfo:', 27),
            $h >= 12 && $h < 14   => $tv('TF1', 1),
            $h >= 17 && $h < 19   => $app('com.google.android.youtube.tv'),
            $h >= 19 && $h < 21   => $tv('France 2', 2),
            $h >= 21 && $h < 24   => $min < 30 ? $app('com.netflix.ninja') : $tv('Arte', 7),
            default               => null,
        };
        return $fg === null ? ['power_state' => 'standby'] : ['power_state' => 'running', 'foreground_app' => $fg];
    }

    private function rrd(array $in): array
    {
        $end = (int)($in['date_end'] ?? $this->now);
        $start = (int)($in['date_start'] ?? $end - 3600);
        $start = max($start, $end - 8 * 86400);
        $step = max(30, intdiv($end - $start, 150));
        $data = [];
        for ($t = $start - $start % $step; $t <= $end; $t += $step) {
            [$down, $up] = $this->totals($t);
            $data[] = ['time' => $t, 'rate_down' => (int)$down, 'rate_up' => (int)$up, 'bw_down' => 1_000_000_000, 'bw_up' => 1_000_000_000];
        }
        return ['date_start' => $start, 'date_end' => $end, 'data' => $data];
    }

    /* ------------------------------------------------------------------ débits simulés */

    /** Débit d'un appareil (octets/s, point de vue de l'appareil), coupure d'accès comprise. */
    public function devRates(array $d, int $t): array
    {
        if (!$this->online($d)) return [0.0, 0.0];
        [$down, $up] = self::rates($d, $t);
        $prof = $this->profileOf($d['mac']);
        if ($prof && $prof['current_mode'] === 'denied') return [$down * 0.01, $up * 0.01];
        return [$down, $up];
    }

    private function totals(int $t): array
    {
        $down = $up = 0.0;
        foreach (self::DEVICES as $d) {
            [$a, $b] = $t === $this->now ? $this->devRates($d, $t) : self::rates($d, $t);
            $down += $a;
            $up += $b;
        }
        return [$down + 30_000, $up + 12_000];
    }

    /** Modèle de trafic déterministe : même instant ⇒ même valeur (historique cohérent avec le direct). */
    public static function rates(array $d, int $t): array
    {
        if (!empty($d['offline'])) return [0.0, 0.0];
        $mb = 125_000.0; // 1 Mb/s en octets par seconde
        $h = (int)date('G', $t) + (int)date('i', $t) / 60;
        $slot = intdiv($t, 5);
        $n = static fn(string $salt) => self::noise($d['mac'] . $salt, $slot);
        $win = static fn(int $period, int $len, int $offset = 0) => (($t + $offset) % $period) < $len;
        $evening = $h >= 18.5 && $h < 23.5;
        $night = $h >= 1 && $h < 5;
        [$down, $up] = match ($d['kind']) {
            'phone'    => $n('a') < 0.45 ? [(0.8 + 7 * $n('b')) * $mb, (0.1 + 0.7 * $n('c')) * $mb] : [0.03 * $mb, 0.01 * $mb],
            'laptop'   => $n('a') < 0.5 ? [(2 + 25 * $n('b')) * $mb, (0.2 + 3 * $n('c')) * $mb] : [0.1 * $mb, 0.05 * $mb],
            'pc'       => $win(5400, 600, 3000) ? [(320 + 520 * $n('b')) * $mb, 2.5 * $mb] : [(0.4 + 2 * $n('b')) * $mb, (0.2 + 0.6 * $n('c')) * $mb],
            'player'   => $evening ? [(9 + 7 * $n('b')) * $mb, 0.15 * $mb] : (($h >= 7 && $h < 9) ? [6 * $mb, 0.1 * $mb] : [0.04 * $mb, 0.01 * $mb]),
            'tv'       => $evening && $n('a') < 0.7 ? [(12 + 9 * $n('b')) * $mb, 0.3 * $mb] : [0.02 * $mb, 0.01 * $mb],
            'console'  => $win(3600, 900, 1200) ? [(350 + 380 * $n('b')) * $mb, 1.8 * $mb] : [(0.2 + 1.5 * $n('b')) * $mb, (0.1 + 0.4 * $n('c')) * $mb],
            'handheld' => ($h >= 14 && $h < 19 && $n('a') < 0.5) ? [(1 + 7 * $n('b')) * $mb, 0.4 * $mb] : [0.01 * $mb, 0.01 * $mb],
            'camera'   => [(0.02 + 0.03 * $n('b')) * $mb, (1.3 + 0.8 * $n('c')) * $mb],
            'printer'  => [0.001 * $mb, 0.001 * $mb],
            'speaker'  => $n('a') < 0.3 ? [0.32 * $mb, 0.02 * $mb] : [0.01 * $mb, 0.01 * $mb],
            'iot'      => [0.002 * $mb, 0.002 * $mb],
            'rogue'    => $win(600, 170, 80) ? [(0.05 + 0.1 * $n('b')) * $mb, (9 + 6 * $n('c')) * $mb] : [0.02 * $mb, 0.03 * $mb],
            'nas'      => $night ? [0.3 * $mb, (12 + 8 * $n('c')) * $mb] : ($win(1800, 120) ? [4 * $mb, 1 * $mb] : [0.05 * $mb, 0.05 * $mb]),
            'server'   => [(0.08 + 0.2 * $n('b')) * $mb, (0.05 + 0.15 * $n('c')) * $mb],
            'guest'    => $n('a') < 0.5 ? [(1 + 4 * $n('b')) * $mb, (0.1 + 0.4 * $n('c')) * $mb] : [0.02 * $mb, 0.01 * $mb],
            default    => [0.0, 0.0],
        };
        return [max(0.0, $down), max(0.0, $up)];
    }

    public static function noise(string $salt, int $slot): float
    {
        return (crc32($salt . '|' . $slot) % 10000) / 10000;
    }

    public static function inWindow(string $start, string $stop, int $t): bool
    {
        $now = (int)date('G', $t) * 60 + (int)date('i', $t);
        [$sh, $sm] = array_map('intval', explode(':', $start));
        [$eh, $em] = array_map('intval', explode(':', $stop));
        $s = $sh * 60 + $sm;
        $e = $eh * 60 + $em;
        return $s <= $e ? ($now >= $s && $now < $e) : ($now >= $s || $now < $e);
    }

    private static function nextBoundary(array $win, int $t): int
    {
        $best = 0;
        foreach ($win as $hm) {
            [$h, $m] = array_map('intval', explode(':', $hm));
            $ts = mktime($h, $m, 0, (int)date('n', $t), (int)date('j', $t), (int)date('Y', $t));
            if ($ts <= $t) $ts = strtotime('+1 day', $ts);
            if ($best === 0 || $ts < $best) $best = $ts;
        }
        return $best;
    }

    private static function human(int $s): string
    {
        $d = intdiv($s, 86400);
        $h = intdiv($s % 86400, 3600);
        $m = intdiv($s % 3600, 60);
        return "$d jours $h heures $m minutes";
    }
}
