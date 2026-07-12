<?php

namespace App\Jobs;

use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;
use App\Libraries\DailyMarketingPackService;

/**
 * Generates tomorrow's daily marketing pack if it doesn't already exist.
 * Idempotent: DailyMarketingPackService::generateForDate returns existing pack.
 *
 * Payload: { "date": "YYYY-MM-DD", "market_id": null, "language": "en" }
 */
class DailyMarketingPackJob implements JobHandlerInterface
{
    public function handle(array $payload, JobContext $ctx): array
    {
        $date     = $payload['date']      ?? date('Y-m-d', strtotime('+1 day'));
        $marketId = isset($payload['market_id']) ? (int) $payload['market_id'] : null;
        $language = $payload['language'] ?? 'en';

        $actor = ['id' => null, 'type' => 'system'];
        $svc   = new DailyMarketingPackService();

        $pack = $svc->generateForDate($date, $marketId, $language, $actor);

        return ['ok' => true, 'pack_id' => $pack['id'], 'date' => $date];
    }
}
