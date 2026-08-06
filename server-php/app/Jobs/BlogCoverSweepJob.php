<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Libraries\Blog\CoverSweepService;
use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;

/**
 * Hourly release of articles parked for want of a cover.
 *
 * Runs hourly rather than daily because covers arrive when an operator
 * uploads them, not on a schedule — an article parked at 09:10 should not
 * wait until tomorrow because its cover landed at 09:15. The sweep itself is
 * idempotent per article per day, so an hourly cadence costs one query when
 * there is nothing to do.
 */
class BlogCoverSweepJob implements JobHandlerInterface
{
    public function handle(array $payload, JobContext $ctx): array
    {
        $limit = (int) ($payload['limit'] ?? 25);

        return (new CoverSweepService())->sweep($limit);
    }
}
