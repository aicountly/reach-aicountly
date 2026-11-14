<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Libraries\Dashboard\RecommendationFixer;
use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;

/**
 * Hourly (reach:schedule): in AUTO bot mode the bot acts on the Command
 * centre's "What to do next" cards by itself — a focused article for a search
 * query close to page one, a sharper title for a page people do not click, an
 * AI Insights recommendation for reach-command — within the Settings → Bot
 * fixes limits. In CONFIRM mode it does nothing.
 */
class RecommendationAutoFixJob implements JobHandlerInterface
{
    public function handle(array $payload, JobContext $ctx): array
    {
        return (new RecommendationFixer())->autoFix();
    }
}
