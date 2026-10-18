<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use App\Libraries\Database\SchemaGuard;
use App\Libraries\Jobs\JobQueueInsights;

/**
 * Builds the marketing dashboard summary from the tables that actually hold
 * live data today.
 *
 * Background: blog/social work used to live in the flat `reach_blog_posts` /
 * `reach_social_posts` tables. Phase 2 moved authoring into the unified
 * Content Studio (`reach_content_items` + per-type detail tables) and the blog
 * automation pipeline writes there exclusively (see BlogCommandCentreController
 * and ReachBlogMigrateLegacy). The dashboard kept counting the legacy tables
 * only, so every tile read 0 on a portal that was in fact busy.
 *
 * Every figure below is therefore the union of:
 *   - the current source of truth (`reach_content_items`, `reach_jobs`,
 *     `reach_content_schedules`, …), and
 *   - the legacy table, for rows that were never migrated across.
 *
 * Legacy blog rows that already have a Content Studio twin (`content_item_id`
 * set, or a completed row in `reach_blog_legacy_migration_map`) are excluded so
 * a migrated post is counted exactly once.
 *
 * Counting is done with one grouped query per table rather than one query per
 * status, so the whole summary costs ~14 queries instead of ~40.
 */
class DashboardSummaryService
{
    // ---- Content Studio workflow_status buckets -------------------------
    // Vocabulary comes from the rci_workflow_status_chk constraint: the
    // ContentWorkflowService (manual) states plus the BlogStateMachine
    // (automation) states.

    private const IDEAS = [
        'idea', 'topic_candidate', 'topic_scored', 'roadmap_planned',
        'brief', 'brief_draft', 'brief_ready', 'outline_draft', 'outline_ready',
    ];

    private const DRAFTS = [
        'draft', 'draft_generating', 'changes_requested',
        'fact_verifying', 'fact_verified',
    ];

    private const IN_REVIEW = [
        'validation_pending', 'review_pending', 'seo_review', 'internal_review',
    ];

    private const APPROVED = ['approved'];

    /**
     * Content with a publish date: `scheduled`, and `ready_for_publication`
     * (ContentScheduleReadinessJob's state for a schedule whose date came).
     * `publish_queued` used to be counted here too, so seven blog items stuck
     * in the publish queue read as "7 scheduled" while the calendar — which
     * needs a date — showed nothing. They are counted as publishing below.
     */
    private const SCHEDULED = ['scheduled', 'ready_for_publication'];

    /** Handed to the publisher but not confirmed live yet. */
    private const PUBLISHING = ['publish_queued', 'publishing', 'verification_pending'];

    private const PUBLISHED = ['published', 'live'];

    private const NEEDS_ATTENTION = ['failed', 'blocked', 'rejected'];

    private const ARCHIVED = ['archived', 'unpublished'];

    /** Content Studio statuses that mean "queued to go out on a channel". */
    private const CHANNEL_QUEUE = [
        'approved', 'scheduled', 'ready_for_publication', 'publish_queued', 'publishing',
    ];

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * Full dashboard payload.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $blog      = $this->blog();
        $campaigns = $this->campaigns();
        $social    = $this->social();
        $leads     = $this->leads();
        $approvals = $this->approvals();
        $bot       = $this->bot();
        $content   = $this->contentStudio();
        $jobs      = (new JobQueueInsights($this->db))->summary(24);

        return [
            'blog'              => $blog,
            'campaigns'         => $campaigns,
            'social'            => $social,
            'leads'             => $leads,
            'approvals'         => $approvals,
            'bot'               => $bot,
            'jobs'              => $jobs,
            'content'           => $content,
            'calendar_upcoming' => $this->calendarUpcoming(),
            'calendar_overdue'  => $this->calendarOverdue(),
            'generated_at'      => date('c'),
        ];
    }

    /**
     * Sidebar badge counts. Same sources as summary(), narrower payload.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $blog      = $this->blog();
        $social    = $this->social();
        $leads     = $this->leads();
        $approvals = $this->approvals();
        $content   = $this->contentStudio();
        $jobs      = (new JobQueueInsights($this->db))->summary(24);

        return [
            'blog'               => $blog['ideas'] + $blog['drafts'] + $blog['in_review'],
            'approvals'          => $approvals['pending'],
            'leads_pending_push' => $leads['pending_push'] + $leads['failed'] + $leads['retry_scheduled'],
            'social_queue'       => $social['queue'],
            'bot_queue_running'  => (int) ($jobs['running'] ?? 0),
            'content_in_review'  => $content['in_review'],
        ];
    }

    // ---------------------------------------------------------------- blog

    /** @return array<string, int> */
    private function blog(): array
    {
        $items  = $this->contentCounts('blog');
        $legacy = $this->legacyBlogCounts();

        // Legacy publishing_status lives in its own column.
        $legacyPublishing = $this->groupCounts('reach_blog_posts', 'publishing_status', [
            'raw' => $this->legacyBlogNotMigrated(),
        ]);

        $publishing = $this->pick($items, self::PUBLISHING)
            + ($legacyPublishing['pending_publishing'] ?? 0);

        return [
            'total'              => $this->sum($items) + $this->sum($legacy),
            'ideas'              => $this->pick($items, self::IDEAS) + $this->pick($legacy, ['idea']),
            'drafts'             => $this->pick($items, self::DRAFTS) + $this->pick($legacy, ['draft']),
            'in_review'          => $this->pick($items, self::IN_REVIEW)
                                    + $this->pick($legacy, ['seo_review', 'internal_review']),
            'approved'           => $this->pick($items, self::APPROVED) + $this->pick($legacy, ['approved']),
            'scheduled'          => $this->pick($items, self::SCHEDULED) + $this->pick($legacy, ['scheduled']),
            'published'          => $this->pick($items, self::PUBLISHED) + $this->pick($legacy, ['published']),
            'pending_publishing' => $publishing,
            'needs_attention'    => $this->pick($items, self::NEEDS_ATTENTION),
            'archived'           => $this->pick($items, self::ARCHIVED) + $this->pick($legacy, ['archived']),
        ];
    }

    /**
     * Blog rows still living only in the legacy table.
     *
     * @return array<string, int>
     */
    private function legacyBlogCounts(): array
    {
        return $this->groupCounts('reach_blog_posts', 'status', [
            'raw' => $this->legacyBlogNotMigrated(),
        ]);
    }

    /**
     * Predicates that exclude legacy blog posts already represented by a
     * Content Studio item, so migrated posts are not double counted.
     *
     * @return list<string>
     */
    private function legacyBlogNotMigrated(): array
    {
        $raw = [];

        if ($this->columnExists('reach_blog_posts', 'content_item_id')) {
            $raw[] = 'content_item_id IS NULL';
        }

        if ($this->hasTable('reach_blog_legacy_migration_map')) {
            $raw[] = 'NOT EXISTS (SELECT 1 FROM reach_blog_legacy_migration_map m '
                . "WHERE m.legacy_blog_post_id = reach_blog_posts.id AND m.status = 'completed')";
        }

        return $raw;
    }

    // ----------------------------------------------------------- campaigns

    /** @return array<string, int> */
    private function campaigns(): array
    {
        $c = $this->groupCounts('reach_campaigns', 'status');

        // Distribution dispatches are what actually goes out for a campaign.
        $d = $this->groupCounts('reach_campaign_dispatches', 'status');

        // reach_campaigns has no `running` status (rcs constraint): a live
        // campaign is `active` or `dispatching`. The tile read 0 for ever and
        // added dispatch rows (a different entity) on top.
        return [
            'total'            => $this->sum($c),
            'draft'            => $this->pick($c, ['draft']),
            'pending_approval' => $this->pick($c, ['pending_approval', 'ready_for_review', 'in_review']),
            'approved'         => $this->pick($c, ['approved']),
            'running'          => $this->pick($c, ['active', 'dispatching', 'running']),
            'paused'           => $this->pick($c, ['paused']),
            'completed'        => $this->pick($c, ['completed']),
            'dispatches_queued' => $this->pick($d, ['queued']),
            'dispatches_failed' => $this->pick($d, ['failed', 'dead_lettered']),
        ];
    }

    // -------------------------------------------------------------- social

    /** @return array<string, int> */
    private function social(): array
    {
        $items  = $this->contentCounts('social_post');
        $legacy = $this->groupCounts('reach_social_posts', 'status');

        // `queue` is exactly what the Social queue page lists
        // (SocialQueueController: reach_social_posts approved / scheduled /
        // manual_queue). Content Studio social items waiting on a channel are
        // reported apart as content_queue — the page does not show them.
        return [
            'total'  => $this->sum($items) + $this->sum($legacy),
            'draft'  => $this->pick($items, array_merge(self::IDEAS, self::DRAFTS))
                        + $this->pick($legacy, ['draft']),
            'review' => $this->pick($items, self::IN_REVIEW) + $this->pick($legacy, ['pending_approval']),
            'queue'  => $this->pick($legacy, ['approved', 'scheduled', 'manual_queue']),
            'publishing'    => $this->pick($legacy, ['publishing']),
            'content_queue' => $this->pick($items, self::CHANNEL_QUEUE),
            'posted' => $this->pick($items, self::PUBLISHED) + $this->pick($legacy, ['posted']),
            'failed' => $this->pick($items, self::NEEDS_ATTENTION) + $this->pick($legacy, ['failed']),
        ];
    }

    // --------------------------------------------------------------- leads

    /** @return array<string, int> */
    private function leads(): array
    {
        $l = $this->groupCounts('reach_leads', 'engage_push_status');

        return [
            'total'           => $this->sum($l),
            'pending_push'    => $this->pick($l, ['pending_push']),
            'pushed'          => $this->pick($l, ['pushed']),
            'failed'          => $this->pick($l, ['failed']),
            'duplicate'       => $this->pick($l, ['duplicate']),
            'retry_scheduled' => $this->pick($l, ['retry_scheduled']),
        ];
    }

    // ----------------------------------------------------------- approvals

    /**
     * ContentWorkflowService writes a reach_approvals row per stage for every
     * content item, so reach_approvals already covers Content Studio work —
     * counting content items again here would double count.
     *
     * @return array<string, int>
     */
    private function approvals(): array
    {
        $a       = $this->groupCounts('reach_approvals', 'decision');
        $bySubject = $this->groupCounts('reach_approvals', 'subject_type', [
            'where' => ['decision' => 'pending'],
        ]);

        return [
            'pending'       => $this->pick($a, ['pending']),
            'approved'      => $this->pick($a, ['approved']),
            'rejected'      => $this->pick($a, ['rejected']),
            'total'         => $this->sum($a),
            'pending_blog'  => $this->pick($bySubject, ['blog', 'content_item']),
            'pending_other' => max(0, $this->pick($a, ['pending']) - $this->pick($bySubject, ['blog', 'content_item'])),
        ];
    }

    // ----------------------------------------------------------------- bot

    /**
     * The Bot queue (manual bot dispatches) and its reports. Queue figures
     * for reach_jobs live in `jobs` (JobQueueInsights). These used to be
     * summed with reach_jobs, which counted every bot dispatch twice (its
     * queue row and its job) and mixed lifetime totals into "running".
     *
     * @return array<string, mixed>
     */
    private function bot(): array
    {
        $queue   = $this->groupCounts('reach_marketing_bot_queue', 'status');
        $reports = $this->groupCounts('reach_marketing_bot_reports', 'approval_status');
        $mode    = null;
        try {
            $mode = $this->hasTable('reach_bot_settings') ? (new \App\Models\BotSettingModel())->currentMode() : null;
        } catch (\Throwable) {
        }

        return [
            'mode'            => $mode,
            'reports_total'   => $this->sum($reports),
            'reports_pending' => $this->pick($reports, ['pending']),
            'dispatch_queued' => $this->pick($queue, ['queued']),
            'dispatch_failed' => $this->pick($queue, ['failed']),
        ];
    }

    // ------------------------------------------------------- content studio

    /**
     * Cross-type Content Studio totals — the portal produces knowledge base,
     * community, video, email and landing content that no legacy tile covered.
     *
     * @return array<string, int>
     */
    private function contentStudio(): array
    {
        $all = $this->groupCounts('reach_content_items', 'workflow_status', [
            'raw' => ['deleted_at IS NULL'],
        ]);

        $byType = $this->groupCounts('reach_content_items', 'content_type', [
            'raw' => ['deleted_at IS NULL'],
        ]);

        return [
            'total'      => $this->sum($all),
            'ideas'      => $this->pick($all, self::IDEAS),
            'drafts'     => $this->pick($all, self::DRAFTS),
            'in_review'  => $this->pick($all, self::IN_REVIEW),
            'approved'   => $this->pick($all, self::APPROVED),
            'scheduled'  => $this->pick($all, self::SCHEDULED),
            'published'  => $this->pick($all, self::PUBLISHED),
            'types'      => count($byType),
        ];
    }

    // -------------------------------------------------------- calendar feed

    /**
     * Upcoming work, newest sources first:
     *   1. reach_content_schedules  — the live scheduler
     *   2. reach_content_items      — scheduled items without a schedule row
     *   3. reach_content_calendar_items — the legacy editorial calendar
     *
     * @return list<array<string, mixed>>
     */
    private function calendarUpcoming(int $limit = 10): array
    {
        $today = date('Y-m-d');
        $rows  = [];
        $seen  = [];

        if ($this->hasTable('reach_content_schedules') && $this->hasTable('reach_content_items')) {
            $scheduled = $this->db->table('reach_content_schedules s')
                ->select('s.id, s.scheduled_at, s.schedule_status, i.id AS item_id, i.title, i.content_type')
                ->join('reach_content_items i', 'i.id = s.content_item_id', 'inner')
                ->whereIn('s.schedule_status', ['pending', 'approved', 'ready', 'executing'])
                ->where('s.scheduled_at >=', $today . ' 00:00:00')
                ->where('s.cancelled_at IS NULL', null, false)
                ->where('i.deleted_at IS NULL', null, false)
                ->orderBy('s.scheduled_at', 'ASC')
                ->limit($limit)
                ->get()
                ->getResultArray();

            foreach ($scheduled as $r) {
                $seen[(int) $r['item_id']] = true;
                $rows[] = [
                    'id'        => 'schedule-' . $r['id'],
                    'title'     => $r['title'],
                    'date'      => substr((string) $r['scheduled_at'], 0, 10),
                    'item_kind' => $r['content_type'],
                    'source'    => 'schedule',
                ];
            }
        }

        if ($this->hasTable('reach_content_items')) {
            $items = $this->db->table('reach_content_items')
                ->select('id, title, content_type, scheduled_at')
                ->whereIn('workflow_status', self::SCHEDULED)
                ->where('scheduled_at IS NOT NULL', null, false)
                ->where('scheduled_at >=', $today . ' 00:00:00')
                ->where('deleted_at IS NULL', null, false)
                ->orderBy('scheduled_at', 'ASC')
                ->limit($limit)
                ->get()
                ->getResultArray();

            foreach ($items as $r) {
                if (isset($seen[(int) $r['id']])) {
                    continue;
                }
                $seen[(int) $r['id']] = true;
                $rows[] = [
                    'id'        => 'item-' . $r['id'],
                    'title'     => $r['title'],
                    'date'      => substr((string) $r['scheduled_at'], 0, 10),
                    'item_kind' => $r['content_type'],
                    'source'    => 'content',
                ];
            }
        }

        // Blog SCHEDULE_PUBLISH work blocks schedule through
        // PublicationDeploymentService: the date lives on the deployment, not
        // on reach_content_items.scheduled_at or a content schedule row, so
        // those scheduled blogs never reached this feed.
        if ($this->hasTable('reach_publication_deployments') && $this->hasTable('reach_content_items')) {
            $deployments = $this->db->query(
                "SELECT d.id, d.scheduled_at, i.id AS item_id, i.title, i.content_type
                   FROM reach_publication_deployments d
                   JOIN reach_content_items i ON i.id = d.content_item_id AND i.deleted_at IS NULL
                  WHERE d.status IN ('queued', 'scheduled')
                    AND d.scheduled_at IS NOT NULL
                    AND d.scheduled_at >= ?
                  ORDER BY d.scheduled_at ASC
                  LIMIT ?",
                [$today . ' 00:00:00', $limit]
            )->getResultArray();

            foreach ($deployments as $r) {
                if (isset($seen[(int) $r['item_id']])) {
                    continue;
                }
                $seen[(int) $r['item_id']] = true;
                $rows[] = [
                    'id'        => 'deployment-' . $r['id'],
                    'title'     => $r['title'],
                    'date'      => substr((string) $r['scheduled_at'], 0, 10),
                    'item_kind' => $r['content_type'],
                    'source'    => 'deployment',
                ];
            }
        }

        if ($this->hasTable('reach_content_calendar_items')) {
            $legacy = $this->db->table('reach_content_calendar_items')
                ->select('id, title, date, item_kind')
                ->where('date >=', $today)
                ->orderBy('date', 'ASC')
                ->limit($limit)
                ->get()
                ->getResultArray();

            foreach ($legacy as $r) {
                $rows[] = [
                    'id'        => 'calendar-' . $r['id'],
                    'title'     => $r['title'],
                    'date'      => substr((string) $r['date'], 0, 10),
                    'item_kind' => $r['item_kind'],
                    'source'    => 'calendar',
                ];
            }
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['date'], $b['date']));

        return array_slice($rows, 0, $limit);
    }

    /**
     * Content counted as "scheduled" (SCHEDULED buckets) that the upcoming
     * feed cannot show: its date has passed, or it has none at all. The
     * dashboard lists these under the calendar so "7 scheduled" next to
     * "No upcoming items" is explained instead of contradictory.
     *
     * @return array{count:int, items:list<array<string, mixed>>}
     */
    private function calendarOverdue(int $limit = 10): array
    {
        if (! $this->hasTable('reach_content_items')) {
            return ['count' => 0, 'items' => []];
        }

        $hasSchedules   = $this->hasTable('reach_content_schedules');
        $hasDeployments = $this->hasTable('reach_publication_deployments');
        $dates = ['i.scheduled_at'];
        if ($hasSchedules) {
            $dates[] = "(SELECT MAX(s.scheduled_at) FROM reach_content_schedules s
                          WHERE s.content_item_id = i.id AND s.cancelled_at IS NULL
                            AND s.schedule_status IN ('pending','approved','ready','executing'))";
        }
        if ($hasDeployments) {
            $dates[] = "(SELECT MAX(d.scheduled_at) FROM reach_publication_deployments d
                          WHERE d.content_item_id = i.id AND d.status IN ('queued','scheduled'))";
        }
        $placeholders = implode(',', array_fill(0, count(self::SCHEDULED), '?'));

        $rows = $this->db->query(
            "SELECT * FROM (
                SELECT i.id, i.title, i.content_type, i.workflow_status,
                       GREATEST(" . implode(', ', $dates) . ") AS due_at
                  FROM reach_content_items i
                 WHERE i.deleted_at IS NULL AND i.workflow_status IN ({$placeholders})
             ) x
             WHERE x.due_at IS NULL OR x.due_at < ?
             ORDER BY x.due_at ASC NULLS FIRST, x.id ASC",
            array_merge(self::SCHEDULED, [date('Y-m-d') . ' 00:00:00'])
        )->getResultArray();

        $items = array_map(static fn (array $r): array => [
            'id'              => (int) $r['id'],
            'title'           => $r['title'],
            'content_type'    => $r['content_type'],
            'workflow_status' => $r['workflow_status'],
            'date'            => $r['due_at'] !== null ? substr((string) $r['due_at'], 0, 10) : null,
            'reason'          => $r['due_at'] === null ? 'no_date' : 'date_passed',
        ], array_slice($rows, 0, $limit));

        return ['count' => count($rows), 'items' => $items];
    }

    // ------------------------------------------------------------- helpers

    /**
     * workflow_status histogram for one Content Studio type.
     *
     * @return array<string, int>
     */
    private function contentCounts(string $contentType): array
    {
        return $this->groupCounts('reach_content_items', 'workflow_status', [
            'where' => ['content_type' => $contentType],
            'raw'   => ['deleted_at IS NULL'],
        ]);
    }

    /**
     * One grouped COUNT(*) per table instead of one query per status.
     * Missing tables yield an empty histogram rather than a 500.
     *
     * @param array{where?: array<string, mixed>, raw?: list<string>} $options
     *
     * @return array<string, int>
     */
    private function groupCounts(string $table, string $column, array $options = []): array
    {
        if (! $this->hasTable($table)) {
            return [];
        }

        $builder = $this->db->table($table)
            ->select($column . ' AS bucket, COUNT(*) AS n', false)
            ->groupBy($column);

        foreach ($options['where'] ?? [] as $key => $value) {
            $builder->where($key, $value);
        }
        foreach ($options['raw'] ?? [] as $predicate) {
            $builder->where($predicate, null, false);
        }

        $out = [];
        foreach ($builder->get()->getResultArray() as $row) {
            $out[(string) ($row['bucket'] ?? '')] = (int) $row['n'];
        }

        return $out;
    }

    /**
     * Sum of the given buckets. Keys are deduplicated first so overlapping
     * bucket lists (publish_queued is both "scheduled" and "publishing")
     * never count a row twice within one call.
     *
     * @param array<string, int> $histogram
     * @param list<string>       $keys
     */
    private function pick(array $histogram, array $keys): int
    {
        $total = 0;
        foreach (array_unique($keys) as $key) {
            $total += $histogram[$key] ?? 0;
        }

        return $total;
    }

    /** @param array<string, int> $histogram */
    private function sum(array $histogram): int
    {
        return array_sum($histogram);
    }

    private function hasTable(string $table): bool
    {
        // Not memoised locally: `??=` would also cache a false, turning a
        // table that appears later into a permanent "missing" for this
        // instance. SchemaGuard memoises the positives and re-probes the rest.
        return SchemaGuard::hasTable($this->db, $table);
    }

    /**
     * This used to read getFieldData() directly to dodge the connection's
     * cached column list, which can predate columns added by later migrations
     * (reach_blog_posts gained content_item_id in 100063). SchemaGuard does
     * that for every caller now.
     */
    private function columnExists(string $table, string $column): bool
    {
        return SchemaGuard::hasColumn($this->db, $table, $column);
    }
}
