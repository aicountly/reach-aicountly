<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Libraries\Blog\ContentBaseService;
use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;

/**
 * Daily (and post-deploy) sync of the repo-versioned content-base blog index
 * into reach_topic_candidates. Idempotent by content_base_key.
 */
class ContentBaseSyncJob implements JobHandlerInterface
{
    public function handle(array $payload, JobContext $ctx): array
    {
        return (new ContentBaseService())->syncBlogIndex();
    }
}
