<?php

declare(strict_types=1);

namespace App\Models\Intelligence;

use CodeIgniter\Model;

class SitemapSnapshotModel extends Model
{
    protected $table      = 'reach_sitemap_snapshots';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;

    protected $allowedFields = [
        'uuid', 'tenant_id', 'generated_at', 'total_entries', 'included_entries',
        'excluded_noindex', 'excluded_withdrawn', 'excluded_other', 'status',
        'generation_secs', 'error_message', 'triggered_by',
    ];

    public function findByUuid(string $uuid): ?array
    {
        return $this->where('uuid', $uuid)->first();
    }

    public function getLatest(int $tenantId): ?array
    {
        return $this->where('tenant_id', $tenantId)
                    ->where('status', 'validated')
                    ->orderBy('generated_at', 'DESC')
                    ->first();
    }
}
