<?php

namespace App\Libraries\Publishing\Connector;

/**
 * Phase 4 — Classifies HTTP/network errors from the public-site publisher.
 *
 * Maps HTTP status codes and exception types to error categories defined in
 * the publishing API contract. The safe_error_message is never PII and
 * never contains secret material.
 */
class PublishingErrorClassifier
{
    public const RETRYABLE_CATEGORIES = [
        'rate_limited',
        'timeout',
        'network_error',
        'server_error',
    ];

    /**
     * Classify based on HTTP status code.
     */
    public function classifyHttpStatus(int $httpStatus): string
    {
        return match (true) {
            $httpStatus === 401  => 'authentication_error',
            $httpStatus === 403  => 'publication_blocked',
            $httpStatus === 404  => 'not_found',
            $httpStatus === 409  => 'version_conflict',
            $httpStatus === 422  => 'validation_error',
            $httpStatus === 429  => 'rate_limited',
            $httpStatus === 400  => 'validation_error',
            $httpStatus === 504  => 'timeout',
            $httpStatus >= 500   => 'server_error',
            default              => 'unknown_error',
        };
    }

    /**
     * Build a safe (non-PII, non-secret) error message for logging.
     */
    public function safeMessage(string $category, int $httpStatus = 0): string
    {
        return match ($category) {
            'authentication_error' => 'Authentication failed with the public site.',
            'signature_error'      => 'Request signature could not be verified.',
            'replay_rejected'      => 'Request was rejected as a replay.',
            'rate_limited'         => 'Rate limit reached. Retry after indicated delay.',
            'validation_error'     => 'Payload validation failed on the public site.',
            'version_conflict'     => 'Content version conflict on the public site.',
            'checksum_mismatch'    => 'Payload checksum does not match on the public site.',
            'not_found'            => 'Content not found on the public site.',
            'timeout'              => 'Request timed out.',
            'network_error'        => 'Network error communicating with the public site.',
            'server_error'         => "Server error from public site (HTTP {$httpStatus}).",
            'publication_blocked'  => 'Publication was blocked by public site policy.',
            'configuration_error'  => 'Publishing connector is not configured.',
            default                => 'An unexpected error occurred.',
        };
    }

    /**
     * Classify from a cURL or network exception message.
     */
    public function classifyException(\Throwable $e): string
    {
        $msg = strtolower($e->getMessage());

        if (str_contains($msg, 'timeout') || str_contains($msg, 'timed out')) {
            return 'timeout';
        }

        if (str_contains($msg, 'could not resolve') || str_contains($msg, 'connection refused')) {
            return 'network_error';
        }

        if (str_contains($msg, 'ssl') || str_contains($msg, 'certificate')) {
            return 'network_error';
        }

        return 'network_error';
    }

    /**
     * Determine if the error is retryable.
     */
    public function isRetryable(string $category): bool
    {
        return in_array($category, self::RETRYABLE_CATEGORIES, true);
    }
}
