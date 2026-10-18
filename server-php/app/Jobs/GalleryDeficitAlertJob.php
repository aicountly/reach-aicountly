<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;
use App\Libraries\Media\CoverGenerationAlertService;

/**
 * reach.gallery_deficit_alert (09:00 UTC, notifications queue).
 *
 * This used to email a superadmin the cover deficit with prompts to generate
 * and "Upload at: Reach console → Quality Centre → Cover Gallery" — a request
 * for a human to make images. Reach now generates covers itself
 * (reach.cover_gallery_autofill), the daily numbers are in the daily
 * analytics report, and this job only sends an informational email when
 * cover generation keeps failing (CoverGenerationAlertService). The job type
 * keeps its name so already-queued rows still have a handler.
 */
class GalleryDeficitAlertJob implements JobHandlerInterface
{
    public function handle(array $payload, JobContext $ctx): array
    {
        return (new CoverGenerationAlertService())->run();
    }
}
