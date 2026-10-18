<?php

declare(strict_types=1);

namespace App\Libraries\Bot;

use App\Libraries\Settings\ReachConfig;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Services;

/**
 * Bot → Ask the bot: an owner instruction becomes one run of the
 * reach-command Claude Routine (Reach never writes content itself).
 *
 * Never double-fires: the command row is inserted with its unique
 * idempotency key BEFORE the fire (status `firing`); a repeated request with
 * the same key returns that row; only the request that inserted the row
 * fires, at most once (RoutineFireClient re-sends only when the connection
 * never opened). Outcomes are recorded honestly: `submitted` with the session
 * URL, `failed` with the reason, or `outcome_unknown` when the request may
 * have reached Anthropic without an answer. The routine reports back through
 * POST v1/automation/commands/{id}/report.
 */
class BotCommandService
{
    public const MAX_INSTRUCTION   = 4000;
    public const MAX_ATTACHMENTS   = 10;
    /** Stay under the routine's own 30 fires/hour. */
    public const HOURLY_FIRE_LIMIT = 25;
    public const MAX_REPORTS       = 20;
    private const STALE_FIRING_SECS = 180;
    public const REPORT_STATUSES   = ['in_progress', 'completed', 'partial', 'failed'];

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null, private ?RoutineFireClient $client = null)
    {
        $this->db = $db ?? Database::connect();
    }

    // ── configuration ──────────────────────────────────────────────────────

    /** @return array{configured: bool, url_set: bool, url_valid: bool, token_set: bool, problems: list<string>} */
    public function configuration(): array
    {
        $url   = $this->fireUrl();
        $token = $this->token();
        $valid = $url !== '' && preg_match(ReachConfig::ROUTINE_FIRE_URL_PATTERN, $url) === 1;
        $problems = [];
        if ($url === '') {
            $problems[] = 'bot.command.routine_fire_url is not set.';
        } elseif (! $valid) {
            $problems[] = 'bot.command.routine_fire_url is not a Claude routine API trigger URL (https://api.anthropic.com/v1/claude_code/routines/trig_…/fire).';
        }
        if ($token === null) {
            $problems[] = 'bot.command.routine_token is not set.';
        }
        return ['configured' => $valid && $token !== null, 'url_set' => $url !== '', 'url_valid' => $valid, 'token_set' => $token !== null, 'problems' => $problems];
    }

    public function configured(): bool
    {
        return $this->configuration()['configured'];
    }

    /** @return list<string> */
    public static function setupSteps(): array
    {
        return [
            'In claude.ai → Code → Routines, create a routine named "reach-command" (model Sonnet, fresh session per fire) in the environment the other Reach routines use, with the prompt from docs/automation/routines/reach-command.md. It needs no schedule.',
            'Open the routine → Add another trigger → API → Save. Copy the URL (https://api.anthropic.com/v1/claude_code/routines/trig_…/fire).',
            'Generate a token for that trigger and copy it (sk-ant-oat01-…; shown once).',
            'In Reach → Settings → AI & Automation → Bot commands, paste the URL and the token and save.',
        ];
    }

    // ── submit ─────────────────────────────────────────────────────────────

    /**
     * @param list<mixed> $channels
     * @param list<mixed> $attachments
     * @param array{id?: int|string|null, name?: string|null, email?: string|null} $user
     * @return array{replayed: bool, command: array<string, mixed>}
     */
    public function submit(string $instruction, array $channels, array $attachments, array $user, ?string $idempotencyKey = null, ?string $activityKey = null, ?\DateTimeImmutable $now = null): array
    {
        $now         = $now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $instruction = trim(str_replace("\r\n", "\n", $instruction));
        $len         = mb_strlen($instruction);
        if ($len < 3 || $len > self::MAX_INSTRUCTION) {
            throw new BotActionException('instruction must be 3–' . self::MAX_INSTRUCTION . ' characters.', 422, 'invalid');
        }
        $channels    = self::normalizeChannels($channels);
        $attachments = self::normalizeAttachments($attachments);
        $idem        = BotActivityService::normalizeIdempotencyKey($idempotencyKey);

        $existing = $this->findBy('idempotency_key', $idem);
        if ($existing !== null) {
            return ['replayed' => true, 'command' => $this->present($existing)];
        }

        $config = $this->configuration();
        if (! $config['configured']) {
            throw new BotActionException(
                'The reach-command routine is not configured, so nothing was sent. ' . implode(' ', $config['problems']),
                409,
                'not_configured',
                null,
                ['setup' => self::setupSteps(), 'configuration' => $config],
            );
        }

        $this->settleStaleFiring($now);
        $this->assertHourlyCap($now);

        $userId = isset($user['id']) && $user['id'] !== null ? (int) $user['id'] : null;
        $uuid   = BotActivityService::uuid();
        try {
            $this->db->table('reach_bot_commands')->insert([
                'uuid'             => $uuid,
                'kind'             => $activityKey !== null ? 'activity_run' : 'command',
                'activity_key'     => $activityKey,
                'instruction'      => $instruction,
                'channels_json'    => json_encode($channels),
                'attachments_json' => json_encode($attachments, JSON_UNESCAPED_SLASHES),
                'idempotency_key'  => $idem,
                'status'           => 'firing',
                'created_by'       => $userId,
                'created_by_name'  => isset($user['name']) ? mb_substr((string) $user['name'], 0, 200) : null,
                'created_by_email' => isset($user['email']) ? mb_substr((string) $user['email'], 0, 255) : null,
                'created_at'       => $now->format('Y-m-d H:i:sP'),
                'updated_at'       => $now->format('Y-m-d H:i:sP'),
            ]);
        } catch (\Throwable $e) {
            $winner = $this->findBy('idempotency_key', $idem);
            if ($winner !== null) {
                return ['replayed' => true, 'command' => $this->present($winner)];
            }
            throw $e;
        }
        $id  = (int) $this->db->insertID();
        $row = (array) $this->findBy('id', $id);

        $text = BotCommandPayloadBuilder::build($row, null, $now);
        $this->db->table('reach_bot_commands')->where('id', $id)->update(['payload_text' => $text, 'payload_chars' => mb_strlen($text)]);

        $result = ($this->client ?? new RoutineFireClient())->fire($this->fireUrl(), (string) $this->token(), $text);

        $fields = [
            'status'           => $result['outcome'],
            'fire_http_status' => $result['http_status'],
            'fire_error'       => $result['error'] !== null ? mb_substr($result['error'], 0, 2000) : null,
            'retryable'        => $result['retryable'],
            'retry_after_secs' => $result['retry_after'],
            'session_id'       => $result['session_id'],
            'session_url'      => $result['session_url'],
            'fired_at'         => gmdate('Y-m-d H:i:sP'),
            'updated_at'       => gmdate('Y-m-d H:i:sP'),
        ];
        // Only the request that claimed the row records its fire.
        $this->db->table('reach_bot_commands')->where('id', $id)->where('status', 'firing')->update($fields);

        $this->audit($userId, $result['outcome'] === 'submitted' ? 'bot.command.fired' : 'bot.command.fire_' . $result['outcome'], $id, [
            'uuid'         => $uuid,
            'activity_key' => $activityKey,
            'channels'     => $channels,
            'http_status'  => $result['http_status'],
            'session_url'  => $result['session_url'],
            'error'        => $result['error'],
            'attempts'     => $result['attempts'],
            'requested_by' => $user['email'] ?? null,
        ]);

        return ['replayed' => false, 'command' => $this->present((array) $this->findBy('id', $id))];
    }

    // ── read ───────────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    public function history(int $limit = 50): array
    {
        $this->settleStaleFiring(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $rows = $this->db->table('reach_bot_commands')->orderBy('id', 'DESC')->limit(max(1, min(200, $limit)))->get()->getResultArray();
        return array_map(fn ($r) => $this->present($r, true), $rows);
    }

    /** @return array<string, mixed>|null */
    public function find(string $idOrUuid): ?array
    {
        $row = ctype_digit($idOrUuid) ? $this->findBy('id', (int) $idOrUuid) : (self::isUuid($idOrUuid) ? $this->findBy('uuid', strtolower($idOrUuid)) : null);
        return $row;
    }

    /**
     * What the routine sees when it verifies the command.
     *
     * @return array<string, mixed>
     */
    public function forAutomation(array $row): array
    {
        return [
            'command_id'   => $row['uuid'],
            'kind'         => $row['kind'],
            'activity_key' => $row['activity_key'],
            'instruction'  => $row['instruction'],
            'channels'     => self::json($row['channels_json']),
            'attachments'  => self::json($row['attachments_json']),
            'status'       => $row['status'],
            'issued_by'    => $row['created_by_name'] ?: $row['created_by_email'],
            'created_at'   => $row['created_at'],
            'report_status' => $row['report_status'],
            'report_count' => (int) $row['report_count'],
            'artifacts'    => $this->artifacts((int) $row['id']),
        ];
    }

    // ── report (from the routine) ──────────────────────────────────────────

    /**
     * @param array<string, mixed> $input {status, summary, links?: list<{label?, url}|string>}
     * @return array<string, mixed>
     */
    public function report(string $idOrUuid, array $input): array
    {
        $row = $this->find($idOrUuid);
        if ($row === null) {
            throw new BotActionException('No such command.', 404, 'not_found');
        }
        $status = strtolower(trim((string) ($input['status'] ?? '')));
        if (! in_array($status, self::REPORT_STATUSES, true)) {
            throw new BotActionException('status must be one of: ' . implode(', ', self::REPORT_STATUSES) . '.', 422, 'invalid');
        }
        $summary = trim((string) ($input['summary'] ?? ''));
        if ($summary === '' || mb_strlen($summary) > 8000) {
            throw new BotActionException('summary is required (at most 8000 characters).', 422, 'invalid');
        }
        $links = self::normalizeLinks((array) ($input['links'] ?? []));

        // Reportable once the routine got it (or might have): a fire that failed
        // never reached a session, so nothing can be reporting on it.
        $delivered = in_array($row['status'], ['submitted', 'outcome_unknown', 'in_progress', 'completed', 'partial'], true)
            || ($row['status'] === 'failed' && $row['report_status'] !== null);
        if (! $delivered) {
            throw new BotActionException('This command was never delivered to the routine (status ' . $row['status'] . '), so it cannot be reported on.', 409, 'not_fired');
        }
        if ((int) $row['report_count'] >= self::MAX_REPORTS) {
            throw new BotActionException('This command already has ' . self::MAX_REPORTS . ' reports.', 429, 'rate_limited');
        }

        $now = gmdate('Y-m-d H:i:sP');
        $this->db->table('reach_bot_commands')->where('id', (int) $row['id'])->update([
            'status'            => $status,
            'report_status'     => $status,
            'report_summary'    => $summary,
            'report_links_json' => json_encode($links, JSON_UNESCAPED_SLASHES),
            'report_count'      => (int) $row['report_count'] + 1,
            'reported_at'       => $now,
            'updated_at'        => $now,
        ]);

        try {
            Services::auditLogger()->log(
                userId: null,
                action: 'bot.command.reported',
                entityType: 'bot_command',
                entityId: (int) $row['id'],
                newValue: ['uuid' => $row['uuid'], 'status' => $status, 'links' => $links],
                actorType: 'service',
                actorService: 'reach:automation',
            );
        } catch (\Throwable) {
        }

        return $this->present((array) $this->findBy('id', (int) $row['id']));
    }

    // ── presentation ───────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function present(array $row, bool $withArtifacts = true): array
    {
        return [
            'id'               => (int) $row['id'],
            'uuid'             => $row['uuid'],
            'kind'             => $row['kind'],
            'activity_key'     => $row['activity_key'],
            'instruction'      => $row['instruction'],
            'channels'         => self::json($row['channels_json']),
            'attachments'      => self::json($row['attachments_json']),
            'status'           => $row['status'],
            'fire_http_status' => $row['fire_http_status'] !== null ? (int) $row['fire_http_status'] : null,
            'fire_error'       => $row['fire_error'],
            'retryable'        => in_array($row['retryable'], [true, 't', 'true', 1, '1'], true),
            'retry_after_secs' => $row['retry_after_secs'] !== null ? (int) $row['retry_after_secs'] : null,
            'session_url'      => $row['session_url'],
            'report_status'    => $row['report_status'],
            'report_summary'   => $row['report_summary'],
            'report_links'     => self::json($row['report_links_json']),
            'reported_at'      => $row['reported_at'],
            'created_by'       => $row['created_by_name'] ?: $row['created_by_email'],
            'created_at'       => $row['created_at'],
            'fired_at'         => $row['fired_at'],
            'payload_chars'    => $row['payload_chars'] !== null ? (int) $row['payload_chars'] : null,
            'artifacts'        => $withArtifacts ? $this->artifacts((int) $row['id']) : [],
        ];
    }

    /**
     * What Reach holds for a command right now: videos, social posts and
     * social submissions made with its command_id, with their live status.
     *
     * @return list<array<string, mixed>>
     */
    public function artifacts(int $commandId): array
    {
        $out = [];
        try {
            foreach ($this->db->table('reach_video_pipeline_items')->select('uuid, format, title, status, step, step_detail, youtube_url, last_error')->where('bot_command_id', $commandId)->orderBy('id')->get()->getResultArray() as $v) {
                $out[] = ['type' => 'video', 'ref' => $v['uuid'], 'title' => $v['title'], 'format' => $v['format'], 'status' => $v['status'], 'step' => $v['step'], 'detail' => $v['step_detail'], 'error' => $v['last_error'], 'url' => $v['youtube_url'], 'link' => '/video'];
            }
            foreach ($this->db->table('reach_social_posts')->select('id, channel, status, remote_url, error_message, provider_error_code')->where('bot_command_id', $commandId)->orderBy('id')->get()->getResultArray() as $p) {
                $out[] = ['type' => 'social_post', 'ref' => (string) $p['id'], 'channel' => $p['channel'], 'status' => $p['status'], 'error' => $p['error_message'], 'url' => $p['remote_url'], 'link' => '/social/queue'];
            }
            foreach ($this->db->table('reach_automation_social_submissions')->select('uuid, status, vetting_decision, vetting_reasons_json')->where('bot_command_id', $commandId)->where('status !=', 'approved')->orderBy('id')->get()->getResultArray() as $s) {
                $out[] = ['type' => 'social_submission', 'ref' => $s['uuid'], 'status' => $s['status'], 'decision' => $s['vetting_decision'], 'reasons' => self::json($s['vetting_reasons_json'])];
            }
        } catch (\Throwable $e) {
            log_message('error', 'bot command artifacts: ' . $e->getMessage());
        }
        return $out;
    }

    // ── internals ──────────────────────────────────────────────────────────

    private function fireUrl(): string
    {
        return trim((string) ReachConfig::get('bot.command.routine_fire_url', ''));
    }

    private function token(): ?string
    {
        $t = ReachConfig::secret('bot.command.routine_token');
        return $t !== null && trim($t) !== '' ? trim($t) : null;
    }

    /** A request that died between claiming and recording its fire leaves `firing`; that outcome is unknown. */
    private function settleStaleFiring(\DateTimeImmutable $now): void
    {
        $cutoff = $now->modify('-' . self::STALE_FIRING_SECS . ' seconds')->format('Y-m-d H:i:sP');
        $this->db->table('reach_bot_commands')->where('status', 'firing')->where('created_at <', $cutoff)->update([
            'status'     => 'outcome_unknown',
            'fire_error' => 'Reach stopped before recording the routine\'s answer. A session may or may not have started — check claude.ai → Routines → reach-command.',
            'updated_at' => $now->format('Y-m-d H:i:sP'),
        ]);
    }

    private function assertHourlyCap(\DateTimeImmutable $now): void
    {
        $since = $now->modify('-1 hour')->format('Y-m-d H:i:sP');
        $rows  = $this->db->table('reach_bot_commands')->select('created_at')
            ->where('created_at >=', $since)
            ->whereIn('status', ['firing', 'submitted', 'outcome_unknown', 'in_progress', 'completed', 'partial'])
            ->orderBy('created_at', 'ASC')->get()->getResultArray();
        if (count($rows) >= self::HOURLY_FIRE_LIMIT) {
            $wait = max(1, 3600 - ($now->getTimestamp() - (int) strtotime((string) $rows[0]['created_at'])));
            throw new BotActionException(sprintf('%d commands were sent in the last hour (Reach keeps under the routine\'s limit of 30 per hour). Try again in %d minutes.', count($rows), (int) ceil($wait / 60)), 429, 'rate_limited', $wait);
        }
    }

    /** @return array<string, mixed>|null */
    private function findBy(string $col, int|string $value): ?array
    {
        return $this->db->table('reach_bot_commands')->where($col, $value)->get()->getRowArray();
    }

    /** @return list<string> */
    public static function normalizeChannels(array $channels): array
    {
        $out = [];
        foreach ($channels as $c) {
            if (! is_string($c) || trim($c) === '') {
                continue;
            }
            $n = SocialChannelReadiness::normalize($c);
            if ($n === null) {
                throw new BotActionException("Unknown channel '{$c}'. Use: " . implode(', ', SocialChannelReadiness::CHANNELS) . '.', 422, 'invalid');
            }
            $out[] = $n;
        }
        return array_values(array_unique($out));
    }

    /** @return list<string> */
    public static function normalizeAttachments(array $attachments): array
    {
        if (count($attachments) > self::MAX_ATTACHMENTS) {
            throw new BotActionException('At most ' . self::MAX_ATTACHMENTS . ' attachments.', 422, 'invalid');
        }
        $out = [];
        foreach ($attachments as $a) {
            $url = is_array($a) ? (string) ($a['url'] ?? '') : (string) $a;
            $url = trim($url);
            if ($url === '') {
                continue;
            }
            if (! self::isHttpsUrl($url)) {
                throw new BotActionException("Attachment '" . mb_substr($url, 0, 80) . "' must be an https:// URL (files cannot be uploaded here).", 422, 'invalid');
            }
            $out[] = $url;
        }
        return array_values(array_unique($out));
    }

    /** @return list<array{label: string, url: string}> */
    public static function normalizeLinks(array $links): array
    {
        $out = [];
        foreach (array_slice($links, 0, 30) as $l) {
            $url   = is_array($l) ? trim((string) ($l['url'] ?? '')) : trim((string) $l);
            $label = is_array($l) ? trim((string) ($l['label'] ?? '')) : '';
            if ($url === '' || ! self::isHttpsUrl($url)) {
                continue;
            }
            $out[] = ['label' => mb_substr($label !== '' ? $label : $url, 0, 200), 'url' => $url];
        }
        return $out;
    }

    public static function isHttpsUrl(string $url): bool
    {
        return mb_strlen($url) <= 2000 && filter_var($url, FILTER_VALIDATE_URL) !== false && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https' && (string) parse_url($url, PHP_URL_HOST) !== '';
    }

    public static function isUuid(string $s): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $s) === 1;
    }

    /** @return list<mixed> */
    private static function json(mixed $v): array
    {
        if (is_array($v)) {
            return $v;
        }
        $d = json_decode((string) $v, true);
        return is_array($d) ? $d : [];
    }

    private function audit(?int $userId, string $action, int $id, array $data): void
    {
        try {
            Services::auditLogger()->log(
                userId: $userId,
                action: $action,
                entityType: 'bot_command',
                entityId: $id,
                newValue: $data,
                actorType: 'human',
                actorService: 'reach:api',
            );
        } catch (\Throwable $e) {
            log_message('error', 'bot command audit failed: ' . $e->getMessage());
        }
    }
}
