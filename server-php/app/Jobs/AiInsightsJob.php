<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Libraries\Intelligence\Insights\InsightsService;
use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;

/**
 * Runs AI Insights (weekly schedule or "Run now"). A failure is stored as a
 * failed report by InsightsService and re-thrown so the job shows failed too.
 */
class AiInsightsJob implements JobHandlerInterface
{
    public function handle(array $payload, JobContext $ctx): array
    {
        $report = (new InsightsService())->run(
            (string) ($payload['trigger'] ?? 'scheduled'),
            isset($payload['user_id']) ? (int) $payload['user_id'] : null,
        );

        return [
            'report_id'          => $report['id'] ?? null,
            'recommendations'    => count($report['report']['recommendations'] ?? []),
            'unverified_numbers' => count($report['unverified_numbers'] ?? []),
        ];
    }
}
