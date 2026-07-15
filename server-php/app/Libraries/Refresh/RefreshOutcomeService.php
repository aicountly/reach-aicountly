<?php

declare(strict_types=1);

namespace App\Libraries\Refresh;

use App\Libraries\AuditLogger;
use App\Libraries\Intelligence\IntelligenceEvidenceService;
use App\Models\Refresh\RefreshOutcomeMetricModel;
use App\Models\Refresh\RefreshOutcomeWindowModel;
use App\Models\Refresh\RefreshPublicationLinkModel;
use RuntimeException;

/**
 * Measures post-refresh observed changes relative to a pre-refresh baseline.
 *
 * Language governance: All metric descriptions use "Observed post-refresh change"
 * language. Never "caused by", "due to", or causal attributions.
 * Confidence levels: high (>= 21 data points), medium (>= 14), low (>= 7), insufficient_data (< 7).
 */
class RefreshOutcomeService
{
    private const BASELINE_DAYS = 28;
    private const POST_DAYS     = 28;

    public function __construct(
        private RefreshPublicationLinkModel $linkModel,
        private RefreshOutcomeWindowModel   $windowModel,
        private RefreshOutcomeMetricModel   $metricModel,
        private IntelligenceEvidenceService $evidenceService,
        private AuditLogger                 $auditLogger,
    ) {}

    public function openOutcomeWindow(int $publicationLinkId, int $contentIdentityId, string $publishedAt): array
    {
        $baselineFrom = date('Y-m-d', strtotime($publishedAt . ' -' . (self::BASELINE_DAYS * 2) . ' days'));
        $baselineTo   = date('Y-m-d', strtotime($publishedAt . ' -1 day'));
        $postFrom     = date('Y-m-d', strtotime($publishedAt));
        $postTo       = date('Y-m-d', strtotime($publishedAt . ' +' . self::POST_DAYS . ' days'));

        $windowId = $this->windowModel->insert([
            'publication_link_id' => $publicationLinkId,
            'content_identity_id' => $contentIdentityId,
            'baseline_from'       => $baselineFrom,
            'baseline_to'         => $baselineTo,
            'post_from'           => $postFrom,
            'post_to'             => $postTo,
            'measurement_status'  => 'pending',
        ]);

        $this->auditLogger->log(
            userId:     null,
            action:     AuditLogger::REFRESH_OUTCOME_WINDOW_OPENED,
            entityType: 'refresh_outcome_window',
            entityId:   $windowId,
            extra:      [
                'content_identity_id' => $contentIdentityId,
                'baseline'            => "{$baselineFrom}/{$baselineTo}",
                'post'                => "{$postFrom}/{$postTo}",
            ],
            actorType:    'system',
            actorService: 'reach:refresh',
        );

        return $this->windowModel->find($windowId);
    }

    public function measureOutcome(int $windowId): array
    {
        $window = $this->windowModel->find($windowId);
        if (! $window) throw new RuntimeException("Outcome window {$windowId} not found");

        $today = date('Y-m-d');
        if ($today < $window['post_to']) {
            return ['status' => 'post_period_not_complete', 'post_to' => $window['post_to']];
        }

        $baselinePacket = $this->evidenceService->getEvidencePacket(
            (int) $window['content_identity_id'],
            $window['baseline_to'],
            self::BASELINE_DAYS,
        );
        $postPacket = $this->evidenceService->getEvidencePacket(
            (int) $window['content_identity_id'],
            $window['post_to'],
            self::POST_DAYS,
        );

        $metrics = $this->comparePackets($baselinePacket, $postPacket);
        $measured = 0;

        foreach ($metrics as $metric) {
            $this->metricModel->insert(array_merge($metric, ['outcome_window_id' => $windowId]));
            $measured++;
        }

        $status = $measured > 0 ? 'complete' : 'insufficient_data';
        $this->windowModel->update($windowId, ['measurement_status' => $status]);

        $this->auditLogger->log(
            userId:     null,
            action:     $measured > 0 ? AuditLogger::REFRESH_OUTCOME_MEASURED : AuditLogger::REFRESH_OUTCOME_INSUFFICIENT,
            entityType: 'refresh_outcome_window',
            entityId:   $windowId,
            extra:      ['metrics_recorded' => $measured],
            actorType:    'system',
            actorService: 'reach:refresh',
        );

        return ['window_id' => $windowId, 'metrics_recorded' => $measured, 'status' => $status];
    }

    private function comparePackets(array $baseline, array $post): array
    {
        $metrics = [];
        $now = date('Y-m-d H:i:s');

        // Search metrics
        foreach (['impressions', 'clicks', 'ctr', 'avg_position'] as $field) {
            $bVal = $baseline['search'][$field] ?? null;
            $pVal = $post['search'][$field] ?? null;
            if ($bVal !== null && $pVal !== null && $bVal > 0) {
                $changePct = round((($pVal - $bVal) / $bVal) * 100, 4);
                $metrics[] = [
                    'metric_domain'        => 'search',
                    'metric_name'          => "observed_change_{$field}",
                    'baseline_value'       => $bVal,
                    'post_value'           => $pVal,
                    'observed_change_pct'  => $changePct,
                    'evidence_source'      => 'google_search_console',
                    'confidence'           => $this->confidence((int) ($baseline['search']['data_points'] ?? 0), (int) ($post['search']['data_points'] ?? 0)),
                    'data_points_baseline' => (int) ($baseline['search']['data_points'] ?? 0),
                    'data_points_post'     => (int) ($post['search']['data_points'] ?? 0),
                    'measured_at'          => $now,
                ];
            }
        }

        // Engagement metrics
        foreach (['sessions', 'pageviews', 'engagement_rate', 'avg_session_duration'] as $field) {
            $bVal = $baseline['engagement'][$field] ?? null;
            $pVal = $post['engagement'][$field] ?? null;
            if ($bVal !== null && $pVal !== null && $bVal > 0) {
                $changePct = round((($pVal - $bVal) / $bVal) * 100, 4);
                $metrics[] = [
                    'metric_domain'       => 'engagement',
                    'metric_name'         => "observed_change_{$field}",
                    'baseline_value'      => $bVal,
                    'post_value'          => $pVal,
                    'observed_change_pct' => $changePct,
                    'evidence_source'     => 'google_analytics',
                    'confidence'          => $this->confidence((int) ($baseline['engagement']['data_points'] ?? 0), (int) ($post['engagement']['data_points'] ?? 0)),
                    'data_points_baseline'=> (int) ($baseline['engagement']['data_points'] ?? 0),
                    'data_points_post'    => (int) ($post['engagement']['data_points'] ?? 0),
                    'measured_at'         => $now,
                ];
            }
        }

        return $metrics;
    }

    private function confidence(int $baselinePoints, int $postPoints): string
    {
        $min = min($baselinePoints, $postPoints);
        if ($min >= 21) return 'high';
        if ($min >= 14) return 'medium';
        if ($min >= 7)  return 'low';
        return 'insufficient_data';
    }
}
