<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence;

final class IntelligenceJobTypes
{
    public const SEARCH_CONSOLE_INGEST         = 'intelligence.search_console.ingest';
    public const SEARCH_CONSOLE_BACKFILL       = 'intelligence.search_console.backfill';
    public const CONTENT_ANALYTICS_INGEST      = 'intelligence.content_analytics.ingest';
    public const CONTENT_ANALYTICS_BACKFILL    = 'intelligence.content_analytics.backfill';
    public const SITEMAP_SNAPSHOT              = 'intelligence.sitemap.snapshot';
    public const INDEXNOW_RETRY_PENDING        = 'intelligence.indexnow.retry_pending';
    public const ATTRIBUTION_CALCULATE         = 'intelligence.attribution.calculate';
    public const ATTRIBUTION_RECONCILE         = 'intelligence.attribution.reconcile';
    public const VISIBILITY_RUN_EXECUTE        = 'intelligence.visibility.execute';
    public const VISIBILITY_RESPONSE_PARSE     = 'intelligence.visibility.parse';
    public const COMPETITOR_AGGREGATE          = 'intelligence.competitor.aggregate';
    public const CONNECTOR_HEALTH_CHECK        = 'intelligence.connector.health_check';
    public const DAILY_METRIC_AGGREGATION      = 'intelligence.metrics.daily_aggregate';
    public const IDENTITY_RECONCILE            = 'intelligence.identity.reconcile';
    public const MAPPING_CONFLICT_RESOLVE      = 'intelligence.mapping.conflict_resolve';

    public static function all(): array
    {
        return [
            self::SEARCH_CONSOLE_INGEST,
            self::SEARCH_CONSOLE_BACKFILL,
            self::CONTENT_ANALYTICS_INGEST,
            self::CONTENT_ANALYTICS_BACKFILL,
            self::SITEMAP_SNAPSHOT,
            self::INDEXNOW_RETRY_PENDING,
            self::ATTRIBUTION_CALCULATE,
            self::ATTRIBUTION_RECONCILE,
            self::VISIBILITY_RUN_EXECUTE,
            self::VISIBILITY_RESPONSE_PARSE,
            self::COMPETITOR_AGGREGATE,
            self::CONNECTOR_HEALTH_CHECK,
            self::DAILY_METRIC_AGGREGATION,
            self::IDENTITY_RECONCILE,
            self::MAPPING_CONFLICT_RESOLVE,
        ];
    }
}
