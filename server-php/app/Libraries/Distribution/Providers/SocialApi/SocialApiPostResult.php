<?php

declare(strict_types=1);

namespace App\Libraries\Distribution\Providers\SocialApi;

/**
 * A POST /v1/posts or GET /v1/posts/{id} answer, read defensively.
 *
 * ASSUMPTIONS (field names not pinned down by the docs Reach had):
 *   post id      `id` | `post_id` | `pid`, at the top level or under `data` / `post`
 *   status       `status` | `state`
 *   per target   `targets` | `results` (list), each with `account_id`, `status`,
 *                `error` {code, message} (or `error_code` / `error_message`),
 *                the published URL in `url` | `post_url` | `permalink` | `platform_url`
 *                | `published_url`, and the network's own id in `platform_post_id`
 *                | `external_id` | `external_post_id` | `native_id` | `remote_id`
 * The raw body is kept (redacted) so an unexpected shape can be diagnosed.
 */
final class SocialApiPostResult
{
    public const PUBLISHED = 'published';
    public const FAILED    = 'failed';
    public const PENDING   = 'pending';
    public const PARTIAL   = 'partial';
    public const UNKNOWN   = 'unknown';

    private const PUBLISHED_WORDS = ['published', 'posted', 'success', 'succeeded', 'successful', 'completed', 'complete', 'done', 'live', 'sent', 'delivered'];
    private const FAILED_WORDS    = ['failed', 'failure', 'error', 'errored', 'rejected', 'cancelled', 'canceled', 'aborted', 'expired'];
    private const PENDING_WORDS   = ['pending', 'queued', 'processing', 'publishing', 'scheduled', 'submitted', 'in_progress', 'in-progress', 'accepted', 'created', 'uploading', 'running', 'retrying', 'waiting'];
    private const PARTIAL_WORDS   = ['partial', 'partially_published', 'partially_failed', 'partial_success', 'mixed'];

    /**
     * @param list<array{account_id: ?string, platform: ?string, status: string, raw_status: ?string, error_code: ?string, error_message: ?string, url: ?string, external_id: ?string}> $targets
     * @param array<string, mixed> $raw redacted body
     */
    public function __construct(
        public readonly int $httpStatus,
        public readonly ?string $postId,
        public readonly string $status,
        public readonly ?string $rawStatus,
        public readonly array $targets,
        public readonly array $raw,
    ) {
    }

    /** @param array<string, mixed>|list<mixed> $json */
    public static function parse(int $httpStatus, array $json, SocialApiRedactor $redactor): self
    {
        // Everything Reach keeps (targets, messages, raw) comes from the redacted body.
        $json = (array) $redactor->value($json);
        $body = $json;
        foreach (['data', 'post'] as $wrap) {
            if (isset($json[$wrap]) && is_array($json[$wrap]) && ! array_is_list($json[$wrap])) {
                $body = $json[$wrap] + $json;
                break;
            }
        }

        $id = null;
        foreach (['id', 'post_id', 'pid', 'postId'] as $k) {
            if (isset($body[$k]) && is_scalar($body[$k]) && (string) $body[$k] !== '') {
                $id = mb_substr((string) $body[$k], 0, 200);
                break;
            }
        }
        $rawStatus = null;
        foreach (['status', 'state'] as $k) {
            if (isset($body[$k]) && is_string($body[$k])) {
                $rawStatus = $body[$k];
                break;
            }
        }

        $list = null;
        foreach (['targets', 'results'] as $k) {
            if (isset($body[$k]) && is_array($body[$k])) {
                $list = $body[$k];
                break;
            }
        }
        $targets = [];
        foreach ((array) $list as $t) {
            if (is_array($t)) {
                $targets[] = self::normalizeTarget($t);
            }
        }

        return new self($httpStatus, $id, self::normalizeStatus($rawStatus), $rawStatus, $targets, $json);
    }

    public static function normalizeStatus(?string $status): string
    {
        if ($status === null || trim($status) === '') {
            return self::UNKNOWN;
        }
        $s = strtolower(trim($status));
        return match (true) {
            in_array($s, self::PARTIAL_WORDS, true)   => self::PARTIAL,
            in_array($s, self::PUBLISHED_WORDS, true) => self::PUBLISHED,
            in_array($s, self::FAILED_WORDS, true)    => self::FAILED,
            in_array($s, self::PENDING_WORDS, true)   => self::PENDING,
            default                                   => self::UNKNOWN,
        };
    }

    /**
     * The target Reach sent (by account id); the only target when there is one.
     *
     * @return array{account_id: ?string, platform: ?string, status: string, raw_status: ?string, error_code: ?string, error_message: ?string, url: ?string, external_id: ?string}|null
     */
    public function target(?string $accountId): ?array
    {
        if ($accountId !== null && $accountId !== '') {
            foreach ($this->targets as $t) {
                if ($t['account_id'] === $accountId) {
                    return $t;
                }
            }
        }
        return count($this->targets) === 1 ? $this->targets[0] : null;
    }

    /**
     * The outcome for the one target Reach cares about: the target's own
     * status when it is reported, otherwise the post status (a `partial` post
     * says nothing about one target, so it reads as unknown).
     */
    public function outcomeFor(?string $accountId): string
    {
        $t = $this->target($accountId);
        if ($t !== null && $t['status'] !== self::UNKNOWN) {
            return $t['status'];
        }
        return $this->status === self::PARTIAL ? self::UNKNOWN : $this->status;
    }

    /** "youtube: publishing.delivery.timeout — message" for each failed target. */
    public function failureSummary(): string
    {
        $parts = [];
        foreach ($this->targets as $t) {
            if ($t['status'] !== self::FAILED) {
                continue;
            }
            $parts[] = trim(($t['platform'] ?? $t['account_id'] ?? 'target') . ': ' . ($t['error_code'] ?? 'failed') . ($t['error_message'] ? ' — ' . $t['error_message'] : ''));
        }
        return implode('; ', $parts);
    }

    /**
     * @param array<string, mixed> $t
     * @return array{account_id: ?string, platform: ?string, status: string, raw_status: ?string, error_code: ?string, error_message: ?string, url: ?string, external_id: ?string}
     */
    private static function normalizeTarget(array $t): array
    {
        $account = $t['account_id'] ?? $t['accountId'] ?? $t['target_id'] ?? (is_array($t['account'] ?? null) ? ($t['account']['id'] ?? null) : ($t['account'] ?? null));
        $err     = SocialApiException::errorOf(['error' => $t['error'] ?? null] + (isset($t['error_code']) ? ['code' => $t['error_code']] : []) + (isset($t['error_message']) ? ['message' => $t['error_message']] : []));
        if ($err['code'] === null && isset($t['error_code']) && is_scalar($t['error_code'])) {
            $err['code'] = (string) $t['error_code'];
        }
        if ($err['message'] === null && isset($t['error_message']) && is_scalar($t['error_message'])) {
            $err['message'] = mb_substr((string) $t['error_message'], 0, 500);
        }

        $url = null;
        foreach (['url', 'post_url', 'permalink', 'platform_url', 'published_url', 'platform_post_url', 'link'] as $k) {
            if (isset($t[$k]) && is_string($t[$k]) && preg_match('#^https?://#i', $t[$k])) {
                $url = mb_substr($t[$k], 0, 500);
                break;
            }
        }
        $ext = null;
        foreach (['platform_post_id', 'external_id', 'external_post_id', 'native_id', 'remote_id', 'platform_id', 'provider_post_id'] as $k) {
            if (isset($t[$k]) && is_scalar($t[$k]) && (string) $t[$k] !== '') {
                $ext = mb_substr((string) $t[$k], 0, 200);
                break;
            }
        }
        $rawStatus = is_string($t['status'] ?? null) ? $t['status'] : (is_string($t['state'] ?? null) ? $t['state'] : null);
        $status    = self::normalizeStatus($rawStatus);
        if ($status === self::UNKNOWN && $err['code'] !== null) {
            $status = self::FAILED;
        }

        return [
            'account_id'    => is_scalar($account) && (string) $account !== '' ? (string) $account : null,
            'platform'      => SocialApiPlatforms::normalize($t['platform'] ?? $t['provider'] ?? null),
            'status'        => $status,
            'raw_status'    => $rawStatus,
            'error_code'    => $err['code'],
            'error_message' => $err['message'],
            'url'           => $url,
            'external_id'   => $ext,
        ];
    }
}
