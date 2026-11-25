<?php
/*
 * Sentinelle XDR — notifications sur téléphone (ntfy, Gotify, Discord, Telegram, webhook).
 * Attention : sur ntfy.sh, un « sujet » est public pour qui en connaît le nom. Choisissez un
 * nom long et imprévisible, ou hébergez votre propre serveur ntfy.
 */
declare(strict_types=1);

final class Notify
{
    private const ORDER = ['info' => 0, 'faible' => 1, 'moyenne' => 2, 'elevee' => 3, 'critique' => 4];

    public static function alerts(array $new): void
    {
        $cfg = (array)Settings::get('notify');
        if (!$new || ($cfg['type'] ?? 'none') === 'none') return;
        $min = self::ORDER[$cfg['min_severity'] ?? 'elevee'] ?? 3;
        foreach ($new as $a) {
            if ((self::ORDER[$a['severity']] ?? 0) < $min) continue;
            try {
                self::send('Sentinelle · alerte ' . mb_strtolower(Detect::LABELS[$a['severity']] ?? ''), $a['title'] . "\n" . str_limit($a['detail'], 380), $a['severity']);
                Db::q('UPDATE alerts SET notified = 1 WHERE fp = ?', [$a['fp']]);
            } catch (Throwable $e) {
                Db::event('notify', '', 'Notification impossible : ' . $e->getMessage());
                break;
            }
        }
    }

    public static function send(string $title, string $msg, string $sev = 'info'): void
    {
        $c = (array)Settings::get('notify');
        $prio = ['info' => 2, 'faible' => 2, 'moyenne' => 3, 'elevee' => 4, 'critique' => 5][$sev] ?? 3;
        $json = ['Content-Type: application/json'];
        switch ($c['type'] ?? 'none') {
            case 'ntfy':
                $p = parse_url((string)$c['url']);
                $topic = trim((string)($p['path'] ?? ''), '/');
                if (empty($p['host']) || $topic === '' || str_contains($topic, '/')) {
                    throw new RuntimeException('Indiquez l\'adresse complète du sujet ntfy, par exemple https://ntfy.sh/sentinelle-7k2q9x.');
                }
                $base = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
                $h = $json;
                if (($c['token'] ?? '') !== '') $h[] = 'Authorization: Bearer ' . $c['token'];
                self::post($base, ['topic' => $topic, 'title' => $title, 'message' => $msg, 'priority' => $prio,
                    'tags' => [$prio >= 4 ? 'rotating_light' : 'shield']], $h);
                break;
            case 'gotify':
                self::post(rtrim((string)$c['url'], '/') . '/message?token=' . rawurlencode((string)$c['token']),
                    ['title' => $title, 'message' => $msg, 'priority' => $prio * 2], $json);
                break;
            case 'discord':
                self::post((string)$c['url'], ['content' => mb_substr("**$title**\n$msg", 0, 1900)], $json);
                break;
            case 'telegram':
                if (($c['token'] ?? '') === '' || ($c['chat_id'] ?? '') === '') throw new RuntimeException('Jeton du robot et identifiant de discussion Telegram requis.');
                self::post('https://api.telegram.org/bot' . $c['token'] . '/sendMessage', ['chat_id' => $c['chat_id'], 'text' => "$title\n$msg"], $json);
                break;
            case 'webhook':
                self::post((string)$c['url'], ['source' => 'sentinelle-xdr', 'title' => $title, 'message' => $msg, 'severity' => $sev, 'ts' => time()], $json);
                break;
            default:
                throw new RuntimeException('Aucun canal de notification n\'est configuré.');
        }
    }

    private static function post(string $url, array $body, array $headers): void
    {
        if (!preg_match('~^https?://[^\s"<>]+$~i', $url)) throw new RuntimeException('Adresse de notification invalide.');
        $r = Http::request('POST', $url, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $headers, ['timeout' => 8]);
        if ($r['error'] !== '') throw new RuntimeException($r['error']);
        if ($r['status'] >= 300) throw new RuntimeException("le service a répondu HTTP {$r['status']}");
    }
}
