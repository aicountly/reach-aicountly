<?php

declare(strict_types=1);

namespace App\Libraries\Bot;

use App\Libraries\Settings\ReachConfig;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Services;

/**
 * Bot → Activities: what every bot activity is, when it runs, how its last
 * run went — and "Run now".
 *
 * A manual run is never a different code path: a `job` activity enqueues the
 * scheduler's own job type on its own queue with the payload the scheduler
 * would build now; a `command` activity queues the command job, which runs
 * the cron's own spark command; a `routine` activity fires the reach-command
 * routine with that routine's prompt file.
 *
 * Guards: an idempotency key per click (a replay returns the first run), one
 * manual run of an activity in flight at a time, a cooldown between manual
 * runs, the route throttle, and an audit event with the owner as actor.
 */
class BotActivityService
{
    public const COOLDOWN_SECS         = 60;
    public const ROUTINE_COOLDOWN_SECS = 600;
    private const LIVE_JOB_STATUSES    = ['pending', 'reserved', 'processing'];

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null, private ?BotCommandService $commands = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(?\DateTimeImmutable $now = null): array
    {
        $now      = $now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $registry = BotActivityRegistry::all();

        $jobTypes = array_values(array_unique(array_filter(array_map(static fn ($e) => $e['job_type'] ?? null, $registry))));
        $latestJobs   = $this->latestJobsByType($jobTypes);
        $latestManual = $this->latestManualRuns();
        $latestCmdJob = $this->latestCommandJobs();
        $latestRoutine = $this->latestRoutineCommands();
        $commandConfigured = $this->commands()->configured();

        $out = [];
        foreach ($registry as $key => $entry) {
            $out[] = $this->describe($key, $entry, $now, [
                'job'      => isset($entry['job_type']) ? ($latestJobs[$entry['job_type']] ?? null) : ($latestCmdJob[$key] ?? null),
                'manual'   => $latestManual[$key] ?? null,
                'routine'  => $latestRoutine[$key] ?? null,
            ], $commandConfigured);
        }
        return $out;
    }

    /** @return array<string, mixed>|null */
    public function one(string $key, ?\DateTimeImmutable $now = null): ?array
    {
        foreach ($this->list($now) as $row) {
            if ($row['key'] === $key) {
                return $row;
            }
        }
        return null;
    }

    /**
     * Run an activity now.
     *
     * @param array{id?: int|string|null, name?: string|null, email?: string|null, role?: string|null} $user
     * @return array{replayed: bool, run: array<string, mixed>}
     * @throws BotActionException  not_found (404), not_runnable / busy (409), cooldown (429), not_configured (409)
     */
    public function run(string $key, array $user, ?string $idempotencyKey = null, ?\DateTimeImmutable $now = null): array
    {
        $now   = $now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $entry = BotActivityRegistry::get($key);
        if ($entry === null) {
            throw new BotActionException("Unknown bot activity '{$key}'.", 404, 'not_found');
        }
        $notRunnable = $this->notRunnableReason($entry);
        if ($notRunnable !== null) {
            throw new BotActionException($notRunnable, 409, 'not_runnable');
        }

        $idem = self::normalizeIdempotencyKey($idempotencyKey);
        $existing = $this->db->table('reach_bot_activity_runs')->where('idempotency_key', $idem)->get()->getRowArray();
        if ($existing !== null) {
            if ($existing['activity_key'] !== $key) {
                throw new BotActionException('This idempotency key was already used for another activity.', 409, 'idempotency_conflict');
            }
            return ['replayed' => true, 'run' => $this->presentRun($existing)];
        }

        $this->assertNotBusy($key, $entry, $now);

        $userId = isset($user['id']) && $user['id'] !== null ? (int) $user['id'] : null;
        $runUuid = self::uuid();
        try {
            $this->db->table('reach_bot_activity_runs')->insert([
                'uuid'               => $runUuid,
                'activity_key'       => $key,
                'kind'               => $entry['kind'],
                'idempotency_key'    => $idem,
                'status'             => 'queued',
                'requested_by'       => $userId,
                'requested_by_name'  => isset($user['name']) ? mb_substr((string) $user['name'], 0, 200) : null,
                'requested_by_email' => isset($user['email']) ? mb_substr((string) $user['email'], 0, 255) : null,
                'created_at'         => $now->format('Y-m-d H:i:sP'),
            ]);
        } catch (\Throwable $e) {
            // A concurrent request with the same key won the insert.
            $winner = $this->db->table('reach_bot_activity_runs')->where('idempotency_key', $idem)->get()->getRowArray();
            if ($winner !== null) {
                return ['replayed' => true, 'run' => $this->presentRun($winner)];
            }
            throw $e;
        }
        $runId = (int) $this->db->insertID();

        $update = [];
        try {
            if ($entry['kind'] === 'job') {
                $opts = [
                    'queue'               => (string) $entry['queue'],
                    'idempotency_key'     => "bot-run-{$key}-{$runUuid}",
                    'correlation_id'      => $runUuid,
                    'enqueued_by_user_id' => $userId,
                    'enqueued_actor_type' => 'human',
                ];
                if (isset($entry['max_attempts'])) {
                    $opts['max_attempts'] = (int) $entry['max_attempts'];
                }
                $payload = ($entry['payload'])($now, $userId);
                $jobId   = Services::jobService()->enqueue((string) $entry['job_type'], $payload, $opts);
                $update  = ['job_id' => $jobId, 'message' => "Queued {$entry['job_type']} on the {$entry['queue']} queue (job #{$jobId}); the worker picks it up within a minute."];
            } elseif ($entry['kind'] === 'command') {
                $jobId  = Services::jobService()->enqueue(BotActivityRegistry::COMMAND_JOB_TYPE, ['activity_key' => $key], [
                    'queue'               => 'default',
                    'idempotency_key'     => "bot-run-{$key}-{$runUuid}",
                    'correlation_id'      => $runUuid,
                    'enqueued_by_user_id' => $userId,
                    'enqueued_actor_type' => 'human',
                    'max_attempts'        => 1,
                ]);
                $line   = 'php spark ' . trim($entry['command'] . ' ' . implode(' ', (array) ($entry['args'] ?? [])));
                $update = ['job_id' => $jobId, 'message' => "Queued `{$line}` (job #{$jobId}); the worker runs it within a minute."];
            } else { // routine
                $result = $this->commands()->submit(
                    self::routineInstruction($entry),
                    [],
                    [],
                    $user,
                    'activity-' . $idem,
                    $key,
                );
                $cmd    = $result['command'];
                $update = [
                    'bot_command_id' => (int) $cmd['id'],
                    'status'         => in_array($cmd['status'], ['submitted', 'outcome_unknown'], true) ? 'fired' : 'failed',
                    'message'        => $cmd['status'] === 'submitted'
                        ? 'Fired reach-command to run ' . $entry['routine'] . ' now.' . ($cmd['session_url'] ? ' Session: ' . $cmd['session_url'] : '')
                        : ($cmd['fire_error'] ?? ('Fire ' . $cmd['status'] . '.')),
                ];
            }
        } catch (BotActionException $e) {
            $this->db->table('reach_bot_activity_runs')->where('id', $runId)->update(['status' => 'failed', 'message' => mb_substr($e->getMessage(), 0, 2000)]);
            throw $e;
        } catch (\Throwable $e) {
            $this->db->table('reach_bot_activity_runs')->where('id', $runId)->update(['status' => 'failed', 'message' => mb_substr('Could not queue the run: ' . $e->getMessage(), 0, 2000)]);
            $this->audit($userId, 'bot.activity.run_failed', $runId, ['activity_key' => $key, 'error' => $e->getMessage()]);
            throw new BotActionException('Could not queue the run: ' . $e->getMessage(), 500, 'enqueue_failed');
        }

        $this->db->table('reach_bot_activity_runs')->where('id', $runId)->update($update);
        $row = (array) $this->db->table('reach_bot_activity_runs')->where('id', $runId)->get()->getRowArray();

        $this->audit($userId, 'bot.activity.run', $runId, [
            'activity_key' => $key,
            'kind'         => $entry['kind'],
            'job_type'     => $entry['job_type'] ?? ($entry['kind'] === 'command' ? BotActivityRegistry::COMMAND_JOB_TYPE : null),
            'job_id'       => isset($row['job_id']) ? (int) $row['job_id'] : null,
            'bot_command_id' => isset($row['bot_command_id']) ? (int) $row['bot_command_id'] : null,
            'run_uuid'     => $runUuid,
            'status'       => $row['status'],
            'requested_by' => $user['email'] ?? null,
        ]);

        return ['replayed' => false, 'run' => $this->presentRun($row)];
    }

    /** The instruction a routine "Run now" sends to reach-command. */
    public static function routineInstruction(array $entry): string
    {
        return sprintf(
            "Run the `%s` routine now, once, exactly as its scheduled run would: open %s in the reach-aicountly repository, "
            . "take the prompt in its \"Prompt\" section and carry it out step by step (same endpoints, same rules, same daily quotas — "
            . "if Reach says the quota is used or the automation is switched off, stop). Then report what it did.",
            (string) $entry['routine'],
            (string) $entry['prompt_file'],
        );
    }

    // ── internals ───────────────────────────────────────────────────────────

    /** @param array<string, mixed> $entry */
    private function notRunnableReason(array $entry): ?string
    {
        if (isset($entry['not_runnable'])) {
            return (string) $entry['not_runnable'];
        }
        if (! in_array($entry['kind'], ['job', 'command', 'routine'], true)) {
            return 'This activity cannot be run from here.';
        }
        return null;
    }

    /** @param array<string, mixed> $entry */
    private function assertNotBusy(string $key, array $entry, \DateTimeImmutable $now): void
    {
        $last = $this->db->query(
            'SELECT r.*, j.status AS job_status FROM reach_bot_activity_runs r LEFT JOIN reach_jobs j ON j.id = r.job_id
             WHERE r.activity_key = ? AND r.status <> ? ORDER BY r.id DESC LIMIT 1',
            [$key, 'failed']
        )->getRowArray();
        if ($last === null) {
            return;
        }
        if ($last['job_id'] !== null && in_array((string) $last['job_status'], self::LIVE_JOB_STATUSES, true)) {
            throw new BotActionException(sprintf('A manual run of this activity is already %s (job #%d). Wait for it to finish.', $last['job_status'], (int) $last['job_id']), 409, 'busy');
        }
        $cooldown = $entry['kind'] === 'routine' ? self::ROUTINE_COOLDOWN_SECS : self::COOLDOWN_SECS;
        $elapsed  = $now->getTimestamp() - strtotime((string) $last['created_at']);
        if ($elapsed < $cooldown) {
            throw new BotActionException(sprintf('This activity was run by hand %d s ago; wait %d s before running it again.', max(0, $elapsed), $cooldown - $elapsed), 429, 'cooldown', $cooldown - $elapsed);
        }
    }

    /**
     * @param array<string, mixed>              $entry
     * @param array<string, array<string, mixed>|null> $last
     * @return array<string, mixed>
     */
    private function describe(string $key, array $entry, \DateTimeImmutable $now, array $last, bool $commandConfigured): array
    {
        $cron = new CronSchedule((string) $entry['cron']);
        $next = $cron->nextAfter($now);
        $ist  = new \DateTimeZone('Asia/Kolkata');

        $notRunnable = $this->notRunnableReason($entry);
        if ($notRunnable === null && $entry['kind'] === 'routine' && ! $commandConfigured) {
            $notRunnable = 'The reach-command routine is not configured: add its API trigger URL and token in Settings → AI & Automation → Bot commands (docs/operations/BOT_ACTIONS.md).';
        }

        $job      = $last['job'];
        $manual   = $last['manual'];
        $routine  = $last['routine'];
        $lastRun  = null;
        if ($job !== null) {
            $lastRun = [
                'source'       => (int) ($job['enqueued_by_user_id'] ?? 0) > 0 ? 'manual' : 'scheduled',
                'job_id'       => (int) $job['id'],
                'status'       => (string) $job['status'],
                'queued_at'    => $job['created_at'],
                'finished_at'  => $job['completed_at'] ?? null,
                'error'        => $job['error_message'] !== null ? mb_substr((string) $job['error_message'], 0, 500) : null,
                'link'         => '/admin/jobs/' . (int) $job['id'],
            ];
        } elseif ($routine !== null) {
            $lastRun = [
                'source'      => 'manual',
                'command_id'  => (int) $routine['id'],
                'status'      => (string) $routine['status'],
                'queued_at'   => $routine['created_at'],
                'finished_at' => $routine['reported_at'] ?? null,
                'error'       => $routine['fire_error'] !== null ? mb_substr((string) $routine['fire_error'], 0, 500) : null,
                'link'        => $routine['session_url'] ?: '/bot/ask',
            ];
        }

        $logFile = isset($entry['log']) ? WRITEPATH . 'logs/' . $entry['log'] : null;
        $logAt   = $logFile !== null && is_file($logFile) ? gmdate('c', (int) filemtime($logFile)) : null;

        $visibility = match ($entry['kind']) {
            'job'     => 'Last run is the latest job of this type (scheduled or manual).',
            'command' => 'Cron runs are not recorded in Reach; the log file time is when cron last wrote to it. Manual runs are shown with their job.',
            'routine' => 'Scheduled runs happen in claude.ai and are not visible to Reach; manual runs are shown with their session link.',
            default   => null,
        };

        $enabled = null;
        if (isset($entry['setting'])) {
            try {
                $enabled = (bool) ReachConfig::get((string) $entry['setting']);
            } catch (\Throwable) {
                $enabled = null;
            }
        }

        return [
            'key'           => $key,
            'name'          => $entry['name'],
            'group'         => $entry['group'],
            'kind'          => $entry['kind'],
            'description'   => $entry['description'],
            'schedule'      => $entry['schedule'],
            'cron_utc'      => $entry['cron'],
            'next_run_utc'  => $next?->format('c'),
            'next_run_ist'  => $next?->setTimezone($ist)->format('Y-m-d H:i') . ($next ? ' IST' : ''),
            'job_type'      => $entry['job_type'] ?? ($entry['kind'] === 'command' ? BotActivityRegistry::COMMAND_JOB_TYPE : null),
            'queue'         => $entry['queue'] ?? ($entry['kind'] === 'command' ? 'default' : null),
            'command'       => $entry['kind'] === 'command' ? 'php spark ' . trim($entry['command'] . ' ' . implode(' ', (array) ($entry['args'] ?? []))) : null,
            'routine'       => $entry['routine'] ?? null,
            'prompt_file'   => $entry['prompt_file'] ?? null,
            'cost_note'     => $entry['cost'] ?? null,
            'setting'       => $entry['setting'] ?? null,
            'enabled'       => $enabled,
            'safe_to_run_now' => $notRunnable === null,
            'not_runnable_reason' => $notRunnable,
            'last_run'      => $lastRun,
            'last_manual_run' => $manual !== null ? $this->presentRun($manual) : null,
            'log_file'      => $entry['log'] ?? null,
            'log_written_at' => $logAt,
            'visibility_note' => $visibility,
            'link'          => $entry['link'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function presentRun(array $row): array
    {
        $job = null;
        if (! empty($row['job_id'])) {
            $job = $this->db->table('reach_jobs')->select('id, status, error_message, created_at, completed_at, result_json')->where('id', (int) $row['job_id'])->get()->getRowArray();
        }
        $cmd = null;
        if (! empty($row['bot_command_id'])) {
            $cmd = $this->db->table('reach_bot_commands')->select('id, uuid, status, session_url, fire_error')->where('id', (int) $row['bot_command_id'])->get()->getRowArray();
        }
        return [
            'uuid'         => $row['uuid'],
            'activity_key' => $row['activity_key'],
            'kind'         => $row['kind'],
            'status'       => $row['status'],
            'message'      => $row['message'],
            'requested_by' => $row['requested_by_name'] ?: $row['requested_by_email'],
            'created_at'   => $row['created_at'],
            'job'          => $job !== null ? [
                'id'           => (int) $job['id'],
                'status'       => $job['status'],
                'error'        => $job['error_message'] !== null ? mb_substr((string) $job['error_message'], 0, 500) : null,
                'completed_at' => $job['completed_at'],
                'link'         => '/admin/jobs/' . (int) $job['id'],
            ] : null,
            'command'      => $cmd !== null ? [
                'id'          => (int) $cmd['id'],
                'uuid'        => $cmd['uuid'],
                'status'      => $cmd['status'],
                'session_url' => $cmd['session_url'],
                'error'       => $cmd['fire_error'],
            ] : null,
        ];
    }

    /**
     * @param list<string> $types
     * @return array<string, array<string, mixed>>
     */
    private function latestJobsByType(array $types): array
    {
        if ($types === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($types), '?'));
        $rows  = $this->db->query(
            "SELECT DISTINCT ON (job_type) id, job_type, status, error_message, created_at, completed_at, enqueued_by_user_id
             FROM reach_jobs WHERE job_type IN ({$marks}) ORDER BY job_type, id DESC",
            $types
        )->getResultArray();
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['job_type']] = $r;
        }
        return $out;
    }

    /** @return array<string, array<string, mixed>> latest command job per activity key */
    private function latestCommandJobs(): array
    {
        $rows = $this->db->query(
            "SELECT DISTINCT ON (payload_json->>'activity_key') id, payload_json->>'activity_key' AS activity_key, status, error_message, created_at, completed_at, enqueued_by_user_id
             FROM reach_jobs WHERE job_type = ? ORDER BY payload_json->>'activity_key', id DESC",
            [BotActivityRegistry::COMMAND_JOB_TYPE]
        )->getResultArray();
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['activity_key']] = $r;
        }
        return $out;
    }

    /** @return array<string, array<string, mixed>> */
    private function latestManualRuns(): array
    {
        $rows = $this->db->query('SELECT DISTINCT ON (activity_key) * FROM reach_bot_activity_runs ORDER BY activity_key, id DESC')->getResultArray();
        $out  = [];
        foreach ($rows as $r) {
            $out[(string) $r['activity_key']] = $r;
        }
        return $out;
    }

    /** @return array<string, array<string, mixed>> */
    private function latestRoutineCommands(): array
    {
        $rows = $this->db->query(
            'SELECT DISTINCT ON (activity_key) id, activity_key, status, session_url, fire_error, created_at, reported_at
             FROM reach_bot_commands WHERE activity_key IS NOT NULL ORDER BY activity_key, id DESC'
        )->getResultArray();
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['activity_key']] = $r;
        }
        return $out;
    }

    private function audit(?int $userId, string $action, int $runId, array $data): void
    {
        try {
            Services::auditLogger()->log(
                userId: $userId,
                action: $action,
                entityType: 'bot_activity_run',
                entityId: $runId,
                newValue: $data,
                actorType: 'human',
                actorService: 'reach:api',
            );
        } catch (\Throwable $e) {
            log_message('error', 'bot activity audit failed: ' . $e->getMessage());
        }
    }

    private function commands(): BotCommandService
    {
        return $this->commands ??= new BotCommandService($this->db);
    }

    public static function normalizeIdempotencyKey(?string $key): string
    {
        $key = trim((string) $key);
        if ($key === '') {
            return 'auto-' . self::uuid();
        }
        if (! preg_match('/^[A-Za-z0-9._:\-]{8,100}$/', $key)) {
            throw new BotActionException('idempotency_key must be 8–100 characters of letters, digits and . _ : -', 422, 'invalid');
        }
        return $key;
    }

    public static function uuid(): string
    {
        $b    = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
