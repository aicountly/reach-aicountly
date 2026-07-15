<?php

declare(strict_types=1);

namespace App\Models\Intelligence;

use CodeIgniter\Model;

class IngestionCursorModel extends Model
{
    protected $table      = 'reach_analytics_ingestion_cursors';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;

    protected $allowedFields = [
        'connection_id', 'stream_type', 'last_ingested_date', 'backfill_from_date',
        'backfill_days_remaining', 'cursor_state', 'is_backfill_active', 'updated_at',
    ];

    public function getCursor(int $connectionId, string $streamType): ?array
    {
        return $this->where('connection_id', $connectionId)
                    ->where('stream_type', $streamType)
                    ->first();
    }

    public function upsertCursor(int $connectionId, string $streamType, array $data): bool
    {
        $existing = $this->getCursor($connectionId, $streamType);
        $data['updated_at'] = date('Y-m-d H:i:s');

        if ($existing) {
            return $this->update($existing['id'], $data);
        }

        $data['connection_id'] = $connectionId;
        $data['stream_type']   = $streamType;
        return $this->insert($data) !== false;
    }
}
