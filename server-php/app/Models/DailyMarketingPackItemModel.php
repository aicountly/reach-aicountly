<?php

namespace App\Models;

use CodeIgniter\Model;

class DailyMarketingPackItemModel extends Model
{
    protected $table         = 'reach_daily_marketing_pack_items';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'pack_id', 'content_item_id', 'slot_type', 'slot_label',
        'is_placeholder', 'priority', 'sort_order', 'reviewer_id',
        'notes', 'created_by',
    ];

    protected array $casts = [
        'is_placeholder' => 'boolean',
    ];

    public function forPack(int $packId): array
    {
        return $this->where('pack_id', $packId)->orderBy('sort_order', 'ASC')->findAll();
    }

    public function isContentInPack(int $packId, int $contentItemId): bool
    {
        return $this->where('pack_id', $packId)
            ->where('content_item_id', $contentItemId)
            ->countAllResults() > 0;
    }
}
