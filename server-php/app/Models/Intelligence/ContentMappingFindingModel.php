<?php

declare(strict_types=1);

namespace App\Models\Intelligence;

use CodeIgniter\Model;

class ContentMappingFindingModel extends Model
{
    protected $table      = 'reach_content_mapping_findings';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';

    protected $allowedFields = [
        'connection_id', 'ingestion_run_id', 'unmapped_url', 'finding_type',
        'resolution_status', 'resolved_identity_id', 'resolved_at', 'resolved_by',
        'suppressed_reason',
    ];

    public function getUnresolvedForConnection(int $connectionId): array
    {
        return $this->where('connection_id', $connectionId)
                    ->where('resolution_status', 'unresolved')
                    ->findAll();
    }
}
