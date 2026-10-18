<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Libraries\Bot\Plan\BotPlanRunner;
use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;

/**
 * Bot Plan & Performance (reach:schedule): `daily` at 01:00 IST snapshots
 * yesterday's actuals, reviews the last 7 days, self-tunes and regenerates
 * the plans; `refresh` every hour only regenerates the plans (a no-op when
 * nothing changed). See App\Libraries\Bot\Plan\BotPlanRunner.
 *
 * Payload: { "mode": "daily" | "refresh", "date"?: "YYYY-MM-DD" (IST, informational) }
 */
class BotPlanJob implements JobHandlerInterface
{
    public const TYPE = 'reach.bot_plan';

    public function handle(array $payload, JobContext $ctx): array
    {
        $mode = ($payload['mode'] ?? 'daily') === 'refresh' ? 'refresh' : 'daily';

        return (new BotPlanRunner())->run($mode);
    }
}
