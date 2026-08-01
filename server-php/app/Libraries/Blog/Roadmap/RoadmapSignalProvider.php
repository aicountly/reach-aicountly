<?php

namespace App\Libraries\Blog\Roadmap;

use App\Libraries\Blog\BlogPortfolioService;
use App\Libraries\Support\PgBoolean;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Gathers normalised scoring signals for topic candidates from analytics facts when available.
 */
class RoadmapSignalProvider
{
    private ?BaseConnection $db = null;

    public function __construct(
        private ?BlogPortfolioService $portfolioService = null,
        private ?StabilityController $stability = null,
    ) {
        $this->portfolioService ??= new BlogPortfolioService();
        $this->stability ??= new StabilityController();
    }

    /**
     * @param array<string,mixed> $candidate
     * @return array<string,mixed>
     */
    public function gatherForCandidate(array $candidate, string $forDate): array
    {
        $signals        = [];
        $missingSignals = [];

        $contentItemId = (int) ($candidate['content_item_id'] ?? 0);
        $identityId    = $contentItemId > 0 ? $this->resolveContentIdentityId($contentItemId) : null;

        if ($identityId !== null) {
            $signals = array_merge($signals, $this->searchConsoleSignals($identityId, $forDate, $missingSignals));
            $signals = array_merge($signals, $this->analyticsSignals($identityId, $forDate, $missingSignals));
        } else {
            $missingSignals[] = 'content_identity';
        }

        $clusterId = (int) ($candidate['topic_cluster_id'] ?? 0);
        if ($clusterId > 0) {
            $coverage = $this->clusterCoverage($clusterId);
            if ($coverage !== null) {
                $signals['cluster_coverage'] = $coverage;
            } else {
                $missingSignals[] = 'cluster_coverage';
            }
        } else {
            $missingSignals[] = 'cluster_coverage';
        }

        if (array_key_exists('evidence_ready', $candidate)) {
            $signals['evidence_ready'] = (bool) $candidate['evidence_ready'];
        }

        $productId = (int) ($candidate['primary_product_id'] ?? 0);
        if ($productId > 0) {
            $priority = $this->productPrioritySignal($productId);
            if ($priority !== null) {
                $signals['product_priority'] = $priority;
            } else {
                $missingSignals[] = 'product_priority';
            }
        }

        $portfolioCounts = $this->portfolioStreamCounts();
        $targetMix       = $this->portfolioTargetMix();
        $stream          = (string) ($candidate['portfolio_stream'] ?? '');
        if ($stream !== '' && $this->stability->portfolioOverConcentrated($stream, $portfolioCounts, $targetMix)) {
            $signals['portfolio_over_concentration'] = true;
        }

        $signals['missing_signals'] = array_values(array_unique($missingSignals));

        return $signals;
    }

    /**
     * @return array<string,mixed>
     */
    public function buildDecisionContext(array $candidate, array $signals, string $forDate): array
    {
        $contentItemId = (int) ($candidate['content_item_id'] ?? 0);
        $productId     = (int) ($candidate['primary_product_id'] ?? 0);

        return [
            'signals'                       => $signals,
            'content_stale'                 => $contentItemId > 0 ? $this->isContentStale($contentItemId, $forDate) : false,
            'product_production_ready'      => $productId > 0 ? $this->isProductProductionReady($productId) : true,
            'create_product_support_flagged'=> ! empty($signals['create_product_support']),
            'risk_level'                    => $this->contentRiskLevel($contentItemId),
            'evidence_ready'                => PgBoolean::isTrue($signals['evidence_ready'] ?? false)
                || PgBoolean::isTrue($candidate['evidence_ready'] ?? false),
            'portfolio_counts'              => $this->portfolioStreamCounts(),
            'portfolio_target_mix'          => $this->portfolioTargetMix(),
        ];
    }

    /**
     * @param list<string> $missingSignals
     * @return array<string,mixed>
     */
    private function searchConsoleSignals(int $identityId, string $forDate, array &$missingSignals): array
    {
        if (! $this->db()->tableExists('reach_search_metric_facts')) {
            $missingSignals[] = 'search_console';
            return [];
        }

        $count = (int) $this->db()->table('reach_search_metric_facts')
            ->where('content_identity_id', $identityId)
            ->countAllResults();
        if ($count === 0) {
            $missingSignals[] = 'search_console';
            return [];
        }

        $from = date('Y-m-d', strtotime($forDate . ' -28 days'));
        $row  = $this->db()->table('reach_search_metric_facts')
            ->select('SUM(clicks) AS clicks, SUM(impressions) AS impressions, AVG(ctr) AS ctr, AVG(avg_position) AS avg_position')
            ->where('content_identity_id', $identityId)
            ->where('metric_date >=', $from)
            ->where('metric_date <=', $forDate)
            ->get()
            ->getRowArray();

        if (! $row || ((int) ($row['impressions'] ?? 0)) === 0 && ((int) ($row['clicks'] ?? 0)) === 0) {
            $missingSignals[] = 'search_console';
            return [];
        }

        return array_filter([
            'clicks'         => (float) ($row['clicks'] ?? 0),
            'impressions'    => (float) ($row['impressions'] ?? 0),
            'ctr'            => isset($row['ctr']) ? (float) $row['ctr'] : null,
            'avg_position'   => isset($row['avg_position']) ? (float) $row['avg_position'] : null,
        ], static fn ($v) => $v !== null);
    }

    /**
     * @param list<string> $missingSignals
     * @return array<string,mixed>
     */
    private function analyticsSignals(int $identityId, string $forDate, array &$missingSignals): array
    {
        if (! $this->db()->tableExists('reach_content_metric_facts')) {
            $missingSignals[] = 'ga4';
            return [];
        }

        $count = (int) $this->db()->table('reach_content_metric_facts')
            ->where('content_identity_id', $identityId)
            ->countAllResults();
        if ($count === 0) {
            $missingSignals[] = 'ga4';
            return [];
        }

        $from = date('Y-m-d', strtotime($forDate . ' -28 days'));
        $row  = $this->db()->table('reach_content_metric_facts')
            ->select('SUM(sessions) AS organic_sessions, SUM(page_views) AS product_visits')
            ->where('content_identity_id', $identityId)
            ->where('metric_date >=', $from)
            ->where('metric_date <=', $forDate)
            ->groupStart()
                ->where('source', 'google')
                ->orWhere('medium', 'organic')
            ->groupEnd()
            ->get()
            ->getRowArray();

        if (! $row || ((int) ($row['organic_sessions'] ?? 0)) === 0) {
            $missingSignals[] = 'ga4';
            return [];
        }

        return [
            'organic_sessions' => (float) ($row['organic_sessions'] ?? 0),
            'product_visits'   => (float) ($row['product_visits'] ?? 0),
        ];
    }

    private function resolveContentIdentityId(int $contentItemId): ?int
    {
        if (! $this->db()->tableExists('reach_content_identities')) {
            return null;
        }

        $row = $this->db()->table('reach_content_identities')
            ->select('id')
            ->where('content_type', 'blog')
            ->where('source_id', $contentItemId)
            ->get()
            ->getRowArray();

        return $row ? (int) $row['id'] : null;
    }

    private function clusterCoverage(int $clusterId): ?float
    {
        if (! $this->db()->tableExists('reach_content_items')) {
            return null;
        }

        $total = (int) $this->db()->table('reach_content_items')
            ->where('content_type', 'blog')
            ->where('deleted_at IS NULL', null, false)
            ->countAllResults();
        if ($total === 0) {
            return 0.0;
        }

        $clusterCount = (int) $this->db()->table('reach_content_items')
            ->where('content_type', 'blog')
            ->where('primary_topic_cluster_id', $clusterId)
            ->where('deleted_at IS NULL', null, false)
            ->countAllResults();

        return min(1.0, $clusterCount / max(1, $total));
    }

    /**
     * @return array<string,int>
     */
    public function portfolioStreamCounts(): array
    {
        $counts = [
            'marketing'          => 0,
            'product'              => 0,
            'problem_to_product'   => 0,
        ];

        if (! $this->db()->tableExists('reach_content_blog_details')
            || ! $this->db()->tableExists('reach_content_items')) {
            return $counts;
        }

        $rows = $this->db()->table('reach_content_blog_details rcbd')
            ->select('rcbd.portfolio_stream, COUNT(*) AS cnt')
            ->join('reach_content_items ci', 'ci.id = rcbd.content_item_id')
            ->where('ci.content_type', 'blog')
            ->where('ci.deleted_at IS NULL', null, false)
            ->where('ci.workflow_status', 'published')
            ->groupBy('rcbd.portfolio_stream')
            ->get()
            ->getResultArray();

        foreach ($rows as $row) {
            $stream = strtolower((string) ($row['portfolio_stream'] ?? ''));
            $key    = match ($stream) {
                'marketing' => 'marketing',
                'product' => 'product',
                'problem_to_product', 'problem-to-product' => 'problem_to_product',
                default => $stream,
            };
            if ($key !== '') {
                $counts[$key] = (int) ($row['cnt'] ?? 0);
            }
        }

        return $counts;
    }

    /**
     * @return array<string,int>
     */
    public function portfolioTargetMix(): array
    {
        $portfolio = $this->portfolioService->get();

        return [
            'marketing'          => (int) ($portfolio['marketing_percent'] ?? 45),
            'product'            => (int) ($portfolio['product_percent'] ?? 35),
            'problem_to_product' => (int) ($portfolio['problem_to_product_percent'] ?? 20),
        ];
    }

    private function productPrioritySignal(int $productId): ?float
    {
        if (! $this->db()->tableExists('reach_products')) {
            return null;
        }

        $row = $this->db()->table('reach_products')
            ->select('status')
            ->where('id', $productId)
            ->where('deleted_at IS NULL', null, false)
            ->get()
            ->getRowArray();

        if (! $row) {
            return null;
        }

        return match ((string) ($row['status'] ?? '')) {
            'approved' => 1.0,
            'needs_review' => 0.6,
            'draft' => 0.3,
            default => 0.1,
        };
    }

    private function isProductProductionReady(int $productId): bool
    {
        if (! $this->db()->tableExists('reach_products')) {
            return true;
        }

        $row = $this->db()->table('reach_products')
            ->select('status')
            ->where('id', $productId)
            ->where('deleted_at IS NULL', null, false)
            ->get()
            ->getRowArray();

        return ($row['status'] ?? '') === 'approved';
    }

    private function isContentStale(int $contentItemId, string $forDate): bool
    {
        if (! $this->db()->tableExists('reach_content_items')) {
            return false;
        }

        $row = $this->db()->table('reach_content_items')
            ->select('refresh_due_at, updated_at, published_at')
            ->where('id', $contentItemId)
            ->get()
            ->getRowArray();

        if (! $row) {
            return false;
        }

        if (! empty($row['refresh_due_at']) && strtotime((string) $row['refresh_due_at']) <= strtotime($forDate)) {
            return true;
        }

        $anchor = $row['published_at'] ?? $row['updated_at'] ?? null;
        if ($anchor === null) {
            return false;
        }

        return strtotime((string) $anchor) < strtotime($forDate . ' -180 days');
    }

    private function contentRiskLevel(int $contentItemId): string
    {
        if ($contentItemId <= 0 || ! $this->db()->tableExists('reach_content_blog_details')) {
            return 'LOW';
        }

        $row = $this->db()->table('reach_content_blog_details')
            ->select('risk_class')
            ->where('content_item_id', $contentItemId)
            ->get()
            ->getRowArray();

        return strtoupper((string) ($row['risk_class'] ?? 'LOW'));
    }

    private function db(): BaseConnection
    {
        return $this->db ??= Database::connect();
    }
}
