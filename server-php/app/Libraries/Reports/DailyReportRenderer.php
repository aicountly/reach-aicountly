<?php

declare(strict_types=1);

namespace App\Libraries\Reports;

/**
 * Turns a DailyReportBuilder summary into an email: inline-styled HTML (mail
 * clients strip <style>) and a plain-text part. Pure — no I/O.
 */
final class DailyReportRenderer
{
    private const C_TEXT   = '#1f2937';
    private const C_MUTED  = '#6b7280';
    private const C_BORDER = '#e5e7eb';
    private const C_UP     = '#047857';
    private const C_DOWN   = '#b91c1c';
    private const C_ACCENT = '#1d4ed8';

    /** @param array<string, mixed> $summary */
    public static function subject(array $summary): string
    {
        $date   = self::longDate((string) $summary['report_date']);
        $search = $summary['sections']['search'] ?? [];
        if (! empty($search['day'])) {
            $delta = self::deltaPct((float) $search['day']['clicks'], $search['baseline']['clicks'] ?? null);

            return sprintf('AICOUNTLY daily report — %s · %s search clicks%s', $date, number_format((int) $search['day']['clicks']),
                $delta === null ? '' : ' (' . $delta . ' vs 7d)');
        }

        return 'AICOUNTLY daily report — ' . $date;
    }

    /** @param array<string, mixed> $summary */
    public static function html(array $summary): string
    {
        $s = $summary['sections'];
        $body = '';
        $body .= self::section('What to do today', self::todoHtml($s['todo'] ?? []));
        $body .= self::section('Search (Google Search Console)', self::searchHtml($s['search'] ?? []));
        $body .= self::section('Website traffic (GA4)', self::trafficHtml($s['traffic'] ?? []));
        $body .= self::section('SEO Tracker', self::trackerHtml($s['seo_tracker'] ?? []));
        $body .= self::section('Content published (last 24h)', self::contentHtml($s['content'] ?? []));
        $body .= self::section('AI vetting (last 24h)', self::vettingHtml($s['vetting'] ?? []));
        $body .= self::section('Pipeline health', self::pipelineHtml($s['pipeline'] ?? []));

        $title = 'Daily analytics report — ' . self::e(self::longDate((string) $summary['report_date']));
        $meta  = 'Generated ' . self::e((string) $summary['generated_at']) . ' (' . self::e((string) $summary['timezone']) . ')';

        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>' . $title . '</title></head>'
            . '<body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:' . self::C_TEXT . ';">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;"><tr><td align="center" style="padding:24px 12px;">'
            . '<table role="presentation" width="640" cellpadding="0" cellspacing="0" style="max-width:640px;width:100%;background:#ffffff;border:1px solid ' . self::C_BORDER . ';border-radius:8px;">'
            . '<tr><td style="padding:20px 24px;border-bottom:1px solid ' . self::C_BORDER . ';">'
            . '<div style="font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:' . self::C_ACCENT . ';font-weight:700;">AICOUNTLY Reach</div>'
            . '<div style="font-size:20px;font-weight:700;margin-top:4px;">' . $title . '</div>'
            . '<div style="font-size:12px;color:' . self::C_MUTED . ';margin-top:4px;">' . $meta . '</div>'
            . '</td></tr>'
            . $body
            . '<tr><td style="padding:16px 24px;font-size:12px;color:' . self::C_MUTED . ';">Every figure comes from a connected source; a section that says “Not configured” has no source yet. Open Reach → Reports for the archive.</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    /** @param array<string, mixed> $summary */
    public static function text(array $summary): string
    {
        $s   = $summary['sections'];
        $out = [];
        $out[] = 'AICOUNTLY daily analytics report — ' . self::longDate((string) $summary['report_date']);
        $out[] = 'Generated ' . $summary['generated_at'] . ' (' . $summary['timezone'] . ')';
        $out[] = '';
        $out[] = '== What to do today ==';
        foreach (($s['todo']['items'] ?? []) as $i => $item) {
            $out[] = ($i + 1) . '. ' . $item;
        }

        $out[] = '';
        $out[] = '== Search ==';
        $search = $s['search'] ?? [];
        if (empty($search['day'])) {
            $out[] = self::noteOf($search);
        } else {
            $d = $search['day'];
            $b = $search['baseline'];
            $out[] = 'Day: ' . $search['date'] . ' (previous 7-day average in brackets)';
            $out[] = sprintf('Clicks %s [%s] · Impressions %s [%s] · CTR %s [%s] · Avg position %s [%s]',
                number_format($d['clicks']), self::num($b['clicks']), number_format($d['impressions']), self::num($b['impressions']),
                self::pct($d['ctr']), self::pct($b['ctr']), self::pos($d['avg_position']), self::pos($b['avg_position']));
            $out[] = 'Top queries:';
            foreach ($search['top_queries'] as $q) {
                $out[] = sprintf('  - %s: %d clicks, %d impr, pos %s', $q['dimension_value'], $q['clicks'], $q['impressions'], self::pos($q['avg_position']));
            }
            $out[] = 'Top pages:';
            foreach ($search['top_pages'] as $p) {
                $out[] = sprintf('  - %s: %d clicks, %d impr', $p['dimension_value'], $p['clicks'], $p['impressions']);
            }
            foreach (['improved' => 'Improved', 'declined' => 'Declined'] as $key => $label) {
                foreach ($search['movers'][$key] ?? [] as $m) {
                    $out[] = sprintf('  %s: %s %.1f → %.1f', $label, $m['query'], $m['previous_position'], $m['position']);
                }
            }
        }

        $out[] = '';
        $out[] = '== Website traffic (GA4) ==';
        $t = $s['traffic'] ?? [];
        if (! isset($t['users'])) {
            $out[] = self::noteOf($t);
        } else {
            $out[] = sprintf('%s: users %d [%s], sessions %d [%s]', $t['date'], $t['users'], self::num($t['baseline_users']), $t['sessions'], self::num($t['baseline_sessions']));
            foreach ($t['sources'] ?? [] as $src) {
                $out[] = sprintf('  - %s: %d sessions', $src['source'], $src['sessions']);
            }
        }

        $out[] = '';
        $out[] = '== SEO Tracker ==';
        $tr = $s['seo_tracker'] ?? [];
        if (empty($tr['date'])) {
            $out[] = self::noteOf($tr);
        } else {
            $sum = $tr['summary'];
            $out[] = sprintf('%s: %d keywords checked, we rank for %d (%d in top 10); %d up, %d down vs 7d; competitors win %d.',
                $tr['date'], $sum['checked'], $sum['we_rank'], $sum['top10'], $sum['up'], $sum['down'], $sum['competitor_wins']);
            foreach ($tr['beaten'] as $b) {
                $out[] = sprintf('  - "%s": %s #%d, us %s', $b['keyword'], $b['competitor'], $b['competitor_rank'], $b['our_rank'] === null ? 'not ranked' : '#' . $b['our_rank']);
            }
        }

        $out[] = '';
        $out[] = '== Content published (last 24h) ==';
        foreach (DailyReportBuilder::CONTENT_TYPES as $type => $label) {
            $n = $s['content']['counts'][$type] ?? null;
            $out[] = sprintf('  %s: %s', $label, $n === null ? 'not tracked yet' : (string) $n);
        }

        $out[] = '';
        $out[] = '== AI vetting (last 24h) ==';
        $v = $s['vetting'] ?? [];
        if (empty($v['configured']) || ! isset($v['counts'])) {
            $out[] = self::noteOf($v);
        } else {
            $out[] = sprintf('approve %d · revise %d · reject %d', $v['counts']['approve'], $v['counts']['revise'], $v['counts']['reject']);
            foreach ($v['top_reject_reasons'] as $r) {
                $out[] = sprintf('  - %s (%d)', $r['reason'], $r['count']);
            }
        }

        $out[] = '';
        $out[] = '== Pipeline health ==';
        $p = $s['pipeline'] ?? [];
        if (isset($p['failed_jobs_24h'])) {
            $out[] = sprintf('Failed jobs (24h) %d · dead letters %d · stalled %d · pending >1h %d', $p['failed_jobs_24h'], $p['dead_letters'], $p['stalled_jobs'], $p['pending_over_1h']);
        } else {
            $out[] = self::noteOf($p);
        }
        if (isset($p['email']) && $p['email'] !== '') {
            $out[] = 'Email: ' . $p['email'];
        }

        return implode("\n", $out) . "\n";
    }

    // ── section renderers ──────────────────────────────────────────────────

    /** @param array<string, mixed> $t */
    private static function todoHtml(array $t): string
    {
        $li = '';
        foreach ($t['items'] ?? [] as $item) {
            $li .= '<li style="margin:0 0 6px;">' . self::e($item) . '</li>';
        }

        return '<ol style="margin:0;padding-left:20px;font-size:14px;line-height:1.5;">' . $li . '</ol>';
    }

    /** @param array<string, mixed> $s */
    private static function searchHtml(array $s): string
    {
        if (empty($s['day'])) {
            return self::note($s);
        }
        $d = $s['day'];
        $b = $s['baseline'];
        $tiles = self::tiles([
            ['Clicks', number_format($d['clicks']), self::deltaPct((float) $d['clicks'], $b['clicks']), false],
            ['Impressions', number_format($d['impressions']), self::deltaPct((float) $d['impressions'], $b['impressions']), false],
            ['CTR', self::pct($d['ctr']), self::deltaPct((float) ($d['ctr'] ?? 0), $b['ctr']), false],
            ['Avg position', self::pos($d['avg_position']), $d['avg_position'] !== null && $b['avg_position'] !== null
                ? sprintf('%+.1f', $b['avg_position'] - $d['avg_position']) : null, true],
        ]);
        $intro = '<p style="margin:0 0 10px;font-size:13px;color:' . self::C_MUTED . ';">' . self::e($s['date'])
            . ' — the freshest day Google has published — against the average of ' . self::e($b['from']) . ' to ' . self::e($b['to']) . '.</p>';

        $queries = self::table(['Query', 'Clicks', 'Impr.', 'Pos.'], array_map(static fn ($q) => [
            $q['dimension_value'], number_format($q['clicks']), number_format($q['impressions']), self::pos($q['avg_position']),
        ], $s['top_queries']));
        $pages = self::table(['Page', 'Clicks', 'Impr.', 'CTR'], array_map(static fn ($p) => [
            self::shortUrl($p['dimension_value']), number_format($p['clicks']), number_format($p['impressions']), self::pct($p['ctr']),
        ], $s['top_pages']));

        $movers = '';
        foreach (['improved' => ['Biggest gains', self::C_UP], 'declined' => ['Biggest drops', self::C_DOWN]] as $key => [$label, $color]) {
            $rows = $s['movers'][$key] ?? [];
            if ($rows === []) {
                continue;
            }
            $movers .= '<div style="font-size:13px;font-weight:600;margin:12px 0 4px;color:' . $color . ';">' . $label . '</div>'
                . self::table(['Query', 'Was', 'Now', 'Change'], array_map(static fn ($m) => [
                    $m['query'], self::pos($m['previous_position']), self::pos($m['position']), sprintf('%+.1f', $m['change']),
                ], $rows));
        }

        return $intro . $tiles
            . '<div style="font-size:13px;font-weight:600;margin:14px 0 4px;">Top 10 queries</div>' . $queries
            . '<div style="font-size:13px;font-weight:600;margin:14px 0 4px;">Top 10 pages</div>' . $pages
            . $movers;
    }

    /** @param array<string, mixed> $t */
    private static function trafficHtml(array $t): string
    {
        if (! isset($t['users'])) {
            return self::note($t);
        }
        $tiles = self::tiles([
            ['Users', number_format($t['users']), self::deltaPct((float) $t['users'], $t['baseline_users']), false],
            ['Sessions', number_format($t['sessions']), self::deltaPct((float) $t['sessions'], $t['baseline_sessions']), false],
        ]);
        $sources = self::table(['Source / medium', 'Sessions', 'Users'], array_map(static fn ($r) => [
            $r['source'], number_format($r['sessions']), number_format($r['users']),
        ], $t['sources'] ?? []));

        return '<p style="margin:0 0 10px;font-size:13px;color:' . self::C_MUTED . ';">' . self::e($t['date']) . ' against the previous 7-day average.</p>'
            . $tiles . '<div style="font-size:13px;font-weight:600;margin:14px 0 4px;">Top sources</div>' . $sources;
    }

    /** @param array<string, mixed> $t */
    private static function trackerHtml(array $t): string
    {
        if (empty($t['date'])) {
            return self::note($t);
        }
        $sum   = $t['summary'];
        $tiles = self::tiles([
            ['Keywords checked', (string) $sum['checked'], null, false],
            ['We rank (top 100)', (string) $sum['we_rank'], null, false],
            ['In top 10', (string) $sum['top10'], null, false],
            ['Up / down vs 7d', $sum['up'] . ' / ' . $sum['down'], null, false],
        ]);
        $beaten = $t['beaten'] === []
            ? '<p style="margin:8px 0 0;font-size:13px;">No tracked keyword where a competitor outranks us.</p>'
            : '<div style="font-size:13px;font-weight:600;margin:14px 0 4px;">Where competitors beat us</div>'
              . self::table(['Keyword', 'Leader', 'Their #', 'Our #'], array_map(static fn ($b) => [
                  $b['keyword'], $b['competitor'], '#' . $b['competitor_rank'], $b['our_rank'] === null ? '—' : '#' . $b['our_rank'],
              ], $t['beaten']));
        $share = self::table(['Domain', 'Top-10 keywords', 'Share'], array_map(static fn ($r) => [
            $r['name'], (string) $r['top10'], $r['share'] === null ? '—' : round($r['share'] * 100) . '%',
        ], $t['top10_share'] ?? []));

        return '<p style="margin:0 0 10px;font-size:13px;color:' . self::C_MUTED . ';">SERP check of ' . self::e($t['date']) . ' (DataForSEO, Google India).</p>'
            . $tiles . $beaten . '<div style="font-size:13px;font-weight:600;margin:14px 0 4px;">Share of top 10</div>' . $share;
    }

    /** @param array<string, mixed> $c */
    private static function contentHtml(array $c): string
    {
        if (! isset($c['counts'])) {
            return self::note($c);
        }
        $rows = [];
        foreach (DailyReportBuilder::CONTENT_TYPES as $type => $label) {
            $n = $c['counts'][$type] ?? null;
            $rows[] = [$label, $n === null ? 'Not tracked yet' : (string) $n];
        }

        return self::table(['Type', 'Published'], $rows);
    }

    /** @param array<string, mixed> $v */
    private static function vettingHtml(array $v): string
    {
        if (empty($v['configured']) || ! isset($v['counts'])) {
            return self::note($v);
        }
        $tiles = self::tiles([
            ['Approved', (string) $v['counts']['approve'], null, false],
            ['Revised', (string) $v['counts']['revise'], null, false],
            ['Rejected', (string) $v['counts']['reject'], null, false],
        ]);
        $reasons = $v['top_reject_reasons'] === [] ? '' : '<div style="font-size:13px;font-weight:600;margin:14px 0 4px;">Top reject reasons</div>'
            . self::table(['Reason', 'Count'], array_map(static fn ($r) => [$r['reason'], (string) $r['count']], $v['top_reject_reasons']));

        return $tiles . $reasons;
    }

    /** @param array<string, mixed> $p */
    private static function pipelineHtml(array $p): string
    {
        $email = isset($p['email']) && $p['email'] !== ''
            ? '<div style="font-size:13px;margin:10px 0 0;">Email: ' . self::e((string) $p['email']) . '</div>'
            : '';
        if (! isset($p['failed_jobs_24h'])) {
            return self::note($p) . $email;
        }
        $rows = [
            ['Failed jobs (24h)', (string) $p['failed_jobs_24h']],
            ['Dead-letter jobs (total)', (string) $p['dead_letters']],
            ['Stalled jobs (lease expired)', (string) $p['stalled_jobs']],
            ['Pending > 1 hour', (string) $p['pending_over_1h']],
        ];
        if (isset($p['stalled_content'])) {
            $rows[] = ['Content waiting > 24h (validation/review/ready)', (string) $p['stalled_content']];
        }
        if (isset($p['failed_deployments_24h'])) {
            $rows[] = ['Failed publication deployments (24h)', (string) $p['failed_deployments_24h']];
        }
        $failing = '';
        if (! empty($p['top_failing'])) {
            $failing = '<div style="font-size:13px;font-weight:600;margin:14px 0 4px;">Failing job types</div>'
                . self::table(['Job type', 'Count'], array_map(static fn ($r) => [$r['job_type'], (string) $r['c']], $p['top_failing']));
        }

        return self::table(['Signal', 'Value'], $rows) . $failing . $email;
    }

    // ── primitives ─────────────────────────────────────────────────────────

    private static function section(string $title, string $inner): string
    {
        return '<tr><td style="padding:18px 24px;border-bottom:1px solid ' . self::C_BORDER . ';">'
            . '<div style="font-size:16px;font-weight:700;margin:0 0 10px;">' . self::e($title) . '</div>' . $inner . '</td></tr>';
    }

    /** @param list<array{0:string,1:string,2:?string,3:bool}> $tiles label, value, delta, (unused) */
    private static function tiles(array $tiles): string
    {
        $cells = '';
        foreach ($tiles as [$label, $value, $delta]) {
            $color = $delta === null ? self::C_MUTED : (str_starts_with($delta, '-') ? self::C_DOWN : self::C_UP);
            $cells .= '<td style="padding:8px 10px;border:1px solid ' . self::C_BORDER . ';border-radius:6px;vertical-align:top;">'
                . '<div style="font-size:11px;color:' . self::C_MUTED . ';text-transform:uppercase;letter-spacing:.04em;">' . self::e($label) . '</div>'
                . '<div style="font-size:18px;font-weight:700;margin-top:2px;">' . self::e($value) . '</div>'
                . ($delta === null ? '' : '<div style="font-size:12px;color:' . $color . ';">' . self::e($delta) . ' vs 7d</div>')
                . '</td>';
        }

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="6" style="border-collapse:separate;"><tr>' . $cells . '</tr></table>';
    }

    /**
     * @param list<string>       $head
     * @param list<list<string>> $rows
     */
    private static function table(array $head, array $rows): string
    {
        if ($rows === []) {
            return '<p style="margin:4px 0;font-size:13px;color:' . self::C_MUTED . ';">No rows.</p>';
        }
        $th = '';
        foreach ($head as $i => $h) {
            $th .= '<th style="text-align:' . ($i === 0 ? 'left' : 'right') . ';font-size:11px;color:' . self::C_MUTED . ';font-weight:600;padding:4px 6px;border-bottom:1px solid ' . self::C_BORDER . ';">' . self::e($h) . '</th>';
        }
        $tr = '';
        foreach ($rows as $row) {
            $tr .= '<tr>';
            foreach ($row as $i => $cell) {
                $tr .= '<td style="text-align:' . ($i === 0 ? 'left' : 'right') . ';font-size:13px;padding:4px 6px;border-bottom:1px solid #f3f4f6;' . ($i === 0 ? 'word-break:break-word;' : 'white-space:nowrap;') . '">' . self::e($cell) . '</td>';
            }
            $tr .= '</tr>';
        }

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;"><thead><tr>' . $th . '</tr></thead><tbody>' . $tr . '</tbody></table>';
    }

    /** @param array<string, mixed> $section */
    private static function note(array $section): string
    {
        return '<p style="margin:0;font-size:13px;color:' . self::C_MUTED . ';">' . self::e(self::noteOf($section)) . '</p>';
    }

    /** @param array<string, mixed> $section */
    private static function noteOf(array $section): string
    {
        return (string) ($section['note'] ?? (empty($section['configured']) ? 'Not configured.' : 'No data.'));
    }

    private static function deltaPct(float $current, mixed $baseline): ?string
    {
        if ($baseline === null || (float) $baseline <= 0) {
            return null;
        }

        return sprintf('%+d%%', (int) round(($current - (float) $baseline) / (float) $baseline * 100));
    }

    private static function pct(mixed $v): string
    {
        return $v === null ? '—' : sprintf('%.1f%%', (float) $v * 100);
    }

    private static function pos(mixed $v): string
    {
        return $v === null ? '—' : sprintf('%.1f', (float) $v);
    }

    private static function num(mixed $v): string
    {
        return $v === null ? '—' : (string) $v;
    }

    private static function shortUrl(string $url): string
    {
        $short = preg_replace('#^https?://(www\.)?#', '', $url) ?? $url;

        return mb_strlen($short) > 70 ? mb_substr($short, 0, 67) . '…' : $short;
    }

    private static function longDate(string $date): string
    {
        $ts = strtotime($date);

        return $ts === false ? $date : date('D j M Y', $ts);
    }

    private static function e(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
