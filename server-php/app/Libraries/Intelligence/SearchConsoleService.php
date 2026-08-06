<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence;

use App\Libraries\AuditLogger;
use App\Libraries\Intelligence\Connectors\ConnectorProviderFactory;
use App\Libraries\Intelligence\Connectors\SearchConsoleConnectorInterface;
use App\Libraries\Intelligence\Connectors\DTOs\IngestionRequest;
use App\Models\Intelligence\AnalyticsConnectionModel;
use App\Models\Intelligence\IngestionRunModel;
use App\Models\Intelligence\SearchMetricFactModel;
use App\Models\Intelligence\ContentIdentityModel;
use App\Models\Intelligence\ContentMappingFindingModel;

class SearchConsoleService
{
    /**
     * Safety stop for the pagination loop. Search Console caps a response at
     * 25 000 rows, so this still allows a quarter of a million rows per run
     * while guaranteeing a malformed cursor can never spin forever.
     */
    private const MAX_PAGES = 10;

    /** Days per backfill slice, so one job never straddles a huge window. */
    private const BACKFILL_CHUNK_DAYS = 14;

    public function __construct(
        private SearchConsoleConnectorInterface $connector,
        private AnalyticsConnectionModel        $connectionModel,
        private IngestionRunModel               $runModel,
        private SearchMetricFactModel           $factModel,
        private ContentIdentityModel            $identityModel,
        private ContentMappingFindingModel      $findingModel,
        private IngestionCursorService          $cursorService,
        private AuditLogger                     $auditLogger,
        private ?ContentIdentitySyncService     $identitySync = null,
    ) {
        $this->identitySync ??= new ContentIdentitySyncService();
    }

    /**
     * Build the service with every collaborator wired from defaults.
     *
     * Controllers and jobs both need the same eight-dependency graph; without
     * this the wiring was simply never done and the endpoints stayed stubs.
     */
    public static function make(?string $siteProperty = null): self
    {
        return new self(
            ConnectorProviderFactory::searchConsole($siteProperty),
            new AnalyticsConnectionModel(),
            new IngestionRunModel(),
            new SearchMetricFactModel(),
            new ContentIdentityModel(),
            new ContentMappingFindingModel(),
            new IngestionCursorService(new \App\Models\Intelligence\IngestionCursorModel()),
            new AuditLogger(),
        );
    }

    /**
     * Ingest the rolling incremental window for one connection.
     *
     * @return array<string, mixed>
     */
    public function ingestIncremental(int $connectionId): array
    {
        $connection = $this->requireConnection($connectionId);
        $range      = $this->cursorService->getIncrementalDateRange($connectionId, 'search_metrics', 3);

        return $this->runIngestion($connection, $range['from'], $range['to'], 'incremental', advanceCursor: true);
    }

    /**
     * Register a backfill intent. The work itself happens in runBackfillChunk(),
     * one slice per job run, so a 90-day backfill never blocks a request or
     * blows the API quota in a single burst.
     */
    public function initiateBackfill(int $connectionId, int $days): array
    {
        $days         = max(1, min($days, 480));
        $backfillFrom = date('Y-m-d', strtotime("-{$days} days"));

        $this->requireConnection($connectionId);
        $this->cursorService->initBackfill($connectionId, 'search_metrics', $backfillFrom, $days);

        $this->auditLogger->log(null, AuditLogger::SEARCH_BACKFILL_STARTED, 'analytics_connection', $connectionId,
            null, ['days' => $days, 'from' => $backfillFrom], null, 'human');

        return [
            'connection_id'  => $connectionId,
            'days'           => $days,
            'from'           => $backfillFrom,
            'chunk_days'     => self::BACKFILL_CHUNK_DAYS,
            'status'         => 'queued',
        ];
    }

    /**
     * Process the next slice of an active backfill.
     *
     * @return array<string, mixed> with status 'inactive' when nothing is pending
     */
    public function runBackfillChunk(int $connectionId): array
    {
        $cursor = $this->cursorService->getCursor($connectionId, 'search_metrics');
        if (! $cursor || ! $cursor['is_backfill_active']) {
            return ['status' => 'inactive', 'connection_id' => $connectionId];
        }

        $connection = $this->requireConnection($connectionId);
        $from       = (string) $cursor['backfill_from_date'];
        $remaining  = max(0, (int) $cursor['backfill_days_remaining']);
        $chunk      = min(self::BACKFILL_CHUNK_DAYS, $remaining);

        if ($from === '' || $chunk === 0) {
            $this->cursorService->decrementBackfill($connectionId, 'search_metrics', max(1, $remaining));
            return ['status' => 'completed', 'connection_id' => $connectionId];
        }

        $to = date('Y-m-d', strtotime($from . ' +' . ($chunk - 1) . ' days'));

        // A backfill walks backwards in time from `backfill_from_date`; it must
        // not touch last_ingested_date, or the incremental window would jump
        // back to the historical slice and re-read it forever.
        $result = $this->runIngestion($connection, $from, $to, 'backfill', advanceCursor: false);

        $this->cursorService->decrementBackfill($connectionId, 'search_metrics', $chunk);

        // Walk the window forward through history towards the present.
        $stillActive = $this->cursorService->isBackfillActive($connectionId, 'search_metrics');
        if ($stillActive) {
            $this->cursorService->setBackfillWindowStart(
                $connectionId,
                'search_metrics',
                date('Y-m-d', strtotime($to . ' +1 day')),
            );
        }

        return $result + [
            'status'          => $stillActive ? 'in_progress' : 'completed',
            'chunk_from'      => $from,
            'chunk_to'        => $to,
            'days_remaining'  => max(0, $remaining - $chunk),
        ];
    }

    /**
     * Where a connection stands right now: cursor position, last run, and how
     * many facts have actually landed.
     *
     * @return array<string, mixed>
     */
    public function connectionStatus(int $connectionId): array
    {
        $connection = $this->connectionModel->find($connectionId);
        if (! $connection) {
            throw new \RuntimeException("Connection {$connectionId} not found");
        }

        $cursor  = $this->cursorService->getCursor($connectionId, 'search_metrics');
        $lastRun = $this->runModel->where('connection_id', $connectionId)
            ->orderBy('id', 'DESC')->first();

        $connector = $this->connectorFor($connection);

        return [
            'connection_id'       => $connectionId,
            'provider'            => $connection['provider'],
            'site_property'       => $connection['site_property'],
            'enabled'             => (bool) $connection['enabled'],
            'health_status'       => $connection['health_status'],
            'last_health_check_at' => $connection['last_health_check_at'] ?? null,
            'connector_enabled'   => $connector->isEnabled(),
            'cursor'              => $cursor ? [
                'last_ingested_date'      => $cursor['last_ingested_date'],
                'is_backfill_active'      => (bool) $cursor['is_backfill_active'],
                'backfill_from_date'      => $cursor['backfill_from_date'],
                'backfill_days_remaining' => (int) $cursor['backfill_days_remaining'],
            ] : null,
            'last_run'            => $lastRun ? [
                'id'            => (int) $lastRun['id'],
                'run_type'      => $lastRun['run_type'],
                'status'        => $lastRun['status'],
                'date_from'     => $lastRun['date_from'],
                'date_to'       => $lastRun['date_to'],
                'rows_ingested' => (int) $lastRun['rows_ingested'],
                'rows_skipped'  => (int) $lastRun['rows_skipped'],
                'started_at'    => $lastRun['started_at'],
                'completed_at'  => $lastRun['completed_at'],
                'error_message' => $lastRun['error_message'],
            ] : null,
        ];
    }

    /**
     * Run a live health check and persist the verdict on the connection.
     *
     * @return array<string, mixed>
     */
    public function healthCheck(int $connectionId): array
    {
        $connection = $this->connectionModel->find($connectionId);
        if (! $connection) {
            throw new \RuntimeException("Connection {$connectionId} not found");
        }

        $result = $this->connectorFor($connection)->healthCheck();

        $this->connectionModel->update($connectionId, [
            'health_status'        => $result->healthy ? 'healthy' : 'failing',
            'last_health_check_at' => date('Y-m-d H:i:s'),
        ]);

        $this->auditLogger->log(
            null,
            $result->healthy ? AuditLogger::CONNECTOR_HEALTH_CHECK_PASSED : AuditLogger::CONNECTOR_HEALTH_CHECK_FAILED,
            'analytics_connection',
            $connectionId,
            null,
            ['status' => $result->status],
            $result->healthy ? null : ['error' => $result->errorMessage],
            'system'
        );

        return [
            'connection_id' => $connectionId,
            'healthy'       => $result->healthy,
            'status'        => $result->status,
            'latency_ms'    => $result->latencyMs,
            'error_class'   => $result->errorClass,
            'error'         => $result->errorMessage,
            'http_status'   => $result->httpStatus,
            'checked_at'    => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Drive one ingestion window to completion, following the connector's
     * pagination cursor.
     *
     * The previous implementation read a single page and stopped, silently
     * dropping everything past the first batch, and looked identity up with one
     * query per row.
     *
     * @param array<string, mixed> $connection
     * @return array<string, mixed>
     */
    private function runIngestion(
        array $connection,
        string $dateFrom,
        string $dateTo,
        string $runType,
        bool $advanceCursor,
    ): array {
        $connectionId = (int) $connection['id'];
        $tenantId     = (int) $connection['tenant_id'];
        $connector    = $this->connectorFor($connection);

        if (! $connector->isEnabled()) {
            throw new \RuntimeException(
                'Search Console connector is disabled. Set SEARCH_CONSOLE_ENABLED=true and restart PHP.'
            );
        }

        // Refresh identities first: a post published since the last run has no
        // identity yet, and its Search Console rows would be filed as unmapped.
        $this->identitySync->syncBlogs($tenantId);
        $urlIndex = $this->identitySync->urlIndex($tenantId);

        $runId = $this->runModel->startRun($connectionId, 'search_metrics', $runType, $dateFrom, $dateTo);

        $this->auditLogger->log(null, AuditLogger::SEARCH_INGESTION_STARTED, 'ingestion_run', $runId,
            null, ['from' => $dateFrom, 'to' => $dateTo, 'run_type' => $runType], null, 'system');

        $ingested    = 0;
        $skipped     = 0;
        $failed      = 0;
        $pages       = 0;
        $warnings    = [];
        $cursorState = null;

        try {
            do {
                $batch = $connector->fetchSearchMetrics(new IngestionRequest(
                    connectionId: $connectionId,
                    streamType: 'search_metrics',
                    dateFrom: $dateFrom,
                    dateTo: $dateTo,
                    dimensions: ['date', 'page', 'query'],
                    cursorState: $cursorState,
                    isBackfill: $runType === 'backfill',
                ));

                $pages++;

                if ($batch->warningMessage !== null) {
                    $warnings[] = $batch->warningMessage;
                }

                foreach ($batch->rows as $row) {
                    $pageUrl    = trim((string) ($row['page_url'] ?? ''));
                    $identityId = $urlIndex[ContentIdentitySyncService::normaliseUrl($pageUrl)] ?? null;

                    if ($identityId === null) {
                        $this->recordUnmappedUrl($connectionId, $runId, $pageUrl);
                        $skipped++;
                        continue;
                    }

                    $row['content_identity_id']   = $identityId;
                    $row['connection_id']         = $connectionId;
                    $row['ingestion_run_id']      = $runId;
                    $row['provider_freshness_at'] = $batch->providerFreshnessAt;

                    try {
                        $this->factModel->upsertFact($row);
                        $ingested++;
                    } catch (\Throwable $e) {
                        $failed++;
                        log_message('error', 'SearchConsoleService: fact upsert failed: ' . $e->getMessage());
                    }
                }

                $cursorState = $batch->nextCursorState;
            } while ($cursorState !== null && ! $batch->isComplete && $pages < self::MAX_PAGES);

            $truncated = $cursorState !== null && $pages >= self::MAX_PAGES;
            if ($truncated) {
                $warnings[] = 'Stopped after ' . self::MAX_PAGES . ' pages; more rows remain for '
                    . $dateFrom . '..' . $dateTo . '.';
            }

            // A property covers the whole site, so most rows legitimately belong
            // to pages Reach does not own (marketing, guides, pricing). Say so,
            // rather than letting a high skip count read as a failure.
            if ($skipped > 0 && $ingested === 0) {
                $warnings[] = 'All ' . $skipped . ' row(s) were for URLs no Reach content claims. '
                    . 'Run `reach:search-console unmapped` to see them — if they are marketing or guide '
                    . 'pages this is correct, and it means the tracked posts have no search traffic yet.';
            }

            if ($advanceCursor) {
                $this->cursorService->advanceCursor($connectionId, 'search_metrics', $dateTo);
            }

            $this->runModel->completeRun($runId, $ingested, $skipped, $failed);
            $this->connectionModel->update($connectionId, ['last_successful_ingest' => date('Y-m-d H:i:s')]);

            $this->auditLogger->log(null, AuditLogger::SEARCH_INGESTION_COMPLETED, 'ingestion_run', $runId,
                null, ['ingested' => $ingested, 'skipped' => $skipped, 'failed' => $failed], null, 'system');

            return [
                'run_id'        => $runId,
                'connection_id' => $connectionId,
                'run_type'      => $runType,
                'date_from'     => $dateFrom,
                'date_to'       => $dateTo,
                'pages'         => $pages,
                'ingested'      => $ingested,
                'skipped'       => $skipped,
                'failed'        => $failed,
                'truncated'     => $truncated,
                'warnings'      => $warnings,
            ];
        } catch (\Throwable $e) {
            $this->runModel->failRun($runId, $e->getMessage());
            $this->auditLogger->log(null, AuditLogger::SEARCH_INGESTION_FAILED, 'ingestion_run', $runId,
                null, null, ['error' => $e->getMessage()], 'system');
            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function requireConnection(int $connectionId): array
    {
        $connection = $this->connectionModel->find($connectionId);
        if (! $connection) {
            throw new \RuntimeException("Connection {$connectionId} not found");
        }
        if (! $connection['enabled']) {
            throw new \RuntimeException("Connection {$connectionId} is not enabled");
        }
        if (($connection['provider'] ?? '') !== 'gsc') {
            throw new \RuntimeException("Connection {$connectionId} is not a Search Console connection");
        }

        return $connection;
    }

    /**
     * @param array<string, mixed> $connection
     */
    private function connectorFor(array $connection): SearchConsoleConnectorInterface
    {
        $property = trim((string) ($connection['site_property'] ?? ''));

        return $property === '' ? $this->connector : $this->connector->forSiteProperty($property);
    }

    private function recordUnmappedUrl(int $connectionId, int $runId, string $url): void
    {
        if ($url === '') {
            return;
        }

        // One finding per URL per connection is enough — the point is to tell an
        // operator which live pages have no identity, not to log every row.
        $existing = $this->findingModel
            ->where('connection_id', $connectionId)
            ->where('unmapped_url', $url)
            ->where('resolution_status', 'unresolved')
            ->first();

        if ($existing) {
            return;
        }

        $this->findingModel->insert([
            'connection_id'     => $connectionId,
            'ingestion_run_id'  => $runId,
            'unmapped_url'      => $url,
            'finding_type'      => 'unmapped',
            'resolution_status' => 'unresolved',
        ]);

        $this->auditLogger->log(null, AuditLogger::SEARCH_UNMAPPED_URL_FOUND, 'content_mapping', null,
            null, ['url' => $url], null, 'system');
    }
}
