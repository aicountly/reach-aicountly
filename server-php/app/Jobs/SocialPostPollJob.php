<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Libraries\Distribution\SocialPostStatusPoller;
use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;

/**
 * reach.social_post_poll (queue `distribution`, every 5 minutes from
 * reach:schedule): confirm social-api.ai posts that are `publishing` —
 * posted, failed, or failed on the 30-minute timeout.
 */
class SocialPostPollJob implements JobHandlerInterface
{
    public function handle(array $payload, JobContext $ctx): array
    {
        return ['status' => 'ok'] + (new SocialPostStatusPoller())->pollDue(max(1, min(100, (int) ($payload['limit'] ?? 25))));
    }
}
