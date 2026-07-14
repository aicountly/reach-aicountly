<?php

declare(strict_types=1);

namespace App\Models\Video;

use CodeIgniter\Model;

class VideoChapterMarkerModel extends Model
{
    protected $table         = 'reach_video_chapter_markers';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'uuid', 'script_version_id', 'chapter_order', 'title', 'start_time_secs',
    ];

    public function listForVersion(int $scriptVersionId): array
    {
        return $this->where('script_version_id', $scriptVersionId)
            ->orderBy('chapter_order', 'ASC')
            ->findAll();
    }
}
