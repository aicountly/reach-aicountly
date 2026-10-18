<?php

declare(strict_types=1);

namespace App\Libraries\Video\Automation;

use App\Libraries\Automation\SystemApprover;
use App\Libraries\Distribution\Jobs\DistributionJobTypes;
use App\Libraries\Distribution\Providers\ChannelProviderFactory;
use Config\Services;

/**
 * Offers a published video to the social networks: one reach_social_posts
 * row per network (approved by the Reach System Bot, the YouTube link and the
 * rendered MP4 in media_refs) and a `distribution.dispatch.social` job where
 * the network's social-api.ai account resolves; otherwise the post waits in
 * the manual queue. Nothing is ever marked posted here — the publisher and
 * SocialPostStatusPoller decide that.
 *
 * Used by the pipeline's `social` step (Shorts, or any video an owner command
 * listed networks for) and by POST v1/automation/videos/{uuid}/distribute.
 * A network that already has a live (not failed) post for the item is never
 * given a second one; a failed one is dispatched again.
 */
final class VideoSocialDistributor
{
    /** Networks a Short is offered to when nobody chose (the pipeline's long-standing set). */
    public const DEFAULT_CHANNELS = ['instagram', 'facebook', 'linkedin'];
    /** Networks a video can be offered to besides YouTube itself. */
    public const ALLOWED_CHANNELS = ['instagram', 'facebook', 'linkedin', 'twitter'];

    public function __construct(private readonly VideoPipelineRepository $repo = new VideoPipelineRepository())
    {
    }

    /**
     * The networks chosen for an item: null column = the default set (Shorts only), [] = none.
     *
     * @param array<string, mixed> $item
     * @return list<string>
     */
    public static function channelsFor(array $item): array
    {
        $raw = $item['social_channels_json'] ?? null;
        if ($raw === null || $raw === '') {
            return ($item['format'] ?? '') === 'short' ? self::DEFAULT_CHANNELS : [];
        }
        $list = is_array($raw) ? $raw : json_decode((string) $raw, true);
        return is_array($list) ? array_values(array_intersect(self::ALLOWED_CHANNELS, $list)) : [];
    }

    /** Whether the pipeline should run its social step for this item. */
    public static function offersSocial(array $item): bool
    {
        return self::channelsFor($item) !== [];
    }

    /**
     * @param array<string, mixed> $item a decoded reach_video_pipeline_items row
     * @param list<string>         $channels
     * @return list<array{channel: string, post_id: int, status: string, action: string, note: ?string}>
     */
    public function distribute(array $item, array $channels): array
    {
        $id       = (int) $item['id'];
        $db       = $this->repo->db();
        $botId    = SystemApprover::userId();
        $tenantId = (int) (env('REACH_DEFAULT_TENANT_ID') ?: 1);
        $script   = (array) $item['script_json'];
        $commandId = isset($item['bot_command_id']) && $item['bot_command_id'] !== null ? (int) $item['bot_command_id'] : null;

        $existingIds = array_values(array_map('intval', (array) ($item['social_post_ids_json'] ?? [])));
        $existing    = [];
        if ($existingIds !== []) {
            foreach ($db->table('reach_social_posts')->whereIn('id', $existingIds)->orderBy('id')->get()->getResultArray() as $p) {
                $existing[(string) $p['channel']] = $p; // latest per channel wins
            }
        }

        $hashtags = array_slice(array_values(array_filter(array_map(static fn ($t) => '#' . preg_replace('/[^\p{L}\p{N}]+/u', '', (string) $t), (array) ($script['tags'] ?? [])), static fn ($t) => mb_strlen($t) > 1)), 0, 5);
        $watch    = (string) ($item['youtube_url'] ?? '');
        $text     = trim((string) ($script['title'] ?? '')) . "\n\n" . mb_substr(trim((string) ($script['description'] ?? '')), 0, 280)
            . "\n\n" . trim((string) ($script['cta_text'] ?? '')) . ($watch !== '' ? "\nWatch: " . $watch : '');
        $media    = array_values(array_filter([
            $watch !== '' ? ['type' => 'video', 'kind' => $item['format'] === 'short' ? 'youtube_short' : 'youtube', 'url' => $watch] : null,
            ! empty($item['render_url']) ? ['type' => 'video', 'kind' => 'mp4', 'url' => $item['render_url'], 'note' => 'Rendered MP4 — hosted by Shotstack for about 24 hours after rendering.', 'rendered_at' => $item['rendered_at'] ?? null] : null,
        ]));

        $out = [];
        $ids = $existingIds;
        foreach (array_values(array_unique($channels)) as $channel) {
            if (! in_array($channel, self::ALLOWED_CHANNELS, true)) {
                continue;
            }
            $ready = ChannelProviderFactory::socialPublisherReadyFor($channel);
            $prev  = $existing[$channel] ?? null;

            if ($prev !== null && $prev['status'] !== 'failed') {
                $out[] = ['channel' => $channel, 'post_id' => (int) $prev['id'], 'status' => (string) $prev['status'], 'action' => 'already_offered', 'note' => null];
                continue;
            }
            if ($prev !== null) { // failed before: send that post again
                if ($ready) {
                    $db->table('reach_social_posts')->where('id', (int) $prev['id'])->update(['status' => 'approved', 'updated_at' => gmdate('Y-m-d H:i:s')]);
                    Services::jobService()->enqueue(DistributionJobTypes::CAMPAIGN_DISPATCH_SOCIAL, ['post_id' => (int) $prev['id'], 'tenant_id' => $tenantId], ['queue' => 'distribution', 'enqueued_actor_type' => 'system']);
                    $out[] = ['channel' => $channel, 'post_id' => (int) $prev['id'], 'status' => 'approved', 'action' => 'requeued', 'note' => 'The earlier attempt failed (' . mb_substr((string) $prev['error_message'], 0, 200) . '); queued again.'];
                } else {
                    $out[] = ['channel' => $channel, 'post_id' => (int) $prev['id'], 'status' => 'failed', 'action' => 'not_ready', 'note' => 'No social-api.ai account resolves for this network; nothing was sent.'];
                }
                continue;
            }

            $now = gmdate('Y-m-d H:i:s');
            $db->table('reach_social_posts')->insert(array_filter(['bot_command_id' => $commandId], static fn ($v) => $v !== null) + [
                'channel'         => $channel,
                'content'         => $text,
                'media_refs'      => json_encode($media, JSON_UNESCAPED_SLASHES),
                'hashtags'        => json_encode($hashtags, JSON_UNESCAPED_UNICODE),
                // Without a publisher that can post on this channel they wait in the manual queue.
                'status'          => $ready ? 'approved' : 'manual_queue',
                'approval_status' => 'approved',
                'approved_by'     => $botId,
                'approved_at'     => $now,
                'bot_generated'   => true,
                'created_by'      => $botId,
                'tenant_id'       => $tenantId,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
            $postId = (int) $db->insertID();
            $ids[]  = $postId;
            if ($ready) {
                Services::jobService()->enqueue(DistributionJobTypes::CAMPAIGN_DISPATCH_SOCIAL, ['post_id' => $postId, 'tenant_id' => $tenantId], ['queue' => 'distribution', 'enqueued_actor_type' => 'system']);
            }
            $out[] = [
                'channel' => $channel, 'post_id' => $postId, 'status' => $ready ? 'approved' : 'manual_queue',
                'action'  => $ready ? 'queued' : 'manual_queue',
                'note'    => $ready ? null : 'No social-api.ai account resolves for this network; the post waits in the manual queue and is not marked posted.',
            ];
        }

        $this->repo->update($id, ['social_post_ids_json' => array_values(array_unique($ids))]);
        return $out;
    }
}
