<?php

declare(strict_types=1);

namespace App\Models\Refresh;

use CodeIgniter\Model;

class ReleaseAcceptanceRecordModel extends Model
{
    protected $table      = 'reach_release_acceptance_records';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;

    protected $allowedFields = [
        'release_name', 'recommendation', 'evidence_summary', 'blockers_resolved',
        'limitations_accepted', 'accepted_risks', 'prerequisite_checks',
        'accepted_by', 'accepted_at',
    ];

    public function getLatest(): ?array
    {
        return $this->orderBy('id', 'DESC')->first();
    }
}
