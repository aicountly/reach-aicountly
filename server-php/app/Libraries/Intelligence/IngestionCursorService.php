<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence;

use App\Models\Intelligence\IngestionCursorModel;

class IngestionCursorService
{
    public function __construct(private IngestionCursorModel $cursorModel) {}

    public function getCursor(int $connectionId, string $streamType): ?array
    {
        return $this->cursorModel->getCursor($connectionId, $streamType);
    }

    public function advanceCursor(int $connectionId, string $streamType, string $lastIngestedDate, ?array $cursorState = null): void
    {
        $this->cursorModel->upsertCursor($connectionId, $streamType, [
            'last_ingested_date' => $lastIngestedDate,
            'cursor_state'       => $cursorState ? json_encode($cursorState) : null,
        ]);
    }

    public function initBackfill(int $connectionId, string $streamType, string $fromDate, int $days): void
    {
        $this->cursorModel->upsertCursor($connectionId, $streamType, [
            'backfill_from_date'      => $fromDate,
            'backfill_days_remaining' => $days,
            'is_backfill_active'      => true,
        ]);
    }

    public function decrementBackfill(int $connectionId, string $streamType, int $daysProcessed = 1): void
    {
        $cursor = $this->cursorModel->getCursor($connectionId, $streamType);
        if (!$cursor || !$cursor['is_backfill_active']) {
            return;
        }

        $remaining = max(0, (int) $cursor['backfill_days_remaining'] - $daysProcessed);
        $data = ['backfill_days_remaining' => $remaining];

        if ($remaining === 0) {
            $data['is_backfill_active'] = false;
            $data['backfill_from_date'] = null;
        }

        $this->cursorModel->upsertCursor($connectionId, $streamType, $data);
    }

    public function isBackfillActive(int $connectionId, string $streamType): bool
    {
        $cursor = $this->cursorModel->getCursor($connectionId, $streamType);
        return (bool) ($cursor['is_backfill_active'] ?? false);
    }

    public function getLastIngestedDate(int $connectionId, string $streamType): ?string
    {
        $cursor = $this->cursorModel->getCursor($connectionId, $streamType);
        return $cursor['last_ingested_date'] ?? null;
    }

    public function getIncrementalDateRange(int $connectionId, string $streamType, int $lookbackDays = 3): array
    {
        $lastDate = $this->getLastIngestedDate($connectionId, $streamType);
        $fromDate = $lastDate
            ? date('Y-m-d', strtotime($lastDate . ' -' . $lookbackDays . ' days'))
            : date('Y-m-d', strtotime('-' . $lookbackDays . ' days'));

        return [
            'from' => $fromDate,
            'to'   => date('Y-m-d', strtotime('-1 day')),
        ];
    }
}
