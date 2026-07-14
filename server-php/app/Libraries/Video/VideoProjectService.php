<?php

declare(strict_types=1);

namespace App\Libraries\Video;

use App\Libraries\AuditLogger;

class VideoProjectService
{
    public function __construct(
        private readonly VideoProjectRepository  $projectRepo,
        private readonly VideoLifecycleValidator $validator,
        private readonly AuditLogger             $audit,
    ) {}

    public function createProject(array $data, int $userId): array
    {
        $id = $this->projectRepo->create([
            'tenant_id'  => (int) ($data['tenant_id'] ?? 0),
            'idea_id'    => $data['idea_id'] ?? null,
            'title'      => $data['title'],
            'status'     => 'draft',
            'created_by' => $userId,
        ]);
        $project = $this->projectRepo->findById($id);
        $this->audit->log($userId, AuditLogger::VIDEO_PROJECT_CREATED, 'video_project', $id);
        return $project;
    }

    public function updateProject(array $project, array $data, int $userId): array
    {
        $allowed = ['title'];
        $update  = array_intersect_key($data, array_flip($allowed));
        if (! empty($update)) {
            $this->projectRepo->update((int) $project['id'], $update);
            $this->audit->log($userId, AuditLogger::VIDEO_PROJECT_UPDATED, 'video_project', (int) $project['id']);
        }
        return $this->projectRepo->findById((int) $project['id']);
    }

    public function cancelProject(array $project, string $reason, int $userId): array
    {
        $this->validator->assertProjectTransition($project['status'], 'cancelled');
        $this->projectRepo->transitionStatus(
            (int) $project['id'],
            'cancelled',
            (int) $project['lock_version']
        );
        $this->audit->log($userId, AuditLogger::VIDEO_PROJECT_CANCELLED, 'video_project', (int) $project['id'], null, ['reason' => $reason]);
        return $this->projectRepo->findById((int) $project['id']);
    }
}
