<?php

declare(strict_types=1);

namespace App\Libraries\Leads;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Engage outcome totals for a period — the API's /leads/outcomes/summary and
 * /analytics/outcomes, and the daily report.
 *
 *   LeadOutcomeSummary::forPeriod('2026-10-16', '2026-10-16');          // one IST day
 *   LeadOutcomeSummary::forPeriod('2026-10-01T00:00:00Z', '2026-10-08T00:00:00Z');
 *
 * Bounds: a date-only `from` starts that day and a date-only `to` ends that day (both
 * inclusive, in $timezone, default Asia/Kolkata); a date-time is used as given (from
 * inclusive, to exclusive). Events are counted by `occurred_at` (when it happened in
 * Engage, not when Reach received it).
 *
 * Definitions:
 *   - by_stage / conversions count distinct leads (a lead re-sent `converted` twice is one
 *     conversion). An unmatched event counts by its Engage lead_code.
 *   - revenue_inr        sum of value_inr on payment-confirmed events (stage
 *                        `payment_confirmed`, or sales_status `payment_confirmed` / `paid`).
 *   - converted_value_inr sum of value_inr on `converted` events (deal value Engage reported
 *                        at conversion; not money received).
 *   Money is a NUMERIC(14,2) string, e.g. "1234.50". Nothing is estimated: no events ⇒ zeros.
 */
final class LeadOutcomeSummary
{
    private const LEAD_KEY  = "COALESCE('L' || o.lead_id::text, 'C' || o.engage_lead_code, 'E' || o.event_id)";
    public const PAID       = "(o.stage = 'payment_confirmed' OR o.sales_status IN ('payment_confirmed', 'paid'))";
    private const CONVERTED = "o.stage = 'converted'";

    /**
     * @return array{
     *   period: array{from:string, to:string, timezone:string},
     *   events:int, matched:int, unmatched:int, conversions:int,
     *   revenue_inr:string, converted_value_inr:string,
     *   by_stage: array<string,int>,
     *   by_source: list<array<string,mixed>>, by_campaign: list<array<string,mixed>>,
     *   by_content: list<array<string,mixed>>, by_seller: list<array<string,mixed>>
     * }
     */
    public static function forPeriod(string $from, string $to, string $timezone = 'Asia/Kolkata', ?BaseConnection $db = null): array
    {
        [$start, $end] = self::bounds($from, $to, $timezone);
        $db ??= Database::connect();
        $where  = 'o.occurred_at >= ?::timestamptz AND o.occurred_at < ?::timestamptz';
        $params = [$start->format('c'), $end->format('c')];

        $totals = $db->query(
            'SELECT COUNT(*) AS events,
                    COUNT(o.lead_id) AS matched,
                    COUNT(DISTINCT ' . self::LEAD_KEY . ') FILTER (WHERE ' . self::CONVERTED . ') AS conversions,
                    COALESCE(SUM(o.value_inr) FILTER (WHERE ' . self::PAID . '), 0)::numeric(14,2)::text AS revenue_inr,
                    COALESCE(SUM(o.value_inr) FILTER (WHERE ' . self::CONVERTED . '), 0)::numeric(14,2)::text AS converted_value_inr
               FROM reach_lead_outcomes o WHERE ' . $where,
            $params,
        )->getRowArray() ?? [];

        $byStage = array_fill_keys(LeadOutcomeIngestService::KNOWN_STAGES, 0);
        foreach ($db->query(
            'SELECT o.stage, COUNT(DISTINCT ' . self::LEAD_KEY . ') AS leads
               FROM reach_lead_outcomes o WHERE ' . $where . ' GROUP BY o.stage ORDER BY o.stage',
            $params,
        )->getResultArray() as $r) {
            $byStage[(string) $r['stage']] = (int) $r['leads'];
        }

        $metrics = 'COUNT(*) AS events,
                    COUNT(DISTINCT ' . self::LEAD_KEY . ') FILTER (WHERE ' . self::CONVERTED . ') AS conversions,
                    COALESCE(SUM(o.value_inr) FILTER (WHERE ' . self::PAID . '), 0)::numeric(14,2)::text AS revenue_inr,
                    COALESCE(SUM(o.value_inr) FILTER (WHERE ' . self::CONVERTED . '), 0)::numeric(14,2)::text AS converted_value_inr';
        $order = 'ORDER BY SUM(o.value_inr) FILTER (WHERE ' . self::PAID . ') DESC NULLS LAST, conversions DESC, events DESC';

        $bySource = $db->query(
            "SELECT COALESCE(o.source, 'unattributed') AS source, {$metrics}
               FROM reach_lead_outcomes o WHERE {$where}
              GROUP BY COALESCE(o.source, 'unattributed') {$order}",
            $params,
        )->getResultArray();

        $byCampaign = $db->query(
            "SELECT o.campaign_id, c.name AS campaign_name, c.campaign_type, {$metrics}
               FROM reach_lead_outcomes o
               LEFT JOIN reach_campaigns c ON c.id = o.campaign_id
              WHERE {$where}
              GROUP BY o.campaign_id, c.name, c.campaign_type {$order}",
            $params,
        )->getResultArray();

        $byContent = $db->query(
            "SELECT o.content_identity_id, ci.content_type, ci.canonical_url, {$metrics}
               FROM reach_lead_outcomes o
               JOIN reach_content_identities ci ON ci.id = o.content_identity_id
              WHERE {$where}
              GROUP BY o.content_identity_id, ci.content_type, ci.canonical_url {$order}",
            $params,
        )->getResultArray();

        $bySeller = $db->query(
            "SELECT o.seller_code, {$metrics}
               FROM reach_lead_outcomes o
              WHERE {$where} AND o.seller_code IS NOT NULL
              GROUP BY o.seller_code {$order}",
            $params,
        )->getResultArray();

        $events  = (int) ($totals['events'] ?? 0);
        $matched = (int) ($totals['matched'] ?? 0);

        return [
            'period'              => ['from' => $start->format('c'), 'to' => $end->format('c'), 'timezone' => $timezone],
            'events'              => $events,
            'matched'             => $matched,
            'unmatched'           => $events - $matched,
            'conversions'         => (int) ($totals['conversions'] ?? 0),
            'revenue_inr'         => (string) ($totals['revenue_inr'] ?? '0.00'),
            'converted_value_inr' => (string) ($totals['converted_value_inr'] ?? '0.00'),
            'by_stage'            => $byStage,
            'by_source'           => array_map(self::row(...), $bySource),
            'by_campaign'         => array_map(static function (array $r): array {
                $r = self::row($r);
                $r['campaign_id'] = $r['campaign_id'] !== null ? (int) $r['campaign_id'] : null;

                return $r;
            }, $byCampaign),
            'by_content'          => array_map(static function (array $r): array {
                $r = self::row($r);
                $r['content_identity_id'] = (int) $r['content_identity_id'];

                return $r;
            }, $byContent),
            'by_seller'           => array_map(self::row(...), $bySeller),
        ];
    }

    /**
     * @return array{0:DateTimeImmutable, 1:DateTimeImmutable} [inclusive start, exclusive end]
     */
    public static function bounds(string $from, string $to, string $timezone = 'Asia/Kolkata'): array
    {
        try {
            $tz = new DateTimeZone($timezone);
        } catch (\Throwable) {
            throw new InvalidArgumentException("Unknown timezone: {$timezone}");
        }
        $parse = static function (string $v, bool $isEnd) use ($tz): DateTimeImmutable {
            $v = trim($v);
            if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $v) === 1) {
                $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v, $tz);
                if ($d === false || $d->format('Y-m-d') !== $v) {
                    throw new InvalidArgumentException("Invalid date: {$v}");
                }

                return $isEnd ? $d->modify('+1 day') : $d;
            }
            if (preg_match('/\A\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $v) !== 1) {
                throw new InvalidArgumentException("Invalid date-time: {$v}");
            }
            try {
                return new DateTimeImmutable($v, $tz);
            } catch (\Throwable) {
                throw new InvalidArgumentException("Invalid date-time: {$v}");
            }
        };
        $start = $parse($from, false);
        $end   = $parse($to, true);
        if ($end <= $start) {
            throw new InvalidArgumentException('The period ends before it starts.');
        }

        return [$start, $end];
    }

    /** @param array<string,mixed> $r */
    private static function row(array $r): array
    {
        $r['events']      = (int) $r['events'];
        $r['conversions'] = (int) $r['conversions'];

        return $r;
    }
}
