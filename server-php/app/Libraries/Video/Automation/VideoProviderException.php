<?php

declare(strict_types=1);

namespace App\Libraries\Video\Automation;

use App\Libraries\Video\Automation\Http\HttpResponse;

/**
 * A provider call that did not do what was asked. `kind` tells the pipeline
 * what to do next:
 *   not_configured / auth → park the item as `blocked` until an operator acts
 *   quota                 → retry much later (provider daily quota)
 *   transient             → retry with backoff
 *   permanent             → the item fails with this message
 *   session_expired       → restart the YouTube upload session
 *   source_expired        → re-render (Shotstack keeps outputs ~24 h)
 */
class VideoProviderException extends \RuntimeException
{
    public const NOT_CONFIGURED = 'not_configured';
    public const AUTH           = 'auth';
    public const QUOTA          = 'quota';
    public const TRANSIENT      = 'transient';
    public const PERMANENT      = 'permanent';
    /** The YouTube upload session is gone: open a new one. */
    public const SESSION_EXPIRED = 'session_expired';
    /** The renderer's output expired before the upload finished: render again. */
    public const SOURCE_EXPIRED  = 'source_expired';

    public function __construct(string $message, public readonly string $kind = self::TRANSIENT, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function notConfigured(string $message): self
    {
        return new self($message, self::NOT_CONFIGURED);
    }

    public static function fromResponse(string $provider, string $operation, HttpResponse $res): self
    {
        $detail = self::detail($res);
        $msg    = "{$provider} {$operation} failed" . ($res->status > 0 ? " (HTTP {$res->status})" : '') . ($detail !== '' ? ": {$detail}" : '');

        $kind = match (true) {
            $res->status === 0                         => self::TRANSIENT,
            $res->status === 429                       => self::QUOTA,
            $res->status === 401                       => self::AUTH,
            $res->status === 403 && self::isQuota($res) => self::QUOTA,
            $res->status === 403                       => self::AUTH,
            $res->status >= 500                        => self::TRANSIENT,
            default                                    => self::PERMANENT,
        };

        return new self($msg, $kind);
    }

    public function blocksPipeline(): bool
    {
        return $this->kind === self::NOT_CONFIGURED || $this->kind === self::AUTH;
    }

    private static function isQuota(HttpResponse $res): bool
    {
        $reason = (string) ($res->json()['error']['errors'][0]['reason'] ?? '');
        return in_array($reason, ['quotaExceeded', 'dailyLimitExceeded', 'rateLimitExceeded', 'uploadLimitExceeded', 'userRateLimitExceeded'], true);
    }

    private static function detail(HttpResponse $res): string
    {
        if ($res->transportError !== null) {
            return mb_substr($res->transportError, 0, 300);
        }
        $json = $res->json();
        $msg  = $json['error']['message'] ?? $json['error_description'] ?? $json['message'] ?? $json['response']['error'] ?? null;
        if (! is_string($msg) || $msg === '') {
            $msg = is_string($json['error'] ?? null) ? $json['error'] : mb_substr(trim(strip_tags($res->body)), 0, 200);
        }
        // Never echo credentials a provider reflected back.
        $msg = preg_replace('/(Bearer\s+)\S+/i', '$1[REDACTED]', (string) $msg) ?? '';
        return mb_substr(preg_replace('/sk-[A-Za-z0-9_\-]+/', '[REDACTED]', $msg) ?? '', 0, 300);
    }
}
