<?php

namespace App\Commands;

use App\Enums\CommunityAnswerStatus;
use App\Libraries\Community\OfficialAnswerPublishingService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Config\Services;
use Throwable;

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
 * to act on. Due scheduled publishes are published here (lifecycle promise).
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
    protected $description = 'Housekeeping tick for Community Q&A: scheduled publish, deployment retries, reconciliation, sync, analytics.';
    protected $usage       = 'community:schedule';

    public function run(array $params): int
    {
        $svc    = Services::jobService();
        $bucket = date('Y-m-d-H-i');           // per-minute bucket → safe even if cron overlaps
        $hour   = (int) date('H');
        $today  = date('Y-m-d');

        $enqueuedIds = [];
        $publishedDue = $this->publishDueScheduledAnswers();

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
            'event'          => 'community_schedule.tick',
            'ts'             => gmdate('c'),
            'enqueued'       => $enqueuedIds,
            'scheduled_publish' => $publishedDue,
        ], JSON_UNESCAPED_SLASHES));

        return 0;
    }

    /**
     * Publish answers that were approved and scheduled for a past time.
     * Lifecycle.schedule() promises a dispatcher; this is that dispatcher.
     *
     * @return array{attempted:int,published:int,failed:int}
     */
    private function publishDueScheduledAnswers(): array
    {
        $stats = ['attempted' => 0, 'published' => 0, 'failed' => 0];

        try {
            $db = Database::connect();
            if (! $db->tableExists('reach_community_official_answers')) {
                return $stats;
            }

            $due = $db->table('reach_community_official_answers')
                ->select('id')
                ->where('status', CommunityAnswerStatus::Scheduled->value)
                ->where('scheduled_at IS NOT NULL', null, false)
                ->where('scheduled_at <=', date('Y-m-d H:i:s'))
                ->orderBy('scheduled_at', 'ASC')
                ->limit(10)
                ->get()
                ->getResultArray();

            if ($due === []) {
                return $stats;
            }

            $publisher = new OfficialAnswerPublishingService();
            foreach ($due as $row) {
                $stats['attempted']++;
                try {
                    $publisher->publish((int) $row['id'], null);
                    $stats['published']++;
                } catch (Throwable $e) {
                    $stats['failed']++;
                    log_message('error', 'community scheduled publish failed for answer ' . $row['id'] . ': ' . $e->getMessage());
                }
            }
        } catch (Throwable $e) {
            log_message('error', 'community scheduled publish sweep failed: ' . $e->getMessage());
        }

        return $stats;
    }
}
