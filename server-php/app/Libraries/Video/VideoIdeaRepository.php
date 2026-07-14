<?php

declare(strict_types=1);

namespace App\Libraries\Video;

use App\Models\Video\VideoIdeaModel;
use App\Models\Video\VideoIdeaSourceModel;

class VideoIdeaRepository
{
    public function __construct(
        private readonly VideoIdeaModel       $ideaModel,
        private readonly VideoIdeaSourceModel $sourceModel,
    ) {}

    public function findById(int $id): ?array
    {
        return $this->ideaModel->find($id);
    }

    public function findByUuid(string $uuid): ?array
    {
        return $this->ideaModel->findByUuid($uuid);
    }

    public function listForTenant(int $tenantId, array $filters = [], int $page = 1, int $perPage = 25): array
    {
        return $this->ideaModel->listForTenant($tenantId, $filters, $page, $perPage);
    }

    public function create(array $data): int
    {
        $this->ideaModel->insert($data);
        return (int) $this->ideaModel->insertID();
    }

    public function update(int $id, array $data): bool
    {
        return (bool) $this->ideaModel->update($id, $data);
    }

    public function addSource(int $ideaId, array $source): int
    {
        $source['idea_id'] = $ideaId;
        $this->sourceModel->insert($source);
        return (int) $this->sourceModel->insertID();
    }

    public function listSources(int $ideaId): array
    {
        return $this->sourceModel->listForIdea($ideaId);
    }

    public function countByStatus(int $tenantId): array
    {
        $rows = $this->ideaModel->db->table('reach_video_ideas')
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
