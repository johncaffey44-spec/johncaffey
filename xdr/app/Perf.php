<?php
/*
 * Sentinelle XDR — performances : trouver ce qui limite le débit, sans jamais brider personne.
 *
 * Principe : la fibre (jusqu'à 8 Gb/s sur Freebox Ultra) est rarement le goulet. Ce qui ralentit
 * vraiment un appareil, c'est sa propre liaison : Wi-Fi faible ou en 2,4 GHz, câble Ethernet
 * négocié à 100 Mb/s, canal Wi-Fi encombré, ou un autre appareil qui sature l'envoi. Corriger
 * ces liaisons accélère un appareil sans rien retirer aux autres. Sentinelle ne pose donc AUCUNE
 * limite de débit : elle diagnostique, mesure et explique.
 */
declare(strict_types=1);

final class Perf
{
    private const ORDER = ['elevee' => 0, 'moyenne' => 1, 'faible' => 2, 'info' => 3];
    private const STREAMERS = ['television', 'freebox_player', 'multimedia_device', 'vg_console', 'laptop', 'workstation', 'nas'];

    public static function diagnose(Network $net): array
    {
        $issues = [];
        $good = [];
        $add = static function (string $level, string $title, string $why, string $fix, ?array $d = null) use (&$issues): void {
            $issues[] = ['level' => $level, 'title' => $title, 'why' => $why, 'fix' => $fix,
                'device' => $d ? ['mac' => $d['mac'], 'name' => $d['name'], 'type' => $d['type']] : null];
        };
        $link = $net->link();
        $devices = $net->devices(true);

        /* ---- liaisons des appareils ---- */
        $wifiFast = 0;
        foreach ($devices as $d) {
            if (!$d['online']) continue;
            $c = $d['conn'];
            if (($c['kind'] ?? '') === 'ethernet') {
                $speed = (int)($c['speed'] ?? 0);
                if ($speed > 0 && $speed <= 100) {
                    $add('elevee', "{$d['name']} est limité à $speed Mb/s par son câble",
                        "Sa prise Ethernet s'est négociée à $speed Mb/s, alors que la box en offre 2 500. Cause habituelle : câble abîmé, ancien (catégorie 5) ou mal enfoncé.",
                        'Remplacez le câble par un câble catégorie 6 et vérifiez qu\'il clique dans la prise. Effet immédiat, sans toucher aux autres appareils.', $d);
                } elseif ($speed >= 1000) {
                    $good[] = "{$d['name']} : Ethernet à " . ($speed >= 2500 ? '2,5 Gb/s' : ($speed >= 1000 ? '1 Gb/s' : "$speed Mb/s"));
                }
            }
            if (($c['kind'] ?? '') === 'wifi') {
                $sig = isset($c['signal']) ? (int)$c['signal'] : null;
                $phy = isset($c['phy_down']) ? (int)$c['phy_down'] : null;
                $stream = in_array($d['type'], self::STREAMERS, true);
                $tvLike = in_array($d['type'], ['television', 'freebox_player'], true);
                if ($sig !== null && $sig <= -70) {
                    $add($stream ? 'elevee' : 'moyenne', "{$d['name']} : signal Wi-Fi faible ($sig dBm)",
                        'Un signal faible fait chuter le débit et provoque des coupures' . ($stream ? ', surtout en vidéo 4K et en jeu en ligne.' : '.'),
                        $tvLike ? 'Le plus efficace : un câble Ethernet ou deux boîtiers CPL entre la box et la TV.'
                            : 'Rapprochez l\'appareil de la box, ou ajoutez un répéteur Wi-Fi Freebox à mi-chemin.', $d);
                } elseif ($phy !== null && $phy > 0 && $phy < 100 && $stream) {
                    $add('moyenne', "{$d['name']} : liaison Wi-Fi lente ($phy Mb/s)",
                        'La liaison radio est lente pour un appareil de vidéo ou de jeu.',
                        'Rapprochez-le de la box ou branchez-le en Ethernet.', $d);
                }
                if (($c['band'] ?? '') === '2,4 GHz' && $sig !== null && $sig > -65
                    && in_array($d['type'], ['smartphone', 'tablet', 'laptop', 'television', 'freebox_player', 'vg_console', 'workstation'], true)) {
                    $add('faible', "{$d['name']} reste en Wi-Fi 2,4 GHz",
                        'C\'est la bande la plus lente et la plus encombrée. Près de la box, cet appareil devrait utiliser le 5 ou le 6 GHz.',
                        'Sur l\'appareil, « oubliez » le réseau Wi-Fi puis reconnectez-le près de la box. S\'il reste en 2,4 GHz, il ne gère sans doute pas le 5 GHz.', $d);
                }
                if ($tvLike && ($sig === null || $sig > -70)) {
                    $add('info', "{$d['name']} regarde la TV en Wi-Fi",
                        'Ça fonctionne, mais un four à micro-ondes ou un voisin peut provoquer des micro-coupures pendant un match ou un film.',
                        'Si l\'image se fige parfois : câble Ethernet ou CPL.', $d);
                }
                if (in_array($c['band'] ?? '', ['5 GHz', '6 GHz'], true) && ($sig === null || $sig > -65)) $wifiFast++;
            }
        }
        if ($wifiFast) $good[] = "$wifiFast appareil(s) en Wi-Fi 5/6 GHz avec un bon signal";

        /* ---- quelqu'un sature-t-il la ligne ? ---- */
        $bwUp = $link['bw_up'] / 8;
        $bwDown = $link['bw_down'] / 8;
        if ($bwDown > 0 && $link['down'] > 0.85 * $bwDown) {
            $top = self::topTalker($devices, 'down');
            $add('moyenne', 'La réception est saturée en ce moment',
                'La ligne est utilisée à ' . (int)round($link['down'] / $bwDown * 100) . ' %' . ($top ? ', surtout par ' . $top . '.' : '.'),
                'Les gros téléchargements (jeux, mises à jour) peuvent être lancés la nuit.');
        }
        if ($bwUp > 0 && $link['up'] > 0.85 * $bwUp) {
            $top = self::topTalker($devices, 'up');
            $add('elevee', 'L\'envoi est saturé en ce moment',
                'Quand l\'envoi est plein, tout ralentit (appels vidéo, jeux, navigation)' . ($top ? ' ; en cause : ' . $top . '.' : '.'),
                'Programmez les sauvegardes et les envois vers le cloud la nuit.');
        }
        if ($bwUp > 0) {
            foreach (Db::all('SELECT t.mac, AVG(t.up) AS up, COUNT(*) AS n, COALESCE(d.name, t.mac) AS name FROM dev_traffic t LEFT JOIN devices d ON d.mac = t.mac
                              WHERE t.ts >= ? AND CAST(strftime(\'%H\', t.ts, \'unixepoch\', \'localtime\') AS INTEGER) BETWEEN 18 AND 22
                              GROUP BY t.mac HAVING AVG(t.up) > ? AND COUNT(*) >= 10', [time() - 7 * 86400, 0.4 * $bwUp]) as $r) {
                $add('moyenne', "{$r['name']} envoie beaucoup de données en soirée",
                    'En moyenne ' . self::mbps((float)$r['up']) . ' entre 18 h et 23 h, soit une bonne partie de votre débit d\'envoi aux heures où la famille utilise Internet.',
                    'Décalez ses sauvegardes ou synchronisations après minuit, dans ses propres réglages.');
            }
        }

        /* ---- Wi-Fi : canaux encombrés, économie d'énergie ---- */
        foreach ((array)$net->fbx->tryGet('wifi/ap/', []) as $ap) {
            if (!isset($ap['id'])) continue;
            $band = Network::band((string)($ap['config']['band'] ?? ''));
            $chan = (int)($ap['config']['primary_channel'] ?? 0);
            if ($chan <= 0) continue;
            $same = 0;
            foreach ((array)$net->fbx->tryGet('wifi/ap/' . (int)$ap['id'] . '/neighbors/', []) as $n) {
                $nc = (int)($n['channel'] ?? $n['primary_channel'] ?? 0);
                $ns = (int)($n['signal'] ?? $n['rssi'] ?? -100);
                if ($nc === $chan && $ns > -80) $same++;
            }
            if ($same >= 3) {
                $add('faible', "Wi-Fi $band : $same réseaux voisins sur le même canal ($chan)",
                    'Les réseaux voisins sur le même canal se partagent le temps d\'antenne : le débit baisse aux heures chargées.',
                    'Laissez la box en canal automatique, ou choisissez dans Freebox OS (Wi-Fi) un canal moins chargé. Le 5 et le 6 GHz sont bien moins encombrés.');
            }
        }
        $wcfg = (array)$net->fbx->tryGet('wifi/config/', []);
        if (!empty($wcfg['power_saving'])) {
            $add('info', 'Économie d\'énergie Wi-Fi activée sur la box',
                'Elle peut réduire la portée ou le débit à certaines heures.', 'Si des appareils décrochent, désactivez-la dans Freebox OS › Wi-Fi.');
        }

        /* ---- la box et la fibre ---- */
        $box = $net->box();
        if ($box['ftth'] && $box['ftth']['rx_dbm'] !== null) {
            $rx = (float)$box['ftth']['rx_dbm'];
            if ($rx < -27 || $rx > -8) {
                $add('elevee', 'Signal de la fibre hors des valeurs normales (' . str_replace('.', ',', (string)$rx) . ' dBm)',
                    'Un signal optique trop faible ou trop fort provoque des pertes et des coupures.',
                    'Vérifiez que la jarretière (le fin câble de fibre) n\'est ni pliée ni pincée, puis contactez Free si le problème persiste.');
            } else {
                $good[] = 'Fibre : signal optique correct (' . str_replace('.', ',', (string)$rx) . ' dBm)';
            }
        }
        $hot = $box['sensors'] ? max(array_column($box['sensors'], 'value')) : 0;
        if ($hot >= 85) {
            $add('moyenne', "La box chauffe ($hot °C)", 'Une box trop chaude peut ralentir ou redémarrer.', 'Dégagez ses aérations et ne la posez pas dans un meuble fermé.');
        }
        if (!(bool)($net->exposure()['ipv6']['enabled'] ?? true)) {
            $add('faible', 'IPv6 désactivé', 'De nombreux services (vidéo, jeux, Google) sont plus directs en IPv6.', 'Activez l\'IPv6 dans Freebox OS (avec son pare-feu).');
        } else {
            $good[] = 'IPv6 actif';
        }
        if ($link['state'] === 'up') $good[] = 'Connexion ' . strtoupper($link['media']) . ' en ligne · ' . self::mbps($link['bw_down'] / 8) . ' disponibles';

        usort($issues, fn($a, $b) => self::ORDER[$a['level']] <=> self::ORDER[$b['level']]);
        return [
            'issues' => $issues,
            'good' => $good,
            'link' => $link,
            'availability' => self::availability(),
            'outages' => array_map(fn($o) => ['start' => (int)$o['start'], 'stop' => (int)$o['stop']],
                Db::all('SELECT start, stop FROM outages ORDER BY start DESC LIMIT 20')),
            'tests' => array_map(fn($t) => ['ts' => (int)$t['ts'], 'kind' => $t['kind'], 'label' => $t['label'],
                'down' => (float)$t['down'], 'up' => (float)$t['up'], 'ping' => (float)$t['ping']],
                Db::all('SELECT * FROM speedtests ORDER BY ts DESC LIMIT 20')),
        ];
    }

    private static function topTalker(array $devices, string $dir): string
    {
        usort($devices, fn($a, $b) => $b['rate'][$dir] <=> $a['rate'][$dir]);
        return $devices && $devices[0]['rate'][$dir] > 0 ? $devices[0]['name'] . ' (' . self::mbps($devices[0]['rate'][$dir]) . ')' : '';
    }

    public static function mbps(float $bytesPerSec): string
    {
        $m = $bytesPerSec * 8 / 1e6;
        return $m >= 1000 ? str_replace('.', ',', (string)round($m / 1000, 1)) . ' Gb/s' : str_replace('.', ',', (string)round($m, $m < 10 ? 1 : 0)) . ' Mb/s';
    }

    /* ------------------------------------------------------------------ disponibilité (coupures) */

    /** Appelé par la collecte : repère les coupures de la connexion Internet. */
    public static function trackLink(string $state): void
    {
        $prev = Db::kv('link_state', 'up');
        $now = time();
        if ($state !== 'up' && $prev === 'up') {
            Db::q('INSERT INTO outages(start) VALUES(?)', [$now]);
            Db::event('outage', '', 'Connexion Internet coupée');
        } elseif ($state === 'up' && $prev !== 'up') {
            $o = Db::one('SELECT id, start FROM outages WHERE stop = 0 ORDER BY start DESC LIMIT 1');
            if ($o) {
                Db::q('UPDATE outages SET stop = ? WHERE id = ?', [$now, $o['id']]);
                $min = max(1, (int)round(($now - (int)$o['start']) / 60));
                Db::event('outage', '', "Connexion Internet rétablie après $min min");
                try {
                    if ((Settings::get('notify')['type'] ?? 'none') !== 'none') {
                        Notify::send('Sentinelle · coupure Internet', "La connexion a été coupée $min min (de " . date('H:i', (int)$o['start']) . ' à ' . date('H:i', $now) . ').', 'moyenne');
                    }
                } catch (Throwable) {
                }
            }
        }
        if ($state !== $prev) Db::setKv('link_state', $state);
    }

    public static function availability(int $days = 30): array
    {
        $from = time() - $days * 86400;
        $down = 0;
        $count = 0;
        foreach (Db::all('SELECT start, stop FROM outages WHERE stop = 0 OR stop >= ?', [$from]) as $o) {
            $down += min($o['stop'] ?: time(), time()) - max((int)$o['start'], $from);
            $count++;
        }
        return ['days' => $days, 'percent' => round(100 - $down / ($days * 864), 3), 'down_minutes' => (int)round($down / 60), 'count' => $count];
    }

    /* ------------------------------------------------------------------ tests de débit */

    private const SPEED_URL = 'https://speed.cloudflare.com/';
    /** Le service refuse les demandes de 100 Mo et plus (HTTP 403) : on enchaîne des morceaux de 25 Mo. */
    private const CHUNK = 25_000_000;

    /** Débit Internet mesuré par le serveur (plafonné par sa carte réseau). */
    public static function wanTest(): array
    {
        if (!function_exists('curl_multi_init')) throw new RuntimeException('Extension PHP curl manquante.');
        $ping = self::latency(5);
        $down = self::throughput('down', 6);
        $up = self::throughput('up', 5);
        $res = ['down' => round($down, 1), 'up' => round($up, 1), 'ping' => round($ping, 1)];
        Db::q('INSERT INTO speedtests(ts, kind, label, down, up, ping) VALUES(?, ?, ?, ?, ?, ?)', [time(), 'wan', 'Fibre (depuis le serveur)', $res['down'], $res['up'], $res['ping']]);
        return $res;
    }

    private static function curlBase(): array
    {
        $o = [
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT => 40,
            CURLOPT_USERAGENT => 'SentinelleXDR/' . XDR_VERSION,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => 'identity',
        ];
        if (PHP_OS_FAMILY === 'Windows' && !ini_get('curl.cainfo') && defined('CURLSSLOPT_NATIVE_CA')) $o[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
        return $o;
    }

    private static function unreachable(string $why): RuntimeException
    {
        return new RuntimeException("Test impossible ($why). Le serveur a-t-il accès à Internet ?");
    }

    /** Latence : médiane de $n requêtes vides sur une connexion déjà ouverte (ni DNS, ni TLS dans le chiffre). */
    private static function latency(int $n): float
    {
        $ch = curl_init();
        curl_setopt_array($ch, self::curlBase() + [CURLOPT_URL => self::SPEED_URL . '__down?bytes=0', CURLOPT_RETURNTRANSFER => true]);
        $t = [];
        for ($i = 0; $i < $n; $i++) {
            curl_exec($ch);
            if (curl_errno($ch) !== 0) throw self::unreachable(curl_error($ch));
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if ($code < 200 || $code >= 300) throw self::unreachable("le service de mesure a répondu HTTP $code");
            $t[] = ((float)curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME) - (float)curl_getinfo($ch, CURLINFO_PRETRANSFER_TIME)) * 1000;
        }
        sort($t);
        return $t[intdiv($n, 2)];
    }

    /**
     * Débit en Mb/s sur une fenêtre de $seconds secondes, avec $streams transferts en parallèle relancés
     * au fil de l'eau (comme les tests du commerce : un seul flux ne remplit pas une ligne rapide).
     */
    private static function throughput(string $dir, int $seconds, int $streams = 4): float
    {
        $mh = curl_multi_init();
        $handles = [];
        $seen = [];
        $bytes = 0;
        $first = null;
        $lastErr = '';
        $body = $dir === 'up' ? str_repeat(random_bytes(1 << 20), 8) : null;
        $add = static function () use ($mh, $dir, $body, &$handles, &$seen, &$bytes, &$first): void {
            $ch = curl_init();
            $id = spl_object_id($ch);
            $seen[$id] = 0;
            $o = self::curlBase();
            if ($dir === 'down') {
                $o[CURLOPT_URL] = self::SPEED_URL . '__down?bytes=' . self::CHUNK;
                $o[CURLOPT_WRITEFUNCTION] = static function ($c, string $d) use (&$bytes, &$first): int {
                    $code = (int)curl_getinfo($c, CURLINFO_RESPONSE_CODE);
                    if ($code >= 200 && $code < 300) { // une page d'erreur ne compte pas comme du débit
                        $first ??= microtime(true);
                        $bytes += strlen($d);
                    }
                    return strlen($d);
                };
            } else {
                $o[CURLOPT_URL] = self::SPEED_URL . '__up';
                $o[CURLOPT_POST] = true;
                $o[CURLOPT_POSTFIELDS] = $body;
                $o[CURLOPT_HTTPHEADER] = ['Content-Type: application/octet-stream'];
                $o[CURLOPT_RETURNTRANSFER] = true;
                $o[CURLOPT_NOPROGRESS] = false;
                $o[CURLOPT_XFERINFOFUNCTION] = static function ($c, $dlTotal, $dlNow, $ulTotal, $ulNow) use ($id, &$seen, &$bytes, &$first): int {
                    $delta = (int)$ulNow - ($seen[$id] ?? 0);
                    if ($delta > 0) {
                        $first ??= microtime(true);
                        $bytes += $delta;
                        $seen[$id] = (int)$ulNow;
                    }
                    return 0;
                };
            }
            curl_setopt_array($ch, $o);
            curl_multi_add_handle($mh, $ch);
            $handles[$id] = $ch;
        };
        $stop = static function () use ($mh, &$handles): void {
            foreach ($handles as $ch) curl_multi_remove_handle($mh, $ch);
            $handles = [];
        };
        for ($i = 0; $i < $streams; $i++) $add();
        $giveUp = microtime(true) + 15; // sans premier octet au bout de 15 s, on abandonne
        try {
            while (true) {
                curl_multi_exec($mh, $running);
                while (($info = curl_multi_info_read($mh)) !== false) {
                    $ch = $info['handle'];
                    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                    curl_multi_remove_handle($mh, $ch);
                    unset($handles[spl_object_id($ch)]);
                    if ($info['result'] !== CURLE_OK) {
                        $lastErr = curl_strerror($info['result']);
                    } elseif ($code < 200 || $code >= 300) {
                        $lastErr = "le service de mesure a répondu HTTP $code";
                    } else {
                        $add(); // la ligne reste occupée jusqu'à la fin de la fenêtre
                    }
                }
                $now = microtime(true);
                if ($first !== null && $now - $first >= $seconds) break;
                if (!$handles) break; // tous les transferts ont échoué
                if ($first === null && $now > $giveUp) {
                    $lastErr = 'le service de mesure ne répond pas';
                    break;
                }
                if (curl_multi_select($mh, 0.2) === -1) usleep(5000);
            }
            $span = $first !== null ? microtime(true) - $first : 0.0;
        } finally {
            $stop();
        }
        if ($bytes === 0 || $span < 0.2) throw self::unreachable($lastErr ?: 'aucune donnée reçue');
        return $bytes * 8 / $span / 1e6;
    }

    /** Résultat d'un test fait depuis le navigateur (appareil ↔ serveur, réseau local). */
    public static function saveLan(float $down, float $up, float $ping, string $label): array
    {
        $down = max(0, min(100000, $down));
        $up = max(0, min(100000, $up));
        $ping = max(0, min(10000, $ping));
        Db::q('INSERT INTO speedtests(ts, kind, label, down, up, ping) VALUES(?, ?, ?, ?, ?, ?)', [time(), 'lan', clean_text($label, 60), round($down, 1), round($up, 1), round($ping, 1)]);
        return ['ok' => true];
    }
}
