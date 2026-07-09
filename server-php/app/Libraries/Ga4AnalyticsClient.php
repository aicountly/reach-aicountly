<?php

namespace App\Libraries;

/**
 * Google Analytics 4 Data API client (service-account JWT auth).
 */
final class Ga4AnalyticsClient
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const SCOPE     = 'https://www.googleapis.com/auth/analytics.readonly';

    /** @return array{path_configured: bool, file_exists: bool, file_readable: bool, email: ?string, auth_ok: bool} */
    public static function inspectServiceAccountKey(string $keyPath): array
    {
        $keyPath = trim($keyPath);
        $exists  = $keyPath !== '' && is_file($keyPath);
        $readable = $exists && is_readable($keyPath);
        $email   = null;

        if ($readable) {
            $keyData = json_decode((string) file_get_contents($keyPath), true);
            $rawEmail = trim((string) ($keyData['client_email'] ?? ''));
            $email = $rawEmail !== '' ? $rawEmail : null;
        }

        return [
            'path_configured' => $keyPath !== '',
            'file_exists'     => $exists,
            'file_readable'   => $readable,
            'email'           => $email,
            'auth_ok'         => self::getAccessTokenFromPath($keyPath) !== null,
        ];
    }

    public static function getAccessTokenFromPath(string $keyPath): ?string
    {
        $keyPath = trim($keyPath);
        if ($keyPath === '' || ! is_readable($keyPath)) {
            return null;
        }

        $keyData = json_decode((string) file_get_contents($keyPath), true);
        if (! is_array($keyData) || empty($keyData['private_key']) || empty($keyData['client_email'])) {
            return null;
        }

        $now     = time();
        $header  = self::base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = self::base64UrlEncode(json_encode([
            'iss'   => $keyData['client_email'],
            'scope' => self::SCOPE,
            'aud'   => self::TOKEN_URL,
            'iat'   => $now,
            'exp'   => $now + 3600,
        ], JSON_THROW_ON_ERROR));

        $toSign  = $header . '.' . $payload;
        $pkeyRes = openssl_pkey_get_private($keyData['private_key']);
        if ($pkeyRes === false) {
            return null;
        }

        $sig = '';
        if (! openssl_sign($toSign, $sig, $pkeyRes, OPENSSL_ALGO_SHA256)) {
            return null;
        }

        $jwt = $toSign . '.' . self::base64UrlEncode($sig);

        $ch = curl_init(self::TOKEN_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT        => 10,
        ]);
        $body   = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status !== 200) {
            return null;
        }

        $data = json_decode($body, true);

        return is_array($data) ? ($data['access_token'] ?? null) : null;
    }

    /** Returns true on success, false on HTTP error, null when skipped. */
    public static function testPropertyAccess(string $token, string $propertyId): ?bool
    {
        $propertyId = trim($propertyId);
        if ($propertyId === '') {
            return null;
        }

        $report = self::runReport($token, $propertyId, [
            'dateRanges' => [['startDate' => '7daysAgo', 'endDate' => 'today']],
            'metrics'    => [['name' => 'sessions']],
            'limit'      => '1',
        ]);

        return $report !== null;
    }

    /** @param array<string, mixed> $body */
    public static function runReport(string $token, string $propertyId, array $body): ?array
    {
        $propertyId = trim($propertyId);
        if ($propertyId === '' || $token === '') {
            return null;
        }

        if (isset($body['limit'])) {
            $body['limit'] = (string) $body['limit'];
        }

        $url = 'https://analyticsdata.googleapis.com/v1beta/properties/' . rawurlencode($propertyId) . ':runReport';
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR),
            CURLOPT_TIMEOUT    => 15,
        ]);
        $resp   = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status !== 200) {
            return null;
        }

        $decoded = json_decode($resp, true);

        return is_array($decoded) ? $decoded : null;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
