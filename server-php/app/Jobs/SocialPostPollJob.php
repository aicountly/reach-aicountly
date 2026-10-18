<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Libraries\Distribution\SocialPostStatusPoller;
use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;

/**
 * reach.social_post_poll (queue `distribution`, every 5 minutes from
 * reach:schedule): confirm social-api.ai posts that are `publishing` —
 * posted, failed, or failed on the 30-minute timeout — and release
 * bot-generated posts parked in the manual queue once their channel's
 * social-api.ai account is connected (ManualQueueReleaser).
 */
class SocialPostPollJob implements JobHandlerInterface
{
    public function handle(array $payload, JobContext $ctx): array
    {
        $limit  = max(1, min(100, (int) ($payload['limit'] ?? 25)));
        $result = ['status' => 'ok'] + (new SocialPostStatusPoller())->pollDue($limit);
        // Bot posts parked while no account was connected go out once one is.
        try {
            $result['manual_queue'] = (new \App\Libraries\Distribution\ManualQueueReleaser())->release($limit);
        } catch (\Throwable $e) {
            log_message('error', '[SocialPostPoll] manual-queue release failed: ' . $e->getMessage());
        }

        return $result;
    }
}
