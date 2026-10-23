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

    /**
     * Drill-down behind one "What the bot did" counter:
     * `?kind=<counter key>|all&window=24h|7d|30d&page=&per_page=`. Same
     * scope as the counter, so the list holds exactly the rows it counts;
     * failed jobs come grouped by job type.
     */
    public function commandCentreActivity()
    {
        $kind   = trim((string) ($this->request->getGet('kind') ?? ''));
        $kind   = $kind === 'all' ? '' : $kind;
        $window = trim((string) ($this->request->getGet('window') ?? '7d'));
        [$page, $perPage] = $this->pagination(25, 100);

        $activity = new \App\Libraries\Dashboard\CommandCentreActivity();
        if (\App\Libraries\Dashboard\CommandCentreActivity::window($window) === null) {
            return $this->fail('Unknown window: use 24h, 7d or 30d.', 422);
        }
        if ($kind !== '' && ! in_array($kind, $activity->kinds(), true)) {
            return $this->fail('Unknown activity kind: ' . mb_substr($kind, 0, 60), 422);
        }

        return $this->ok($activity->drilldown($kind, $window, $page, $perPage));
    }

    /** Used by the sidebar to badge menu items. */
    public function counts()
    {
        return $this->ok((new DashboardSummaryService())->counts());
    }
}
