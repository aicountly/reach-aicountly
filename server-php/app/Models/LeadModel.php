<?php

namespace App\Models;

use CodeIgniter\Model;

class LeadModel extends Model
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
    ];

    protected array $casts = ['raw_payload' => 'json-array'];

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
