<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence;

use App\Models\Intelligence\ContentIdentityModel;
use App\Models\Intelligence\AnalyticsConnectionModel;

class ContentIdentityService
{
    public function __construct(
        private ContentIdentityModel $identityModel,
        private AnalyticsConnectionModel $connectionModel
    ) {}

    public function registerOrUpdate(int $tenantId, string $contentType, int $sourceId, array $data): array
    {
        $existing = $this->identityModel->findBySource($tenantId, $contentType, $sourceId);

        $payload = array_merge([
            'tenant_id'    => $tenantId,
            'content_type' => $contentType,
            'source_id'    => $sourceId,
        ], $data);

        if ($existing) {
            $this->identityModel->update($existing['id'], $payload);
            return $this->identityModel->find($existing['id']);
        }

        $id = $this->identityModel->insert($payload);
        return $this->identityModel->find($id);
    }

    public function resolveByUrl(int $tenantId, string $url): ?array
    {
        $normalised = rtrim(strtolower($url), '/');
        $result     = $this->identityModel->findByCanonicalUrl($tenantId, $normalised);
        if ($result) {
            return $result;
        }
        return $this->identityModel->findByCanonicalUrl($tenantId, $url);
    }

    public function markPublished(int $identityId, string $publishedAt): bool
    {
        $identity = $this->identityModel->find($identityId);
        if (!$identity) {
            return false;
        }

        $update = ['publication_status' => 'published', 'last_published_at' => $publishedAt];
        if (empty($identity['first_published_at'])) {
            $update['first_published_at'] = $publishedAt;
        }
        return $this->identityModel->update($identityId, $update);
    }

    public function markWithdrawn(int $identityId): bool
    {
        return $this->identityModel->update($identityId, ['publication_status' => 'withdrawn']);
    }

    public function getSitemapEligible(int $tenantId): array
    {
        return $this->identityModel->getSitemapEligible($tenantId);
    }

    public function getAnalyticsEligible(int $tenantId): array
    {
        return $this->identityModel->getAnalyticsEligible($tenantId);
    }

    public function detectCanonicalConflict(int $tenantId, string $url, int $excludeSourceId, string $contentType): bool
    {
        $existing = $this->identityModel->findByCanonicalUrl($tenantId, $url);
        if (!$existing) {
            return false;
        }
        return !($existing['content_type'] === $contentType && $existing['source_id'] === $excludeSourceId);
    }
}
