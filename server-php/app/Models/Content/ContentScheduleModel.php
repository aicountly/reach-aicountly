<?php

namespace App\Models\Content;

use CodeIgniter\Model;

class ContentScheduleModel extends Model
{
    protected $table         = 'reach_content_schedules';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'content_item_id', 'publication_target_id', 'content_version_id',
        'scheduled_at', 'timezone', 'schedule_status', 'approval_required',
        'approval_met_at', 'job_id', 'rescheduled_from_id',
        'cancelled_at', 'cancelled_by', 'cancellation_reason',
        'created_by', 'updated_by', 'created_actor_type', 'request_id',
    ];

    protected array $casts = [
        'approval_required' => 'boolean',
    ];

    public function forItem(int $contentItemId): array
    {
        return $this->where('content_item_id', $contentItemId)
            ->orderBy('scheduled_at', 'ASC')
            ->findAll();
    }

    public function pendingSchedules(): array
    {
        return $this->whereIn('schedule_status', ['pending', 'approved', 'ready'])
            ->where('cancelled_at IS NULL')
            ->orderBy('scheduled_at', 'ASC')
            ->findAll();
    }
}
