<?php
/*
 * Sentinelle XDR — client HTTP (cURL). Une poignée par hôte pour garder la connexion ouverte.
 */
declare(strict_types=1);

final class Http
{
    /** @var array<string, CurlHandle> */
    private static array $handles = [];

    /**
     * @param array{timeout?:int,connect_timeout?:int,cainfo?:?string,no_proxy?:bool,userpwd?:string,max_bytes?:int} $opt
     * @return array{status:int, body:string, error:string, type:string, location:string}
     */
    public static function request(string $method, string $url, ?string $body = null, array $headers = [], array $opt = []): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException("L'extension PHP « curl » n'est pas activée (php.ini : extension=curl).");
        }
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        $ch = self::$handles[$host] ??= curl_init();
        curl_reset($ch);

        $location = '';
        $options = [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $opt['connect_timeout'] ?? 4,
            CURLOPT_TIMEOUT        => $opt['timeout'] ?? 12,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERAGENT      => 'SentinelleXDR/' . XDR_VERSION . ' (+reseau-local)',
            CURLOPT_ENCODING       => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$location): int {
                if (stripos($line, 'location:') === 0) $location = trim(substr($line, 9));
                return strlen($line);
            },
        ];
        if ($body !== null) $options[CURLOPT_POSTFIELDS] = $body;
        if (!empty($opt['cainfo'])) {
            $options[CURLOPT_CAINFO] = $opt['cainfo'];
        } elseif (PHP_OS_FAMILY === 'Windows' && !ini_get('curl.cainfo') && defined('CURLSSLOPT_NATIVE_CA')) {
            // Windows : sans curl.cainfo, PHP ne connaît aucune autorité ; on utilise le magasin de Windows.
            $options[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
        }
        if (!empty($opt['no_proxy'])) {
            $options[CURLOPT_PROXY] = '';
            $options[CURLOPT_NOPROXY] = '*';
        }
        if (!empty($opt['userpwd'])) {
            $options[CURLOPT_USERPWD] = $opt['userpwd'];
            $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
        }
        if (!empty($opt['max_bytes'])) {
            $max = (int)$opt['max_bytes'];
            $options[CURLOPT_NOPROGRESS] = false;
            $options[CURLOPT_XFERINFOFUNCTION] = static fn($ch, $dlTotal, $dl) => $dl > $max ? 1 : 0;
        }
        curl_setopt_array($ch, $options);
        $res = curl_exec($ch);
        return [
            'status'   => (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
            'body'     => is_string($res) ? $res : '',
            'error'    => $res === false ? (curl_error($ch) ?: 'erreur réseau') : '',
            'type'     => (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE),
            'location' => $location,
        ];
    }

    /** Vérifie qu'une adresse web publique ne vise pas le réseau interne (anti-SSRF). */
    public static function isPublicUrl(string $url): bool
    {
        $p = parse_url($url);
        if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host'])) return false;
        $host = trim($p['host'], '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (!$ips) return false;
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
        }
        return true;
    }
}
