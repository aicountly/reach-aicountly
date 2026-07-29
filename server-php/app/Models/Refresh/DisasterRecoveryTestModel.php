<?php

declare(strict_types=1);

namespace App\Models\Refresh;

use CodeIgniter\Model;

class DisasterRecoveryTestModel extends Model
{
    protected $table      = 'reach_disaster_recovery_tests';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;

    protected $allowedFields = [
        'test_type', 'environment', 'status', 'rpo_minutes', 'rto_minutes',
        'procedure_followed', 'evidence_notes', 'tested_by', 'tested_at',
    ];

    public function hasPassedType(string $testType): bool
    {
        return $this->where('test_type', $testType)
            ->where('status', 'passed')
            ->countAllResults() > 0;
    }
}
