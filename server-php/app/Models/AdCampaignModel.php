<?php

namespace App\Models;

/**
 * A paid campaign placed by hand in an ads manager (reach_ad_campaigns).
 * Reach never places, pauses or reads ads; see App\Libraries\Ads.
 */
class AdCampaignModel extends ReachModel
{
    protected $table         = 'reach_ad_campaigns';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'platform', 'name', 'external_ref', 'objective', 'daily_budget_inr', 'start_date', 'end_date',
        'landing_url', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
        'creatives', 'status', 'notes', 'created_by',
    ];

    protected array $casts = [
        'creatives' => 'json-array',
    ];
}
