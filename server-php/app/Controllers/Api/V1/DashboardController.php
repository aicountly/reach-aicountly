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

    /** Used by the sidebar to badge menu items. */
    public function counts()
    {
        return $this->ok((new DashboardSummaryService())->counts());
    }
}
