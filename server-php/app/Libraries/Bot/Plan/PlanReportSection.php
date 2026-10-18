<?php

declare(strict_types=1);

namespace App\Libraries\Bot\Plan;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * "Plan vs achieved (yesterday)" for the daily report email. Data comes from
 * the stored daily review the planner wrote at 01:00 IST (no provider calls
 * on the report's path); without one it is computed from the stored plans
 * and actuals, without root causes. Rendering is pure and inline-styled.
 */
class PlanReportSection
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /** @return array<string, mixed> */
    public function build(string $reportDate): array
    {
        $date = PlanPeriods::addDays($reportDate, -1);
        $row  = $this->db->query('SELECT summary_json::text AS s FROM reach_bot_performance_reviews WHERE review_date = ?', [$date])->getRowArray();
        $review = $row ? (json_decode((string) $row['s'], true) ?: null) : null;

        if ($review !== null) {
            $metrics  = (array) $review['day'];
            $topLags  = array_map(static fn ($l) => ['label' => $l['label'], 'achievement_pct' => $l['achievement_pct'], 'cause' => $l['cause']['label'] ?? null], (array) $review['top_lags']);
            $selfTune = $review['self_tune'] ?? null;
        } else {
            $perf     = (new PerformanceService($this->db))->compute($date, $date, false);
            $metrics  = $perf['metrics'];
            $topLags  = [];
            $selfTune = null;
        }
        $metrics = array_values(array_filter($metrics, static fn ($m) => ($m['target'] ?? 0) > 0 || ($m['achieved'] ?? 0) > 0));
        if ($metrics === []) {
            return ['configured' => false, 'date' => $date, 'note' => 'No plan was recorded for ' . $date . ' yet — the bot planner (job reach.bot_plan, 01:00 IST) stores the first plan the day it first runs.'];
        }

        return [
            'configured' => true,
            'date'       => $date,
            'metrics'    => array_map(static fn ($m) => array_intersect_key($m, array_flip(['metric_key', 'label', 'kind', 'target', 'achieved', 'achievement_pct', 'status'])), $metrics),
            'top_lags'   => $topLags,
            'self_tune'  => $selfTune === null ? null : ['enabled' => (bool) $selfTune['enabled'], 'applied' => (int) $selfTune['applied']],
        ];
    }

    /** @param array<string, mixed> $s */
    public static function html(array $s): string
    {
        $muted = '#6b7280';
        if (empty($s['configured']) || empty($s['metrics'])) {
            return '<p style="margin:0;font-size:13px;color:' . $muted . ';">' . self::e($s['note'] ?? 'Not available.') . '</p>';
        }
        $rows = '';
        foreach ($s['metrics'] as $m) {
            $color = match ($m['status']) { 'met' => '#047857', 'lagging' => '#b91c1c', 'behind' => '#b45309', default => '#1f2937' };
            $rows .= '<tr>'
                . '<td style="padding:4px 8px 4px 0;font-size:13px;border-bottom:1px solid #e5e7eb;">' . self::e($m['label']) . '</td>'
                . '<td style="padding:4px 8px;font-size:13px;text-align:right;border-bottom:1px solid #e5e7eb;">' . self::num($m['target']) . '</td>'
                . '<td style="padding:4px 8px;font-size:13px;text-align:right;border-bottom:1px solid #e5e7eb;">' . self::num($m['achieved']) . '</td>'
                . '<td style="padding:4px 0 4px 8px;font-size:13px;text-align:right;font-weight:600;color:' . $color . ';border-bottom:1px solid #e5e7eb;">'
                . ($m['achievement_pct'] === null ? '—' : (int) $m['achievement_pct'] . '%') . '</td></tr>';
        }
        $head = '<tr>' . implode('', array_map(static fn ($h, $i) => '<th style="padding:4px ' . ($i === 3 ? '0' : '8px') . ' 4px ' . ($i === 0 ? '0' : '8px') . ';font-size:12px;color:' . $muted . ';text-align:' . ($i === 0 ? 'left' : 'right') . ';border-bottom:1px solid #e5e7eb;">' . $h . '</th>',
            ['Metric', 'Planned', 'Achieved', '%'], [0, 1, 2, 3])) . '</tr>';
        $html = '<p style="margin:0 0 8px;font-size:13px;color:' . $muted . ';">' . self::e($s['date']) . ' (IST) against the bot\'s plan for that day.</p>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">' . $head . $rows . '</table>';
        if (! empty($s['top_lags'])) {
            $li = '';
            foreach ($s['top_lags'] as $l) {
                $li .= '<li style="margin:0 0 4px;">' . self::e($l['label']) . ' — ' . ($l['achievement_pct'] === null ? '—' : (int) $l['achievement_pct'] . '%')
                    . ($l['cause'] ? ': ' . self::e($l['cause']) : '') . '</li>';
            }
            $html .= '<div style="font-size:13px;font-weight:600;margin:12px 0 4px;">Top lags (last 7 days)</div><ol style="margin:0;padding-left:20px;font-size:13px;">' . $li . '</ol>';
        }
        if (! empty($s['self_tune'])) {
            $html .= '<p style="margin:10px 0 0;font-size:12px;color:' . $muted . ';">' . ($s['self_tune']['enabled']
                ? 'Self-tuning applied ' . (int) $s['self_tune']['applied'] . ' adjustment(s) overnight — see Reach → Bot → Plan & Performance.'
                : 'Self-tuning is switched off (bot.self_tune.enabled); suggested adjustments are logged, not applied.') . '</p>';
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $s
     * @return list<string>
     */
    public static function text(array $s): array
    {
        $out = ['== Plan vs achieved (yesterday) =='];
        if (empty($s['configured']) || empty($s['metrics'])) {
            $out[] = (string) ($s['note'] ?? 'Not available.');

            return $out;
        }
        foreach ($s['metrics'] as $m) {
            $out[] = sprintf('  %s: %s of %s planned (%s)', $m['label'], self::num($m['achieved']), self::num($m['target']), $m['achievement_pct'] === null ? '—' : (int) $m['achievement_pct'] . '%');
        }
        foreach ($s['top_lags'] ?? [] as $i => $l) {
            $out[] = sprintf('  Lag %d: %s%s', $i + 1, $l['label'], $l['cause'] ? ' — ' . $l['cause'] : '');
        }

        return $out;
    }

    private static function num(mixed $v): string
    {
        if ($v === null) {
            return '—';
        }
        $f = (float) $v;

        return $f == floor($f) ? number_format($f) : number_format($f, 1);
    }

    private static function e(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
