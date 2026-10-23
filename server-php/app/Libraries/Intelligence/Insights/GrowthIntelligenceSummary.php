<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence\Insights;

use App\Libraries\Database\SchemaGuard;
use App\Libraries\Intelligence\Visibility\AiVisibilityService;
use CodeIgniter\Database\BaseConnection;

/**
 * Read-only roll-ups of AI visibility + AI Insights for other consumers:
 *
 *  - forDailyReport(): the block the 08:00 IST daily report can include
 *    (`(new GrowthIntelligenceSummary())->forDailyReport()`).
 *  - automationDigest(): what GET v1/automation/insights-digest returns to
 *    the weekly growth-strategy routine.
 *
 * Both return `available: false` with a reason, never an exception, when the
 * stream-H tables are not migrated yet.
 */
final class GrowthIntelligenceSummary
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? \Config\Database::connect();
    }

    private function installed(): bool
    {
        return SchemaGuard::hasTable($this->db, 'reach_ai_insight_reports')
            && SchemaGuard::hasTable($this->db, 'reach_ai_visibility_batches');
    }

    /** @return array<string, mixed> */
    public function forDailyReport(): array
    {
        if (! $this->installed()) {
            return ['available' => false, 'reason' => 'AI visibility / insights tables are not migrated.'];
        }
        try {
            $vis      = new AiVisibilityService($this->db);
            $insights = new InsightsService($this->db);
            $latest   = $insights->latest();
            $open     = $insights->actions(['status' => 'open']);

            return [
                'available'        => true,
                'ai_visibility_24h' => $vis->compactSummary(1),
                'ai_visibility_7d'  => $vis->compactSummary(7),
                'latest_batch'     => $vis->batches(1)[0] ?? null,
                'insights'         => $latest === null ? null : [
                    'report_id'          => $latest['id'],
                    'created_at'         => $latest['created_at'],
                    'summary'            => $latest['report']['summary'] ?? null,
                    'unverified_numbers' => count($latest['unverified_numbers'] ?? []),
                ],
                'open_actions'       => count($open),
                'top_open_actions'   => array_map(static fn (array $a): array => ['title' => $a['title'], 'expected_impact' => $a['expected_impact'], 'effort' => $a['effort'], 'owner_type' => $a['owner_type']], array_slice($open, 0, 5)),
                'latest_memo'        => (new StrategyMemoService($this->db))->list(1)[0] ?? null,
            ];
        } catch (\Throwable $e) {
            log_message('error', '[GrowthIntelligenceSummary] ' . $e->getMessage());
            return ['available' => false, 'reason' => 'Could not read AI visibility / insights.'];
        }
    }

    /** @return array<string, mixed> */
    public function automationDigest(): array
    {
        if (! $this->installed()) {
            return ['available' => false, 'reason' => 'AI visibility / insights tables are not migrated.'];
        }
        $vis      = new AiVisibilityService($this->db);
        $insights = new InsightsService($this->db);
        $latest   = $insights->latest();
        $failed   = $this->db->table('reach_ai_insight_reports')->select('id, error, created_at')->where('status', 'failed')->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();

        return [
            'available'          => true,
            'generated_at'       => gmdate('c'),
            'insights_report'    => $latest === null ? null : [
                'id'                 => $latest['id'],
                'created_at'         => $latest['created_at'],
                'model'              => $latest['model'],
                'period'             => ['start' => $latest['period_start'], 'end' => $latest['period_end']],
                'report'             => $latest['report'],
                'unverified_numbers' => $latest['unverified_numbers'],
                'digest'             => $latest['digest'],
            ],
            'last_failed_insights_run' => $failed ?: null,
            'ai_visibility_28d'  => $vis->compactSummary(28),
            'ai_visibility_7d'   => $vis->compactSummary(7),
            'open_actions'       => $insights->actions(['status' => 'open']),
            'recent_memos'       => (new StrategyMemoService($this->db))->list(5),
            'rules'              => [
                'Use only numbers present in this payload; label anything else as an assumption.',
                'ai_visibility figures are a small sample of API answers, not market share.',
                'Read the product repositories for what Aicountly actually does; never invent features.',
            ],
        ];
    }
}
