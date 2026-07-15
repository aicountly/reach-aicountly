<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence;

use App\Models\Intelligence\IngestionCursorModel;

class FreshnessService
{
    public function __construct(private IngestionCursorModel $cursorModel) {}

    public function getFreshnessState(int $connectionId, string $streamType, int $thresholdHours = 26): array
    {
        $cursor = $this->cursorModel->getCursor($connectionId, $streamType);
        if (!$cursor || empty($cursor['last_ingested_date'])) {
            return ['state' => 'no_data', 'hours_since_ingest' => null, 'is_stale' => true];
        }

        $lastDate = new \DateTimeImmutable($cursor['last_ingested_date'] . ' 23:59:59');
        $now      = new \DateTimeImmutable();
        $diff     = $now->diff($lastDate);
        $hours    = ($diff->days * 24) + $diff->h;
        $isStale  = $hours > $thresholdHours;

        return [
            'state'             => $isStale ? 'stale' : 'fresh',
            'hours_since_ingest' => $hours,
            'last_ingested_date' => $cursor['last_ingested_date'],
            'is_stale'          => $isStale,
            'threshold_hours'   => $thresholdHours,
        ];
    }

    public function isStale(int $connectionId, string $streamType, int $thresholdHours = 26): bool
    {
        return $this->getFreshnessState($connectionId, $streamType, $thresholdHours)['is_stale'];
    }
}
