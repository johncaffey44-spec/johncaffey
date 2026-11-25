<?php
/*
 * Sentinelle XDR — horaires de coupure d'Internet par profil (ex. « Enfants : 21h30 → 7h, du dimanche au jeudi »).
 *
 * Fonctionnement : à l'heure de début, la tâche planifiée pose sur le profil Freebox une coupure
 * « jusqu'à l'heure de fin » (override_until). C'est la box elle-même qui rétablit l'accès à la fin,
 * même si ce serveur est éteint entre-temps. En revanche, si le serveur est éteint à l'heure de
 * DÉBUT, la coupure n'a pas lieu : pour une garantie absolue, utilisez aussi la planification
 * intégrée à Freebox OS (Contrôle parental).
 */
declare(strict_types=1);

final class Schedules
{
    public const DAYS = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];

    public static function all(): array
    {
        return array_map(fn($r) => [
            'id' => (int)$r['id'], 'name' => $r['name'], 'profile_id' => (int)$r['profile_id'], 'days' => (int)$r['days'],
            'start' => $r['start'], 'stop' => $r['stop'], 'enabled' => (bool)$r['enabled'],
            'active' => self::window($r, time()) !== null, 'applied_until' => (int)$r['applied_until'],
        ], Db::all('SELECT * FROM schedules ORDER BY profile_id, start'));
    }

    public static function byProfile(): array
    {
        $out = [];
        foreach (self::all() as $s) $out[$s['profile_id']][] = $s;
        return $out;
    }

    public static function save(array $in): array
    {
        $time = static fn($v) => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)$v) ? (string)$v : null;
        $start = $time($in['start'] ?? '');
        $stop = $time($in['stop'] ?? '');
        $days = (int)($in['days'] ?? 0) & 0x7f;
        $pid = (int)($in['profile_id'] ?? 0);
        $name = clean_text($in['name'] ?? '', 40) ?: 'Coupure programmée';
        if ($start === null || $stop === null || $start === $stop) throw new InvalidArgumentException('Heures de début et de fin invalides.');
        if ($days === 0) throw new InvalidArgumentException('Choisissez au moins un jour.');
        if ($pid <= 0) throw new InvalidArgumentException('Profil Freebox manquant.');
        $id = (int)($in['id'] ?? 0);
        if ($id > 0) {
            Db::q('UPDATE schedules SET name = ?, profile_id = ?, days = ?, start = ?, stop = ?, enabled = ?, applied_until = 0, skip_until = 0 WHERE id = ?',
                [$name, $pid, $days, $start, $stop, !empty($in['enabled']) ? 1 : 0, $id]);
        } else {
            Db::q('INSERT INTO schedules(name, profile_id, days, start, stop, enabled, created) VALUES(?, ?, ?, ?, ?, ?, ?)',
                [$name, $pid, $days, $start, $stop, !isset($in['enabled']) || !empty($in['enabled']) ? 1 : 0, time()]);
            $id = (int)Db::pdo()->lastInsertId();
        }
        return ['ok' => true, 'id' => $id];
    }

    public static function delete(int $id): void
    {
        Db::q('DELETE FROM schedules WHERE id = ?', [$id]);
    }

    /**
     * Fenêtre de coupure en cours pour l'instant $t, ou null. Les plages qui passent minuit
     * appartiennent au jour où elles commencent (« vendredi 22h → samedi 8h » = vendredi).
     * @return array{0:int,1:int}|null [début, fin] en horodatage
     */
    public static function window(array $s, int $t): ?array
    {
        if (empty($s['enabled'])) return null;
        [$sh, $sm] = array_map('intval', explode(':', $s['start']));
        [$eh, $em] = array_map('intval', explode(':', $s['stop']));
        foreach ([0, -1] as $offset) {
            $day = strtotime("$offset day", $t);
            $dow = ((int)date('N', $day)) - 1; // 0 = lundi
            if (!(((int)$s['days'] >> $dow) & 1)) continue;
            $begin = mktime($sh, $sm, 0, (int)date('n', $day), (int)date('j', $day), (int)date('Y', $day));
            $end = mktime($eh, $em, 0, (int)date('n', $day), (int)date('j', $day), (int)date('Y', $day));
            if ($end <= $begin) $end = strtotime('+1 day', $end);
            if ($t >= $begin && $t < $end) return [$begin, $end];
        }
        return null;
    }

    /** Appelé chaque minute par la collecte. */
    public static function apply(Network $net): array
    {
        $done = [];
        $now = time();
        foreach (Db::all('SELECT * FROM schedules WHERE enabled = 1') as $s) {
            $w = self::window($s, $now);
            if ($w === null) continue;
            [, $end] = $w;
            if ((int)$s['applied_until'] >= $end || (int)$s['skip_until'] >= $end) continue;
            try {
                $net->setOverride((int)$s['profile_id'], 'denied', $end);
                Db::q('UPDATE schedules SET applied_until = ? WHERE id = ?', [$end, $s['id']]);
                Db::event('schedule', '', "Horaire « {$s['name']} » : Internet coupé jusqu'à " . date('H:i', $end));
                $done[] = $s['name'];
            } catch (Throwable $e) {
                Db::event('schedule', '', "Horaire « {$s['name']} » : échec — " . $e->getMessage());
            }
        }
        return $done;
    }

    /** Autorisation manuelle pendant une plage : on ne recoupe pas avant la fin de cette plage. */
    public static function skipActive(int $profileId): void
    {
        $now = time();
        foreach (Db::all('SELECT * FROM schedules WHERE enabled = 1 AND profile_id = ?', [$profileId]) as $s) {
            $w = self::window($s, $now);
            if ($w !== null) Db::q('UPDATE schedules SET skip_until = ? WHERE id = ?', [$w[1], $s['id']]);
        }
    }
}
