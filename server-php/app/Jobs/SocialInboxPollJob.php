<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Libraries\Distribution\SocialInbox\SocialInboxService;
use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;

/**
 * reach.social_inbox_poll (queue `distribution`, every 10 minutes from
 * reach:schedule): read new comments / messages from social-api.ai and handle
 * them — vetted reply, Flow ticket, Reach lead, or ignored with the reason.
 * Idempotent: messages are unique per network + id, and each is handled once.
 */
class SocialInboxPollJob implements JobHandlerInterface
{
    public function handle(array $payload, JobContext $ctx): array
    {
        return SocialInboxService::fromConfig()->run(max(1, min(100, (int) ($payload['limit'] ?? 25))));
    }
}
