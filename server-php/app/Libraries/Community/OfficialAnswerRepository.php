<?php

namespace App\Libraries\Community;

use App\Enums\CommunityAnswerStatus;
use App\Models\CommunityAnswerVersionModel;
use App\Models\CommunityOfficialAnswerModel;
use RuntimeException;

/**
 * Repository for official answer aggregates.
 * Encapsulates multi-table queries and state-machine transition enforcement.
 */
class OfficialAnswerRepository
{
    public function __construct(
        private readonly CommunityOfficialAnswerModel $answerModel = new CommunityOfficialAnswerModel(),
        private readonly CommunityAnswerVersionModel  $versionModel = new CommunityAnswerVersionModel()
    ) {}

    public function findById(int $id): ?array
    {
        return $this->answerModel->getWithIdentityAndQuestion($id);
    }

    public function findByUuid(string $uuid): ?array
    {
        $answer = $this->answerModel->findByUuid($uuid);
        if ($answer === null) {
            return null;
        }
        return $this->answerModel->getWithIdentityAndQuestion((int) $answer['id']);
    }

    public function requireByUuid(string $uuid): array
    {
        $answer = $this->findByUuid($uuid);
        if ($answer === null) {
            throw new RuntimeException("Official answer not found: {$uuid}");
        }
        return $answer;
    }

    public function save(array $data): int
    {
        if (empty($data['id'])) {
            $this->answerModel->insert($data);
            return (int) $this->answerModel->getInsertID();
        }
        $this->answerModel->update($data['id'], $data);
        return (int) $data['id'];
    }

    public function transitionStatus(int $id, CommunityAnswerStatus $from, CommunityAnswerStatus $to): void
    {
        if (!$from->canTransitionTo($to)) {
            throw new RuntimeException(
                "Invalid answer status transition: {$from->value} → {$to->value}"
            );
        }

        $affected = $this->answerModel->db->table($this->answerModel->table)
            ->set('status', $to->value)
            ->set('updated_at', date('Y-m-d H:i:s'))
            ->where('id', $id)
            ->where('status', $from->value)
            ->update();

        if (!$affected) {
            throw new RuntimeException("Status transition failed (concurrent modification?) for answer #{$id}");
        }
    }

    public function saveVersion(array $versionData): int
    {
        $this->versionModel->insert($versionData);
        $versionId = (int) $this->versionModel->getInsertID();

        // Update current_version on parent
        $this->answerModel->update($versionData['answer_id'], [
            'current_version' => $versionData['version_number'],
        ]);

        return $versionId;
    }

    public function getLatestVersion(int $answerId): ?array
    {
        return $this->versionModel->getLatestVersion($answerId);
    }

    public function getVersion(int $answerId, int $versionNumber): ?array
    {
        return $this->versionModel->getVersion($answerId, $versionNumber);
    }

    public function listVersions(int $answerId): array
    {
        return $this->versionModel->listVersions($answerId);
    }

    public function getApprovedVersion(array $answer): ?array
    {
        if (empty($answer['approved_version'])) {
            return null;
        }
        return $this->versionModel->getVersion((int) $answer['id'], (int) $answer['approved_version']);
    }

    public function verifyApprovalChecksum(array $answer): bool
    {
        if (empty($answer['approved_version']) || empty($answer['approved_version_checksum'])) {
            return false;
        }
        $version = $this->versionModel->getVersion((int) $answer['id'], (int) $answer['approved_version']);
        if ($version === null) {
            return false;
        }
        return hash_equals($answer['approved_version_checksum'], $version['checksum']);
    }

    public function markApproved(int $answerId, int $versionNumber, string $checksum): void
    {
        $this->answerModel->update($answerId, [
            'approved_version'          => $versionNumber,
            'approved_version_checksum' => $checksum,
        ]);
    }

    public function invalidateApproval(int $answerId): void
    {
        $this->answerModel->update($answerId, [
            'approved_version'          => null,
            'approved_version_checksum' => null,
        ]);
    }

    public function listByStatus(string $status, int $limit = 100): array
    {
        return $this->answerModel->where('status', $status)->limit($limit)->findAll();
    }

    public function countByStatus(): array
    {
        return $this->answerModel->countByStatus();
    }
}
