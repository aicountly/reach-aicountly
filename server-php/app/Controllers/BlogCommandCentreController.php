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
use Config\Database;

class BlogCommandCentreController extends BaseApiController
{
    public function overview()
    {
        $db    = Database::connect();
        $flags = new BlogFeatureFlags();

        $workflowStatuses = [
            'idea', 'draft', 'seo_review', 'internal_review', 'approved',
            'scheduled', 'published', 'rejected', 'archived',
        ];
        $workflowCounts = [];
        foreach ($workflowStatuses as $status) {
            $workflowCounts[$status] = (int) $db->table('reach_content_items')
                ->where('content_type', 'blog')
                ->where('workflow_status', $status)
                ->where('deleted_at IS NULL', null, false)
                ->countAllResults();
        }

        $portfolio = (new BlogPortfolioService())->get();

        $publishing = [
            'queued'    => (int) $db->table('reach_publication_deployments')->where('status', 'queued')->countAllResults(),
            'sending'   => (int) $db->table('reach_publication_deployments')->where('status', 'sending')->countAllResults(),
            'failed'    => (int) $db->table('reach_publication_deployments')->where('status', 'failed')->countAllResults(),
            'published' => (int) $db->table('reach_publication_deployments')->where('status', 'published')->countAllResults(),
            'verified'  => (int) $db->table('reach_publication_deployments')->where('status', 'verified')->countAllResults(),
        ];

        $workBlocks = [
            'pending'   => (int) $db->table('reach_work_blocks')->where('eligibility_status', 'pending')->countAllResults(),
            'eligible'  => (int) $db->table('reach_work_blocks')->where('eligibility_status', 'eligible')->countAllResults(),
            'queued'    => (int) $db->table('reach_work_blocks')->where('eligibility_status', 'queued')->countAllResults(),
            'completed' => (int) $db->table('reach_work_blocks')->where('eligibility_status', 'completed')->countAllResults(),
        ];

        return $this->ok([
            'workflow_counts' => $workflowCounts,
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
     * Report the true state of each external connector so the dashboard can show
     * DISABLED, MISCONFIGURED or CONNECTED rather than implying data exists.
     *
     * @return array<string, array<string, mixed>>
     */
    private function connectorStates(BlogFeatureFlags $flags): array
    {
        $searchConsole = ['status' => 'DISABLED', 'detail' => 'Search Console is disabled for blogs.'];

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
            'images_enabled'       => $flags->isEnabled('image_generation'),
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

        if (! $db->tableExists('reach_optimizer_runs')) {
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

        if (! $db->tableExists('reach_topic_candidates')) {
            return $this->ok(['items' => [], 'total' => 0, 'page' => $page, 'limit' => $limit]);
        }

        $status = trim((string) $this->request->getGet('status'));
        $stream = trim((string) $this->request->getGet('portfolio_stream'));

        $builder = $db->table('reach_topic_candidates tc')
            ->select('tc.*, ts.total_score, ts.scored_for_date, ts.trend_7d, ts.trend_28d')
            ->join(
                'reach_topic_scores ts',
                'ts.topic_candidate_id = tc.id AND ts.is_current = true',
                'left',
            );

        if ($status !== '') {
            $builder->where('tc.status', $status);
        }
        if ($stream !== '') {
            $builder->where('tc.portfolio_stream', $stream);
        }

        $total = $builder->countAllResults(false);
        $rows  = $builder->orderBy('ts.total_score', 'DESC', false)
            ->orderBy('tc.updated_at', 'DESC')
            ->limit($limit, $offset)
            ->get()
            ->getResultArray();

        return $this->ok(['items' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit]);
    }

    public function roadmapScored()
    {
        [$page, $limit, $offset] = $this->pagination();
        $db = Database::connect();

        if (! $db->tableExists('reach_topic_scores')) {
            return $this->ok(['items' => [], 'total' => 0, 'page' => $page, 'limit' => $limit]);
        }

        $builder = $db->table('reach_topic_scores ts')
            ->select('ts.*, tc.title, tc.portfolio_stream, tc.status AS candidate_status')
            ->join('reach_topic_candidates tc', 'tc.id = ts.topic_candidate_id', 'inner')
            ->where('ts.is_current', true);

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

        if (! $db->tableExists('reach_roadmap_decisions')) {
            return $this->ok(['items' => [], 'total' => 0, 'page' => $page, 'limit' => $limit]);
        }

        $decision = trim((string) $this->request->getGet('decision'));
        $builder  = $db->table('reach_roadmap_decisions rd')
            ->select('rd.*, tc.title')
            ->join('reach_topic_candidates tc', 'tc.id = rd.topic_candidate_id', 'left');

        if ($decision !== '') {
            $builder->where('rd.decision', $decision);
        }

        $total = $builder->countAllResults(false);
        $rows  = $builder->orderBy('rd.decided_for_date', 'DESC')
            ->orderBy('rd.id', 'DESC')
            ->limit($limit, $offset)
            ->get()
            ->getResultArray();

        return $this->ok(['items' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit]);
    }

    public function optimizerRuns()
    {
        [$page, $limit, $offset] = $this->pagination();
        $db = Database::connect();

        if (! $db->tableExists('reach_optimizer_runs')) {
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
        if (! $db->tableExists('reach_roadmap_scoring_weights')) {
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

        if (! $db->tableExists('reach_roadmap_scoring_weights')) {
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
