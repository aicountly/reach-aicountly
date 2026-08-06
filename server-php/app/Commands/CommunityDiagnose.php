<?php

namespace App\Commands;

use App\Libraries\Community\CommunityAutomationWindow;
use App\Libraries\Community\CommunityAutoPublishService;
use App\Libraries\Community\CommunityPublisherFactory;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * `php spark community:diagnose` — one-shot answer to "why is Community Q&A
 * empty on aicountly.com?", runnable straight from the cPanel terminal.
 *
 * Reports the whole chain in one JSON document: automation window, feature
 * flags, receiver credentials + live health, official identities, question and
 * answer status histograms, generation-request outcomes, deployment states, and
 * a verdict with the exact next command to run. Exit code 2 means something
 * blocking was found.
 */
class CommunityDiagnose extends BaseCommand
{
    protected $group       = 'Reach';
    protected $name        = 'community:diagnose';
    protected $description = 'Diagnose the Community Q&A pipeline end to end (window, flags, AI routes, publisher, deployments).';
    protected $usage       = 'community:diagnose';

    public function run(array $params): int
    {
        $nowIst = new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata'));

        $report = [
            'event'   => 'community_diagnose',
            'ts'      => gmdate('c'),
            'now_ist' => $nowIst->format('Y-m-d H:i:s T'),
            'automation' => [
                'window_open_now'  => $this->windowOpen(),
                'auto_publish_flag' => CommunityAutoPublishService::isEnabled(),
            ],
            'publisher' => $this->publisherSnapshot(),
            'ai_routes' => $this->aiRouteSnapshot(),
            'database'  => $this->databaseSnapshot(),
            'lock_files' => [
                'agents_run' => $this->lockFileStatus(WRITEPATH . 'community-agents-run.lock'),
                'advance'    => $this->lockFileStatus(WRITEPATH . 'community-advance.lock'),
            ],
            'verdict'      => [],
            'next_actions' => [],
        ];

        $report['verdict']      = $this->buildVerdict($report);
        $report['next_actions'] = $this->buildNextActions($report);

        CLI::write(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $blocking = array_filter($report['verdict'], static fn ($v) => ($v['severity'] ?? '') === 'blocking');

        return $blocking === [] ? 0 : 2;
    }

    private function windowOpen(): bool
    {
        try {
            return CommunityAutomationWindow::isOpenNow();
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string,mixed> */
    private function publisherSnapshot(): array
    {
        $base  = trim((string) ($_ENV['AICOUNTLY_PUBLIC_SITE_BASE_URL'] ?? ''));
        $token = trim((string) ($_ENV['AICOUNTLY_PUBLIC_SITE_SERVICE_TOKEN'] ?? ''));
        $key   = trim((string) ($_ENV['AICOUNTLY_PUBLIC_SITE_SIGNING_KEY'] ?? ''));

        $snapshot = [
            'base_url_set'     => $base !== '',
            'base_url'         => $base,
            'service_token_set' => $token !== '',
            'signing_key_set'  => $key !== '',
            'mock_publisher'   => ! empty($_ENV['REACH_PUB_COMMUNITY_MOCK']),
            'health'           => null,
        ];

        try {
            $snapshot['health'] = CommunityPublisherFactory::create()->healthCheck();
        } catch (Throwable $e) {
            $snapshot['health'] = false;
            $snapshot['health_error'] = mb_substr($e->getMessage(), 0, 160);
        }

        return $snapshot;
    }

    /**
     * The community answer generator routes on task_type `community_answer`.
     * Without an enabled route the orchestrator fails at `routing_failed` and
     * every draft lands in validation_failed.
     *
     * @return array<string,mixed>
     */
    private function aiRouteSnapshot(): array
    {
        try {
            $db = Database::connect();
            if (! $db->tableExists('reach_ai_model_routes')) {
                return ['table_missing' => true];
            }

            $routes = $db->query(
                "SELECT r.id, r.content_type, r.priority, r.enabled, m.model_key, p.provider_key, p.status AS provider_status
                   FROM reach_ai_model_routes r
                   LEFT JOIN reach_ai_models m ON m.id = r.primary_model_id
                   LEFT JOIN reach_ai_providers p ON p.id = m.provider_id
                  WHERE r.task_type = 'community_answer'
                    AND r.deleted_at IS NULL
                  ORDER BY r.priority DESC"
            )->getResultArray();

            return [
                'community_answer_routes' => $routes,
                'usable_routes'           => count(array_filter(
                    $routes,
                    static fn (array $r): bool => ! empty($r['enabled'])
                        && ($r['provider_status'] ?? '') === 'enabled'
                        && ! empty($r['model_key'])
                )),
                'openai_key_set' => trim((string) ($_ENV['AI_OPENAI_API_KEY'] ?? '')) !== '',
                'gemini_key_set' => trim((string) ($_ENV['AI_GEMINI_API_KEY'] ?? '')) !== '',
            ];
        } catch (Throwable $e) {
            return ['error' => mb_substr($e->getMessage(), 0, 200)];
        }
    }

    /** @return array<string,mixed> */
    private function databaseSnapshot(): array
    {
        $snap = [];

        try {
            $db = Database::connect();

            $snap['identities'] = $this->rows($db, 'reach_community_official_identities',
                'SELECT operational_role, COUNT(*) AS count, SUM(CASE WHEN is_active THEN 1 ELSE 0 END) AS active
                   FROM reach_community_official_identities GROUP BY operational_role ORDER BY operational_role');

            $snap['questions_by_status'] = $this->rows($db, 'reach_community_questions',
                'SELECT status, COUNT(*) AS count, ROUND(AVG(triage_score), 3) AS avg_triage
                   FROM reach_community_questions GROUP BY status ORDER BY count DESC');

            $snap['answers_by_status'] = $this->rows($db, 'reach_community_official_answers',
                'SELECT status, COUNT(*) AS count,
                        SUM(CASE WHEN ai_assisted THEN 1 ELSE 0 END) AS ai_assisted,
                        SUM(CASE WHEN human_reviewed THEN 1 ELSE 0 END) AS human_reviewed
                   FROM reach_community_official_answers GROUP BY status ORDER BY count DESC');

            $snap['answers_without_version'] = $this->scalar($db, 'reach_community_official_answers',
                'SELECT COUNT(*) AS c FROM reach_community_official_answers a
                  WHERE NOT EXISTS (SELECT 1 FROM reach_community_answer_versions v WHERE v.answer_id = a.id)');

            $snap['generation_requests'] = $this->rows($db, 'reach_ai_generation_requests',
                "SELECT status, COUNT(*) AS count FROM reach_ai_generation_requests
                  WHERE task_type = 'community_answer' GROUP BY status ORDER BY count DESC");

            $snap['deployments_by_status'] = $this->rows($db, 'reach_community_deployments',
                'SELECT status, operation, COUNT(*) AS count, MAX(last_error) AS last_error
                   FROM reach_community_deployments GROUP BY status, operation ORDER BY count DESC');

            $snap['recent_agent_runs'] = $this->rows($db, 'reach_community_agent_runs',
                'SELECT action, outcome, COUNT(*) AS count, MAX(block_reason) AS last_block_reason
                   FROM reach_community_agent_runs
                  WHERE created_at > NOW() - INTERVAL \'2 days\'
                  GROUP BY action, outcome ORDER BY count DESC');
        } catch (Throwable $e) {
            $snap['error'] = mb_substr($e->getMessage(), 0, 200);
        }

        return $snap;
    }

    /**
     * @return list<array<string,mixed>>|array{table_missing:true}
     */
    private function rows(\CodeIgniter\Database\BaseConnection $db, string $table, string $sql): array
    {
        if (! $db->tableExists($table)) {
            return ['table_missing' => true];
        }

        try {
            return $db->query($sql)->getResultArray();
        } catch (Throwable $e) {
            return [['error' => mb_substr($e->getMessage(), 0, 160)]];
        }
    }

    private function scalar(\CodeIgniter\Database\BaseConnection $db, string $table, string $sql): ?int
    {
        if (! $db->tableExists($table)) {
            return null;
        }

        try {
            return (int) ($db->query($sql)->getRowArray()['c'] ?? 0);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{exists:bool,held:bool} */
    private function lockFileStatus(string $path): array
    {
        if (! is_file($path)) {
            return ['exists' => false, 'held' => false];
        }

        $fp = @fopen($path, 'c+');
        if ($fp === false) {
            return ['exists' => true, 'held' => true];
        }

        $held = ! flock($fp, LOCK_EX | LOCK_NB);
        if (! $held) {
            flock($fp, LOCK_UN);
        }
        fclose($fp);

        return ['exists' => true, 'held' => $held];
    }

    /**
     * @param array<string,mixed> $report
     * @return list<array{severity:string,check:string,detail:string}>
     */
    private function buildVerdict(array $report): array
    {
        $v = [];
        $pub = $report['publisher'];
        $ai  = $report['ai_routes'];
        $db  = $report['database'];

        if (! $pub['base_url_set'] || ! $pub['service_token_set'] || ! $pub['signing_key_set']) {
            $v[] = [
                'severity' => 'blocking',
                'check'    => 'publisher_credentials',
                'detail'   => 'AICOUNTLY_PUBLIC_SITE_BASE_URL / _SERVICE_TOKEN / _SIGNING_KEY must all be set, or nothing can be published to aicountly.com.',
            ];
        } elseif ($pub['health'] !== true) {
            $v[] = [
                'severity' => 'blocking',
                'check'    => 'publisher_health',
                'detail'   => 'GET {base}/api/reach/v1/health did not return ok. The public receiver is unreachable or misconfigured.',
            ];
        }

        if (($ai['usable_routes'] ?? 0) < 1) {
            $v[] = [
                'severity' => 'blocking',
                'check'    => 'community_answer_ai_route',
                'detail'   => "No enabled reach_ai_model_routes row for task_type='community_answer'. Every draft will fail generation and park in validation_failed.",
            ];
        }

        if (empty($ai['openai_key_set']) && empty($ai['gemini_key_set'])) {
            $v[] = [
                'severity' => 'blocking',
                'check'    => 'ai_provider_keys',
                'detail'   => 'Neither AI_OPENAI_API_KEY nor AI_GEMINI_API_KEY is set.',
            ];
        }

        $withoutVersion = $db['answers_without_version'] ?? null;
        if (is_int($withoutVersion) && $withoutVersion > 0) {
            $v[] = [
                'severity' => 'warning',
                'check'    => 'answers_without_version',
                'detail'   => "{$withoutVersion} official answer(s) have no content version — generation never produced a body. `php spark community:advance` retries them.",
            ];
        }

        foreach (($db['answers_by_status'] ?? []) as $row) {
            if (($row['status'] ?? '') === 'draft_generated' && (int) ($row['count'] ?? 0) > 0) {
                $v[] = [
                    'severity' => 'warning',
                    'check'    => 'drafts_awaiting_advance',
                    'detail'   => "{$row['count']} generated draft(s) are waiting for validation/review routing. Install the `community:advance` cron.",
                ];
            }
            if (($row['status'] ?? '') === 'validation_failed' && (int) ($row['count'] ?? 0) > 0) {
                $v[] = [
                    'severity' => 'warning',
                    'check'    => 'validation_failed_backlog',
                    'detail'   => "{$row['count']} answer(s) sit in validation_failed. With no versions this means AI generation failed, not that the content was rejected.",
                ];
            }
        }

        foreach (($db['deployments_by_status'] ?? []) as $row) {
            if (in_array($row['status'] ?? '', ['dead_letter', 'retrying'], true)) {
                $v[] = [
                    'severity' => 'warning',
                    'check'    => 'deployment_' . $row['status'],
                    'detail'   => "{$row['count']} {$row['operation']} deployment(s) in {$row['status']}: " . mb_substr((string) ($row['last_error'] ?? ''), 0, 160),
                ];
            }
        }

        if (! $report['automation']['window_open_now']) {
            $v[] = [
                'severity' => 'info',
                'check'    => 'automation_window',
                'detail'   => 'The content-creating window (09:00–19:00 IST) is closed right now, so community:agents-run will only report blocked dispatches.',
            ];
        }

        if ($v === []) {
            $v[] = ['severity' => 'info', 'check' => 'all_clear', 'detail' => 'No blocking issues detected.'];
        }

        return $v;
    }

    /**
     * @param array<string,mixed> $report
     * @return list<string>
     */
    private function buildNextActions(array $report): array
    {
        $actions = [];

        foreach ($report['verdict'] as $item) {
            $actions[] = match ($item['check']) {
                'publisher_credentials'      => 'Set AICOUNTLY_PUBLIC_SITE_BASE_URL, _SERVICE_TOKEN, _SIGNING_KEY and _KEY_ID in server-php/.env (they must match the site side).',
                'publisher_health'           => 'curl -s "$AICOUNTLY_PUBLIC_SITE_BASE_URL/api/reach/v1/health" — expect {"ok":true}.',
                'community_answer_ai_route'  => 'php spark reach:ai-seed-catalog   # seeds the community_answer route for both providers',
                'ai_provider_keys'           => 'Add AI_OPENAI_API_KEY and/or AI_GEMINI_API_KEY to server-php/.env, then run php spark reach:ai-seed-catalog.',
                'answers_without_version',
                'validation_failed_backlog'  => 'php spark community:advance --limit=10',
                'drafts_awaiting_advance'    => 'Install the cron: */15 * * * * cd <path>/server-php && php spark community:advance >> writable/logs/community-advance.log 2>&1',
                'deployment_retrying',
                'deployment_dead_letter'     => 'php spark reach:work --queue=community --once --limit=20   # drains the retry sweep',
                default                      => null,
            } ?? '';
        }

        return array_values(array_unique(array_filter($actions)));
    }
}
