<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Libraries\DashboardSummaryService;

class DashboardController extends BaseApiController
{
    /** Marketing dashboard tiles + upcoming calendar. */
    public function summary()
    {
        return $this->ok((new DashboardSummaryService())->summary());
    }

    /**
     * The owner's command centre: health, bot activity, impact, rankings,
     * projections, recommendations. `?sections=a,b` limits the build,
     * `?fresh=1` bypasses the per-section cache.
     */
    public function commandCentre()
    {
        $only  = array_values(array_filter(array_map('trim', explode(',', (string) ($this->request->getGet('sections') ?? '')))));
        $fresh = in_array((string) $this->request->getGet('fresh'), ['1', 'true'], true);

        return $this->ok((new \App\Libraries\Dashboard\CommandCentreService())->build($only, $fresh));
    }

    /** Used by the sidebar to badge menu items. */
    public function counts()
    {
        return $this->ok((new DashboardSummaryService())->counts());
    }
}
