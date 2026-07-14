<?php

declare(strict_types=1);

namespace App\Libraries\Video;

use App\Models\Video\VideoProjectModel;

class VideoProjectRepository
{
    public function __construct(
        private readonly VideoProjectModel $model,
    ) {}

    public function findById(int $id): ?array
    {
        return $this->model->find($id);
    }

    public function findByUuid(string $uuid): ?array
    {
        return $this->model->findByUuid($uuid);
    }

    public function listForTenant(int $tenantId, array $filters = [], int $page = 1, int $perPage = 25): array
    {
        return $this->model->listForTenant($tenantId, $filters, $page, $perPage);
    }

    public function create(array $data): int
    {
        $this->model->insert($data);
        return (int) $this->model->insertID();
    }

    public function update(int $id, array $data): bool
    {
        return (bool) $this->model->update($id, $data);
    }

    public function transitionStatus(int $id, string $newStatus, int $lockVersion): bool
    {
        return $this->model->transitionStatus($id, $newStatus, $lockVersion);
    }

    /**
     * Enum-based status transition helper.
     *
     * Fetches the current lock_version from DB and performs an optimistic
     * concurrency-safe update to the new status.
     *
     * @param int                 $id   Project primary key.
     * @param \App\Enums\VideoProjectStatus $from Expected current status (verified client-side).
     * @param \App\Enums\VideoProjectStatus $to   Target status.
     */
    public function transitionStatusEnum(int $id, \App\Enums\VideoProjectStatus $from, \App\Enums\VideoProjectStatus $to): bool
    {
        $project = $this->model->find($id);
        if ($project === null) {
            throw new \RuntimeException("Video project #{$id} not found");
        }
        return $this->model->transitionStatus($id, $to->value, (int) ($project['lock_version'] ?? 0));
    }

    public function countByStatus(int $tenantId): array
    {
        $rows = $this->model->db->table('reach_video_projects')
            ->select('status, COUNT(*) as count')
            ->where('tenant_id', $tenantId)
            ->groupBy('status')
            ->get()->getResultArray();

        $result = [];
        foreach ($rows as $row) {
            $result[$row['status']] = (int) $row['count'];
        }
        return $result;
    }
}
