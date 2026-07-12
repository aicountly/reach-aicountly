<?php

namespace App\Models\Content;

use CodeIgniter\Model;

class ContentValidationModel extends Model
{
    protected $table         = 'reach_content_validations';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'content_item_id', 'version_id', 'validation_type', 'validation_status',
        'score', 'message', 'details',
        'waiver_reason', 'waived_by', 'waived_at',
        'run_by', 'run_at',
    ];

    protected array $casts = [
        'details' => 'json-array',
    ];

    public function forItem(int $contentItemId): array
    {
        return $this->where('content_item_id', $contentItemId)
            ->orderBy('validation_type', 'ASC')
            ->findAll();
    }

    public function latestForType(int $contentItemId, string $type): ?array
    {
        return $this->where('content_item_id', $contentItemId)
            ->where('validation_type', $type)
            ->orderBy('created_at', 'DESC')
            ->first();
    }

    public function hasBlockers(int $contentItemId): bool
    {
        return $this->where('content_item_id', $contentItemId)
            ->where('validation_status', 'failed')
            ->where('waived_at IS NULL')
            ->countAllResults() > 0;
    }

    public function computeOverallStatus(int $contentItemId): string
    {
        $rows = $this->forItem($contentItemId);
        if (empty($rows)) {
            return 'not_run';
        }

        $statuses = array_column($rows, 'validation_status');

        if (in_array('failed', $statuses, true)) {
            $anyUnwaived = $this->hasBlockers($contentItemId);
            return $anyUnwaived ? 'failed' : 'warning';
        }
        if (in_array('warning', $statuses, true)) {
            return 'warning';
        }
        if (in_array('pending', $statuses, true)) {
            return 'pending';
        }
        return 'passed';
    }
}
