<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Reach's central audit to Console (console-react-app AUTH_INTEGRATION.md "Central audit"):
 *
 *   POST {CONSOLE_API_URL | CONSOLE_API_BASE_URL}/portal/audit
 *   Authorization: Bearer {CONSOLE_API_TOKEN}     (Reach's own Console key, capability audit.write)
 *   {"events": [{action, external_id, app_code: "reach", entity_type, entity_id, actor_email,
 *                actor_name, occurred_at, ip, user_agent, old_value, new_value, metadata}]}
 *
 * It used to post each event once to {CONSOLE_API_BASE_URL}/audit — a route Console never had —
 * and only record the failure. Now every event is a `pending` row in reach_console_sync_logs
 * (the outbox, migration 2026-10-14-100002), sent at once, and retried with back-off by
 * `php spark reach:console-audit-deliver` until Console answers stored / duplicate (delivered),
 * rejected (kept, not retried) or 12 attempts fail (dead). Console de-duplicates on external_id,
 * so a retry never doubles an event. Every failure is logged ([ConsoleAuditClient] …); nothing
 * here throws to the caller. The Console Sync Status page reads the same rows.
 */
class ConsoleAuditClient
{
    public const APP_CODE     = 'reach';
    public const SOURCE       = 'reach.aicountly.org';
    public const PATH         = '/portal/audit';
    public const MAX_ATTEMPTS = 12;
    public const BATCH        = 100;

    private string $baseUrl;
    private string $token;
    private ?BaseConnection $db;

    /** @var callable(string, list<string>, string): array{status:int, body:string|false|null, error?:string} */
    private $transport;

    public function __construct(?callable $transport = null, ?string $baseUrl = null, ?string $token = null, ?BaseConnection $db = null)
    {
        $base = $baseUrl ?? (trim((string) env('CONSOLE_API_URL', '')) ?: trim((string) env('CONSOLE_API_BASE_URL', '')));
        $this->baseUrl   = rtrim($base, '/');
        $this->token     = $token ?? trim((string) env('CONSOLE_API_TOKEN', ''));
        $this->db        = $db;
        $this->transport = $transport ?? [self::class, 'curlPost'];
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->token !== '';
    }

    /**
     * Send a typed event to Console (queued first, delivered at once when Console takes it).
     * `type` is a dotted event key, e.g. 'reach.bot.generate_blog_draft', 'reach.approval.decided'.
     * Never throws.
     *
     * @return string 'delivered' | 'pending' | 'rejected' | 'dead' | 'duplicate' (already queued) | 'not_configured' | 'error'
     */
    public function event(string $type, array $payload, ?string $externalId = null): string
    {
        try {
            $externalId ??= 'reach:event:' . bin2hex(random_bytes(12));
            $event = self::toEvent($type, $payload, $externalId, $this->actor($payload));

            if (! $this->isConfigured()) {
                $this->db()->table('reach_console_sync_logs')->insert([
                    'event_type'    => substr($type, 0, 100),
                    'payload'       => json_encode($payload, JSON_UNESCAPED_SLASHES),
                    'ok'            => false,
                    'error_message' => 'CONSOLE_API_URL / CONSOLE_API_TOKEN not configured',
                    'attempted_at'  => date('Y-m-d H:i:s'),
                ]);
                log_message('warning', "[ConsoleAuditClient] type={$type} not sent: CONSOLE_API_URL / CONSOLE_API_TOKEN not configured");

                return 'not_configured';
            }

            $row = $this->db()->query(
                "INSERT INTO reach_console_sync_logs (event_type, payload, event, external_id, status, attempts, next_attempt_at, ok, attempted_at)
                 VALUES (?, CAST(? AS JSONB), CAST(? AS JSONB), ?, 'pending', 0, NOW(), false, NOW())
                 ON CONFLICT (external_id) WHERE external_id IS NOT NULL DO NOTHING
                 RETURNING id, external_id, attempts, event_type",
                [substr($type, 0, 100), json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                 json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $externalId]
            )->getRowArray();
            if ($row === null) {
                return 'duplicate';
            }

            $counts = $this->apply([$row], $this->send([$event]));
            foreach (['delivered', 'rejected', 'dead'] as $s) {
                if ($counts[$s] > 0) {
                    return $s;
                }
            }

            return 'pending';
        } catch (\Throwable $e) {
            log_message('error', "[ConsoleAuditClient] type={$type} failed: " . $e->getMessage());

            return 'error';
        }
    }

    /**
     * Deliver what is due (php spark reach:console-audit-deliver).
     *
     * @return array{batches:int, delivered:int, rejected:int, retry:int, dead:int}
     */
    public function deliverDue(int $maxBatches = 20): array
    {
        $out = ['batches' => 0, 'delivered' => 0, 'rejected' => 0, 'retry' => 0, 'dead' => 0];
        for ($b = 0; $b < $maxBatches; $b++) {
            $rows = $this->db()->query(
                "UPDATE reach_console_sync_logs SET next_attempt_at = NOW() + INTERVAL '10 minutes'
                  WHERE id IN (SELECT id FROM reach_console_sync_logs
                                WHERE status = 'pending' AND next_attempt_at <= NOW()
                                ORDER BY id LIMIT " . self::BATCH . ' FOR UPDATE SKIP LOCKED)
                  RETURNING id, external_id, attempts, event_type, event'
            )->getResultArray();
            if ($rows === []) {
                break;
            }
            usort($rows, static fn (array $x, array $y): int => (int) $x['id'] <=> (int) $y['id']);
            $events = array_map(static fn (array $r): array => json_decode((string) $r['event'], true) ?: ['external_id' => $r['external_id']], $rows);
            $counts = $this->apply($rows, $this->send($events));
            $out['batches']++;
            foreach ($counts as $k => $n) {
                $out[$k] += $n;
            }
            if ($counts['delivered'] + $counts['rejected'] === 0) {
                break; // Console is not taking anything now; the next run retries.
            }
        }

        return $out;
    }

    /**
     * Reach's (type, payload) as a Console audit event: Console's columns where it has one,
     * everything else Reach sent (and the original type) in metadata.
     *
     * @param array<string, mixed> $payload
     * @param array{email: ?string, name: ?string} $actor
     * @return array<string, mixed>
     */
    public static function toEvent(string $type, array $payload, string $externalId, array $actor = ['email' => null, 'name' => null]): array
    {
        $action = str_starts_with($type, 'reach.') ? substr($type, 6) : $type;
        $mapped = ['entity_type', 'entity_id', 'old_value', 'new_value', 'ip', 'user_agent', 'occurred_at', 'actor_email', 'actor_name'];
        $meta = ['type' => $type, 'source' => self::SOURCE];
        foreach ($payload as $k => $v) {
            if (! in_array($k, $mapped, true) && $v !== null) {
                $meta[$k] = $v;
            }
        }
        $entityId = $payload['entity_id'] ?? null;

        return [
            'action'      => $action,
            'external_id' => $externalId,
            'app_code'    => self::APP_CODE,
            'entity_type' => isset($payload['entity_type']) ? (string) $payload['entity_type'] : null,
            'entity_id'   => is_int($entityId) || is_string($entityId) ? $entityId : null,
            'actor_email' => $payload['actor_email'] ?? $actor['email'],
            'actor_name'  => $payload['actor_name'] ?? $actor['name'],
            'occurred_at' => is_string($payload['occurred_at'] ?? null) ? $payload['occurred_at'] : date(DATE_ATOM),
            'ip'          => isset($payload['ip']) ? (string) $payload['ip'] : null,
            'user_agent'  => isset($payload['user_agent']) ? (string) $payload['user_agent'] : null,
            'old_value'   => $payload['old_value'] ?? null,
            'new_value'   => $payload['new_value'] ?? null,
            'metadata'    => $meta,
        ];
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return array{status:int, results:list<array<string,mixed>>|null, error:string, body:?array}
     */
    public function send(array $events): array
    {
        $body = (string) json_encode(['events' => array_values($events)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $this->token,
            'X-Source: ' . self::SOURCE,
        ];
        $rid = $this->currentRequestId();
        if ($rid !== null) {
            $headers[] = 'X-Request-Id: ' . $rid;
        }
        try {
            $res = ($this->transport)($this->baseUrl . self::PATH, $headers, $body);
        } catch (\Throwable $e) {
            $res = ['status' => 0, 'body' => null, 'error' => $e->getMessage()];
        }
        $status = (int) ($res['status'] ?? 0);
        $raw = is_string($res['body'] ?? null) ? $res['body'] : null;
        $json = $raw !== null ? json_decode($raw, true) : null;
        $results = $status === 200 && is_array($json['data']['results'] ?? null) ? $json['data']['results'] : null;
        $error = (string) ($res['error'] ?? '');
        if ($results === null && $error === '') {
            $error = $raw !== null && $raw !== '' ? substr($raw, 0, 200) : 'no body';
        }

        return ['status' => $status, 'results' => $results, 'error' => $error, 'body' => is_array($json) ? $json : null];
    }

    /**
     * Record Console's answer on the outbox rows, in the order they were sent.
     *
     * @param list<array<string, mixed>> $rows id, external_id, attempts, event_type
     * @return array{delivered:int, rejected:int, retry:int, dead:int}
     */
    public function apply(array $rows, array $res): array
    {
        $counts = ['delivered' => 0, 'rejected' => 0, 'retry' => 0, 'dead' => 0];
        $byIndex = [];
        foreach ($res['results'] ?? [] as $r) {
            if (isset($r['index'])) {
                $byIndex[(int) $r['index']] = $r;
            }
        }
        $body = $res['body'] !== null ? json_encode($res['body'], JSON_UNESCAPED_SLASHES) : null;

        foreach (array_values($rows) as $i => $row) {
            $r = $byIndex[$i] ?? null;
            $outcome = is_array($r) ? (string) ($r['status'] ?? '') : '';
            if ($outcome === 'stored' || $outcome === 'duplicate') {
                $this->db()->query(
                    "UPDATE reach_console_sync_logs SET status = 'delivered', ok = true, event = NULL, attempts = attempts + 1,
                            response_status = ?, response_body = CAST(? AS JSONB), error_message = NULL, console_audit_id = ?, attempted_at = NOW()
                      WHERE id = ?",
                    [$res['status'], json_encode($r), isset($r['id']) ? (int) $r['id'] : null, $row['id']]
                );
                $counts['delivered']++;

                continue;
            }
            if ($outcome === 'rejected') {
                $err = 'rejected: ' . (string) ($r['error'] ?? 'unknown');
                $this->db()->query(
                    "UPDATE reach_console_sync_logs SET status = 'rejected', ok = false, attempts = attempts + 1, response_status = ?,
                            response_body = CAST(? AS JSONB), error_message = ?, attempted_at = NOW() WHERE id = ?",
                    [$res['status'], json_encode($r), $err, $row['id']]
                );
                log_message('error', "[ConsoleAuditClient] type={$row['event_type']} external_id={$row['external_id']} {$err} (not retried)");
                $counts['rejected']++;

                continue;
            }

            $attempts = (int) $row['attempts'] + 1;
            $dead = $attempts >= self::MAX_ATTEMPTS;
            $delay = min(360, 2 ** min($attempts, 9));
            $err = $res['results'] === null ? $res['error'] : 'no result for this event';
            $this->db()->query(
                "UPDATE reach_console_sync_logs SET status = ?, ok = false, attempts = ?, response_status = ?, response_body = CAST(? AS JSONB),
                        error_message = ?, next_attempt_at = NOW() + make_interval(mins => ?), attempted_at = NOW() WHERE id = ?",
                [$dead ? 'dead' : 'pending', $attempts, $res['status'], $body, substr($err, 0, 1000), $delay, $row['id']]
            );
            log_message($dead ? 'critical' : 'error', sprintf('[ConsoleAuditClient] type=%s status=%d err=%s (%s)', $row['event_type'], $res['status'], substr($err, 0, 200),
                $dead ? "dead after {$attempts} attempts" : "attempt {$attempts}, retry in {$delay} min"));
            $counts[$dead ? 'dead' : 'retry']++;
        }

        return $counts;
    }

    /**
     * The Reach user behind a payload's user_id / decided_by / created_by, for Console's actor columns.
     *
     * @return array{email: ?string, name: ?string}
     */
    private function actor(array $payload): array
    {
        foreach (['user_id', 'decided_by', 'created_by'] as $k) {
            $id = $payload[$k] ?? null;
            if ((is_int($id) || (is_string($id) && ctype_digit($id))) && (int) $id > 0) {
                try {
                    $u = $this->db()->table('reach_users')->select('email, name')->where('id', (int) $id)->get()->getRowArray();
                    if ($u !== null) {
                        return ['email' => $u['email'] ?? null, 'name' => $u['name'] ?? null];
                    }
                } catch (\Throwable) {
                    // the actor is context only
                }
                break;
            }
        }

        return ['email' => null, 'name' => null];
    }

    /**
     * Best-effort read of the current HTTP request-id, if set by RequestIdFilter.
     */
    private function currentRequestId(): ?string
    {
        try {
            $req = service('request');
            $id  = $req->reachRequestId ?? null;

            return is_string($id) && $id !== '' ? $id : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function db(): BaseConnection
    {
        return $this->db ??= Database::connect();
    }

    /** @return array{status:int, body:string|false, error:string} */
    private static function curlPost(string $url, array $headers, string $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        return ['status' => $status, 'body' => $raw, 'error' => $err];
    }
}
