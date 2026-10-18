<?php

declare(strict_types=1);

namespace App\Libraries\Bot\Plan;

use App\Libraries\Database\SchemaGuard;
use App\Libraries\Settings\ReachConfig;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Services;

/**
 * The learning loop's hands: adjustments the bot makes on its own after the
 * daily review, inside hard bounds, every one logged (before / after / why)
 * in reach_bot_self_tune_log.
 *
 *   lower_target    a target that cannot be reached: 0 while a required
 *                   integration is missing; after LAG_DAYS straight lagging
 *                   days for another reason, down to what was achieved —
 *                   never below MIN_FRACTION of the base target, at most once
 *                   per COOLDOWN_DAYS per metric
 *   restore_target  the override is removed once its cause is gone (key set)
 *                   or the lowered target was met RESTORE_DAYS days running
 *   requeue_stuck   routine drafts parked on a long vetting / cover back-off
 *                   while the provider is configured again: retried now
 *                   (MAX_REQUEUE a run, each at most once a day)
 *   retry_job       dead-lettered jobs of idempotent ingest/sync types from
 *                   the last 48 h (MAX_RETRY a run, each job once)
 *   topic_mix       the categories / products the routine briefing steers
 *                   towards, when they change
 *
 * bot.self_tune.enabled = false: nothing is applied; each action is logged
 * with applied = false (skipped_reason self_tune_disabled) so the owner sees
 * what the bot would have done, and the planner ignores existing overrides.
 * The bot never changes a setting the owner owns (limits, keys, budgets) —
 * those become recommendations.
 */
class SelfTuner
{
    public const MIN_FRACTION  = 0.5;
    public const LAG_DAYS      = 7;
    public const STRUCTURAL_DAYS = 3;
    public const RESTORE_DAYS  = 3;
    public const COOLDOWN_DAYS = 7;
    public const MAX_ACTIONS   = 25;
    public const MAX_REQUEUE   = 10;
    public const MAX_RETRY     = 5;

    /** Job types safe to re-run: they only re-read a source and upsert. */
    public const RETRYABLE_JOBS = ['reach.search_console_ingest', 'reach.content_base_sync', 'reach.seo_snapshot', 'reach.social_post_poll', 'reach.routine_content_sweep', 'reach.structured_content_sweep', 'reach.video_pipeline_sweep'];

    private BaseConnection $db;
    private int $count = 0;

    /** @var list<array<string, mixed>> */
    private array $log = [];

    public function __construct(?BaseConnection $db = null, private ?\Closure $retryJob = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public static function enabled(): bool
    {
        return (bool) ReachConfig::get('bot.self_tune.enabled', true);
    }

    /**
     * @param array<string, mixed> $week    PerformanceService::compute() for the 7 days ending $runDate
     * @param array<string, mixed> $signals LagSignals::gather() for the same window
     * @param array<string, mixed>|null $topicMix this review's topic mix
     * @param array<string, mixed>|null $previousTopicMix the previous review's
     * @return array{enabled:bool, applied:int, skipped:int, actions:list<array<string, mixed>>}
     */
    public function run(string $runDate, array $week, array $signals, ?array $topicMix = null, ?array $previousTopicMix = null, ?\DateTimeImmutable $now = null): array
    {
        $this->count = 0;
        $this->log   = [];
        $enabled     = self::enabled();
        $nowTs       = ($now ?? new \DateTimeImmutable('now'))->setTimezone(new \DateTimeZone('UTC'));

        $this->targets($runDate, $week, $signals, $enabled);
        $this->requeueStuck($runDate, $signals, $enabled, $nowTs);
        $this->retryJobs($runDate, $enabled, $nowTs);
        if ($topicMix !== null) {
            $this->topicMix($runDate, $topicMix, $previousTopicMix, $enabled);
        }

        $applied = count(array_filter($this->log, static fn ($l) => $l['applied']));

        return ['enabled' => $enabled, 'applied' => $applied, 'skipped' => count($this->log) - $applied, 'actions' => $this->log];
    }

    // ── targets ─────────────────────────────────────────────────────────────

    private function targets(string $runDate, array $week, array $signals, bool $enabled): void
    {
        $overrides = PlanInputs::overrides($this->db);
        $rows      = array_column((array) $week['metrics'], null, 'metric_key');

        // Restore first: an override whose cause is gone.
        foreach ($overrides as $metric => $ov) {
            $row    = $rows[$metric] ?? null;
            $reason = null;
            if ($ov['cause'] === 'integration_missing') {
                $missing = array_filter(RootCauseClassifier::requirements($metric, $signals), static fn ($r) => ($signals['integrations'][$r] ?? null) === false);
                if ($missing === []) {
                    $reason = 'The missing integration is configured now, so the full target applies again.';
                }
            } elseif ($row !== null) {
                $recent = array_slice(array_values(array_filter($row['series'], static fn ($s) => $s['target'] !== null && $s['achieved'] !== null)), -self::RESTORE_DAYS);
                if (count($recent) === self::RESTORE_DAYS && array_filter($recent, static fn ($s) => $s['achieved'] < $s['target']) === []) {
                    $reason = sprintf('The lowered target was met %d days running; back to the full target.', self::RESTORE_DAYS);
                }
            }
            if ($reason !== null) {
                $this->act($runDate, 'restore_target', $metric, 'metric', null,
                    ['target_per_day' => $ov['target_value'], 'cause' => $ov['cause']], ['target_per_day' => $ov['base_value']], $reason, $enabled,
                    function () use ($metric): void {
                        $this->db->table('reach_bot_target_overrides')->where('metric_key', $metric)->update(['active' => false, 'updated_at' => gmdate('Y-m-d H:i:sP')]);
                    });
            }
        }

        foreach ($rows as $metric => $row) {
            if ($row['kind'] !== 'output' || $row['status'] !== 'lagging' || ($row['days_planned'] ?? 0) === 0) {
                continue;
            }
            $planned = array_values(array_filter($row['series'], static fn ($s) => $s['target'] !== null));
            $basePerDay = $row['base_target'] !== null && $row['days_planned'] > 0 ? (float) $row['base_target'] / max(1, $row['days_planned'] - $row['days_without_data']) : 0.0;
            if ($basePerDay <= 0) {
                continue;
            }
            $current = $overrides[$metric] ?? null;
            if ($current !== null && $this->recentlyChanged($metric, $runDate)) {
                continue; // cooldown
            }
            $lagDays = (int) (($row['streak']['type'] ?? '') === 'lag' ? $row['streak']['days'] : 0);
            $causes  = (array) $row['causes'];
            $structural = array_values(array_filter($causes, static fn ($c) => $c['code'] === 'integration_missing'));

            if ($structural !== [] && $lagDays >= self::STRUCTURAL_DAYS) {
                if ($current !== null && (float) $current['target_value'] === 0.0) {
                    continue;
                }
                $reason = sprintf('%s — structurally unreachable until configured (%s). Restored automatically once it is.', $structural[0]['label'], $structural[0]['fix'] ?? 'see recommendations');
                $this->lower($runDate, $metric, $current, $basePerDay, 0.0, 'integration_missing', $reason, $enabled);
                continue;
            }
            if ($lagDays >= self::LAG_DAYS) {
                $recent   = array_slice(array_values(array_filter($planned, static fn ($s) => $s['achieved'] !== null)), -self::LAG_DAYS);
                $avg      = $recent === [] ? 0.0 : array_sum(array_column($recent, 'achieved')) / count($recent);
                $floor    = $basePerDay * self::MIN_FRACTION;
                $newValue = round(max($floor, ceil($avg)), 2);
                $currentValue = $current !== null ? (float) $current['target_value'] : $basePerDay;
                if ($newValue >= $currentValue) {
                    continue;
                }
                $top    = $causes[0]['label'] ?? 'unexplained shortfall';
                $reason = sprintf('Lagged %d days running (main cause: %s); achieved %.1f a day on average. Lowered to %s a day (never below %d%% of the base %s) so the plan stays honest while the cause is fixed.',
                    $lagDays, $top, $avg, self::n($newValue), (int) (self::MIN_FRACTION * 100), self::n($basePerDay));
                $this->lower($runDate, $metric, $current, $basePerDay, $newValue, (string) ($causes[0]['code'] ?? 'chronic_lag'), $reason, $enabled);
            }
        }
    }

    private function lower(string $runDate, string $metric, ?array $current, float $base, float $value, string $cause, string $reason, bool $enabled): void
    {
        $this->act($runDate, 'lower_target', $metric, 'metric', null,
            ['target_per_day' => $current['target_value'] ?? $base, 'base_per_day' => $base],
            ['target_per_day' => $value, 'base_per_day' => $base], $reason, $enabled,
            function () use ($metric, $value, $base, $cause, $reason): void {
                $now = gmdate('Y-m-d H:i:sP');
                $this->db->query(
                    'INSERT INTO reach_bot_target_overrides (metric_key, target_value, base_value, cause, reason, active, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, TRUE, ?, ?)
                     ON CONFLICT (metric_key) DO UPDATE SET target_value = EXCLUDED.target_value, base_value = EXCLUDED.base_value,
                        cause = EXCLUDED.cause, reason = EXCLUDED.reason, active = TRUE, updated_at = EXCLUDED.updated_at',
                    [$metric, $value, $base, mb_substr($cause, 0, 32), $reason, $now, $now]
                );
            });
    }

    private function recentlyChanged(string $metric, string $runDate): bool
    {
        $since = PlanPeriods::addDays($runDate, -(self::COOLDOWN_DAYS - 1));

        return $this->db->table('reach_bot_self_tune_log')
            ->where('metric_key', $metric)->where('action', 'lower_target')->where('applied', true)
            ->where('run_date >=', $since)->countAllResults() > 0;
    }

    // ── stuck items / jobs ──────────────────────────────────────────────────

    private function requeueStuck(string $runDate, array $signals, bool $enabled, \DateTimeImmutable $now): void
    {
        if (! SchemaGuard::hasTable($this->db, 'reach_routine_submissions')) {
            return;
        }
        $integ  = (array) ($signals['integrations'] ?? []);
        $soon   = $now->modify('+30 minutes')->format('Y-m-d H:i:sP');
        $old    = $now->modify('-1 hour')->format('Y-m-d H:i:sP');
        $done   = 0;
        $stages = [];
        if (($integ['gemini'] ?? false) === true) {
            $stages[] = ['pending_vetting', 'next_attempt_at', 'vetting back-off'];
        }
        if (($integ['openai'] ?? false) === true) {
            $stages[] = ['awaiting_cover', 'next_cover_attempt_at', 'cover back-off'];
        }
        foreach ($stages as [$status, $col, $label]) {
            $rows = $this->db->query(
                "SELECT id, content_type, {$col} AS next_at FROM reach_routine_submissions
                 WHERE status = ? AND {$col} > ? AND updated_at < ? AND (locked_until IS NULL OR locked_until < NOW())
                   AND NOT EXISTS (SELECT 1 FROM reach_bot_self_tune_log l WHERE l.subject_type = 'routine_submission' AND l.subject_id = reach_routine_submissions.id AND l.run_date = ?)
                 ORDER BY id LIMIT ?",
                [$status, $soon, $old, $runDate, self::MAX_REQUEUE]
            )->getResultArray();
            foreach ($rows as $r) {
                if ($done >= self::MAX_REQUEUE) {
                    return;
                }
                $id = (int) $r['id'];
                $this->act($runDate, 'requeue_stuck', $r['content_type'] === 'blog' ? 'content.blog.published' : 'content.knowledge_base.published', 'routine_submission', $id,
                    ['status' => $status, $col => $r['next_at']], ['status' => $status, $col => 'now'],
                    "Parked on a long {$label} while the provider is configured — retried now instead of waiting.", $enabled,
                    function () use ($id, $col): void {
                        $this->db->table('reach_routine_submissions')->where('id', $id)->update([$col => gmdate('Y-m-d H:i:sP'), 'updated_at' => gmdate('Y-m-d H:i:sP')]);
                    });
                $done++;
            }
        }
    }

    private function retryJobs(string $runDate, bool $enabled, \DateTimeImmutable $now): void
    {
        if (! SchemaGuard::hasTable($this->db, 'reach_jobs')) {
            return;
        }
        $rows = $this->db->table('reach_jobs')->select('id, job_type, error_message')
            ->where('status', 'dead_letter')->whereIn('job_type', self::RETRYABLE_JOBS)
            ->where('updated_at >=', $now->modify('-48 hours')->format('Y-m-d H:i:sP'))
            ->where("NOT EXISTS (SELECT 1 FROM reach_bot_self_tune_log l WHERE l.subject_type = 'job' AND l.subject_id = reach_jobs.id)", null, false)
            ->orderBy('id', 'DESC')->limit(self::MAX_RETRY)->get()->getResultArray();
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $this->act($runDate, 'retry_job', null, 'job', $id,
                ['status' => 'dead_letter', 'job_type' => $r['job_type'], 'error' => mb_substr((string) $r['error_message'], 0, 200)], ['status' => 'pending'],
                "{$r['job_type']} only re-reads its source and upserts, so a dead-lettered run is safe to retry once.", $enabled,
                function () use ($id): void {
                    $this->retryJob !== null ? ($this->retryJob)($id) : Services::jobService()->retry($id);
                });
        }
    }

    private function topicMix(string $runDate, array $mix, ?array $previous, bool $enabled): void
    {
        $now  = ['blog_prefer' => $mix['blog']['prefer'] ?? [], 'blog_avoid' => $mix['blog']['avoid'] ?? [], 'kb_prefer' => $mix['knowledge_base']['prefer'] ?? []];
        $then = ['blog_prefer' => $previous['blog']['prefer'] ?? [], 'blog_avoid' => $previous['blog']['avoid'] ?? [], 'kb_prefer' => $previous['knowledge_base']['prefer'] ?? []];
        if ($now === $then || ($now['blog_prefer'] === [] && $now['blog_avoid'] === [] && $now['kb_prefer'] === [])) {
            return;
        }
        $this->act($runDate, 'topic_mix', null, 'briefing', null, $then, $now,
            'Routine briefing now steers topics towards the blog categories with the best approval rate and clicks per post, away from the most-rejected, and towards KB products furthest behind their cadence.',
            $enabled, static function (): void {
                // Applied by being in the stored review the briefing reads.
            });
    }

    // ── logging ─────────────────────────────────────────────────────────────

    private function act(string $runDate, string $action, ?string $metric, ?string $subjectType, ?int $subjectId, array $before, array $after, string $reason, bool $enabled, callable $apply): void
    {
        $skipped = null;
        if (! $enabled) {
            $skipped = 'self_tune_disabled';
        } elseif ($this->count >= self::MAX_ACTIONS) {
            $skipped = 'bound:max_actions_per_run';
        }
        if ($skipped === null) {
            try {
                $apply();
                $this->count++;
            } catch (\Throwable $e) {
                $skipped = 'error:' . mb_substr($e->getMessage(), 0, 56);
            }
        }
        $row = [
            'run_date'       => $runDate,
            'action'         => $action,
            'metric_key'     => $metric,
            'subject_type'   => $subjectType,
            'subject_id'     => $subjectId,
            'before_json'    => json_encode((object) $before, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'after_json'     => json_encode((object) $after, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'reason'         => $reason,
            'applied'        => $skipped === null,
            'skipped_reason' => $skipped,
            'created_at'     => gmdate('Y-m-d H:i:sP'),
        ];
        $this->db->table('reach_bot_self_tune_log')->insert($row);
        $this->log[] = ['action' => $action, 'metric_key' => $metric, 'subject_type' => $subjectType, 'subject_id' => $subjectId,
            'before' => $before, 'after' => $after, 'reason' => $reason, 'applied' => $skipped === null, 'skipped_reason' => $skipped];
    }

    /** @return list<array<string, mixed>> newest first */
    public static function recentLog(?BaseConnection $db = null, int $limit = 50, ?string $since = null): array
    {
        $db ??= Database::connect();
        if (! SchemaGuard::hasTable($db, 'reach_bot_self_tune_log')) {
            return [];
        }
        $b = $db->table('reach_bot_self_tune_log')->orderBy('id', 'DESC')->limit(max(1, min(200, $limit)));
        if ($since !== null) {
            $b->where('run_date >=', $since);
        }

        return array_map(static function (array $r): array {
            $r['before']  = json_decode((string) $r['before_json'], true) ?: [];
            $r['after']   = json_decode((string) $r['after_json'], true) ?: [];
            $r['applied'] = in_array($r['applied'], [true, 't', 'true', 1, '1'], true);
            $r['label']   = $r['metric_key'] ? PlanMetrics::label((string) $r['metric_key']) : null;
            unset($r['before_json'], $r['after_json']);

            return $r;
        }, $b->get()->getResultArray());
    }

    private static function n(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }
}
