<?php

declare(strict_types=1);

namespace App\Models\Intelligence;

use CodeIgniter\Model;

class AiVisibilityPromptVersionModel extends Model
{
    protected $table      = 'reach_ai_visibility_prompt_versions';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;

    protected $allowedFields = [
        'uuid', 'prompt_id', 'version_number', 'prompt_text', 'content_hash',
        'is_active', 'approved_at', 'approved_by', 'created_by', 'created_at',
    ];

    public function findByUuid(string $uuid): ?array
    {
        return $this->where('uuid', $uuid)->first();
    }

    public function getActiveVersion(int $promptId): ?array
    {
        return $this->where('prompt_id', $promptId)->where('is_active', true)->first();
    }

    public function getNextVersionNumber(int $promptId): int
    {
        $max = $this->selectMax('version_number')->where('prompt_id', $promptId)->first();
        return (int) ($max['version_number'] ?? 0) + 1;
    }
}
