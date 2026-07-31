<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Services;

/**
 * `php spark community:schedule` — periodic housekeeping for Community Q&A.
 *
 * Intended to run every 5 minutes via cron:
 *   asterisk/5 * * * * cd /path/to/server-php && php spark community:schedule
 *
 * Deliberately excludes agent *dispatch* (question_curator / expert_answer_
 * assistant / community_steward / thread_facilitator / review_objection_desk
 * actions via CommunityOperationalAgentService::dispatch()) — dispatch acts
 * on specific content picked by whatever upstream source approved it (a
 * human, an approved intake feed), which this tick has no visibility into.
 * Everything scheduled here is infrastructure-only: detecting drift and
 * retrying/reconciling already-known records, never selecting new content
 * to act on.
 *
 * Every enqueue below carries an idempotency_key derived from the current
 * time bucket so that overlapping or backed-up cron ticks cannot fan out
 * duplicate jobs — a gap in the older reach:schedule hour-gated pattern,
 * which is safe against effect but not against duplicate low-cost enqueues.
 */
class CommunitySchedule extends BaseCommand
{
    protected $group       = 'Reach';
    protected $name        = 'community:schedule';
    protected $description = 'Housekeeping tick for Community Q&A: deployment retries, publication reconciliation, public-site change sync, daily analytics.';
    protected $usage       = 'community:schedule';

    public function run(array $params): int
    {
        $svc    = Services::jobService();
        $bucket = date('Y-m-d-H-i');           // per-minute bucket → safe even if cron overlaps
        $hour   = (int) date('H');
        $today  = date('Y-m-d');

        $enqueuedIds = [];

        // Every tick: sweep deployments whose backoff window has elapsed.
        $enqueuedIds['deployment_retry_sweep'] = $svc->enqueue(
            'reach.community_deployment_retry_sweep',
            [],
            [
                'queue'               => 'community',
                'idempotency_key'     => "community_retry_sweep_{$bucket}",
                'enqueued_actor_type' => 'system',
            ]
        );

        // Every tick: pull the public site's change feed and log drift.
        $enqueuedIds['sync_changes'] = $svc->enqueue(
            'reach.community_sync_changes',
            [],
            [
                'queue'               => 'community',
                'idempotency_key'     => "community_sync_{$bucket}",
                'enqueued_actor_type' => 'system',
            ]
        );

        // Every 15 minutes: reconcile all published answers against the
        // public site's checksums (heavier than the change-feed pull above).
        if ((int) date('i') % 15 === 0) {
            $enqueuedIds['reconciliation'] = $svc->enqueue(
                'reach.community_publication_reconciliation',
                [],
                [
                    'queue'               => 'community',
                    'idempotency_key'     => "community_reconcile_{$today}-{$hour}-" . intdiv((int) date('i'), 15),
                    'enqueued_actor_type' => 'system',
                ]
            );
        }

        // Once per day at 02:00: roll up yesterday's analytics into the cache.
        if ($hour === 2) {
            $yesterday = date('Y-m-d', strtotime('-1 day'));
            $enqueuedIds['analytics_reconciliation'] = $svc->enqueue(
                'reach.community_analytics_reconciliation',
                ['date' => $yesterday],
                [
                    'queue'               => 'community',
                    'idempotency_key'     => "community_analytics_{$yesterday}",
                    'enqueued_actor_type' => 'system',
                ]
            );
        }

        CLI::write(json_encode([
            'event'    => 'community_schedule.tick',
            'ts'       => gmdate('c'),
            'enqueued' => $enqueuedIds,
        ], JSON_UNESCAPED_SLASHES));

        return 0;
    }
}
