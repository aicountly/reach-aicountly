<?php

declare(strict_types=1);

namespace App\Libraries;


/**
 * GA4 traffic reports for Reach Marketing Analytics (ported from Flow).
 */
final class TrafficAnalyticsService
{
    private const CACHE_TTL_SECONDS = 3600;

    private const LEAD_EVENTS = [
        'lead_form_submit',
        'blog_lead_submit',
        'blog_cta_click',
        'pricing_view',
        'signup_click',
    ];

    private AnalyticsCache $cache;

    public function __construct()
    {
        $this->cache = new AnalyticsCache();
    }

    public function overview(int $days, string $stream): array
    {
        $cacheKey   = 'traffic_overview_v3';
        $paramsHash = md5("days={$days}&stream={$stream}");
        $cached     = $this->cache->get($cacheKey, $paramsHash);
        if ($cached !== null && empty($cached['_demo']) && empty($cached['_unconfigured'])) {
            return ['data' => $cached, 'demo' => false, 'unconfigured' => false, 'cached' => true];
        }

        if ($this->isCombinedStream($stream)) {
            $result = $this->fetchCombinedOverview($days);
            if ($result === null) {
                $empty = $this->emptyOverview($days, $stream, $this->streamFailureReason($stream));

                return ['data' => $empty, 'demo' => false, 'unconfigured' => true, 'cached' => false];
            }
        } else {
            $setupReason = $this->saasStreamSetupReason($stream);
            if ($setupReason !== null) {
                $empty = $this->emptyOverview($days, $stream, $setupReason);

                return ['data' => $empty, 'demo' => false, 'unconfigured' => true, 'cached' => false];
            }
            $result = $this->fetchOverviewForStream($stream, $days);
            if ($result === null) {
                $empty = $this->emptyOverview($days, $stream, $this->streamFailureReason($stream));

                return ['data' => $empty, 'demo' => false, 'unconfigured' => true, 'cached' => false];
            }
        }

        $this->cache->set($cacheKey, $paramsHash, $result, self::CACHE_TTL_SECONDS);

        return ['data' => $result, 'demo' => false, 'unconfigured' => false, 'cached' => false];
    }

    public function sources(int $days, string $stream): array
    {
        $cacheKey   = 'traffic_sources_v3';
        $paramsHash = md5("days={$days}&stream={$stream}");
        $cached     = $this->cache->get($cacheKey, $paramsHash);
        if ($cached !== null && empty($cached['_demo']) && empty($cached['_unconfigured'])) {
            return ['data' => $cached, 'demo' => false, 'unconfigured' => false, 'cached' => true];
        }

        if ($this->isCombinedStream($stream)) {
            $result = $this->fetchCombinedSources($days);
            if ($result === null) {
                $empty = $this->emptySources($stream, $this->streamFailureReason($stream));

                return ['data' => $empty, 'demo' => false, 'unconfigured' => true, 'cached' => false];
            }
        } else {
            $setupReason = $this->saasStreamSetupReason($stream);
            if ($setupReason !== null) {
                $empty = $this->emptySources($stream, $setupReason);

                return ['data' => $empty, 'demo' => false, 'unconfigured' => true, 'cached' => false];
            }
            $result = $this->fetchSourcesForStream($stream, $days);
            if ($result === null) {
                $empty = $this->emptySources($stream, $this->streamFailureReason($stream));

                return ['data' => $empty, 'demo' => false, 'unconfigured' => true, 'cached' => false];
            }
        }

        $this->cache->set($cacheKey, $paramsHash, $result, self::CACHE_TTL_SECONDS);

        return ['data' => $result, 'demo' => false, 'unconfigured' => false, 'cached' => false];
    }

    public function leads(int $days, string $stream): array
    {
        $cacheKey   = 'traffic_leads_v3';
        $paramsHash = md5("days={$days}&stream={$stream}");
        $cached     = $this->cache->get($cacheKey, $paramsHash);
        if ($cached !== null && empty($cached['_demo']) && empty($cached['_unconfigured'])) {
            return ['data' => $cached, 'demo' => false, 'unconfigured' => false, 'cached' => true];
        }

        $events = self::LEAD_EVENTS;

        if ($this->isCombinedStream($stream)) {
            $result = $this->fetchCombinedLeads($days, $events);
            if ($result === null) {
                $empty = $this->emptyLeads($stream, $this->streamFailureReason($stream));

                return ['data' => $empty, 'demo' => false, 'unconfigured' => true, 'cached' => false];
            }
        } else {
            $setupReason = $this->saasStreamSetupReason($stream);
            if ($setupReason !== null) {
                $empty = $this->emptyLeads($stream, $setupReason);

                return ['data' => $empty, 'demo' => false, 'unconfigured' => true, 'cached' => false];
            }
            $result = $this->fetchLeadsForStream($stream, $days, $events);
            if ($result === null) {
                $empty = $this->emptyLeads($stream, $this->streamFailureReason($stream));

                return ['data' => $empty, 'demo' => false, 'unconfigured' => true, 'cached' => false];
            }
        }

        $this->cache->set($cacheKey, $paramsHash, $result, self::CACHE_TTL_SECONDS);

        return ['data' => $result, 'demo' => false, 'unconfigured' => false, 'cached' => false];
    }

    public function configStatus(): array
    {
        $streams = $this->buildStreamStatusList();
        $liveStreams = array_values(array_filter(
            $streams,
            static fn (array $s): bool => ($s['api_ok'] ?? false) === true,
        ));

        $marketingId  = trim((string) (env('GA4_PROPERTY_ID_MARKETING') ?? ''));
        $portalId     = trim((string) (env('GA4_PROPERTY_ID_REACH') ?? env('GA4_PROPERTY_ID_PORTAL') ?? ''));
        $saasId       = trim((string) (env('GA4_PROPERTY_ID_SAAS') ?? ''));
        $marketingKey = trim((string) (env('GOOGLE_SERVICE_ACCOUNT_JSON_MARKETING') ?? ''));
        $portalKey    = trim((string) (env('GOOGLE_SERVICE_ACCOUNT_JSON_REACH') ?? env('GOOGLE_SERVICE_ACCOUNT_JSON_PORTAL') ?? ''));
        $saasKey      = trim((string) (env('GOOGLE_SERVICE_ACCOUNT_JSON_SAAS') ?? ''));

        $marketingSa = Ga4AnalyticsClient::inspectServiceAccountKey($marketingKey);
        $portalSa    = Ga4AnalyticsClient::inspectServiceAccountKey($portalKey);
        $saasSa      = Ga4AnalyticsClient::inspectServiceAccountKey($saasKey);

        $marketingApiOk = $this->streamApiAccess('marketing_site');
        $portalApiOk    = $this->streamApiAccess('portal');
        $saasApiOk      = $this->streamApiAccess('saas:smart_books') ?? $this->streamApiAccess('saas:hrms');

        $anyPropertyConfigured = $marketingId !== '' || $portalId !== '' || $saasId !== ''
            || count(array_filter($streams, static fn (array $s): bool => ($s['property_id'] ?? '') !== '')) > 0;
        $anyKeyConfigured = $marketingSa['path_configured'] || $portalSa['path_configured'] || $saasSa['path_configured']
            || count(array_filter($streams, static fn (array $s): bool => ($s['key_readable'] ?? false) === true)) > 0;

        $checklist = [
            [
                'id'    => 'ga4_properties',
                'label' => 'Set GA4_PROPERTY_ID_* in server-php/.env (marketing, portal, SaaS hub, or per-product SAAS_*)',
                'done'  => $anyPropertyConfigured,
            ],
            [
                'id'    => 'service_account_viewer',
                'label' => 'Grant each service account Viewer on its GA4 property + enable Analytics Data API',
                'done'  => count($liveStreams) > 0,
            ],
            [
                'id'    => 'backend_env',
                'label' => 'Set GOOGLE_SERVICE_ACCOUNT_JSON_* paths readable by PHP on the server',
                'done'  => $anyKeyConfigured && count(array_filter(
                    $streams,
                    static fn (array $s): bool => ($s['property_id'] ?? '') !== '' && ($s['key_readable'] ?? false),
                )) > 0,
            ],
            [
                'id'            => 'frontend_gtag',
                'label'         => 'Add gtag to your sites so GA4 receives traffic (VITE_GA4_* on each React app)',
                'done'          => false,
                'informational' => true,
                'note'          => 'Backend can read GA4 before gtag is live; metrics may show 0 until tags fire.',
            ],
        ];

        $defaultStream = $liveStreams[0]['id'] ?? 'marketing_site';

        return [
            'ready'           => count($liveStreams) > 0,
            'default_stream'  => $defaultStream,
            'live_streams'    => array_map(static fn (array $s): string => (string) $s['id'], $liveStreams),
            'streams'         => $streams,
            'property_marketing' => $marketingId !== '' ? $marketingId : null,
            'property_portal'    => $portalId !== '' ? $portalId : null,
            'property_saas'      => $saasId !== '' ? $saasId : null,
            'service_accounts'   => [
                'marketing' => $marketingSa,
                'portal'    => $portalSa,
                'saas'      => $saasSa,
            ],
            'api_access' => [
                'marketing' => $marketingApiOk,
                'portal'    => $portalApiOk,
                'saas'      => $saasApiOk,
            ],
            'checklist' => $checklist,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function buildStreamStatusList(): array
    {
        $list = [];
        foreach ($this->dataStreams() as $streamId) {
            $propertyId = $this->resolvePropertyId($streamId);
            $keyPath    = $this->resolveServiceAccountPath($streamId);
            $keyInfo    = Ga4AnalyticsClient::inspectServiceAccountKey($keyPath);
            $apiOk      = $this->streamApiAccess($streamId);

            $label = match ($this->normalizeStream($streamId)) {
                'marketing_site' => 'Marketing site',
                'portal'         => 'Reach portal',
                default          => str_starts_with($streamId, 'saas:')
                    ? SaasProductTaxonomy::labelProduct(substr($streamId, 5))
                    : $streamId,
            };

            $productSlug = $this->parseSaasProductSlug($streamId);

            $list[] = [
                'id'          => $streamId,
                'label'       => $label,
                'property_id' => $propertyId !== '' ? $propertyId : null,
                'key_path'    => $keyPath !== '' ? $keyPath : null,
                'key_readable'=> $keyInfo['file_readable'],
                'service_account_email' => $keyInfo['email'],
                'api_ok'      => $apiOk === true,
                'dedicated_property' => $productSlug !== null && $this->hasDedicatedSaasProductProperty($productSlug),
                'path_filter_active' => $this->ga4PagePathFilterForStream($streamId) !== null,
                'requires_dedicated_property' => $productSlug !== null && SaasProductTaxonomy::requiresDedicatedGa4Property($productSlug),
            ];
        }

        return $list;
    }

    public function streamApiAccess(string $stream): ?bool
    {
        $propertyId = $this->resolvePropertyId($stream);
        if ($propertyId === '') {
            return null;
        }

        $token = $this->accessTokenForStream($stream);
        if ($token === null) {
            return false;
        }

        return Ga4AnalyticsClient::testPropertyAccess($token, $propertyId);
    }

    private function streamFailureReason(string $stream): string
    {
        if ($this->isCombinedStream($stream)) {
            $live = array_filter(
                $this->buildStreamStatusList(),
                static fn (array $s): bool => ($s['api_ok'] ?? false) === true,
            );
            if ($live !== []) {
                return 'No combined data available.';
            }

            return 'No GA4 streams are configured. Set GA4_PROPERTY_ID_* and GOOGLE_SERVICE_ACCOUNT_JSON_* in server-php/.env, grant Viewer access, then pick a configured stream.';
        }

        $propertyId = $this->resolvePropertyId($stream);
        if ($propertyId === '') {
            return 'GA4 property ID is missing in server-php/.env for this stream.';
        }

        $keyPath = $this->resolveServiceAccountPath($stream);
        $keyInfo = Ga4AnalyticsClient::inspectServiceAccountKey($keyPath);
        if (!$keyInfo['file_readable']) {
            return 'Service account JSON path is missing or not readable for this stream.';
        }
        if (!$keyInfo['auth_ok']) {
            return 'Service account JSON is invalid or OAuth token failed.';
        }

        $apiOk = $this->streamApiAccess($stream);
        if ($apiOk === false) {
            return 'GA4 Data API denied access — add the service account as Viewer on property '
                . $propertyId . ' and enable Google Analytics Data API.';
        }

        return 'GA4 returned no data for this stream.';
    }

    private function emptyOverview(int $days, string $stream, string $reason): array
    {
        return [
            'trend'          => [],
            'totals'         => [
                'sessions'    => 0,
                'users'       => 0,
                'pageviews'   => 0,
                'new_users'   => 0,
                'bounce_rate' => 0,
            ],
            'top_pages'      => [],
            'days'           => $days,
            '_unconfigured'  => true,
            '_reason'        => $reason,
            '_stream'        => $stream,
        ];
    }

    /** @return array<string, mixed> */
    private function emptySources(string $stream, string $reason): array
    {
        return [
            'channels'       => [],
            'total_sessions' => 0,
            '_unconfigured'  => true,
            '_reason'        => $reason,
            '_stream'        => $stream,
        ];
    }

    /** @return array<string, mixed> */
    private function emptyLeads(string $stream, string $reason): array
    {
        $counts = array_fill_keys(self::LEAD_EVENTS, 0);
        $counts['_unconfigured'] = true;
        $counts['_reason']       = $reason;
        $counts['_stream']       = $stream;

        return $counts;
    }

    private function isCombinedStream(string $stream): bool
    {
        return strtolower(trim($stream)) === 'all';
    }

    /** @return list<string> */
    private function dataStreams(): array
    {
        $streams = ['marketing_site', 'portal'];
        foreach (array_keys(SaasProductTaxonomy::trafficAnalyticsSaasProducts()) as $slug) {
            $streams[] = 'saas:' . $slug;
        }

        return $streams;
    }

    private function marketingSiteUrl(): string
    {
        return rtrim(trim((string) (env('AICOUNTLY_SITE_URL') ?? 'https://www.aicountly.com')), '/');
    }

    private function portalSiteUrl(): string
    {
        return rtrim(trim((string) (env('REACH_APP_URL') ?? 'https://reach.aicountly.org')), '/');
    }

    private function saasPortalSiteUrl(): string
    {
        return rtrim(trim((string) (env('AIC_GLOBAL_URL') ?? 'https://my.aicountly.com')), '/');
    }

    private function parseSaasProductSlug(string $stream): ?string
    {
        $stream = strtolower(trim($stream));
        if (!str_starts_with($stream, 'saas:')) {
            return null;
        }

        $slug = SaasProductTaxonomy::normalizeProductSlug(substr($stream, 5));
        if ($slug === '' || $slug === 'flow' || !SaasProductTaxonomy::validateProduct($slug)) {
            return null;
        }

        return $slug;
    }

    private function hasDedicatedSaasProductProperty(string $productSlug): bool
    {
        $key = SaasProductTaxonomy::ga4PropertyEnvKeyForSaasProduct($productSlug);

        return trim((string) (env($key) ?? '')) !== '';
    }

    /** Warn when a subdomain product is missing its own GA4 property on Flow. */
    private function saasStreamSetupReason(string $stream): ?string
    {
        $productSlug = $this->parseSaasProductSlug($stream);
        if ($productSlug === null || !SaasProductTaxonomy::requiresDedicatedGa4Property($productSlug)) {
            return null;
        }

        if ($this->hasDedicatedSaasProductProperty($productSlug)) {
            return null;
        }

        $envKey = SaasProductTaxonomy::ga4PropertyEnvKeyForSaasProduct($productSlug);
        $site   = SaasProductTaxonomy::productDedicatedSiteUrl($productSlug) ?? 'its own subdomain';

        return 'Set ' . $envKey . ' in server-php/.env to the numeric GA4 property for '
            . $site . '. This product uses its own domain and gtag stream — the shared SaaS hub property '
            . 'with /' . str_replace('_', '-', $productSlug) . ' path filters will not match its page paths.';
    }

    private function resolveSaasPropertyId(?string $productSlug): string
    {
        if ($productSlug !== null && $productSlug !== '') {
            $dedicatedKey = SaasProductTaxonomy::ga4PropertyEnvKeyForSaasProduct($productSlug);
            $dedicated    = trim((string) (env($dedicatedKey) ?? ''));
            if ($dedicated !== '') {
                return $dedicated;
            }
        }

        return trim((string) (env('GA4_PROPERTY_ID_SAAS') ?? ''));
    }

    private function resolveSaasServiceAccountPath(?string $productSlug): string
    {
        if ($productSlug !== null && $productSlug !== '') {
            $dedicatedKey = SaasProductTaxonomy::ga4ServiceAccountEnvKeyForSaasProduct($productSlug);
            $dedicated    = trim((string) (env($dedicatedKey) ?? ''));
            if ($dedicated !== '') {
                return $dedicated;
            }
        }

        return trim((string) (env('GOOGLE_SERVICE_ACCOUNT_JSON_SAAS') ?? ''));
    }

    /** @return array<string, mixed>|null */
    private function ga4PagePathFilterForStream(string $stream): ?array
    {
        $productSlug = $this->parseSaasProductSlug($stream);
        if ($productSlug === null) {
            return null;
        }

        if ($this->hasDedicatedSaasProductProperty($productSlug)) {
            return null;
        }

        $prefixes = SaasProductTaxonomy::productPortalPathPrefixes($productSlug);
        if ($prefixes === []) {
            return null;
        }

        if (count($prefixes) === 1) {
            return [
                'filter' => [
                    'fieldName'    => 'pagePath',
                    'stringFilter' => [
                        'matchType' => 'BEGINS_WITH',
                        'value'     => $prefixes[0],
                    ],
                ],
            ];
        }

        return [
            'orGroup' => [
                'expressions' => array_map(static fn (string $prefix): array => [
                    'filter' => [
                        'fieldName'    => 'pagePath',
                        'stringFilter' => [
                            'matchType' => 'BEGINS_WITH',
                            'value'     => $prefix,
                        ],
                    ],
                ], $prefixes),
            ],
        ];
    }

    /** @param array<string, mixed> ...$filters */
    private function mergeDimensionFilters(array ...$filters): ?array
    {
        $expressions = [];
        foreach ($filters as $filter) {
            if ($filter !== []) {
                $expressions[] = $filter;
            }
        }

        if ($expressions === []) {
            return null;
        }
        if (count($expressions) === 1) {
            return $expressions[0];
        }

        return ['andGroup' => ['expressions' => $expressions]];
    }

    private function usesSaasProperty(string $stream): bool
    {
        return $this->parseSaasProductSlug($stream) !== null;
    }

    private function normalizeStream(string $stream): string
    {
        $stream = strtolower(trim($stream));

        if ($this->parseSaasProductSlug($stream) !== null) {
            return $stream;
        }

        if ($stream === 'portal' || $stream === 'app' || $stream === 'flow' || $stream === 'reach') {
            return 'portal';
        }
        if ($stream === 'marketing' || $stream === 'marketing_site' || $stream === 'website') {
            return 'marketing_site';
        }

        return $stream;
    }

    private function siteUrlForStream(string $stream): string
    {
        $productSlug = $this->parseSaasProductSlug($stream);
        if ($productSlug !== null) {
            $dedicated = SaasProductTaxonomy::productDedicatedSiteUrl($productSlug);
            if ($dedicated !== null) {
                return rtrim($dedicated, '/');
            }

            return $this->saasPortalSiteUrl();
        }

        return match ($this->normalizeStream($stream)) {
            'portal' => $this->portalSiteUrl(),
            default  => $this->marketingSiteUrl(),
        };
    }

    private function resolvePropertyId(string $stream): string
    {
        if ($this->usesSaasProperty($stream)) {
            return $this->resolveSaasPropertyId($this->parseSaasProductSlug($stream));
        }

        return match ($this->normalizeStream($stream)) {
            'portal' => trim((string) (env('GA4_PROPERTY_ID_REACH') ?? env('GA4_PROPERTY_ID_PORTAL') ?? '')),
            default  => trim((string) (env('GA4_PROPERTY_ID_MARKETING') ?? '')),
        };
    }

    private function resolveServiceAccountPath(string $stream): string
    {
        if ($this->usesSaasProperty($stream)) {
            return $this->resolveSaasServiceAccountPath($this->parseSaasProductSlug($stream));
        }

        return match ($this->normalizeStream($stream)) {
            'portal' => trim((string) (env('GOOGLE_SERVICE_ACCOUNT_JSON_REACH') ?? env('GOOGLE_SERVICE_ACCOUNT_JSON_PORTAL') ?? '')),
            default  => trim((string) (env('GOOGLE_SERVICE_ACCOUNT_JSON_MARKETING') ?? '')),
        };
    }

    private function accessTokenForStream(string $stream): ?string
    {
        return Ga4AnalyticsClient::getAccessTokenFromPath($this->resolveServiceAccountPath($stream));
    }

    private function fetchOverviewForStream(string $stream, int $days): ?array
    {
        $propertyId = $this->resolvePropertyId($stream);
        if ($propertyId === '') {
            return null;
        }

        $token = $this->accessTokenForStream($stream);
        if ($token === null) {
            return null;
        }

        $startDate = date('Y-m-d', strtotime("-{$days} days"));
        $site      = $this->siteUrlForStream($stream);
        $pathFilter = $this->ga4PagePathFilterForStream($stream);

        $trendBody = [
            'dateRanges' => [['startDate' => $startDate, 'endDate' => 'today']],
            'dimensions' => [['name' => 'date']],
            'metrics'    => [
                ['name' => 'sessions'],
                ['name' => 'activeUsers'],
                ['name' => 'screenPageViews'],
                ['name' => 'newUsers'],
            ],
            'orderBys' => [['dimension' => ['dimensionName' => 'date']]],
        ];
        if ($pathFilter !== null) {
            $trendBody['dimensionFilter'] = $pathFilter;
        }

        $trendReport = Ga4AnalyticsClient::runReport($token, $propertyId, $trendBody);

        $totalsBody = [
            'dateRanges'         => [['startDate' => $startDate, 'endDate' => 'today']],
            'metrics'            => [
                ['name' => 'sessions'],
                ['name' => 'activeUsers'],
                ['name' => 'screenPageViews'],
                ['name' => 'newUsers'],
                ['name' => 'bounceRate'],
            ],
            'metricAggregations' => ['TOTAL'],
        ];
        if ($pathFilter !== null) {
            $totalsBody['dimensionFilter'] = $pathFilter;
        }

        $totalsReport = Ga4AnalyticsClient::runReport($token, $propertyId, $totalsBody);

        $pagesBody = [
            'dateRanges' => [['startDate' => $startDate, 'endDate' => 'today']],
            'dimensions' => [['name' => 'pagePath'], ['name' => 'pageTitle']],
            'metrics'    => [['name' => 'screenPageViews'], ['name' => 'activeUsers']],
            'orderBys'   => [['metric' => ['metricName' => 'screenPageViews'], 'desc' => true]],
            'limit'      => 10,
        ];
        if ($pathFilter !== null) {
            $pagesBody['dimensionFilter'] = $pathFilter;
        }

        $pagesReport = Ga4AnalyticsClient::runReport($token, $propertyId, $pagesBody);

        if ($trendReport === null && $totalsReport === null) {
            return null;
        }

        return [
            'trend'     => $this->parseTrendRows($trendReport ?? []),
            'totals'    => $this->parseTotalsRow($totalsReport ?? []),
            'top_pages' => $this->parsePageRows($pagesReport ?? [], $site),
            'days'      => $days,
        ];
    }

    private function fetchSourcesForStream(string $stream, int $days): ?array
    {
        $propertyId = $this->resolvePropertyId($stream);
        if ($propertyId === '') {
            return null;
        }

        $token = $this->accessTokenForStream($stream);
        if ($token === null) {
            return null;
        }

        $startDate = date('Y-m-d', strtotime("-{$days} days"));
        $sourcesBody = [
            'dateRanges' => [['startDate' => $startDate, 'endDate' => 'today']],
            'dimensions' => [['name' => 'sessionDefaultChannelGrouping']],
            'metrics'    => [['name' => 'sessions'], ['name' => 'activeUsers']],
            'orderBys'   => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
        ];
        $pathFilter = $this->ga4PagePathFilterForStream($stream);
        if ($pathFilter !== null) {
            $sourcesBody['dimensionFilter'] = $pathFilter;
        }

        $report = Ga4AnalyticsClient::runReport($token, $propertyId, $sourcesBody);

        if ($report === null) {
            return null;
        }

        return $this->parseSourceRows($report);
    }

    /** @param list<string> $events */
    private function fetchLeadsForStream(string $stream, int $days, array $events): ?array
    {
        $propertyId = $this->resolvePropertyId($stream);
        if ($propertyId === '') {
            return null;
        }

        $token = $this->accessTokenForStream($stream);
        if ($token === null) {
            return null;
        }

        $startDate = date('Y-m-d', strtotime("-{$days} days"));
        $eventFilter = [
            'filter' => [
                'fieldName'    => 'eventName',
                'inListFilter' => ['values' => $events],
            ],
        ];
        $pathFilter = $this->ga4PagePathFilterForStream($stream);
        $dimensionFilter = $this->mergeDimensionFilters($pathFilter ?? [], $eventFilter);

        $leadsBody = [
            'dateRanges' => [['startDate' => $startDate, 'endDate' => 'today']],
            'dimensions' => [['name' => 'eventName']],
            'metrics'    => [['name' => 'eventCount']],
        ];
        if ($dimensionFilter !== null) {
            $leadsBody['dimensionFilter'] = $dimensionFilter;
        }

        $report = Ga4AnalyticsClient::runReport($token, $propertyId, $leadsBody);

        if ($report === null) {
            return null;
        }

        return $this->parseEventRows($report, $events);
    }

    private function fetchCombinedOverview(int $days): ?array
    {
        $results = [];
        foreach ($this->dataStreams() as $dataStream) {
            $row = $this->fetchOverviewForStream($dataStream, $days);
            if ($row !== null) {
                $results[] = $row;
            }
        }

        if ($results === []) {
            return null;
        }

        $combined = array_shift($results);
        foreach ($results as $next) {
            $combined = [
                'trend'     => $this->mergeTrendRows($combined['trend'] ?? [], $next['trend'] ?? []),
                'totals'    => $this->mergeTotals($combined['totals'] ?? [], $next['totals'] ?? []),
                'top_pages' => $this->mergeTopPages($combined['top_pages'] ?? [], $next['top_pages'] ?? []),
                'days'      => $days,
            ];
        }

        return $combined;
    }

    private function fetchCombinedSources(int $days): ?array
    {
        $results = [];
        foreach ($this->dataStreams() as $dataStream) {
            $row = $this->fetchSourcesForStream($dataStream, $days);
            if ($row !== null) {
                $results[] = $row;
            }
        }

        if ($results === []) {
            return null;
        }

        $combined = array_shift($results);
        foreach ($results as $next) {
            $combined = $this->mergeSourceResults($combined, $next);
        }

        return $combined;
    }

    /** @param list<string> $events */
    private function fetchCombinedLeads(int $days, array $events): ?array
    {
        $results = [];
        foreach ($this->dataStreams() as $dataStream) {
            $row = $this->fetchLeadsForStream($dataStream, $days, $events);
            if ($row !== null) {
                $results[] = $row;
            }
        }

        if ($results === []) {
            return null;
        }

        $combined = array_shift($results);
        foreach ($results as $next) {
            $combined = $this->mergeLeadCounts($combined, $next, $events);
        }

        return $combined;
    }

    /** @param list<array<string, mixed>> $a @param list<array<string, mixed>> $b */
    private function mergeTrendRows(array $a, array $b): array
    {
        $byDate = [];
        foreach (array_merge($a, $b) as $row) {
            $date = (string) ($row['date'] ?? '');
            if ($date === '') {
                continue;
            }
            if (!isset($byDate[$date])) {
                $byDate[$date] = [
                    'date'      => $date,
                    'sessions'  => 0,
                    'users'     => 0,
                    'pageviews' => 0,
                    'new_users' => 0,
                ];
            }
            $byDate[$date]['sessions']  += (int) ($row['sessions'] ?? 0);
            $byDate[$date]['users']     += (int) ($row['users'] ?? 0);
            $byDate[$date]['pageviews'] += (int) ($row['pageviews'] ?? 0);
            $byDate[$date]['new_users'] += (int) ($row['new_users'] ?? 0);
        }
        ksort($byDate);

        return array_values($byDate);
    }

    /** @param array<string, mixed> $a @param array<string, mixed> $b */
    private function mergeTotals(array $a, array $b): array
    {
        $sessionsA   = (int) ($a['sessions'] ?? 0);
        $sessionsB   = (int) ($b['sessions'] ?? 0);
        $totalSessions = $sessionsA + $sessionsB;
        $bounceA     = (float) ($a['bounce_rate'] ?? 0);
        $bounceB     = (float) ($b['bounce_rate'] ?? 0);

        return [
            'sessions'    => $totalSessions,
            'users'       => (int) ($a['users'] ?? 0) + (int) ($b['users'] ?? 0),
            'pageviews'   => (int) ($a['pageviews'] ?? 0) + (int) ($b['pageviews'] ?? 0),
            'new_users'   => (int) ($a['new_users'] ?? 0) + (int) ($b['new_users'] ?? 0),
            'bounce_rate' => $totalSessions > 0
                ? round(($bounceA * $sessionsA + $bounceB * $sessionsB) / $totalSessions, 1)
                : 0,
        ];
    }

    /** @param list<array<string, mixed>> $a @param list<array<string, mixed>> $b */
    private function mergeTopPages(array $a, array $b, int $limit = 10): array
    {
        $combined = array_merge($a, $b);
        usort($combined, fn (array $x, array $y): int => ($y['pageviews'] ?? 0) <=> ($x['pageviews'] ?? 0));

        return array_slice($combined, 0, $limit);
    }

    /** @param array<string, mixed> $a @param array<string, mixed> $b */
    private function mergeSourceResults(array $a, array $b): array
    {
        $channels = [];
        foreach (array_merge($a['channels'] ?? [], $b['channels'] ?? []) as $ch) {
            $name = (string) ($ch['channel'] ?? 'Unknown');
            if (!isset($channels[$name])) {
                $channels[$name] = ['channel' => $name, 'sessions' => 0, 'users' => 0];
            }
            $channels[$name]['sessions'] += (int) ($ch['sessions'] ?? 0);
            $channels[$name]['users']    += (int) ($ch['users'] ?? 0);
        }

        $list = array_values($channels);
        usort($list, fn (array $x, array $y): int => ($y['sessions'] ?? 0) <=> ($x['sessions'] ?? 0));

        $total = 0;
        foreach ($list as $ch) {
            $total += (int) ($ch['sessions'] ?? 0);
        }
        foreach ($list as &$ch) {
            $ch['pct'] = $total > 0 ? round($ch['sessions'] / $total * 100, 1) : 0;
        }
        unset($ch);

        return ['channels' => $list, 'total_sessions' => $total];
    }

    /** @param list<string> $events */
    private function mergeLeadCounts(array $a, array $b, array $events): array
    {
        $result = [];
        foreach ($events as $event) {
            $result[$event] = (int) ($a[$event] ?? 0) + (int) ($b[$event] ?? 0);
        }

        return $result;
    }

    /** @param array<string, mixed> $report */
    private function parseTrendRows(array $report): array
    {
        $rows = $report['rows'] ?? [];

        return array_map(static function (array $row): array {
            $dims    = $row['dimensionValues'] ?? [];
            $metrics = $row['metricValues'] ?? [];

            return [
                'date'      => $dims[0]['value'] ?? '',
                'sessions'  => (int) ($metrics[0]['value'] ?? 0),
                'users'     => (int) ($metrics[1]['value'] ?? 0),
                'pageviews' => (int) ($metrics[2]['value'] ?? 0),
                'new_users' => (int) ($metrics[3]['value'] ?? 0),
            ];
        }, is_array($rows) ? $rows : []);
    }

    /** @param array<string, mixed> $report */
    private function parseTotalsRow(array $report): array
    {
        $totals = $report['totals'][0]['metricValues']
            ?? $report['rows'][0]['metricValues']
            ?? [];

        return [
            'sessions'    => (int) ($totals[0]['value'] ?? 0),
            'users'       => (int) ($totals[1]['value'] ?? 0),
            'pageviews'   => (int) ($totals[2]['value'] ?? 0),
            'new_users'   => (int) ($totals[3]['value'] ?? 0),
            'bounce_rate' => round((float) ($totals[4]['value'] ?? 0) * 100, 1),
        ];
    }

    /** @param array<string, mixed> $report */
    private function parsePageRows(array $report, string $site = ''): array
    {
        $rows = $report['rows'] ?? [];

        return array_map(static function (array $row) use ($site): array {
            $dims    = $row['dimensionValues'] ?? [];
            $metrics = $row['metricValues'] ?? [];
            $page    = [
                'path'      => $dims[0]['value'] ?? '',
                'title'     => $dims[1]['value'] ?? '',
                'pageviews' => (int) ($metrics[0]['value'] ?? 0),
                'users'     => (int) ($metrics[1]['value'] ?? 0),
            ];
            if ($site !== '') {
                $page['site'] = $site;
            }

            return $page;
        }, is_array($rows) ? $rows : []);
    }

    /** @param array<string, mixed> $report */
    private function parseSourceRows(array $report): array
    {
        $rows    = is_array($report['rows'] ?? null) ? $report['rows'] : [];
        $total   = 0;
        $sources = [];

        foreach ($rows as $row) {
            $dims  = $row['dimensionValues'] ?? [];
            $metrics = $row['metricValues'] ?? [];
            $name  = $dims[0]['value'] ?? 'Unknown';
            $count = (int) ($metrics[0]['value'] ?? 0);
            $sources[] = [
                'channel'  => $name,
                'sessions' => $count,
                'users'    => (int) ($metrics[1]['value'] ?? 0),
            ];
            $total += $count;
        }

        foreach ($sources as &$s) {
            $s['pct'] = $total > 0 ? round($s['sessions'] / $total * 100, 1) : 0;
        }
        unset($s);

        return ['channels' => $sources, 'total_sessions' => $total];
    }

    /** @param list<string> $eventNames */
    private function parseEventRows(array $report, array $eventNames): array
    {
        $rows   = is_array($report['rows'] ?? null) ? $report['rows'] : [];
        $counts = array_fill_keys($eventNames, 0);

        foreach ($rows as $row) {
            $name  = $row['dimensionValues'][0]['value'] ?? '';
            $count = (int) ($row['metricValues'][0]['value'] ?? 0);
            if (isset($counts[$name])) {
                $counts[$name] = $count;
            }
        }

        return $counts;
    }

}
