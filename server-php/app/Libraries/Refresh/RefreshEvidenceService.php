<?php

declare(strict_types=1);

namespace App\Libraries\Refresh;

use App\Libraries\AuditLogger;
use App\Libraries\Intelligence\IntelligenceEvidenceService;
use App\Models\Refresh\RefreshEvidenceSnapshotModel;
use RuntimeException;

/**
 * Reads Phase 8 evidence packets via IntelligenceEvidenceService and writes
 * immutable snapshots. A snapshot may never be updated or deleted.
 */
class RefreshEvidenceService
{
    private const REQUIRED_DOMAINS = ['identity', 'search', 'engagement', 'indexing', 'freshness'];

    public function __construct(
        private IntelligenceEvidenceService $evidenceService,
        private RefreshEvidenceSnapshotModel $snapshotModel,
        private AuditLogger                  $auditLogger,
    ) {}

    /**
     * Returns an existing snapshot if one exists for this triple, or creates a
     * new immutable snapshot from a fresh evidence packet.
     */
    public function getOrCreateSnapshot(
        int    $tenantId,
        int    $contentIdentityId,
        int    $policyVersionId,
        string $evidenceDate,
        int    $windowDays = 28,
    ): array {
        $existing = $this->snapshotModel->getForContent($contentIdentityId, $evidenceDate);
        if ($existing && $existing['policy_version_id'] === $policyVersionId) {
            return $existing;
        }

        $packet = $this->evidenceService->getEvidencePacket($contentIdentityId, $evidenceDate, $windowDays);

        $missingDomains = [];
        foreach (self::REQUIRED_DOMAINS as $domain) {
            if (empty($packet[$domain])) {
                $missingDomains[] = $domain;
            }
        }
        $completeness = $this->computeCompleteness($packet);
        $freshnessState = $packet['freshness'] ?? [];

        $snapshotData = [
            'tenant_id'           => $tenantId,
            'content_identity_id' => $contentIdentityId,
            'policy_version_id'   => $policyVersionId,
            'evidence_date'       => $evidenceDate,
            'window_days'         => $windowDays,
            'evidence_packet'     => json_encode($packet),
            'completeness_score'  => $completeness,
            'missing_domains'     => json_encode($missingDomains),
            'freshness_state'     => json_encode($freshnessState),
        ];

        $id = $this->snapshotModel->insert($snapshotData);
        $saved = $this->snapshotModel->find($id);

        $this->auditLogger->log(
            userId:     null,
            action:     AuditLogger::REFRESH_EVIDENCE_SNAPSHOT,
            entityType: 'refresh_evidence_snapshot',
            entityId:   $id,
            extra:      [
                'content_identity_id' => $contentIdentityId,
                'evidence_date'       => $evidenceDate,
                'completeness'        => $completeness,
                'missing_domains'     => $missingDomains,
            ],
            actorType:    'system',
            actorService: 'reach:refresh',
        );

        return $saved;
    }

    /**
     * Retrieve the evidence packet from a stored snapshot.
     */
    public function getPacketFromSnapshot(array $snapshot): array
    {
        $packet = json_decode($snapshot['evidence_packet'], true);
        if (! is_array($packet)) {
            throw new RuntimeException("Snapshot {$snapshot['id']} has malformed evidence_packet");
        }
        return $packet;
    }

    private function computeCompleteness(array $packet): float
    {
        $domains = ['identity', 'search', 'engagement', 'indexing', 'visibility', 'attribution', 'freshness', 'completeness'];
        $present = 0;
        foreach ($domains as $d) {
            if (! empty($packet[$d])) {
                $present++;
            }
        }
        return round($present / count($domains), 3);
    }
}
