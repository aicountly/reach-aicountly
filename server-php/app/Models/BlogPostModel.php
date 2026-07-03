<?php

namespace App\Models;

use CodeIgniter\Model;

class BlogPostModel extends Model
{
    protected $table         = 'reach_blog_posts';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'title', 'slug', 'excerpt', 'content', 'category', 'tags',
        'seo_title', 'seo_description', 'canonical_url', 'focus_keyword',
        'author', 'featured_image', 'status', 'scheduled_at', 'published_at',
        'approval_status', 'bot_generated', 'current_version',
        'publishing_status', 'publishing_error', 'external_post_id',
        'created_by', 'approved_by', 'approved_at',
    ];

    protected array $casts = ['tags' => 'json-array'];

    public function findBySlug(string $slug): ?array
    {
        return $this->where('slug', $slug)->first();
    }
}
