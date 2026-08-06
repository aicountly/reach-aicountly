<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Intelligence\ContentIdentitySyncService;
use App\Libraries\Intelligence\Connectors\ConnectorProviderFactory;
use App\Libraries\Intelligence\Connectors\GoogleSearchConsoleConnector;
use App\Libraries\Intelligence\SearchConsoleService;
use App\Models\Intelligence\AnalyticsConnectionModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * `php spark reach:search-console <action>`
 *
 * Operator entry point for the Google Search Console connector. Everything it
 * reports comes from the live API or the database — no action ever invents a
 * figure, and `doctor` is safe to run on production at any time.
 *
 *   doctor              credential/property diagnosis, no writes
 *   properties          properties the service account can actually read
 *   register            create/refresh the stored connection from env values
 *   health [id]         live authenticated check, persisted on the connection
 *   sync-identities     map live blog URLs so ingested rows can attach
 *   ingest [id]         run the incremental window now
 *   backfill [id] [--days=90]   queue historical backfill
 *   status [id]         cursor, last run and health
 *   unmapped            URLs Google reports that no content claims, beside the
 *                       canonical URLs Reach holds — the two lists side by side
 *                       are what diagnoses a zero-ingest run
 */
class ReachSearchConsole extends BaseCommand
{
    protected $group       = 'Reach';
    protected $name        = 'reach:search-console';
    protected $description = 'Diagnose, register and run the Google Search Console connector.';
    protected $usage       = 'reach:search-console <doctor|properties|register|health|sync-identities|ingest|backfill|status> [connection_id] [--days=90] [--tenant=1]';

    public function run(array $params): int
    {
        $action   = strtolower(trim((string) ($params[0] ?? 'doctor')));
        $argument = isset($params[1]) ? (int) $params[1] : 0;
        $tenantId = (int) (CLI::getOption('tenant') ?: 1);

        try {
            $payload = match ($action) {
                'doctor'          => $this->doctor(),
                'properties'      => $this->properties(),
                'register'        => $this->register($tenantId),
                'health'          => SearchConsoleService::make()->healthCheck($this->resolveConnection($argument, $tenantId)),
                'sync-identities' => (new ContentIdentitySyncService())->syncBlogs($tenantId),
                'ingest'          => SearchConsoleService::make()->ingestIncremental($this->resolveConnection($argument, $tenantId)),
                'backfill'        => SearchConsoleService::make()->initiateBackfill(
                    $this->resolveConnection($argument, $tenantId),
                    (int) (CLI::getOption('days') ?: 90),
                ),
                'status'          => SearchConsoleService::make()->connectionStatus($this->resolveConnection($argument, $tenantId)),
                'unmapped'        => $this->unmapped($tenantId),
                default           => throw new \InvalidArgumentException("Unknown action '{$action}'. See: {$this->usage}"),
            };

            CLI::write(json_encode(
                ['action' => $action, 'ts' => gmdate('c'), 'result' => $payload],
                JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
            ));

            return 0;
        } catch (\Throwable $e) {
            CLI::error('search-console ' . $action . ' failed: ' . $e->getMessage());

            return 1;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function doctor(): array
    {
        $connector = ConnectorProviderFactory::searchConsole();

        $report = [
            'provider'                    => $connector->providerName(),
            'connector_enabled'           => $connector->isEnabled(),
            'blog_card_flag'              => filter_var(env('BLOG_SEARCH_CONSOLE_ENABLED', false), FILTER_VALIDATE_BOOL),
            'site_property'               => $connector->siteProperty(),
        ];

        if ($connector instanceof GoogleSearchConsoleConnector) {
            $report += $connector->configurationState();
        } else {
            $report['status'] = 'mock';
            $report['detail'] = 'SEARCH_CONSOLE_USE_MOCK is true — figures would not be real.';
        }

        $report['next_step'] = match (true) {
            ! $report['connector_enabled']      => 'Set SEARCH_CONSOLE_ENABLED=true in server-php/.env.',
            ($report['status'] ?? '') !== 'configured' => (string) ($report['detail'] ?? 'Fix the credential configuration.'),
            ! $report['blog_card_flag']         => 'Set BLOG_SEARCH_CONSOLE_ENABLED=true so the Blog Command Centre card reports live state.',
            default                             => 'Run: php spark reach:search-console health',
        };

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    private function properties(): array
    {
        $connector  = ConnectorProviderFactory::searchConsole();
        $properties = $connector->getSiteProperties();

        return [
            'configured_property' => $connector->siteProperty(),
            'readable_properties' => $properties,
            'property_accessible' => in_array($connector->siteProperty(), $properties, true),
            'hint'                => $properties === []
                ? 'Google returned no properties. Add the service account client_email as a user on the Search Console property.'
                : null,
        ];
    }

    /**
     * Create or refresh the stored connection from the env configuration, so an
     * operator does not have to hand-craft a row before the first ingest.
     *
     * @return array<string, mixed>
     */
    private function register(int $tenantId): array
    {
        $connector = ConnectorProviderFactory::searchConsole();
        $property  = $connector->siteProperty();

        if ($property === '') {
            throw new \RuntimeException('SEARCH_CONSOLE_SITE_PROPERTY is not set; nothing to register.');
        }

        $model    = new AnalyticsConnectionModel();
        $existing = $model->where('tenant_id', $tenantId)
            ->where('provider', 'gsc')
            ->where('site_property', $property)
            ->first();

        $payload = [
            'tenant_id'            => $tenantId,
            'provider'             => 'gsc',
            'display_name'         => 'Google Search Console',
            'site_property'        => $property,
            // An env var name, never the key itself.
            'credential_reference' => 'SEARCH_CONSOLE_SERVICE_ACCOUNT_KEY_PATH',
            'enabled'              => true,
            'enabled_at'           => date('Y-m-d H:i:s'),
            'disabled_at'          => null,
        ];

        if ($existing) {
            $model->update((int) $existing['id'], $payload);
            $id = (int) $existing['id'];
        } else {
            $id = (int) $model->insert($payload);
        }

        return ['connection_id' => $id, 'site_property' => $property, 'created' => ! $existing];
    }

    /**
     * The two lists that explain a zero-ingest run: what Google reported and
     * could not be placed, against what Reach believes each post's URL is.
     *
     * @return array<string, mixed>
     */
    private function unmapped(int $tenantId): array
    {
        $db = \Config\Database::connect();

        $findings = [];
        if ($db->tableExists('reach_content_mapping_findings', false)) {
            $findings = array_column($db->table('reach_content_mapping_findings')
                ->select('unmapped_url')
                ->where('resolution_status', 'unresolved')
                ->orderBy('id', 'DESC')
                ->limit(50)
                ->get()->getResultArray(), 'unmapped_url');
        }

        $identities = [];
        if ($db->tableExists('reach_content_identities', false)) {
            $identities = array_column($db->table('reach_content_identities')
                ->select('canonical_url')
                ->where('tenant_id', $tenantId)
                ->where('canonical_url IS NOT NULL', null, false)
                ->orderBy('id', 'ASC')
                ->limit(50)
                ->get()->getResultArray(), 'canonical_url');
        }

        $slugIndex = (new ContentIdentitySyncService())->slugIndex($tenantId);
        $unmatched = [];
        foreach ($findings as $url) {
            $slug = ContentIdentitySyncService::slugOf((string) $url);
            $unmatched[] = [
                'url'        => $url,
                'slug'       => $slug,
                'slug_known' => isset($slugIndex[$slug]),
            ];
        }

        return [
            'reported_but_unclaimed' => $unmatched,
            'canonical_urls_on_file' => $identities,
            'hint' => $findings === []
                ? null
                : 'Compare the two lists. A matching slug under a different path prefix means the '
                    . 'stored canonical URL is wrong (the public site 301s /blog/ to /blogs/); ingestion '
                    . 'will still attribute those rows by slug.',
        ];
    }

    private function resolveConnection(int $connectionId, int $tenantId): int
    {
        if ($connectionId > 0) {
            return $connectionId;
        }

        $connection = (new AnalyticsConnectionModel())
            ->where('tenant_id', $tenantId)
            ->where('provider', 'gsc')
            ->where('enabled', true)
            ->orderBy('id', 'ASC')
            ->first();

        if (! $connection) {
            throw new \RuntimeException(
                'No enabled Search Console connection for tenant ' . $tenantId
                . '. Run: php spark reach:search-console register'
            );
        }

        return (int) $connection['id'];
    }
}
