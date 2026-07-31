<?php

namespace App\Jobs;

use App\Libraries\Blog\WorkBlockService;
use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;

/**
 * Executes a reach_work_blocks row via WorkBlockService.
 */
class BlogWorkBlockJob implements JobHandlerInterface
{
    public function handle(array $payload, JobContext $ctx): array
    {
        $workBlockId = (int) ($payload['work_block_id'] ?? 0);
        if ($workBlockId <= 0) {
            throw new \InvalidArgumentException('BlogWorkBlockJob requires payload.work_block_id');
        }

        $service = new WorkBlockService();
        $result  = $service->execute($workBlockId);

        return array_merge($result, [
            'work_block_id' => $workBlockId,
            'attempt'       => $ctx->attempts,
            'worker_id'     => $ctx->workerId,
        ]);
    }
}
