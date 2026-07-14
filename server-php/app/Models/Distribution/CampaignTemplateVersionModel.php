<?php

declare(strict_types=1);

namespace App\Models\Distribution;

use CodeIgniter\Model;

class CampaignTemplateVersionModel extends Model
{
    protected $table      = 'reach_campaign_template_versions';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;

    protected $allowedFields = [
        'uuid', 'template_id', 'version_number', 'content_json', 'merge_field_schema',
        'character_count', 'segment_count', 'approved_by', 'approved_at', 'created_by', 'created_at',
    ];

    protected $casts = [
        'content_json'       => '?json-array',
        'merge_field_schema' => '?json-array',
    ];

    public function nextVersionNumber(int $templateId): int
    {
        $max = $this->selectMax('version_number', 'max_v')->where('template_id', $templateId)->first();
        return (int) ($max['max_v'] ?? 0) + 1;
    }
}
