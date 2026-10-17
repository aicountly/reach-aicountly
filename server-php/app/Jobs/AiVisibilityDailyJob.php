<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Libraries\Intelligence\Visibility\AiVisibilityService;
use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;

/**
 * Plans today's AI-visibility pass: one run per active prompt per configured
 * assistant, each queued as its own `intelligence.visibility.execute` job.
 * Idempotent per IST day for scheduled passes.
 */
class AiVisibilityDailyJob implements JobHandlerInterface
{
    public function handle(array $payload, JobContext $ctx): array
    {
        $batch = (new AiVisibilityService())->startBatch(
            ($payload['trigger'] ?? 'scheduled') === 'manual' ? 'manual' : 'scheduled',
            isset($payload['user_id']) ? (int) $payload['user_id'] : null,
        );

        return [
            'batch_id'        => $batch['id'] ?? null,
            'status'          => $batch['status'] ?? null,
            'note'            => $batch['note'] ?? null,
            'prompts_planned' => $batch['prompts_planned'] ?? 0,
            'runs_queued'     => count($batch['run_ids'] ?? []),
            'already_ran'     => (bool) ($batch['already_ran'] ?? false),
        ];
    }
}
