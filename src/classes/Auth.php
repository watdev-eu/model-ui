<?php
// classes/Auth.php

declare(strict_types=1);

final class Auth
{
    public static function isLoggedIn(): bool
    {
        return !empty($_SESSION['auth']['user']);
    }

    public static function user(): ?array
    {
        return $_SESSION['auth']['user'] ?? null;
    }

    public static function roles(): array
    {
        $user = self::user();
        if (!$user) return [];
        return array_values(array_unique(array_map('strval', $user['roles'] ?? [])));
    }

    public static function hasRole(string $role): bool
    {
        return in_array($role, self::roles(), true);
    }

    public static function isAdmin(): bool
    {
        return self::hasRole('admin');
    }

    public static function isAdvanced(): bool
    {
        return self::hasRole('advanced') || self::isAdmin();
    }

    public static function isBasic(): bool
    {
        return self::hasRole('basic') || self::isAdvanced() || self::isAdmin();
    }

    public static function effectiveRole(): ?string
    {
        if (self::isAdmin()) return 'admin';
        if (self::isAdvanced()) return 'advanced';
        if (self::isBasic()) return 'basic';
        return null;
    }

    public static function roleBadgeMeta(): array
    {
        return match (self::effectiveRole()) {
            'admin' => [
                'label' => 'Admin',
                'class' => 'text-bg-danger',
                'icon'  => 'shield-lock',
            ],
            'advanced' => [
                'label' => 'Advanced user',
                'class' => 'text-bg-primary',
                'icon'  => 'stars',
            ],
            'basic' => [
                'label' => 'Basic user',
                'class' => 'text-bg-secondary',
                'icon'  => 'person',
            ],
            default => [
                'label' => 'Guest',
                'class' => 'text-bg-dark',
                'icon'  => 'person-circle',
            ],
        };
    }

    public static function requireLogin(): void
    {
        if (self::isLoggedIn()) return;

        $_SESSION['post_login_redirect'] = self::currentUrlPathWithQuery();
        header('Location: /login.php');
        exit;
    }

    public static function requireAdvanced(): void
    {
        self::requireLogin();

        if (!self::isAdvanced()) {
            http_response_code(403);
            exit('Forbidden');
        }
    }

    public static function requireAdmin(): void
    {
        self::requireLogin();

        if (!self::isAdmin()) {
            http_response_code(403);
            exit('Forbidden');
        }
    }

    public static function requireRole(string $role): void
    {
        self::requireLogin();

        if (!self::hasRole($role)) {
            http_response_code(403);
            exit('Forbidden');
        }
    }

    public static function loginUrl(): string
    {
        $state = bin2hex(random_bytes(16));
        $_SESSION['oidc_state'] = $state;

        $params = [
            'client_id'     => self::clientId(),
            'redirect_uri'  => self::redirectUri(),
            'response_type' => 'code',
            'scope'         => 'openid profile email',
            'state'         => $state,
        ];

        return self::authorizationEndpoint() . '?' . http_build_query($params);
    }

    public static function handleCallback(string $code, string $state): void
    {
        $expectedState = (string)($_SESSION['oidc_state'] ?? '');
        unset($_SESSION['oidc_state']);

        if ($expectedState === '' || !hash_equals($expectedState, $state)) {
            throw new RuntimeException('Invalid login state.');
        }

        $token = self::exchangeAuthorizationCode($code);

        if (empty($token['access_token']) || empty($token['id_token'])) {
            throw new RuntimeException('Missing tokens in token response.');
        }

        $accessPayload = self::decodeAndVerifyJwt((string)$token['access_token']);
        $idPayload     = self::decodeAndVerifyJwt((string)$token['id_token']);

        self::validateTokenPayload($accessPayload);
        self::validateTokenPayload($idPayload);

        $roles = self::extractRoles($accessPayload);

        $displayName = self::firstNonEmpty(
            $idPayload['name'] ?? null,
            trim((string)(($idPayload['given_name'] ?? '') . ' ' . ($idPayload['family_name'] ?? ''))),
            $idPayload['preferred_username'] ?? null,
            $idPayload['email'] ?? null,
            'User'
        );

        $_SESSION['auth'] = [
            'logged_in_at' => time(),
            'tokens' => [
                'access_token'  => (string)$token['access_token'],
                'id_token'      => (string)$token['id_token'],
                'refresh_token' => (string)($token['refresh_token'] ?? ''),
                'expires_in'    => (int)($token['expires_in'] ?? 0),
            ],
            'user' => [
                'sub'                => (string)($idPayload['sub'] ?? ''),
                'display_name'       => $displayName,
                'preferred_username' => (string)($idPayload['preferred_username'] ?? ''),
                'email'              => (string)($idPayload['email'] ?? ''),
                'roles'              => $roles,
            ],
        ];
    }

    public static function logout(): void
    {
        $idToken = $_SESSION['auth']['tokens']['id_token'] ?? null;

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                (bool)$params['secure'],
                (bool)$params['httponly']
            );
        }

        session_destroy();

        header('Location: ' . self::logoutUrl(is_string($idToken) ? $idToken : null));
        exit;
    }

    private static function decodeAndVerifyJwt(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new RuntimeException('Invalid JWT format.');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        $headerJson = self::base64UrlDecode($encodedHeader);
        $header = json_decode($headerJson, true);
        if (!is_array($header)) {
            throw new RuntimeException('Invalid JWT header.');
        }

        $alg = (string)($header['alg'] ?? '');
        if ($alg !== 'RS256') {
            throw new RuntimeException('Unsupported JWT alg: ' . $alg);
        }

        $kid = (string)($header['kid'] ?? '');
        if ($kid === '') {
            throw new RuntimeException('Missing JWT kid.');
        }

        $jwk = self::findJwkByKid($kid);
        $pem = self::jwkToPem($jwk);

        $signedData = $encodedHeader . '.' . $encodedPayload;
        $signature = self::base64UrlDecode($encodedSignature);

        $ok = openssl_verify($signedData, $signature, $pem, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            throw new RuntimeException('JWT signature verification failed.');
        }

        $payloadJson = self::base64UrlDecode($encodedPayload);
        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            throw new RuntimeException('Invalid JWT payload.');
        }

        return $payload;
    }

    private static function findJwkByKid(string $kid): array
    {
        $jwks = self::jwks(false);

        foreach (($jwks['keys'] ?? []) as $jwk) {
            if (is_array($jwk) && (($jwk['kid'] ?? null) === $kid)) {
                return $jwk;
            }
        }

        $jwks = self::jwks(true);

        foreach (($jwks['keys'] ?? []) as $jwk) {
            if (is_array($jwk) && (($jwk['kid'] ?? null) === $kid)) {
                return $jwk;
            }
        }

        throw new RuntimeException('No matching JWKS key found for kid: ' . $kid);
    }

    private static function jwks(bool $forceRefresh = false): array
    {
        $cacheFile = self::jwksCacheFile();
        $cacheTtl  = 3600;

        if (!$forceRefresh && is_readable($cacheFile)) {
            $cached = json_decode((string)file_get_contents($cacheFile), true);

            if (is_array($cached)) {
                $fetchedAt = (int)($cached['fetched_at'] ?? 0);
                $data = $cached['data'] ?? null;

                if ($fetchedAt > 0 && (time() - $fetchedAt) < $cacheTtl && is_array($data)) {
                    return $data;
                }
            }
        }

        $response = self::httpGet(self::jwksEndpoint());
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException('Failed to fetch JWKS: HTTP ' . $response['status']);
        }

        $json = json_decode($response['body'], true);
        if (!is_array($json) || !isset($json['keys']) || !is_array($json['keys'])) {
            throw new RuntimeException('Invalid JWKS response.');
        }

        $dir = dirname($cacheFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        @file_put_contents($cacheFile, json_encode([
            'fetched_at' => time(),
            'data' => $json,
        ], JSON_PRETTY_PRINT));

        return $json;
    }

    private static function jwksEndpoint(): string
    {
        return self::issuer() . '/protocol/openid-connect/certs';
    }

    private static function jwksCacheFile(): string
    {
        return __DIR__ . '/../var/jwks_cache.json';
    }

    private static function httpGet(string $url): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('cURL is required for authentication.');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 20,
        ]);

        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('HTTP GET failed: ' . $err);
        }

        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [
            'status' => $status,
            'body'   => $body,
        ];
    }

    private static function jwkToPem(array $jwk): string
    {
        if (($jwk['kty'] ?? '') !== 'RSA') {
            throw new RuntimeException('Only RSA JWKS keys are supported.');
        }

        if (isset($jwk['use']) && $jwk['use'] !== 'sig') {
            throw new RuntimeException('JWKS key is not for signatures.');
        }

        if (isset($jwk['alg']) && $jwk['alg'] !== 'RS256') {
            throw new RuntimeException('Unexpected JWKS alg.');
        }

        $n = $jwk['n'] ?? null;
        $e = $jwk['e'] ?? null;

        if (!is_string($n) || !is_string($e) || $n === '' || $e === '') {
            throw new RuntimeException('Invalid RSA JWK.');
        }

        $modulus  = self::base64UrlDecode($n);
        $exponent = self::base64UrlDecode($e);

        $rsaPublicKey = self::asn1Sequence(
            self::asn1Integer($modulus),
            self::asn1Integer($exponent)
        );

        $bitString = "\x03" . self::asn1Length(strlen($rsaPublicKey) + 1) . "\x00" . $rsaPublicKey;

        $algorithmIdentifier = self::asn1Sequence(
            self::asn1ObjectIdentifier("\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01"),
            "\x05\x00"
        );

        $subjectPublicKeyInfo = self::asn1Sequence(
            $algorithmIdentifier,
            $bitString
        );

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($subjectPublicKeyInfo), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private static function asn1Sequence(string ...$parts): string
    {
        $data = implode('', $parts);
        return "\x30" . self::asn1Length(strlen($data)) . $data;
    }

    private static function asn1Integer(string $value): string
    {
        if ($value === '') {
            $value = "\x00";
        }

        if ((ord($value[0]) & 0x80) !== 0) {
            $value = "\x00" . $value;
        }

        return "\x02" . self::asn1Length(strlen($value)) . $value;
    }

    private static function asn1ObjectIdentifier(string $oidBinary): string
    {
        return "\x06" . self::asn1Length(strlen($oidBinary)) . $oidBinary;
    }

    private static function asn1Length(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }

        $temp = '';
        while ($length > 0) {
            $temp = chr($length & 0xFF) . $temp;
            $length >>= 8;
        }

        return chr(0x80 | strlen($temp)) . $temp;
    }

    private static function exchangeAuthorizationCode(string $code): array
    {
        $response = self::httpPostForm(self::tokenEndpoint(), [
            'grant_type'    => 'authorization_code',
            'client_id'     => self::clientId(),
            'client_secret' => self::clientSecret(),
            'code'          => $code,
            'redirect_uri'  => self::redirectUri(),
        ]);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException('Token exchange failed with HTTP ' . $response['status']);
        }

        $json = json_decode($response['body'], true);
        if (!is_array($json)) {
            throw new RuntimeException('Invalid token response.');
        }

        return $json;
    }

    private static function validateTokenPayload(array $payload): void
    {
        $issuer = self::issuer();

        if (($payload['iss'] ?? '') !== $issuer) {
            throw new RuntimeException('Invalid token issuer.');
        }

        $now = time();
        $exp = (int)($payload['exp'] ?? 0);
        if ($exp <= 0 || $exp < $now) {
            throw new RuntimeException('Token expired.');
        }

        $aud = $payload['aud'] ?? null;
        $azp = (string)($payload['azp'] ?? '');

        $clientId = self::clientId();

        $audOk = false;
        if (is_string($aud)) {
            $audOk = ($aud === $clientId);
        } elseif (is_array($aud)) {
            $audOk = in_array($clientId, $aud, true);
        }

        if (!$audOk && $azp !== $clientId) {
            throw new RuntimeException('Token audience mismatch.');
        }
    }

    private static function extractRoles(array $accessPayload): array
    {
        $roles = [];

        $realmRoles = $accessPayload['realm_access']['roles'] ?? [];
        if (is_array($realmRoles)) {
            $roles = array_merge($roles, $realmRoles);
        }

        $clientId = self::clientId();
        $resourceRoles = $accessPayload['resource_access'][$clientId]['roles'] ?? [];
        if (is_array($resourceRoles)) {
            $roles = array_merge($roles, $resourceRoles);
        }

        return array_values(array_unique(array_map('strval', $roles)));
    }

    private static function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder > 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        if ($decoded === false) {
            throw new RuntimeException('Invalid base64url data.');
        }

        return $decoded;
    }

    private static function httpPostForm(string $url, array $data): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('cURL is required for authentication.');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_TIMEOUT        => 20,
        ]);

        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('HTTP request failed: ' . $err);
        }

        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [
            'status' => $status,
            'body'   => $body,
        ];
    }

    private static function authorizationEndpoint(): string
    {
        return self::issuer() . '/protocol/openid-connect/auth';
    }

    private static function tokenEndpoint(): string
    {
        return self::issuer() . '/protocol/openid-connect/token';
    }

    private static function endSessionEndpoint(): string
    {
        return self::issuer() . '/protocol/openid-connect/logout';
    }

    private static function logoutUrl(?string $idToken): string
    {
        $params = [
            'post_logout_redirect_uri' => self::postLogoutRedirectUri(),
            'client_id'                => self::clientId(),
        ];

        if ($idToken) {
            $params['id_token_hint'] = $idToken;
        }

        return self::endSessionEndpoint() . '?' . http_build_query($params);
    }

    private static function issuer(): string
    {
        return rtrim((string)env('KEYCLOAK_URL'), '/') . '/realms/' . rawurlencode((string)env('KEYCLOAK_REALM'));
    }

    private static function clientId(): string
    {
        $v = (string)env('KEYCLOAK_CLIENT_ID', '');
        if ($v === '') throw new RuntimeException('Missing KEYCLOAK_CLIENT_ID');
        return $v;
    }

    private static function clientSecret(): string
    {
        $v = (string)env('KEYCLOAK_CLIENT_SECRET', '');
        if ($v === '') throw new RuntimeException('Missing KEYCLOAK_CLIENT_SECRET');
        return $v;
    }

    private static function redirectUri(): string
    {
        return rtrim((string)env('APP_URL', ''), '/') . '/auth_callback.php';
    }

    private static function postLogoutRedirectUri(): string
    {
        return rtrim((string)env('APP_URL', ''), '/') . '/';
    }

    private static function currentUrlPathWithQuery(): string
    {
        $path = $_SERVER['REQUEST_URI'] ?? '/';
        return is_string($path) && $path !== '' ? $path : '/';
    }

    private static function firstNonEmpty(?string ...$values): string
    {
        foreach ($values as $v) {
            $v = trim((string)$v);
            if ($v !== '') return $v;
        }
        return 'User';
    }

    public static function userId(): ?string
    {
        $user = self::user();
        $sub = isset($user['sub']) ? trim((string)$user['sub']) : '';
        return $sub !== '' ? $sub : null;
    }
}