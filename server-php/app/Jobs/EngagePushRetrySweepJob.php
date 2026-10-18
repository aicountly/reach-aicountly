<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;
use App\Libraries\Leads\EngagePushRetrySweep;

/** Every 15 minutes (reach:schedule): retry leads Engage has not accepted yet. */
class EngagePushRetrySweepJob implements JobHandlerInterface
{
    public function handle(array $payload, JobContext $ctx): array
    {
        return (new EngagePushRetrySweep())->run(max(1, min(100, (int) ($payload['limit'] ?? 25))));
    }
}
