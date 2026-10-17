<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;
use App\Libraries\Reports\DailyReportService;

/**
 * The 08:00 Asia/Kolkata daily analytics report (enqueued by reach:schedule
 * on the `notifications` queue, idempotency key daily-report-<date>).
 *
 * Idempotent per report date: a date already sent is skipped. A report that
 * could not be sent (EMAIL_API_KEY missing, gateway rejection) is stored as
 * `failed` with the reason and the job completes — retrying would not fix a
 * missing key, and the Reports page shows the failure and offers "Send now".
 *
 * Payload: { "date"?: "YYYY-MM-DD" }  (IST date; default today in IST)
 */
class DailyAnalyticsReportJob implements JobHandlerInterface
{
    public function handle(array $payload, JobContext $ctx): array
    {
        $date = isset($payload['date']) && is_string($payload['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $payload['date'])
            ? $payload['date']
            : null;

        return (new DailyReportService())->runScheduled($date);
    }
}
