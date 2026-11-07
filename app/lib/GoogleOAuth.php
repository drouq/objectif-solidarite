<?php
declare(strict_types=1);

/**
 * "Sign in with Google": OAuth 2.0 authorization code flow, written by hand
 * with cURL and OpenSSL (Google's PHP client needs PHP 8).
 *
 * Checks on return: state, nonce, ID token signature (RS256, Google certificates),
 * issuer, audience, expiry and email_verified. TLS verification is always on.
 */
final class GoogleOAuth
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const CERTS_URL = 'https://www.googleapis.com/oauth2/v1/certs';
    private const ISSUERS = ['https://accounts.google.com', 'accounts.google.com'];
    private const CLOCK_SKEW = 120;

    public static function isConfigured(): bool
    {
        return (string) Config::get('google_client_id') !== '' && (string) Config::get('google_client_secret') !== '';
    }

    public static function redirectUri(): string
    {
        return Config::baseUrl() . '/admin/auth/callback';
    }

    /** Address to send the browser to. Stores state and nonce in the session. */
    public static function authorizationUrl(): string
    {
        $state = bin2hex(random_bytes(24));
        $nonce = bin2hex(random_bytes(24));
        $_SESSION['oauth'] = ['state' => $state, 'nonce' => $nonce, 'created' => time()];
        return self::AUTH_URL . '?' . http_build_query([
            'client_id' => (string) Config::get('google_client_id'),
            'redirect_uri' => self::redirectUri(),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'nonce' => $nonce,
            'prompt' => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Handle Google's return. Returns the verified claims (email, name).
     * @param array<string,mixed> $query the callback's GET parameters
     * @return array<string,mixed>
     */
    public static function handleCallback(array $query): array
    {
        $pending = $_SESSION['oauth'] ?? null;
        unset($_SESSION['oauth']); // one use only
        if (isset($query['error'])) {
            throw new UserError('La connexion avec Google a été annulée.');
        }
        if (!is_array($pending) || !isset($query['state'], $query['code'])
            || !is_string($query['state']) || !is_string($query['code'])) {
            throw new UserError('La demande de connexion a expiré. Recommencez.');
        }
        if (!hash_equals((string) $pending['state'], $query['state'])) {
            throw new UserError('La demande de connexion n\'est pas valide. Recommencez.');
        }
        if (time() - (int) $pending['created'] > 600) {
            throw new UserError('La demande de connexion a expiré. Recommencez.');
        }
        $tokens = self::exchangeCode($query['code']);
        if (!isset($tokens['id_token']) || !is_string($tokens['id_token'])) {
            throw new RuntimeException('Google token response has no id_token');
        }
        return self::verifyIdToken($tokens['id_token'], (string) $pending['nonce'], self::certificates());
    }

    /** @return array<string,mixed> */
    private static function exchangeCode(string $code): array
    {
        $response = self::httpRequest(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => (string) Config::get('google_client_id'),
            'client_secret' => (string) Config::get('google_client_secret'),
            'redirect_uri' => self::redirectUri(),
            'grant_type' => 'authorization_code',
        ]);
        $data = json_decode($response['body'], true);
        if ($response['status'] !== 200 || !is_array($data)) {
            throw new RuntimeException('Google token endpoint returned HTTP ' . $response['status'] . ': '
                . substr($response['body'], 0, 300));
        }
        return $data;
    }

    /**
     * Check an ID token and return its claims.
     * @param array<string,string> $certificates key id => PEM certificate
     * @return array<string,mixed>
     */
    public static function verifyIdToken(string $jwt, string $expectedNonce, array $certificates, ?int $now = null): array
    {
        $now = $now ?? time();
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new RuntimeException('Malformed ID token');
        }
        $header = json_decode(self::base64UrlDecode($parts[0]), true);
        $claims = json_decode(self::base64UrlDecode($parts[1]), true);
        $signature = self::base64UrlDecode($parts[2]);
        if (!is_array($header) || !is_array($claims)) {
            throw new RuntimeException('Unreadable ID token');
        }
        if (($header['alg'] ?? '') !== 'RS256') {
            throw new RuntimeException('Unexpected ID token algorithm');
        }
        $kid = (string) ($header['kid'] ?? '');
        if (!isset($certificates[$kid])) {
            throw new RuntimeException('Unknown ID token key');
        }
        $key = openssl_pkey_get_public($certificates[$kid]);
        if ($key === false) {
            throw new RuntimeException('Bad Google certificate');
        }
        $ok = openssl_verify($parts[0] . '.' . $parts[1], $signature, $key, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            throw new RuntimeException('Invalid ID token signature');
        }
        if (!in_array($claims['iss'] ?? '', self::ISSUERS, true)) {
            throw new RuntimeException('Wrong ID token issuer');
        }
        $audience = $claims['aud'] ?? '';
        $clientId = (string) Config::get('google_client_id');
        $audOk = is_array($audience) ? in_array($clientId, $audience, true) : $audience === $clientId;
        if (!$audOk || $clientId === '') {
            throw new RuntimeException('Wrong ID token audience');
        }
        if (!isset($claims['exp']) || (int) $claims['exp'] < $now - self::CLOCK_SKEW) {
            throw new RuntimeException('Expired ID token');
        }
        if (isset($claims['iat']) && (int) $claims['iat'] > $now + self::CLOCK_SKEW) {
            throw new RuntimeException('ID token issued in the future');
        }
        if (!isset($claims['nonce']) || !is_string($claims['nonce']) || !hash_equals($expectedNonce, $claims['nonce'])) {
            throw new RuntimeException('Wrong ID token nonce');
        }
        if (empty($claims['email']) || !is_string($claims['email'])) {
            throw new RuntimeException('ID token without e-mail');
        }
        $verified = $claims['email_verified'] ?? false;
        if ($verified !== true && $verified !== 'true') {
            throw new UserError('L\'adresse e-mail de ce compte Google n\'est pas vérifiée.');
        }
        return $claims;
    }

    /**
     * Google's public certificates, cached in data/cache as long as Google allows.
     * @return array<string,string>
     */
    private static function certificates(): array
    {
        $cacheFile = DATA_PATH . '/cache/google-certs.json';
        if (is_file($cacheFile)) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached) && isset($cached['expires'], $cached['certs']) && $cached['expires'] > time()) {
                return $cached['certs'];
            }
        }
        $response = self::httpRequest(self::CERTS_URL, null);
        $certs = json_decode($response['body'], true);
        if ($response['status'] !== 200 || !is_array($certs) || !$certs) {
            throw new RuntimeException('Cannot download Google certificates (HTTP ' . $response['status'] . ')');
        }
        $maxAge = 3600;
        if (preg_match('/max-age=(\d+)/i', $response['headers'], $m)) {
            $maxAge = min(86400, max(300, (int) $m[1]));
        }
        ensure_dir(dirname($cacheFile));
        @file_put_contents($cacheFile, json_encode(['expires' => time() + $maxAge, 'certs' => $certs]));
        return $certs;
    }

    /**
     * HTTPS request with certificate verification. POST when $form is given.
     * @param array<string,string>|null $form
     * @return array{status:int,body:string,headers:string}
     */
    private static function httpRequest(string $url, ?array $form): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL extension missing');
        }
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_SSL_VERIFYPEER => true, // never disable
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'site-admin-login/1.0',
        ];
        if ($form !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query($form, '', '&');
            $options[CURLOPT_HTTPHEADER] = ['Content-Type: application/x-www-form-urlencoded'];
        }
        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            throw new RuntimeException('HTTPS request to Google failed: ' . $error);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        return [
            'status' => $status,
            'headers' => substr((string) $raw, 0, $headerSize),
            'body' => (string) substr((string) $raw, $headerSize),
        ];
    }

    public static function base64UrlDecode(string $data): string
    {
        $data = strtr($data, '-_', '+/');
        $pad = strlen($data) % 4;
        if ($pad > 0) {
            $data .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode($data, true);
        return $decoded === false ? '' : $decoded;
    }
}
