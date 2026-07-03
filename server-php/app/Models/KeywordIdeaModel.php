<?php

namespace App\Models;

use CodeIgniter\Model;

class KeywordIdeaModel extends Model
{
    protected $table         = 'reach_keyword_ideas';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'keyword', 'search_intent', 'priority', 'source', 'status', 'notes', 'created_by',
    ];
}
