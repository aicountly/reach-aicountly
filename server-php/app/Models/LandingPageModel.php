<?php

namespace App\Models;

use CodeIgniter\Model;

class LandingPageModel extends Model
{
    protected $table         = 'reach_landing_pages';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'campaign_id', 'slug', 'title', 'meta', 'body', 'status', 'published_at', 'created_by',
    ];

    protected array $casts = ['meta' => 'json-array'];
}
