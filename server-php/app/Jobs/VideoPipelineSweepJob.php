<?php

namespace App\Jobs;

use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;
use App\Libraries\Video\Automation\VideoPipelineSweeper;

/** reach.video_pipeline_sweep (queue `video`, hourly from reach:schedule). */
class VideoPipelineSweepJob implements JobHandlerInterface
{
    public function handle(array $payload, JobContext $ctx): array
    {
        return (new VideoPipelineSweeper())->sweep();
    }
}
