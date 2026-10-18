<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Libraries\Blog\Roadmap\RoadmapOptimizerService;
use App\Libraries\Content\Base\RoadmapRelease;
use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;
use Config\Database;

/**
 * Daily roadmap rescore without depending on a cPanel cron line.
 *
 * `reach:blog-optimize-roadmap` only runs inside 00:00–00:59 IST and only when
 * its cron line exists; the August scores ("scored for 01–05-08-2026") never
 * moved because selected candidates were excluded from scoring and nothing
 * else rescored them. reach:schedule enqueues this at 01:30 IST: it releases
 * stuck roadmap topics, then runs the optimiser for today's IST date unless a
 * completed run for that date already exists (the cron got there first).
 */
class RoadmapRefreshJob implements JobHandlerInterface
{
    public function handle(array $payload, JobContext $ctx): array
    {
        $date = (string) ($payload['date'] ?? (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d'));
        $out  = ['date' => $date, 'released' => (new RoadmapRelease())->run()];

        $done = Database::connect()->table('reach_optimizer_runs')
            ->where('run_for_date', $date)->where('status', 'completed')
            ->countAllResults() > 0;
        if ($done) {
            return $out + ['optimizer' => 'skipped: a completed run for this date exists'];
        }

        $summary = (new RoadmapOptimizerService())->run($date);
        unset($summary['ranked']);

        return $out + ['optimizer' => $summary];
    }
}
