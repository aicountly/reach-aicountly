<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Libraries\Intelligence\ContentIdentitySyncService;

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

    /**
     * Explorer dimensions → GA4 Data API dimension names. The channel name is
     * the one the Traffic sources pie groups by, so a slice and its drill use
     * the same values.
     */
    public const EXPLORE_DIMENSIONS = [
        'date'    => 'date',
        'channel' => self::CHANNEL_DIMENSION,
        'source'  => 'sessionSourceMedium',
        'page'    => 'pagePath',
        'device'  => 'deviceCategory',
        'country' => 'country',
        'event'   => 'eventName',
    ];

    public const EXPLORE_SORTS = ['key', 'sessions', 'users', 'pageviews', 'bounce_rate', 'events'];

    /** Same metric names as the overview KPIs (users = activeUsers), so a card and its drill agree. */
    private const EXPLORE_METRICS = ['sessions', 'activeUsers', 'screenPageViews', 'bounceRate', 'eventCount'];

    private const EXPLORE_SORT_METRICS = [
        'sessions'    => 'sessions',
        'users'       => 'activeUsers',
        'pageviews'   => 'screenPageViews',
        'bounce_rate' => 'bounceRate',
        'events'      => 'eventCount',
    ];

    /** Rows each property may return for a combined (all sites) explore, which merges and pages in PHP. */
    private const COMBINED_FETCH_CAP = 1000;

    private const CHANNEL_DIMENSION = 'sessionDefaultChannelGrouping';

    private AnalyticsCache $cache;

    /** @var (\Closure(string, array<string, mixed>): ?array)|null */
    private ?\Closure $reportRunner;

    /** @var array<string, ?string> */
    private array $tokens = [];

    /**
     * $reportRunner(stream, body) replaces the live GA4 call for explore()
     * (tests); production resolves the stream's property and token.
     */
    public function __construct(?AnalyticsCache $cache = null, ?\Closure $reportRunner = null)
    {
        $this->cache        = $cache ?? new AnalyticsCache();
        $this->reportRunner = $reportRunner;
    }

    public function overview(int $days, string $stream): array
    {
        $cacheKey   = 'traffic_overview_v4';
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

        $result['comparison'] = self::buildComparison($result['totals'] ?? [], $result['previous_totals'] ?? []);

        $this->cache->set($cacheKey, $paramsHash, $result, self::CACHE_TTL_SECONDS);

        return ['data' => $result, 'demo' => false, 'unconfigured' => false, 'cached' => false];
    }

    /**
     * [startDate, endDate] (Y-m-d, inclusive) for the period immediately
     * preceding a "last $days days" window that ends on $referenceDate
     * (defaults to today) — same length as that window, no gap or overlap.
     * A pure function of its arguments so it's unit-testable without mocking
     * GA4 or the system clock.
     *
     * @return array{0: string, 1: string}
     */
    public static function previousPeriodDates(int $days, ?string $referenceDate = null): array
    {
        $reference = $referenceDate ?? date('Y-m-d');

        return [
            date('Y-m-d', strtotime($reference . ' -' . ($days * 2 + 1) . ' days')),
            date('Y-m-d', strtotime($reference . ' -' . ($days + 1) . ' days')),
        ];
    }

    /**
     * Per-metric comparison of $current totals against $previous totals:
     * `previous` (the prior-period value), `delta` (absolute change), and
     * `delta_pct` (percent change, or null when the previous period has no
     * baseline to compare against — i.e. it was 0 and the current period isn't).
     *
     * @param array<string, mixed> $current
     * @param array<string, mixed> $previous
     * @return array<string, array{previous: int|float, delta: float, delta_pct: ?float}>
     */
    public static function buildComparison(array $current, array $previous): array
    {
        $fields = ['sessions', 'users', 'pageviews', 'new_users', 'bounce_rate'];
        $out    = [];

        foreach ($fields as $field) {
            $curVal  = (float) ($current[$field] ?? 0);
            $prevVal = (float) ($previous[$field] ?? 0);

            if ($prevVal > 0) {
                $deltaPct = round((($curVal - $prevVal) / $prevVal) * 100, 1);
            } elseif ($curVal > 0) {
                $deltaPct = null;
            } else {
                $deltaPct = 0.0;
            }

            $out[$field] = [
                'previous'  => $previous[$field] ?? 0,
                'delta'     => round($curVal - $prevVal, 2),
                'delta_pct' => $deltaPct,
            ];
        }

        return $out;
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

    /**
     * GA4 traffic grouped by one dimension within the filters chosen so far
     * — the traffic counterpart of SearchSiteAnalyticsService::explore().
     * Each filter is an exact match on an EXPLORE_DIMENSIONS key, so every
     * drill step narrows the same report until a single GA4 row is left (one
     * day × channel × source × page × device × country × event).
     *
     * $opts: sort (EXPLORE_SORTS), dir (asc|desc), search (contains on the
     * grouped dimension), limit (1–250), offset. Unconfigured streams return
     * an empty, flagged payload exactly like overview() — never numbers.
     *
     * @param array<string, mixed> $filters
     * @param array<string, mixed> $opts
     * @return array{data: array<string, mixed>, demo: bool, unconfigured: bool, cached: bool}
     */
    public function explore(string $groupBy, int $days, string $stream, array $filters = [], array $opts = []): array
    {
        if (! isset(self::EXPLORE_DIMENSIONS[$groupBy])) {
            throw new \InvalidArgumentException("Unknown dimension `{$groupBy}`.");
        }
        $filters = self::normalizeExploreFilters($filters);
        $opts = self::normalizeExploreOpts($groupBy, $opts);
        [$startDate, $endDate] = self::exploreWindow($days, $filters['date'] ?? null);
        $period = ['from' => $startDate, 'to' => $endDate === 'today' ? date('Y-m-d') : $endDate];

        $cacheKey   = 'traffic_explore_v1';
        $paramsHash = md5((string) json_encode([$groupBy, $days, $stream, $filters, $opts]));
        $cached     = $this->cache->get($cacheKey, $paramsHash);
        if ($cached !== null && empty($cached['_unconfigured'])) {
            return ['data' => $this->withContentIdentities($cached, $groupBy, $filters), 'demo' => false, 'unconfigured' => false, 'cached' => true];
        }

        if ($this->isCombinedStream($stream)) {
            $result = $this->fetchCombinedExplore($groupBy, $startDate, $endDate, $filters, $opts);
        } else {
            $setupReason = $this->saasStreamSetupReason($stream);
            if ($setupReason !== null) {
                return ['data' => $this->emptyExplore($groupBy, $stream, $filters, $period, $setupReason), 'demo' => false, 'unconfigured' => true, 'cached' => false];
            }
            $result = $this->fetchExploreForStream($stream, $groupBy, $startDate, $endDate, $filters, $opts);
            if ($result !== null) {
                $result['sites'] = [$result['site']];
            }
        }
        if ($result === null) {
            $empty = $this->emptyExplore($groupBy, $stream, $filters, $period, $this->streamFailureReason($stream));

            return ['data' => $empty, 'demo' => false, 'unconfigured' => true, 'cached' => false];
        }

        $result += ['group_by' => $groupBy, 'filters' => $filters, 'period' => $period, 'stream' => $stream, 'days' => $days];
        $this->cache->set($cacheKey, $paramsHash, $result, self::CACHE_TTL_SECONDS);

        return ['data' => $this->withContentIdentities($result, $groupBy, $filters), 'demo' => false, 'unconfigured' => false, 'cached' => false];
    }

    /**
     * Known keys with non-empty values; `date` becomes GA4's YYYYMMDD.
     *
     * @param array<string, mixed> $filters
     * @return array<string, string>
     */
    public static function normalizeExploreFilters(array $filters): array
    {
        $out = [];
        foreach (array_keys(self::EXPLORE_DIMENSIONS) as $key) {
            $value = $filters[$key] ?? '';
            if (! is_string($value) || trim($value) === '') {
                continue;
            }
            $value = trim($value);
            if ($key === 'date') {
                $ymd = str_replace('-', '', $value);
                if (! preg_match('/^\d{8}$/', $ymd)
                    || ! checkdate((int) substr($ymd, 4, 2), (int) substr($ymd, 6, 2), (int) substr($ymd, 0, 4))) {
                    throw new \InvalidArgumentException('`date` must be YYYY-MM-DD or YYYYMMDD.');
                }
                $value = $ymd;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $opts
     * @return array{sort: string, dir: string, search: string, limit: int, offset: int}
     */
    private static function normalizeExploreOpts(string $groupBy, array $opts): array
    {
        $sort = in_array($opts['sort'] ?? '', self::EXPLORE_SORTS, true)
            ? (string) $opts['sort']
            : match ($groupBy) {
                'date'  => 'key',
                'event' => 'events',
                default => 'sessions',
            };
        $dir = strtolower((string) ($opts['dir'] ?? ''));
        if ($dir !== 'asc' && $dir !== 'desc') {
            // Metrics high → low, days newest first, other names A → Z.
            $dir = $sort === 'key' && $groupBy !== 'date' ? 'asc' : 'desc';
        }

        return [
            'sort'   => $sort,
            'dir'    => $dir,
            'search' => $groupBy === 'date' ? '' : trim((string) ($opts['search'] ?? '')),
            'limit'  => max(1, min(250, (int) ($opts['limit'] ?? 50))),
            'offset' => max(0, (int) ($opts['offset'] ?? 0)),
        ];
    }

    /**
     * The overview's "last $days days" window, or the one day a date filter names.
     *
     * @return array{0: string, 1: string}
     */
    public static function exploreWindow(int $days, ?string $ymd = null): array
    {
        if ($ymd !== null && $ymd !== '') {
            $day = substr($ymd, 0, 4) . '-' . substr($ymd, 4, 2) . '-' . substr($ymd, 6, 2);

            return [$day, $day];
        }

        return [date('Y-m-d', strtotime("-{$days} days")), 'today'];
    }

    /**
     * The runReport body for one explore step: one dimension, the shared
     * metrics, an andGroup of EXACT matches for every filter (plus the SaaS
     * path filter and a CONTAINS on the grouped dimension), sorted and paged
     * by GA4. Pure, so the request shape is unit-testable.
     *
     * @param array<string, string>     $filters normalised (see normalizeExploreFilters)
     * @param array<string, mixed>      $opts    normalised
     * @param array<string, mixed>|null $pathFilter
     * @return array<string, mixed>
     */
    public static function exploreRequestBody(
        string $groupBy,
        string $startDate,
        string $endDate,
        array $filters,
        array $opts,
        ?array $pathFilter = null,
    ): array {
        $dimension   = self::EXPLORE_DIMENSIONS[$groupBy];
        $expressions = self::exactFilterExpressions($filters, $pathFilter);
        $search      = (string) ($opts['search'] ?? '');
        if ($search !== '') {
            $expressions[] = ['filter' => [
                'fieldName'    => $dimension,
                'stringFilter' => ['matchType' => 'CONTAINS', 'value' => $search, 'caseSensitive' => false],
            ]];
        }

        $sort = (string) ($opts['sort'] ?? 'sessions');
        $desc = ($opts['dir'] ?? 'desc') === 'desc';
        $orderBys = [$sort === 'key'
            ? ['dimension' => ['dimensionName' => $dimension], 'desc' => $desc]
            : ['metric' => ['metricName' => self::EXPLORE_SORT_METRICS[$sort] ?? 'sessions'], 'desc' => $desc]];
        if ($sort !== 'sessions') {
            $orderBys[] = ['metric' => ['metricName' => 'sessions'], 'desc' => true];
        }
        if ($sort !== 'key') {
            $orderBys[] = ['dimension' => ['dimensionName' => $dimension], 'desc' => false];
        }

        $body = [
            'dateRanges'         => [['startDate' => $startDate, 'endDate' => $endDate]],
            'dimensions'         => [['name' => $dimension]],
            'metrics'            => array_map(static fn (string $m): array => ['name' => $m], self::EXPLORE_METRICS),
            'orderBys'           => $orderBys,
            'limit'              => (int) ($opts['limit'] ?? 50),
            'offset'             => (int) ($opts['offset'] ?? 0),
            'metricAggregations' => ['TOTAL'],
        ];
        $filter = self::andExpressions($expressions);
        if ($filter !== null) {
            $body['dimensionFilter'] = $filter;
        }

        return $body;
    }

    /**
     * Totals of the slice without the contains-filter (a search narrows the
     * rows, not the slice the tiles describe).
     *
     * @param array<string, string>     $filters
     * @param array<string, mixed>|null $pathFilter
     * @return array<string, mixed>
     */
    public static function exploreTotalsBody(string $startDate, string $endDate, array $filters, ?array $pathFilter = null): array
    {
        $body = [
            'dateRanges' => [['startDate' => $startDate, 'endDate' => $endDate]],
            'metrics'    => array_map(static fn (string $m): array => ['name' => $m], self::EXPLORE_METRICS),
        ];
        $filter = self::andExpressions(self::exactFilterExpressions($filters, $pathFilter));
        if ($filter !== null) {
            $body['dimensionFilter'] = $filter;
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $report a runReport response
     * @return array{rows: list<array<string, mixed>>, total_rows: int, totals: array<string, int|float>}
     */
    public static function parseExploreReport(array $report): array
    {
        $rows = is_array($report['rows'] ?? null) ? $report['rows'] : [];
        $out  = [];
        foreach ($rows as $row) {
            $out[] = ['dimension_value' => (string) ($row['dimensionValues'][0]['value'] ?? '')]
                + self::exploreMetrics($row['metricValues'] ?? []);
        }

        return [
            'rows'       => $out,
            'total_rows' => (int) ($report['rowCount'] ?? count($out)),
            'totals'     => self::exploreMetrics($report['totals'][0]['metricValues'] ?? $report['rows'][0]['metricValues'] ?? []),
        ];
    }

    /**
     * Rows of several properties keyed by dimension value: counts add up,
     * bounce rate is session-weighted (as mergeTotals does for the KPIs).
     *
     * @param list<array{rows: list<array<string, mixed>>, site?: string}> $parts
     * @return list<array<string, mixed>>
     */
    public static function mergeExploreRows(array $parts): array
    {
        $byKey = [];
        foreach ($parts as $part) {
            foreach ($part['rows'] as $row) {
                $key = (string) $row['dimension_value'];
                if (! isset($byKey[$key])) {
                    $byKey[$key] = ['dimension_value' => $key] + self::exploreMetrics([]) + ['sites' => []];
                }
                $byKey[$key] = ['dimension_value' => $key]
                    + self::addExploreMetrics($byKey[$key], $row)
                    + ['sites' => $byKey[$key]['sites']];
                if (($part['site'] ?? '') !== '' && ! in_array($part['site'], $byKey[$key]['sites'], true)) {
                    $byKey[$key]['sites'][] = $part['site'];
                }
            }
        }

        return array_values($byKey);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array{sort: string, dir: string} $opts
     * @return list<array<string, mixed>>
     */
    public static function sortExploreRows(array $rows, array $opts): array
    {
        $field = $opts['sort'] === 'key' ? 'dimension_value' : $opts['sort'];
        $sign  = $opts['dir'] === 'asc' ? 1 : -1;
        usort($rows, static function (array $a, array $b) use ($field, $sign): int {
            $cmp = $field === 'dimension_value'
                ? strcmp((string) $a[$field], (string) $b[$field])
                : ($a[$field] <=> $b[$field]);

            return $cmp * $sign
                ?: (($b['sessions'] <=> $a['sessions']) ?: strcmp((string) $a['dimension_value'], (string) $b['dimension_value']));
        });

        return $rows;
    }

    /** @param list<array{value?: string}> $values */
    private static function exploreMetrics(array $values): array
    {
        return [
            'sessions'    => (int) ($values[0]['value'] ?? 0),
            'users'       => (int) ($values[1]['value'] ?? 0),
            'pageviews'   => (int) ($values[2]['value'] ?? 0),
            'bounce_rate' => round((float) ($values[3]['value'] ?? 0) * 100, 1),
            'events'      => (int) ($values[4]['value'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     * @return array<string, int|float>
     */
    private static function addExploreMetrics(array $a, array $b): array
    {
        $sessions = (int) $a['sessions'] + (int) $b['sessions'];

        return [
            'sessions'    => $sessions,
            'users'       => (int) $a['users'] + (int) $b['users'],
            'pageviews'   => (int) $a['pageviews'] + (int) $b['pageviews'],
            'bounce_rate' => $sessions > 0
                ? round(((float) $a['bounce_rate'] * (int) $a['sessions'] + (float) $b['bounce_rate'] * (int) $b['sessions']) / $sessions, 1)
                : 0.0,
            'events'      => (int) $a['events'] + (int) $b['events'],
        ];
    }

    /**
     * @param array<string, string>     $filters
     * @param array<string, mixed>|null $pathFilter
     * @return list<array<string, mixed>>
     */
    private static function exactFilterExpressions(array $filters, ?array $pathFilter): array
    {
        $expressions = $pathFilter !== null ? [$pathFilter] : [];
        foreach ($filters as $key => $value) {
            $expressions[] = ['filter' => [
                'fieldName'    => self::EXPLORE_DIMENSIONS[$key],
                'stringFilter' => ['matchType' => 'EXACT', 'value' => $value, 'caseSensitive' => true],
            ]];
        }

        return $expressions;
    }

    /**
     * @param list<array<string, mixed>> $expressions
     * @return array<string, mixed>|null
     */
    private static function andExpressions(array $expressions): ?array
    {
        return match (count($expressions)) {
            0       => null,
            1       => $expressions[0],
            default => ['andGroup' => ['expressions' => $expressions]],
        };
    }

    /**
     * @param array<string, string> $filters
     * @param array<string, mixed>  $opts
     * @return array<string, mixed>|null
     */
    private function fetchExploreForStream(string $stream, string $groupBy, string $startDate, string $endDate, array $filters, array $opts): ?array
    {
        $pathFilter = $this->ga4PagePathFilterForStream($stream);
        $report     = $this->runStreamReport($stream, self::exploreRequestBody($groupBy, $startDate, $endDate, $filters, $opts, $pathFilter));
        if ($report === null) {
            return null;
        }

        $parsed = self::parseExploreReport($report);
        if ($opts['search'] !== '') {
            $totals = $this->runStreamReport($stream, self::exploreTotalsBody($startDate, $endDate, $filters, $pathFilter));
            $parsed['totals'] = self::parseExploreReport($totals ?? [])['totals'];
        }

        return $parsed + ['site' => $this->siteUrlForStream($stream)];
    }

    /**
     * Every configured property, merged per dimension value and paged here
     * (GA4 can only page one property). When a property had more rows than
     * were fetched the total is a lower bound, flagged `total_rows_approx`.
     *
     * @param array<string, string> $filters
     * @param array<string, mixed>  $opts
     * @return array<string, mixed>|null
     */
    private function fetchCombinedExplore(string $groupBy, string $startDate, string $endDate, array $filters, array $opts): ?array
    {
        $fetchOpts = ['offset' => 0, 'limit' => min(self::COMBINED_FETCH_CAP, $opts['offset'] + $opts['limit'])] + $opts;
        $parts     = [];
        foreach ($this->dataStreams() as $dataStream) {
            $part = $this->fetchExploreForStream($dataStream, $groupBy, $startDate, $endDate, $filters, $fetchOpts);
            if ($part !== null) {
                $parts[] = $part;
            }
        }
        if ($parts === []) {
            return null;
        }

        $rows   = self::sortExploreRows(self::mergeExploreRows($parts), $opts);
        $approx = false;
        $total  = count($rows);
        $totals = self::exploreMetrics([]);
        $sites  = [];
        foreach ($parts as $part) {
            if ($part['total_rows'] > count($part['rows'])) {
                $approx = true;
                $total  = max($total, $part['total_rows']);
            }
            $totals = self::addExploreMetrics($totals, $part['totals']);
            if (! in_array($part['site'], $sites, true)) {
                $sites[] = $part['site'];
            }
        }

        return [
            'rows'              => array_slice($rows, $opts['offset'], $opts['limit']),
            'total_rows'        => $total,
            'total_rows_approx' => $approx,
            'totals'            => $totals,
            'sites'             => $sites,
        ];
    }

    /** @param array<string, mixed> $body */
    private function runStreamReport(string $stream, array $body): ?array
    {
        if ($this->reportRunner !== null) {
            return ($this->reportRunner)($stream, $body);
        }

        $propertyId = $this->resolvePropertyId($stream);
        if ($propertyId === '') {
            return null;
        }
        // One OAuth exchange per stream per request, not per report.
        if (! array_key_exists($stream, $this->tokens)) {
            $this->tokens[$stream] = $this->accessTokenForStream($stream);
        }
        $token = $this->tokens[$stream];

        return $token === null ? null : Ga4AnalyticsClient::runReport($token, $propertyId, $body);
    }

    /**
     * @param array<string, string> $filters
     * @param array{from: string, to: string} $period
     * @return array<string, mixed>
     */
    private function emptyExplore(string $groupBy, string $stream, array $filters, array $period, string $reason): array
    {
        return [
            'group_by'      => $groupBy,
            'rows'          => [],
            'total_rows'    => 0,
            'totals'        => self::exploreMetrics([]),
            'filters'       => $filters,
            'period'        => $period,
            'stream'        => $stream,
            'sites'         => [],
            '_unconfigured' => true,
            '_reason'       => $reason,
            '_stream'       => $stream,
        ];
    }

    /**
     * Page rows (and a page leaf) carry the Reach content identity whose
     * published URL is exactly that page on the stream's site — the same
     * index Search Console ingestion maps pages with. Ambiguous or unknown
     * pages get none. Not cached: identities change as posts go live.
     *
     * @param array<string, mixed>  $data
     * @param array<string, string> $filters
     * @return array<string, mixed>
     */
    private function withContentIdentities(array $data, string $groupBy, array $filters): array
    {
        if ($groupBy !== 'page' && ! isset($filters['page'])) {
            return $data;
        }
        try {
            // Reach runs a single tenant; ingestion maps pages against tenant 1 too.
            $index = (new ContentIdentitySyncService())->urlIndex(1);
        } catch (\Throwable) {
            return $data;
        }
        if ($index === []) {
            return $data;
        }

        $lookup = static function (string $path, array $sites) use ($index): ?int {
            $ids = [];
            foreach ($sites as $site) {
                $id = $index[ContentIdentitySyncService::normaliseUrl(rtrim((string) $site, '/') . $path)] ?? null;
                if ($id !== null) {
                    $ids[$id] = true;
                }
            }

            return count($ids) === 1 ? (int) array_key_first($ids) : null;
        };

        $sites = is_array($data['sites'] ?? null) ? $data['sites'] : [];
        if ($groupBy === 'page') {
            foreach ($data['rows'] as &$row) {
                $row['content_identity_id'] = $lookup((string) $row['dimension_value'], $row['sites'] ?? $sites);
            }
            unset($row);
        }
        if (isset($filters['page'])) {
            $data['page_content_identity_id'] = $lookup($filters['page'], $sites);
        }

        return $data;
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
        $zeroTotals = [
            'sessions'    => 0,
            'users'       => 0,
            'pageviews'   => 0,
            'new_users'   => 0,
            'bounce_rate' => 0,
        ];

        return [
            'trend'           => [],
            'totals'          => $zeroTotals,
            'previous_totals' => $zeroTotals,
            'top_pages'       => [],
            'days'            => $days,
            '_unconfigured'   => true,
            '_reason'         => $reason,
            '_stream'         => $stream,
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
        // Apex, not www: aicountly.com 301s every www URL to the apex host and
        // the deploy asserts it does, so www is never a destination — only a
        // redirect source. Defaulting to it produced URLs one hop away from the
        // ones the site actually serves and Search Console actually reports.
        return rtrim(trim((string) (env('AICOUNTLY_SITE_URL') ?? 'https://aicountly.com')), '/');
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

        [$previousStartDate, $previousEndDate] = self::previousPeriodDates($days);
        $previousTotalsBody = [
            'dateRanges'         => [['startDate' => $previousStartDate, 'endDate' => $previousEndDate]],
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
            $previousTotalsBody['dimensionFilter'] = $pathFilter;
        }

        $previousTotalsReport = Ga4AnalyticsClient::runReport($token, $propertyId, $previousTotalsBody);

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
            'trend'           => $this->parseTrendRows($trendReport ?? []),
            'totals'          => $this->parseTotalsRow($totalsReport ?? []),
            'previous_totals' => $this->parseTotalsRow($previousTotalsReport ?? []),
            // `stream` lets a top page drill into the property it came from
            // when the overview combines all sites.
            'top_pages'       => array_map(
                static fn (array $page): array => $page + ['stream' => $stream],
                $this->parsePageRows($pagesReport ?? [], $site),
            ),
            'days'            => $days,
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
            'dimensions' => [['name' => self::CHANNEL_DIMENSION]],
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
                'trend'           => $this->mergeTrendRows($combined['trend'] ?? [], $next['trend'] ?? []),
                'totals'          => $this->mergeTotals($combined['totals'] ?? [], $next['totals'] ?? []),
                'previous_totals' => $this->mergeTotals($combined['previous_totals'] ?? [], $next['previous_totals'] ?? []),
                'top_pages'       => $this->mergeTopPages($combined['top_pages'] ?? [], $next['top_pages'] ?? []),
                'days'            => $days,
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
