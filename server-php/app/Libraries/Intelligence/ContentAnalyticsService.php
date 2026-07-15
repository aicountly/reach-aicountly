<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence;

use App\Libraries\AuditLogger;
use App\Libraries\Intelligence\Connectors\ContentAnalyticsConnectorInterface;
use App\Libraries\Intelligence\Connectors\DTOs\IngestionRequest;
use App\Models\Intelligence\AnalyticsConnectionModel;
use App\Models\Intelligence\ContentIdentityModel;
use App\Models\Intelligence\ContentMetricFactModel;
use App\Models\Intelligence\ContentMappingFindingModel;
use App\Models\Intelligence\IngestionRunModel;

class ContentAnalyticsService
{
    public function __construct(
        private ContentAnalyticsConnectorInterface $connector,
        private AnalyticsConnectionModel           $connectionModel,
        private ContentIdentityModel               $identityModel,
        private ContentMetricFactModel             $factModel,
        private ContentMappingFindingModel         $findingModel,
        private IngestionRunModel                  $runModel,
        private IngestionCursorService             $cursorService,
        private AuditLogger                        $auditLogger,
    ) {}

    public function ingestIncremental(int $connectionId): array
    {
        $connection = $this->connectionModel->find($connectionId);
        if (!$connection || !$connection['enabled']) {
            throw new \RuntimeException("Connection {$connectionId} is not enabled");
        }

        $range = $this->cursorService->getIncrementalDateRange($connectionId, 'content_metrics', 3);
        $runId = $this->runModel->startRun($connectionId, 'content_metrics', 'incremental', $range['from'], $range['to']);

        $ingested = 0;
        $skipped  = 0;

        try {
            $request = new IngestionRequest(
                connectionId: $connectionId,
                streamType: 'content_metrics',
                dateFrom: $range['from'],
                dateTo: $range['to'],
            );

            $batch = $this->connector->fetchContentMetrics($request);

            foreach ($batch->rows as $row) {
                $url        = $row['page_url'] ?? $this->connector->resolvePageToIdentity($row['page_path'] ?? '');
                $identityId = $this->resolveIdentity((int) $connection['tenant_id'], $url ?? '');

                if (!$identityId) {
                    $this->recordUnmapped($connectionId, $runId, $row['page_url'] ?? $row['page_path'] ?? '');
                    $skipped++;
                    continue;
                }

                $row['content_identity_id'] = $identityId;
                $row['connection_id']       = $connectionId;
                $row['ingestion_run_id']    = $runId;
                $this->factModel->upsertFact($row);
                $ingested++;
            }

            $this->cursorService->advanceCursor($connectionId, 'content_metrics', $range['to']);
            $this->runModel->completeRun($runId, $ingested, $skipped, 0);

            $this->auditLogger->log(null, AuditLogger::ANALYTICS_INGESTION_COMPLETED, 'ingestion_run', $runId,
                null, ['ingested' => $ingested, 'skipped' => $skipped], null, 'system');

            return ['run_id' => $runId, 'ingested' => $ingested, 'skipped' => $skipped];
        } catch (\Throwable $e) {
            $this->runModel->failRun($runId, $e->getMessage());
            $this->auditLogger->log(null, AuditLogger::ANALYTICS_INGESTION_FAILED, 'ingestion_run', $runId,
                null, null, ['error' => $e->getMessage()], 'system');
            throw $e;
        }
    }

    private function resolveIdentity(int $tenantId, string $url): ?int
    {
        if (empty($url)) return null;
        $identity = $this->identityModel->findByCanonicalUrl($tenantId, rtrim($url, '/'));
        return $identity ? (int) $identity['id'] : null;
    }

    private function recordUnmapped(int $connectionId, int $runId, string $url): void
    {
        if (empty($url)) return;
        $this->findingModel->insert([
            'connection_id'    => $connectionId,
            'ingestion_run_id' => $runId,
            'unmapped_url'     => $url,
            'finding_type'     => 'unmapped',
            'resolution_status' => 'unresolved',
        ]);
    }
}
