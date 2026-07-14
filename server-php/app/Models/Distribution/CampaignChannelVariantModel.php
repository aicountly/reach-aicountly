<?php

declare(strict_types=1);

namespace App\Models\Distribution;

use CodeIgniter\Model;

class CampaignChannelVariantModel extends Model
{
    protected $table      = 'reach_campaign_channel_variants';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;

    protected $allowedFields = [
        'uuid', 'campaign_version_id', 'channel', 'source_content_id',
        'template_version_id', 'content_json', 'merge_field_values',
        'validation_status', 'validation_findings', 'generation_artifact_id',
        'created_by', 'created_at',
    ];

    protected $casts = [
        'content_json'        => '?json-array',
        'merge_field_values'  => '?json-array',
        'validation_findings' => '?json-array',
    ];

    public function findByVersion(int $versionId): array
    {
        return $this->where('campaign_version_id', $versionId)->findAll();
    }

    public function findByVersionAndChannel(int $versionId, string $channel): ?array
    {
        return $this->where('campaign_version_id', $versionId)->where('channel', $channel)->first();
    }
}
