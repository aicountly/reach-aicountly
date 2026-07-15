<?php

declare(strict_types=1);

namespace App\Models\Intelligence;

use CodeIgniter\Model;

class AttributionConversionLinkModel extends Model
{
    protected $table      = 'reach_attribution_conversion_links';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';

    protected $allowedFields = [
        'uuid', 'tenant_id', 'lead_id', 'first_touchpoint_id', 'last_touchpoint_id',
        'conversion_type', 'converted_at', 'matching_method', 'confidence_state',
        'calculation_version_id', 'manual_correction_note', 'corrected_by', 'corrected_at',
    ];

    public function findByUuid(string $uuid): ?array
    {
        return $this->where('uuid', $uuid)->first();
    }

    public function getByContentIdentity(int $contentIdentityId, string $fromDate, string $toDate): array
    {
        return $this->db->query(
            "SELECT cl.*, tp.touch_type
             FROM reach_attribution_conversion_links cl
             JOIN reach_attribution_touchpoints tp
               ON tp.id = cl.first_touchpoint_id OR tp.id = cl.last_touchpoint_id
             WHERE tp.content_identity_id = ?
               AND cl.converted_at >= ? AND cl.converted_at <= ?",
            [$contentIdentityId, $fromDate, $toDate]
        )->getResultArray();
    }
}
