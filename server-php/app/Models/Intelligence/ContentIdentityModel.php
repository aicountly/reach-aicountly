<?php

declare(strict_types=1);

namespace App\Models\Intelligence;

use CodeIgniter\Model;

class ContentIdentityModel extends Model
{
    protected $table      = 'reach_content_identities';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';

    protected $allowedFields = [
        'uuid', 'tenant_id', 'content_type', 'source_id', 'canonical_url',
        'publication_status', 'first_published_at', 'last_published_at',
        'content_version', 'analytics_eligible', 'privacy_class',
        'source_repository', 'public_site_route', 'product_ids', 'persona_ids',
    ];

    public function findByUuid(string $uuid): ?array
    {
        return $this->where('uuid', $uuid)->first();
    }

    public function findBySource(int $tenantId, string $contentType, int $sourceId): ?array
    {
        return $this->where('tenant_id', $tenantId)
                    ->where('content_type', $contentType)
                    ->where('source_id', $sourceId)
                    ->first();
    }

    public function findByCanonicalUrl(int $tenantId, string $url): ?array
    {
        return $this->where('tenant_id', $tenantId)->where('canonical_url', $url)->first();
    }

    public function getAnalyticsEligible(int $tenantId): array
    {
        return $this->where('tenant_id', $tenantId)
                    ->where('analytics_eligible', true)
                    ->where('publication_status', 'published')
                    ->findAll();
    }

    public function getSitemapEligible(int $tenantId): array
    {
        return $this->where('tenant_id', $tenantId)
                    ->whereIn('publication_status', ['published'])
                    ->where('privacy_class', 'public')
                    ->findAll();
    }
}
