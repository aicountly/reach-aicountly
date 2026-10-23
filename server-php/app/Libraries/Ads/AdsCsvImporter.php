<?php

declare(strict_types=1);

namespace App\Libraries\Ads;

use CodeIgniter\Database\BaseConnection;

/**
 * Imports daily results from each ads manager's standard CSV export, and the
 * sign-ups report my.aicountly.com produces, into reach_ad_results.
 *
 * Formats (header names are matched case-insensitively; title rows above the
 * header, as Google Ads and LinkedIn add, are skipped):
 *
 *   google_ads         Google Ads → Campaigns → Download (CSV), segmented by Day:
 *                      Day, Campaign, Cost, Impr., Clicks, Conversions[, Currency code]
 *   meta               Meta Ads Manager → Export table (CSV), Breakdown → By time → Day:
 *                      Day (or Reporting starts = Reporting ends), Campaign name,
 *                      Amount spent (INR), Impressions, Link clicks, Results
 *   linkedin           LinkedIn Campaign Manager → Export → Campaign performance,
 *                      time breakdown Daily: Start Date (in UTC), Campaign Name,
 *                      Total Spent, Impressions, Clicks, Conversions[, Currency]
 *   aicountly_signups  my.aicountly.com `php spark accounts:acquisition-report`:
 *                      date, utm_source, utm_medium, utm_campaign, utm_content, signups
 *
 * A row is matched to a registered campaign by its ads-manager name
 * (external_ref, else name) within the format's platforms; a sign-ups row by
 * utm_source + utm_campaign (+ utm_medium when that is needed to tell two
 * campaigns apart). Rows for the same campaign and day are summed; importing a
 * file replaces that day's figures from the same source, so re-importing it
 * changes nothing. Totals rows are skipped; a row that cannot be read is
 * rejected with its reason and never stored as zero; a figure in a currency
 * other than INR is refused. Nothing is fetched from any ads platform.
 */
final class AdsCsvImporter
{
    public const FORMATS = [
        'google_ads'        => ['label' => 'Google Ads — campaign report by day (Day, Campaign, Cost, Impr., Clicks, Conversions)', 'platforms' => ['google_search', 'youtube']],
        'meta'              => ['label' => 'Meta Ads Manager — export with Breakdown → By time → Day (Day, Campaign name, Amount spent (INR), Impressions, Link clicks, Results)', 'platforms' => ['meta']],
        'linkedin'          => ['label' => 'LinkedIn Campaign Manager — campaign performance, daily (Start Date (in UTC), Campaign Name, Total Spent, Impressions, Clicks, Conversions)', 'platforms' => ['linkedin']],
        'aicountly_signups' => ['label' => 'Aicountly sign-ups report from my.aicountly.com (date, utm_source, utm_medium, utm_campaign, utm_content, signups)', 'platforms' => ['google_search', 'youtube', 'meta', 'linkedin']],
    ];

    public const MAX_BYTES = 2_000_000;
    public const MAX_ROWS  = 20_000;
    private const LIST_LIMIT = 200;

    /** Column aliases per format. `sig` columns identify the format when auto-detecting. */
    private const COLUMNS = [
        'google_ads' => [
            'date'        => ['day', 'date'],
            'campaign'    => ['campaign', 'campaign name'],
            'spend'       => ['cost', 'cost (inr)', 'spend'],
            'impressions' => ['impr.', 'impr', 'impressions'],
            'clicks'      => ['clicks'],
            'conversions' => ['conversions', 'conv.'],
            'currency'    => ['currency code', 'currency'],
            'sig'         => ['cost', 'cost (inr)', 'impr.', 'impr'],
        ],
        'meta' => [
            'date'        => ['day', 'date'],
            'start'       => ['reporting starts'],
            'end'         => ['reporting ends'],
            'campaign'    => ['campaign name', 'campaign'],
            'spend'       => ['amount spent (inr)', 'amount spent'],
            'impressions' => ['impressions'],
            'clicks'      => ['link clicks', 'clicks (all)', 'clicks'],
            'conversions' => ['results', 'leads', 'website registrations completed', 'registrations completed'],
            'currency'    => ['currency'],
            'sig'         => ['amount spent (inr)', 'amount spent', 'reporting starts'],
        ],
        'linkedin' => [
            'date'        => ['start date (in utc)', 'start date', 'day', 'date'],
            'end'         => ['end date (in utc)', 'end date'],
            'campaign'    => ['campaign name', 'campaign'],
            'spend'       => ['total spent', 'amount spent', 'spend'],
            'impressions' => ['impressions'],
            'clicks'      => ['clicks'],
            'conversions' => ['conversions', 'total conversions'],
            'currency'    => ['currency'],
            'sig'         => ['total spent', 'start date (in utc)'],
        ],
        'aicountly_signups' => [
            'date'         => ['date'],
            'utm_source'   => ['utm_source'],
            'utm_medium'   => ['utm_medium'],
            'utm_campaign' => ['utm_campaign'],
            'utm_content'  => ['utm_content'],
            'signups'      => ['signups'],
            'sig'          => ['signups'],
        ],
    ];

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * Read a CSV into normalised rows. Pure: no database.
     *
     * @return array{format: ?string, error: ?string, rows: list<array<string, mixed>>, rejected: list<array{line:int, reason:string}>, skipped: int, rows_read: int}
     */
    public static function parse(string $csv, string $format = 'auto'): array
    {
        $out = ['format' => null, 'error' => null, 'rows' => [], 'rejected' => [], 'skipped' => 0, 'rows_read' => 0];
        if (strlen($csv) > self::MAX_BYTES) {
            $out['error'] = 'The file is larger than 2 MB. Export a shorter date range.';

            return $out;
        }
        $csv = self::toUtf8($csv);
        $lines = self::rows($csv);
        if ($lines === []) {
            $out['error'] = 'The file is empty.';

            return $out;
        }

        $candidates = $format === 'auto' ? ['aicountly_signups', 'linkedin', 'meta', 'google_ads'] : [$format];
        foreach ($candidates as $f) {
            if (! isset(self::COLUMNS[$f])) {
                $out['error'] = 'Unknown format. Use one of: ' . implode(', ', array_keys(self::FORMATS)) . '.';

                return $out;
            }
            $found = self::findHeader($lines, $f, $format === 'auto');
            if ($found !== null) {
                [$headerIndex, $map] = $found;
                $out['format'] = $f;
                break;
            }
        }
        if ($out['format'] === null) {
            $out['error'] = $format === 'auto'
                ? 'Could not recognise the export. Choose the format, and export with a daily breakdown.'
                : 'The header row was not found: ' . self::FORMATS[$format]['label'] . '.';

            return $out;
        }
        $f = $out['format'];

        // A currency named in the spend header (Meta: "Amount spent (USD)").
        foreach (array_keys($map['_header']) as $h) {
            if (preg_match('/^(?:amount spent|cost|total spent) \(([a-z]{3})\)$/', (string) $h, $m) === 1 && $m[1] !== 'inr') {
                $out['error'] = 'Spend is in ' . strtoupper($m[1]) . '. Reach records ads spend in INR only; export the account in INR.';

                return $out;
            }
        }

        for ($i = $headerIndex + 1, $n = count($lines); $i < $n; $i++) {
            $line = $i + 1;
            $cells = $lines[$i];
            if (count(array_filter($cells, static fn ($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }
            if (++$out['rows_read'] > self::MAX_ROWS) {
                $out['error'] = 'More than ' . self::MAX_ROWS . ' rows. Export a shorter date range.';
                $out['rows'] = [];

                return $out;
            }
            $get = static fn (string $col): string => isset($map[$col]) ? trim((string) ($cells[$map[$col]] ?? '')) : '';

            $row = $f === 'aicountly_signups' ? self::signupsRow($get) : self::platformRow($f, $get);
            if ($row === 'skip') {
                $out['skipped']++;
                continue;
            }
            if (is_string($row)) {
                $out['rejected'][] = ['line' => $line, 'reason' => $row];
                continue;
            }
            $out['rows'][] = ['line' => $line] + $row;
        }

        return $out;
    }

    /**
     * Parse, match and (unless $dryRun) store.
     *
     * @return array<string, mixed>
     */
    public function import(string $csv, string $format, bool $dryRun, ?int $userId): array
    {
        $parsed = self::parse($csv, $format);
        $report = [
            'format'               => $parsed['format'],
            'dry_run'              => $dryRun,
            'rows_read'            => $parsed['rows_read'],
            'rows_used'            => 0,
            'skipped'              => $parsed['skipped'],
            'stored_days'          => 0,
            'unattributed_signups' => 0,
            'campaigns'            => [],
            'unmatched'            => [],
            'rejected'             => array_slice($parsed['rejected'], 0, self::LIST_LIMIT),
            'rejected_total'       => count($parsed['rejected']),
            'error'                => $parsed['error'],
        ];
        if ($parsed['error'] !== null) {
            return $report;
        }
        $f = (string) $parsed['format'];
        $campaigns = $this->db->table('reach_ad_campaigns')
            ->whereIn('platform', self::FORMATS[$f]['platforms'])
            ->where('status !=', 'archived')
            ->get()->getResultArray();

        /** @var array<string, array<string, mixed>> $days  key "campaignId|date" */
        $days = [];
        $unmatched = [];
        foreach ($parsed['rows'] as $row) {
            if ($f === 'aicountly_signups') {
                if ($row['utm_source'] === '' && $row['utm_campaign'] === '') {
                    $report['unattributed_signups'] += $row['signups'];
                    continue;
                }
                [$id, $why] = self::matchUtm($campaigns, $row);
            } else {
                [$id, $why] = self::matchName($campaigns, $row['campaign']);
            }
            if ($id === null) {
                $unmatched[] = ['line' => $row['line'], 'campaign' => $f === 'aicountly_signups'
                    ? trim($row['utm_source'] . ' / ' . $row['utm_medium'] . ' / ' . $row['utm_campaign'], ' /')
                    : $row['campaign'], 'reason' => $why];
                continue;
            }
            $report['rows_used']++;
            $key = $id . '|' . $row['date'];
            $days[$key] ??= ['ad_campaign_id' => $id, 'result_date' => $row['date']];
            foreach (['spend', 'impressions', 'clicks', 'conversions', 'signups'] as $m) {
                if (array_key_exists($m, $row) && $row[$m] !== null) {
                    $days[$key][$m] = ($days[$key][$m] ?? 0) + $row[$m];
                } elseif (array_key_exists($m, $row)) {
                    $days[$key][$m] ??= null;
                }
            }
        }
        $report['unmatched'] = array_slice($unmatched, 0, self::LIST_LIMIT);
        $report['unmatched_total'] = count($unmatched);

        $byCampaign = [];
        foreach ($days as $d) {
            $c = &$byCampaign[$d['ad_campaign_id']];
            $c ??= ['ad_campaign_id' => $d['ad_campaign_id'], 'days' => 0, 'spend_inr' => null, 'impressions' => null, 'clicks' => null, 'conversions' => null, 'signups' => null];
            $c['days']++;
            foreach (['spend' => 'spend_inr', 'impressions' => 'impressions', 'clicks' => 'clicks', 'conversions' => 'conversions', 'signups' => 'signups'] as $from => $to) {
                if (isset($d[$from])) {
                    $c[$to] = ($c[$to] ?? 0) + $d[$from];
                }
            }
            unset($c);
        }
        $names = array_column($campaigns, 'name', 'id');
        foreach ($byCampaign as &$c) {
            $c['name'] = $names[$c['ad_campaign_id']] ?? null;
            if ($c['spend_inr'] !== null) {
                $c['spend_inr'] = round((float) $c['spend_inr'], 2);
            }
        }
        unset($c);
        $report['campaigns'] = array_values($byCampaign);
        $report['stored_days'] = $dryRun ? 0 : count($days);

        if (! $dryRun && $days !== []) {
            $this->store($f, array_values($days), $userId);
        }

        return $report;
    }

    /** @param list<array<string, mixed>> $days */
    private function store(string $format, array $days, ?int $userId): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->transStart();
        foreach ($days as $d) {
            if ($format === 'aicountly_signups') {
                $values = ['signups' => isset($d['signups']) ? (int) $d['signups'] : null, 'signups_source' => 'portal_report'];
            } else {
                $values = [
                    'spend_inr'      => isset($d['spend']) ? number_format((float) $d['spend'], 2, '.', '') : null,
                    'impressions'    => isset($d['impressions']) ? (int) $d['impressions'] : null,
                    'clicks'         => isset($d['clicks']) ? (int) $d['clicks'] : null,
                    'conversions'    => isset($d['conversions']) ? number_format((float) $d['conversions'], 2, '.', '') : null,
                    'metrics_source' => 'csv:' . $format,
                ];
            }
            $existing = $this->db->table('reach_ad_results')
                ->where('ad_campaign_id', $d['ad_campaign_id'])->where('result_date', $d['result_date'])
                ->get()->getRowArray();
            if ($existing) {
                $this->db->table('reach_ad_results')->where('id', $existing['id'])->update($values + ['updated_at' => $now]);
            } else {
                $this->db->table('reach_ad_results')->insert($values + [
                    'ad_campaign_id' => $d['ad_campaign_id'], 'result_date' => $d['result_date'],
                    'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
        $this->db->transComplete();
        if ($this->db->transStatus() === false) {
            throw new \RuntimeException('The import could not be stored; nothing was changed.');
        }
    }

    /** @return array{0: ?int, 1: string} */
    private static function matchName(array $campaigns, string $name): array
    {
        $want = mb_strtolower(trim($name));
        $hits = [];
        foreach ($campaigns as $c) {
            if (mb_strtolower(trim((string) ($c['external_ref'] ?? ''))) === $want || mb_strtolower(trim((string) $c['name'])) === $want) {
                $hits[] = (int) $c['id'];
            }
        }
        $hits = array_values(array_unique($hits));
        if (count($hits) === 1) {
            return [$hits[0], ''];
        }

        return [null, $hits === []
            ? 'No registered campaign has this ads-manager name. Register it (or set its ads-manager name) first.'
            : 'More than one registered campaign has this ads-manager name.'];
    }

    /** @return array{0: ?int, 1: string} */
    private static function matchUtm(array $campaigns, array $row): array
    {
        $hits = array_values(array_filter($campaigns, static fn (array $c): bool =>
            strtolower((string) $c['utm_source']) === strtolower($row['utm_source'])
            && strtolower((string) $c['utm_campaign']) === strtolower($row['utm_campaign'])));
        if (count($hits) > 1 && $row['utm_medium'] !== '') {
            $hits = array_values(array_filter($hits, static fn (array $c): bool => strtolower((string) $c['utm_medium']) === strtolower($row['utm_medium'])));
        }
        if (count($hits) === 1) {
            return [(int) $hits[0]['id'], ''];
        }

        return [null, $hits === []
            ? 'No registered campaign uses this utm_source and utm_campaign.'
            : 'More than one registered campaign uses this UTM set; give each its own utm_campaign or utm_medium.'];
    }

    /** @return array<string, mixed>|string a row, a rejection reason, or 'skip' */
    private static function platformRow(string $f, callable $get): array|string
    {
        $campaign = $get('campaign');
        if ($campaign === '' || preg_match('/^total\b/i', $campaign) === 1) {
            return 'skip';
        }
        $currency = strtoupper($get('currency'));
        if ($currency !== '' && $currency !== 'INR') {
            return "Currency {$currency}: Reach records ads spend in INR only.";
        }
        $rawDate = $get('date');
        if ($rawDate === '' && $f === 'meta') {
            $rawDate = $get('start');
        }
        $date = self::parseDate($rawDate, $f);
        if ($date === null) {
            return "Unreadable date '{$rawDate}'. Export with a daily breakdown and dates as YYYY-MM-DD.";
        }
        $end = $get('end');
        if ($end !== '' && self::parseDate($end, $f) !== $date) {
            return 'This row covers more than one day. Export with a daily breakdown (By time → Day / Daily).';
        }

        $row = ['date' => $date, 'campaign' => $campaign];
        foreach (['spend' => false, 'impressions' => true, 'clicks' => true, 'conversions' => false] as $col => $int) {
            [$ok, $value] = self::number($get($col), $int);
            if (! $ok) {
                return "Unreadable {$col} '" . $get($col) . "'.";
            }
            $row[$col] = $value;
        }
        if ($row['spend'] === null && $row['impressions'] === null && $row['clicks'] === null && $row['conversions'] === null) {
            return 'skip';
        }

        return $row;
    }

    /** @return array<string, mixed>|string */
    private static function signupsRow(callable $get): array|string
    {
        $date = self::parseDate($get('date'), 'aicountly_signups');
        if ($date === null) {
            return "Unreadable date '" . $get('date') . "'.";
        }
        [$ok, $signups] = self::number($get('signups'), true);
        if (! $ok || $signups === null) {
            return "Unreadable signups '" . $get('signups') . "'.";
        }
        $clean = static fn (string $v): string => strtolower(ltrim($v, "'"));

        return [
            'date'         => $date,
            'utm_source'   => $clean($get('utm_source')),
            'utm_medium'   => $clean($get('utm_medium')),
            'utm_campaign' => $clean($get('utm_campaign')),
            'utm_content'  => $clean($get('utm_content')),
            'signups'      => (int) $signups,
        ];
    }

    /** @return array{0: bool, 1: int|float|null} */
    private static function number(string $raw, bool $int): array
    {
        $v = trim($raw);
        if ($v === '' || $v === '--' || $v === '-' || $v === '—') {
            return [true, null];
        }
        $v = str_replace(['₹', ',', ' ', "\u{00A0}"], '', $v);
        $v = preg_replace('/^(?:rs\.?|inr)/i', '', $v) ?? $v;
        if (preg_match('/\A\d+(?:\.\d+)?\z/', $v) !== 1) {
            return [false, null];
        }
        if ($int) {
            if (str_contains($v, '.') && (float) $v !== floor((float) $v)) {
                return [false, null];
            }

            return [true, (int) $v];
        }

        return [true, round((float) $v, 2)];
    }

    /** Y-m-d from the date shapes the exports use; null when unreadable or ambiguous. */
    public static function parseDate(string $raw, string $format): ?string
    {
        $raw = trim($raw);
        if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})/', $raw, $m) === 1) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
        }
        if (preg_match('/\A(\d{1,2})\/(\d{1,2})\/(\d{4})\z/', $raw, $m) === 1) {
            [$a, $b, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            if ($format === 'linkedin') {
                [$mo, $d] = [$a, $b];             // LinkedIn exports US dates
            } elseif ($a > 12 && $b <= 12) {
                [$mo, $d] = [$b, $a];
            } elseif ($b > 12 && $a <= 12) {
                [$mo, $d] = [$a, $b];
            } else {
                return null;                      // 05/06/2026: day or month first? refuse to guess
            }

            return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
        }
        foreach (['M j, Y', 'F j, Y', 'j M Y', 'D, M j, Y', 'l, F j, Y'] as $shape) {
            $dt = \DateTimeImmutable::createFromFormat('!' . $shape, $raw);
            if ($dt !== false && $dt->format($shape) === $raw) {
                return $dt->format('Y-m-d');
            }
        }

        return null;
    }

    /** @return array{0:int, 1:array<string,mixed>}|null header row index and column map */
    private static function findHeader(array $lines, string $f, bool $needSignature): ?array
    {
        $cols = self::COLUMNS[$f];
        $limit = min(count($lines), 20);
        for ($i = 0; $i < $limit; $i++) {
            $header = [];
            foreach ($lines[$i] as $idx => $cell) {
                $h = strtolower(trim(preg_replace('/\s+/', ' ', (string) $cell) ?? ''));
                if ($h !== '' && ! isset($header[$h])) {
                    $header[$h] = $idx;
                }
            }
            $map = ['_header' => $header];
            foreach ($cols as $col => $aliases) {
                if ($col === 'sig') {
                    continue;
                }
                foreach ($aliases as $alias) {
                    if (isset($header[$alias])) {
                        $map[$col] = $header[$alias];
                        break;
                    }
                }
            }
            if ($f !== 'aicountly_signups' && ! isset($map['spend'])) {
                // Spend named with another currency ("Amount spent (USD)"): found
                // here so that parse() can refuse it by name, not as "no header".
                foreach ($header as $h => $idx) {
                    if (preg_match('/^(?:amount spent|cost|total spent) \([a-z]{3}\)$/', (string) $h) === 1) {
                        $map['spend'] = $idx;
                        break;
                    }
                }
            }
            $required = $f === 'aicountly_signups'
                ? ['date', 'utm_source', 'utm_campaign', 'signups']
                : ['campaign', 'spend'];
            $hasDate = isset($map['date']) || isset($map['start']);
            if (! $hasDate || array_diff($required, array_keys($map)) !== []) {
                continue;
            }
            if ($needSignature && array_intersect($cols['sig'], array_keys($header)) === []) {
                continue;
            }

            return [$i, $map];
        }

        return null;
    }

    /** @return list<list<string>> */
    private static function rows(string $csv): array
    {
        $first = strtok($csv, "\n") ?: '';
        $delimiter = substr_count($first, "\t") > substr_count($first, ',') ? "\t" : ',';
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $csv);
        rewind($fh);
        $rows = [];
        while (($cells = fgetcsv($fh, 0, $delimiter, '"', '')) !== false) {
            if ($cells === [null]) {
                $rows[] = [];
                continue;
            }
            $rows[] = array_map(static fn ($c) => (string) $c, $cells);
        }
        fclose($fh);

        return $rows;
    }

    private static function toUtf8(string $csv): string
    {
        if (str_starts_with($csv, "\xFF\xFE")) {
            $csv = (string) mb_convert_encoding(substr($csv, 2), 'UTF-8', 'UTF-16LE');
        } elseif (str_starts_with($csv, "\xFE\xFF")) {
            $csv = (string) mb_convert_encoding(substr($csv, 2), 'UTF-8', 'UTF-16BE');
        }
        if (str_starts_with($csv, "\xEF\xBB\xBF")) {
            $csv = substr($csv, 3);
        }
        if (! mb_check_encoding($csv, 'UTF-8')) {
            $csv = (string) mb_convert_encoding($csv, 'UTF-8', 'Windows-1252');
        }

        return str_replace("\r\n", "\n", $csv);
    }
}
