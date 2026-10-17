<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Libraries\Intelligence\Seo\SeoTrackerService;
use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;

/**
 * Daily SEO Tracker run (06:00 IST via reach:schedule): DataForSEO SERP checks
 * for the due tracked keywords, capped by seo.tracker.max_keywords_per_day.
 *
 * Without credentials the result is `not_configured` and nothing is written —
 * the job completes rather than failing, so it does not retry five times
 * against an account that does not exist.
 *
 * Payload: { "date"?: "YYYY-MM-DD", "limit"?: int, "force"?: bool }
 */
class SeoTrackerJob implements JobHandlerInterface
{
    public function handle(array $payload, JobContext $ctx): array
    {
        $result = (new SeoTrackerService())->runDaily(
            isset($payload['date']) && is_string($payload['date']) ? $payload['date'] : null,
            isset($payload['limit']) ? max(0, (int) $payload['limit']) : null,
            ! empty($payload['force']),
        );

        // A run where every attempted check failed is a real failure (the
        // queue should retry it); not_configured / disabled are not.
        if (($result['status'] ?? '') === 'failed' && ($result['stopped'] ?? null) === null) {
            throw new \RuntimeException('SEO Tracker: every check failed — ' . implode('; ', $result['errors'] ?? []));
        }

        return $result;
    }
}
