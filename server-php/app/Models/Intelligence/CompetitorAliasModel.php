<?php

declare(strict_types=1);

namespace App\Models\Intelligence;

use CodeIgniter\Model;

class CompetitorAliasModel extends Model
{
    protected $table      = 'reach_competitor_aliases';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;

    protected $allowedFields = [
        'competitor_id', 'alias_type', 'alias_value', 'is_canonical', 'added_by', 'added_at',
    ];
}
