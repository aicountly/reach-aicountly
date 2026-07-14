<?php

namespace App\Models;

use CodeIgniter\Model;

class DailyMarketingPackModel extends Model
{
    protected $table         = 'reach_daily_marketing_packs';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $useSoftDeletes = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $deletedField  = 'deleted_at';

    protected $allowedFields = [
        'uuid', 'pack_date', 'market_id', 'language', 'pack_status',
        'admin_owner_id', 'summary', 'config_snapshot',
        'approved_at', 'approved_by', 'generated_by', 'created_actor_type',
    ];

    protected array $casts = [
        'config_snapshot' => '?json-array',
    ];

    public function forDate(string $date, ?int $marketId = null, string $language = 'en'): ?array
    {
        $q = $this->where('pack_date', $date)->where('language', $language)->withDeleted(false);
        if ($marketId !== null) {
            $q = $q->where('market_id', $marketId);
        } else {
            $q = $q->where('market_id IS NULL');
        }
        return $q->first();
    }

    public function listPaged(int $perPage = 25): array
    {
        return $this->withDeleted(false)->orderBy('pack_date', 'DESC')->paginate($perPage);
    }
}
