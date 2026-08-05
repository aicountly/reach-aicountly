<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Libraries\Intelligence\Seo\SeoSnapshotService;
use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;

/**
 * Daily SEO snapshots: tracked-keyword rank history (from ingested GSC
 * facts) and the backlink-health snapshot.
 */
class SeoSnapshotJob implements JobHandlerInterface
{
    public function handle(array $payload, JobContext $ctx): array
    {
        $service = new SeoSnapshotService();

        return [
            'ranks'     => $service->captureRankSnapshots($payload['date'] ?? null),
            'backlinks' => $service->captureBacklinkSnapshot(),
        ];
    }
}
