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

    /**
     * The cursor row, with is_backfill_active as a real bool.
     *
     * Postgres hands booleans back as the strings 't' / 'f', and (bool) 'f'
     * is true — so a finished backfill still read as active: status reported
     * `is_backfill_active: true` with 0 days remaining, the last slice said
     * `in_progress` and re-armed the window, and backfill-run printed a bogus
     * extra `?..? site_rows=0 status=completed` slice.
     */
    public function getCursor(int $connectionId, string $streamType): ?array
    {
        $row = $this->where('connection_id', $connectionId)
                    ->where('stream_type', $streamType)
                    ->first();
        if (is_array($row) && array_key_exists('is_backfill_active', $row)) {
            $row['is_backfill_active'] = self::pgBool($row['is_backfill_active']);
        }

        return $row;
    }

    public static function pgBool(mixed $value): bool
    {
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['t', 'true', '1', 'yes', 'on'], true);
        }

        return (bool) $value;
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
