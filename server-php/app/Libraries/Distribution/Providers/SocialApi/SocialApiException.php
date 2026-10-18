<?php

declare(strict_types=1);

namespace App\Libraries\Distribution\Providers\SocialApi;

/**
 * A social-api.ai call that did not do what was asked.
 *
 * `kind` says what the caller should do; the exception code is an HTTP-like
 * status so SocialPublisherService classifies it the way it classifies every
 * provider (429 rate_limit, 4xx permanent, 5xx transient):
 *
 *   not_configured  key / account missing or ambiguous — an operator must act (503)
 *   auth            the API key was refused (401/403)
 *   rate_limit      429
 *   transient       network error / 5xx — for POST /posts the outcome is unknown
 *   validation      400 (e.g. validation.field_invalid)
 *   conflict        409 resource.conflict (same title + text within 24 h)
 *   rejected        422 / a failed target in a 207 — social-api.ai could not publish
 *   media_source    the media file Reach was asked to attach could not be fetched (424)
 *   unrecognised    a 2xx Reach could not read (no post id) — outcome unknown (502)
 */
class SocialApiException extends \RuntimeException
{
    public const NOT_CONFIGURED = 'not_configured';
    public const AUTH           = 'auth';
    public const RATE_LIMIT     = 'rate_limit';
    public const TRANSIENT      = 'transient';
    public const VALIDATION     = 'validation';
    public const CONFLICT       = 'conflict';
    public const REJECTED       = 'rejected';
    public const MEDIA_SOURCE   = 'media_source';
    public const UNRECOGNISED   = 'unrecognised';
    public const PERMANENT      = 'permanent';

    /**
     * @param array<string, mixed>       $response redacted response body
     * @param list<array<string, mixed>> $targets  normalized per-target outcomes
     */
    public function __construct(
        string $message,
        public readonly string $kind = self::PERMANENT,
        public readonly int $httpStatus = 0,
        public readonly ?string $errorCode = null,
        public readonly array $response = [],
        public readonly array $targets = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, self::codeFor($kind, $httpStatus), $previous);
    }

    public static function codeFor(string $kind, int $httpStatus): int
    {
        return match ($kind) {
            self::NOT_CONFIGURED => 503,
            self::AUTH           => $httpStatus > 0 ? $httpStatus : 401,
            self::RATE_LIMIT     => 429,
            self::TRANSIENT      => $httpStatus >= 500 ? $httpStatus : 503,
            self::VALIDATION     => 400,
            self::CONFLICT       => 409,
            self::REJECTED       => 422,
            self::MEDIA_SOURCE   => 424,
            self::UNRECOGNISED   => 502,
            default              => $httpStatus >= 400 && $httpStatus < 500 ? $httpStatus : 422,
        };
    }

    /** The kind an HTTP status maps to. */
    public static function kindForStatus(int $status): string
    {
        return match (true) {
            $status === 0                     => self::TRANSIENT,
            $status === 401, $status === 403  => self::AUTH,
            $status === 429                   => self::RATE_LIMIT,
            $status === 409                   => self::CONFLICT,
            $status === 400                   => self::VALIDATION,
            $status === 422                   => self::REJECTED,
            $status >= 500                    => self::TRANSIENT,
            default                           => self::PERMANENT,
        };
    }

    /**
     * The `error.code` / `error.message` of a social-api.ai error body, read
     * tolerantly: {error:{code,message}}, {code,message}, {error:"…"}, {message}.
     *
     * @param array<string, mixed> $json
     * @return array{code: ?string, message: ?string}
     */
    public static function errorOf(array $json): array
    {
        $err = $json['error'] ?? null;
        if (is_array($err)) {
            $code = $err['code'] ?? $err['type'] ?? null;
            $msg  = $err['message'] ?? $err['detail'] ?? null;
        } else {
            $code = $json['code'] ?? $json['error_code'] ?? null;
            $msg  = is_string($err) ? $err : ($json['message'] ?? $json['detail'] ?? null);
        }
        $details = $json['error']['details'] ?? $json['details'] ?? $json['errors'] ?? null;
        if (is_array($details) && $details !== [] && is_string($msg)) {
            $fields = [];
            foreach (array_slice($details, 0, 5) as $k => $d) {
                if (is_array($d)) {
                    $f = $d['field'] ?? $d['path'] ?? (is_string($k) ? $k : null);
                    $m = $d['message'] ?? $d['code'] ?? null;
                    if ($f !== null || $m !== null) {
                        $fields[] = trim((is_scalar($f) ? (string) $f : '') . ' ' . (is_scalar($m) ? (string) $m : ''));
                    }
                } elseif (is_string($d)) {
                    $fields[] = (is_string($k) ? $k . ': ' : '') . $d;
                }
            }
            if ($fields !== []) {
                $msg .= ' (' . implode('; ', $fields) . ')';
            }
        }
        return [
            'code'    => is_scalar($code) && (string) $code !== '' ? mb_substr((string) $code, 0, 120) : null,
            'message' => is_scalar($msg) && (string) $msg !== '' ? mb_substr((string) $msg, 0, 500) : null,
        ];
    }
}
