<?php

declare(strict_types=1);

namespace App\Libraries\Ads;

use CodeIgniter\Database\BaseConnection;

/**
 * GET v1/ads/summary — paid-campaign figures for the dashboard and plan views.
 *
 * Sources, and nothing else:
 *   - spend / impressions / clicks / platform conversions: reach_ad_results,
 *     typed in or imported from each ads manager's CSV export;
 *   - sign-ups: the my.aicountly.com sign-ups report imported per campaign day;
 *   - leads: Reach's own lead capture (reach_leads), attributed by the UTM set
 *     the lead arrived with (utm_source + utm_campaign, and utm_medium when the
 *     lead has one) within the campaign's dates.
 *
 * A figure with no underlying entry is null, not 0, and ratios are null when
 * their denominator is. Nothing here is read from an ads platform.
 */
final class AdsSummary
{
    public const SOURCE_NOTE = 'Figures are typed in or imported from each ads manager\'s CSV export, and sign-ups from the my.aicountly.com report. Reach is not connected to the Google Ads, Meta or LinkedIn ads APIs.';

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(string $from, string $to, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $campaigns = $this->db->query(
            "SELECT * FROM reach_ad_campaigns
              WHERE status <> 'archived' AND start_date <= ? AND (end_date IS NULL OR end_date >= ?)
              ORDER BY start_date, id",
            [$to, $from],
        )->getResultArray();

        $results = [];
        foreach ($this->db->query(
            'SELECT ad_campaign_id,
                    COUNT(*) AS days,
                    SUM(spend_inr) AS spend_inr, COUNT(spend_inr) AS spend_days,
                    SUM(impressions) AS impressions, SUM(clicks) AS clicks,
                    SUM(conversions) AS conversions, SUM(signups) AS signups, COUNT(signups) AS signup_days,
                    MAX(result_date) AS last_result_date
               FROM reach_ad_results
              WHERE result_date BETWEEN ? AND ?
              GROUP BY ad_campaign_id',
            [$from, $to],
        )->getResultArray() as $r) {
            $results[(int) $r['ad_campaign_id']] = $r;
        }
        $resultDays = [];
        foreach ($this->db->query(
            'SELECT ad_campaign_id, result_date FROM reach_ad_results WHERE result_date BETWEEN ? AND ? AND spend_inr IS NOT NULL',
            [$from, $to],
        )->getResultArray() as $r) {
            $resultDays[(int) $r['ad_campaign_id']][(string) $r['result_date']] = true;
        }

        // Campaigns sharing a UTM set cannot be told apart in lead data.
        $keyCount = [];
        foreach ($campaigns as $c) {
            $keyCount[self::utmKey($c)] = ($keyCount[self::utmKey($c)] ?? 0) + 1;
        }

        $rows = [];
        $leadsCountedKeys = [];
        $totals = ['spend_inr' => null, 'impressions' => null, 'clicks' => null, 'conversions' => null, 'signups' => null, 'leads' => 0];
        $byPlatform = [];
        foreach ($campaigns as $c) {
            $id = (int) $c['id'];
            $r = $results[$id] ?? null;
            $windowFrom = max($from, (string) $c['start_date']);
            $windowTo = min($to, (string) ($c['end_date'] ?? $to));
            [$leads, $leadsByCreative] = $this->leads($c, $windowFrom, $windowTo);

            $spend = $r && (int) $r['spend_days'] > 0 ? round((float) $r['spend_inr'], 2) : null;
            $row = [
                'id'                 => $id,
                'name'               => $c['name'],
                'platform'           => $c['platform'],
                'objective'          => $c['objective'],
                'status'             => $c['status'],
                'start_date'         => $c['start_date'],
                'end_date'           => $c['end_date'],
                'daily_budget_inr'   => $c['daily_budget_inr'] !== null ? (float) $c['daily_budget_inr'] : null,
                'utm'                => ['source' => $c['utm_source'], 'medium' => $c['utm_medium'], 'campaign' => $c['utm_campaign']],
                'spend_inr'          => $spend,
                'impressions'        => $r && $r['impressions'] !== null ? (int) $r['impressions'] : null,
                'clicks'             => $r && $r['clicks'] !== null ? (int) $r['clicks'] : null,
                'platform_conversions' => $r && $r['conversions'] !== null ? round((float) $r['conversions'], 2) : null,
                'signups'            => $r && (int) $r['signup_days'] > 0 ? (int) $r['signups'] : null,
                'leads'              => $leads,
                'leads_by_creative'  => $leadsByCreative,
                'last_result_date'   => $r['last_result_date'] ?? null,
                'days_missing_results' => $c['status'] === 'live' ? $this->missingDays($windowFrom, min($windowTo, self::yesterday($today)), $resultDays[$id] ?? []) : null,
                'attribution_warning' => $keyCount[self::utmKey($c)] > 1 ? 'Another campaign uses the same utm_source, utm_medium and utm_campaign; leads cannot be told apart.' : null,
            ];
            $row += self::ratios($row);
            $rows[] = $row;

            foreach (['spend_inr', 'impressions', 'clicks', 'signups'] as $k) {
                if ($row[$k] !== null) {
                    $totals[$k] = ($totals[$k] ?? 0) + $row[$k];
                }
            }
            if ($row['platform_conversions'] !== null) {
                $totals['conversions'] = ($totals['conversions'] ?? 0) + $row['platform_conversions'];
            }
            if (! isset($leadsCountedKeys[self::utmKey($c)])) {
                $totals['leads'] += $leads;
                $leadsCountedKeys[self::utmKey($c)] = true;
            }

            $p = &$byPlatform[$c['platform']];
            $p ??= ['platform' => $c['platform'], 'label' => AdsConventions::PLATFORMS[$c['platform']]['label'], 'campaigns' => 0, 'live' => 0,
                'planned_daily_budget_inr' => 0.0, 'spend_inr' => null, 'impressions' => null, 'clicks' => null, 'platform_conversions' => null, 'signups' => null, 'leads' => 0];
            $p['campaigns']++;
            if ($c['status'] === 'live') {
                $p['live']++;
                $p['planned_daily_budget_inr'] += (float) ($c['daily_budget_inr'] ?? 0);
            }
            foreach (['spend_inr', 'impressions', 'clicks', 'platform_conversions', 'signups'] as $k) {
                if ($row[$k] !== null) {
                    $p[$k] = ($p[$k] ?? 0) + $row[$k];
                }
            }
            $p['leads'] += $leads;
            unset($p);
        }
        foreach ($byPlatform as &$p) {
            $p += self::ratios($p);
        }
        unset($p);

        $totals['spend_inr'] = $totals['spend_inr'] !== null ? round((float) $totals['spend_inr'], 2) : null;
        $totals['platform_conversions'] = $totals['conversions'] !== null ? round((float) $totals['conversions'], 2) : null;
        unset($totals['conversions']);
        $totals += self::ratios($totals);

        $live = array_filter($campaigns, static fn (array $c): bool => $c['status'] === 'live');
        $planned = array_filter($campaigns, static fn (array $c): bool => $c['status'] === 'planned');

        return [
            'period'        => ['from' => $from, 'to' => $to],
            'source'        => self::SOURCE_NOTE,
            'api_connected' => false,
            'has_results'   => $results !== [],
            'totals'        => $totals,
            'budget'        => [
                'live_daily_inr'    => round(array_sum(array_map(static fn (array $c): float => (float) ($c['daily_budget_inr'] ?? 0), $live)), 2),
                'planned_daily_inr' => round(array_sum(array_map(static fn (array $c): float => (float) ($c['daily_budget_inr'] ?? 0), $planned)), 2),
                'launch_plan'       => ['daily_total_inr' => array_sum(AdsConventions::LAUNCH_PLAN), 'split' => AdsConventions::LAUNCH_PLAN, 'note' => 'Owner\'s planned split (example for planning, not spend).'],
            ],
            'counts'        => ['campaigns' => count($campaigns), 'live' => count($live), 'planned' => count($planned)],
            'by_platform'   => array_values($byPlatform),
            'campaigns'     => $rows,
            'generated_at'  => date(DATE_ATOM),
        ];
    }

    /** @return array{0:int, 1:list<array{utm_content:?string, leads:int}>} */
    private function leads(array $c, string $from, string $to): array
    {
        if ($from > $to) {
            return [0, []];
        }
        $rows = $this->db->query(
            "SELECT NULLIF(TRIM(utm_content), '') AS utm_content, COUNT(*) AS n
               FROM reach_leads
              WHERE LOWER(TRIM(utm_source)) = ? AND LOWER(TRIM(utm_campaign)) = ?
                AND (utm_medium IS NULL OR TRIM(utm_medium) = '' OR LOWER(TRIM(utm_medium)) = ?)
                AND created_at >= ?::date AND created_at < (?::date + INTERVAL '1 day')
              GROUP BY 1 ORDER BY 2 DESC",
            [strtolower((string) $c['utm_source']), strtolower((string) $c['utm_campaign']), strtolower((string) $c['utm_medium']), $from, $to],
        )->getResultArray();
        $total = 0;
        $by = [];
        foreach ($rows as $r) {
            $total += (int) $r['n'];
            $by[] = ['utm_content' => $r['utm_content'], 'leads' => (int) $r['n']];
        }

        return [$total, $by];
    }

    /** @param array<string, true> $have */
    private function missingDays(string $from, string $to, array $have): int
    {
        if ($from > $to) {
            return 0;
        }
        $missing = 0;
        for ($d = new \DateTimeImmutable($from), $end = new \DateTimeImmutable($to); $d <= $end; $d = $d->modify('+1 day')) {
            if (! isset($have[$d->format('Y-m-d')])) {
                $missing++;
            }
        }

        return $missing;
    }

    /** @return array<string, ?float> */
    private static function ratios(array $r): array
    {
        $div = static fn ($a, $b, int $p = 2): ?float => ($a === null || $b === null || (float) $b == 0.0) ? null : round((float) $a / (float) $b, $p);

        return [
            'ctr'             => $div($r['clicks'] ?? null, $r['impressions'] ?? null, 4),
            'cpc_inr'         => $div($r['spend_inr'] ?? null, $r['clicks'] ?? null),
            'cost_per_lead_inr'   => $div($r['spend_inr'] ?? null, ($r['leads'] ?? 0) ?: null),
            'cost_per_signup_inr' => $div($r['spend_inr'] ?? null, $r['signups'] ?? null),
        ];
    }

    private static function utmKey(array $c): string
    {
        return strtolower($c['utm_source'] . '|' . $c['utm_medium'] . '|' . $c['utm_campaign']);
    }

    private static function yesterday(string $today): string
    {
        return (new \DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
    }
}
