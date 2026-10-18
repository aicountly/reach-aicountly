<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Libraries\Content\Quality\ContentRevetService;
use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;

/**
 * Daily safety net (reach:schedule, 01:00 UTC): re-vets content that went
 * live in the last 48 hours. A vetting-model REJECT takes the page down and
 * queues a same-slug rewrite; anything else is recorded only. The daily
 * report shows the counts (informational — nobody has to act).
 */
class ContentQualitySweepJob implements JobHandlerInterface
{
    public function handle(array $payload, JobContext $ctx): array
    {
        $hours  = max(1, (int) ($payload['hours'] ?? 48));
        $report = (new ContentRevetService())->run([
            'scope'     => 'live',
            'since'     => date('Y-m-d H:i:s', time() - $hours * 3600),
            'trigger'   => 'daily_sweep',
            'community' => true,
            'limit'     => (int) ($payload['limit'] ?? 50),
        ]);

        return ['totals' => $report['totals']];
    }
}
