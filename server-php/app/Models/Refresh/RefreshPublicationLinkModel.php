<?php

declare(strict_types=1);

namespace App\Models\Refresh;

use CodeIgniter\Model;

class RefreshPublicationLinkModel extends Model
{
    protected $table      = 'reach_refresh_publication_links';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';

    protected $allowedFields = [
        'workflow_id', 'publication_attempt_id', 'idempotency_key',
        'published_at', 'delivery_status', 'retry_count',
    ];

    public function findByIdempotencyKey(string $key): ?array
    {
        return $this->where('idempotency_key', $key)->first();
    }
}
