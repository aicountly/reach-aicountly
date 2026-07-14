<?php

declare(strict_types=1);

namespace App\Models\Video;

use CodeIgniter\Model;

class VideoRenderAttemptModel extends Model
{
    protected $table         = 'reach_video_render_attempts';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'render_job_id', 'attempt_number', 'provider', 'provider_job_id',
        'status', 'failure_class', 'failure_message', 'started_at',
        'completed_at', 'receipt_raw',
    ];

    protected array $casts = [
        'receipt_raw' => '?json-array',
    ];

    public function listForJob(int $renderJobId): array
    {
        return $this->where('render_job_id', $renderJobId)
            ->orderBy('attempt_number', 'ASC')
            ->findAll();
    }
}
