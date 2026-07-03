<?php

namespace App\Libraries;

use Firebase\JWT\JWT as FirebaseJWT;
use Firebase\JWT\Key;
use RuntimeException;
use UnexpectedValueException;

/**
 * Thin HS256 JWT wrapper for Reach's independent superadmin auth.
 * Token TTL is configured via JWT_TTL_MINUTES (default 720 = 12h).
 */
class Jwt
{
    private string $secret;
    private int $ttlSeconds;

    public function __construct(?string $secret = null, ?int $ttlMinutes = null)
    {
        $secret  ??= (string) env('JWT_SECRET', '');
        $minutes = $ttlMinutes ?? (int) env('JWT_TTL_MINUTES', 720);

        if ($secret === '' || strlen($secret) < 32) {
            throw new RuntimeException('JWT_SECRET must be at least 32 characters. Set it in api/.env.');
        }

        $this->secret     = $secret;
        $this->ttlSeconds = max(60, $minutes * 60);
    }

    public function issue(int $userId, string $email, string $roleSlug, string $name = ''): string
    {
        $now = time();

        return FirebaseJWT::encode([
            'iss'   => 'reach.aicountly.org',
            'aud'   => 'reach-portal',
            'iat'   => $now,
            'nbf'   => $now,
            'exp'   => $now + $this->ttlSeconds,
            'sub'   => (string) $userId,
            'email' => $email,
            'name'  => $name,
            'role'  => $roleSlug,
        ], $this->secret, 'HS256');
    }

    /**
     * @return array{sub:string,email:string,name:string,role:string}|null
     */
    public function decode(string $token): ?array
    {
        try {
            $payload = FirebaseJWT::decode($token, new Key($this->secret, 'HS256'));
        } catch (UnexpectedValueException|\Throwable $e) {
            return null;
        }

        $arr = (array) $payload;
        $arr['role'] = (string) ($arr['role'] ?? '');
        $arr['name'] = (string) ($arr['name'] ?? '');
        return $arr;
    }

    public function ttlSeconds(): int
    {
        return $this->ttlSeconds;
    }
}
