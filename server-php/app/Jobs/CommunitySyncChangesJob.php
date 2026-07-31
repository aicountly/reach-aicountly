<?php

namespace App\Jobs;

use App\Libraries\Community\CommunityChangeSyncService;
use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;

/**
 * Phase 4 — Community Change Sync Job.
 *
 * Job type key: reach.community_sync_changes
 *
 * Payload: { "limit"?: int }
 *
 * Pulls the public site's `/community/changes` feed since Reach's last
 * synced cursor and surfaces drift (public-side moderation/status changes
 * Reach doesn't yet know about) as audit log entries. Detection-only — see
 * CommunityChangeSyncService for why it never mutates Reach's own status
 * columns directly.
 */
class CommunitySyncChangesJob implements JobHandlerInterface
{
    public function handle(array $payload, JobContext $ctx): array
    {
        $limit = (int) ($payload['limit'] ?? 500);

        $summary = (new CommunityChangeSyncService())->sync($limit);

        return array_merge(['ok' => true], $summary);
    }
}
