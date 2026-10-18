<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Bot;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\Bot\Plan\PlanBriefing;

/**
 * GET v1/automation/plan/briefing[?type=blog|knowledge_base|community|release_note|workflow_guide|video|growth]
 * (automation token). The routines read it first: today's plan, yesterday's
 * achievement, the top lags with causes and what the bot changed, vetting
 * reasons to avoid, topics that perform.
 */
class PlanBriefingController extends BaseApiController
{
    public function briefing()
    {
        $type = $this->request->getGet('type');
        $type = is_string($type) && $type !== '' ? $type : null;
        try {
            return $this->ok((new PlanBriefing())->build($type));
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }
}
