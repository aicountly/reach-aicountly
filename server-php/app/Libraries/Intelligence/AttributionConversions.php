<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence;

use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;

/**
 * Attribution conversion links: the Attribution overview's counts and the
 * conversion list behind them, built from ONE set of predicates so a tile or
 * a first-touch channel bar opens exactly the rows it counted.
 *
 *   overview()  Total / Attributed / Unattributed / Rate + first-touch channel bars
 *   list()      the same rows, filtered by attributed=0|1, channel (first touch),
 *               model (matching_method), confidence, type, lead_id, from/to
 *
 * Every query aliases the links `cl`, the first / last touchpoints `ft` / `lt`
 * and the lead `l`.
 */
final class AttributionConversions
{
    /** A conversion with a usable touchpoint that nobody marked unattributed. */
    public const ATTRIBUTED = "(cl.confidence_state IS DISTINCT FROM 'unattributed'
        AND cl.matching_method IS DISTINCT FROM 'unattributed'
        AND (cl.first_touchpoint_id IS NOT NULL OR cl.last_touchpoint_id IS NOT NULL))";

    // Every column in ATTRIBUTED is NOT NULL or tested with IS [NOT] NULL, so the
    // negation is never NULL: attributed + unattributed = total, always.
    public const UNATTRIBUTED = 'NOT ' . self::ATTRIBUTED;

    /** The channel label a first-touch bar is grouped by. */
    public const FIRST_TOUCH_CHANNEL = "COALESCE(NULLIF(TRIM(ft.channel), ''), NULLIF(TRIM(ft.utm_source), ''), 'Unknown')";
    public const LAST_TOUCH_CHANNEL  = "COALESCE(NULLIF(TRIM(lt.channel), ''), NULLIF(TRIM(lt.utm_source), ''), 'Unknown')";

    public const MODELS      = ['first_touch', 'last_touch', 'manual', 'unattributed'];
    public const CONFIDENCES = ['confirmed', 'inferred', 'unattributed', 'corrected'];
    public const TYPES       = ['lead', 'signup', 'demo_request', 'contact', 'download', 'engage_push'];

    private const FROM = 'FROM reach_attribution_conversion_links cl
        LEFT JOIN reach_attribution_touchpoints ft ON ft.id = cl.first_touchpoint_id
        LEFT JOIN reach_attribution_touchpoints lt ON lt.id = cl.last_touchpoint_id
        LEFT JOIN reach_leads l ON l.id = cl.lead_id';

    /** How many first-touch channels the overview lists. */
    public const CHANNEL_LIMIT = 20;

    /**
     * WHERE clause + bound params for the filters. Unknown filter values are
     * ignored; a malformed date throws (the API answers 422).
     *
     * @param array<string,mixed> $filters
     * @return array{0:string,1:list<mixed>}
     */
    public static function where(?int $tenantId, array $filters): array
    {
        $where  = [];
        $params = [];
        if ($tenantId !== null) {
            $where[]  = 'cl.tenant_id = ?';
            $params[] = $tenantId;
        }

        $attributed = (string) ($filters['attributed'] ?? '');
        if ($attributed === '1') {
            $where[] = self::ATTRIBUTED;
        } elseif ($attributed === '0') {
            $where[] = self::UNATTRIBUTED;
        }

        // A first-touch bar counts conversions that have a first touchpoint, grouped by its label.
        $channel = trim((string) ($filters['channel'] ?? ''));
        if ($channel !== '') {
            $where[]  = 'cl.first_touchpoint_id IS NOT NULL AND ' . self::FIRST_TOUCH_CHANNEL . ' = ?';
            $params[] = $channel;
        }

        foreach (['model' => ['cl.matching_method', self::MODELS], 'confidence' => ['cl.confidence_state', self::CONFIDENCES], 'type' => ['cl.conversion_type', self::TYPES]] as $key => [$col, $allowed]) {
            $v = (string) ($filters[$key] ?? '');
            if (in_array($v, $allowed, true)) {
                $where[]  = "{$col} = ?";
                $params[] = $v;
            }
        }

        $leadId = (string) ($filters['lead_id'] ?? '');
        if ($leadId !== '' && ctype_digit($leadId)) {
            $where[]  = 'cl.lead_id = ?';
            $params[] = (int) $leadId;
        }

        // from / to are calendar days, both inclusive.
        foreach (['from' => '>=', 'to' => '<'] as $key => $op) {
            $v = trim((string) ($filters[$key] ?? ''));
            if ($v === '') {
                continue;
            }
            $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
            if ($day === false || $day->format('Y-m-d') !== $v) {
                throw new InvalidArgumentException("{$key} must be a date (YYYY-MM-DD).");
            }
            $where[]  = "cl.converted_at {$op} ?::date";
            $params[] = $key === 'to' ? $day->modify('+1 day')->format('Y-m-d') : $v;
        }

        return [$where === [] ? '' : 'WHERE ' . implode(' AND ', $where), $params];
    }

    /**
     * The overview tiles and first-touch bars. `attributed` and `channel` are
     * what the tiles / bars split by, so they are not applied here.
     *
     * @param array<string,mixed> $filters
     */
    public static function overview(BaseConnection $db, int $tenantId, array $filters = []): array
    {
        unset($filters['attributed'], $filters['channel']);
        [$where, $params] = self::where($tenantId, $filters);

        $row = $db->query(
            'SELECT COUNT(*)::int AS total,
                    COUNT(*) FILTER (WHERE ' . self::ATTRIBUTED . ')::int AS attributed,
                    COUNT(*) FILTER (WHERE ' . self::UNATTRIBUTED . ')::int AS unattributed
             ' . self::FROM . " {$where}",
            $params,
        )->getRowArray() ?: [];
        $total      = (int) ($row['total'] ?? 0);
        $attributed = (int) ($row['attributed'] ?? 0);

        // Same rows as list(['attributed' => '1', 'channel' => X]).
        $channelWhere = ($where === '' ? 'WHERE ' : $where . ' AND ') . self::ATTRIBUTED . ' AND cl.first_touchpoint_id IS NOT NULL';
        $channels     = $db->query(
            'SELECT ' . self::FIRST_TOUCH_CHANNEL . ' AS channel, COUNT(*)::int AS conversions
             ' . self::FROM . " {$channelWhere}
             GROUP BY 1 ORDER BY conversions DESC, channel LIMIT " . self::CHANNEL_LIMIT,
            $params,
        )->getResultArray();
        $channelTotal = array_sum(array_map(static fn (array $r): int => (int) $r['conversions'], $channels));

        return [
            'stats' => [
                'total_conversions' => $total,
                'attributed'        => $attributed,
                'unattributed'      => (int) ($row['unattributed'] ?? 0),
                'attribution_rate'  => $total > 0 ? round(($attributed / $total) * 100, 1) : 0.0,
            ],
            'first_touch_breakdown' => array_map(static fn (array $r): array => [
                'channel'     => (string) $r['channel'],
                'conversions' => (int) $r['conversions'],
                'pct'         => $channelTotal > 0 ? (int) round(((int) $r['conversions'] / $channelTotal) * 100) : 0,
            ], $channels),
        ];
    }

    /**
     * One page of conversions, newest first, with the first / last touch and the lead.
     *
     * @param array<string,mixed> $filters
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(BaseConnection $db, ?int $tenantId, array $filters, int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = self::where($tenantId, $filters);
        $total = (int) ($db->query('SELECT COUNT(*) AS n ' . self::FROM . " {$where}", $params)->getRowArray()['n'] ?? 0);
        $limit  = max(1, min(500, $limit));
        $offset = max(0, $offset);

        $rows = $db->query(
            'SELECT cl.id, cl.uuid, cl.tenant_id, cl.lead_id, cl.conversion_type, cl.converted_at,
                    cl.matching_method, cl.confidence_state, cl.calculation_version_id,
                    cl.manual_correction_note, cl.corrected_at,
                    (' . self::ATTRIBUTED . ') AS attributed,
                    cl.first_touchpoint_id,
                    CASE WHEN ft.id IS NOT NULL THEN ' . self::FIRST_TOUCH_CHANNEL . ' END AS first_touch_channel,
                    ft.touchpoint_type AS first_touch_type, ft.touched_at AS first_touched_at,
                    ft.utm_source AS first_utm_source, ft.utm_medium AS first_utm_medium, ft.utm_campaign AS first_utm_campaign,
                    ft.referrer_domain AS first_referrer_domain, ft.campaign_id AS first_campaign_id,
                    cl.last_touchpoint_id,
                    CASE WHEN lt.id IS NOT NULL THEN ' . self::LAST_TOUCH_CHANNEL . ' END AS last_touch_channel,
                    lt.touchpoint_type AS last_touch_type, lt.touched_at AS last_touched_at,
                    lt.utm_source AS last_utm_source, lt.utm_campaign AS last_utm_campaign,
                    l.name AS lead_name, l.email AS lead_email, l.source_kind AS lead_source_kind
             ' . self::FROM . " {$where}
             ORDER BY cl.converted_at DESC, cl.id DESC
             LIMIT {$limit} OFFSET {$offset}",
            $params,
        )->getResultArray();

        foreach ($rows as &$r) {
            $r['attributed'] = in_array($r['attributed'], [true, 't', 'true', 1, '1'], true);
        }
        unset($r);

        return ['items' => $rows, 'total' => $total];
    }
}
