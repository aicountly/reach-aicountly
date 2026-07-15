<?php

declare(strict_types=1);

namespace App\Models\Intelligence;

use CodeIgniter\Model;

class AttributionTouchpointModel extends Model
{
    protected $table      = 'reach_attribution_touchpoints';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;

    protected $allowedFields = [
        'uuid', 'tenant_id', 'visitor_pseudonym_hash', 'utm_source', 'utm_medium',
        'utm_campaign', 'utm_content', 'utm_term', 'content_identity_id', 'campaign_id',
        'channel', 'touchpoint_type', 'source_event_ref', 'referrer_domain', 'touched_at', 'created_at',
    ];

    public function findByUuid(string $uuid): ?array
    {
        return $this->where('uuid', $uuid)->first();
    }

    public function getForVisitor(string $hash, int $tenantId): array
    {
        return $this->where('tenant_id', $tenantId)
                    ->where('visitor_pseudonym_hash', $hash)
                    ->orderBy('touched_at', 'ASC')
                    ->findAll();
    }
}
