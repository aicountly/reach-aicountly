<?php

namespace App\Libraries\Community;

use App\Libraries\AuditLogger;
use App\Models\CommunityModerationFindingModel;

/**
 * review_objection_desk operational role.
 *
 * Flags unsupported claims and conflicts, and recommends risk escalation or
 * revision. This is deliberately a *recommend*, not a *decide*, role: the
 * underlying human-gated actions it points at
 * (OfficialAnswerLifecycleService::requestChanges / setRiskTierWithOverride)
 * require a real reach_users actor ID because they carry legal/compliance
 * accountability, and this audit will not fabricate a human attribution for
 * a bot-initiated action. A finding raised here surfaces in the same review
 * queue a human moderator already watches; the human decides whether to act.
 */
class CommunityReviewObjectionService
{
    public function __construct(
        private readonly CommunityModerationFindingModel $findingModel = new CommunityModerationFindingModel(),
    ) {}

    /**
     * @param array{answer_version_id:int, finding_type?:string, detail:string} $context
     */
    public function flagObjection(array $identity, array $context, ?int $actorId): array
    {
        $versionId = (int) ($context['answer_version_id'] ?? 0);
        $detail    = trim((string) ($context['detail'] ?? ''));
        if ($versionId <= 0 || $detail === '') {
            throw new \InvalidArgumentException('CommunityReviewObjectionService::flagObjection requires answer_version_id and detail.');
        }

        return $this->recordFinding($identity, $actorId, [
            'answer_version_id' => $versionId,
            'finding_type'      => (string) ($context['finding_type'] ?? 'unsupported_claims'),
            'severity'          => 'warning',
            'detail'            => $detail,
        ]);
    }

    /**
     * @param array{answer_version_id:int, reason:string} $context
     */
    public function requestRevision(array $identity, array $context, ?int $actorId): array
    {
        $versionId = (int) ($context['answer_version_id'] ?? 0);
        $reason    = trim((string) ($context['reason'] ?? ''));
        if ($versionId <= 0 || $reason === '') {
            throw new \InvalidArgumentException('CommunityReviewObjectionService::requestRevision requires answer_version_id and reason.');
        }

        return $this->recordFinding($identity, $actorId, [
            'answer_version_id' => $versionId,
            'finding_type'      => 'unsupported_claims',
            'severity'          => 'warning',
            'detail'            => 'Revision recommended: ' . $reason,
        ]);
    }

    /**
     * @param array{answer_version_id:int, question_id?:int, current_risk_tier:int, recommended_risk_tier:int, reason:string} $context
     */
    public function escalateRisk(array $identity, array $context, ?int $actorId): array
    {
        $recommended = (int) ($context['recommended_risk_tier'] ?? -1);
        $reason      = trim((string) ($context['reason'] ?? ''));
        if ($recommended < 0 || $recommended > 4 || $reason === '') {
            throw new \InvalidArgumentException('CommunityReviewObjectionService::escalateRisk requires a valid recommended_risk_tier (0-4) and reason.');
        }

        $severity = $recommended >= 3 ? 'critical' : ($recommended >= 2 ? 'error' : 'warning');

        return $this->recordFinding($identity, $actorId, [
            'answer_version_id' => $context['answer_version_id'] ?? null,
            'question_id'       => $context['question_id'] ?? null,
            'finding_type'      => 'legal_risk',
            'severity'          => $severity,
            'detail'            => "Risk escalation recommended to tier {$recommended}: {$reason}",
        ]);
    }

    private function recordFinding(array $identity, ?int $actorId, array $finding): array
    {
        $id = $this->findingModel->insert([
            'answer_version_id' => $finding['answer_version_id'] ?? null,
            'question_id'       => $finding['question_id'] ?? null,
            'finding_type'      => $finding['finding_type'],
            'severity'          => $finding['severity'],
            'details'           => json_encode([
                'detail'    => $finding['detail'],
                'raised_by' => $identity['slug'],
                'role'      => 'review_objection_desk',
            ]),
            'status'            => 'open',
            'created_at'        => date('Y-m-d H:i:s'),
        ], true);

        AuditLogger::record(AuditLogger::COMMUNITY_MODERATION_FINDING_ESCALATED, [
            'finding_id'      => $id,
            'reviewer_identity' => $identity['slug'],
            'finding_type'    => $finding['finding_type'],
            'severity'        => $finding['severity'],
        ], $actorId);

        return ['finding_id' => $id];
    }
}
