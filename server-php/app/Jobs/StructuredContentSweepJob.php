<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Libraries\Automation\Structured\StructuredSubmissionService;
use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;

/**
 * Every 15 minutes (reach:schedule): retry release notes and workflow guides
 * left pending because vetting was unavailable, or approved but not yet live
 * on aicountly.com. Each row backs off on its own (next_attempt_at), so a
 * tick with nothing due costs one query.
 */
class StructuredContentSweepJob implements JobHandlerInterface
{
    public function handle(array $payload, JobContext $ctx): array
    {
        return (new StructuredSubmissionService())->sweep((int) ($payload['limit'] ?? 20));
    }
}
