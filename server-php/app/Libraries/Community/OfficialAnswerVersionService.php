<?php

namespace App\Libraries\Community;

use App\Libraries\AuditLogger;

/**
 * Creates and manages immutable official answer versions.
 *
 * Every content change produces a new version. Versions are never mutated.
 * A SHA-256 checksum of the content enforces immutability at approval time.
 */
class OfficialAnswerVersionService
{
    public function __construct(
        private readonly OfficialAnswerRepository $repo = new OfficialAnswerRepository()
    ) {}

    /**
     * Create a new version for an official answer.
     *
     * @param int    $answerId         The parent answer ID.
     * @param string $content          Full HTML/structured content.
     * @param string $excerpt          Plain-text excerpt.
     * @param array  $sources          Source reference array.
     * @param string $creationReason   'initial' | 'edit' | 'correction' | 'translation'
     * @param array  $generationRefs   Optional generation run/request/artifact IDs.
     * @param array  $validationResults Optional validation result data.
     * @param array  $riskFindings      Optional risk finding data.
     * @param int|null $actorId        Actor creating the version.
     */
    public function createVersion(
        int    $answerId,
        string $content,
        string $excerpt,
        array  $sources        = [],
        string $creationReason = 'initial',
        array  $generationRefs = [],
        array  $validationResults = [],
        array  $riskFindings   = [],
        ?int   $actorId        = null
    ): array {
        $checksum      = $this->computeChecksum($content);
        $versionNumber = $this->repo->getLatestVersion($answerId) !== null
            ? $this->repo->getLatestVersion($answerId)['version_number'] + 1
            : 1;

        // Supersede previous version
        if ($versionNumber > 1) {
            $this->markVersionSuperseded($answerId, $versionNumber - 1, $versionNumber);

            // Invalidate approval since content changed
            $this->repo->invalidateApproval($answerId);
        }

        $versionData = [
            'answer_id'              => $answerId,
            'version_number'         => $versionNumber,
            'content'                => $content,
            'excerpt'                => $excerpt,
            'sources'                => $sources,
            'grounding_snapshot_id'  => $generationRefs['grounding_snapshot_id'] ?? null,
            'generation_request_id'  => $generationRefs['generation_request_id'] ?? null,
            'generation_run_id'      => $generationRefs['generation_run_id'] ?? null,
            'generation_artifact_id' => $generationRefs['generation_artifact_id'] ?? null,
            'prompt_version'         => $generationRefs['prompt_version'] ?? null,
            'model_route'            => $generationRefs['model_route'] ?? null,
            'validation_results'     => $validationResults,
            'risk_findings'          => $riskFindings,
            'moderation_decision'    => 'pending',
            'checksum'               => $checksum,
            'creation_reason'        => $creationReason,
            'superseded_by'          => null,
            'created_at'             => date('Y-m-d H:i:s'),
        ];

        $versionId = $this->repo->saveVersion($versionData);

        AuditLogger::record(AuditLogger::COMMUNITY_ANSWER_VERSION_CREATED, [
            'answer_id'      => $answerId,
            'version_number' => $versionNumber,
            'checksum'       => substr($checksum, 0, 8) . '...',
            'creation_reason' => $creationReason,
        ], $actorId);

        return array_merge($versionData, ['id' => $versionId]);
    }

    /**
     * Compute a stable SHA-256 checksum of answer content.
     * The checksum is over the normalised UTF-8 content string only.
     */
    public function computeChecksum(string $content): string
    {
        return hash('sha256', mb_convert_encoding($content, 'UTF-8', 'UTF-8'));
    }

    /**
     * Verify that a stored version's checksum still matches its content.
     */
    public function verifyIntegrity(array $version): bool
    {
        return hash_equals($version['checksum'], $this->computeChecksum($version['content']));
    }

    private function markVersionSuperseded(int $answerId, int $oldVersionNumber, int $newVersionNumber): void
    {
        db_connect()->table('reach_community_answer_versions')
            ->where('answer_id', $answerId)
            ->where('version_number', $oldVersionNumber)
            ->update(['superseded_by' => $newVersionNumber]);
    }
}
