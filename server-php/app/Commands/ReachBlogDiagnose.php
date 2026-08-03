<?php

namespace App\Commands;

use App\Libraries\Blog\BlogFeatureFlags;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * `php spark reach:blog-diagnose` — one-shot health check for blog cron / automation.
 *
 * Prints JSON to stdout so cPanel Terminal / cron redirects can capture it.
 * Does not mutate data.
 */
class ReachBlogDiagnose extends BaseCommand
{
    protected $group       = 'Reach';
    protected $name        = 'reach:blog-diagnose';
    protected $description = 'Diagnose blog cron, flags, candidates, work blocks, and jobs.';
    protected $usage       = 'reach:blog-diagnose';

    public function run(array $params): int
    {
        $flags = new BlogFeatureFlags();
        $nowIst = new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata'));

        $report = [
            'event'            => 'blog_diagnose',
            'ts'               => gmdate('c'),
            'now_ist'          => $nowIst->format('Y-m-d H:i:s T'),
            'php_binary'       => PHP_BINARY,
            'php_version'      => PHP_VERSION,
            'cwd'              => getcwd(),
            'writepath'        => WRITEPATH,
            'writepath_ok'     => is_dir(WRITEPATH) && is_writable(WRITEPATH),
            'logs_dir'         => WRITEPATH . 'logs' . DIRECTORY_SEPARATOR,
            'logs_dir_ok'      => is_dir(WRITEPATH . 'logs') && is_writable(WRITEPATH . 'logs'),
            'cron_log_files'   => $this->cronLogFiles(),
            'stop_worker_file' => is_file(WRITEPATH . 'stop-reach-worker'),
            'lock_files'       => [
                'dispatch'  => is_file(WRITEPATH . 'reach-blog-dispatch.lock'),
                'optimizer' => is_file(WRITEPATH . 'reach-blog-optimize-roadmap.lock'),
            ],
            'flags'            => [
                'automation'        => $flags->isEnabled('automation'),
                'roadmap_optimizer' => $flags->isEnabled('roadmap_optimizer'),
                'ai_generation'     => $flags->isEnabled('ai_generation'),
                'fact_verification' => $flags->isEnabled('fact_verification'),
                'auto_publish'      => $flags->isEnabled('auto_publish'),
                'public_publisher'  => $flags->isEnabled('public_publisher'),
                'search_console'    => $flags->isEnabled('search_console'),
            ],
            'windows'          => [
                'optimizer_preferred_hour_ist' => ((int) $nowIst->format('H') === 0),
                'dispatch_window_open_ist'     => $this->isWithinDispatchWindow($nowIst),
            ],
            'database'         => $this->databaseSnapshot(),
            'verdict'          => [],
            'next_actions'     => [],
        ];

        $report['verdict']      = $this->buildVerdict($report);
        $report['next_actions'] = $this->buildNextActions($report);

        CLI::write(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $blocking = array_filter($report['verdict'], static fn ($v) => ($v['severity'] ?? '') === 'blocking');
        return $blocking === [] ? 0 : 2;
    }

    /**
     * @return array<string, array{exists:bool,bytes:int|null,mtime:string|null}>
     */
    private function cronLogFiles(): array
    {
        $names = [
            'worker.log',
            'schedule.log',
            'blog-dispatch.log',
            'blog-optimizer.log',
        ];
        $out = [];
        foreach ($names as $name) {
            $path = WRITEPATH . 'logs' . DIRECTORY_SEPARATOR . $name;
            if (! is_file($path)) {
                $out[$name] = ['exists' => false, 'bytes' => null, 'mtime' => null];
                continue;
            }
            $mtime = @filemtime($path);
            $out[$name] = [
                'exists' => true,
                'bytes'  => (int) (@filesize($path) ?: 0),
                'mtime'  => $mtime ? gmdate('c', $mtime) : null,
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function databaseSnapshot(): array
    {
        try {
            $db = Database::connect();
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $snap = ['ok' => true];

        try {
            if ($db->tableExists('reach_optimizer_runs')) {
                $row = $db->table('reach_optimizer_runs')->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();
                $snap['last_optimizer_run'] = $row ? [
                    'id'                  => (int) $row['id'],
                    'run_for_date'        => $row['run_for_date'] ?? null,
                    'status'              => $row['status'] ?? null,
                    'skipped_reason'      => $row['skipped_reason'] ?? null,
                    'candidates_scored'   => (int) ($row['candidates_scored'] ?? 0),
                    'decisions_created'   => (int) ($row['decisions_created'] ?? 0),
                    'work_blocks_created' => (int) ($row['work_blocks_created'] ?? 0),
                    'completed_at'        => $row['completed_at'] ?? null,
                ] : null;
            } else {
                $snap['last_optimizer_run'] = null;
                $snap['missing_table'][] = 'reach_optimizer_runs';
            }

            if ($db->tableExists('reach_topic_candidates')) {
                $rows = $db->query(
                    'SELECT status, COUNT(*) AS cnt FROM reach_topic_candidates GROUP BY status ORDER BY status'
                )->getResultArray();
                $byStatus = [];
                foreach ($rows as $r) {
                    $byStatus[(string) $r['status']] = (int) $r['cnt'];
                }
                $eligibleRow = $db->query(
                    "SELECT COUNT(*) AS cnt
                     FROM reach_topic_candidates
                     WHERE status IN ('candidate', 'scored')
                       AND is_locked = FALSE
                       AND (
                         is_human_pinned = TRUE
                         OR cooldown_until IS NULL
                         OR cooldown_until <= ?
                       )",
                    [date('Y-m-d H:i:s')]
                )->getRowArray();
                $snap['topic_candidates'] = [
                    'by_status'        => $byStatus,
                    'eligible_for_run' => (int) ($eligibleRow['cnt'] ?? 0),
                ];
            } else {
                $snap['missing_table'][] = 'reach_topic_candidates';
            }

            if ($db->tableExists('reach_work_blocks')) {
                $rows = $db->query(
                    'SELECT eligibility_status, block_type, COUNT(*) AS cnt
                     FROM reach_work_blocks
                     GROUP BY eligibility_status, block_type
                     ORDER BY eligibility_status, block_type'
                )->getResultArray();
                $snap['work_blocks'] = array_map(static fn ($r) => [
                    'eligibility_status' => $r['eligibility_status'],
                    'block_type'         => $r['block_type'],
                    'count'              => (int) $r['cnt'],
                ], $rows);
            } else {
                $snap['missing_table'][] = 'reach_work_blocks';
            }

            if ($db->tableExists('reach_jobs')) {
                $rows = $db->query(
                    'SELECT queue, status, COUNT(*) AS cnt
                     FROM reach_jobs
                     GROUP BY queue, status
                     ORDER BY queue, status'
                )->getResultArray();
                $snap['jobs'] = array_map(static fn ($r) => [
                    'queue'  => $r['queue'],
                    'status' => $r['status'],
                    'count'  => (int) $r['cnt'],
                ], $rows);
            } else {
                $snap['missing_table'][] = 'reach_jobs';
            }

            if ($db->tableExists('reach_content_items')) {
                $rows = $db->query(
                    "SELECT workflow_status, COUNT(*) AS cnt
                     FROM reach_content_items
                     WHERE content_type = 'blog' AND deleted_at IS NULL
                     GROUP BY workflow_status
                     ORDER BY workflow_status"
                )->getResultArray();
                $byStatus = [];
                foreach ($rows as $r) {
                    $byStatus[(string) $r['workflow_status']] = (int) $r['cnt'];
                }
                $snap['blog_content_items'] = $byStatus;
            } else {
                $snap['missing_table'][] = 'reach_content_items';
            }
        } catch (Throwable $e) {
            $snap['ok'] = false;
            $snap['error'] = $e->getMessage();
        }

        return $snap;
    }

    private function isWithinDispatchWindow(DateTimeImmutable $nowIst): bool
    {
        $mins = ((int) $nowIst->format('H')) * 60 + (int) $nowIst->format('i');
        return ($mins >= 0 && $mins <= 8 * 60 + 59)
            || ($mins >= 19 * 60 && $mins <= 23 * 60 + 59);
    }

    /**
     * @param array<string, mixed> $report
     * @return list<array{severity:string,code:string,message:string}>
     */
    private function buildVerdict(array $report): array
    {
        $issues = [];

        if (empty($report['writepath_ok'])) {
            $issues[] = [
                'severity' => 'blocking',
                'code'     => 'writable_not_writable',
                'message'  => 'WRITEPATH is missing or not writable by PHP CLI.',
            ];
        }

        if (empty($report['logs_dir_ok'])) {
            $issues[] = [
                'severity' => 'blocking',
                'code'     => 'logs_dir_not_writable',
                'message'  => 'writable/logs is missing or not writable — cron redirects cannot create log files.',
            ];
        }

        $cronLogs = $report['cron_log_files'] ?? [];
        $anyCronLog = false;
        foreach ($cronLogs as $meta) {
            if (! empty($meta['exists']) && (int) ($meta['bytes'] ?? 0) > 0) {
                $anyCronLog = true;
                break;
            }
        }
        if (! $anyCronLog) {
            $issues[] = [
                'severity' => 'blocking',
                'code'     => 'no_cron_log_files',
                'message'  => 'No worker/schedule/dispatch/optimizer cron log files found under writable/logs. Crontab is almost certainly missing, using the wrong cd path, or not redirecting stdout.',
            ];
        }

        if (! empty($report['stop_worker_file'])) {
            $issues[] = [
                'severity' => 'blocking',
                'code'     => 'stop_reach_worker_present',
                'message'  => 'writable/stop-reach-worker exists — reach:work exits immediately until this file is removed.',
            ];
        }

        $flags = $report['flags'] ?? [];
        if (empty($flags['roadmap_optimizer'])) {
            $issues[] = [
                'severity' => 'blocking',
                'code'     => 'optimizer_flag_off',
                'message'  => 'BLOG_ROADMAP_OPTIMIZER_ENABLED is false — optimiser runs will be skipped.',
            ];
        }
        if (empty($flags['automation'])) {
            $issues[] = [
                'severity' => 'blocking',
                'code'     => 'automation_flag_off',
                'message'  => 'BLOG_AUTOMATION_ENABLED is false — work blocks execute as blocked and blogs will not be saved.',
            ];
        }
        if (empty($flags['ai_generation'])) {
            $issues[] = [
                'severity' => 'warning',
                'code'     => 'ai_generation_flag_off',
                'message'  => 'BLOG_AI_GENERATION_ENABLED is false — briefs may be created but drafts will not generate.',
            ];
        }

        $db = $report['database'] ?? [];
        if (empty($db['ok'])) {
            $issues[] = [
                'severity' => 'blocking',
                'code'     => 'database_error',
                'message'  => 'Database check failed: ' . (string) ($db['error'] ?? 'unknown'),
            ];
            return $issues;
        }

        $eligible = (int) ($db['topic_candidates']['eligible_for_run'] ?? 0);
        if ($eligible === 0) {
            $issues[] = [
                'severity' => 'blocking',
                'code'     => 'no_eligible_candidates',
                'message'  => 'No eligible topic candidates (status candidate/scored, unlocked, not in cooldown). Optimiser will score 0 and create 0 blogs.',
            ];
        }

        $last = $db['last_optimizer_run'] ?? null;
        if (is_array($last) && ! empty($last['run_for_date'])) {
            try {
                $runDate = new DateTimeImmutable((string) $last['run_for_date'], new DateTimeZone('Asia/Kolkata'));
                $today   = new DateTimeImmutable('today', new DateTimeZone('Asia/Kolkata'));
                $ageDays = (int) $runDate->diff($today)->format('%a');
                if ($ageDays >= 2) {
                    $issues[] = [
                        'severity' => 'blocking',
                        'code'     => 'optimizer_stale',
                        'message'  => "Last optimiser run_for_date is {$last['run_for_date']} ({$ageDays} days ago). Daily optimiser cron is not hitting.",
                    ];
                }
            } catch (Throwable) {
                // ignore parse errors
            }
            if (($last['status'] ?? '') === 'skipped') {
                $issues[] = [
                    'severity' => 'warning',
                    'code'     => 'optimizer_last_skipped',
                    'message'  => 'Last optimiser run was skipped: ' . (string) ($last['skipped_reason'] ?? 'unknown'),
                ];
            }
        } else {
            $issues[] = [
                'severity' => 'warning',
                'code'     => 'optimizer_never_ran',
                'message'  => 'No rows in reach_optimizer_runs yet.',
            ];
        }

        $jobs = $db['jobs'] ?? [];
        $blogPending = 0;
        foreach ($jobs as $j) {
            if (($j['queue'] ?? '') === 'blog' && in_array($j['status'] ?? '', ['pending', 'reserved'], true)) {
                $blogPending += (int) ($j['count'] ?? 0);
            }
        }
        if ($blogPending > 0 && empty($cronLogs['worker.log']['exists'])) {
            $issues[] = [
                'severity' => 'blocking',
                'code'     => 'blog_jobs_not_drained',
                'message'  => "{$blogPending} blog queue job(s) are pending/reserved but worker.log is missing — worker cron is not draining blog/publishing queues.",
            ];
        }

        return $issues;
    }

    /**
     * @param array<string, mixed> $report
     * @return list<string>
     */
    private function buildNextActions(array $report): array
    {
        $actions = [];
        $codes = array_column($report['verdict'] ?? [], 'code');

        if (in_array('no_cron_log_files', $codes, true) || in_array('optimizer_stale', $codes, true)) {
            $actions[] = 'Install/fix cPanel cron lines from docs/blog-automation/WHM_CPANEL_DEPLOYMENT_RUNBOOK.md (must include reach:blog-dispatch + reach:work --queue=default,blog,publishing + reach:blog-optimize-roadmap + reach:schedule).';
            $actions[] = 'Confirm cron cd path is the real server-php directory that contains this WRITEPATH.';
            $actions[] = 'Confirm PHP binary with: which php; ls -la $(which php)';
        }

        if (in_array('stop_reach_worker_present', $codes, true)) {
            $actions[] = 'rm -f writable/stop-reach-worker';
        }

        if (in_array('optimizer_flag_off', $codes, true) || in_array('automation_flag_off', $codes, true) || in_array('ai_generation_flag_off', $codes, true)) {
            $actions[] = 'In server-php/.env set BLOG_ROADMAP_OPTIMIZER_ENABLED=true, BLOG_AUTOMATION_ENABLED=true, BLOG_AI_GENERATION_ENABLED=true (keep BLOG_AUTO_PUBLISH_ENABLED=false until reviewed).';
        }

        if (in_array('no_eligible_candidates', $codes, true)) {
            $actions[] = 'Create/unlock topic candidates in Blog Command Centre → Roadmap (status candidate/scored).';
        }

        $actions[] = 'Manual smoke: php spark reach:blog-optimize-roadmap --force';
        $actions[] = 'Manual smoke: php spark reach:blog-dispatch --force';
        $actions[] = 'Manual smoke: php spark reach:work --queue=default,blog,publishing --once --limit=10';

        return $actions;
    }
}
