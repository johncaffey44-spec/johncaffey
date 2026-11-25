<?php
/*
 * Sentinelle XDR — moteur de détection.
 *
 * Deux familles de règles :
 *  - « état » (posture) : vraies tant que la configuration ne change pas (pare-feu IPv6 coupé,
 *    UPnP actif, port ouvert…). Elles se résolvent seules quand le problème disparaît.
 *  - « événement » : un fait observé (nouvel appareil, envoi massif, domaine malveillant…).
 *    Elles restent ouvertes jusqu'à ce que vous les traitiez.
 * Chaque alerte explique le risque en clair, propose une correction et, quand c'est pertinent,
 * cite la technique MITRE ATT&CK correspondante (référentiel public des modes opératoires).
 */
declare(strict_types=1);

final class Detect
{
    /** Poids dans le score : chaque alerte ouverte retire ce pourcentage du score restant. */
    public const WEIGHTS = ['critique' => 30, 'elevee' => 15, 'moyenne' => 7, 'faible' => 2, 'info' => 0];
    public const LABELS = ['critique' => 'Critique', 'elevee' => 'Élevée', 'moyenne' => 'Moyenne', 'faible' => 'Faible', 'info' => 'Info'];

    private array $f = [];

    private function add(string $rule, string $subject, string $sev, string $title, string $detail, string $fix = '',
                         array $actions = [], string $mitre = '', string $mac = '', string $kind = 'state'): void
    {
        $this->f[] = ['fp' => $rule . '|' . $subject, 'rule' => $rule, 'kind' => $kind, 'severity' => $sev, 'mac' => $mac,
            'title' => str_limit($title, 160), 'detail' => str_limit($detail, 900), 'mitre' => $mitre, 'fix' => str_limit($fix, 500),
            'action' => $actions ? json_encode($actions, JSON_UNESCAPED_UNICODE) : ''];
    }

    public static function maybeRun(Network $net, int $every = 60): void
    {
        if (time() - (int)Db::kv('detect_last', '0') < $every) return;
        Db::setKv('detect_last', (string)time());
        try {
            self::run($net);
        } catch (Throwable $e) {
            error_log('Sentinelle — détection : ' . $e->getMessage());
        }
    }

    /** Lance toutes les règles ; renvoie les alertes nouvellement ouvertes. */
    public static function run(Network $net, ?array $devices = null): array
    {
        $d = new self();
        $complete = true;

        /* ---------------- posture de la box ---------------- */
        $exp = $net->exposure();
        if ($exp['errors']) $complete = false;
        $v6 = $exp['ipv6'];
        if ($v6['known'] && $v6['enabled'] && !$v6['firewall']) {
            $d->add('ipv6_firewall_off', 'box', 'critique', 'Pare-feu IPv6 de la Freebox désactivé',
                'En IPv6, chaque appareil de la maison possède une adresse publique. Sans ce pare-feu, tout service ouvert sur un appareil '
                . '(partage Windows, bureau à distance, ce serveur web IIS…) est joignable depuis Internet, sans passer par les redirections de la box.',
                'Freebox OS › Paramètres de la Freebox › Mode avancé › Configuration IPv6 › « Activer le pare-feu IPv6 ».',
                [['type' => 'fix', 'fix' => 'ipv6_firewall_on', 'label' => 'Activer le pare-feu IPv6']], 'T1190 · T1133');
        }
        if ($exp['dmz']['enabled']) {
            $d->add('dmz_on', 'box', 'critique', 'DMZ activée vers ' . ($exp['dmz']['host'] ?: $exp['dmz']['ip']),
                'La DMZ envoie toutes les connexions entrantes vers un seul appareil : il est entièrement exposé à Internet.',
                'Désactivez la DMZ et n\'ouvrez que les ports nécessaires.', [['type' => 'fix', 'fix' => 'dmz_off', 'label' => 'Désactiver la DMZ']], 'T1190');
        }
        if ($exp['upnp']['enabled']) {
            $n = count($exp['upnp']['redirs']);
            $d->add('upnp_on', 'box', $n ? 'moyenne' : 'faible', 'UPnP actif' . ($n ? " : $n port(s) ouvert(s) sans votre accord" : ''),
                'Avec l\'UPnP, n\'importe quel appareil ou logiciel de la maison peut ouvrir un port sur Internet sans rien demander. '
                . 'Un objet connecté piraté s\'en sert pour se rendre joignable de l\'extérieur.',
                'Désactivez l\'UPnP dans Freebox OS (Paramètres › Mode avancé › UPnP IGD). Une console fonctionne en général sans, en « NAT modéré ».',
                [['type' => 'fix', 'fix' => 'upnp_off', 'label' => 'Désactiver l\'UPnP']]);
            foreach ($exp['upnp']['redirs'] as $r) {
                if (!in_array($r['risk']['level'], ['critique', 'elevee'], true)) continue;
                $d->add('upnp_redir', $r['id'] ?: ($r['proto'] . $r['ext_port']), $r['risk']['level'],
                    "Port {$r['ext_port']}/{$r['proto']} ouvert par UPnP vers " . ($r['host'] ?: $r['int_ip']),
                    $r['risk']['why'] . ($r['desc'] !== '' ? " — l'appareil s'annonce « {$r['desc']} »." : '.'),
                    'Fermez ce port et examinez l\'appareil qui l\'a demandé.',
                    array_values(array_filter([
                        ['type' => 'fix', 'fix' => 'upnp_redir_delete', 'id' => $r['id'], 'label' => 'Fermer ce port'],
                        $r['mac'] ? ['type' => 'device', 'act' => 'isolate', 'mac' => $r['mac'], 'label' => 'Isoler l\'appareil'] : null,
                    ])), 'T1133', $r['mac']);
            }
        }
        foreach ($exp['redirs'] as $r) {
            if (!$r['enabled']) continue;
            $open = $r['src'] === '' || $r['src'] === '0.0.0.0';
            $sev = $open ? $r['risk']['level'] : ($r['risk']['level'] === 'critique' ? 'moyenne' : 'faible');
            $d->add('port_forward', (string)$r['id'], $sev, "Port {$r['wan']}/{$r['proto']} redirigé vers " . ($r['host'] ?: $r['lan_ip']),
                $r['risk']['why'] . ($r['comment'] !== '' ? " — « {$r['comment']} »" : '') . ($open ? ' — ouvert à tout Internet.' : " — limité à l'adresse {$r['src']}."),
                'Gardez-la seulement si elle sert ; pour accéder à la maison, préférez le VPN WireGuard intégré à la Freebox.',
                [['type' => 'fix', 'fix' => 'redir_disable', 'id' => $r['id'], 'label' => 'Désactiver la redirection']], 'T1133 · T1190');
        }
        $rem = $exp['remote'];
        if ($rem['known']) {
            if ($rem['remote_access']) {
                $d->add('remote_access', 'box', $rem['secure_pass'] ? 'moyenne' : 'critique', 'Administration de la Freebox ouverte sur Internet (port ' . $rem['port'] . ')',
                    $rem['secure_pass'] ? 'Freebox OS est joignable depuis Internet. Le mot de passe est jugé solide par la box, mais c\'est une porte d\'entrée de plus.'
                        : 'Freebox OS est joignable depuis Internet avec un mot de passe que la box elle-même juge faible.',
                    'Coupez l\'accès distant si vous ne l\'utilisez pas, ou passez par le VPN de la Freebox.',
                    [['type' => 'fix', 'fix' => 'remote_access_off', 'label' => 'Couper l\'accès distant']], 'T1133');
            }
            if ($rem['allow_token_request'] && $net->fbx->paired()) {
                $d->add('token_request', 'box', 'faible', 'Nouvelles associations d\'applications autorisées',
                    'Tout appareil du réseau peut demander un accès à la Freebox. Il faut encore valider sur l\'écran de la box, mais mieux vaut fermer la porte.',
                    'Une fois Sentinelle associée : Freebox OS › Gestion des accès › Paramètres › décochez « Permettre les nouvelles demandes d\'association ».',
                    [['type' => 'fix', 'fix' => 'token_request_off', 'label' => 'Refuser les nouvelles demandes']]);
            }
            if ($rem['ping']) {
                $d->add('wan_ping', 'box', 'info', 'La box répond au « ping » depuis Internet', 'Sans danger réel, mais cela signale votre connexion aux robots qui balayent Internet.', '',
                    [['type' => 'fix', 'fix' => 'ping_off', 'label' => 'Ne plus répondre']]);
            }
        }
        if ($exp['ftp']['enabled'] && $exp['ftp']['remote']) {
            $d->add('ftp_remote', 'box', $exp['ftp']['anonymous'] ? 'critique' : 'elevee', 'Serveur FTP de la Freebox accessible depuis Internet',
                ($exp['ftp']['anonymous'] ? 'L\'accès anonyme est actif : n\'importe qui peut lire' . ($exp['ftp']['anonymous_write'] ? ' et écrire' : '') . ' sur le disque. ' : '')
                . 'Le FTP ne chiffre ni les mots de passe ni les fichiers.', 'Coupez le FTP distant ; passez par le VPN pour vos fichiers.',
                [['type' => 'fix', 'fix' => 'ftp_remote_off', 'label' => 'Couper le FTP distant']], 'T1133');
        }
        if ($exp['samba']['enabled'] && !$exp['samba']['logon']) {
            $d->add('smb_guest', 'box', 'moyenne', 'Partage de fichiers de la Freebox sans mot de passe',
                'Tout appareil de la maison, y compris un objet piraté, peut lire et modifier les fichiers du disque de la box.',
                'Activez l\'authentification dans Freebox OS › Partage de fichiers (Windows).');
        }
        $wifi = $net->wifi();
        if ($wifi['errors']) $complete = false;
        foreach ($wifi['bss'] as $b) {
            $lvl = $b['security']['level'];
            if (in_array($lvl, ['critique', 'elevee'], true)) {
                $d->add('wifi_weak', $b['id'] ?: $b['ssid'], $lvl, "Wi-Fi « {$b['ssid']} » : {$b['security']['label']}",
                    'Un voisin ou un passant peut casser ce chiffrement, écouter vos échanges et rejoindre votre réseau.',
                    'Choisissez WPA2/WPA3 dans Freebox OS › Paramètres › Wi-Fi.', [], 'T1040');
            }
            if ($b['wps']) {
                $d->add('wifi_wps', $b['id'] ?: $b['ssid'], 'faible', "WPS actif sur le Wi-Fi « {$b['ssid']} »",
                    'Le WPS permet de rejoindre le Wi-Fi sans mot de passe. Sur la Freebox il faut un appui sur la box : risque faible.',
                    'Désactivez le WPS s\'il ne sert pas.');
            }
        }

        /* ---------------- appareils ---------------- */
        try {
            $devices ??= $net->devices(true);
        } catch (Throwable) {
            $devices = [];
            $complete = false;
        }
        $byIp = [];
        $mbps = 125_000.0;
        $thr = (float)Settings::get('upload_spike_mbps') * $mbps;
        foreach ($devices as $dev) {
            $label = $dev['name'] . ($dev['vendor'] !== '' ? " ({$dev['vendor']})" : '');
            foreach ($dev['ipv4_all'] as $ip) $byIp[$ip][] = $dev;
            if ($dev['flags']['new'] && !$dev['flags']['approved']) {
                $d->add('new_device', $dev['mac'], $dev['vendor'] === '' && !$dev['flags']['random_mac'] ? 'elevee' : 'moyenne',
                    'Nouvel appareil sur le réseau : ' . $dev['name'],
                    'Vu pour la première fois le ' . date('d/m à H:i', $dev['first_seen'] ?: time()) . ' — ' . ($dev['vendor'] ?: 'fabricant inconnu')
                    . ($dev['flags']['random_mac'] ? ' (adresse MAC privée, typique d\'un smartphone récent)' : '')
                    . ($dev['ipv4'] ? ' — ' . $dev['ipv4'] : '') . '. Si vous ne le reconnaissez pas, isolez-le puis changez le mot de passe Wi-Fi.',
                    'Reconnaissez-le (« C\'est à moi ») ou isolez-le.',
                    [['type' => 'device', 'act' => 'approve', 'mac' => $dev['mac'], 'label' => 'C\'est à moi'],
                        ['type' => 'device', 'act' => 'isolate', 'mac' => $dev['mac'], 'label' => 'Isoler']], 'T1200', $dev['mac'], 'event');
            }
            if ($dev['flags']['isolated']) {
                $d->add('isolated', $dev['mac'], 'info', 'Appareil isolé : ' . $dev['name'], 'Cet appareil est coupé d\'Internet et du Wi-Fi par Sentinelle.',
                    'Rétablissez-le une fois nettoyé ou réinitialisé.', [['type' => 'device', 'act' => 'release', 'mac' => $dev['mac'], 'label' => 'Rétablir']], '', $dev['mac']);
            }
            $up = (float)$dev['rate']['up'];
            $row = Db::one('SELECT base_up, spike FROM devices WHERE mac = ?', [$dev['mac']]);
            $base = max(50_000.0, (float)($row['base_up'] ?? 0));
            $sustained = (int)($row['spike'] ?? 0) >= 2;
            $risky = in_array($dev['type'], ['ip_camera', 'smart_home', 'other', 'speaker', 'multimedia_device', 'printer'], true);
            if ($dev['online'] && !($dev['conn']['shared'] ?? false) && $up >= $thr && $up >= (float)Settings::get('upload_spike_factor') * $base && ($sustained || $up >= 3 * $thr)) {
                $d->add('upload_spike', $dev['mac'], $risky ? 'critique' : 'elevee',
                    sprintf('Envoi inhabituel de données : %s (%.1f Mb/s)', $dev['name'], $up / $mbps),
                    sprintf('%s envoie %.1f Mb/s vers Internet, %d fois plus que d\'habitude. %s', $label, $up / $mbps, (int)round($up / $base),
                        $risky ? 'Pour un objet connecté, c\'est le signe classique d\'un appareil enrôlé dans un botnet (attaques, minage) ou d\'une fuite de données.'
                            : 'Cela peut être une sauvegarde ou un envoi de vidéo… ou une fuite de données.'),
                    'Vérifiez ce que fait l\'appareil ; au moindre doute, isolez-le.',
                    [['type' => 'device', 'act' => 'isolate', 'mac' => $dev['mac'], 'label' => 'Isoler'],
                        ['type' => 'device', 'act' => 'block', 'mac' => $dev['mac'], 'label' => 'Couper Internet']], 'TA0010 · T1041', $dev['mac'], 'event');
            }
        }
        foreach ($byIp as $ip => $list) {
            $active = array_values(array_filter($list, fn($x) => $x['online']));
            if (count($active) < 2) continue;
            $names = implode(' et ', array_map(fn($x) => $x['name'] . ' (' . $x['mac'] . ')', $active));
            $suspect = null;
            foreach ($active as $x) {
                if (count($x['ipv4_all']) > 1) $suspect = $x;
            }
            $d->add('ip_conflict', $ip, 'elevee', "Adresse $ip revendiquée par plusieurs appareils",
                "$names utilisent la même adresse IP. C'est soit une erreur de configuration, soit un appareil qui se fait passer pour un autre "
                . '(empoisonnement ARP) pour intercepter son trafic.',
                'Isolez l\'appareil qui revendique plusieurs adresses, puis vérifiez les réglages réseau.',
                $suspect ? [['type' => 'device', 'act' => 'isolate', 'mac' => $suspect['mac'], 'label' => 'Isoler ' . $suspect['name']]] : [],
                'T1557.002', $suspect['mac'] ?? '');
        }

        /* ---------------- DNS (AdGuard Home) ---------------- */
        if (Dns::enabled()) {
            try {
                $log = cache_get('dns-recent', 8, fn() => Dns::recent(500));
                $per = [];
                foreach ($log as $e) {
                    if ($e['t'] < time() - 900) continue;
                    $p = &$per[$e['ip']];
                    $p ??= ['nx' => [], 'mal' => [], 'mine' => []];
                    if ($e['status'] === 'NXDOMAIN') $p['nx'][$e['domain']] = true;
                    if ($e['reason'] === 'FilteredSafeBrowsing') $p['mal'][$e['domain']] = true;
                    if (preg_match('/(xmr|monero|nicehash|minergate|coinhive|cryptonight|stratum|minexmr|supportxmr|2miners|ethermine)/i', $e['domain'])) $p['mine'][$e['domain']] = true;
                    unset($p);
                }
                foreach ($per as $ip => $p) {
                    $dev = $byIp[$ip][0] ?? null;
                    $who = $dev ? $dev['name'] : $ip;
                    $mac = $dev['mac'] ?? '';
                    $iso = $mac ? [['type' => 'device', 'act' => 'isolate', 'mac' => $mac, 'label' => 'Isoler']] : [];
                    if ($p['mal']) {
                        $d->add('dns_malware', $mac ?: $ip, 'elevee', "Domaine malveillant contacté par $who",
                            'Le filtre anti-hameçonnage et anti-logiciels malveillants a bloqué : ' . implode(', ', array_slice(array_keys($p['mal']), 0, 4))
                            . '. Un clic sur un lien piégé ou un logiciel infecté peut en être la cause.',
                            'Analysez l\'appareil (antivirus), changez les mots de passe saisis récemment.', $iso, 'T1566 · T1071.004', $mac, 'event');
                    }
                    if ($p['mine']) {
                        $d->add('dns_mining', $mac ?: $ip, 'elevee', "Activité de minage de cryptomonnaie : $who",
                            'Requêtes vers des serveurs de minage : ' . implode(', ', array_slice(array_keys($p['mine']), 0, 3))
                            . '. Un logiciel utilise la puissance de l\'appareil à votre insu.', 'Isolez l\'appareil puis réinitialisez-le.', $iso, 'T1496', $mac, 'event');
                    }
                    if (count($p['nx']) >= 20) {
                        $d->add('dns_dga', $mac ?: $ip, 'moyenne', "Rafale de domaines inexistants : $who",
                            count($p['nx']) . ' noms de domaine inexistants demandés en un quart d\'heure (ex. : ' . implode(', ', array_slice(array_keys($p['nx']), 0, 3))
                            . '). Les logiciels malveillants génèrent ainsi des adresses pour retrouver leur serveur de commande.',
                            'À surveiller ; isolez l\'appareil si d\'autres signes s\'ajoutent.', $iso, 'T1568.002', $mac, 'event');
                    }
                }
            } catch (Throwable) {
                // AdGuard Home injoignable : aucune règle DNS ce tour-ci
            }
        }

        /* ---------------- Sentinelle elle-même ---------------- */
        if (Db::kv('ui_https', '1') === '0') {
            $d->add('xdr_http', 'xdr', 'faible', 'Interface Sentinelle ouverte en HTTP (non chiffré)',
                'Votre mot de passe Sentinelle circule en clair sur le réseau local : un appareil piraté de la maison pourrait l\'intercepter.',
                'Activez HTTPS sur IIS (certificat auto-signé, voir le guide d\'installation).');
        }
        if (!$net->fbx->isDemo() && str_starts_with((string)($net->fbx->info()['base'] ?? ''), 'http:')) {
            $d->add('fbx_http', 'xdr', 'faible', 'Liaison Sentinelle ↔ Freebox non chiffrée',
                'Le jeton de session circule en clair entre ce serveur et la box.', 'Gardez l\'adresse https://mafreebox.freebox.fr dans Paramètres › Freebox.');
        }
        $cron = (int)Db::kv('cron_last', '0');
        if ($cron < time() - 900) {
            $d->add('no_cron', 'xdr', 'faible', 'Surveillance continue inactive',
                'Sans la tâche planifiée Windows, Sentinelle n\'analyse le réseau que lorsque cette page est ouverte : un intrus la nuit passerait inaperçu '
                . 'et les horaires de coupure ne s\'appliqueraient pas.', 'Lancez tools\\Installer-XDR.cmd, ou créez la tâche décrite dans le guide.');
        }
        $fails = (int)Db::val('SELECT COUNT(*) FROM login_fail WHERE ts > ?', [time() - 3600]);
        if ($fails >= 8) {
            $d->add('login_bruteforce', 'xdr', 'elevee', "$fails mots de passe erronés sur Sentinelle en une heure",
                'Quelqu\'un essaie peut-être de deviner le mot de passe de cette console.', 'Changez le mot de passe et activez la double authentification.',
                [], 'T1110', '', 'event');
        }

        return self::persist($d->f, $complete);
    }

    /** Enregistre les constats ; renvoie ceux qui deviennent (ou redeviennent) des alertes ouvertes. */
    public static function persist(array $findings, bool $autoResolve = true): array
    {
        $now = time();
        $pdo = Db::pdo();
        $new = [];
        $seen = [];
        $pdo->beginTransaction();
        try {
            foreach ($findings as $x) {
                $seen[$x['fp']] = true;
                $row = Db::one('SELECT id, status FROM alerts WHERE fp = ?', [$x['fp']]);
                if ($row === null) {
                    Db::q('INSERT INTO alerts(fp, rule, kind, severity, mac, title, detail, mitre, fix, action, status, first_ts, last_ts)
                           VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'open\', ?, ?)',
                        [$x['fp'], $x['rule'], $x['kind'], $x['severity'], $x['mac'], $x['title'], $x['detail'], $x['mitre'], $x['fix'], $x['action'], $now, $now]);
                    $x['id'] = (int)$pdo->lastInsertId();
                    $new[] = $x;
                } else {
                    Db::q("UPDATE alerts SET severity = ?, mac = ?, title = ?, detail = ?, mitre = ?, fix = ?, action = ?,
                           count = count + CASE WHEN kind = 'event' AND last_ts < ? THEN 1 ELSE 0 END,
                           notified = CASE WHEN status = 'resolved' THEN 0 ELSE notified END,
                           status = CASE WHEN status = 'resolved' THEN 'open' ELSE status END, last_ts = ? WHERE id = ?",
                        [$x['severity'], $x['mac'], $x['title'], $x['detail'], $x['mitre'], $x['fix'], $x['action'], $now - 600, $now, $row['id']]);
                    if ($row['status'] === 'resolved') {
                        $x['id'] = (int)$row['id'];
                        $new[] = $x;
                    }
                }
            }
            if ($autoResolve) {
                foreach (Db::all("SELECT id, fp FROM alerts WHERE kind = 'state' AND status != 'resolved'") as $r) {
                    if (!isset($seen[$r['fp']])) Db::q("UPDATE alerts SET status = 'resolved', resolved_ts = ? WHERE id = ?", [$now, $r['id']]);
                }
            }
            Db::q("UPDATE alerts SET status = 'resolved', resolved_ts = ? WHERE kind = 'event' AND status = 'open' AND last_ts < ?", [$now, $now - 7 * 86400]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $new;
    }

    public static function score(): array
    {
        $counts = array_fill_keys(array_keys(self::WEIGHTS), 0);
        foreach (Db::all("SELECT severity, COUNT(*) AS n FROM alerts WHERE status = 'open' GROUP BY severity") as $r) {
            if (isset($counts[$r['severity']])) $counts[$r['severity']] = (int)$r['n'];
        }
        $v = 100.0;
        foreach ($counts as $sev => $n) $v *= (1 - self::WEIGHTS[$sev] / 100) ** $n;
        $value = (int)round($v);
        $grade = match (true) {
            $value >= 85 => 'A', $value >= 70 => 'B', $value >= 50 => 'C', $value >= 30 => 'D', default => 'E',
        };
        $text = match ($grade) {
            'A' => 'Réseau bien protégé', 'B' => 'Quelques points à corriger', 'C' => 'Des risques à traiter',
            'D' => 'Réseau exposé', default => 'Situation critique',
        };
        return ['value' => $value, 'grade' => $grade, 'text' => $text, 'counts' => $counts];
    }

    public static function list(string $status = 'active', int $limit = 200): array
    {
        $where = match ($status) {
            'open' => "status = 'open'",
            'ack' => "status = 'ack'",
            'resolved' => "status = 'resolved'",
            'all' => '1 = 1',
            default => "status != 'resolved'",
        };
        $rows = Db::all("SELECT * FROM alerts WHERE $where ORDER BY CASE status WHEN 'open' THEN 0 WHEN 'ack' THEN 1 ELSE 2 END,
            CASE severity WHEN 'critique' THEN 0 WHEN 'elevee' THEN 1 WHEN 'moyenne' THEN 2 WHEN 'faible' THEN 3 ELSE 4 END, last_ts DESC LIMIT " . max(1, min(500, $limit)));
        return array_map(fn($r) => [
            'id' => (int)$r['id'], 'rule' => $r['rule'], 'kind' => $r['kind'], 'severity' => $r['severity'], 'mac' => $r['mac'],
            'title' => $r['title'], 'detail' => $r['detail'], 'mitre' => $r['mitre'], 'fix' => $r['fix'],
            'actions' => $r['action'] !== '' ? (json_decode($r['action'], true) ?: []) : [], 'status' => $r['status'],
            'first' => (int)$r['first_ts'], 'last' => (int)$r['last_ts'], 'count' => (int)$r['count'],
        ], $rows);
    }

    public static function setStatus(int $id, string $status): void
    {
        if (!in_array($status, ['open', 'ack', 'resolved'], true)) throw new InvalidArgumentException('Statut inconnu.');
        Db::q('UPDATE alerts SET status = ?, resolved_ts = ? WHERE id = ?', [$status, $status === 'resolved' ? time() : 0, $id]);
    }
}
