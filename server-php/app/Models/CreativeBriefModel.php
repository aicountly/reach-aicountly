<?php

namespace App\Models;

use CodeIgniter\Model;

class CreativeBriefModel extends Model
{
    protected $table         = 'reach_creative_briefs';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'campaign_id', 'title', 'brief', 'audience', 'deliverables',
        'status', 'bot_generated', 'created_by',
    ];

    protected array $casts = ['deliverables' => 'json-array'];
}
