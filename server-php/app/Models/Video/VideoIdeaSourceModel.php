<?php

declare(strict_types=1);

namespace App\Models\Video;

use CodeIgniter\Model;

class VideoIdeaSourceModel extends Model
{
    protected $table         = 'reach_video_idea_sources';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'idea_id', 'source_type', 'source_ref', 'title', 'snippet', 'relevance',
    ];

    public function listForIdea(int $ideaId): array
    {
        return $this->where('idea_id', $ideaId)
            ->orderBy('relevance', 'DESC NULLS LAST')
            ->findAll();
    }
}
