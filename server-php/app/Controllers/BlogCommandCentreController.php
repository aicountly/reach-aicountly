<?php

namespace App\Controllers;

use App\Controllers\BaseApiController;
use App\Libraries\Ai\AiProviderRegistry;
use App\Libraries\Ai\Images\ImageProviderRegistry;
use App\Libraries\Blog\BlogFeatureFlags;
use App\Libraries\Blog\BlogPortfolioService;
use App\Libraries\Blog\Roadmap\ScoringWeights;
use App\Libraries\Intelligence\Connectors\ConnectorProviderFactory;
use App\Libraries\Intelligence\Connectors\GoogleSearchConsoleConnector;
use CodeIgniter\Database\RawSql;
use Config\Database;
use App\Libraries\Database\SchemaGuard;

class BlogCommandCentreController extends BaseApiController
{
    /** Window that decides whether publishing failures are still current. */
    private const PUBLISHING_RECENT_DAYS = 7;

    public function overview()
    {
        $db    = Database::connect();
        $flags = new BlogFeatureFlags();

        // Include automation states (brief_*, outline_*, draft_generating, …).
        // Older Overview only counted classic idea/draft and wrongly showed
        // "NO DATA" while a blog was sitting in brief_ready / outline_ready.
        $workflowStatuses = [
            'idea', 'topic_candidate', 'topic_scored', 'roadmap_planned',
            'brief_draft', 'brief_ready', 'outline_draft', 'outline_ready',
            'draft_generating', 'draft', 'fact_verifying', 'fact_verified',
            'seo_review', 'internal_review', 'changes_requested', 'approved',
            'scheduled', 'publish_queued', 'publishing', 'published',
            'verification_pending', 'live', 'blocked', 'failed', 'rejected',
            'archived',
        ];
        $workflowCounts = [];
        foreach ($workflowStatuses as $status) {
            $workflowCounts[$status] = (int) $db->table('reach_content_items')
                ->where('content_type', 'blog')
                ->where('workflow_status', $status)
                ->where('deleted_at IS NULL', null, false)
                ->countAllResults();
        }

        // The Production card counts pre-approval stages only. Without a total
        // it cannot tell "no blog content exists" from "every blog has already
        // moved past production" — and it reported both as NO DATA.
        $blogTotal = (int) $db->table('reach_content_items')
            ->where('content_type', 'blog')
            ->where('deleted_at IS NULL', null, false)
            ->countAllResults();

        $portfolio = (new BlogPortfolioService())->get();

        $publishing = $this->publishingSummary($db);

        $workBlocks = [
            'pending'   => (int) $db->table('reach_work_blocks')->where('eligibility_status', 'pending')->countAllResults(),
            'eligible'  => (int) $db->table('reach_work_blocks')->where('eligibility_status', 'eligible')->countAllResults(),
            'queued'    => (int) $db->table('reach_work_blocks')->where('eligibility_status', 'queued')->countAllResults(),
            'completed' => (int) $db->table('reach_work_blocks')->where('eligibility_status', 'completed')->countAllResults(),
        ];

        return $this->ok([
            'workflow_counts' => $workflowCounts,
            'content'         => ['blog_total' => $blogTotal],
            'portfolio'       => [
                'marketing_percent'          => (int) ($portfolio['marketing_percent'] ?? 45),
                'product_percent'            => (int) ($portfolio['product_percent'] ?? 35),
                'problem_to_product_percent' => (int) ($portfolio['problem_to_product_percent'] ?? 20),
            ],
            'publishing'      => $publishing,
            'work_blocks'     => $workBlocks,
            'connectors'      => $this->connectorStates($flags),
            'ai_providers'    => $this->aiProviderStates($flags),
            'optimizer'       => $this->optimizerSummary($db, $flags),
            'automation'      => [
                'enabled' => $flags->isEnabled('automation'),
            ],
        ]);
    }

    /**
     * Deployment counts for the Publishing card.
     *
     * Scoped to blog content: this table also carries knowledge-base
     * deployments, and counting those made the Blog dashboard report work it
     * does not own. `failed_recent` drives the card's status so a single old
     * failure cannot pin it to ERROR forever, while `failed` keeps the
     * lifetime total for context.
     *
     * @return array<string, int>
     */
    private function publishingSummary($db): array
    {
        $empty = [
            'queued'        => 0,
            'sending'       => 0,
            'in_flight'     => 0,
            'failed'        => 0,
            'failed_recent' => 0,
            'published'     => 0,
            'verified'      => 0,
            'recent_days'   => self::PUBLISHING_RECENT_DAYS,
        ];

        if (
            ! SchemaGuard::hasTable($db, 'reach_publication_deployments')
            || ! SchemaGuard::hasTable($db, 'reach_content_items')
        ) {
            return $empty;
        }

        $blogCount = function (array $statuses, ?string $since = null) use ($db): int {
            $builder = $db->table('reach_publication_deployments')
                ->join(
                    'reach_content_items',
                    'reach_content_items.id = reach_publication_deployments.content_item_id',
                    'inner',
                )
                ->where('reach_content_items.content_type', 'blog')
                ->whereIn('reach_publication_deployments.status', $statuses);

            if ($since !== null) {
                $builder->where('reach_publication_deployments.updated_at >=', $since);
            }

            return (int) $builder->countAllResults();
        };

        $since = date('Y-m-d H:i:s', strtotime('-' . self::PUBLISHING_RECENT_DAYS . ' days'));

        return [
            'queued'        => $blogCount(['queued']),
            'sending'       => $blogCount(['sending']),
            // Anything the pipeline is still working on. Without this the card
            // showed neither the work in progress nor why nothing was moving.
            'in_flight'     => $blogCount(['queued', 'sending', 'accepted', 'scheduled', 'verification_pending']),
            'failed'        => $blogCount(['failed', 'blocked']),
            'failed_recent' => $blogCount(['failed', 'blocked'], $since),
            'published'     => $blogCount(['published']),
            'verified'      => $blogCount(['verified']),
            'recent_days'   => self::PUBLISHING_RECENT_DAYS,
        ];
    }

    /**
     * Report the true state of each external connector so the dashboard can show
     * DISABLED, MISCONFIGURED or CONNECTED rather than implying data exists.
     *
     * @return array<string, array<string, mixed>>
     */
    private function connectorStates(BlogFeatureFlags $flags): array
    {
        $searchConsole = [
            'status' => 'DISABLED',
            'detail' => 'Search Console is disabled for blogs. Set BLOG_SEARCH_CONSOLE_ENABLED=true to surface live state here.',
        ];

        if ($flags->isEnabled('search_console')) {
            $connector = ConnectorProviderFactory::searchConsole();

            if ($connector instanceof GoogleSearchConsoleConnector) {
                $state         = $connector->configurationState();
                $searchConsole = [
                    'status'        => $state['status'] === 'configured' ? 'CONNECTED' : strtoupper($state['status']),
                    'detail'        => $state['detail'],
                    'site_property' => $state['site_property'],
                    'provider'      => $connector->providerName(),
                ];
            } else {
                $searchConsole = [
                    'status'   => 'MOCK',
                    'detail'   => 'Mock Search Console connector is active; figures are not real.',
                    'provider' => $connector->providerName(),
                ];
            }
        }

        // Whether the card says CONNECTED or DISABLED, report what has actually
        // been ingested. A connected connector with an empty fact table is a
        // different problem from a switched-off one, and the card should say so.
        $searchConsole += $this->searchIngestionSummary();

        return [
            'search_console' => $searchConsole,
            'ga4'            => [
                'status' => $flags->isEnabled('ga4') ? 'ENABLED' : 'DISABLED',
                'detail' => $flags->isEnabled('ga4')
                    ? 'GA4 ingestion is enabled.'
                    : 'GA4 is disabled for blogs.',
            ],
        ];
    }

    /**
     * How much search data has genuinely landed, and when it last did.
     *
     * @return array<string, mixed>
     */
    private function searchIngestionSummary(): array
    {
        $db      = Database::connect();
        $summary = [
            'facts_28d'          => 0,
            'latest_metric_date' => null,
            'last_ingest_at'     => null,
            'unmapped_urls'      => 0,
        ];

        try {
            if (SchemaGuard::hasTable($db, 'reach_search_metric_facts')) {
                $row = $db->query(
                    'SELECT COUNT(*) AS c, MAX(metric_date) AS latest, MAX(collected_at) AS collected
                     FROM reach_search_metric_facts WHERE metric_date >= ?',
                    [date('Y-m-d', strtotime('-28 days'))]
                )->getRowArray() ?: [];

                $summary['facts_28d']          = (int) ($row['c'] ?? 0);
                $summary['latest_metric_date'] = $row['latest'] ?? null;
                $summary['last_ingest_at']     = $row['collected'] ?? null;
            }

            if (SchemaGuard::hasTable($db, 'reach_content_mapping_findings')) {
                $summary['unmapped_urls'] = (int) $db->table('reach_content_mapping_findings')
                    ->where('resolution_status', 'unresolved')
                    ->countAllResults();
            }
        } catch (\Throwable $e) {
            log_message('error', 'BlogCommandCentreController::searchIngestionSummary: ' . $e->getMessage());
        }

        return $summary;
    }

    /**
     * Which AI providers are actually usable in this environment. Configuration is
     * read from the adapters themselves, never from the database.
     *
     * @return array<string, mixed>
     */
    private function aiProviderStates(BlogFeatureFlags $flags): array
    {
        $text  = [];
        $image = [];

        try {
            $registry = new AiProviderRegistry();
            foreach ($registry->allKeys() as $key) {
                if ($key === 'mock') {
                    continue;
                }
                $text[$key] = $registry->resolve($key)->isConfigured() ? 'CONFIGURED' : 'NOT_CONFIGURED';
            }
        } catch (\Throwable) {
            $text = [];
        }

        try {
            $imageRegistry = new ImageProviderRegistry();
            foreach ($imageRegistry->allKeys() as $key) {
                $image[$key] = $imageRegistry->resolve($key)->isConfigured() ? 'CONFIGURED' : 'NOT_CONFIGURED';
            }
        } catch (\Throwable) {
            $image = [];
        }

        return [
            'generation_enabled'   => $flags->isEnabled('ai_generation'),
            'verification_enabled' => $flags->isEnabled('fact_verification'),
            // The cover block's own switch (automation.blog.ai_cover, BLOG_IMAGE_GENERATION_ENABLED kill switch).
            'images_enabled'       => \App\Libraries\Automation\Routine\RoutineFlags::enabled('blog.ai_cover'),
            'text'                 => $text,
            'image'                => $image,
        ];
    }

    /**
     * Latest roadmap optimiser run, or an explicit no-run state.
     *
     * @return array<string, mixed>
     */
    private function optimizerSummary($db, BlogFeatureFlags $flags): array
    {
        $summary = [
            'enabled'  => $flags->isEnabled('roadmap_optimizer'),
            'last_run' => null,
        ];

        if (! SchemaGuard::hasTable($db, 'reach_optimizer_runs')) {
            return $summary;
        }

        $row = $db->table('reach_optimizer_runs')
            ->orderBy('id', 'DESC')
            ->limit(1)
            ->get()
            ->getRowArray();

        if ($row) {
            $summary['last_run'] = [
                'id'                    => (int) $row['id'],
                'run_for_date'          => $row['run_for_date'] ?? null,
                'status'                => $row['status'] ?? null,
                'candidates_scored'     => (int) ($row['candidates_scored'] ?? 0),
                'decisions_created'     => (int) ($row['decisions_created'] ?? 0),
                'work_blocks_created'   => (int) ($row['work_blocks_created'] ?? 0),
                'skipped_reason'        => $row['skipped_reason'] ?? null,
                'completed_at'          => $row['completed_at'] ?? null,
            ];
        }

        return $summary;
    }

    public function getPortfolio()
    {
        return $this->ok((new BlogPortfolioService())->get());
    }

    public function updatePortfolio()
    {
        $body = $this->input();
        try {
            $row = (new BlogPortfolioService())->update($body, $this->userId());
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        $this->audit('blog.portfolio_updated', 'blog_portfolio_config', (int) ($row['id'] ?? 0), null, $row);
        return $this->ok($row);
    }

    public function getSettings()
    {
        $portfolio = (new BlogPortfolioService())->get();
        $flags     = new BlogFeatureFlags();

        return $this->ok([
            'portfolio' => $portfolio,
            'flags'     => [
                'command_centre'    => $flags->isEnabled('command_centre'),
                'automation'        => $flags->isEnabled('automation'),
                'ai_generation'     => $flags->isEnabled('ai_generation'),
                'auto_publish'      => $flags->isEnabled('auto_publish'),
                'public_publisher'  => $flags->isEnabled('public_publisher'),
                'search_console'    => $flags->isEnabled('search_console'),
                'ga4'               => $flags->isEnabled('ga4'),
            ],
        ]);
    }

    public function updateSettings()
    {
        $body      = $this->input();
        $portfolio = new BlogPortfolioService();
        $updated   = $portfolio->get();

        if (isset($body['marketing_percent']) || isset($body['problem_to_product_percent']) || isset($body['product_percent'])) {
            try {
                $updated = $portfolio->update(array_merge($updated, $body), $this->userId());
            } catch (\InvalidArgumentException $e) {
                return $this->fail($e->getMessage(), 422);
            }
        } elseif (isset($body['settings_json'])) {
            $updated = $portfolio->update([
                'marketing_percent'          => $updated['marketing_percent'] ?? 45,
                'product_percent'            => $updated['product_percent'] ?? 35,
                'problem_to_product_percent' => $updated['problem_to_product_percent'] ?? 20,
                'settings_json'              => $body['settings_json'],
            ], $this->userId());
        }

        $this->audit('blog.settings_updated', 'blog_portfolio_config', (int) ($updated['id'] ?? 0), null, $updated);
        return $this->ok($updated);
    }

    public function roadmapCandidates()
    {
        [$page, $limit, $offset] = $this->pagination();
        $db = Database::connect();

        if (! SchemaGuard::hasTable($db, 'reach_topic_candidates')) {
            return $this->ok(['items' => [], 'total' => 0, 'page' => $page, 'limit' => $limit]);
        }

        $status = trim((string) $this->request->getGet('status'));
        $stream = trim((string) $this->request->getGet('portfolio_stream'));

        $builder = $db->table('reach_topic_candidates tc')
            ->select('tc.*, ts.total_score, ts.scored_for_date, ts.trend_7d, ts.trend_28d')
            ->join(
                'reach_topic_scores ts',
                // RawSql, not a plain string: the query builder escapes every
                // token of a string ON clause as an identifier, so the boolean
                // literal would compile to `"ts"."is_current" = "true"` and
                // Postgres rejects it with `column "true" does not exist`.
                new RawSql('ts.topic_candidate_id = tc.id AND ts.is_current = TRUE'),
                'left',
            );

        if ($status !== '') {
            $builder->where('tc.status', $status);
        }
        if ($stream !== '') {
            $builder->where('tc.portfolio_stream', $stream);
        }

        $total = $builder->countAllResults(false);
        // NULLS LAST keeps not-yet-scored candidates below scored ones;
        // Postgres sorts NULLs first on DESC by default.
        $rows  = $builder->orderBy('ts.total_score DESC NULLS LAST', '', false)
            ->orderBy('tc.updated_at', 'DESC')
            ->limit($limit, $offset)
            ->get()
            ->getResultArray();

        // Postgres booleans come back as "t"/"f"; "f" is truthy in the
        // console, which showed every candidate as Pinned + Locked.
        foreach ($rows as &$row) {
            foreach (['is_locked', 'is_human_pinned', 'evidence_ready'] as $flag) {
                if (array_key_exists($flag, $row)) {
                    $row[$flag] = \App\Libraries\Support\PgBoolean::isTrue($row[$flag]);
                }
            }
            $row['in_routine_queue'] = ($row['status'] ?? '') === 'roadmap_selected' && empty($row['content_item_id']);
        }
        unset($row);

        return $this->ok(['items' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit]);
    }

    public function roadmapScored()
    {
        [$page, $limit, $offset] = $this->pagination();
        $db = Database::connect();

        if (! SchemaGuard::hasTable($db, 'reach_topic_scores')) {
            return $this->ok(['items' => [], 'total' => 0, 'page' => $page, 'limit' => $limit]);
        }

        $builder = $db->table('reach_topic_scores ts')
            ->select('ts.*, tc.title, tc.portfolio_stream, tc.status AS candidate_status, tc.content_item_id')
            ->join('reach_topic_candidates tc', 'tc.id = ts.topic_candidate_id', 'inner');

        // An optimiser run's "Scored" count is the scores it wrote for its
        // date — later runs may have superseded them, so this view is not
        // limited to the current score.
        $scoredFor = trim((string) $this->request->getGet('scored_for_date'));
        if ($scoredFor !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $scoredFor) === 1) {
            $builder->where('ts.scored_for_date', $scoredFor);
        } else {
            $builder->where('ts.is_current', true);
        }

        $total = $builder->countAllResults(false);
        $rows  = $builder->orderBy('ts.total_score', 'DESC')
            ->limit($limit, $offset)
            ->get()
            ->getResultArray();

        return $this->ok(['items' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit]);
    }

    public function roadmapDecisions()
    {
        [$page, $limit, $offset] = $this->pagination();
        $db = Database::connect();

        if (! SchemaGuard::hasTable($db, 'reach_roadmap_decisions')) {
            return $this->ok(['items' => [], 'total' => 0, 'page' => $page, 'limit' => $limit]);
        }

        $decision = trim((string) $this->request->getGet('decision'));
        $builder  = $db->table('reach_roadmap_decisions rd')
            ->select('rd.*, tc.title')
            ->join('reach_topic_candidates tc', 'tc.id = rd.topic_candidate_id', 'left');

        if ($decision !== '') {
            $builder->where('rd.decision', $decision);
        }
        // One optimiser run's decisions (its "Decisions" count) and the ones
        // that queued a work block (its "Work blocks" count).
        $runId = (int) $this->request->getGet('run_id');
        if ($runId > 0) {
            $builder->where('rd.optimizer_run_id', $runId);
        }
        if ((string) $this->request->getGet('has_work_block') === '1') {
            $builder->where('rd.work_block_id IS NOT NULL', null, false);
        }

        $total = $builder->countAllResults(false);
        $rows  = $builder->orderBy('rd.decided_for_date', 'DESC')
            ->orderBy('rd.id', 'DESC')
            ->limit($limit, $offset)
            ->get()
            ->getResultArray();

        return $this->ok(['items' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit]);
    }

    /**
     * reach_work_blocks for the AI Ops card's "work blocks queued" and an
     * optimiser run's "Work blocks" count.
     *
     *   ?status=queued[,eligible…]  eligibility_status (the card counts one each)
     *   ?run_id=                    blocks a run's decisions queued (rd.work_block_id)
     *   ?block_type= ?content_item_id=
     *
     * Each row carries its content item and the roadmap decision that queued
     * it (if any), so it can open /content/:id or the run's decisions.
     */
    public function workBlocks()
    {
        [$page, $limit, $offset] = $this->pagination();
        $db = Database::connect();

        if (! SchemaGuard::hasTable($db, 'reach_work_blocks')) {
            return $this->ok(['items' => [], 'total' => 0, 'page' => $page, 'limit' => $limit]);
        }
        $hasDecisions = SchemaGuard::hasTable($db, 'reach_roadmap_decisions');

        $where = ['1=1'];
        $binds = [];
        $statuses = array_values(array_filter(array_map('trim', explode(',', (string) $this->request->getGet('status')))));
        if ($statuses !== []) {
            $where[] = 'wb.eligibility_status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
            array_push($binds, ...$statuses);
        }
        $blockType = trim((string) $this->request->getGet('block_type'));
        if ($blockType !== '') {
            $where[] = 'wb.block_type = ?';
            $binds[] = $blockType;
        }
        $contentId = (int) $this->request->getGet('content_item_id');
        if ($contentId > 0) {
            $where[] = 'wb.content_item_id = ?';
            $binds[] = $contentId;
        }
        $runId = (int) $this->request->getGet('run_id');
        if ($runId > 0) {
            if (! $hasDecisions) {
                return $this->ok(['items' => [], 'total' => 0, 'page' => $page, 'limit' => $limit]);
            }
            $where[] = 'wb.id IN (SELECT work_block_id FROM reach_roadmap_decisions WHERE optimizer_run_id = ? AND work_block_id IS NOT NULL)';
            $binds[] = $runId;
        }
        $whereSql = implode(' AND ', $where);

        // A block is queued by at most one decision; LATERAL … LIMIT 1 keeps a
        // stray duplicate from doubling the row.
        $decisionJoin = $hasDecisions
            ? 'LEFT JOIN LATERAL (SELECT id, decision, optimizer_run_id, decided_for_date FROM reach_roadmap_decisions d
                                  WHERE d.work_block_id = wb.id ORDER BY d.id DESC LIMIT 1) rd ON TRUE'
            : '';
        $decisionCols = $hasDecisions
            ? 'rd.id AS decision_id, rd.decision, rd.optimizer_run_id, rd.decided_for_date'
            : 'NULL AS decision_id, NULL AS decision, NULL AS optimizer_run_id, NULL AS decided_for_date';

        $total = (int) ($db->query("SELECT COUNT(*) AS c FROM reach_work_blocks wb WHERE {$whereSql}", $binds)->getRowArray()['c'] ?? 0);
        $rows  = $db->query(
            "SELECT wb.id, wb.work_block_uuid, wb.block_type, wb.scope, wb.content_item_id, wb.content_version_id,
                    wb.eligibility_status, wb.priority, wb.scheduled_at, wb.attempt_count, wb.max_attempts,
                    wb.provider_route, wb.failure_classification, wb.job_id, wb.created_at, wb.started_at,
                    wb.completed_at, wb.updated_at,
                    ci.title AS content_title, ci.workflow_status AS content_status, {$decisionCols}
             FROM reach_work_blocks wb
             LEFT JOIN reach_content_items ci ON ci.id = wb.content_item_id
             {$decisionJoin}
             WHERE {$whereSql}
             ORDER BY wb.priority DESC, wb.created_at DESC, wb.id DESC
             LIMIT {$limit} OFFSET {$offset}",
            $binds
        )->getResultArray();

        return $this->ok(['items' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit]);
    }

    public function optimizerRuns()
    {
        [$page, $limit, $offset] = $this->pagination();
        $db = Database::connect();

        if (! SchemaGuard::hasTable($db, 'reach_optimizer_runs')) {
            return $this->ok(['items' => [], 'total' => 0, 'page' => $page, 'limit' => $limit]);
        }

        $builder = $db->table('reach_optimizer_runs');
        $total   = $builder->countAllResults(false);
        $rows    = $builder->orderBy('run_for_date', 'DESC')
            ->orderBy('id', 'DESC')
            ->limit($limit, $offset)
            ->get()
            ->getResultArray();

        return $this->ok(['items' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit]);
    }

    public function scoringWeights()
    {
        $db = Database::connect();
        if (! SchemaGuard::hasTable($db, 'reach_roadmap_scoring_weights')) {
            return $this->ok(ScoringWeights::defaults()->toArray());
        }

        $row = $db->table('reach_roadmap_scoring_weights')->orderBy('id', 'ASC')->get()->getRowArray();
        if (! $row) {
            return $this->ok(ScoringWeights::defaults()->toArray());
        }

        if (isset($row['deductions_json']) && is_string($row['deductions_json'])) {
            $row['deductions_json'] = json_decode($row['deductions_json'], true);
        }

        return $this->ok($row);
    }

    public function updateScoringWeights()
    {
        $body = $this->input();
        $db   = Database::connect();

        try {
            $weights = ScoringWeights::fromArray($body);
            $weights->assertValid();
            if ($weights->sum() !== 100) {
                return $this->fail('Scoring weights must sum to 100.', 422);
            }
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        $payload = [
            'search_opportunity'   => $weights->searchOpportunity,
            'product_priority'     => $weights->productPriority,
            'audience_problem'     => $weights->audienceProblem,
            'conversion_potential' => $weights->conversionPotential,
            'content_gap'          => $weights->contentGap,
            'seasonality'          => $weights->seasonality,
            'internal_link_value'  => $weights->internalLinkValue,
            'evidence_readiness'   => $weights->evidenceReadiness,
            'deductions_json'      => json_encode($weights->deductions, JSON_UNESCAPED_SLASHES),
            'updated_by'           => $this->userId(),
            'updated_at'           => date('Y-m-d H:i:s'),
        ];

        if (! SchemaGuard::hasTable($db, 'reach_roadmap_scoring_weights')) {
            return $this->fail('Scoring weights table is not available.', 503);
        }

        $existing = $db->table('reach_roadmap_scoring_weights')->orderBy('id', 'ASC')->get()->getRowArray();
        if ($existing) {
            $db->table('reach_roadmap_scoring_weights')->where('id', $existing['id'])->update($payload);
            $id = (int) $existing['id'];
        } else {
            $payload['created_at'] = date('Y-m-d H:i:s');
            $db->table('reach_roadmap_scoring_weights')->insert($payload);
            $id = (int) $db->insertID();
        }

        $row = $db->table('reach_roadmap_scoring_weights')->where('id', $id)->get()->getRowArray();
        $this->audit('blog.scoring_weights_updated', 'reach_roadmap_scoring_weights', $id, null, $row ?? $payload);

        return $this->ok($row);
    }
}
