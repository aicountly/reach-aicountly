<?php

namespace App\Models;

use CodeIgniter\Model;

class BlogVersionModel extends Model
{
    protected $table         = 'reach_blog_versions';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'blog_post_id', 'version', 'snapshot', 'changed_by', 'change_reason', 'created_at',
    ];

    protected array $casts = ['snapshot' => 'json-array'];

    public function latestVersionFor(int $blogPostId): int
    {
        $row = $this->where('blog_post_id', $blogPostId)
            ->orderBy('version', 'DESC')
            ->first();
        return (int) ($row['version'] ?? 0);
    }
}
