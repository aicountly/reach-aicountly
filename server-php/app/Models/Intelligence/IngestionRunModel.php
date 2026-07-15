<?php

declare(strict_types=1);

namespace App\Models\Intelligence;

use CodeIgniter\Model;

class IngestionRunModel extends Model
{
    protected $table      = 'reach_analytics_ingestion_runs';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;

    protected $allowedFields = [
        'uuid', 'connection_id', 'stream_type', 'run_type', 'status', 'date_from', 'date_to',
        'rows_ingested', 'rows_skipped', 'rows_failed', 'job_id', 'started_at', 'completed_at', 'error_message',
    ];

    public function findByUuid(string $uuid): ?array
    {
        return $this->where('uuid', $uuid)->first();
    }

    public function startRun(int $connectionId, string $streamType, string $runType, string $dateFrom, string $dateTo): int
    {
        $id = $this->insert([
            'connection_id' => $connectionId,
            'stream_type'   => $streamType,
            'run_type'      => $runType,
            'status'        => 'started',
            'date_from'     => $dateFrom,
            'date_to'       => $dateTo,
            'started_at'    => date('Y-m-d H:i:s'),
        ]);
        return (int) $id;
    }

    public function completeRun(int $runId, int $ingested, int $skipped, int $failed): void
    {
        $this->update($runId, [
            'status'        => 'completed',
            'rows_ingested' => $ingested,
            'rows_skipped'  => $skipped,
            'rows_failed'   => $failed,
            'completed_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    public function failRun(int $runId, string $error): void
    {
        $this->update($runId, [
            'status'        => 'failed',
            'error_message' => $error,
            'completed_at'  => date('Y-m-d H:i:s'),
        ]);
    }
}
