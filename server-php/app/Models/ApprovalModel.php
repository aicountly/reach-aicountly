<?php

namespace App\Models;

use CodeIgniter\Model;

class ApprovalModel extends Model
{
    protected $table         = 'reach_approvals';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'subject_type', 'subject_id', 'summary', 'requested_by',
        'decision', 'decided_by', 'decided_at', 'note',
        'console_synced_at', 'metadata',
    ];

    protected array $casts = ['metadata' => '?json-array'];

    public function pendingCount(): int
    {
        return $this->where('decision', 'pending')->countAllResults();
    }
}
