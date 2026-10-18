<?php

declare(strict_types=1);

namespace App\Libraries\Distribution\SocialInbox;

use App\Libraries\Settings\ReachConfig;
use App\Libraries\Video\Automation\Http\HttpResponse;
use App\Libraries\Video\Automation\Http\HttpTransport;
use App\Libraries\Video\Automation\VideoProviders;

/**
 * Reach → Flow: lodge a social-media complaint as a Flow ticket.
 *
 *   POST {flow.api_base_url}/internal/reach/social-tickets
 *   Authorization: Bearer {flow.reach_key}   (= Flow's FLOW_REACH_INBOUND_KEY, ≥ 32 chars)
 *
 * flow-react-app docs/REACH_SOCIAL_TICKETS.md. Flow is idempotent on
 * `source_message_id`, so a retry after a network error returns the ticket the
 * first call created (200 duplicate) instead of a second one.
 *
 * Outcome kinds: created | duplicate | appended (ticket known) ·
 * not_configured (no key / URL, Flow 503 "not configured") · auth (401) ·
 * invalid (422) · transient (network / 5xx — safe to retry) · error.
 */
final class FlowTicketClient
{
    public const PATH        = '/internal/reach/social-tickets';
    public const MIN_KEY_LEN = 32;
    public const TIMEOUT     = 20;

    private HttpTransport $http;

    public function __construct(private readonly ?string $baseUrl = null, private readonly ?string $key = null, ?HttpTransport $http = null)
    {
        $this->http = $http ?? VideoProviders::http();
    }

    public static function fromConfig(?HttpTransport $http = null): self
    {
        return new self((string) ReachConfig::get('flow.api_base_url'), ReachConfig::secret('flow.reach_key'), $http);
    }

    /** Why lodging cannot work at all, or null when configured. */
    public function configurationProblem(): ?string
    {
        $base = rtrim(trim((string) $this->baseUrl), '/');
        if ($base === '' || ! preg_match('#^https://#i', $base)) {
            return 'Flow API base URL is not set (Settings → Social inbox → flow.api_base_url, https only).';
        }
        $key = trim((string) $this->key);
        if ($key === '') {
            return 'The Reach → Flow key is not set (Settings → Social inbox → Flow key, or FLOW_REACH_INBOUND_KEY).';
        }
        if (strlen($key) < self::MIN_KEY_LEN) {
            return 'The Reach → Flow key is shorter than ' . self::MIN_KEY_LEN . ' characters; Flow refuses it.';
        }
        return null;
    }

    public function endpoint(): string
    {
        return rtrim(trim((string) $this->baseUrl), '/') . self::PATH;
    }

    /**
     * @param array<string, mixed> $body see docs/REACH_SOCIAL_TICKETS.md
     * @return array{ok: bool, kind: string, http_status: int, ticket_number: ?string, ticket_id: ?int, message: string}
     */
    public function lodge(array $body): array
    {
        $problem = $this->configurationProblem();
        if ($problem !== null) {
            return $this->out(false, 'not_configured', 0, null, null, $problem);
        }
        $res = $this->http->request('POST', $this->endpoint(), [
            'Authorization' => 'Bearer ' . trim((string) $this->key),
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
            'X-Source'      => 'reach.aicountly.org',
        ], (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ['timeout' => self::TIMEOUT]);

        return $this->interpret($res);
    }

    /**
     * Prove the key without lodging anything: an empty body must come back 422
     * (key accepted, body refused) — never 401 / 503.
     *
     * @return array{ok: bool, kind: string, http_status: int, ticket_number: ?string, ticket_id: ?int, message: string}
     */
    public function check(): array
    {
        $problem = $this->configurationProblem();
        if ($problem !== null) {
            return $this->out(false, 'not_configured', 0, null, null, $problem);
        }
        $res = $this->http->request('POST', $this->endpoint(), [
            'Authorization' => 'Bearer ' . trim((string) $this->key),
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ], '{}', ['timeout' => self::TIMEOUT]);
        $r = $this->interpret($res);
        if ($r['kind'] === 'invalid') {
            return $this->out(true, 'ok', $res->status, null, null, 'Flow accepted the key (HTTP 422 for the empty test body, as expected). Nothing was lodged.');
        }
        return $r;
    }

    /** @return array{ok: bool, kind: string, http_status: int, ticket_number: ?string, ticket_id: ?int, message: string} */
    private function interpret(HttpResponse $res): array
    {
        $json = $res->json();
        $data = is_array($json['data'] ?? null) ? $json['data'] : [];
        $msg  = is_string($json['message'] ?? null) ? mb_substr($json['message'], 0, 300) : '';
        $num  = is_string($data['ticket_number'] ?? null) && $data['ticket_number'] !== '' ? $data['ticket_number'] : null;
        $tid  = isset($data['ticket_id']) && is_numeric($data['ticket_id']) ? (int) $data['ticket_id'] : null;

        if ($res->ok()) {
            if ($num === null) {
                return $this->out(false, 'error', $res->status, null, $tid, 'Flow answered HTTP ' . $res->status . ' without a ticket number' . ($msg !== '' ? ' (' . $msg . ')' : '') . '.');
            }
            $kind = ! empty($data['created']) ? 'created' : (! empty($data['appended']) ? 'appended' : 'duplicate');
            return $this->out(true, $kind, $res->status, $num, $tid, 'Flow ticket ' . $num . ' (' . $kind . ').');
        }
        if ($res->status === 0) {
            return $this->out(false, 'transient', 0, null, null, 'Could not reach Flow at ' . $this->endpoint() . ' (network error' . ($res->transportError ? ': ' . mb_substr($res->transportError, 0, 160) : '') . ').');
        }
        return match (true) {
            $res->status === 401 => $this->out(false, 'auth', 401, null, null, 'Flow refused the Reach key (HTTP 401) — flow.reach_key must equal Flow\'s FLOW_REACH_INBOUND_KEY.'),
            $res->status === 422 => $this->out(false, 'invalid', 422, null, null, 'Flow refused the ticket (HTTP 422): ' . ($msg !== '' ? $msg : 'invalid body') . self::fieldErrors($json) . '.'),
            $res->status === 503 && str_contains(strtolower($msg), 'not configured') => $this->out(false, 'not_configured', 503, null, null, 'Flow is not set up for Reach yet (HTTP 503: ' . $msg . ').'),
            $res->status === 404 => $this->out(false, 'not_configured', 404, null, null, 'Flow has no ' . self::PATH . ' route (HTTP 404) — deploy the Flow release with Reach social tickets.'),
            $res->status >= 500 => $this->out(false, 'transient', $res->status, null, null, 'Flow answered HTTP ' . $res->status . ($msg !== '' ? ': ' . $msg : '') . '.'),
            default              => $this->out(false, 'error', $res->status, null, null, 'Flow answered HTTP ' . $res->status . ($msg !== '' ? ': ' . $msg : '') . '.'),
        };
    }

    private static function fieldErrors(array $json): string
    {
        $errors = is_array($json['errors'] ?? null) ? $json['errors'] : [];
        $parts  = [];
        foreach (array_slice($errors, 0, 5, true) as $field => $why) {
            if (is_string($why)) {
                $parts[] = (is_string($field) ? $field . ': ' : '') . mb_substr($why, 0, 120);
            }
        }
        return $parts !== [] ? ' (' . implode('; ', $parts) . ')' : '';
    }

    /** @return array{ok: bool, kind: string, http_status: int, ticket_number: ?string, ticket_id: ?int, message: string} */
    private function out(bool $ok, string $kind, int $status, ?string $num, ?int $tid, string $message): array
    {
        $key = trim((string) $this->key);
        if ($key !== '') {
            $message = str_replace($key, '[REDACTED]', $message);
        }
        return ['ok' => $ok, 'kind' => $kind, 'http_status' => $status, 'ticket_number' => $num, 'ticket_id' => $tid, 'message' => $message];
    }
}
