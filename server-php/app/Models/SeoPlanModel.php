<?php

namespace App\Models;

use CodeIgniter\Model;

class SeoPlanModel extends Model
{
    protected $table         = 'reach_seo_plans';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'title', 'focus_keyword', 'secondary_keywords', 'brief', 'target_url',
        'status', 'bot_generated', 'created_by',
    ];

    protected array $casts = ['secondary_keywords' => 'json-array'];
}
