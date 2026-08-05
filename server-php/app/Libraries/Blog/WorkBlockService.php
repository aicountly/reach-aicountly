<?php

namespace App\Libraries\Blog;

use App\Libraries\Ai\AiGenerationInput;
use App\Libraries\Ai\Generation\AiGenerationOrchestrator;
use App\Libraries\Ai\Generation\AiGenerationRequestService;
use App\Libraries\Ai\Images\ImageGenerationInput;
use App\Libraries\Ai\Images\ImageProviderRegistry;
use App\Libraries\Blog\Roadmap\RoadmapOptimizerService;
use App\Libraries\Blog\Roadmap\RoadmapSignalProvider;
use App\Libraries\Blog\Roadmap\ScoringWeights;
use App\Libraries\Blog\Roadmap\TopicScoringService;
use App\Libraries\Blog\Verification\ClaimExtractor;
use App\Libraries\Blog\Verification\CrossReviewRouter;
use App\Libraries\Blog\Verification\FactVerificationService;
use App\Libraries\ContentVersionService;
use App\Libraries\JobService;
use App\Libraries\Publishing\Blog\BlogInternalLinkService;
use App\Libraries\Publishing\Blog\BlogPublishingProfileService;
use App\Libraries\Publishing\Jobs\PublicationDeploymentService;
use App\Libraries\Publishing\Jobs\PublicationRollbackService;
use App\Libraries\Publishing\Connector\PublicSitePublisherFactory;
use App\Libraries\Publishing\Seo\SeoReadinessService;
use App\Models\Content\ContentBriefModel;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Work-block planning and execution layer for blog automation.
 *
 * Every handler either performs a real, verifiable action or leaves the
 * block honestly 'blocked' (never 'completed') with a specific, auditable
 * reason. No handler fabricates a signal, a generated fact, or a success
 * result when its dependency (a feature flag, a configured AI route, a
 * human decision) is unavailable.
 */
class WorkBlockService
{
    public const TYPE_OPTIMIZE_ROADMAP     = 'OPTIMIZE_ROADMAP';
    public const TYPE_DISCOVER_TOPICS      = 'DISCOVER_TOPICS';
    public const TYPE_SCORE_TOPIC          = 'SCORE_TOPIC';
    public const TYPE_GENERATE_BRIEF       = 'GENERATE_BRIEF';
    public const TYPE_GENERATE_OUTLINE     = 'GENERATE_OUTLINE';
    public const TYPE_GENERATE_DRAFT       = 'GENERATE_DRAFT';
    public const TYPE_FACT_VERIFY          = 'FACT_VERIFY';
    public const TYPE_REVISE_CONTENT       = 'REVISE_CONTENT';
    public const TYPE_GENERATE_IMAGE       = 'GENERATE_IMAGE';
    public const TYPE_SEO_OPTIMIZE         = 'SEO_OPTIMIZE';
    public const TYPE_INTERNAL_LINK        = 'INTERNAL_LINK';
    public const TYPE_CROSS_REVIEW         = 'CROSS_REVIEW';
    public const TYPE_HUMAN_REVIEW_GATE    = 'HUMAN_REVIEW_GATE';
    public const TYPE_SCHEDULE_PUBLISH     = 'SCHEDULE_PUBLISH';
    public const TYPE_PUBLISH_BLOG         = 'PUBLISH_BLOG';
    public const TYPE_VERIFY_PUBLICATION   = 'VERIFY_PUBLICATION';
    public const TYPE_REFRESH_CONTENT      = 'REFRESH_CONTENT';
    public const TYPE_UNPUBLISH_BLOG       = 'UNPUBLISH_BLOG';
    public const TYPE_RENDER_FILES         = 'RENDER_FILES';
    public const TYPE_BACKFILL_STORAGE     = 'BACKFILL_STORAGE';
    public const TYPE_CONSOLIDATE_CONTENT  = 'CONSOLIDATE_CONTENT';
    public const TYPE_ROADMAP_PLAN         = 'ROADMAP_PLAN';
    public const TYPE_UPDATE_SITEMAP       = 'UPDATE_SITEMAP';
    public const TYPE_CHECK_INDEXING       = 'CHECK_INDEXING';
    public const TYPE_SYNC_ANALYTICS       = 'SYNC_ANALYTICS';

    private BaseConnection $db;
    private JobService $jobService;
    private BlogFeatureFlags $flags;

    public function __construct(?JobService $jobService = null, ?BlogFeatureFlags $flags = null)
    {
        $this->db         = Database::connect();
        $this->jobService = $jobService ?? new JobService();
        $this->flags      = $flags ?? new BlogFeatureFlags();
    }

    /**
     * @param array<string,mixed> $data
     */
    public function create(array $data): int
    {
        $row = [
            'block_type'         => (string) ($data['block_type'] ?? ''),
            'scope'              => $data['scope'] ?? null,
            'content_item_id'    => $data['content_item_id'] ?? null,
            'content_version_id' => $data['content_version_id'] ?? null,
            'input_json'         => isset($data['input_json']) ? json_encode($data['input_json'], JSON_UNESCAPED_SLASHES) : null,
            'eligibility_status' => $data['eligibility_status'] ?? 'pending',
            'idempotency_key'    => $data['idempotency_key'] ?? null,
            'priority'           => (int) ($data['priority'] ?? 0),
            'scheduled_at'       => $data['scheduled_at'] ?? null,
            'dependency_ids'     => isset($data['dependency_ids']) ? json_encode($data['dependency_ids'], JSON_UNESCAPED_SLASHES) : null,
            'provider_route'     => $data['provider_route'] ?? null,
            'cost_ceiling_cents' => $data['cost_ceiling_cents'] ?? null,
            'created_at'         => date('Y-m-d H:i:s'),
            'updated_at'         => date('Y-m-d H:i:s'),
        ];

        $this->db->table('reach_work_blocks')->insert($row);
        return (int) $this->db->insertID();
    }

    public function markEligible(int $id): void
    {
        $this->db->table('reach_work_blocks')->where('id', $id)->update([
            'eligibility_status' => 'eligible',
            'updated_at'         => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return array{enqueued:int, skipped:int, work_block_ids:int[]}
     */
    public function enqueueEligibleBatch(int $limit = 25): array
    {
        $now = date('Y-m-d H:i:s');
        $rows = $this->db->table('reach_work_blocks')
            ->where('eligibility_status', 'eligible')
            ->groupStart()
                ->where('scheduled_at IS NULL', null, false)
                ->orWhere('scheduled_at <=', $now)
            ->groupEnd()
            ->orderBy('priority', 'DESC')
            ->orderBy('id', 'ASC')
            ->limit(max(1, $limit))
            ->get()
            ->getResultArray();

        $enqueued = 0;
        $skipped  = 0;
        $ids      = [];

        foreach ($rows as $row) {
            $blockId = (int) $row['id'];
            $baseKey = $row['idempotency_key'] ?? ('work-block-' . $blockId);
            // After a failed attempt, avoid colliding with the completed/failed
            // reach_jobs row that still owns the original idempotency key.
            $attempt = (int) ($row['attempt_count'] ?? 0);
            $key     = $attempt > 0 ? ($baseKey . ':attempt:' . $attempt) : $baseKey;

            try {
                $jobId = $this->jobService->enqueue(
                    'reach.blog_work_block',
                    ['work_block_id' => $blockId],
                    [
                        'queue'           => 'blog',
                        'idempotency_key' => $key,
                        'priority'        => (int) ($row['priority'] ?? 0),
                    ],
                );

                $this->db->table('reach_work_blocks')->where('id', $blockId)->update([
                    'eligibility_status' => 'queued',
                    'job_id'             => $jobId,
                    'updated_at'         => $now,
                ]);

                $enqueued++;
                $ids[] = $blockId;
            } catch (\Throwable) {
                $skipped++;
            }
        }

        return ['enqueued' => $enqueued, 'skipped' => $skipped, 'work_block_ids' => $ids];
    }

    /**
     * @return array<string,mixed>
     */
    public function execute(int $id): array
    {
        $block = $this->db->table('reach_work_blocks')->where('id', $id)->get()->getRowArray();
        if (! $block) {
            throw new \RuntimeException("Work block {$id} not found");
        }

        $now = date('Y-m-d H:i:s');
        $this->db->table('reach_work_blocks')->where('id', $id)->update([
            'eligibility_status' => 'processing',
            'started_at'         => $now,
            'attempt_count'      => (int) $block['attempt_count'] + 1,
            'updated_at'         => $now,
        ]);

        $blockType = (string) $block['block_type'];

        // PUBLISH_BLOG applies its own hard high-risk-auto-publish ban internally and
        // does not depend on the general 'automation' flag (it depends on
        // 'public_publisher' + 'auto_publish' specifically).
        if ($blockType === self::TYPE_PUBLISH_BLOG) {
            return $this->executePublishBlog($id, $block);
        }

        if (! $this->flags->isEnabled('automation')) {
            return $this->blockOnFlag($id, $blockType, 'automation_disabled');
        }

        try {
            return match ($blockType) {
                self::TYPE_OPTIMIZE_ROADMAP, self::TYPE_ROADMAP_PLAN => $this->executeOptimizeRoadmap($id, $block),
                self::TYPE_DISCOVER_TOPICS   => $this->executeDiscoverTopics($id, $block),
                self::TYPE_SCORE_TOPIC       => $this->executeScoreTopic($id, $block),
                self::TYPE_GENERATE_BRIEF    => $this->executeGenerateBrief($id, $block),
                self::TYPE_GENERATE_OUTLINE  => $this->executeGenerateOutline($id, $block),
                self::TYPE_GENERATE_DRAFT    => $this->executeGenerateDraft($id, $block),
                self::TYPE_FACT_VERIFY       => $this->executeFactVerify($id, $block),
                self::TYPE_REVISE_CONTENT    => $this->executeReviseContent($id, $block),
                self::TYPE_GENERATE_IMAGE    => $this->executeGenerateImage($id, $block),
                self::TYPE_SEO_OPTIMIZE      => $this->executeSeoOptimize($id, $block),
                self::TYPE_INTERNAL_LINK     => $this->executeInternalLink($id, $block),
                self::TYPE_CROSS_REVIEW      => $this->executeCrossReview($id, $block),
                self::TYPE_HUMAN_REVIEW_GATE => $this->executeHumanReviewGate($id, $block),
                self::TYPE_SCHEDULE_PUBLISH  => $this->executeSchedulePublish($id, $block),
                self::TYPE_VERIFY_PUBLICATION=> $this->executeVerifyPublication($id, $block),
                self::TYPE_REFRESH_CONTENT   => $this->executeRefreshContent($id, $block),
                self::TYPE_UNPUBLISH_BLOG    => $this->executeUnpublishBlog($id, $block),
                self::TYPE_RENDER_FILES, self::TYPE_BACKFILL_STORAGE => $this->executeRenderFiles($id, $block),
                self::TYPE_CONSOLIDATE_CONTENT => $this->executeConsolidateContent($id, $block),
                self::TYPE_UPDATE_SITEMAP    => $this->executeUpdateSitemap($id, $block),
                self::TYPE_CHECK_INDEXING    => $this->executeCheckIndexing($id, $block),
                self::TYPE_SYNC_ANALYTICS    => $this->executeSyncAnalytics($id, $block),
                default => $this->blockOnFlag($id, $blockType, 'handler_not_implemented'),
            };
        } catch (\RuntimeException $e) {
            return $this->markFailed($id, $blockType, $e->getMessage());
        }
    }

    // -------------------------------------------------------------------
    // Roadmap
    // -------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function executeOptimizeRoadmap(int $id, array $block): array
    {
        if (! $this->flags->isEnabled('roadmap_optimizer')) {
            return $this->blockOnFlag($id, self::TYPE_OPTIMIZE_ROADMAP, 'roadmap_optimizer_disabled');
        }

        $input   = $this->decodeInput($block);
        $forDate = (string) ($input['run_for_date'] ?? date('Y-m-d'));

        $summary = (new RoadmapOptimizerService())->run($forDate, [
            'limit'    => isset($input['limit']) ? (int) $input['limit'] : null,
            'dry_run'  => (bool) ($input['dry_run'] ?? false),
        ]);

        $this->markCompleted($id, $summary);

        return $summary;
    }

    /**
     * Real gap discovery: finds approved topic clusters that have no open
     * (non-terminal) candidate yet, and creates one candidate per gap. Never
     * invents a cluster or a topic — only surfaces gaps that already exist
     * in reach_topic_clusters.
     */
    private function executeDiscoverTopics(int $id, array $block): array
    {
        $input = $this->decodeInput($block);
        $limit = max(1, (int) ($input['limit'] ?? 10));

        $clusters = $this->db->table('reach_topic_clusters tc')
            ->select('tc.id, tc.slug, tc.name, tc.pillar_topic')
            ->where('tc.status', 'approved')
            ->where('tc.deleted_at IS NULL', null, false)
            ->whereNotIn('tc.id', function ($builder) {
                return $builder->select('topic_cluster_id')
                    ->from('reach_topic_candidates')
                    ->where('topic_cluster_id IS NOT NULL', null, false)
                    ->whereIn('status', ['candidate', 'scored', 'roadmap_selected']);
            })
            ->limit($limit)
            ->get()
            ->getResultArray();

        $created = [];
        $now     = date('Y-m-d H:i:s');

        foreach ($clusters as $cluster) {
            $title = (string) ($cluster['pillar_topic'] ?: $cluster['name']);
            $this->db->table('reach_topic_candidates')->insert([
                'candidate_uuid'   => bin2hex(random_bytes(16)),
                'topic_cluster_id' => $cluster['id'],
                'title'            => $title,
                'normalized_title' => strtolower(trim($title)),
                'slug_hint'        => $cluster['slug'],
                'status'           => 'candidate',
                'source'           => 'work_block_discover_topics',
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);
            $created[] = (int) $this->db->insertID();
        }

        $output = [
            'clusters_considered' => count($clusters),
            'candidates_created'  => count($created),
            'topic_candidate_ids' => $created,
        ];
        $this->markCompleted($id, $output);

        return $output;
    }

    /**
     * Scores exactly one topic candidate using the same TopicScoringService
     * and RoadmapSignalProvider the daily optimizer uses, so a single
     * candidate can be (re)scored on demand without waiting for the next
     * batch run.
     */
    private function executeScoreTopic(int $id, array $block): array
    {
        $input       = $this->decodeInput($block);
        $candidateId = (int) ($input['topic_candidate_id'] ?? 0);
        if ($candidateId <= 0) {
            throw new \RuntimeException('SCORE_TOPIC work block requires input_json.topic_candidate_id');
        }

        $candidate = $this->db->table('reach_topic_candidates')->where('id', $candidateId)->get()->getRowArray();
        if (! $candidate) {
            throw new \RuntimeException("Topic candidate {$candidateId} not found");
        }

        $weightsRow = $this->db->table('reach_roadmap_scoring_weights')->orderBy('id', 'ASC')->get()->getRowArray();
        $weights    = $weightsRow ? ScoringWeights::fromArray($weightsRow) : ScoringWeights::defaults();

        $forDate  = date('Y-m-d');
        $signals  = (new RoadmapSignalProvider())->gatherForCandidate($candidate, $forDate);
        $score    = (new TopicScoringService())->scoreCandidate($candidate, $signals, $weights);

        (new TopicScoringService())->persistScore($candidateId, $score, $forDate);
        $this->db->table('reach_topic_candidates')->where('id', $candidateId)->update([
            'status'         => 'scored',
            'last_scored_at' => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);

        $output = ['topic_candidate_id' => $candidateId, 'total_score' => $score['total_score'], 'factors' => $score['factors']];
        $this->markCompleted($id, $output);

        return $output;
    }

    // -------------------------------------------------------------------
    // Content pipeline
    // -------------------------------------------------------------------

    /**
     * Creates (or reuses) the content item for a roadmap-approved candidate
     * and assembles a real brief from the candidate's own known fields.
     * Deterministic assembly never fabricates facts — every field traces to
     * data already on the topic candidate / decision. AI enrichment is
     * additive and only attempted when ai_generation is enabled and a route
     * is configured; its unavailability never blocks the deterministic
     * brief from being created.
     */
    private function executeGenerateBrief(int $id, array $block): array
    {
        $input       = $this->decodeInput($block);
        $candidateId = (int) ($input['topic_candidate_id'] ?? 0);
        $candidate   = $candidateId > 0
            ? $this->db->table('reach_topic_candidates')->where('id', $candidateId)->get()->getRowArray()
            : null;

        $title = (string) ($input['title'] ?? $candidate['title'] ?? '');
        if ($title === '') {
            throw new \RuntimeException('GENERATE_BRIEF work block requires a topic title');
        }

        $contentItemId = (int) ($block['content_item_id'] ?? 0);
        if ($contentItemId <= 0 && $candidate) {
            $contentItemId = (int) ($candidate['content_item_id'] ?? 0);
        }

        $stateMachine = new BlogStateMachine($this);

        if ($contentItemId <= 0) {
            $slug = $this->buildUniqueSlug($title);
            $now  = date('Y-m-d H:i:s');
            $this->db->table('reach_content_items')->insert([
                'uuid'               => bin2hex(random_bytes(16)),
                'content_type'       => 'blog',
                'title'              => $title,
                'slug'               => $slug,
                'workflow_status'    => BlogStateMachine::IDEA,
                'approval_status'    => 'pending',
                'created_actor_type' => 'system',
                'created_by_service' => 'reach:work_block:generate_brief',
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);
            $contentItemId = (int) $this->db->insertID();

            $this->db->table('reach_content_blog_details')->insert([
                'content_item_id'  => $contentItemId,
                'portfolio_stream' => $candidate['portfolio_stream'] ?? $input['portfolio_stream'] ?? null,
                'funnel_stage'     => $this->normalizeFunnelStage(
                    $candidate['funnel_stage'] ?? $input['funnel_stage'] ?? null
                ),
                'audience'         => $candidate['audience'] ?? $input['audience'] ?? null,
                'search_intent'    => $candidate['search_intent'] ?? $input['search_intent'] ?? null,
                'campaign'         => $candidate['campaign'] ?? $input['campaign'] ?? null,
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);

            if ($candidate) {
                $this->db->table('reach_topic_candidates')->where('id', $candidateId)->update([
                    'content_item_id' => $contentItemId,
                    'status'          => 'roadmap_selected',
                    'updated_at'      => date('Y-m-d H:i:s'),
                ]);
            }
        }

        $stateMachine->transition($contentItemId, BlogStateMachine::BRIEF_DRAFT, null, ['work_block_id' => $id]);

        // Keep work-block row linked for downstream outline/draft handlers.
        $this->db->table('reach_work_blocks')->where('id', $id)->update([
            'content_item_id' => $contentItemId,
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);

        $briefs = new ContentBriefModel();
        $existing = $briefs->forItem($contentItemId);
        $briefRow = [
            'content_item_id'      => $contentItemId,
            'objective'            => (string) ($input['objective'] ?? "Rank and convert for: {$title}"),
            'audience_description' => (string) ($candidate['audience'] ?? $input['audience'] ?? ''),
            // DB CHECK rcb_funnel_stage_chk allows only top|middle|bottom|NULL.
            'funnel_stage'         => $this->normalizeFunnelStage(
                $candidate['funnel_stage'] ?? $input['funnel_stage'] ?? null
            ),
            'primary_keyword'      => (string) ($candidate['seed_keyword'] ?? $title),
            'secondary_keywords'   => $input['secondary_keywords'] ?? [],
            'questions_to_answer'  => $input['questions_to_answer'] ?? [],
            'tone'                 => 'professional, plain-language',
            'min_word_count'       => 900,
            'max_word_count'       => 1800,
            'updated_by'           => null,
        ];

        if ($existing) {
            $briefs->update($existing['id'], $briefRow);
            $briefId = $existing['id'];
        } else {
            $briefRow['created_by'] = null;
            $briefId = $briefs->insert($briefRow, true);
        }

        // Best-effort AI enrichment of secondary keywords / questions. Never fabricates
        // the brief itself and never blocks brief_ready on failure.
        $aiNote = 'ai_enrichment_skipped';
        if ($this->flags->isEnabled('ai_generation')) {
            try {
                $aiNote = $this->tryEnrichBriefWithAi($contentItemId, $briefId, $title, $briefRow);
            } catch (\Throwable $e) {
                $aiNote = 'ai_enrichment_failed: ' . $e->getMessage();
            }
        }

        $stateMachine->transition($contentItemId, BlogStateMachine::BRIEF_READY, null, ['work_block_id' => $id]);

        $output = ['content_item_id' => $contentItemId, 'brief_id' => $briefId, 'ai_enrichment' => $aiNote];
        $this->markCompleted($id, $output);

        return $output;
    }

    private function tryEnrichBriefWithAi(int $contentItemId, int $briefId, string $title, array $brief): string
    {
        $requests = new AiGenerationRequestService();
        $request  = $requests->create([
            'task_type'    => 'brief_generation',
            'content_type' => 'generic',
            'content_item_id' => $contentItemId,
            'parameters'   => [
                'instructions' => "Suggest 5 secondary keywords and 5 reader questions for a blog brief titled '{$title}'. Return JSON with keys secondary_keywords (array of strings) and questions_to_answer (array of strings).",
            ],
        ], ['type' => 'system']);

        (new AiGenerationOrchestrator())->execute((int) $request['id']);
        $refreshed = $requests->findById((int) $request['id']);

        if ($refreshed['status'] !== 'completed') {
            return 'ai_enrichment_unavailable:' . $refreshed['status'];
        }

        $artifact = $this->db->table('reach_ai_generation_artifacts')
            ->where('generation_request_id', $request['id'])
            ->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();

        if (! $artifact) {
            return 'ai_enrichment_no_artifact';
        }

        $structured = is_string($artifact['structured_output_json'] ?? null)
            ? json_decode($artifact['structured_output_json'], true)
            : ($artifact['structured_output_json'] ?? []);

        if (is_array($structured) && (! empty($structured['secondary_keywords']) || ! empty($structured['questions_to_answer']))) {
            (new ContentBriefModel())->update($briefId, [
                'secondary_keywords'  => $structured['secondary_keywords'] ?? $brief['secondary_keywords'],
                'questions_to_answer' => $structured['questions_to_answer'] ?? $brief['questions_to_answer'],
            ]);
            return 'ai_enrichment_applied';
        }

        return 'ai_enrichment_empty_output';
    }

    /**
     * Deterministic outline derived directly from the approved brief's own
     * questions_to_answer / keyword fields — never invented content.
     */
    private function executeGenerateOutline(int $id, array $block): array
    {
        $contentItemId = (int) ($block['content_item_id'] ?? 0);
        if ($contentItemId <= 0) {
            throw new \RuntimeException('GENERATE_OUTLINE work block requires content_item_id');
        }

        $brief = (new ContentBriefModel())->forItem($contentItemId);
        if (! $brief) {
            throw new \RuntimeException("No content brief found for item {$contentItemId}; run GENERATE_BRIEF first");
        }

        $stateMachine = new BlogStateMachine($this);
        $stateMachine->transition($contentItemId, BlogStateMachine::OUTLINE_DRAFT, null, ['work_block_id' => $id]);

        $sections = ['Introduction'];
        foreach ((array) ($brief['questions_to_answer'] ?? []) as $q) {
            if (is_string($q) && trim($q) !== '') {
                $sections[] = trim($q);
            }
        }
        $sections[] = 'Conclusion and next steps';

        $this->db->table('reach_content_briefs')->where('id', $brief['id'])->update([
            'format_notes' => 'Outline: ' . implode(' | ', $sections),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        $stateMachine->transition($contentItemId, BlogStateMachine::OUTLINE_READY, null, ['work_block_id' => $id]);

        $output = ['content_item_id' => $contentItemId, 'sections' => $sections];
        $this->markCompleted($id, $output);

        return $output;
    }

    /**
     * Generates a real draft via the existing Phase 3 AI generation stack
     * (AiGenerationOrchestrator), never a template placeholder. If AI
     * generation is disabled or no route is configured, the block is left
     * honestly blocked — it never fabricates draft content.
     */
    private function executeGenerateDraft(int $id, array $block): array
    {
        if (! $this->flags->isEnabled('ai_generation')) {
            return $this->blockOnFlag($id, self::TYPE_GENERATE_DRAFT, 'ai_generation_disabled');
        }

        $contentItemId = (int) ($block['content_item_id'] ?? 0);
        if ($contentItemId <= 0) {
            throw new \RuntimeException('GENERATE_DRAFT work block requires content_item_id');
        }

        $brief = (new ContentBriefModel())->forItem($contentItemId);
        $item  = $this->db->table('reach_content_items')->where('id', $contentItemId)->get()->getRowArray();

        $stateMachine = new BlogStateMachine($this);
        $stateMachine->transition($contentItemId, BlogStateMachine::DRAFT_GENERATING, null, ['work_block_id' => $id]);

        $rotation          = new \App\Libraries\Ai\ProviderRotationService($this->db);
        $preferredProvider = $rotation->preferredNext(\App\Libraries\Ai\ProviderRotationService::SCOPE_BLOG_DRAFT);

        $requests = new AiGenerationRequestService();
        $request  = $requests->create([
            'task_type'       => 'draft_generation',
            'content_type'    => 'blog_post',
            'content_item_id' => $contentItemId,
            'parameters'      => [
                'title'               => $item['title'] ?? '',
                'primary_keyword'     => $brief['primary_keyword'] ?? ($item['title'] ?? ''),
                'secondary_keywords'  => $brief['secondary_keywords'] ?? [],
                'questions_to_answer' => $brief['questions_to_answer'] ?? [],
                'min_word_count'      => $brief['min_word_count'] ?? 900,
                'max_word_count'      => $brief['max_word_count'] ?? 1800,
                'tone'                => $brief['tone'] ?? 'professional, plain-language',
                'instructions'        => 'Write a complete, accurate blog article of at least 900 words grounded only in the provided context. Return full body_html, body_markdown, and body_plain_text with real sections — never placeholders like "Untitled draft". Use only verifiable claims.',
                // Strict alternation hint; the actual generator is recorded
                // after the artifact is accepted (failover-safe).
                'provider_preference' => $preferredProvider,
            ],
        ], ['type' => 'system']);

        (new AiGenerationOrchestrator())->execute((int) $request['id']);
        $refreshed = $requests->findById((int) $request['id']);

        if ($refreshed['status'] !== 'completed') {
            $detail = $this->lastAiFailureDetail((int) $request['id']);
            $this->moveDraftGeneratingToFailed($contentItemId, $stateMachine, $id);
            return $this->markFailed(
                $id,
                self::TYPE_GENERATE_DRAFT,
                'ai_generation_' . $refreshed['status'] . ($detail !== '' ? ':' . $detail : ''),
            );
        }

        $artifact = $this->db->table('reach_ai_generation_artifacts')
            ->where('generation_request_id', $request['id'])
            ->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();

        if (! $artifact || ($artifact['schema_validation_status'] ?? '') !== 'passed') {
            $errors = is_string($artifact['schema_validation_errors'] ?? null)
                ? $artifact['schema_validation_errors']
                : json_encode($artifact['schema_validation_errors'] ?? []);
            $this->moveDraftGeneratingToFailed($contentItemId, $stateMachine, $id);
            return $this->markFailed(
                $id,
                self::TYPE_GENERATE_DRAFT,
                'schema_validation_failed:' . mb_substr((string) $errors, 0, 180),
            );
        }

        $structured = is_string($artifact['structured_output_json'] ?? null)
            ? json_decode($artifact['structured_output_json'], true)
            : ($artifact['structured_output_json'] ?? []);

        if (! is_array($structured)
            || \App\Libraries\Ai\Generation\StructuredOutputCoercer::isStubBody(
                $structured['body_html'] ?? null,
                $structured['body_markdown'] ?? null,
                $structured['body_plain_text'] ?? null,
                $structured['title'] ?? ($item['title'] ?? null),
            )
        ) {
            $this->moveDraftGeneratingToFailed($contentItemId, $stateMachine, $id);
            return $this->markFailed(
                $id,
                self::TYPE_GENERATE_DRAFT,
                'stub_or_empty_body:provider returned placeholder/short content instead of a real article',
            );
        }

        $plainWords = str_word_count(trim(html_entity_decode(strip_tags((string) (
            $structured['body_plain_text']
            ?? $structured['body_html']
            ?? $structured['body_markdown']
            ?? ''
        )), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if ($plainWords < 200) {
            $this->moveDraftGeneratingToFailed($contentItemId, $stateMachine, $id);
            return $this->markFailed(
                $id,
                self::TYPE_GENERATE_DRAFT,
                "short_body:{$plainWords}_words_need_at_least_200",
            );
        }

        $version = (new ContentVersionService())->createVersion($contentItemId, [
            'title'           => $structured['title']           ?? ($item['title'] ?? ''),
            'summary'         => $structured['summary']         ?? null,
            'body_html'       => $structured['body_html']       ?? '',
            'body_markdown'   => $structured['body_markdown']   ?? null,
            'body_plain_text' => $structured['body_plain_text'] ?? null,
        ], ['type' => 'bot', 'service' => 'reach:work_block:generate_draft'], 'AI-generated draft');

        $this->db->table('reach_ai_generation_artifacts')->where('id', $artifact['id'])->update([
            'content_version_id' => $version['id'],
        ]);

        $actualGenerator = $this->db->table('reach_ai_generation_runs r')
            ->join('reach_ai_providers p', 'p.id = r.provider_id')
            ->select('p.provider_key')
            ->where('r.generation_request_id', $request['id'])
            ->orderBy('r.id', 'DESC')->limit(1)->get()->getRowArray();
        if (! empty($actualGenerator['provider_key'])) {
            $rotation->recordActual(
                \App\Libraries\Ai\ProviderRotationService::SCOPE_BLOG_DRAFT,
                (string) $actualGenerator['provider_key'],
                (int) $request['id'],
            );
        }

        $stateMachine->transition($contentItemId, BlogStateMachine::DRAFT, null, [
            'work_block_id'       => $id,
            'content_version_id'  => $version['id'],
        ]);

        $output = ['content_item_id' => $contentItemId, 'content_version_id' => $version['id'], 'generation_request_id' => $request['id']];
        $this->markCompleted($id, $output);

        return $output;
    }

    /**
     * Fact verification is fail-closed: an unavailable verifier never yields a pass,
     * and unsupported high-risk claims force the item into human review.
     *
     * @param array<string,mixed> $block
     * @return array<string,mixed>
     */
    private function executeFactVerify(int $id, array $block): array
    {
        $contentItemId = (int) ($block['content_item_id'] ?? 0);

        // Flag off must not dead-end the automation chain at draft.
        if (! $this->flags->isEnabled('fact_verification')) {
            if ($contentItemId > 0) {
                try {
                    (new BlogStateMachine($this))->transition(
                        $contentItemId,
                        BlogStateMachine::FACT_VERIFIED,
                        null,
                        ['work_block_id' => $id, 'reason' => 'fact_verification_skipped_flag_off'],
                    );
                } catch (\Throwable) {
                }
            }
            $output = [
                'skipped'    => true,
                'reason'     => 'fact_verification_disabled',
                'block_type' => self::TYPE_FACT_VERIFY,
            ];
            $this->markCompleted($id, $output);
            return $output;
        }

        $contentVersionId = (int) ($block['content_version_id'] ?? 0);
        if ($contentVersionId <= 0) {
            throw new \RuntimeException('FACT_VERIFY work block requires content_version_id');
        }

        $version = $this->db->table('reach_content_versions')
            ->where('id', $contentVersionId)
            ->get()
            ->getRowArray();

        if (! $version) {
            throw new \RuntimeException("Content version {$contentVersionId} not found");
        }

        if ($contentItemId > 0) {
            try {
                (new BlogStateMachine($this))->transition($contentItemId, BlogStateMachine::FACT_VERIFYING, null, ['work_block_id' => $id]);
            } catch (\Throwable) {
                // Already past this stage or on a non-automation-native item; continue best-effort.
            }
        }

        // Plain text first: claim extraction should not trip over markup.
        $body = (string) (
            ($version['body_plain_text'] ?? '')
            ?: ($version['body_markdown'] ?? '')
            ?: ($version['body_html'] ?? '')
        );

        $claims       = (new ClaimExtractor())->extractHeuristic($body);
        $verification = new FactVerificationService(null, $this->flags);
        $result       = $verification->verify($claims);

        $output = [
            'status'      => $result['status'],
            'pass_rate'   => $result['pass_rate'],
            'threshold'   => $result['threshold'],
            'claim_count' => count($claims),
            'unsupported' => $result['unsupported'],
            'publishable' => $verification->isPublishable($result),
        ];

        // Perplexity's verdict is the authority — persist it on the item so the
        // revision loop and reviewers can see exactly what failed and why.
        if ($contentItemId > 0) {
            try {
                $this->db->table('reach_content_blog_details')
                    ->where('content_item_id', $contentItemId)
                    ->update([
                        'last_verification_json' => json_encode($result, JSON_UNESCAPED_SLASHES),
                        'updated_at'             => date('Y-m-d H:i:s'),
                    ]);
            } catch (\Throwable) {
            }
        }

        if ($contentItemId > 0) {
            try {
                (new BlogStateMachine($this))->transition(
                    $contentItemId,
                    $output['publishable'] ? BlogStateMachine::FACT_VERIFIED : BlogStateMachine::CHANGES_REQUESTED,
                    null,
                    ['work_block_id' => $id, 'content_version_id' => $contentVersionId],
                );
            } catch (\Throwable) {
                // Best-effort bookkeeping only; the verification result itself is authoritative.
            }

            // Bounded self-healing: let the generating provider fix or delete the
            // claims Perplexity rejected, then re-verify. Attempts exhausted →
            // the item stays in changes_requested for the human flow, as before.
            if (! $output['publishable']) {
                $output['revision'] = $this->chainRevisionIfAllowed($contentItemId, $contentVersionId, $result);
            }
        }

        $this->markCompleted($id, $output);

        return $output;
    }

    /**
     * @param array<string,mixed> $verification
     * @return array<string,mixed>
     */
    private function chainRevisionIfAllowed(int $contentItemId, int $contentVersionId, array $verification): array
    {
        $maxAttempts = max(0, (int) env('BLOG_FACT_REVISION_MAX_ATTEMPTS', 2));

        $details = $this->db->table('reach_content_blog_details')
            ->where('content_item_id', $contentItemId)
            ->get()->getRowArray() ?? [];
        $attempts = (int) ($details['fact_revision_attempts'] ?? 0);

        if ($attempts >= $maxAttempts) {
            return ['chained' => false, 'reason' => "revision_attempts_exhausted:{$attempts}/{$maxAttempts}"];
        }

        $nextAttempt = $attempts + 1;
        // Deliberately NOT via NEXT_WORK_BLOCK: changes_requested is also a
        // human-flow state, and a static successor there would hijack manual
        // rejections into automated rewrites.
        $blockId = $this->create([
            'block_type'         => self::TYPE_REVISE_CONTENT,
            'scope'              => 'blog',
            'content_item_id'    => $contentItemId,
            'content_version_id' => $contentVersionId,
            'idempotency_key'    => "blog-{$contentItemId}-revise-attempt-{$nextAttempt}",
            'eligibility_status' => 'eligible',
            'input_json'         => ['verification' => $verification, 'attempt' => $nextAttempt],
        ]);

        return ['chained' => true, 'work_block_id' => $blockId, 'attempt' => $nextAttempt];
    }

    /**
     * Applies Perplexity's findings through the provider that generated the
     * current version (never Perplexity itself — the verifier stays a pure
     * judge so fail-closed verification is preserved), producing a new version
     * that transitions back to DRAFT and re-enters FACT_VERIFY automatically.
     *
     * @param array<string,mixed> $block
     * @return array<string,mixed>
     */
    private function executeReviseContent(int $id, array $block): array
    {
        if (! $this->flags->isEnabled('ai_generation')) {
            return $this->blockOnFlag($id, self::TYPE_REVISE_CONTENT, 'ai_generation_disabled');
        }

        $contentItemId    = (int) ($block['content_item_id'] ?? 0);
        $contentVersionId = (int) ($block['content_version_id'] ?? 0);
        if ($contentItemId <= 0 || $contentVersionId <= 0) {
            throw new \RuntimeException('REVISE_CONTENT work block requires content_item_id and content_version_id');
        }

        $input        = $this->decodeInput($block);
        $verification = (array) ($input['verification'] ?? []);
        $attempt      = (int) ($input['attempt'] ?? 1);
        $unsupported  = array_values((array) ($verification['unsupported'] ?? []));

        $version = $this->db->table('reach_content_versions')->where('id', $contentVersionId)->get()->getRowArray();
        if (! $version) {
            throw new \RuntimeException("Content version {$contentVersionId} not found");
        }
        $item = $this->db->table('reach_content_items')->where('id', $contentItemId)->get()->getRowArray() ?? [];

        // Same provider as the generator; a revision is part of the same
        // "turn" and must not advance the OpenAI/Gemini rotation.
        $generatorRun = $this->db->table('reach_ai_generation_artifacts a')
            ->join('reach_ai_generation_runs r', 'r.id = a.generation_run_id')
            ->join('reach_ai_providers p', 'p.id = r.provider_id')
            ->select('p.provider_key')
            ->where('a.content_version_id', $contentVersionId)
            ->orderBy('a.id', 'DESC')->limit(1)->get()->getRowArray();
        $generatorKey = (string) ($generatorRun['provider_key'] ?? 'openai');

        $claimLines = [];
        foreach ($unsupported as $i => $claim) {
            $text   = is_array($claim) ? (string) ($claim['claim'] ?? json_encode($claim, JSON_UNESCAPED_SLASHES)) : (string) $claim;
            $reason = is_array($claim) ? (string) ($claim['reason'] ?? $claim['status'] ?? 'unsupported') : 'unsupported';
            $claimLines[] = ($i + 1) . '. ' . $text . ' — verifier finding: ' . $reason;
        }

        $requests = new AiGenerationRequestService();
        $request  = $requests->create([
            'task_type'       => 'content_revision',
            'content_type'    => 'blog_post',
            'content_item_id' => $contentItemId,
            'parameters'      => [
                'title'               => $item['title'] ?? '',
                'provider_preference' => $generatorKey,
                'revision_attempt'    => $attempt,
                'instructions'        => "The independent fact verifier (Perplexity) rejected the claims listed below. Revise the article: correct each claim using verifiable facts, or delete it entirely if it cannot be supported. Change NOTHING else — keep structure, tone, headings and all other content identical. Return the complete revised article in the same JSON schema (title, summary, body_html, body_markdown, body_plain_text).\n\nRejected claims:\n" . implode("\n", $claimLines) . "\n\nCurrent article:\n" . mb_substr((string) ($version['body_markdown'] ?: $version['body_html'] ?: $version['body_plain_text']), 0, 24000),
            ],
        ], ['type' => 'system']);

        (new AiGenerationOrchestrator())->execute((int) $request['id']);
        $refreshed = $requests->findById((int) $request['id']);

        if ($refreshed['status'] !== 'completed') {
            $detail = $this->lastAiFailureDetail((int) $request['id']);
            return $this->markFailed(
                $id,
                self::TYPE_REVISE_CONTENT,
                'ai_revision_' . $refreshed['status'] . ($detail !== '' ? ':' . $detail : ''),
            );
        }

        $artifact = $this->db->table('reach_ai_generation_artifacts')
            ->where('generation_request_id', $request['id'])
            ->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();

        $structured = is_string($artifact['structured_output_json'] ?? null)
            ? json_decode($artifact['structured_output_json'], true)
            : ($artifact['structured_output_json'] ?? []);

        if (! is_array($structured) || trim((string) ($structured['body_html'] ?? $structured['body_markdown'] ?? '')) === '') {
            return $this->markFailed($id, self::TYPE_REVISE_CONTENT, 'revision_returned_empty_body');
        }

        $newVersion = (new ContentVersionService())->createVersion($contentItemId, [
            'title'           => $structured['title']           ?? ($version['title'] ?? ''),
            'summary'         => $structured['summary']         ?? ($version['summary'] ?? null),
            'body_html'       => $structured['body_html']       ?? '',
            'body_markdown'   => $structured['body_markdown']   ?? null,
            'body_plain_text' => $structured['body_plain_text'] ?? null,
        ], ['type' => 'bot', 'service' => 'reach:work_block:revise_content'], "Fact-revision attempt {$attempt} (verifier findings applied)");

        $this->db->table('reach_ai_generation_artifacts')->where('id', $artifact['id'])->update([
            'content_version_id' => $newVersion['id'],
        ]);

        $this->db->table('reach_content_blog_details')
            ->where('content_item_id', $contentItemId)
            ->update([
                'fact_revision_attempts' => $attempt,
                'updated_at'             => date('Y-m-d H:i:s'),
            ]);

        // changes_requested -> draft re-enters FACT_VERIFY via NEXT_WORK_BLOCK;
        // Perplexity re-verification concludes the loop.
        (new BlogStateMachine($this))->transition($contentItemId, BlogStateMachine::DRAFT, null, [
            'work_block_id'      => $id,
            'content_version_id' => $newVersion['id'],
            'reason'             => 'fact_revision_applied',
        ]);

        $output = [
            'content_item_id'       => $contentItemId,
            'content_version_id'    => $newVersion['id'],
            'generation_request_id' => $request['id'],
            'attempt'               => $attempt,
            'claims_addressed'      => count($claimLines),
        ];
        $this->markCompleted($id, $output);

        return $output;
    }

    /**
     * Cover image for a fact-verified draft. Pipeline items generate one via
     * the same provider family that wrote the text (openai → gpt-image-1,
     * gemini → Nano Banana Pro); Claude-routine items are assigned a cover
     * from the curated gallery instead of spending on AI images. The binary
     * is persisted through MediaAssetStore and exposed as a signed public
     * URL that aicountly.com's HeroImageFetcher can pull. Failures never
     * stall the chain: SEO_OPTIMIZE is always chained afterwards.
     *
     * @param array<string,mixed> $block
     * @return array<string,mixed>
     */
    private function executeGenerateImage(int $id, array $block): array
    {
        $contentItemId    = (int) ($block['content_item_id'] ?? 0);
        $contentVersionId = (int) ($block['content_version_id'] ?? 0);

        if (! $this->flags->isEnabled('image_generation')) {
            // Part of the main chain now: a disabled flag skips the image but
            // must not dead-end the item (mirrors the FACT_VERIFY skip path).
            $this->chainSeoOptimize($contentItemId, $contentVersionId);
            $output = ['skipped' => true, 'reason' => 'image_generation_disabled'];
            $this->markCompleted($id, $output);

            return $output;
        }

        $item    = $contentItemId > 0
            ? ($this->db->table('reach_content_items')->where('id', $contentItemId)->get()->getRowArray() ?? [])
            : [];
        $details = $contentItemId > 0
            ? ($this->db->table('reach_content_blog_details')->where('content_item_id', $contentItemId)->get()->getRowArray() ?? [])
            : [];
        $galleryMiss = null;

        // Claude-routine drafts try the curated gallery first (rotation-managed),
        // not AI generation — 12+ blogs/day of AI covers is exactly the spend
        // the gallery exists to avoid. When no gallery asset is topically
        // relevant we fall through to AI generation rather than fronting the
        // article with an unrelated stock photo; that costs one image on the
        // rare miss instead of every post.
        if (($details['origin'] ?? 'pipeline') === 'claude_routine') {
            $output = $this->assignGalleryCover($contentItemId, $item, $details);
            $aiFallback = filter_var(env('BLOG_ROUTINE_AI_COVER_FALLBACK', true), FILTER_VALIDATE_BOOL);

            if (empty($output['image_failed']) || ! $aiFallback) {
                $this->chainSeoOptimize($contentItemId, $contentVersionId);
                $this->markCompleted($id, $output);

                return $output;
            }

            $galleryMiss = $output;
        }

        $input  = $this->decodeInput($block);
        $prompt = trim((string) ($input['prompt'] ?? ''));
        if ($prompt === '' && $contentItemId > 0) {
            $version = $contentVersionId > 0
                ? $this->db->table('reach_content_versions')->where('id', $contentVersionId)->get()->getRowArray()
                : null;
            // The published category is a sharper theme than the portfolio
            // stream, so the cover matches what the listing card says.
            $category = (string) ($this->db->table('reach_blog_publication_profiles')
                ->select('category')
                ->where('content_item_id', $contentItemId)
                ->get()->getRowArray()['category'] ?? '');
            $prompt = (new \App\Libraries\Ai\Images\CoverPromptBuilder())->build(
                (string) ($item['title'] ?? ''),
                (string) ($version['summary'] ?? ''),
                $category !== '' ? $category : (string) ($details['portfolio_stream'] ?? ''),
            );
        }
        if ($prompt === '') {
            $this->chainSeoOptimize($contentItemId, $contentVersionId);
            $output = array_filter(['image_failed' => true, 'reason' => 'no_prompt_available', 'gallery_miss' => $galleryMiss]);
            $this->markCompleted($id, $output);

            return $output;
        }

        $registry   = new ImageProviderRegistry();
        $configured = array_keys($registry->configuredProviders());
        if ($configured === []) {
            $this->chainSeoOptimize($contentItemId, $contentVersionId);
            $output = array_filter(['image_failed' => true, 'reason' => 'no_image_provider_configured', 'gallery_miss' => $galleryMiss]);
            $this->markCompleted($id, $output);

            return $output;
        }

        // Same provider family as the text generator.
        $generatorRun = $contentVersionId > 0 ? $this->db->table('reach_ai_generation_artifacts a')
            ->join('reach_ai_generation_runs r', 'r.id = a.generation_run_id')
            ->join('reach_ai_providers p', 'p.id = r.provider_id')
            ->select('p.provider_key')
            ->where('a.content_version_id', $contentVersionId)
            ->orderBy('a.id', 'DESC')->limit(1)->get()->getRowArray() : null;
        $textProvider = (string) ($generatorRun['provider_key'] ?? '');
        $imageKeyMap  = ['openai' => 'openai_image', 'gemini' => 'gemini_image'];
        $requestedKey = (string) ($input['provider'] ?? $imageKeyMap[$textProvider] ?? $block['provider_route'] ?? '');
        $providerKey  = in_array($requestedKey, $configured, true) ? $requestedKey : $configured[0];
        $provider     = $registry->get($providerKey);

        $makeInput = static fn (string $model) => new ImageGenerationInput(
            prompt: $prompt,
            modelKey: $model,
            width: (int) ($input['width'] ?? 1536),
            height: (int) ($input['height'] ?? 1024),
        );

        try {
            try {
                $result = $provider->generateImage($makeInput((string) ($input['model'] ?? '')));
            } catch (\Throwable $e) {
                $fallbackModel = trim((string) env('AI_GEMINI_IMAGE_FALLBACK_MODEL', 'gemini-2.5-flash-image'));
                if ($providerKey === 'gemini_image' && $fallbackModel !== ''
                    && str_contains(strtolower($e->getMessage()), 'not')  // NOT_FOUND / not available / not supported
                ) {
                    $result = $provider->generateImage($makeInput($fallbackModel));
                } else {
                    throw $e;
                }
            }

            $binary = $this->firstImageBinary($result);
            if ($binary === null) {
                throw new \RuntimeException('image_provider_returned_no_usable_image');
            }

            $store = new \App\Libraries\Media\MediaAssetStore($this->db);
            $asset = $store->storeBinary($binary, [
                'kind'             => 'ai_generated',
                'content_item_id'  => $contentItemId > 0 ? $contentItemId : null,
                'prompt_used'      => $prompt,
                'portfolio_stream' => $details['portfolio_stream'] ?? null,
            ]);
            $publicUrl = $store->publicUrl($asset);

            if ($contentItemId > 0) {
                $this->setFeaturedImage($contentItemId, $publicUrl, $this->coverAltText($contentItemId, $item));
            }

            $output = array_filter([
                'provider_key' => $result->providerKey,
                'model_key'    => $result->modelKey,
                'image_count'  => $result->imageCount,
                'duration_ms'  => $result->durationMs,
                'asset_id'     => $asset['id'] ?? null,
                'asset_uuid'   => $asset['asset_uuid'] ?? null,
                'public_url'   => $publicUrl,
                'gallery_miss' => $galleryMiss,
            ], static fn ($v) => $v !== null);
        } catch (\Throwable $e) {
            // Image trouble must never stall publication.
            $output = array_filter([
                'image_failed' => true,
                'reason'       => mb_substr($e->getMessage(), 0, 200),
                'provider_key' => $providerKey,
                'gallery_miss' => $galleryMiss,
            ], static fn ($v) => $v !== null);
        }

        $this->chainSeoOptimize($contentItemId, $contentVersionId);
        $this->markCompleted($id, $output);

        return $output;
    }

    /**
     * Topically relevant gallery cover (scored against the article's category,
     * stream, tags and title), least-recently-used among equally relevant
     * assets, with the reuse cooldown enforced. No relevant asset → reported as
     * a miss so the caller generates a cover from the article instead of
     * attaching an unrelated photo.
     *
     * @param array<string,mixed> $item
     * @param array<string,mixed> $details
     * @return array<string,mixed>
     */
    private function assignGalleryCover(int $contentItemId, array $item, array $details): array
    {
        try {
            $assignment = (new \App\Libraries\Media\MediaGalleryAssignmentService($this->db))
                ->assignFor($contentItemId);
        } catch (\Throwable $e) {
            return ['image_failed' => true, 'reason' => 'gallery_assignment_error: ' . mb_substr($e->getMessage(), 0, 160)];
        }

        if ($assignment === null) {
            return ['image_failed' => true, 'reason' => 'gallery_no_relevant_cover'];
        }

        $this->setFeaturedImage(
            $contentItemId,
            $assignment['public_url'],
            $this->coverAltText($contentItemId, $item),
        );

        return [
            'gallery_assigned' => true,
            'asset_id'         => $assignment['asset_id'],
            'asset_uuid'       => $assignment['asset_uuid'],
            'public_url'       => $assignment['public_url'],
            'relevance_score'  => $assignment['relevance_score'],
            'match_reasons'    => $assignment['match_reasons'],
        ];
    }

    /**
     * Alt text that describes the article, not the file — the readiness gate
     * requires it and screen readers depend on it.
     *
     * @param array<string,mixed> $item
     */
    private function coverAltText(int $contentItemId, array $item): string
    {
        $category = $contentItemId > 0
            ? (string) ($this->db->table('reach_blog_publication_profiles')
                ->select('category')
                ->where('content_item_id', $contentItemId)
                ->get()->getRowArray()['category'] ?? '')
            : '';

        return \App\Libraries\Publishing\Blog\CoverAltTextBuilder::build(
            (string) ($item['title'] ?? ''),
            $category,
        );
    }

    private function setFeaturedImage(int $contentItemId, string $url, string $alt): void
    {
        $this->ensurePublicationProfilesForItem($contentItemId);
        $this->db->table('reach_blog_publication_profiles')
            ->where('content_item_id', $contentItemId)
            ->update([
                'featured_image_reference' => mb_substr($url, 0, 512),
                'featured_image_alt'       => mb_substr($alt, 0, 512),
                'updated_at'               => date('Y-m-d H:i:s'),
            ]);
    }

    private function firstImageBinary(\App\Libraries\Ai\Images\ImageGenerationResult $result): ?string
    {
        foreach ($result->images as $image) {
            if (! empty($image['b64'])) {
                $decoded = base64_decode((string) $image['b64'], true);
                if ($decoded !== false && $decoded !== '') {
                    return $decoded;
                }
            }
            if (! empty($image['url'])) {
                $fetched = $this->httpGet((string) $image['url']);
                if ($fetched !== null && $fetched !== '') {
                    return $fetched;
                }
            }
        }

        return null;
    }

    private function chainSeoOptimize(int $contentItemId, int $contentVersionId): void
    {
        if ($contentItemId <= 0) {
            return;
        }

        // Keeps the historical fact_verified successor key so pre-rewire items
        // and idempotent retries coexist.
        $key      = "blog-{$contentItemId}-fact_verified-" . self::TYPE_SEO_OPTIMIZE;
        $existing = $this->db->table('reach_work_blocks')->where('idempotency_key', $key)->get()->getRowArray();

        if ($existing) {
            if (in_array((string) $existing['eligibility_status'], ['pending', 'failed', 'blocked'], true)) {
                $this->db->table('reach_work_blocks')->where('id', $existing['id'])->update([
                    'eligibility_status' => 'eligible',
                    'content_version_id' => $contentVersionId > 0 ? $contentVersionId : ($existing['content_version_id'] ?? null),
                    'updated_at'         => date('Y-m-d H:i:s'),
                ]);
            }

            return;
        }

        $this->create([
            'block_type'         => self::TYPE_SEO_OPTIMIZE,
            'scope'              => 'blog',
            'content_item_id'    => $contentItemId,
            'content_version_id' => $contentVersionId > 0 ? $contentVersionId : null,
            'idempotency_key'    => $key,
            'eligibility_status' => 'eligible',
        ]);
    }

    /** Real rule-based SEO readiness scoring (no AI fabrication). */
    private function executeSeoOptimize(int $id, array $block): array
    {
        $contentItemId = (int) ($block['content_item_id'] ?? 0);
        if ($contentItemId <= 0) {
            throw new \RuntimeException('SEO_OPTIMIZE work block requires content_item_id');
        }

        $this->ensurePublicationProfilesForItem($contentItemId);

        $result = (new SeoReadinessService())->evaluate($contentItemId);

        try {
            (new BlogStateMachine($this))->transition($contentItemId, BlogStateMachine::SEO_REVIEW, null, ['work_block_id' => $id]);
        } catch (\Throwable) {
            // Best-effort bookkeeping only.
        }

        $this->markCompleted($id, $result);

        return $result;
    }

    /** Validates existing internal links (real HTTP HEAD checks); never invents link targets. */
    private function executeInternalLink(int $id, array $block): array
    {
        $contentItemId = (int) ($block['content_item_id'] ?? 0);
        if ($contentItemId <= 0) {
            throw new \RuntimeException('INTERNAL_LINK work block requires content_item_id');
        }

        $broken = (new BlogInternalLinkService())->validateLinks($contentItemId);
        $output = ['content_item_id' => $contentItemId, 'broken_links' => $broken];
        $this->markCompleted($id, $output);

        return $output;
    }

    /**
     * Cross-provider editorial review. Uses CrossReviewRouter to determine
     * which provider SHOULD review (opposite of the generator). If the AI
     * route actually resolves to the same provider as the generator, the
     * block is not silently accepted — it is pushed into a human-review
     * state and visibly audited, per the cross-provider-review requirement.
     */
    private function executeCrossReview(int $id, array $block): array
    {
        if (! $this->flags->isEnabled('ai_generation')) {
            return $this->blockOnFlag($id, self::TYPE_CROSS_REVIEW, 'ai_generation_disabled');
        }

        $contentItemId    = (int) ($block['content_item_id'] ?? 0);
        $contentVersionId = (int) ($block['content_version_id'] ?? 0);
        if ($contentItemId <= 0 || $contentVersionId <= 0) {
            throw new \RuntimeException('CROSS_REVIEW work block requires content_item_id and content_version_id');
        }

        $generatorRun = $this->db->table('reach_ai_generation_artifacts a')
            ->join('reach_ai_generation_runs r', 'r.id = a.generation_run_id')
            ->join('reach_ai_providers p', 'p.id = r.provider_id')
            ->select('p.provider_key')
            ->where('a.content_version_id', $contentVersionId)
            ->orderBy('a.id', 'DESC')->limit(1)->get()->getRowArray();

        $generatorKey = (string) ($generatorRun['provider_key'] ?? 'openai');
        $router       = new CrossReviewRouter();
        $expectedReviewer = $router->reviewerFor($generatorKey);

        $requests = new AiGenerationRequestService();
        $request  = $requests->create([
            'task_type'       => 'editorial_review',
            'content_type'    => 'generic',
            'content_item_id' => $contentItemId,
            'parameters'      => [
                'instructions' => 'Review this blog draft for factual consistency, tone and structure. Return JSON with keys approved (boolean) and notes (string).',
                'generator_provider' => $generatorKey,
                'expected_reviewer'  => $expectedReviewer,
                // Route the review to the opposite provider; the same-provider
                // audit below remains the backstop when no such route exists.
                'provider_preference' => $expectedReviewer,
            ],
        ], ['type' => 'system']);

        (new AiGenerationOrchestrator())->execute((int) $request['id']);
        $refreshed = $requests->findById((int) $request['id']);

        if ($refreshed['status'] !== 'completed') {
            // Editorial AI failed/unavailable — still park for human review instead of
            // leaving the item stuck forever in seo_review with a blocked CROSS_REVIEW.
            try {
                (new BlogStateMachine($this))->transition($contentItemId, BlogStateMachine::INTERNAL_REVIEW, null, [
                    'work_block_id' => $id,
                    'reason'        => 'cross_review_ai_failed_requires_human',
                ]);
            } catch (\Throwable) {
            }

            $auto = (new BlogAutoPublishService($this->flags))->tryAutoPublish($contentItemId, $contentVersionId, $id);
            if ($auto['queued']) {
                $output = [
                    'requires_human'        => false,
                    'reason'                => 'editorial_review_failed_auto_published',
                    'generation_request_id' => $request['id'],
                    'auto_publish'          => $auto,
                ];
                $this->markCompleted($id, $output);

                return $output;
            }

            $gateId = $this->ensureHumanReviewGate($contentItemId, $contentVersionId);
            $output = [
                'requires_human'        => true,
                'reason'                => 'editorial_review_failed',
                'generation_request_id' => $request['id'],
                'human_review_gate_id'  => $gateId,
            ];
            $this->markCompleted($id, $output);

            return $output;
        }

        $run = $this->db->table('reach_ai_generation_runs r')
            ->join('reach_ai_providers p', 'p.id = r.provider_id')
            ->select('p.provider_key')
            ->where('r.generation_request_id', $request['id'])
            ->orderBy('r.id', 'DESC')->limit(1)->get()->getRowArray();

        $actualReviewer = (string) ($run['provider_key'] ?? '');

        if ($actualReviewer !== '' && $actualReviewer === $generatorKey) {
            // Self-review by the same provider must never be silent: force human review
            // unless low-risk auto-publish flags are enabled.
            try {
                (new BlogStateMachine($this))->transition($contentItemId, BlogStateMachine::INTERNAL_REVIEW, null, [
                    'work_block_id' => $id,
                    'reason'        => 'cross_review_self_reviewed_requires_human',
                ]);
            } catch (\Throwable) {
            }

            $auto = (new BlogAutoPublishService($this->flags))->tryAutoPublish($contentItemId, $contentVersionId, $id);
            if ($auto['queued']) {
                $output = [
                    'generator_provider'   => $generatorKey,
                    'reviewer_provider'    => $actualReviewer,
                    'same_provider'        => true,
                    'requires_human'       => false,
                    'auto_publish'         => $auto,
                ];
                \App\Libraries\AuditLogger::record('blog.cross_review_same_provider_auto_publish', $output);
                $this->markCompleted($id, $output);

                return $output;
            }

            $gateId = $this->ensureHumanReviewGate($contentItemId, $contentVersionId);
            $output = [
                'generator_provider' => $generatorKey,
                'reviewer_provider'  => $actualReviewer,
                'same_provider'      => true,
                'requires_human'     => true,
                'human_review_gate_id' => $gateId,
            ];
            \App\Libraries\AuditLogger::record('blog.cross_review_same_provider', $output);
            $this->markCompleted($id, $output);

            return $output;
        }

        // Successful cross-provider review: low/medium risk auto-publishes when flags are on;
        // otherwise park for human approval.
        try {
            (new BlogStateMachine($this))->transition($contentItemId, BlogStateMachine::INTERNAL_REVIEW, null, [
                'work_block_id' => $id,
                'reason'        => 'cross_review_completed',
            ]);
        } catch (\Throwable) {
        }

        $auto = (new BlogAutoPublishService($this->flags))->tryAutoPublish($contentItemId, $contentVersionId, $id);
        if ($auto['queued']) {
            $output = [
                'generator_provider'    => $generatorKey,
                'reviewer_provider'     => $actualReviewer,
                'same_provider'         => false,
                'generation_request_id' => $request['id'],
                'auto_publish'          => $auto,
            ];
            $this->markCompleted($id, $output);

            return $output;
        }

        $gateId = $this->ensureHumanReviewGate($contentItemId, $contentVersionId);

        $output = [
            'generator_provider'    => $generatorKey,
            'reviewer_provider'     => $actualReviewer,
            'same_provider'         => false,
            'generation_request_id' => $request['id'],
            'human_review_gate_id'  => $gateId,
            'auto_publish'          => $auto,
        ];
        $this->markCompleted($id, $output);

        return $output;
    }

    private function ensureHumanReviewGate(int $contentItemId, int $contentVersionId): int
    {
        $gateKey = "blog-{$contentItemId}-human_review_gate";
        $gateRow = $this->db->table('reach_work_blocks')->where('idempotency_key', $gateKey)->get()->getRowArray();
        if ($gateRow) {
            return (int) $gateRow['id'];
        }

        return $this->create([
            'block_type'         => self::TYPE_HUMAN_REVIEW_GATE,
            'scope'              => 'blog',
            'content_item_id'    => $contentItemId,
            'content_version_id' => $contentVersionId > 0 ? $contentVersionId : null,
            'eligibility_status' => 'eligible',
            'priority'           => 10,
            'idempotency_key'    => $gateKey,
        ]);
    }

    /**
     * Human review gate. When auto-publish flags are on and the item is
     * low/medium risk, auto-approve + enqueue PUBLISH_BLOG instead of parking.
     * High-risk and flag-off paths never auto-approve.
     */
    private function executeHumanReviewGate(int $id, array $block): array
    {
        $contentItemId    = (int) ($block['content_item_id'] ?? 0);
        $contentVersionId = (int) ($block['content_version_id'] ?? 0);

        if ($contentItemId > 0) {
            $auto = (new BlogAutoPublishService($this->flags))->tryAutoPublish(
                $contentItemId,
                $contentVersionId > 0 ? $contentVersionId : null,
                $id,
            );
            if ($auto['queued']) {
                $this->markCompleted($id, $auto);

                return $auto;
            }

            try {
                (new BlogStateMachine($this))->transition($contentItemId, BlogStateMachine::INTERNAL_REVIEW, null, ['work_block_id' => $id]);
            } catch (\Throwable) {
                // Already in review or in a state the human path manages directly.
            }
        }

        return $this->blockOnFlag($id, self::TYPE_HUMAN_REVIEW_GATE, 'awaiting_human_review');
    }

    /** Schedules a publish through the real publication pipeline; never publishes directly. */
    private function executeSchedulePublish(int $id, array $block): array
    {
        $contentItemId    = (int) ($block['content_item_id'] ?? 0);
        $contentVersionId = (int) ($block['content_version_id'] ?? 0);
        $input            = $this->decodeInput($block);
        $scheduledAt      = (string) ($input['scheduled_at'] ?? '');

        if ($contentItemId <= 0 || $contentVersionId <= 0 || $scheduledAt === '') {
            throw new \RuntimeException('SCHEDULE_PUBLISH work block requires content_item_id, content_version_id and input_json.scheduled_at');
        }

        $pub = new PublicationDeploymentService($this->jobService);
        $deploymentId = $pub->enqueuePublication($contentItemId, $contentVersionId, (new \App\Libraries\Publishing\PublicationConnectionService())->resolveBlogConnectionKey(), 'schedule', $scheduledAt, null);

        try {
            // APPROVED is a prerequisite adjacency step for SCHEDULED; transitioning
            // through it is a no-op if the item is already there.
            (new BlogStateMachine($this))->transition($contentItemId, BlogStateMachine::APPROVED, null, ['work_block_id' => $id]);
            (new BlogStateMachine($this))->transition($contentItemId, BlogStateMachine::SCHEDULED, null, ['work_block_id' => $id]);
        } catch (\Throwable) {
        }

        $output = ['deployment_id' => $deploymentId, 'scheduled_at' => $scheduledAt];
        $this->markCompleted($id, $output);

        return $output;
    }

    /**
     * @param array<string,mixed> $block
     * @return array<string,mixed>
     */
    private function executePublishBlog(int $id, array $block): array
    {
        if (! $this->flags->isEnabled('public_publisher') || ! $this->flags->isEnabled('auto_publish')) {
            return $this->blockOnFlag($id, self::TYPE_PUBLISH_BLOG, 'publish_flags_disabled');
        }

        $contentItemId    = (int) ($block['content_item_id'] ?? 0);
        $contentVersionId = (int) ($block['content_version_id'] ?? 0);
        if ($contentItemId <= 0 || $contentVersionId <= 0) {
            throw new \RuntimeException('PUBLISH_BLOG work block requires content_item_id and content_version_id');
        }

        $item = $this->db->table('reach_content_items')->where('id', $contentItemId)->get()->getRowArray();
        if (! $item) {
            throw new \RuntimeException("Content item {$contentItemId} not found");
        }
        $risk = strtoupper((string) ($item['risk_level'] ?? 'LOW'));
        // Hard ban applies ONLY to this automated/scheduled dispatch path — never to a
        // human explicitly approving+publishing via ContentPublishController.
        $this->flags->assertHighRiskAutoPublishForbidden($risk);

        // Ensure checksum-bound approval_status before deployment (state machine
        // workflow APPROVED alone is not enough for PublicationDeploymentService).
        $approvals = new BlogContentApprovalService();
        if (strtolower((string) ($item['approval_status'] ?? '')) !== 'approved'
            || ! $approvals->verifyForPublication($contentItemId, $contentVersionId)
        ) {
            $systemUserId = (new BlogAutoPublishService($this->flags))->ensureSystemBotUserId();
            $approvals->approve(
                $contentItemId,
                $contentVersionId,
                $systemUserId,
                'standard',
                'Auto-approval recorded by PUBLISH_BLOG (BLOG_AUTO_PUBLISH_ENABLED)',
            );
        }

        $sm = new BlogStateMachine($this);
        $status = strtolower((string) ($item['workflow_status'] ?? ''));
        try {
            if (in_array($status, [BlogStateMachine::SEO_REVIEW, BlogStateMachine::CHANGES_REQUESTED], true)) {
                $sm->transition($contentItemId, BlogStateMachine::INTERNAL_REVIEW, null, ['work_block_id' => $id]);
                $status = BlogStateMachine::INTERNAL_REVIEW;
            }
            if ($status === BlogStateMachine::INTERNAL_REVIEW) {
                $sm->transition($contentItemId, BlogStateMachine::APPROVED, null, ['work_block_id' => $id]);
                $status = BlogStateMachine::APPROVED;
            }
        } catch (\Throwable) {
        }

        $connectionKey = (new \App\Libraries\Publishing\PublicationConnectionService())
            ->resolveBlogConnectionKey();

        $pub = new PublicationDeploymentService($this->jobService);
        $deploymentId = $pub->enqueuePublication(
            $contentItemId,
            $contentVersionId,
            $connectionKey,
            'publish',
            null,
            null,
        );

        try {
            $fresh = $this->db->table('reach_content_items')->where('id', $contentItemId)->get()->getRowArray();
            $status = strtolower((string) ($fresh['workflow_status'] ?? $status));
            if ($status === BlogStateMachine::APPROVED || $status === BlogStateMachine::SCHEDULED) {
                $sm->transition($contentItemId, BlogStateMachine::PUBLISH_QUEUED, null, [
                    'work_block_id' => $id, 'content_version_id' => $contentVersionId,
                ]);
                $status = BlogStateMachine::PUBLISH_QUEUED;
            }
            if ($status === BlogStateMachine::PUBLISH_QUEUED) {
                $sm->transition($contentItemId, BlogStateMachine::PUBLISHING, null, ['work_block_id' => $id]);
            }
        } catch (\Throwable) {
        }

        $output = ['deployment_id' => $deploymentId, 'status' => 'queued'];
        $this->markCompleted($id, $output);

        return $output;
    }

    /**
     * Verifies the public copy really matches what Reach published by
     * calling the real publisher STATUS/VERIFY endpoints and comparing the
     * returned body hash — never assumes success from the enqueue step
     * alone.
     */
    private function executeVerifyPublication(int $id, array $block): array
    {
        $contentItemId = (int) ($block['content_item_id'] ?? 0);
        if ($contentItemId <= 0) {
            throw new \RuntimeException('VERIFY_PUBLICATION work block requires content_item_id');
        }

        $deployment = $this->db->table('reach_publication_deployments')
            ->where('content_item_id', $contentItemId)
            ->where('public_content_id IS NOT NULL', null, false)
            ->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();

        if (! $deployment) {
            return $this->blockOnFlag($id, self::TYPE_VERIFY_PUBLICATION, 'no_deployment_found');
        }

        $publisher = PublicSitePublisherFactory::make();
        $response  = $publisher->getVerification((int) $deployment['public_content_id']);

        if (! ($response['success'] ?? false)) {
            return $this->markFailed($id, self::TYPE_VERIFY_PUBLICATION, (string) ($response['safe_error_message'] ?? 'verification_call_failed'));
        }

        $checksumMatches = hash_equals(
            (string) ($deployment['payload_checksum'] ?? ''),
            (string) ($response['payload_checksum'] ?? ''),
        );

        $status = $checksumMatches ? 'verified' : 'checksum_mismatch';
        $this->db->table('reach_publication_deployments')->where('id', $deployment['id'])->update([
            'status'     => $checksumMatches ? 'verified' : 'failed',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        if ($checksumMatches) {
            try {
                (new BlogStateMachine($this))->transition($contentItemId, BlogStateMachine::LIVE, null, ['work_block_id' => $id]);
            } catch (\Throwable) {
            }
        }

        $output = ['deployment_id' => $deployment['id'], 'status' => $status, 'public_status' => $response['public_status'] ?? null];
        $this->markCompleted($id, $output);

        return $output;
    }

    /**
     * Verifies the article's canonical URL is actually present in the live
     * public sitemap (a real, unauthenticated HTTP check against the public
     * site — sitemaps are public documents, no signed API call needed) and
     * records that as `sitemap_included`. Never claims a later state
     * (discovered/crawled/indexed) — this handler only confirms the URL is
     * being *offered* for discovery, not that Google has acted on it.
     */
    private function executeUpdateSitemap(int $id, array $block): array
    {
        $contentItemId = (int) ($block['content_item_id'] ?? 0);
        if ($contentItemId <= 0) {
            throw new \RuntimeException('UPDATE_SITEMAP work block requires content_item_id');
        }

        $slug = $this->resolveSlug($contentItemId);
        $siteBase = rtrim((string) ($_ENV['AICOUNTLY_PUBLIC_SITE_BASE_URL'] ?? ''), '/');
        if ($slug === '' || $siteBase === '') {
            return $this->blockOnFlag($id, self::TYPE_UPDATE_SITEMAP, 'slug_or_public_site_url_unavailable');
        }

        $sitemapXml = $this->httpGet($siteBase . '/blogs-sitemap.xml');
        if ($sitemapXml === null) {
            return $this->markFailed($id, self::TYPE_UPDATE_SITEMAP, 'sitemap_fetch_failed');
        }

        $canonicalPath = '/blogs/' . rawurlencode($slug);
        $found = str_contains($sitemapXml, $canonicalPath) || str_contains($sitemapXml, $siteBase . $canonicalPath);

        $this->setIndexingStatus($contentItemId, $found ? 'sitemap_included' : 'not_submitted');

        // Auto-submission: newly-live URLs go straight to IndexNow
        // (Bing/Yandex-class engines; Google retired sitemap ping in 2023 and
        // is covered by the sitemap itself + Search Console).
        $indexnow = null;
        if ($found && filter_var(env('INDEXNOW_ENABLED', 'false'), FILTER_VALIDATE_BOOL)) {
            try {
                $submission = (new \App\Libraries\Intelligence\IndexNowSubmissionService())
                    ->submitUrl(1, $siteBase . $canonicalPath);
                $indexnow = ['status' => $submission['status'] ?? 'unknown', 'id' => $submission['id'] ?? null];
            } catch (\Throwable $e) {
                $indexnow = ['status' => 'error', 'error' => mb_substr($e->getMessage(), 0, 160)];
            }
        }

        if ($found) {
            $checkAt = date('Y-m-d H:i:s', strtotime('+2 days'));
            $this->create([
                'block_type'         => self::TYPE_CHECK_INDEXING,
                'scope'              => 'blog',
                'content_item_id'    => $contentItemId,
                'idempotency_key'    => "blog-{$contentItemId}-check_indexing-" . date('Y-m-d'),
                'eligibility_status' => 'eligible',
                // Give crawlers realistic time before the first check — an
                // indexing check run seconds after sitemap inclusion would
                // always honestly report "discovered" at best.
                'scheduled_at'       => $checkAt,
            ]);
        }

        $output = ['content_item_id' => $contentItemId, 'slug' => $slug, 'sitemap_included' => $found, 'indexnow' => $indexnow];
        $this->markCompleted($id, $output);

        return $output;
    }

    /**
     * Uses the real Search Console connector (never mock in production — see
     * ConnectorProviderFactory) as the only source of an "indexed" signal.
     * Any impressions for the exact page URL in the last 28 days is treated
     * as a defensible proxy for "Google has indexed and is capable of
     * showing this page" (search-console.googleapis.com does not expose a
     * direct per-URL index status without the separate URL Inspection API,
     * which is not wired here). Disabled/misconfigured connectors, or a
     * healthy connector returning zero rows, are reported honestly as
     * `unknown` / `discovered` — never fabricated as `indexed`.
     */
    private function executeCheckIndexing(int $id, array $block): array
    {
        $contentItemId = (int) ($block['content_item_id'] ?? 0);
        if ($contentItemId <= 0) {
            throw new \RuntimeException('CHECK_INDEXING work block requires content_item_id');
        }

        $slug = $this->resolveSlug($contentItemId);
        $siteBase = rtrim((string) ($_ENV['AICOUNTLY_PUBLIC_SITE_BASE_URL'] ?? ''), '/');
        if ($slug === '') {
            return $this->blockOnFlag($id, self::TYPE_CHECK_INDEXING, 'slug_unavailable');
        }

        $connector = \App\Libraries\Intelligence\Connectors\ConnectorProviderFactory::searchConsole();
        if (! $connector->isEnabled()) {
            $this->setIndexingStatus($contentItemId, 'unknown');
            $output = ['content_item_id' => $contentItemId, 'indexing_status' => 'unknown', 'reason' => 'search_console_disabled'];
            $this->markCompleted($id, $output);

            return $output;
        }

        try {
            $pageUrl = $siteBase !== '' ? $siteBase . '/blogs/' . rawurlencode($slug) : '';
            $batch = $connector->fetchSearchMetrics(new \App\Libraries\Intelligence\Connectors\DTOs\IngestionRequest(
                connectionId: 0,
                streamType: 'search_metrics',
                dateFrom: date('Y-m-d', strtotime('-28 days')),
                dateTo: date('Y-m-d'),
                dimensions: ['page'],
            ));
        } catch (\Throwable $e) {
            $this->setIndexingStatus($contentItemId, 'error');
            return $this->markFailed($id, self::TYPE_CHECK_INDEXING, 'search_console_error: ' . $e->getMessage());
        }

        $hasImpressions = false;
        foreach ($batch->rows as $row) {
            if ($pageUrl !== '' && (string) ($row['page_url'] ?? '') === $pageUrl && (int) ($row['impressions'] ?? 0) > 0) {
                $hasImpressions = true;
                break;
            }
        }

        $status = $hasImpressions ? 'indexed' : 'discovered';
        $this->setIndexingStatus($contentItemId, $status);

        $output = ['content_item_id' => $contentItemId, 'indexing_status' => $status, 'rows_checked' => count($batch->rows)];
        $this->markCompleted($id, $output);

        return $output;
    }

    /**
     * Per-article analytics sync is not a meaningful unit of work in the
     * current architecture: GA4/Search Console ingestion runs at the
     * *connection* level on its own schedule (App\Libraries\Intelligence\
     * IngestionCursorService), pulling all pages in one batch — there is no
     * per-URL sync primitive to delegate to, and building a parallel
     * per-article ingestion path would duplicate that canonical pipeline
     * rather than reuse it. This type is intentionally left blocked with
     * that reason rather than faking a per-item sync.
     */
    private function executeSyncAnalytics(int $id, array $block): array
    {
        return $this->blockOnFlag($id, self::TYPE_SYNC_ANALYTICS, 'analytics_sync_is_connection_level_not_per_item');
    }

    private function resolveSlug(int $contentItemId): string
    {
        $seo = $this->db->table('reach_content_seo_profiles')->where('content_item_id', $contentItemId)->get()->getRowArray();
        if (! empty($seo['slug'])) {
            return (string) $seo['slug'];
        }

        $item = $this->db->table('reach_content_items')->where('id', $contentItemId)->get()->getRowArray();

        return (string) ($item['slug'] ?? '');
    }

    private function setIndexingStatus(int $contentItemId, string $status): void
    {
        $this->db->table('reach_blog_publication_profiles')->where('content_item_id', $contentItemId)->update([
            'indexing_status'     => $status,
            'indexing_checked_at' => date('Y-m-d H:i:s'),
            'updated_at'          => date('Y-m-d H:i:s'),
        ]);
    }

    /** Plain unauthenticated GET for public, unsigned resources (sitemap/feed). Never throws. */
    private function httpGet(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno !== CURLE_OK || $status !== 200 || ! is_string($body)) {
            return null;
        }

        return $body;
    }

    /** Marks a publication profile as due for refresh; the roadmap decides what happens next. */
    private function executeRefreshContent(int $id, array $block): array
    {
        $contentItemId = (int) ($block['content_item_id'] ?? 0);
        if ($contentItemId <= 0) {
            throw new \RuntimeException('REFRESH_CONTENT work block requires content_item_id');
        }

        $this->db->table('reach_blog_publication_profiles')->where('content_item_id', $contentItemId)->update([
            'refresh_status' => 'refresh_in_progress',
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);

        try {
            (new BlogStateMachine($this))->transition($contentItemId, BlogStateMachine::REFRESH_IN_PROGRESS, null, ['work_block_id' => $id]);
        } catch (\Throwable) {
        }

        $output = ['content_item_id' => $contentItemId, 'refresh_status' => 'refresh_in_progress'];
        $this->markCompleted($id, $output);

        return $output;
    }

    /**
     * Genuine takedown via PublicationRollbackService::unpublish() - takes
     * the article off the public site entirely. Deliberately distinct from
     * a rollback (revert to a previous version, stays live); see
     * PublicationRollbackService's class docblock.
     */
    private function executeUnpublishBlog(int $id, array $block): array
    {
        $contentItemId = (int) ($block['content_item_id'] ?? 0);
        $input         = $this->decodeInput($block);
        $reason        = (string) ($input['reason'] ?? 'Automated unpublish');

        // 'rolled_back' is included because a rollback (revert to a
        // previous version) keeps the article live on the public site -
        // it must remain eligible for a later emergency unpublish, exactly
        // like a plain 'published'/'verified' deployment would.
        $deployment = $this->db->table('reach_publication_deployments')
            ->where('content_item_id', $contentItemId)
            ->whereIn('status', ['published', 'verified', 'rolled_back'])
            ->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();

        if (! $deployment) {
            return $this->blockOnFlag($id, self::TYPE_UNPUBLISH_BLOG, 'no_active_deployment');
        }

        $success = (new PublicationRollbackService())->unpublish((int) $deployment['id'], $reason, null);

        if ($success && $contentItemId > 0) {
            try {
                $sm = new BlogStateMachine($this);
                // LIVE only adjoins UNPUBLISH_QUEUED; complete the takedown path.
                $sm->transition($contentItemId, BlogStateMachine::UNPUBLISH_QUEUED, null, ['work_block_id' => $id]);
                $sm->transition($contentItemId, BlogStateMachine::UNPUBLISHED, null, ['work_block_id' => $id]);
            } catch (\Throwable) {
            }
        }

        $output = ['deployment_id' => $deployment['id'], 'unpublished' => $success];
        if ($success) {
            $this->markCompleted($id, $output);
        } else {
            return $this->markFailed($id, self::TYPE_UNPUBLISH_BLOG, 'unpublish_failed');
        }

        return $output;
    }

    /**
     * RENDER_FILES / BACKFILL_STORAGE: re-sends the current approved version
     * to the public receiver as a draft (create/update draft only, no
     * status flip), which is what actually (re)writes the file-based
     * package on aicountly-com. This is the mechanism by which existing
     * published content's body moves from a DB-only representation to a
     * real file package without changing its live status.
     */
    private function executeRenderFiles(int $id, array $block): array
    {
        if (! $this->flags->isEnabled('public_publisher')) {
            return $this->blockOnFlag($id, self::TYPE_RENDER_FILES, 'public_publisher_disabled');
        }

        $contentItemId    = (int) ($block['content_item_id'] ?? 0);
        $contentVersionId = (int) ($block['content_version_id'] ?? 0);
        if ($contentItemId <= 0 || $contentVersionId <= 0) {
            throw new \RuntimeException('RENDER_FILES work block requires content_item_id and content_version_id');
        }

        $pub = new PublicationDeploymentService($this->jobService);
        $deploymentId = $pub->enqueuePublication($contentItemId, $contentVersionId, (new \App\Libraries\Publishing\PublicationConnectionService())->resolveBlogConnectionKey(), 'create_draft', null, null);

        $output = ['deployment_id' => $deploymentId];
        $this->markCompleted($id, $output);

        return $output;
    }

    /**
     * MERGE_COMPETING follow-through: links the losing candidate's content
     * item to the winning one and archives the loser. Requires an explicit
     * target_content_item_id — never guesses a merge target.
     */
    private function executeConsolidateContent(int $id, array $block): array
    {
        $input       = $this->decodeInput($block);
        $sourceId    = (int) ($block['content_item_id'] ?? 0);
        $targetId    = (int) ($input['target_content_item_id'] ?? 0);

        if ($sourceId <= 0 || $targetId <= 0) {
            throw new \RuntimeException('CONSOLIDATE_CONTENT work block requires content_item_id and input_json.target_content_item_id');
        }

        $target = $this->db->table('reach_content_items')->where('id', $targetId)->get()->getRowArray();
        if (! $target) {
            throw new \RuntimeException("Target content item {$targetId} not found");
        }

        (new BlogInternalLinkService())->addLink(
            $sourceId,
            null,
            (string) ($target['title'] ?? 'related article'),
            '/blogs/' . ($target['slug'] ?? ''),
            $targetId,
            'consolidate_content_merge',
        );

        try {
            (new BlogStateMachine($this))->transition($sourceId, BlogStateMachine::ARCHIVED, null, ['work_block_id' => $id, 'merged_into' => $targetId]);
        } catch (\Throwable) {
        }

        $output = ['source_content_item_id' => $sourceId, 'merged_into_content_item_id' => $targetId];
        $this->markCompleted($id, $output);

        return $output;
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    public function buildUniqueSlug(string $title): string
    {
        $base = strtolower(trim(preg_replace('/[^a-z0-9]+/', '-', $title) ?? ''));
        $base = trim($base, '-') ?: ('topic-' . bin2hex(random_bytes(4)));
        $slug = $base;
        $i    = 1;
        while ($this->db->table('reach_content_items')->where('slug', $slug)->countAllResults() > 0) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }

    /**
     * @param array<string,mixed> $block
     * @return array<string,mixed>
     */
    private function decodeInput(array $block): array
    {
        $raw = $block['input_json'] ?? null;
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Ensure SEO + blog publication profiles exist with the minimum fields
     * required by BlogReadinessService before schedule/publish.
     */
    public function ensurePublicationProfilesForItem(int $contentItemId): void
    {
        $item = $this->db->table('reach_content_items')->where('id', $contentItemId)->get()->getRowArray();
        if (! $item) {
            return;
        }

        $brief = (new ContentBriefModel())->forItem($contentItemId) ?: [];
        $version = $this->db->table('reach_content_versions')
            ->where('content_item_id', $contentItemId)
            ->orderBy('version_number', 'DESC')
            ->limit(1)->get()->getRowArray();

        $slug = (string) ($item['slug'] ?? '');
        if ($slug === '') {
            $slug = $this->buildUniqueSlug((string) ($item['title'] ?? 'blog-post'));
        }

        $metaTitle = mb_substr((string) ($version['title'] ?? $item['title'] ?? $slug), 0, 120);
        $metaDescription = mb_substr(
            (string) ($version['summary'] ?? $brief['objective'] ?? $item['title'] ?? 'Blog article'),
            0,
            320,
        );
        if (mb_strlen($metaDescription) < 100) {
            $metaDescription = str_pad($metaDescription, 100, '.');
        }

        $seo = $this->db->table('reach_content_seo_profiles')
            ->where('content_item_id', $contentItemId)->get()->getRowArray();

        $seoData = [
            'content_version_id'     => $version['id'] ?? null,
            'primary_keyword'        => $brief['primary_keyword'] ?? ($item['title'] ?? null),
            'meta_title'             => $metaTitle,
            'meta_description'       => $metaDescription,
            'slug'                   => $slug,
            'canonical_preference'   => 'self_canonical',
            'seo_status'             => 'ready',
            'updated_at'             => date('Y-m-d H:i:s'),
        ];

        if ($seo) {
            // Never overwrite a blocked profile; fill only missing readiness fields.
            $patch = ['updated_at' => $seoData['updated_at']];
            foreach (['slug', 'meta_title', 'meta_description', 'canonical_preference', 'primary_keyword'] as $field) {
                if (empty($seo[$field])) {
                    $patch[$field] = $seoData[$field];
                }
            }
            if (! in_array($seo['seo_status'] ?? '', ['ready', 'warning', 'blocked'], true)) {
                $patch['seo_status'] = 'ready';
            }
            $this->db->table('reach_content_seo_profiles')
                ->where('content_item_id', $contentItemId)->update($patch);
        } else {
            $seoData['content_item_id'] = $contentItemId;
            $seoData['created_at'] = date('Y-m-d H:i:s');
            $this->db->table('reach_content_seo_profiles')->insert($seoData);
        }

        $profiles = new BlogPublishingProfileService();
        $profile = $profiles->getOrCreate($contentItemId);
        if (empty($profile['author_reference'])) {
            $profiles->update($contentItemId, [
                'author_reference' => 'aicountly-editorial',
                'excerpt'          => $metaDescription,
            ]);
        }
    }

    /**
     * A flag/dependency is unavailable. This is NEVER treated as success:
     * eligibility_status becomes 'blocked', not 'completed'. Job Monitor
     * and dashboards must be able to tell "nothing happened because a
     * dependency is off" apart from "this genuinely finished".
     *
     * @return array<string,mixed>
     */
    private function blockOnFlag(int $id, string $blockType, string $reason): array
    {
        $output = [
            'blocked'    => true,
            'reason'     => $reason,
            'block_type' => $blockType,
        ];

        $now = date('Y-m-d H:i:s');
        $this->db->table('reach_work_blocks')->where('id', $id)->update([
            'eligibility_status'     => 'blocked',
            'failure_classification' => mb_substr($reason, 0, 64),
            'output_json'            => json_encode($output, JSON_UNESCAPED_SLASHES),
            'updated_at'             => $now,
        ]);

        return $output;
    }

    /**
     * Map free-text / legacy funnel labels onto reach_content_briefs CHECK values.
     * Allowed: top | middle | bottom | null.
     */
    private function normalizeFunnelStage(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return 'top';
        }

        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            'top', 'tof', 'tofu', 'awareness', 'aware' => 'top',
            'middle', 'mof', 'mofu', 'consideration', 'consider' => 'middle',
            'bottom', 'bof', 'bofu', 'decision', 'conversion', 'convert' => 'bottom',
            default => 'top',
        };
    }

    /**
     * A real, permanent failure (not a disabled flag). Distinguished from
     * blocked so retries/backoff and Job Monitor failure counts are correct.
     *
     * @return array<string,mixed>
     */
    private function markFailed(int $id, string $blockType, string $reason): array
    {
        $output = [
            'failed'     => true,
            'reason'     => $reason,
            'block_type' => $blockType,
        ];

        // failure_classification is VARCHAR(64). Callers often pass a full
        // RuntimeException message (far longer), which previously aborted the
        // Feature suite with "value too long for type character varying(64)".
        // Keep the full reason in output_json; store a stable truncated label
        // in the classification column.
        $classification = mb_substr(preg_replace('/\s+/', '_', strtolower(trim($reason))) ?? $reason, 0, 64);

        $now = date('Y-m-d H:i:s');
        $this->db->table('reach_work_blocks')->where('id', $id)->update([
            'eligibility_status'     => 'failed',
            'failure_classification' => $classification,
            'output_json'            => json_encode($output, JSON_UNESCAPED_SLASHES),
            'completed_at'           => $now,
            'updated_at'             => $now,
        ]);

        return $output;
    }

    private function moveDraftGeneratingToFailed(int $contentItemId, BlogStateMachine $stateMachine, int $workBlockId): void
    {
        if ($contentItemId <= 0) {
            return;
        }
        try {
            $stateMachine->transition($contentItemId, BlogStateMachine::FAILED, null, [
                'work_block_id' => $workBlockId,
                'reason'        => 'generate_draft_failed',
            ]);
        } catch (\Throwable) {
            // Best-effort — do not mask the original generation failure.
        }
    }

    private function lastAiFailureDetail(int $generationRequestId): string
    {
        if ($generationRequestId <= 0) {
            return '';
        }

        // Prefer schema errors — provider runs often "completed" while request failed validation.
        if ($this->db->tableExists('reach_ai_generation_artifacts')) {
            $artifact = $this->db->table('reach_ai_generation_artifacts')
                ->select('schema_validation_status, schema_validation_errors')
                ->where('generation_request_id', $generationRequestId)
                ->orderBy('id', 'DESC')
                ->limit(1)
                ->get()
                ->getRowArray();
            if ($artifact && ($artifact['schema_validation_status'] ?? '') === 'failed') {
                $errors = $artifact['schema_validation_errors'] ?? '';
                if (! is_string($errors)) {
                    $errors = json_encode($errors);
                }

                return mb_substr('schema_validation_failed|' . (string) $errors, 0, 220);
            }
        }

        if (! $this->db->tableExists('reach_ai_generation_runs')) {
            return '';
        }

        $run = $this->db->table('reach_ai_generation_runs')
            ->select('error_category, redacted_error_message, status')
            ->where('generation_request_id', $generationRequestId)
            ->orderBy('id', 'DESC')
            ->limit(1)
            ->get()
            ->getRowArray();

        if (! $run) {
            return 'no_provider_run';
        }

        $parts = array_filter([
            (string) ($run['error_category'] ?? ''),
            (string) ($run['redacted_error_message'] ?? ''),
        ]);

        return mb_substr(implode('|', $parts), 0, 180);
    }

    /**
     * @param array<string,mixed> $output
     */
    private function markCompleted(int $id, array $output): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('reach_work_blocks')->where('id', $id)->update([
            'eligibility_status' => 'completed',
            'output_json'        => json_encode($output, JSON_UNESCAPED_SLASHES),
            'completed_at'       => $now,
            'updated_at'         => $now,
        ]);
    }
}
