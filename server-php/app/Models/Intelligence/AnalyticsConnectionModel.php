<?php

declare(strict_types=1);

namespace App\Models\Intelligence;

use CodeIgniter\Model;

class AnalyticsConnectionModel extends Model
{
    protected $table      = 'reach_analytics_connections';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';

    protected $allowedFields = [
        'uuid', 'tenant_id', 'provider', 'display_name', 'site_property',
        'property_id', 'credential_reference', 'enabled', 'health_status',
        'last_health_check_at', 'last_successful_ingest', 'enabled_at',
        'disabled_at', 'revoked_at', 'created_by',
    ];

    public function findByUuid(string $uuid): ?array
    {
        return $this->where('uuid', $uuid)->first();
    }

    public function getEnabledForTenant(int $tenantId): array
    {
        return $this->where('tenant_id', $tenantId)->where('enabled', true)->findAll();
    }

    public function findByProviderAndProperty(int $tenantId, string $provider, string $siteProperty): ?array
    {
        return $this->where('tenant_id', $tenantId)
                    ->where('provider', $provider)
                    ->where('site_property', $siteProperty)
                    ->first();
    }

    public function redactCredentials(array $connection): array
    {
        $connection['credential_reference'] = '[REDACTED]';
        return $connection;
    }
}
