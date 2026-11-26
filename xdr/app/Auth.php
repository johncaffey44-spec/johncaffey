<?php
/*
 * Sentinelle XDR — connexion : mot de passe haché (password_hash), session courte, jeton anti-CSRF,
 * limitation des essais, double authentification TOTP facultative (Google/Microsoft Authenticator…).
 */
declare(strict_types=1);

final class Auth
{
    private const MIN_LEN = 10;
    private const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function start(): void
    {
        $dir = data_path('sessions');
        if (is_dir($dir) && is_writable($dir)) session_save_path($dir);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.gc_maxlifetime', (string)((int)Cfg::get('session_max_hours') * 3600));
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
        $path = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/') . '/';
        session_name('SENTINELLE');
        session_set_cookie_params(['lifetime' => 0, 'path' => $path, 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Strict']);
        if (!@session_start()) throw new RuntimeException('Sessions PHP indisponibles : le dossier data/sessions doit être accessible en écriture.');
    }

    public static function admin(): ?array
    {
        $a = read_json(data_path('admin.json'));
        return !empty($a['hash']) ? $a : null;
    }

    public static function needsSetup(): bool
    {
        return self::admin() === null;
    }

    public static function checkPolicy(string $pw): void
    {
        if (mb_strlen($pw) < self::MIN_LEN) {
            throw new InvalidArgumentException('Mot de passe trop court : 10 caractères minimum. Une phrase de passe (« quatre mots au hasard ») est idéale.');
        }
        $weak = ['motdepasse', 'azertyuiop', '1234567890', 'password', 'administrateur', 'sentinelle', 'freebox'];
        foreach ($weak as $w) {
            if (str_contains(mb_strtolower($pw), $w) && mb_strlen($pw) < mb_strlen($w) + 6) {
                throw new InvalidArgumentException('Mot de passe trop prévisible : évitez les mots évidents (« ' . $w . ' »).');
            }
        }
        if (count(array_unique(mb_str_split($pw))) < 5) throw new InvalidArgumentException('Mot de passe trop répétitif.');
    }

    public static function setup(string $pw): void
    {
        if (!self::needsSetup()) throw new RuntimeException('Sentinelle est déjà configurée.', 409);
        self::checkPolicy($pw);
        write_json(data_path('admin.json'), ['hash' => password_hash($pw, PASSWORD_DEFAULT), 'created' => time(), 'pw_changed' => time(), 'totp' => null]);
        Db::audit('setup', '', true, 'Mot de passe administrateur créé');
    }

    /** Session valide ? (inactivité, durée maximale, mot de passe changé depuis) */
    public static function user(): ?array
    {
        $s = $_SESSION['auth'] ?? null;
        if (!is_array($s)) return null;
        $now = time();
        $a = self::admin();
        if ($a === null
            || $now - (int)($s['last'] ?? 0) > (int)Cfg::get('session_idle_minutes') * 60
            || $now - (int)($s['since'] ?? 0) > (int)Cfg::get('session_max_hours') * 3600
            || (int)($a['pw_changed'] ?? 0) > (int)($s['since'] ?? 0)) {
            self::logout();
            return null;
        }
        $_SESSION['auth']['last'] = $now;
        return ['since' => (int)$s['since'], 'totp' => !empty($a['totp']['enabled'])];
    }

    public static function totpEnabled(): bool
    {
        return !empty(self::admin()['totp']['enabled']);
    }

    public static function login(string $pw, string $code): array
    {
        $ip = client_ip();
        $now = time();
        $ipFails = (int)Db::val('SELECT COUNT(*) FROM login_fail WHERE ip = ? AND ts > ?', [$ip, $now - 900]);
        $allFails = (int)Db::val('SELECT COUNT(*) FROM login_fail WHERE ts > ?', [$now - 900]);
        if ($ipFails >= 5 || $allFails >= 30) {
            Db::audit('login', '', false, 'refusé : trop d\'essais');
            throw new RuntimeException('Trop d\'essais erronés : patientez 15 minutes.', 429);
        }
        $a = self::admin();
        $ok = $a !== null && password_verify($pw, (string)$a['hash']);
        if ($ok && !empty($a['totp']['enabled'])) {
            $ok = self::totpCheck((string)$a['totp']['secret'], $code) || self::useRecovery($code);
        }
        if (!$ok) {
            Db::q('INSERT INTO login_fail(ip, ts) VALUES(?, ?)', [$ip, $now]);
            Db::audit('login', '', false, 'identifiants erronés');
            usleep(random_int(250_000, 600_000));
            throw new RuntimeException('Mot de passe' . (!empty($a['totp']['enabled']) ? ' ou code' : '') . ' incorrect.', 401);
        }
        if (password_needs_rehash((string)$a['hash'], PASSWORD_DEFAULT)) {
            $a['hash'] = password_hash($pw, PASSWORD_DEFAULT);
            write_json(data_path('admin.json'), $a);
        }
        session_regenerate_id(true);
        $_SESSION = ['auth' => ['since' => $now, 'last' => $now], 'csrf' => bin2hex(random_bytes(32))];
        Db::q('DELETE FROM login_fail WHERE ip = ?', [$ip]);
        Db::audit('login', '', true);
        return ['ok' => true];
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Strict']);
            session_destroy();
        }
    }

    public static function csrf(): string
    {
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function checkCsrf(): void
    {
        $h = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if ($h === '' || empty($_SESSION['csrf']) || !hash_equals((string)$_SESSION['csrf'], $h)) {
            throw new RuntimeException('Jeton de sécurité invalide : rechargez la page.', 403);
        }
        $site = (string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
        if ($site !== '' && !in_array($site, ['same-origin', 'none'], true)) throw new RuntimeException('Requête venant d\'un autre site refusée.', 403);
        $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($origin !== '' && strtolower((string)parse_url($origin, PHP_URL_HOST)) !== strtolower((string)parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST))) {
            throw new RuntimeException('Origine de la requête refusée.', 403);
        }
    }

    /** Ré-authentification pour les actions sensibles (redémarrage, dissociation, mot de passe…). */
    public static function confirm(string $pw): void
    {
        $a = self::admin();
        if ($a === null || !password_verify($pw, (string)$a['hash'])) {
            Db::q('INSERT INTO login_fail(ip, ts) VALUES(?, ?)', [client_ip(), time()]);
            throw new RuntimeException('Mot de passe incorrect.', 403);
        }
    }

    public static function changePassword(string $current, string $next): void
    {
        self::confirm($current);
        self::checkPolicy($next);
        $a = self::admin();
        $a['hash'] = password_hash($next, PASSWORD_DEFAULT);
        $a['pw_changed'] = time();
        write_json(data_path('admin.json'), $a);
        $_SESSION['auth']['since'] = time();
        Db::audit('password_change', '', true);
    }

    /* ------------------------------------------------------------------ double authentification */

    public static function totpBegin(): array
    {
        $secret = self::b32encode(random_bytes(20));
        $_SESSION['totp_pending'] = $secret;
        $label = rawurlencode('Sentinelle XDR:admin');
        return ['secret' => trim(chunk_split($secret, 4, ' ')),
            'uri' => "otpauth://totp/$label?secret=$secret&issuer=" . rawurlencode('Sentinelle XDR') . '&digits=6&period=30'];
    }

    public static function totpEnable(string $pw, string $code): array
    {
        self::confirm($pw);
        $secret = (string)($_SESSION['totp_pending'] ?? '');
        if ($secret === '' || !self::totpCheck($secret, $code)) throw new RuntimeException('Code incorrect : vérifiez l\'heure du téléphone et réessayez.', 400);
        $codes = [];
        for ($i = 0; $i < 8; $i++) $codes[] = strtolower(substr(self::b32encode(random_bytes(10)), 0, 5) . '-' . substr(self::b32encode(random_bytes(10)), 0, 5));
        $a = self::admin();
        $a['totp'] = ['enabled' => true, 'secret' => $secret, 'since' => time(), 'recovery' => array_map(fn($c) => hash('sha256', $c), $codes)];
        write_json(data_path('admin.json'), $a);
        unset($_SESSION['totp_pending']);
        Db::audit('totp_enable', '', true);
        return ['ok' => true, 'recovery' => $codes];
    }

    public static function totpDisable(string $pw): void
    {
        self::confirm($pw);
        $a = self::admin();
        $a['totp'] = null;
        write_json(data_path('admin.json'), $a);
        Db::audit('totp_disable', '', true);
    }

    public static function totpCheck(string $secret, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{6}$/', $code)) return false;
        $key = self::b32decode($secret);
        $t = intdiv(time(), 30);
        for ($i = -1; $i <= 1; $i++) {
            if (hash_equals(self::hotp($key, $t + $i), $code)) return true;
        }
        return false;
    }

    private static function useRecovery(string $code): bool
    {
        $code = strtolower(trim($code));
        if (!preg_match('/^[a-z2-7]{5}-[a-z2-7]{5}$/', $code)) return false;
        $a = self::admin();
        $list = (array)($a['totp']['recovery'] ?? []);
        $h = hash('sha256', $code);
        foreach ($list as $i => $stored) {
            if (hash_equals((string)$stored, $h)) {
                unset($list[$i]);
                $a['totp']['recovery'] = array_values($list);
                write_json(data_path('admin.json'), $a);
                Db::audit('totp_recovery', '', true, 'code de secours utilisé (' . count($list) . ' restants)');
                return true;
            }
        }
        return false;
    }

    public static function hotp(string $key, int $counter): string
    {
        $h = hash_hmac('sha1', pack('J', $counter), $key, true);
        $o = ord($h[19]) & 0x0f;
        $v = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
        return str_pad((string)($v % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    public static function b32encode(string $bin): string
    {
        $bits = '';
        foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            if ($chunk !== '') $out .= self::B32[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    public static function b32decode(string $s): string
    {
        $s = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s) ?? '');
        $bits = '';
        foreach ($s === '' ? [] : str_split($s) as $c) {
            $v = strpos(self::B32, $c);
            if ($v !== false) $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach ($bits === '' ? [] : str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) $out .= chr(bindec($byte));
        }
        return $out;
    }
}
