<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Libraries\Intelligence\Connectors\GoogleSearchConsoleConnector;
use App\Libraries\Intelligence\Connectors\ConnectorProviderFactory;
use App\Libraries\Intelligence\SearchConsoleService;
use App\Libraries\Database\SchemaGuard;
use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;
use App\Models\Intelligence\AnalyticsConnectionModel;

/**
 * Pulls Search Console figures into reach_search_metric_facts.
 *
 * This is the writer the search pipeline never had: SEO snapshots, the SEO
 * Command Centre and every Search Intelligence page read that table, and
 * nothing populated it, so they all reported honest zeroes forever.
 *
 * Each enabled `gsc` connection gets its incremental window ingested; if a
 * backfill is active on that connection, one further slice of history is
 * processed in the same run. A failure on one connection is recorded and the
 * others still run.
 *
 * Payload:
 *   tenant_id      (int, default 1)
 *   connection_id  (int, optional — restrict the run to a single connection)
 *   skip_backfill  (bool, optional)
 */
class SearchConsoleIngestJob implements JobHandlerInterface
{
    public function handle(array $payload, JobContext $ctx): array
    {
        $connector = ConnectorProviderFactory::searchConsole();

        if (! $connector->isEnabled()) {
            return [
                'status' => 'skipped',
                'reason' => 'SEARCH_CONSOLE_ENABLED is false.',
            ];
        }

        if ($connector instanceof GoogleSearchConsoleConnector) {
            $state = $connector->configurationState();
            if ($state['status'] !== 'configured') {
                return [
                    'status' => 'skipped',
                    'reason' => $state['detail'],
                    'config' => $state['status'],
                ];
            }
        }

        $db = \Config\Database::connect();
        if (! SchemaGuard::hasTable($db, 'reach_analytics_connections')) {
            return ['status' => 'skipped', 'reason' => 'Connector storage not migrated.'];
        }

        $tenantId = (int) ($payload['tenant_id'] ?? 1);
        $model    = new AnalyticsConnectionModel();

        $builder = $model->where('tenant_id', $tenantId)->where('provider', 'gsc')->where('enabled', true);
        if (! empty($payload['connection_id'])) {
            $builder->where('id', (int) $payload['connection_id']);
        }
        $connections = $builder->findAll();

        if ($connections === []) {
            return [
                'status' => 'skipped',
                'reason' => 'No enabled Search Console connection for tenant ' . $tenantId
                    . '. Add one under Intelligence → Connectors.',
            ];
        }

        $service   = SearchConsoleService::make();
        $results   = [];
        $failures  = 0;

        foreach ($connections as $connection) {
            $connectionId = (int) $connection['id'];

            try {
                $incremental = $service->ingestIncremental($connectionId);
                $backfill    = empty($payload['skip_backfill'])
                    ? $service->runBackfillChunk($connectionId)
                    : ['status' => 'skipped'];

                $results[] = [
                    'connection_id' => $connectionId,
                    'site_property' => $connection['site_property'],
                    'incremental'   => $incremental,
                    'backfill'      => $backfill,
                ];
            } catch (\Throwable $e) {
                $failures++;
                log_message('error', 'SearchConsoleIngestJob: connection ' . $connectionId . ': ' . $e->getMessage());
                $results[] = [
                    'connection_id' => $connectionId,
                    'site_property' => $connection['site_property'],
                    'error'         => $e->getMessage(),
                ];
            }
        }

        return [
            'status'      => $failures === 0 ? 'completed' : 'partial',
            'connections' => count($connections),
            'failures'    => $failures,
            'results'     => $results,
        ];
    }
}
