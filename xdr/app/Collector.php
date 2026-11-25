<?php
/*
 * Sentinelle XDR — collecte périodique (tâche planifiée Windows chaque minute, ou à l'ouverture
 * du tableau de bord) : inventaire des appareils, historique de trafic, horaires, détection,
 * notifications et ménage.
 */
declare(strict_types=1);

final class Collector
{
    public static function maybe(Network $net): void
    {
        if (time() - (int)Db::kv('collect_last', '0') < 55) return;
        try {
            self::run($net, 'web');
        } catch (Throwable $e) {
            error_log('Sentinelle — collecte : ' . $e->getMessage());
        }
    }

    public static function run(Network $net, string $origin = 'web'): array
    {
        $lock = @fopen(data_path('collect.lock'), 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return ['skipped' => true];
        try {
            $t = time();
            Db::setKv('collect_last', (string)$t);
            if ($origin === 'cron') Db::setKv('cron_last', (string)$t);
            try {
                $devices = $net->devices(true);
                $link = $net->link();
            } catch (Throwable $e) {
                Detect::persist([[
                    'fp' => 'collect_error|box', 'rule' => 'collect_error', 'kind' => 'state', 'severity' => 'elevee', 'mac' => '',
                    'title' => 'Sentinelle n\'arrive plus à joindre la Freebox', 'detail' => str_limit($e->getMessage(), 600),
                    'mitre' => '', 'fix' => 'Vérifiez le câble du serveur, puis l\'association dans Paramètres › Freebox.', 'action' => '',
                ]], false);
                throw $e;
            }
            $report = ['devices' => count($devices), 'new' => [], 'alerts' => 0, 'schedules' => []];
            self::inventory($devices, $t, $report);
            $devices = $net->devices(true); // drapeaux « connu / nouveau » à jour après l'inventaire

            $bucket = $t - $t % 60;
            Db::q('INSERT OR REPLACE INTO traffic(ts, down, up) VALUES(?, ?, ?)', [$bucket, $link['down'], $link['up']]);
            foreach ($devices as $d) {
                if (!$d['online'] || ($d['conn']['shared'] ?? false) || ($d['rate']['down'] + $d['rate']['up']) < 2000) continue;
                Db::q('INSERT OR REPLACE INTO dev_traffic(ts, mac, down, up) VALUES(?, ?, ?, ?)', [$bucket, $d['mac'], $d['rate']['down'], $d['rate']['up']]);
            }

            Kids::account($devices);
            Kids::syncClients();
            Kids::weeklyReport();
            $report['schedules'] = Schedules::apply($net);
            $new = Detect::run($net, $devices);
            Db::setKv('detect_last', (string)$t);
            $report['alerts'] = count($new);
            Notify::alerts($new);
            self::cleanup($t);
            return $report;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function inventory(array $devices, int $t, array &$report): void
    {
        $known = [];
        foreach (Db::all('SELECT mac, online, base_up, base_down, spike FROM devices') as $r) $known[$r['mac']] = $r;
        $first = $known === [];
        $thr = (float)Settings::get('upload_spike_mbps') * 125_000;
        $factor = (float)Settings::get('upload_spike_factor');
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            foreach ($devices as $d) {
                $mac = $d['mac'];
                if (!isset($known[$mac])) {
                    // Premier inventaire : ce qui est là depuis plus d'une heure est considéré comme connu.
                    $boxFirst = (int)$d['first_seen'];
                    $approved = $first && ($boxFirst === 0 || $boxFirst < $t - 3600) ? 1 : 0;
                    Db::q('INSERT INTO devices(mac, first_seen, last_seen, name, vendor, type, approved, last_ip, online, owner) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [$mac, $t, $d['online'] ? $t : ($d['last_activity'] ?: $t), $d['name'], $d['vendor'], $d['type'], $approved, $d['ipv4'], $d['online'] ? 1 : 0,
                            Network::isDemo() ? DemoBox::ownerOf($mac) : '']);
                    if (!$approved) {
                        Db::event('new', $mac, 'Nouvel appareil : ' . $d['name'] . ' — ' . ($d['vendor'] ?: 'fabricant inconnu') . ($d['ipv4'] ? ' — ' . $d['ipv4'] : ''));
                        $report['new'][] = $d['name'];
                    }
                    continue;
                }
                $k = $known[$mac];
                $up = (float)$d['rate']['up'];
                $down = (float)$d['rate']['down'];
                $base = max(50_000.0, (float)$k['base_up']);
                $spike = ($d['online'] && $up >= $thr && $up >= $factor * $base) ? (int)$k['spike'] + 1 : 0;
                // moyenne glissante de l'envoi « habituel » (hors pics) : sert de référence aux détections
                $baseUp = $spike ? (float)$k['base_up'] : (float)$k['base_up'] * 0.95 + $up * 0.05;
                $baseDown = (float)$k['base_down'] * 0.95 + $down * 0.05;
                Db::q('UPDATE devices SET last_seen = CASE WHEN ? = 1 THEN ? ELSE last_seen END, name = ?, vendor = ?, type = ?,
                       last_ip = CASE WHEN ? != \'\' THEN ? ELSE last_ip END, online = ?, base_up = ?, base_down = ?, spike = ? WHERE mac = ?',
                    [$d['online'] ? 1 : 0, $t, $d['name'], $d['vendor'], $d['type'], $d['ipv4'], $d['ipv4'], $d['online'] ? 1 : 0, $baseUp, $baseDown, $spike, $mac]);
                if ((int)$k['online'] !== ($d['online'] ? 1 : 0)) {
                    Db::event($d['online'] ? 'online' : 'offline', $mac, ($d['online'] ? 'Connecté : ' : 'Déconnecté : ') . $d['name']);
                }
            }
            if ($first && $devices) Db::event('inventory', '', 'Inventaire initial : ' . count($devices) . ' appareils enregistrés.');
            $pdo->commit();
            if ($first && Network::isDemo()) Kids::seedDemo();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private static function cleanup(int $t): void
    {
        if ($t - (int)Db::kv('cleanup_last', '0') < 3600) return;
        Db::setKv('cleanup_last', (string)$t);
        $days = (int)Settings::get('retention_days');
        Db::q('DELETE FROM traffic WHERE ts < ?', [$t - $days * 86400]);
        Db::q('DELETE FROM dev_traffic WHERE ts < ?', [$t - 2 * 86400]);
        Db::q('DELETE FROM events WHERE ts < ?', [$t - $days * 86400]);
        Db::q('DELETE FROM audit WHERE ts < ?', [$t - 365 * 86400]);
        Db::q('DELETE FROM login_fail WHERE ts < ?', [$t - 86400]);
        Db::q("DELETE FROM alerts WHERE status = 'resolved' AND resolved_ts < ?", [$t - 90 * 86400]);
        foreach (glob(data_path('cache/*.json')) ?: [] as $f) {
            if (filemtime($f) < $t - 86400 && !str_ends_with($f, 'news.json')) @unlink($f);
        }
    }
}
