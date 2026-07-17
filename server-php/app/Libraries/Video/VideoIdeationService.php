<?php

declare(strict_types=1);

namespace App\Libraries\Video;

use App\Enums\VideoIdeaStatus;
use App\Enums\VideoProjectStatus;
use App\Libraries\ActorRegistry;
use App\Libraries\AuditLogger;

class VideoIdeationService
{
    public function __construct(
        private readonly VideoIdeaRepository    $ideaRepo,
        private readonly VideoProjectRepository $projectRepo,
        private readonly VideoLifecycleValidator $validator,
        private readonly AuditLogger            $audit,
    ) {}

    public function createIdea(array $data, int $userId): array
    {
        $actorId = ActorRegistry::idForUser($userId);
        $status  = VideoIdeaStatus::tryFrom((string) ($data['status'] ?? '')) ?? VideoIdeaStatus::Draft;
        if (! in_array($status, [VideoIdeaStatus::Draft, VideoIdeaStatus::Ready], true)) {
            $status = VideoIdeaStatus::Draft;
        }

        $id = $this->ideaRepo->create([
            'tenant_id'  => (int) ($data['tenant_id'] ?? 0),
            'title'      => $data['title'],
            'summary'    => $data['summary'] ?? null,
            'source_type' => $data['source_type'] ?? null,
            'source_ref_id' => $data['source_ref_id'] ?? null,
            'status'     => $status->value,
            'created_by' => $actorId,
        ]);

        $idea = $this->ideaRepo->findById($id);
        $this->audit->log(
            $userId,
            AuditLogger::VIDEO_IDEA_CREATED,
            'video_idea',
            $id,
            null,
            $idea,
        );
        return $idea;
    }

    public function acceptIdea(array $idea, int $userId): array
    {
        $this->validator->assertIdeaTransition($idea['status'], VideoIdeaStatus::Accepted->value);
        $this->ideaRepo->update((int) $idea['id'], ['status' => VideoIdeaStatus::Accepted->value]);
        $updated = $this->ideaRepo->findById((int) $idea['id']);
        $this->audit->log($userId, AuditLogger::VIDEO_IDEA_ACCEPTED, 'video_idea', (int) $idea['id']);
        return $updated;
    }

    public function rejectIdea(array $idea, string $reason, int $userId): array
    {
        $this->validator->assertIdeaTransition($idea['status'], VideoIdeaStatus::Rejected->value);
        $this->ideaRepo->update((int) $idea['id'], ['status' => VideoIdeaStatus::Rejected->value]);
        $updated = $this->ideaRepo->findById((int) $idea['id']);
        $this->audit->log($userId, AuditLogger::VIDEO_IDEA_REJECTED, 'video_idea', (int) $idea['id'], null, ['reason' => $reason]);
        return $updated;
    }

    public function markReady(array $idea, int $userId): array
    {
        $this->validator->assertIdeaTransition($idea['status'], VideoIdeaStatus::Ready->value);
        $this->ideaRepo->update((int) $idea['id'], ['status' => VideoIdeaStatus::Ready->value]);
        return $this->ideaRepo->findById((int) $idea['id']);
    }

    public function convertToProject(array $idea, int $userId): array
    {
        $this->validator->assertIdeaTransition($idea['status'], VideoIdeaStatus::Converted->value);

        $actorId = ActorRegistry::idForUser($userId);
        $projectId = $this->projectRepo->create([
            'tenant_id'  => (int) $idea['tenant_id'],
            'idea_id'    => (int) $idea['id'],
            'title'      => $idea['title'],
            'status'     => VideoProjectStatus::Draft->value,
            'created_by' => $actorId,
        ]);

        $this->ideaRepo->update((int) $idea['id'], ['status' => VideoIdeaStatus::Converted->value]);

        $project = $this->projectRepo->findById($projectId);
        $this->audit->log($userId, AuditLogger::VIDEO_IDEA_CONVERTED, 'video_idea', (int) $idea['id'], null, ['project_id' => $projectId]);
        $this->audit->log($userId, AuditLogger::VIDEO_PROJECT_CREATED, 'video_project', $projectId);
        return $project;
    }

    public function addSource(int $ideaId, array $source, int $userId): array
    {
        $sourceId = $this->ideaRepo->addSource($ideaId, $source);
        return $this->ideaRepo->listSources($ideaId);
    }

    public function flagDuplicate(int $ideaId, int $duplicateOfId, float $similarityScore, int $userId): void
    {
        $this->ideaRepo->update($ideaId, [
            'duplicate_of_id' => $duplicateOfId,
            'similarity_score' => $similarityScore,
        ]);
        $this->audit->log($userId, AuditLogger::VIDEO_IDEA_DUPLICATE_FLAGGED, 'video_idea', $ideaId, null, [
            'duplicate_of_id' => $duplicateOfId,
            'similarity_score' => $similarityScore,
        ]);
    }
}
