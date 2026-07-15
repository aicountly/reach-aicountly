<?php

declare(strict_types=1);

namespace App\Models\Refresh;

use CodeIgniter\Model;

class TechnicalDebtRecordModel extends Model
{
    protected $table      = 'reach_technical_debt_records';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';

    protected $allowedFields = [
        'tenant_id', 'classification', 'title', 'description', 'impact', 'workaround',
        'owner', 'target_date', 'acceptance_reason', 'accepted_by', 'accepted_at',
    ];
}
