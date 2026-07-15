<?php

declare(strict_types=1);

namespace App\Libraries\Refresh;

use App\Enums\RefreshRecommendationStatus;
use App\Libraries\AuditLogger;
use App\Models\Refresh\RefreshEvidenceSnapshotModel;
use App\Models\Refresh\RefreshPolicyVersionModel;
use App\Models\Refresh\RefreshRecommendationModel;
use App\Models\Refresh\RefreshScoreComponentModel;
use RuntimeException;

/**
 * Explainable refresh recommendation engine.
 *
 * Scoring is deterministic and transparent — every factor is stored as an
 * individual score component. No opaque composite score.
 *
 * Key behaviours:
 * - Deduplication: only one active recommendation per content identity per policy version
 * - Cooldown enforcement: respects policy_version.cooldown_days
 * - Supersession: new recommendation supersedes the previous active one
 * - Confidence is derived from evidence completeness
 */
class RefreshRecommendationService
{
    private const SCORING_VERSION = '1.0';

    private const FACTOR_WEIGHTS = [
        'declining_search_impressions'  => 0.15,
        'declining_clicks'              => 0.12,
        'declining_ctr'                 => 0.10,
        'worsening_position'            => 0.15,
        'declining_engagement'          => 0.10,
        'conversion_deterioration'      => 0.08,
        'outdated_product_information'  => 0.08,
        'stale_source_material'         => 0.05,
        'broken_or_withdrawn_citation'  => 0.07,
        'ai_visibility_gap'             => 0.05,
        'competitor_visibility_gap'     => 0.03,
        'missing_cta'                   => 0.02,
    ];

    public function __construct(
        private RefreshRecommendationModel   $recommendationModel,
        private RefreshScoreComponentModel   $scoreModel,
        private RefreshEvidenceSnapshotModel $snapshotModel,
        private RefreshPolicyVersionModel    $policyVersionModel,
        private AuditLogger                  $auditLogger,
    ) {}

    /**
     * Evaluate a snapshot against its policy version and produce a recommendation.
     * Respects cooldown, deduplicates, supersedes stale recommendations.
     */
    public function evaluate(
        int  $tenantId,
        int  $snapshotId,
    ): ?array {
        $snapshot = $this->snapshotModel->find($snapshotId);
        if (! $snapshot) {
            throw new RuntimeException("Evidence snapshot {$snapshotId} not found");
        }

        $policyVersion = $this->policyVersionModel->find($snapshot['policy_version_id']);
        if (! $policyVersion) {
            throw new RuntimeException("Policy version not found for snapshot {$snapshotId}");
        }

        // Cooldown check
        if ($this->isInCooldown($snapshot['content_identity_id'], $policyVersion)) {
            return null;
        }

        // Deduplication: if active recommendation exists, supersede it
        $this->supersedePrevious($snapshot['content_identity_id'], $policyVersion['id'], $tenantId);

        $packet = json_decode($snapshot['evidence_packet'], true) ?? [];
        $factors = $this->computeFactors($packet);
        $totalScore = array_sum(array_column($factors, 'contribution'));

        // Only generate a recommendation if the score exceeds the minimum threshold
        if ($totalScore < 0.1) {
            return null;
        }

        $riskClassification = $this->classifyRisk($totalScore, $packet);
        $confidence = round((float) $snapshot['completeness_score'], 3);

        $cooldownDays = (int) ($policyVersion['cooldown_days'] ?? 14);
        $cooldownUntil = date('Y-m-d H:i:s', strtotime("+{$cooldownDays} days"));

        $recId = $this->recommendationModel->insert([
            'uuid'                => null,
            'tenant_id'           => $tenantId,
            'content_identity_id' => $snapshot['content_identity_id'],
            'policy_version_id'   => $policyVersion['id'],
            'evidence_snapshot_id'=> $snapshotId,
            'status'              => RefreshRecommendationStatus::Recommended->value,
            'risk_classification' => $riskClassification,
            'confidence'          => $confidence,
            'effort_estimate'     => $this->estimateEffort($totalScore),
            'cooldown_until'      => $cooldownUntil,
        ]);

        foreach ($factors as $factor => $data) {
            $this->scoreModel->insert([
                'recommendation_id' => $recId,
                'factor'            => $factor,
                'raw_value'         => $data['raw_value'],
                'weight'            => $data['weight'],
                'contribution'      => $data['contribution'],
                'evidence_source'   => $data['evidence_source'] ?? null,
                'evidence_period'   => $data['evidence_period'] ?? null,
                'scoring_version'   => self::SCORING_VERSION,
            ]);
        }

        $recommendation = $this->recommendationModel->find($recId);

        $this->auditLogger->log(
            userId:     null,
            action:     AuditLogger::REFRESH_RECOMMENDED,
            entityType: 'refresh_recommendation',
            entityId:   $recId,
            extra:      [
                'content_identity_id' => $snapshot['content_identity_id'],
                'total_score'         => $totalScore,
                'risk'                => $riskClassification,
                'confidence'          => $confidence,
            ],
            actorType:    'system',
            actorService: 'reach:refresh',
        );

        return $recommendation;
    }

    public function triage(int $recommendationId, string $notes, int $actorId): array
    {
        $rec = $this->requireRecommendation($recommendationId);
        $this->recommendationModel->update($recommendationId, [
            'status'       => RefreshRecommendationStatus::Triaged->value,
            'triage_notes' => $notes,
        ]);

        $this->auditLogger->log(
            userId:     $actorId,
            action:     AuditLogger::REFRESH_TRIAGED,
            entityType: 'refresh_recommendation',
            entityId:   $recommendationId,
            extra:      ['notes' => $notes],
        );

        return $this->recommendationModel->find($recommendationId);
    }

    public function accept(int $recommendationId, ?int $assignedTo, int $actorId): array
    {
        $this->requireRecommendation($recommendationId);
        $this->recommendationModel->update($recommendationId, [
            'status'      => RefreshRecommendationStatus::Accepted->value,
            'assigned_to' => $assignedTo,
        ]);

        $this->auditLogger->log(
            userId:     $actorId,
            action:     AuditLogger::REFRESH_ACCEPTED,
            entityType: 'refresh_recommendation',
            entityId:   $recommendationId,
        );

        return $this->recommendationModel->find($recommendationId);
    }

    public function reject(int $recommendationId, string $reason, int $actorId): array
    {
        $this->requireRecommendation($recommendationId);
        $this->recommendationModel->update($recommendationId, [
            'status'       => RefreshRecommendationStatus::Rejected->value,
            'triage_notes' => $reason,
        ]);

        $this->auditLogger->log(
            userId:     $actorId,
            action:     AuditLogger::REFRESH_REJECTED,
            entityType: 'refresh_recommendation',
            entityId:   $recommendationId,
            extra:      ['reason' => $reason],
        );

        return $this->recommendationModel->find($recommendationId);
    }

    public function defer(int $recommendationId, string $reason, ?string $deferUntil, int $actorId): array
    {
        $this->requireRecommendation($recommendationId);
        $this->recommendationModel->update($recommendationId, [
            'status'        => RefreshRecommendationStatus::Deferred->value,
            'triage_notes'  => $reason,
            'cooldown_until'=> $deferUntil,
        ]);

        $this->auditLogger->log(
            userId:     $actorId,
            action:     AuditLogger::REFRESH_DEFERRED,
            entityType: 'refresh_recommendation',
            entityId:   $recommendationId,
        );

        return $this->recommendationModel->find($recommendationId);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function requireRecommendation(int $id): array
    {
        $rec = $this->recommendationModel->find($id);
        if (! $rec) throw new RuntimeException("Recommendation {$id} not found");
        return $rec;
    }

    private function isInCooldown(int $contentIdentityId, array $policyVersion): bool
    {
        $active = $this->recommendationModel->getActiveForContent($contentIdentityId);
        foreach ($active as $rec) {
            if ($rec['policy_version_id'] === $policyVersion['id']
                && $rec['cooldown_until'] !== null
                && strtotime($rec['cooldown_until']) > time()) {
                return true;
            }
        }
        return false;
    }

    private function supersedePrevious(int $contentIdentityId, int $policyVersionId, int $tenantId): void
    {
        $active = $this->recommendationModel
            ->where('content_identity_id', $contentIdentityId)
            ->where('policy_version_id', $policyVersionId)
            ->whereIn('status', [
                RefreshRecommendationStatus::Recommended->value,
                RefreshRecommendationStatus::Triaged->value,
            ])
            ->findAll();

        foreach ($active as $rec) {
            $this->recommendationModel->update($rec['id'], [
                'status' => RefreshRecommendationStatus::Superseded->value,
            ]);
        }
    }

    private function computeFactors(array $packet): array
    {
        $factors = [];

        $search = $packet['search'] ?? [];
        $engagement = $packet['engagement'] ?? [];
        $visibility = $packet['visibility'] ?? [];
        $freshness = $packet['freshness'] ?? [];

        foreach (self::FACTOR_WEIGHTS as $factor => $weight) {
            $rawValue = $this->extractFactorSignal($factor, $search, $engagement, $visibility, $freshness);
            $contribution = round($rawValue * $weight, 6);

            $factors[$factor] = [
                'raw_value'      => round($rawValue, 4),
                'weight'         => $weight,
                'contribution'   => $contribution,
                'evidence_source'=> $this->factorSource($factor),
                'evidence_period'=> '28d',
            ];
        }

        return $factors;
    }

    private function extractFactorSignal(string $factor, array $search, array $engagement, array $visibility, array $freshness): float
    {
        return match ($factor) {
            'declining_search_impressions' => $this->positiveDecline($search['impressions_trend'] ?? 0),
            'declining_clicks'             => $this->positiveDecline($search['clicks_trend'] ?? 0),
            'declining_ctr'                => $this->positiveDecline($search['ctr_trend'] ?? 0),
            'worsening_position'           => $this->positiveDecline(-1 * ($search['position_trend'] ?? 0)),
            'declining_engagement'         => $this->positiveDecline($engagement['engagement_trend'] ?? 0),
            'conversion_deterioration'     => $this->positiveDecline($engagement['conversion_trend'] ?? 0),
            'outdated_product_information' => (float) ($freshness['outdated_product'] ?? 0),
            'stale_source_material'        => (float) ($freshness['stale_sources'] ?? 0),
            'broken_or_withdrawn_citation' => (float) ($freshness['broken_citations'] ?? 0),
            'ai_visibility_gap'            => (float) ($visibility['gap_score'] ?? 0),
            'competitor_visibility_gap'    => (float) ($visibility['competitor_gap'] ?? 0),
            'missing_cta'                  => (float) ($freshness['missing_cta'] ?? 0),
            default                        => 0.0,
        };
    }

    private function positiveDecline(float $trend): float
    {
        return $trend < 0 ? min(abs($trend), 1.0) : 0.0;
    }

    private function factorSource(string $factor): string
    {
        return match (true) {
            str_contains($factor, 'search')   => 'google_search_console',
            str_contains($factor, 'clicks')   => 'google_search_console',
            str_contains($factor, 'ctr')      => 'google_search_console',
            str_contains($factor, 'position') => 'google_search_console',
            str_contains($factor, 'engagement') => 'google_analytics',
            str_contains($factor, 'conversion') => 'google_analytics',
            str_contains($factor, 'visibility') => 'ai_visibility_observations',
            default                            => 'evidence_packet',
        };
    }

    private function classifyRisk(float $score, array $packet): string
    {
        if ($score > 0.7) return 'critical';
        if ($score > 0.5) return 'high';
        if ($score > 0.25) return 'medium';
        return 'low';
    }

    private function estimateEffort(float $score): string
    {
        if ($score > 0.5) return 'high';
        if ($score > 0.25) return 'medium';
        return 'low';
    }
}
