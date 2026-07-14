<?php

declare(strict_types=1);

namespace App\Libraries\Video;

use App\Models\Video\VideoPublicationProfileModel;

class VideoPublicationRepository
{
    public function __construct(
        private readonly VideoPublicationProfileModel $profileModel,
    ) {}

    public function findProfileByUuid(string $uuid): ?array
    {
        return $this->profileModel->findByUuid($uuid);
    }

    public function findProfileByProject(int $projectId, string $platform = 'youtube'): ?array
    {
        return $this->profileModel->findByProject($projectId, $platform);
    }

    public function createProfile(array $data): array
    {
        $this->profileModel->insert($data);
        $id = (int) $this->profileModel->insertID();
        return $this->profileModel->find($id);
    }

    public function updateProfile(int $id, array $data): bool
    {
        return (bool) $this->profileModel->update($id, $data);
    }

    public function createDeployment(array $data): int
    {
        $db = \Config\Database::connect();
        $db->table('reach_publication_deployments')->insert(array_merge([
            'subject_type' => 'video_project',
            'subject_id'   => $data['project_id'] ?? null,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ], $data));
        return (int) $db->insertID();
    }

    public function listDeployments(int $tenantId, int $page = 1, int $perPage = 25): array
    {
        $db = \Config\Database::connect();
        $builder = $db->table('reach_publication_deployments d')
            ->select('d.*, p.title AS project_title, p.uuid AS project_uuid')
            ->join('reach_video_projects p', 'p.id = d.subject_id', 'left')
            ->where('d.subject_type', 'video_project')
            ->where('p.tenant_id', $tenantId);

        $total  = $builder->countAllResults(false);
        $offset = ($page - 1) * $perPage;
        $rows   = $builder->orderBy('d.created_at', 'DESC')
            ->limit($perPage, $offset)
            ->get()->getResultArray();

        return ['data' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }
}
