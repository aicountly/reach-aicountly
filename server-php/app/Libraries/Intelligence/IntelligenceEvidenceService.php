<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence;

use App\Models\Intelligence\ContentIdentityModel;
use App\Models\Intelligence\SearchMetricFactModel;
use App\Models\Intelligence\ContentMetricFactModel;
use App\Models\Intelligence\SitemapSnapshotModel;
use App\Models\Intelligence\IndexNowSubmissionModel;
use App\Models\Intelligence\AiVisibilityObservationModel;
use App\Models\Intelligence\AttributionConversionLinkModel;

/**
 * Phase 9 evidence contract: provides a standardised evidence snapshot
 * for each canonical content identity. Phase 9 must only read data
 * through this service — never bypass to the underlying models.
 */
class IntelligenceEvidenceService
{
    private const DEFAULT_WINDOW_DAYS = 28;

    public function __construct(
        private ContentIdentityModel          $identityModel,
        private SearchMetricFactModel         $searchFactModel,
        private ContentMetricFactModel        $contentFactModel,
        private SitemapSnapshotModel          $sitemapSnapshotModel,
        private IndexNowSubmissionModel       $indexNowModel,
        private AiVisibilityObservationModel  $observationModel,
        private AttributionConversionLinkModel $conversionModel,
    ) {}

    /**
     * Returns a complete Phase 9 evidence packet for a content identity.
     *
     * @param int    $contentIdentityId
     * @param string $asOf              ISO-8601 date — evidence collected up to this date
     * @param int    $windowDays        comparison window in days (default 28)
     * @return array{
     *   identity: array,
     *   search: array,
     *   engagement: array,
     *   indexing: array,
     *   visibility: array,
     *   attribution: array,
     *   freshness: array,
     *   completeness: array,
     * }
     */
    public function getEvidencePacket(int $contentIdentityId, string $asOf, int $windowDays = self::DEFAULT_WINDOW_DAYS): array
    {
        $identity = $this->identityModel->find($contentIdentityId);
        if (!$identity) {
            throw new \RuntimeException("Content identity {$contentIdentityId} not found");
        }

        $windowFrom = date('Y-m-d', strtotime($asOf . " -{$windowDays} days"));

        $searchFacts    = $this->searchFactModel->getForContent($contentIdentityId, $windowFrom, $asOf);
        $contentFacts   = $this->contentFactModel->getForContent($contentIdentityId, $windowFrom, $asOf);
        $latestSnapshot = $this->sitemapSnapshotModel->orderBy('snapshot_at', 'DESC')->first();
        $recentIndexNow = $this->indexNowModel->where('content_identity_id', $contentIdentityId)
                                              ->where('submitted_at >=', $windowFrom)
                                              ->orderBy('submitted_at', 'DESC')
                                              ->first();
        $visObs         = $this->observationModel->where('content_identity_id', $contentIdentityId)->findAll();
        $conversions    = $this->conversionModel->getByContentIdentity($contentIdentityId, $windowFrom, $asOf);

        return [
            'identity'    => $this->buildIdentityEvidence($identity),
            'search'      => $this->buildSearchEvidence($searchFacts),
            'engagement'  => $this->buildEngagementEvidence($contentFacts),
            'indexing'    => $this->buildIndexingEvidence($latestSnapshot, $recentIndexNow, $identity),
            'visibility'  => $this->buildVisibilityEvidence($visObs),
            'attribution' => $this->buildAttributionEvidence($conversions),
            'freshness'   => $this->buildFreshnessEvidence($searchFacts, $contentFacts, $asOf),
            'completeness'=> $this->buildCompletenessEvidence($searchFacts, $contentFacts, $visObs, $conversions),
        ];
    }

    private function buildIdentityEvidence(array $identity): array
    {
        return [
            'id'               => $identity['id'],
            'canonical_url'    => $identity['canonical_url'],
            'content_type'     => $identity['content_type'],
            'publication_state'=> $identity['publication_state'],
            'published_at'     => $identity['published_at'],
        ];
    }

    private function buildSearchEvidence(array $facts): array
    {
        if (empty($facts)) {
            return ['available' => false, 'reason' => 'no_gsc_data'];
        }

        $clicks      = array_sum(array_column($facts, 'clicks'));
        $impressions = array_sum(array_column($facts, 'impressions'));
        $positions   = array_filter(array_column($facts, 'avg_position'));

        return [
            'available'      => true,
            'clicks'         => $clicks,
            'impressions'    => $impressions,
            'avg_ctr'        => $impressions > 0 ? round($clicks / $impressions, 4) : null,
            'avg_position'   => !empty($positions) ? round(array_sum($positions) / count($positions), 2) : null,
            'data_points'    => count($facts),
        ];
    }

    private function buildEngagementEvidence(array $facts): array
    {
        if (empty($facts)) {
            return ['available' => false, 'reason' => 'no_ga4_data'];
        }

        $sessions    = array_sum(array_column($facts, 'sessions'));
        $pageViews   = array_sum(array_column($facts, 'page_views'));
        $engRates    = array_filter(array_column($facts, 'engagement_rate'));

        return [
            'available'       => true,
            'sessions'        => $sessions,
            'page_views'      => $pageViews,
            'avg_engagement_rate' => !empty($engRates) ? round(array_sum($engRates) / count($engRates), 4) : null,
            'data_points'     => count($facts),
        ];
    }

    private function buildIndexingEvidence(?array $snapshot, ?array $indexNow, array $identity): array
    {
        return [
            'in_sitemap'            => $snapshot !== null,
            'last_sitemap_snapshot' => $snapshot['snapshot_at'] ?? null,
            'indexnow_submitted'    => $indexNow !== null,
            'indexnow_last'         => $indexNow['submitted_at'] ?? null,
            'indexnow_status'       => $indexNow['delivery_status'] ?? null,
        ];
    }

    private function buildVisibilityEvidence(array $obs): array
    {
        if (empty($obs)) {
            return ['available' => false, 'reason' => 'no_visibility_data'];
        }

        $mentioned    = array_filter($obs, fn($o) => $o['coverage_state'] === 'mentioned');
        $citationObs  = array_filter($obs, fn($o) => !empty($o['citation_url']));

        return [
            'available'       => true,
            'run_count'       => count($obs),
            'mention_count'   => count($mentioned),
            'mention_rate'    => round(count($mentioned) / count($obs), 4),
            'citation_count'  => count($citationObs),
        ];
    }

    private function buildAttributionEvidence(array $conversions): array
    {
        return [
            'conversion_count'   => count($conversions),
            'first_touch_count'  => count(array_filter($conversions, fn($c) => $c['touch_type'] === 'first')),
            'last_touch_count'   => count(array_filter($conversions, fn($c) => $c['touch_type'] === 'last')),
        ];
    }

    private function buildFreshnessEvidence(array $searchFacts, array $contentFacts, string $asOf): array
    {
        $lastSearch  = !empty($searchFacts)  ? max(array_column($searchFacts,  'metric_date')) : null;
        $lastContent = !empty($contentFacts) ? max(array_column($contentFacts, 'metric_date')) : null;

        $searchAge  = $lastSearch  ? (new \DateTimeImmutable($asOf))->diff(new \DateTimeImmutable($lastSearch))->days  : null;
        $contentAge = $lastContent ? (new \DateTimeImmutable($asOf))->diff(new \DateTimeImmutable($lastContent))->days : null;

        return [
            'search_last_date'   => $lastSearch,
            'search_age_days'    => $searchAge,
            'content_last_date'  => $lastContent,
            'content_age_days'   => $contentAge,
            'search_is_stale'    => $searchAge === null || $searchAge > 2,
            'content_is_stale'   => $contentAge === null || $contentAge > 2,
        ];
    }

    private function buildCompletenessEvidence(array $searchFacts, array $contentFacts, array $visObs, array $conversions): array
    {
        $present = [];
        if (!empty($searchFacts))  $present[] = 'search';
        if (!empty($contentFacts)) $present[] = 'engagement';
        if (!empty($visObs))       $present[] = 'visibility';
        if (!empty($conversions))  $present[] = 'attribution';

        $all   = ['search', 'engagement', 'visibility', 'attribution'];
        $score = count($present) / count($all);

        return [
            'present_domains' => $present,
            'missing_domains' => array_values(array_diff($all, $present)),
            'completeness_score' => round($score, 2),
            'is_complete'     => $score >= 0.75,
        ];
    }
}
