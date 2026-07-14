<?php

declare(strict_types=1);

namespace App\Libraries\Video;

use App\Models\Video\VideoRenderJobModel;
use App\Models\Video\VideoRenderAttemptModel;

class VideoRenderJobRepository
{
    public function __construct(
        private readonly VideoRenderJobModel     $jobModel,
        private readonly VideoRenderAttemptModel $attemptModel,
    ) {}

    public function findById(int $id): ?array
    {
        return $this->jobModel->find($id);
    }

    public function findByUuid(string $uuid): ?array
    {
        return $this->jobModel->findByUuid($uuid);
    }

    public function findByIdempotencyKey(string $key): ?array
    {
        return $this->jobModel->findByIdempotencyKey($key);
    }

    public function findByProviderJobId(string $providerJobId): ?array
    {
        return $this->jobModel->findByProviderJobId($providerJobId);
    }

    public function listForProject(int $projectId): array
    {
        return $this->jobModel->listForProject($projectId);
    }

    public function create(array $data): array
    {
        $existing = $this->findByIdempotencyKey($data['idempotency_key'] ?? '');
        if ($existing !== null) {
            return $existing;
        }
        $this->jobModel->insert($data);
        return $this->jobModel->find((int) $this->jobModel->insertID());
    }

    public function update(int $id, array $data): bool
    {
        return (bool) $this->jobModel->update($id, $data);
    }

    public function reserveNext(): ?array
    {
        return $this->jobModel->reserveNext();
    }

    public function recordAttempt(array $attempt): int
    {
        $this->attemptModel->insert($attempt);
        return (int) $this->attemptModel->insertID();
    }

    public function listAttempts(int $renderJobId): array
    {
        return $this->attemptModel->listForJob($renderJobId);
    }
}
