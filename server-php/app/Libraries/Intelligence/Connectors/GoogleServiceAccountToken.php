<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence\Connectors;

/**
 * Service-account JWT -> OAuth2 access token exchange for Google APIs.
 *
 * Mirrors the auth approach already used by Ga4AnalyticsClient but is scope
 * agnostic so any Google connector can reuse it. Tokens are cached in-process
 * per (keyPath, scope) pair until 60s before expiry.
 *
 * Security: the private key and the resulting bearer token are never logged,
 * returned in error strings, or persisted.
 */
final class GoogleServiceAccountToken
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /** @var array<string, array{token: string, expires_at: int}> */
    private static array $cache = [];

    /**
     * Describe the credential file without performing a network call.
     *
     * @return array{path_configured: bool, file_exists: bool, file_readable: bool, parsable: bool, client_email: ?string}
     */
    public static function inspect(string $keyPath): array
    {
        $keyPath  = trim($keyPath);
        $exists   = $keyPath !== '' && is_file($keyPath);
        $readable = $exists && is_readable($keyPath);
        $parsable = false;
        $email    = null;

        if ($readable) {
            $data = json_decode((string) file_get_contents($keyPath), true);
            if (is_array($data) && ! empty($data['private_key']) && ! empty($data['client_email'])) {
                $parsable = true;
                $email    = (string) $data['client_email'];
            }
        }

        return [
            'path_configured' => $keyPath !== '',
            'file_exists'     => $exists,
            'file_readable'   => $readable,
            'parsable'        => $parsable,
            'client_email'    => $email,
        ];
    }

    /**
     * Returns a bearer token, or null when credentials are missing or rejected.
     */
    public static function get(string $keyPath, string $scope, int $timeout = 10): ?string
    {
        $keyPath = trim($keyPath);
        if ($keyPath === '' || ! is_readable($keyPath)) {
            return null;
        }

        $cacheKey = sha1($keyPath . '|' . $scope);
        $cached   = self::$cache[$cacheKey] ?? null;
        if ($cached !== null && $cached['expires_at'] > time() + 60) {
            return $cached['token'];
        }

        $keyData = json_decode((string) file_get_contents($keyPath), true);
        if (! is_array($keyData) || empty($keyData['private_key']) || empty($keyData['client_email'])) {
            return null;
        }

        $jwt = self::buildAssertion((string) $keyData['client_email'], (string) $keyData['private_key'], $scope);
        if ($jwt === null) {
            return null;
        }

        $ch = curl_init(self::TOKEN_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body   = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status !== 200) {
            return null;
        }

        $data  = json_decode($body, true);
        $token = is_array($data) ? ($data['access_token'] ?? null) : null;
        if (! is_string($token) || $token === '') {
            return null;
        }

        $expiresIn = is_array($data) ? (int) ($data['expires_in'] ?? 3600) : 3600;
        self::$cache[$cacheKey] = [
            'token'      => $token,
            'expires_at' => time() + $expiresIn,
        ];

        return $token;
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }

    private static function buildAssertion(string $clientEmail, string $privateKey, string $scope): ?string
    {
        $now     = time();
        $header  = self::base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = self::base64UrlEncode(json_encode([
            'iss'   => $clientEmail,
            'scope' => $scope,
            'aud'   => self::TOKEN_URL,
            'iat'   => $now,
            'exp'   => $now + 3600,
        ], JSON_THROW_ON_ERROR));

        $toSign = $header . '.' . $payload;
        $pkey   = openssl_pkey_get_private($privateKey);
        if ($pkey === false) {
            return null;
        }

        $signature = '';
        if (! openssl_sign($toSign, $signature, $pkey, OPENSSL_ALGO_SHA256)) {
            return null;
        }

        return $toSign . '.' . self::base64UrlEncode($signature);
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
