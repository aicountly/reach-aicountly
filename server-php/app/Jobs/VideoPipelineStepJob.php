<?php

namespace App\Jobs;

use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;
use App\Libraries\Video\Automation\VideoPipelineRunner;

/**
 * reach.video_pipeline_step (queue `video`): one resumable step of an
 * approved video — payload {item_id, step}. See VideoPipelineRunner.
 */
class VideoPipelineStepJob implements JobHandlerInterface
{
    public function handle(array $payload, JobContext $ctx): array
    {
        $itemId = (int) ($payload['item_id'] ?? 0);
        $step   = (string) ($payload['step'] ?? '');
        if ($itemId <= 0 || $step === '') {
            throw new \InvalidArgumentException('video pipeline step needs {item_id, step}');
        }
        return (new VideoPipelineRunner())->run($itemId, $step, $ctx->attempts);
    }
}
