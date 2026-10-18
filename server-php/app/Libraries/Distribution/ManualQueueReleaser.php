<?php

declare(strict_types=1);

namespace App\Libraries\Distribution;

use App\Libraries\Distribution\Jobs\DistributionJobTypes;
use App\Libraries\Distribution\Providers\ChannelProviderFactory;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Services;

/**
 * Bot-generated social posts (the video pipeline's Shorts announcements) are
 * approved under the system account but parked in `manual_queue` while no
 * social-api.ai account is connected for their channel. Nothing ever moved
 * them on once the account was connected, so under bot mode they waited for a
 * person forever. This releases them: when the channel's publisher is ready,
 * the post becomes `approved` and is queued for dispatch like any other.
 *
 * Only bot-generated posts, and only recent ones (a person may have posted an
 * older one by hand from the manual queue): human-approved manual-queue posts
 * are left alone.
 */
class ManualQueueReleaser
{
    public const MAX_AGE_DAYS = 14;

    private BaseConnection $db;

    /** @var callable(string): bool */
    private $ready;

    /** @var callable(int, int): void */
    private $enqueue;

    public function __construct(?BaseConnection $db = null, ?callable $ready = null, ?callable $enqueue = null)
    {
        $this->db      = $db ?? Database::connect();
        $this->ready   = $ready ?? static fn (string $channel): bool => ChannelProviderFactory::socialPublisherReadyFor($channel);
        $this->enqueue = $enqueue ?? static function (int $postId, int $tenantId): void {
            Services::jobService()->enqueue(DistributionJobTypes::CAMPAIGN_DISPATCH_SOCIAL, ['post_id' => $postId, 'tenant_id' => $tenantId], [
                'queue' => 'distribution', 'enqueued_actor_type' => 'system', 'idempotency_key' => 'manual-queue-release-' . $postId,
            ]);
        };
    }

    /** @return array{released: int, waiting: int} */
    public function release(int $limit = 25): array
    {
        $rows = $this->db->table('reach_social_posts')->select('id, channel, tenant_id')
            ->where('status', 'manual_queue')
            ->where('approval_status', 'approved')
            ->where('bot_generated', true)
            ->where('created_at >=', date('Y-m-d H:i:s', time() - self::MAX_AGE_DAYS * 86400))
            ->orderBy('id', 'ASC')->limit(max(1, $limit))
            ->get()->getResultArray();

        $out   = ['released' => 0, 'waiting' => 0];
        $cache = [];
        foreach ($rows as $row) {
            $channel = (string) $row['channel'];
            $cache[$channel] ??= (bool) ($this->ready)($channel);
            if (! $cache[$channel]) {
                $out['waiting']++;
                continue;
            }
            $this->db->table('reach_social_posts')->where('id', $row['id'])->where('status', 'manual_queue')
                ->update(['status' => 'approved', 'updated_at' => date('Y-m-d H:i:s')]);
            if ($this->db->affectedRows() === 1) {
                ($this->enqueue)((int) $row['id'], (int) ($row['tenant_id'] ?: 1));
                $out['released']++;
            }
        }

        return $out;
    }
}
