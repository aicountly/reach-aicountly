<?php

namespace App\Libraries\Publishing\Connector;

/**
 * Phase 4 — HMAC-SHA256 request signer for service-to-service publishing calls.
 *
 * Secrets are never logged or exposed. The signing key must come from environment
 * variables only, never from the database or frontend.
 */
class HmacSigner
{
    /**
     * Canonical string format:
     *   METHOD\npath\ntimestamp\nnonce\ncontent-sha256\nidempotency-key\napi-version
     */
    public function buildCanonicalString(
        string $method,
        string $path,
        int $timestamp,
        string $nonce,
        string $contentSha256,
        string $idempotencyKey,
        int $apiVersion
    ): string {
        return implode("\n", [
            strtoupper($method),
            $path,
            (string) $timestamp,
            $nonce,
            $contentSha256,
            $idempotencyKey,
            (string) $apiVersion,
        ]);
    }

    /**
     * Generate HMAC-SHA256 signature.
     * Signing key is accepted as a parameter — never stored by this class.
     */
    public function sign(string $canonicalString, string $signingKey): string
    {
        return hash_hmac('sha256', $canonicalString, $signingKey);
    }

    /**
     * Constant-time comparison to prevent timing attacks.
     */
    public function verify(string $expected, string $actual): bool
    {
        return hash_equals($expected, $actual);
    }

    /**
     * Compute SHA-256 of a raw request body.
     */
    public function bodyHash(string $rawBody): string
    {
        return hash('sha256', $rawBody);
    }

    /**
     * Build the complete set of authentication headers for a request.
     * The signing key is used inline and never stored in this method's return value.
     */
    public function buildAuthHeaders(
        string $method,
        string $path,
        string $rawBody,
        string $idempotencyKey,
        string $requestId,
        string $bearerToken,
        string $signingKey,
        string $keyId,
        int $apiVersion = 1
    ): array {
        $timestamp    = time();
        $nonce        = $this->generateNonce();
        $contentSha   = $this->bodyHash($rawBody);

        $canonical = $this->buildCanonicalString(
            $method,
            $path,
            $timestamp,
            $nonce,
            $contentSha,
            $idempotencyKey,
            $apiVersion
        );

        $signature = $this->sign($canonical, $signingKey);

        return [
            'Authorization'          => 'Bearer ' . $bearerToken,
            'X-Reach-Key-Id'         => $keyId,
            'X-Reach-Timestamp'      => (string) $timestamp,
            'X-Reach-Nonce'          => $nonce,
            'X-Reach-Signature'      => $signature,
            'X-Reach-Content-SHA256' => $contentSha,
            'X-Request-ID'           => $requestId,
            'X-Idempotency-Key'      => $idempotencyKey,
            'X-Reach-API-Version'    => (string) $apiVersion,
            'Content-Type'           => 'application/json',
        ];
    }

    private function generateNonce(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
