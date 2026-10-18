<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;
use App\Libraries\Media\CoverGalleryAutofillService;

/**
 * reach.cover_gallery_autofill — hourly, `blog` queue. Generates the covers the
 * upcoming blog index is missing and stocks the Cover Gallery with them
 * (CoverGalleryAutofillService). Payload: {limit?: int} = per-run cap.
 */
class CoverGalleryAutofillJob implements JobHandlerInterface
{
    public function handle(array $payload, JobContext $ctx): array
    {
        return (new CoverGalleryAutofillService())->run(max(1, (int) ($payload['limit'] ?? 5)));
    }
}
