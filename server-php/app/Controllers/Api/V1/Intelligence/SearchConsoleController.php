<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Intelligence;

use App\Controllers\BaseApiController;
use App\Libraries\Intelligence\ContentIdentitySyncService;
use App\Libraries\Intelligence\Connectors\ConnectorProviderFactory;
use App\Libraries\Intelligence\Connectors\GoogleSearchConsoleConnector;
use App\Libraries\Intelligence\SearchConsoleService;
use App\Models\Intelligence\AnalyticsConnectionModel;
use App\Models\Intelligence\ContentMappingFindingModel;
use App\Models\Intelligence\IngestionRunModel;
use App\Models\Intelligence\SearchMetricFactModel;
use CodeIgniter\HTTP\ResponseInterface;
use App\Libraries\Database\SchemaGuard;

/**
 * Google Search Console API surface.
 *
 * Every endpoint here talks to the real connector or the real fact table.
 * `ingest`, `backfill`, `status` and `metrics` previously returned canned
 * strings ("Ingestion job queued") without doing anything, so the UI reported
 * success while no data ever moved.
 */
class SearchConsoleController extends BaseApiController
{
    public function connections(): ResponseInterface
    {
        $tenantId = (int) ($this->request->getGet('tenant_id') ?? 1);

        try {
            $db = \Config\Database::connect();
            if (! SchemaGuard::hasTable($db, 'reach_analytics_connections')) {
                return $this->response->setJSON(['data' => []]);
            }

            $model       = new AnalyticsConnectionModel();
            $connections = $model->where('tenant_id', $tenantId)->where('provider', 'gsc')->findAll();
            $connections = is_array($connections) ? $connections : [];

            return $this->response->setJSON([
                'data' => array_map(static fn (array $c): array => $model->redactCredentials($c), $connections),
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'SearchConsoleController::connections: ' . $e->getMessage());
            return $this->response->setJSON(['data' => []]);
        }
    }

    public function createConnection(): ResponseInterface
    {
        $body = $this->request->getJSON(true) ?? [];

        try {
            $db = \Config\Database::connect();
            if (! SchemaGuard::hasTable($db, 'reach_analytics_connections')) {
                return $this->response->setStatusCode(503)->setJSON([
                    'error' => 'Connector storage is not available yet. Run database migrations first.',
                ]);
            }

            $siteProperty = trim((string) ($body['site_property'] ?? ''));
            $credential   = trim((string) ($body['credential_reference'] ?? ''));
            if ($siteProperty === '' || $credential === '') {
                return $this->response->setStatusCode(422)->setJSON([
                    'error' => 'site_property and credential_reference are required',
                ]);
            }

            $model = new AnalyticsConnectionModel();
            $id    = $model->insert([
                'tenant_id'            => (int) ($body['tenant_id'] ?? 1),
                'provider'             => 'gsc',
                'display_name'         => $body['display_name'] ?? 'Google Search Console',
                'site_property'        => $siteProperty,
                'credential_reference' => $credential,
                'enabled'              => array_key_exists('enabled', $body) ? (bool) $body['enabled'] : true,
                'health_status'        => $body['health_status'] ?? 'unknown',
            ]);
            if (! $id) {
                return $this->response->setStatusCode(422)->setJSON([
                    'error'  => 'Unable to create connection',
                    'errors' => $model->errors(),
                ]);
            }

            return $this->response->setStatusCode(201)->setJSON([
                'data' => $model->redactCredentials($model->find($id)),
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'SearchConsoleController::createConnection: ' . $e->getMessage());
            return $this->response->setStatusCode(500)->setJSON(['error' => 'Unable to create connection']);
        }
    }

    public function connection(int $id): ResponseInterface
    {
        $model = new AnalyticsConnectionModel();
        $conn  = $model->find($id);
        if (! $conn) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'Not found']);
        }

        return $this->response->setJSON(['data' => $model->redactCredentials($conn)]);
    }

    /**
     * Why the connector is (or is not) usable, in enough detail to fix it.
     *
     * Deliberately reports the credential *path* state and the service-account
     * client_email, never the key contents.
     */
    public function config(): ResponseInterface
    {
        $connector = ConnectorProviderFactory::searchConsole();

        $state = [
            'provider'          => $connector->providerName(),
            'enabled'           => $connector->isEnabled(),
            'site_property'     => $connector->siteProperty(),
            'blog_card_enabled' => filter_var(env('BLOG_SEARCH_CONSOLE_ENABLED', false), FILTER_VALIDATE_BOOL),
            'is_mock'           => ! ($connector instanceof GoogleSearchConsoleConnector),
        ];

        if ($connector instanceof GoogleSearchConsoleConnector) {
            $state += $connector->configurationState();
            $state['capabilities'] = $connector->getCapabilities();
        } else {
            $state['status'] = 'mock';
            $state['detail'] = 'SEARCH_CONSOLE_USE_MOCK is true — figures are simulated, not real.';
        }

        $state['next_step'] = match (true) {
            ! $state['enabled']                        => 'Set SEARCH_CONSOLE_ENABLED=true in server-php/.env and reload PHP.',
            ($state['status'] ?? '') === 'misconfigured' => (string) ($state['detail'] ?? ''),
            ! $state['blog_card_enabled']              => 'Set BLOG_SEARCH_CONSOLE_ENABLED=true so the Blog Command Centre card reflects live state.',
            default                                    => 'Run a health check, then ingest.',
        };

        return $this->response->setJSON(['data' => $state]);
    }

    /**
     * Properties the service account can actually read, straight from Google.
     */
    public function properties(): ResponseInterface
    {
        try {
            $connector  = ConnectorProviderFactory::searchConsole();
            $properties = $connector->getSiteProperties();

            return $this->response->setJSON(['data' => [
                'configured_property' => $connector->siteProperty(),
                'properties'          => $properties,
                'property_accessible' => in_array($connector->siteProperty(), $properties, true),
                'detail'              => $properties === []
                    ? 'Google returned no readable properties. Add the service account client_email as a user on the property in Search Console.'
                    : null,
            ]]);
        } catch (\Throwable $e) {
            log_message('error', 'SearchConsoleController::properties: ' . $e->getMessage());
            return $this->response->setStatusCode(502)->setJSON(['error' => $e->getMessage()]);
        }
    }

    public function healthCheck(int $id): ResponseInterface
    {
        try {
            return $this->response->setJSON(['data' => SearchConsoleService::make()->healthCheck($id)]);
        } catch (\Throwable $e) {
            log_message('error', 'SearchConsoleController::healthCheck: ' . $e->getMessage());
            return $this->response->setStatusCode(422)->setJSON(['error' => $e->getMessage()]);
        }
    }

    /**
     * Run the incremental window synchronously and return what actually landed.
     */
    public function ingest(int $id): ResponseInterface
    {
        try {
            $result = SearchConsoleService::make()->ingestIncremental($id);

            $result['message'] = sprintf(
                'Ingested %d row(s), skipped %d unmapped, over %s..%s.',
                $result['ingested'],
                $result['skipped'],
                $result['date_from'],
                $result['date_to'],
            );

            return $this->response->setJSON(['data' => $result]);
        } catch (\Throwable $e) {
            log_message('error', 'SearchConsoleController::ingest: ' . $e->getMessage());
            return $this->response->setStatusCode(422)->setJSON(['error' => $e->getMessage()]);
        }
    }

    /**
     * Register a backfill and process its first slice immediately, so the caller
     * gets real evidence rather than a promise. Remaining slices are picked up
     * by reach.search_console_ingest.
     */
    public function backfill(int $id): ResponseInterface
    {
        $days = (int) (($this->request->getJSON(true)['days'] ?? null) ?? 90);

        try {
            $service = SearchConsoleService::make();
            $queued  = $service->initiateBackfill($id, $days);
            $first   = $service->runBackfillChunk($id);

            return $this->response->setJSON(['data' => [
                'backfill'    => $queued,
                'first_chunk' => $first,
                'message'     => sprintf(
                    'Backfill of %d day(s) started from %s; first slice ingested %d row(s).',
                    $queued['days'],
                    $queued['from'],
                    (int) ($first['ingested'] ?? 0),
                ),
            ]]);
        } catch (\Throwable $e) {
            log_message('error', 'SearchConsoleController::backfill: ' . $e->getMessage());
            return $this->response->setStatusCode(422)->setJSON(['error' => $e->getMessage()]);
        }
    }

    public function status(int $id): ResponseInterface
    {
        try {
            return $this->response->setJSON(['data' => SearchConsoleService::make()->connectionStatus($id)]);
        } catch (\Throwable $e) {
            log_message('error', 'SearchConsoleController::status: ' . $e->getMessage());
            return $this->response->setStatusCode(404)->setJSON(['error' => $e->getMessage()]);
        }
    }

    /**
     * Ingested Search Console facts, aggregated over a date range.
     *
     * ?dimension=query|page|date|device|country  (default query)
     * ?from&to    ISO dates (default: last 28 days)
     * ?limit      1..1000 (default 100)
     */
    public function metrics(): ResponseInterface
    {
        $from      = $this->validDate($this->request->getGet('from'), date('Y-m-d', strtotime('-28 days')));
        $to        = $this->validDate($this->request->getGet('to'), date('Y-m-d'));
        $dimension = (string) ($this->request->getGet('dimension') ?? 'query');
        $limit     = (int) ($this->request->getGet('limit') ?? 100);

        if ($from > $to) {
            return $this->response->setStatusCode(422)->setJSON(['error' => '`from` must not be after `to`.']);
        }

        try {
            $db = \Config\Database::connect();
            if (! $db->tableExists('reach_search_metric_facts')) {
                return $this->response->setJSON(['data' => [
                    'rows'    => [],
                    'totals'  => null,
                    'period'  => ['from' => $from, 'to' => $to, 'dimension' => $dimension],
                    'message' => 'Search metric storage is not migrated yet.',
                ]]);
            }

            $model = new SearchMetricFactModel();

            return $this->response->setJSON(['data' => [
                'rows'   => $model->aggregate($dimension, $from, $to, $limit),
                'totals' => $model->totals($from, $to),
                'period' => ['from' => $from, 'to' => $to, 'dimension' => $dimension],
            ]]);
        } catch (\Throwable $e) {
            log_message('error', 'SearchConsoleController::metrics: ' . $e->getMessage());
            return $this->response->setStatusCode(500)->setJSON(['error' => 'Unable to read search metrics']);
        }
    }

    /**
     * Ingestion run history — the audit trail for what was pulled and when.
     */
    public function runs(): ResponseInterface
    {
        $limit = min(100, max(1, (int) ($this->request->getGet('limit') ?? 20)));

        try {
            $db = \Config\Database::connect();
            if (! $db->tableExists('reach_analytics_ingestion_runs')) {
                return $this->response->setJSON(['data' => []]);
            }

            $builder = (new IngestionRunModel())->orderBy('id', 'DESC');
            $connectionId = (int) ($this->request->getGet('connection_id') ?? 0);
            if ($connectionId > 0) {
                $builder->where('connection_id', $connectionId);
            }

            return $this->response->setJSON(['data' => $builder->findAll($limit)]);
        } catch (\Throwable $e) {
            log_message('error', 'SearchConsoleController::runs: ' . $e->getMessage());
            return $this->response->setJSON(['data' => []]);
        }
    }

    /**
     * Live URLs that Search Console reports on but that no content identity
     * claims. These are exactly the rows being dropped from the facts table.
     */
    public function unmapped(): ResponseInterface
    {
        try {
            $db = \Config\Database::connect();
            if (! $db->tableExists('reach_content_mapping_findings')) {
                return $this->response->setJSON(['data' => []]);
            }

            $builder = (new ContentMappingFindingModel())
                ->where('resolution_status', 'unresolved')
                ->orderBy('id', 'DESC');

            $connectionId = (int) ($this->request->getGet('connection_id') ?? 0);
            if ($connectionId > 0) {
                $builder->where('connection_id', $connectionId);
            }

            return $this->response->setJSON(['data' => $builder->findAll(200)]);
        } catch (\Throwable $e) {
            log_message('error', 'SearchConsoleController::unmapped: ' . $e->getMessage());
            return $this->response->setJSON(['data' => []]);
        }
    }

    /**
     * Refresh content identities from what is live, so newly published posts
     * start collecting search facts without waiting for the nightly job.
     */
    public function syncIdentities(): ResponseInterface
    {
        $tenantId = (int) (($this->request->getJSON(true)['tenant_id'] ?? null) ?? 1);

        try {
            $result = (new ContentIdentitySyncService())->syncBlogs($tenantId);

            $result['message'] = sprintf(
                '%d live post(s) scanned: %d created, %d updated, %d without a resolvable URL.',
                $result['scanned'],
                $result['created'],
                $result['updated'],
                $result['without_url'],
            );

            return $this->response->setJSON(['data' => $result]);
        } catch (\Throwable $e) {
            log_message('error', 'SearchConsoleController::syncIdentities: ' . $e->getMessage());
            return $this->response->setStatusCode(500)->setJSON(['error' => $e->getMessage()]);
        }
    }

    private function validDate(mixed $value, string $fallback): string
    {
        $value = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : $fallback;
    }
}
