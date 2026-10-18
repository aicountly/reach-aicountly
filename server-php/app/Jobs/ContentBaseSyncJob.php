<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Libraries\Content\Base\ContentBaseSync;
use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;

/**
 * Hourly (and post-deploy) content-base pass: import the git seeds into
 * reach_content_base_entries, mirror blog seeds into roadmap candidates,
 * release roadmap topics stuck in the retired in-house drafting, and
 * reconcile every topic's status from the pipeline. Idempotent.
 */
class ContentBaseSyncJob implements JobHandlerInterface
{
    public function handle(array $payload, JobContext $ctx): array
    {
        return (new ContentBaseSync())->run();
    }
}
