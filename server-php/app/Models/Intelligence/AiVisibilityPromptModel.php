<?php

declare(strict_types=1);

namespace App\Models\Intelligence;

use CodeIgniter\Model;

class AiVisibilityPromptModel extends Model
{
    protected $table      = 'reach_ai_visibility_prompts';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';

    protected $allowedFields = [
        'uuid', 'tenant_id', 'name', 'topic', 'intent', 'persona',
        'locale', 'product_id', 'purpose', 'schedule_cron', 'status', 'created_by',
    ];

    public function findByUuid(string $uuid): ?array
    {
        return $this->where('uuid', $uuid)->first();
    }

    public function getActiveForTenant(int $tenantId): array
    {
        return $this->where('tenant_id', $tenantId)->where('status', 'active')->findAll();
    }

    public function getScheduledForExecution(): array
    {
        return $this->where('status', 'active')
                    ->where('schedule_cron IS NOT NULL', null, false)
                    ->findAll();
    }
}
