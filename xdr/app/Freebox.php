<?php
/*
 * Sentinelle XDR — client de l'API Freebox OS (Freebox Ultra, Delta, Pop, Révolution).
 *
 * Association (une seule fois) : POST login/authorize/ → la Freebox affiche une demande sur son
 * écran → on appuie sur la flèche droite → GET login/authorize/{track_id} passe à « granted ».
 * Session : GET login/ (challenge) puis POST login/session/ avec password = HMAC-SHA1(app_token, challenge).
 * Chaque appel porte ensuite l'en-tête X-Fbx-App-Auth.
 */
declare(strict_types=1);

final class FreeboxError extends RuntimeException
{
    public function __construct(string $message, public string $codeName = 'error', public string $hint = '', public int $http = 502)
    {
        parent::__construct($message);
    }
}

interface Transport
{
    /** @return array{status:int, body:string} */
    public function send(string $method, string $url, ?string $body, array $headers): array;
}

final class CurlTransport implements Transport
{
    public function send(string $method, string $url, ?string $body, array $headers): array
    {
        $https = str_starts_with($url, 'https:');
        $r = Http::request($method, $url, $body, $headers, [
            'cainfo'   => $https ? XDR_ROOT . '/certs/freebox-ca.pem' : null,
            'no_proxy' => true,
            'timeout'  => 10,
        ]);
        if ($r['error'] !== '') {
            throw new FreeboxError('Freebox injoignable : ' . $r['error'], 'unreachable',
                'Vérifiez que ce serveur est relié à la Freebox (câble ou Wi-Fi) et que l\'adresse dans Paramètres › Freebox est correcte.', 504);
        }
        return $r;
    }
}

final class Freebox
{
    public const APP_ID = 'fr.sentinelle.xdr';
    public const APP_NAME = 'Sentinelle XDR';

    /** Droits Freebox OS utiles à Sentinelle, avec leur libellé dans « Gestion des accès ». */
    public const PERMISSIONS = [
        'settings' => 'Modification des réglages de la Freebox',
        'parental' => 'Accès au contrôle parental',
        'profile'  => 'Gestion des profils utilisateur',
        'player'   => 'Contrôle du Freebox Player',
        'tv'       => 'Accès au guide TV',
    ];

    private array $state;
    private ?string $session = null;
    private array $perms = [];
    private string $stateFile;

    public function __construct(private Transport $t, private bool $demo)
    {
        $this->stateFile = data_path($demo ? 'demo-link.json' : 'freebox.json');
        $this->state = read_json($this->stateFile);
        if ($demo && empty($this->state['app_token'])) {
            $this->state = ['app_token' => DemoBox::APP_TOKEN, 'app_id' => self::APP_ID, 'paired_at' => time()];
            $this->save();
        }
    }

    public static function make(): self
    {
        return Network::isDemo() ? new self(new DemoBox(), true) : new self(new CurlTransport(), false);
    }

    public function isDemo(): bool
    {
        return $this->demo;
    }

    public function paired(): bool
    {
        return !empty($this->state['app_token']);
    }

    public function info(): array
    {
        return [
            'paired'    => $this->paired(),
            'paired_at' => $this->state['paired_at'] ?? null,
            'base'      => $this->state['base'] ?? null,
            'encrypted' => str_starts_with((string)($this->state['base'] ?? ''), 'https:'),
            'api'       => $this->state['disc'] ?? null,
            'pending'   => !empty($this->state['track_id']) && !empty($this->state['pending_token']),
        ];
    }

    private function save(): void
    {
        write_json($this->stateFile, $this->state);
    }

    /* ------------------------------------------------------------------ découverte */

    public function discover(bool $force = false): array
    {
        if (!$force && !empty($this->state['base']) && ($this->state['disc_ts'] ?? 0) > time() - 6 * 3600) {
            return $this->state['disc'] ?? [];
        }
        $cands = [$this->demo ? 'https://mafreebox.freebox.fr' : rtrim((string)Settings::get('freebox_url'), '/'), 'https://mafreebox.freebox.fr'];
        if (!$this->demo && Settings::get('freebox_http_fallback')) {
            $cands[] = 'http://mafreebox.freebox.fr';
            $cands[] = 'http://192.168.1.254';
        }
        $errors = [];
        foreach (array_values(array_unique(array_filter($cands))) as $host) {
            try {
                $r = $this->t->send('GET', $host . '/api_version', null, ['Accept: application/json']);
                $j = json_decode($r['body'], true);
                if (!is_array($j) || !isset($j['api_version'], $j['api_base_url'])) {
                    $errors[] = "$host : réponse qui ne vient pas d'une Freebox";
                    continue;
                }
                $major = (int)explode('.', (string)$j['api_version'])[0];
                $want = (string)Settings::get('api_version');
                if ($want !== 'auto' && preg_match('/^v?(\d+)$/', $want, $m)) $major = min($major, (int)$m[1]);
                $this->state['base'] = $host . '/' . trim((string)$j['api_base_url'], '/') . '/v' . max(1, $major) . '/';
                $this->state['disc'] = [
                    'device_name' => (string)($j['device_name'] ?? 'Freebox'),
                    'device_type' => (string)($j['device_type'] ?? ''),
                    'api_version' => (string)$j['api_version'],
                    'api_domain'  => (string)($j['api_domain'] ?? ''),
                    'https'       => (bool)($j['https_available'] ?? false),
                    'box_model'   => (string)($j['box_model_name'] ?? $j['box_model'] ?? ''),
                ];
                $this->state['disc_ts'] = time();
                $this->save();
                return $this->state['disc'];
            } catch (FreeboxError $e) {
                $errors[] = "$host : " . $e->getMessage();
            }
        }
        throw new FreeboxError('Aucune Freebox trouvée sur le réseau.', 'unreachable',
            'Essais : ' . implode(' · ', $errors) . '. Ce serveur doit être branché sur le réseau de la Freebox.', 504);
    }

    /* ------------------------------------------------------------------ association */

    public function pairStart(string $deviceName): array
    {
        $this->discover(true);
        $res = $this->raw('POST', 'login/authorize/', [
            'app_id'      => self::APP_ID,
            'app_name'    => self::APP_NAME,
            'app_version' => XDR_VERSION,
            'device_name' => mb_substr($deviceName, 0, 40),
        ]);
        if (empty($res['app_token']) || !isset($res['track_id'])) {
            throw new FreeboxError('La Freebox n\'a pas accepté la demande d\'association.', 'pair_failed');
        }
        $this->state['pending_token'] = (string)$res['app_token'];
        $this->state['track_id'] = (int)$res['track_id'];
        $this->state['pair_started'] = time();
        $this->save();
        return ['track_id' => (int)$res['track_id']];
    }

    public function pairStatus(): array
    {
        $tid = $this->state['track_id'] ?? null;
        if ($tid === null || empty($this->state['pending_token'])) {
            return ['status' => $this->paired() ? 'granted' : 'none'];
        }
        $res = $this->raw('GET', 'login/authorize/' . (int)$tid);
        $status = (string)($res['status'] ?? 'unknown');
        if ($status === 'granted') {
            $this->state['app_token'] = $this->state['pending_token'];
            $this->state['app_id'] = self::APP_ID;
            $this->state['paired_at'] = time();
            unset($this->state['pending_token'], $this->state['track_id']);
            $this->save();
            $this->dropSession();
        } elseif (in_array($status, ['denied', 'timeout', 'unknown'], true)) {
            unset($this->state['pending_token'], $this->state['track_id']);
            $this->save();
        }
        return ['status' => $status];
    }

    public function unpair(): void
    {
        $this->state = [];
        $this->save();
        $this->dropSession();
    }

    /* ------------------------------------------------------------------ session */

    public function permissions(): array
    {
        $this->sessionToken();
        return $this->perms;
    }

    private function sessionFile(): string
    {
        return data_path($this->demo ? 'cache/demo-session.json' : 'fbx-session.json');
    }

    private function sessionToken(): string
    {
        if ($this->session !== null) return $this->session;
        $s = read_json($this->sessionFile());
        if (!empty($s['token']) && ($s['ts'] ?? 0) > time() - 1200 && ($s['base'] ?? '') === ($this->state['base'] ?? '')) {
            $this->perms = (array)($s['perms'] ?? []);
            return $this->session = (string)$s['token'];
        }
        return $this->openSession();
    }

    private function openSession(): string
    {
        $token = (string)($this->state['app_token'] ?? '');
        if ($token === '') {
            throw new FreeboxError('La Freebox n\'est pas encore associée à Sentinelle.', 'not_paired',
                'Ouvrez Paramètres › Freebox › « Associer ma Freebox ».', 409);
        }
        $login = $this->raw('GET', 'login/');
        $challenge = (string)($login['challenge'] ?? '');
        $res = $this->raw('POST', 'login/session/', [
            'app_id'      => (string)($this->state['app_id'] ?? self::APP_ID),
            'app_version' => XDR_VERSION,
            'password'    => hash_hmac('sha1', $challenge, $token),
        ]);
        $this->session = (string)($res['session_token'] ?? '');
        if ($this->session === '') throw new FreeboxError('Ouverture de session refusée par la Freebox.', 'session_failed');
        $this->perms = (array)($res['permissions'] ?? []);
        write_json($this->sessionFile(), ['token' => $this->session, 'perms' => $this->perms, 'ts' => time(), 'base' => $this->state['base'] ?? '']);
        return $this->session;
    }

    private function dropSession(): void
    {
        $this->session = null;
        @unlink($this->sessionFile());
    }

    /* ------------------------------------------------------------------ appels */

    /** Appel authentifié. Le chemin est relatif à /api/vN/ (ex. « lan/browser/pub/ »). */
    public function call(string $method, string $path, ?array $body = null, bool $retry = true): mixed
    {
        $this->discover();
        $headers = ['Accept: application/json', 'X-Fbx-App-Auth: ' . $this->sessionToken()];
        $payload = null;
        if ($body !== null) {
            $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $headers[] = 'Content-Type: application/json';
        }
        $r = $this->t->send($method, $this->state['base'] . ltrim($path, '/'), $payload, $headers);
        $j = json_decode($r['body'], true);
        if (!is_array($j)) throw new FreeboxError("Réponse inattendue de la Freebox (HTTP {$r['status']}) pour $path.", 'bad_response');
        if (empty($j['success'])) {
            $code = (string)($j['error_code'] ?? 'error');
            if ($retry && in_array($code, ['auth_required', 'invalid_session'], true)) {
                $this->dropSession();
                return $this->call($method, $path, $body, false);
            }
            if ($code === 'invalid_token') {
                $this->state = [];
                $this->save();
                $this->dropSession();
            }
            throw self::error($code, (string)($j['msg'] ?? ''), $path);
        }
        return $j['result'] ?? null;
    }

    public function get(string $path): mixed
    {
        return $this->call('GET', $path);
    }

    /** GET qui ne fait pas échouer la page : renvoie $default si l'appel échoue. */
    public function tryGet(string $path, mixed $default = null, ?array &$errors = null): mixed
    {
        try {
            return $this->call('GET', $path);
        } catch (FreeboxError $e) {
            if ($errors !== null) $errors[$path] = $e->getMessage() . ($e->hint ? ' — ' . $e->hint : '');
            return $default;
        }
    }

    /** Lecture d'un fichier de la box (logos des chaînes) : corps brut, pas de JSON. */
    public function binary(string $path): array
    {
        $this->discover();
        $r = $this->t->send('GET', $this->state['base'] . ltrim($path, '/'), null, ['X-Fbx-App-Auth: ' . $this->sessionToken()]);
        return ['status' => (int)$r['status'], 'body' => (string)$r['body']];
    }

    /** Appel sans session (découverte, association). */
    private function raw(string $method, string $path, ?array $body = null): array
    {
        if (empty($this->state['base'])) $this->discover(true);
        $headers = ['Accept: application/json'];
        $payload = null;
        if ($body !== null) {
            $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $headers[] = 'Content-Type: application/json';
        }
        $r = $this->t->send($method, $this->state['base'] . ltrim($path, '/'), $payload, $headers);
        $j = json_decode($r['body'], true);
        if (!is_array($j)) throw new FreeboxError("Réponse inattendue de la Freebox (HTTP {$r['status']}).", 'bad_response');
        if (empty($j['success'])) throw self::error((string)($j['error_code'] ?? 'error'), (string)($j['msg'] ?? ''), $path);
        return (array)($j['result'] ?? []);
    }

    private static function error(string $code, string $msg, string $path): FreeboxError
    {
        return match ($code) {
            'insufficient_rights' => new FreeboxError('Droit manquant sur la Freebox pour cette action.', $code, self::rightsHint($path), 403),
            'invalid_token' => new FreeboxError('La Freebox a révoqué l\'accès de Sentinelle.', $code, 'Réassociez la Freebox dans Paramètres › Freebox.', 409),
            'denied_from_external_ip' => new FreeboxError('La Freebox refuse cet accès depuis l\'extérieur.', $code, 'Sentinelle doit tourner sur le réseau local de la Freebox.', 403),
            'new_apps_denied', 'apps_denied' => new FreeboxError('Les nouvelles associations d\'applications sont désactivées sur la Freebox.', $code,
                'Freebox OS › Paramètres de la Freebox › Gestion des accès › Paramètres : autorisez les nouvelles demandes d\'association, associez Sentinelle, puis désactivez-les à nouveau.', 409),
            'ratelimited' => new FreeboxError('La Freebox limite le nombre d\'appels : réessayez dans quelques secondes.', $code, '', 429),
            'nodev', 'noent' => new FreeboxError('Élément introuvable sur la Freebox (' . ($msg ?: $code) . ').', $code, '', 404),
            default => new FreeboxError('La Freebox a répondu : ' . ($msg !== '' ? $msg : $code) . " ($path)", $code, '', 502),
        };
    }

    private static function rightsHint(string $path): string
    {
        $need = match (explode('/', ltrim($path, '/'))[0]) {
            'network_control', 'profile', 'parental' => '« ' . self::PERMISSIONS['settings'] . ' », « ' . self::PERMISSIONS['parental'] . ' » et « ' . self::PERMISSIONS['profile'] . ' »',
            'player' => '« ' . self::PERMISSIONS['player'] . ' »',
            'tv' => '« ' . self::PERMISSIONS['tv'] . ' »',
            default  => '« ' . self::PERMISSIONS['settings'] . ' »',
        };
        return 'Dans Freebox OS : Paramètres de la Freebox › Gestion des accès › onglet Applications › « Sentinelle XDR » › crayon : cochez '
            . $need . ', validez, puis réessayez.';
    }
}
