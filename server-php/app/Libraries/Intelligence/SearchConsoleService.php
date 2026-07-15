<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence;

use App\Libraries\AuditLogger;
use App\Libraries\Intelligence\Connectors\SearchConsoleConnectorInterface;
use App\Libraries\Intelligence\Connectors\DTOs\IngestionRequest;
use App\Models\Intelligence\AnalyticsConnectionModel;
use App\Models\Intelligence\IngestionRunModel;
use App\Models\Intelligence\SearchMetricFactModel;
use App\Models\Intelligence\ContentIdentityModel;
use App\Models\Intelligence\ContentMappingFindingModel;

class SearchConsoleService
{
    public function __construct(
        private SearchConsoleConnectorInterface $connector,
        private AnalyticsConnectionModel        $connectionModel,
        private IngestionRunModel               $runModel,
        private SearchMetricFactModel           $factModel,
        private ContentIdentityModel            $identityModel,
        private ContentMappingFindingModel      $findingModel,
        private IngestionCursorService          $cursorService,
        private AuditLogger                     $auditLogger,
    ) {}

    public function ingestIncremental(int $connectionId): array
    {
        $connection = $this->connectionModel->find($connectionId);
        if (!$connection || !$connection['enabled']) {
            throw new \RuntimeException("Connection {$connectionId} is not enabled");
        }

        $range  = $this->cursorService->getIncrementalDateRange($connectionId, 'search_metrics', 3);
        $runId  = $this->runModel->startRun($connectionId, 'search_metrics', 'incremental', $range['from'], $range['to']);

        $ingested = 0;
        $skipped  = 0;

        try {
            $request = new IngestionRequest(
                connectionId: $connectionId,
                streamType: 'search_metrics',
                dateFrom: $range['from'],
                dateTo: $range['to'],
            );

            $batch = $this->connector->fetchSearchMetrics($request);

            foreach ($batch->rows as $row) {
                $identityId = $this->resolveContentIdentity(
                    (int) $connection['tenant_id'],
                    $row['page_url'] ?? ''
                );

                if (!$identityId) {
                    $this->recordUnmappedUrl($connectionId, $runId, $row['page_url'] ?? '');
                    $skipped++;
                    continue;
                }

                $row['content_identity_id'] = $identityId;
                $row['connection_id']       = $connectionId;
                $row['ingestion_run_id']    = $runId;
                $this->factModel->upsertFact($row);
                $ingested++;
            }

            $this->cursorService->advanceCursor($connectionId, 'search_metrics', $range['to']);
            $this->runModel->completeRun($runId, $ingested, $skipped, 0);

            $this->auditLogger->log(null, AuditLogger::SEARCH_INGESTION_COMPLETED, 'ingestion_run', $runId,
                null, ['ingested' => $ingested, 'skipped' => $skipped], null, 'system');

            return ['run_id' => $runId, 'ingested' => $ingested, 'skipped' => $skipped];
        } catch (\Throwable $e) {
            $this->runModel->failRun($runId, $e->getMessage());
            $this->auditLogger->log(null, AuditLogger::SEARCH_INGESTION_FAILED, 'ingestion_run', $runId,
                null, null, ['error' => $e->getMessage()], 'system');
            throw $e;
        }
    }

    public function initiateBackfill(int $connectionId, int $days): void
    {
        $backfillFrom = date('Y-m-d', strtotime("-{$days} days"));
        $this->cursorService->initBackfill($connectionId, 'search_metrics', $backfillFrom, $days);

        $this->auditLogger->log(null, AuditLogger::SEARCH_BACKFILL_STARTED, 'analytics_connection', $connectionId,
            null, ['days' => $days, 'from' => $backfillFrom], null, 'human');
    }

    private function resolveContentIdentity(int $tenantId, string $url): ?int
    {
        if (empty($url)) return null;
        $identity = $this->identityModel->findByCanonicalUrl($tenantId, rtrim($url, '/'));
        return $identity ? (int) $identity['id'] : null;
    }

    private function recordUnmappedUrl(int $connectionId, int $runId, string $url): void
    {
        if (empty($url)) return;
        $this->findingModel->insert([
            'connection_id'    => $connectionId,
            'ingestion_run_id' => $runId,
            'unmapped_url'     => $url,
            'finding_type'     => 'unmapped',
            'resolution_status' => 'unresolved',
        ]);
        $this->auditLogger->log(null, AuditLogger::SEARCH_UNMAPPED_URL_FOUND, 'content_mapping', null,
            null, ['url' => $url], null, 'system');
    }
}
