<?php

namespace App\Libraries\Community;

use App\Enums\CommunityAnswerStatus;
use App\Enums\CommunityRiskClassification;
use App\Libraries\AuditLogger;
use App\Models\CommunityAnswerApprovalModel;

/**
 * Approval workflow for official answers.
 *
 * Enforces:
 *   - Checksum locking: approval is bound to an immutable version checksum
 *   - Separation of duties: last editor cannot approve for high-risk answers
 *   - Human approval required: no AI-generated answer may publish without this
 *   - Version integrity: approval checksum must match stored version before publication
 */
class OfficialAnswerApprovalService
{
    public function __construct(
        private readonly OfficialAnswerRepository       $answerRepo    = new OfficialAnswerRepository(),
        private readonly OfficialAnswerVersionService   $versionService = new OfficialAnswerVersionService(),
        private readonly CommunityAnswerApprovalModel   $approvalModel = new CommunityAnswerApprovalModel()
    ) {}

    /**
     * Approve an official answer version.
     *
     * @param int    $answerId         The answer to approve.
     * @param int    $versionNumber    The version being approved.
     * @param int    $approverId       The user performing approval.
     * @param string $approvalType     'standard' | 'professional_review' | 'compliance_review'
     * @param string $reason           Mandatory reason for audit.
     */
    public function approve(
        int    $answerId,
        int    $versionNumber,
        int    $approverId,
        string $approvalType = 'standard',
        string $reason       = ''
    ): array {
        $answer  = $this->answerRepo->findById($answerId);
        if ($answer === null) {
            throw new \RuntimeException("Answer #{$answerId} not found");
        }

        $version = $this->answerRepo->getVersion($answerId, $versionNumber);
        if ($version === null) {
            throw new \RuntimeException("Version {$versionNumber} not found for answer #{$answerId}");
        }

        // Check version is not superseded
        if ($version['superseded_by'] !== null) {
            throw new \RuntimeException(
                "Version {$versionNumber} has been superseded by version {$version['superseded_by']}. Approve the latest version."
            );
        }

        // Verify checksum integrity
        if (!$this->versionService->verifyIntegrity($version)) {
            AuditLogger::record(AuditLogger::COMMUNITY_ANSWER_CHECKSUM_MISMATCH, [
                'answer_id' => $answerId, 'version_number' => $versionNumber,
            ], $approverId);
            throw new \RuntimeException("Version checksum integrity check failed. The answer content may have been tampered with.");
        }

        // Self-approval check for high/critical risk
        $risk = CommunityRiskClassification::from($answer['risk_classification'] ?? 'low');
        if ($risk->requiresProfessionalReview()) {
            $lastEditor = $this->getLastEditorId($answerId, $versionNumber);
            if ($lastEditor !== null && $lastEditor === $approverId) {
                throw new \RuntimeException(
                    "Self-approval is not permitted for high-risk answers. The author of this version cannot approve it."
                );
            }
        }

        // Check answer is in an approvable state
        $currentStatus = CommunityAnswerStatus::from($answer['status'] ?? 'intake');
        if (!in_array($currentStatus, [
            CommunityAnswerStatus::EditorialReview,
            CommunityAnswerStatus::ProfessionalReview,
        ], true)) {
            throw new \RuntimeException(
                "Answer must be in editorial_review or professional_review status to approve. Current: {$currentStatus->value}"
            );
        }

        // Store approval record
        $this->approvalModel->insert([
            'answer_id'             => $answerId,
            'answer_version_number' => $versionNumber,
            'version_checksum'      => $version['checksum'],
            'approved_by'           => $approverId,
            'approval_type'         => $approvalType,
            'outcome'               => 'approved',
            'reason'                => $reason,
            'created_at'            => date('Y-m-d H:i:s'),
        ]);

        // Lock approval on the answer record
        $this->answerRepo->markApproved($answerId, $versionNumber, $version['checksum']);

        // Update version with approver and timestamp
        db_connect()->table('reach_community_answer_versions')
            ->where('answer_id', $answerId)
            ->where('version_number', $versionNumber)
            ->update([
                'approver_id'        => $approverId,
                'approval_timestamp' => date('Y-m-d H:i:s'),
            ]);

        // Update answer with human_reviewed flag
        $this->answerRepo->save([
            'id'             => $answerId,
            'human_reviewed' => true,
            'status'         => CommunityAnswerStatus::Approved->value,
        ]);

        AuditLogger::record(AuditLogger::COMMUNITY_ANSWER_APPROVED, [
            'answer_id'      => $answerId,
            'version_number' => $versionNumber,
            'approval_type'  => $approvalType,
            'checksum'       => substr($version['checksum'], 0, 8) . '...',
        ], $approverId);

        return [
            'approved'       => true,
            'answer_id'      => $answerId,
            'version_number' => $versionNumber,
            'checksum'       => $version['checksum'],
        ];
    }

    /**
     * Reject an answer version.
     */
    public function reject(int $answerId, int $versionNumber, int $reviewerId, string $reason): void
    {
        if (empty(trim($reason))) {
            throw new \InvalidArgumentException('Rejection reason is required.');
        }

        $this->approvalModel->insert([
            'answer_id'             => $answerId,
            'answer_version_number' => $versionNumber,
            'version_checksum'      => '',
            'approved_by'           => $reviewerId,
            'approval_type'         => 'standard',
            'outcome'               => 'rejected',
            'reason'                => $reason,
            'created_at'            => date('Y-m-d H:i:s'),
        ]);

        $this->answerRepo->transitionStatus(
            $answerId,
            CommunityAnswerStatus::from($this->answerRepo->findById($answerId)['status'] ?? 'editorial_review'),
            CommunityAnswerStatus::ChangesRequested
        );

        AuditLogger::record(AuditLogger::COMMUNITY_ANSWER_REJECTED, [
            'answer_id' => $answerId, 'version_number' => $versionNumber,
        ], $reviewerId);
    }

    /**
     * Request changes on an answer in review.
     */
    public function requestChanges(int $answerId, int $reviewerId, string $reason): void
    {
        if (empty(trim($reason))) {
            throw new \InvalidArgumentException('Reason for changes is required.');
        }

        $answer = $this->answerRepo->findById($answerId);
        $fromStatus = CommunityAnswerStatus::from($answer['status'] ?? 'editorial_review');

        $this->answerRepo->transitionStatus($answerId, $fromStatus, CommunityAnswerStatus::ChangesRequested);

        AuditLogger::record(AuditLogger::COMMUNITY_ANSWER_CHANGES_REQUESTED, [
            'answer_id' => $answerId,
            'reason'    => substr($reason, 0, 200),
        ], $reviewerId);
    }

    /**
     * Verify that the approval checksum still matches before publication.
     * This is a last-line gate in the publishing service.
     */
    public function verifyApprovalForPublication(array $answer): bool
    {
        if (empty($answer['approved_version']) || empty($answer['approved_version_checksum'])) {
            return false;
        }

        $valid = $this->answerRepo->verifyApprovalChecksum($answer);
        if (!$valid) {
            AuditLogger::record(AuditLogger::COMMUNITY_PUBLISHING_CHECKSUM_MISMATCH, [
                'answer_id'               => $answer['id'],
                'approved_version'        => $answer['approved_version'],
                'stored_checksum_prefix'  => substr($answer['approved_version_checksum'], 0, 8),
            ]);
        }
        return $valid;
    }

    private function getLastEditorId(int $answerId, int $versionNumber): ?int
    {
        // The creator of this version is the last editor
        $version = db_connect()->table('reach_community_answer_versions')
            ->select('reviewer_id')
            ->where('answer_id', $answerId)
            ->where('version_number', $versionNumber)
            ->get()
            ->getRowArray();

        return $version ? ((int) $version['reviewer_id'] ?: null) : null;
    }
}
