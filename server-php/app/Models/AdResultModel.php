<?php

namespace App\Models;

/**
 * One day of results for a paid campaign (reach_ad_results), typed in or
 * imported from an ads manager CSV. NULL means "not entered".
 */
class AdResultModel extends ReachModel
{
    protected $table         = 'reach_ad_results';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'ad_campaign_id', 'result_date', 'spend_inr', 'impressions', 'clicks', 'conversions',
        'metrics_source', 'signups', 'signups_source', 'created_by',
    ];
}
