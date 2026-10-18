<?php

declare(strict_types=1);

namespace App\Libraries\Distribution;

use App\Libraries\AuditLogger;
use App\Libraries\Distribution\Providers\ChannelProviderFactory;
use App\Libraries\Distribution\Providers\SocialApi\SocialApiException;
use App\Libraries\Distribution\Providers\SocialApi\SocialApiRedactor;
use App\Libraries\Distribution\Providers\SocialApiAiPublisher;
use App\Libraries\Distribution\Providers\SocialPublisherInterface;
use CodeIgniter\Database\BaseConnection;

/**
 * Settles reach_social_posts that social-api.ai accepted (`publishing`):
 * GET /v1/posts/{id} until the target is published or failed, or until
 * TIMEOUT_MINUTES have passed since submission (or since the scheduled time).
 *
 *   published → `posted`, published_at, the network URL / id when returned
 *   failed    → `failed` with social-api.ai's error code (e.g. publishing.delivery.timeout)
 *   timeout   → `failed` with publishing.delivery.timeout — the post may still
 *               appear; the message says to check social-api.ai before reposting
 *
 * Runs as `reach.social_post_poll` (reach:schedule, every 5 minutes) and is
 * called directly by the video pipeline for its YouTube post. Idempotent:
 * a post that is no longer `publishing` is left alone.
 */
final class SocialPostStatusPoller
{
    public const JOB_TYPE          = 'reach.social_post_poll';
    public const TIMEOUT_MINUTES   = 30;
    public const MIN_INTERVAL_SECS = 120;
    public const TIMEOUT_CODE      = 'publishing.delivery.timeout';

    private BaseConnection $db;
    /** @var callable(): int */
    private $clock;
    private ?SocialPublisherInterface $publisher;

    public function __construct(?SocialPublisherInterface $publisher = null, ?BaseConnection $db = null, ?callable $clock = null)
    {
        $this->publisher = $publisher;
        $this->db        = $db ?? \Config\Database::connect();
        $this->clock     = $clock ?? static fn (): int => time();
    }

    /**
     * Poll the posts that are due (oldest check first).
     *
     * @return array{checked: int, posted: int, failed: int, pending: int, errors: int}
     */
    public function pollDue(int $limit = 25): array
    {
        $cutoff = gmdate('Y-m-d H:i:s', ($this->clock)() - self::MIN_INTERVAL_SECS);
        $rows   = $this->db->table('reach_social_posts')->select('id')
            ->where('status', 'publishing')
            ->groupStart()->where('last_polled_at IS NULL', null, false)->orWhere('last_polled_at <', $cutoff)->groupEnd()
            ->orderBy("COALESCE(last_polled_at, TIMESTAMPTZ '1970-01-01')", 'ASC', false)->orderBy('id', 'ASC')
            ->limit(max(1, $limit))->get()->getResultArray();

        $out = ['checked' => 0, 'posted' => 0, 'failed' => 0, 'pending' => 0, 'errors' => 0];
        foreach ($rows as $row) {
            try {
                $r = $this->pollOne((int) $row['id']);
            } catch (\Throwable $e) {
                $out['errors']++;
                log_message('error', '[SocialPostPoll] post ' . $row['id'] . ': ' . $e->getMessage());
                continue;
            }
            $out['checked']++;
            match ($r['status']) {
                'posted'     => $out['posted']++,
                'failed'     => $out['failed']++,
                default      => $out['pending']++,
            };
            if (! empty($r['poll_error'])) {
                $out['errors']++;
            }
        }
        return $out;
    }

    /**
     * Check one post now.
     *
     * @return array{status: string, changed: bool, post_id: int, provider_status?: ?string, poll_error?: ?string}
     */
    public function pollOne(int $postId, bool $force = false): array
    {
        $post = $this->db->table('reach_social_posts')->where('id', $postId)->get()->getRowArray();
        if ($post === null) {
            return ['status' => 'missing', 'changed' => false, 'post_id' => $postId];
        }
        if ($post['status'] !== 'publishing') {
            return ['status' => (string) $post['status'], 'changed' => false, 'post_id' => $postId];
        }
        $now = ($this->clock)();
        if (! $force && ! empty($post['last_polled_at']) && strtotime((string) $post['last_polled_at']) > $now - 20) {
            return ['status' => 'publishing', 'changed' => false, 'post_id' => $postId, 'provider_status' => $post['provider_status'] ?? null];
        }

        $publisher = $this->publisher ?? $this->makePublisher();
        $pid       = (string) ($post['external_post_id'] ?? '');
        $status    = null;
        $pollError = null;
        if (! $publisher instanceof SocialApiAiPublisher) {
            $pollError = 'social-api.ai is no longer the configured social publisher (social.socialapi.api_key cleared?) — cannot check this post.';
        } elseif ($pid === '') {
            $pollError = 'No social-api.ai post id was stored for this post.';
        } else {
            try {
                $status = $publisher->statusFor($pid, $post['platform_account_id'] ?? null);
            } catch (SocialApiException $e) {
                $pollError = $e->getMessage();
            } catch (\Throwable $e) {
                $pollError = 'Status check failed: ' . mb_substr($e->getMessage(), 0, 300);
            }
        }

        $stamp = gmdate('Y-m-d H:i:s', $now);
        $base  = ['last_polled_at' => $stamp, 'poll_count' => (int) ($post['poll_count'] ?? 0) + 1, 'updated_at' => $stamp];

        if ($status !== null && $status->normalisedStatus === 'published') {
            $target = (array) ($status->raw['target'] ?? []);
            $this->db->table('reach_social_posts')->where('id', $postId)->update($base + [
                'status'                 => 'posted',
                'published_at'           => $stamp,
                'remote_url'             => $target['url'] ?? null,
                'remote_post_id'         => $target['external_id'] ?? null,
                'provider_status'        => 'published',
                'provider_error_code'    => null,
                'provider_response_json' => $this->responseJson($post, $status->raw),
                'error_message'          => null,
            ]);
            $this->settleAttempt($pid, true, null, null);
            AuditLogger::record(AuditLogger::DISTRIBUTION_SOCIAL_POSTED, ['post_id' => $postId, 'provider_post_id' => $pid, 'platform' => $post['channel'], 'remote_url' => $target['url'] ?? null], null);
            $this->settleDispatch($post, true, null);
            return ['status' => 'posted', 'changed' => true, 'post_id' => $postId, 'provider_status' => 'published'];
        }

        if ($status !== null && $status->normalisedStatus === 'failed') {
            $target = (array) ($status->raw['target'] ?? []);
            $code   = (string) ($status->failureClass ?? 'publishing.failed');
            $msg    = 'social-api.ai could not publish to ' . $post['channel'] . ': ' . $code . (! empty($target['error_message']) ? ' — ' . $target['error_message'] : '') . '.';
            $this->db->table('reach_social_posts')->where('id', $postId)->update($base + [
                'status'                 => 'failed',
                'provider_status'        => 'failed',
                'provider_error_code'    => mb_substr($code, 0, 120),
                'provider_response_json' => $this->responseJson($post, $status->raw),
                'error_message'          => $msg,
            ]);
            $this->settleAttempt($pid, false, 'rejected', $msg);
            AuditLogger::record(AuditLogger::DISTRIBUTION_SOCIAL_FAILED, ['post_id' => $postId, 'provider_post_id' => $pid, 'error' => $msg, 'error_code' => $code], null);
            $this->settleDispatch($post, false, $msg);
            return ['status' => 'failed', 'changed' => true, 'post_id' => $postId, 'provider_status' => 'failed'];
        }

        $providerStatus = $status !== null ? (string) ($status->raw['provider_status'] ?? 'pending') : 'poll_error';
        $since          = $this->clockStart($post);
        if ($now - $since >= self::TIMEOUT_MINUTES * 60) {
            $msg = sprintf(
                'social-api.ai did not report the post published within %d minutes (last status: %s%s). It may still appear on %s — check social-api.ai before posting it again.',
                self::TIMEOUT_MINUTES,
                $providerStatus,
                $pollError !== null ? '; last check: ' . mb_substr($pollError, 0, 200) : '',
                $post['channel'],
            );
            $this->db->table('reach_social_posts')->where('id', $postId)->update($base + [
                'status'                 => 'failed',
                'provider_status'        => mb_substr($providerStatus, 0, 40),
                'provider_error_code'    => self::TIMEOUT_CODE,
                'provider_response_json' => $status !== null ? $this->responseJson($post, $status->raw) : ($post['provider_response_json'] ?? null),
                'error_message'          => $msg,
            ]);
            $this->settleAttempt($pid, false, 'unknown', $msg);
            AuditLogger::record(AuditLogger::DISTRIBUTION_SOCIAL_FAILED, ['post_id' => $postId, 'provider_post_id' => $pid, 'error' => $msg, 'error_code' => self::TIMEOUT_CODE], null);
            $this->settleDispatch($post, false, $msg);
            return ['status' => 'failed', 'changed' => true, 'post_id' => $postId, 'provider_status' => $providerStatus, 'poll_error' => $pollError];
        }

        $this->db->table('reach_social_posts')->where('id', $postId)->update($base + [
            'provider_status'        => mb_substr($providerStatus, 0, 40),
            'provider_response_json' => $status !== null ? $this->responseJson($post, $status->raw) : ($post['provider_response_json'] ?? null),
            // A failing status check is shown, but the post stays in flight until the timeout.
            'error_message'          => $pollError !== null ? mb_substr('Status check failed (will retry): ' . $pollError, 0, 2000) : null,
        ]);
        return ['status' => 'publishing', 'changed' => false, 'post_id' => $postId, 'provider_status' => $providerStatus, 'poll_error' => $pollError];
    }

    // ── internals ───────────────────────────────────────────────────────────

    private function makePublisher(): ?SocialPublisherInterface
    {
        try {
            return $this->publisher = ChannelProviderFactory::makeSocialPublisher();
        } catch (\Throwable) {
            return null;
        }
    }

    /** When the 30-minute clock started: submission, or the scheduled time if later. */
    private function clockStart(array $post): int
    {
        $submitted = strtotime((string) ($post['submitted_at'] ?? '')) ?: (strtotime((string) ($post['updated_at'] ?? '')) ?: ($this->clock)());
        $scheduled = ! empty($post['scheduled_at']) ? (strtotime((string) $post['scheduled_at']) ?: 0) : 0;
        return max($submitted, $scheduled);
    }

    /** Keep the create response next to the latest status answer (both redacted). */
    private function responseJson(array $post, array $statusRaw): string
    {
        $prev = $post['provider_response_json'] ?? null;
        $prev = is_string($prev) ? (json_decode($prev, true) ?: []) : (array) $prev;
        $redactor = new SocialApiRedactor();
        return (string) json_encode([
            'create'       => $prev['create'] ?? null,
            'publish_mode' => $prev['publish_mode'] ?? null,
            'media_ids'    => $prev['media_ids'] ?? [],
            'status'       => $redactor->value($statusRaw),
            'checked_at'   => gmdate('c', ($this->clock)()),
        ], JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    private function settleAttempt(string $pid, bool $ok, ?string $class, ?string $detail): void
    {
        if ($pid === '') {
            return;
        }
        $now = gmdate('Y-m-d H:i:s', ($this->clock)());
        $this->db->table('reach_campaign_delivery_attempts')
            ->where('provider_message_id', $pid)->where('status', 'accepted')
            ->update($ok ? ['status' => 'delivered', 'delivered_at' => $now] : ['status' => 'failed', 'failure_class' => $class, 'failure_detail' => mb_substr((string) $detail, 0, 2000), 'failed_at' => $now]);
    }

    private function settleDispatch(array $post, bool $ok, ?string $error): void
    {
        if (empty($post['dispatch_id'])) {
            return;
        }
        SocialPublisherService::settleDispatch((int) $post['dispatch_id'], $ok, $error);
    }
}
