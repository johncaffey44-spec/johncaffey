<?php
/*
 * Sentinelle XDR — base SQLite (data/xdr.sqlite) : inventaire des appareils, alertes,
 * journal d'audit, historique de trafic, programmations.
 */
declare(strict_types=1);

final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) return self::$pdo;
        if (!extension_loaded('pdo_sqlite')) {
            throw new RuntimeException("L'extension PHP « pdo_sqlite » n'est pas activée. Dans php.ini, retirez le « ; » devant « extension=pdo_sqlite » et « extension=sqlite3 », puis redémarrez IIS (commande : iisreset).");
        }
        $pdo = new PDO('sqlite:' . data_path('xdr.sqlite'), null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT            => 5,
        ]);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        self::migrate($pdo);
        return self::$pdo = $pdo;
    }

    private static function migrate(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS devices (
    mac TEXT PRIMARY KEY, first_seen INTEGER NOT NULL, last_seen INTEGER NOT NULL,
    name TEXT NOT NULL DEFAULT '', vendor TEXT NOT NULL DEFAULT '', type TEXT NOT NULL DEFAULT 'other',
    custom_type TEXT NOT NULL DEFAULT '', approved INTEGER NOT NULL DEFAULT 0, note TEXT NOT NULL DEFAULT '',
    last_ip TEXT NOT NULL DEFAULT '', online INTEGER NOT NULL DEFAULT 0,
    isolated INTEGER NOT NULL DEFAULT 0, isolation TEXT NOT NULL DEFAULT '',
    base_up REAL NOT NULL DEFAULT 0, base_down REAL NOT NULL DEFAULT 0, spike INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS alerts (
    id INTEGER PRIMARY KEY AUTOINCREMENT, fp TEXT NOT NULL UNIQUE, rule TEXT NOT NULL, kind TEXT NOT NULL,
    severity TEXT NOT NULL, mac TEXT NOT NULL DEFAULT '', title TEXT NOT NULL, detail TEXT NOT NULL DEFAULT '',
    mitre TEXT NOT NULL DEFAULT '', fix TEXT NOT NULL DEFAULT '', action TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'open', first_ts INTEGER NOT NULL, last_ts INTEGER NOT NULL,
    count INTEGER NOT NULL DEFAULT 1, notified INTEGER NOT NULL DEFAULT 0, resolved_ts INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS alerts_status ON alerts(status, severity);
CREATE TABLE IF NOT EXISTS events (
    id INTEGER PRIMARY KEY AUTOINCREMENT, ts INTEGER NOT NULL, kind TEXT NOT NULL, mac TEXT NOT NULL DEFAULT '', text TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS events_ts ON events(ts);
CREATE TABLE IF NOT EXISTS audit (
    id INTEGER PRIMARY KEY AUTOINCREMENT, ts INTEGER NOT NULL, ip TEXT NOT NULL, action TEXT NOT NULL,
    target TEXT NOT NULL DEFAULT '', ok INTEGER NOT NULL DEFAULT 1, detail TEXT NOT NULL DEFAULT ''
);
CREATE TABLE IF NOT EXISTS traffic (ts INTEGER PRIMARY KEY, down REAL NOT NULL, up REAL NOT NULL);
CREATE TABLE IF NOT EXISTS dev_traffic (ts INTEGER NOT NULL, mac TEXT NOT NULL, down REAL NOT NULL, up REAL NOT NULL, PRIMARY KEY (ts, mac));
CREATE TABLE IF NOT EXISTS login_fail (ip TEXT NOT NULL, ts INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS schedules (
    id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, profile_id INTEGER NOT NULL, days INTEGER NOT NULL,
    start TEXT NOT NULL, stop TEXT NOT NULL, enabled INTEGER NOT NULL DEFAULT 1,
    applied_until INTEGER NOT NULL DEFAULT 0, skip_until INTEGER NOT NULL DEFAULT 0, created INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS kv (k TEXT PRIMARY KEY, v TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS usage (
    day TEXT NOT NULL, hour INTEGER NOT NULL, mac TEXT NOT NULL, service TEXT NOT NULL, minutes INTEGER NOT NULL,
    PRIMARY KEY (day, hour, mac, service)
);
SQL);
        $cols = array_column($pdo->query('PRAGMA table_info(devices)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('owner', $cols, true)) $pdo->exec("ALTER TABLE devices ADD COLUMN owner TEXT NOT NULL DEFAULT ''");
    }

    public static function q(string $sql, array $params = []): PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::q($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $r = self::q($sql, $params)->fetch();
        return $r === false ? null : $r;
    }

    public static function val(string $sql, array $params = []): mixed
    {
        $v = self::q($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function kv(string $k, ?string $default = null): ?string
    {
        $v = self::val('SELECT v FROM kv WHERE k = ?', [$k]);
        return $v === null ? $default : (string)$v;
    }

    public static function setKv(string $k, string $v): void
    {
        self::q('INSERT INTO kv(k, v) VALUES(?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v', [$k, $v]);
    }

    public static function event(string $kind, string $mac, string $text): void
    {
        self::q('INSERT INTO events(ts, kind, mac, text) VALUES(?, ?, ?, ?)', [time(), $kind, $mac, mb_substr($text, 0, 500)]);
    }

    public static function audit(string $action, string $target = '', bool $ok = true, string $detail = ''): void
    {
        try {
            self::q('INSERT INTO audit(ts, ip, action, target, ok, detail) VALUES(?, ?, ?, ?, ?, ?)',
                [time(), PHP_SAPI === 'cli' ? 'console' : client_ip(), $action, mb_substr($target, 0, 200), $ok ? 1 : 0, mb_substr($detail, 0, 1000)]);
        } catch (Throwable) {
            // l'audit ne doit jamais bloquer une action
        }
    }
}
