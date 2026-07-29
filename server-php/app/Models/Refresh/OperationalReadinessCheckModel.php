<?php

declare(strict_types=1);

namespace App\Models\Refresh;

use CodeIgniter\Model;

class OperationalReadinessCheckModel extends Model
{
    protected $table          = 'reach_operational_readiness_checks';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;

    protected $allowedFields = [
        'check_category', 'check_name', 'status', 'evidence', 'checked_at', 'checked_by',
    ];

    /** @return list<array<string, mixed>> */
    public function forCategories(array $categories): array
    {
        if ($categories === []) {
            return [];
        }

        return $this->whereIn('check_category', $categories)
            ->orderBy('check_category', 'ASC')
            ->orderBy('check_name', 'ASC')
            ->findAll();
    }
}
