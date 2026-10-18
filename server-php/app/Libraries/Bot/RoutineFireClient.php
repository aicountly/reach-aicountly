<?php

declare(strict_types=1);

namespace App\Libraries\Bot;

use App\Libraries\Video\Automation\Http\CurlTransport;
use App\Libraries\Video\Automation\Http\HttpResponse;
use App\Libraries\Video\Automation\Http\HttpTransport;

/**
 * Fires a Claude Code Routine through its API trigger:
 *
 *   POST https://api.anthropic.com/v1/claude_code/routines/{trig_id}/fire
 *   Authorization: Bearer <routine token>   anthropic-version: 2023-06-01
 *   {"text": "<≤ 65,536 chars>"}
 *   200 {"type":"routine_fire","claude_code_session_id":"session_…","claude_code_session_url":"https://claude.ai/code/session_…"}
 *
 * The API has NO idempotency key — every accepted call starts a new session —
 * so this client sends at most once, with one exception: when the connection
 * could not be opened at all (DNS / connect / TLS handshake failure) the
 * request never left Reach and is tried once more. Anything that may have
 * reached Anthropic (a timeout after connecting, a 500 / 504) is reported as
 * `outcome_unknown`, never retried. There is no status API: the session URL
 * is all Reach can keep.
 */
class RoutineFireClient
{
    public const MAX_TEXT = 65536;
    public const API_VERSION = '2023-06-01';

    /** cURL errors raised before any byte of the request was sent. */
    private const NOT_SENT = '/^(Could not resolve|Couldn\'t resolve|Resolving timed out|Failed to connect|Couldn\'t connect|Connection refused|Connection timed out after|SSL connect error|curl_init failed)/i';

    /** @var callable(int): void */
    private $sleep;

    private static ?HttpTransport $testTransport = null;

    /** @internal tests: every client built without a transport uses this one */
    public static function useTransport(?HttpTransport $transport): void
    {
        self::$testTransport = $transport;
    }

    public function __construct(private ?HttpTransport $http = null, ?callable $sleep = null)
    {
        $this->sleep = $sleep ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
    }

    /**
     * @return array{outcome: string, http_status: ?int, session_id: ?string, session_url: ?string, error: ?string, retryable: bool, retry_after: ?int, attempts: int}
     */
    public function fire(string $url, string $token, string $text): array
    {
        if (mb_strlen($text) > self::MAX_TEXT) {
            return self::result('failed', null, null, null, 'The command text is longer than the Routines API accepts (65,536 characters). Nothing was sent.', false, null, 0);
        }
        $body    = (string) json_encode(['text' => $text], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = [
            'Authorization'     => 'Bearer ' . $token,
            'anthropic-version' => self::API_VERSION,
            'Content-Type'      => 'application/json',
            'Accept'            => 'application/json',
        ];
        $http = $this->http ?? self::$testTransport ?? new CurlTransport();
        if (self::$testTransport !== null && $this->http === null) {
            $this->sleep = static function (int $ms): void {
            };
        }

        $attempts = 0;
        do {
            $attempts++;
            $res = $http->request('POST', $url, $headers, $body, ['timeout' => 45]);
            $notSent = $res->status === 0 && preg_match(self::NOT_SENT, (string) $res->transportError) === 1;
            if ($notSent && $attempts < 2) {
                ($this->sleep)(1500);
                continue;
            }
            break;
        } while (true);

        return $this->interpret($res, $attempts, $notSent ?? false, $token);
    }

    /** @return array{outcome: string, http_status: ?int, session_id: ?string, session_url: ?string, error: ?string, retryable: bool, retry_after: ?int, attempts: int} */
    private function interpret(HttpResponse $res, int $attempts, bool $notSent, string $token): array
    {
        $status = $res->status;
        if ($status === 0) {
            $err = self::redact((string) $res->transportError, $token);
            return $notSent
                ? self::result('failed', null, null, null, "Could not reach api.anthropic.com ({$err}). Nothing was sent; it is safe to send again.", true, null, $attempts)
                : self::result('outcome_unknown', null, null, null, "The connection failed after the request was sent ({$err}). A session may or may not have started — check claude.ai → Routines → reach-command before sending again.", false, null, $attempts);
        }

        $json    = $res->json();
        $message = self::redact(self::providerMessage($json, $res->body), $token);

        if ($status >= 200 && $status < 300) {
            $sid  = is_string($json['claude_code_session_id'] ?? null) ? $json['claude_code_session_id'] : null;
            $surl = is_string($json['claude_code_session_url'] ?? null) && str_starts_with($json['claude_code_session_url'], 'https://') ? $json['claude_code_session_url'] : null;
            return self::result('submitted', $status, $sid, $surl, $surl === null ? 'The routine accepted the fire but returned no session URL; find the session in claude.ai → Routines → reach-command.' : null, false, null, $attempts);
        }

        $retryAfter = null;
        $ra = $res->header('retry-after');
        if ($ra !== null && ctype_digit(trim($ra))) {
            $retryAfter = (int) trim($ra);
        }

        return match (true) {
            $status === 400 => self::result('failed', 400, null, null, 'The Routines API refused the request (400' . ($message !== '' ? ': ' . $message : '') . '). Common causes: the routine is paused, the text is too long, or the API version changed. Nothing was started.', false, null, $attempts),
            $status === 401 => self::result('failed', 401, null, null, 'The routine token was refused (401). Generate a new token for the reach-command API trigger in claude.ai and paste it in Settings → AI & Automation → Bot commands. Nothing was started.', false, null, $attempts),
            $status === 403 => self::result('failed', 403, null, null, 'The token may not fire this routine (403' . ($message !== '' ? ': ' . $message : '') . '). Check the URL and token belong to the same reach-command trigger. Nothing was started.', false, null, $attempts),
            $status === 404 => self::result('failed', 404, null, null, 'No such routine trigger (404). Copy the API trigger URL again from claude.ai → Routines → reach-command. Nothing was started.', false, null, $attempts),
            $status === 429 => self::result('failed', 429, null, null, 'Routine rate limit reached (429: 30 fires per hour per routine, 100 per hour per account)' . ($retryAfter !== null ? "; try again in {$retryAfter} s" : '') . '. Nothing was started.', true, $retryAfter, $attempts),
            in_array($status, [502, 503, 529], true) => self::result('failed', $status, null, null, "Anthropic was unavailable (HTTP {$status}) and did not start a session. Reach does not re-send automatically; send the command again in a few minutes.", true, $retryAfter, $attempts),
            $status >= 500 => self::result('outcome_unknown', $status, null, null, "Anthropic answered HTTP {$status}. A session may or may not have started — check claude.ai → Routines → reach-command before sending again." . ($message !== '' ? ' (' . $message . ')' : ''), false, null, $attempts),
            default => self::result('failed', $status, null, null, "Unexpected answer from the Routines API (HTTP {$status}" . ($message !== '' ? ': ' . $message : '') . ').', false, null, $attempts),
        };
    }

    private static function providerMessage(array $json, string $body): string
    {
        $m = $json['error']['message'] ?? $json['message'] ?? $json['error'] ?? null;
        if (is_string($m)) {
            return mb_substr(trim($m), 0, 400);
        }
        return $json === [] ? mb_substr(trim(strip_tags($body)), 0, 200) : '';
    }

    private static function redact(string $s, string $token): string
    {
        if ($token !== '') {
            $s = str_replace($token, '[REDACTED]', $s);
        }
        return (string) preg_replace('/sk-ant-[A-Za-z0-9_\-]+/', '[REDACTED]', $s);
    }

    /** @return array{outcome: string, http_status: ?int, session_id: ?string, session_url: ?string, error: ?string, retryable: bool, retry_after: ?int, attempts: int} */
    private static function result(string $outcome, ?int $status, ?string $sid, ?string $surl, ?string $error, bool $retryable, ?int $retryAfter, int $attempts): array
    {
        return [
            'outcome'     => $outcome,
            'http_status' => $status,
            'session_id'  => $sid,
            'session_url' => $surl,
            'error'       => $error,
            'retryable'   => $retryable,
            'retry_after' => $retryAfter,
            'attempts'    => $attempts,
        ];
    }
}
