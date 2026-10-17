<?php

namespace App\Models;

class LeadModel extends ReachModel
{
    protected $table         = 'reach_leads';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'name', 'email', 'mobile', 'whatsapp', 'organization',
        'source_kind', 'campaign_id', 'landing_page_id', 'product_interest',
        'priority', 'notes', 'raw_payload',
        'engage_push_status', 'engage_lead_code', 'engage_push_attempts',
        'last_push_at', 'last_push_error', 'created_by',
        // Seller referral + UTM / landing capture (2026-10-17-800001)
        'ref_code', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
        'landing_url', 'referrer_url',
        // Latest Engage outcome (written by LeadOutcomeIngestService)
        'engage_stage', 'engage_sales_status', 'engage_stage_at',
    ];

    protected array $casts = ['raw_payload' => '?json-array'];

    public function isRecentDuplicate(string $email, int $windowSeconds = 86400): bool
    {
        if ($email === '') {
            return false;
        }
        $since = gmdate('Y-m-d H:i:s', time() - $windowSeconds);
        return $this->where('email', strtolower($email))
                ->where('engage_push_status', 'pushed')
                ->where('last_push_at >', $since)
                ->countAllResults() > 0;
    }
}
