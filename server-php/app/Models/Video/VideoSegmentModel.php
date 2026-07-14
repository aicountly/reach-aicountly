<?php

declare(strict_types=1);

namespace App\Models\Video;

use CodeIgniter\Model;

class VideoSegmentModel extends Model
{
    protected $table         = 'reach_video_segments';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'uuid', 'script_version_id', 'segment_order', 'segment_type',
        'title', 'voice_over_text', 'visual_direction', 'duration_hint_secs', 'metadata',
    ];

    protected array $casts = [
        'metadata' => '?json-array',
    ];

    public function listForVersion(int $scriptVersionId): array
    {
        return $this->where('script_version_id', $scriptVersionId)
            ->orderBy('segment_order', 'ASC')
            ->findAll();
    }
}
