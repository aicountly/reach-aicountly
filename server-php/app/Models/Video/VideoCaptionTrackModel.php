<?php

declare(strict_types=1);

namespace App\Models\Video;

use CodeIgniter\Model;

class VideoCaptionTrackModel extends Model
{
    protected $table         = 'reach_video_caption_tracks';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'uuid', 'script_version_id', 'language', 'track_name',
        'content', 'format', 'is_default', 'remote_track_id',
    ];

    public function listForVersion(int $scriptVersionId): array
    {
        return $this->where('script_version_id', $scriptVersionId)
            ->orderBy('is_default', 'DESC')
            ->orderBy('language', 'ASC')
            ->findAll();
    }
}
