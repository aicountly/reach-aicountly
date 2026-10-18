<?php

declare(strict_types=1);

namespace Tests\Unit\Bot;

use App\Libraries\Bot\RoutineFireClient;
use App\Libraries\Video\Automation\Http\HttpResponse;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Video\FakeHttpTransport;

/**
 * The Routines API has no idempotency key: every accepted fire is a new
 * session. The client must send at most once unless the connection never
 * opened, and must report an ambiguous outcome as such.
 *
 * @internal
 */
final class RoutineFireClientTest extends CIUnitTestCase
{
    private const URL   = 'https://api.anthropic.com/v1/claude_code/routines/trig_abc123XYZ/fire';
    private const TOKEN = 'sk-ant-oat01-secret-token-value';

    private function client(FakeHttpTransport $http): RoutineFireClient
    {
        return new RoutineFireClient($http, static function (int $ms): void {
        });
    }

    public function testSuccessStoresTheSessionAndSendsTheDocumentedRequest(): void
    {
        $http = (new FakeHttpTransport())->on('POST', '#/fire$#', FakeHttpTransport::json(200, [
            'type' => 'routine_fire', 'claude_code_session_id' => 'session_01ABC', 'claude_code_session_url' => 'https://claude.ai/code/session_01ABC',
        ]));
        $r = $this->client($http)->fire(self::URL, self::TOKEN, 'Do the thing');

        $this->assertSame('submitted', $r['outcome']);
        $this->assertSame('session_01ABC', $r['session_id']);
        $this->assertSame('https://claude.ai/code/session_01ABC', $r['session_url']);
        $this->assertCount(1, $http->requests);
        $req = $http->requests[0];
        $this->assertSame(self::URL, $req['url']);
        $this->assertSame('Bearer ' . self::TOKEN, $req['headers']['Authorization']);
        $this->assertSame('2023-06-01', $req['headers']['anthropic-version']);
        $this->assertSame('application/json', $req['headers']['Content-Type']);
        $this->assertSame(['text' => 'Do the thing'], json_decode((string) $req['body'], true));
    }

    /** @return iterable<string, array{int, string, bool}> */
    public static function refusals(): iterable
    {
        yield '400 paused'   => [400, 'failed', false];
        yield '401 token'    => [401, 'failed', false];
        yield '403'          => [403, 'failed', false];
        yield '404'          => [404, 'failed', false];
        yield '429'          => [429, 'failed', true];
        yield '503'          => [503, 'failed', true];
        yield '500 unknown'  => [500, 'outcome_unknown', false];
        yield '504 unknown'  => [504, 'outcome_unknown', false];
    }

    /** @dataProvider refusals */
    public function testErrorsAreRecordedAndNeverRetried(int $status, string $outcome, bool $retryable): void
    {
        $http = (new FakeHttpTransport())->on('POST', '#/fire$#', FakeHttpTransport::json($status, ['error' => ['type' => 'x', 'message' => 'routine is paused; token ' . self::TOKEN]], ['Retry-After' => '120']));
        $r = $this->client($http)->fire(self::URL, self::TOKEN, 'x');

        $this->assertSame($outcome, $r['outcome']);
        $this->assertSame($status, $r['http_status']);
        $this->assertSame($retryable, $r['retryable']);
        $this->assertCount(1, $http->requests, 'a fire that reached Anthropic is never re-sent');
        $this->assertStringNotContainsString(self::TOKEN, (string) $r['error']);
        $this->assertNull($r['session_url']);
        if ($status === 429) {
            $this->assertSame(120, $r['retry_after']);
            $this->assertStringContainsString('30 fires per hour', (string) $r['error']);
        }
        if ($status === 401) {
            $this->assertStringContainsString('Generate a new token', (string) $r['error']);
        }
    }

    public function testConnectFailureBeforeSendingIsTriedOnceMore(): void
    {
        $http = (new FakeHttpTransport())
            ->on('POST', '#/fire$#', new HttpResponse(0, '', [], 'Could not resolve host: api.anthropic.com'), 1)
            ->on('POST', '#/fire$#', FakeHttpTransport::json(200, ['type' => 'routine_fire', 'claude_code_session_url' => 'https://claude.ai/code/session_2']));
        $r = $this->client($http)->fire(self::URL, self::TOKEN, 'x');

        $this->assertSame('submitted', $r['outcome']);
        $this->assertSame(2, $r['attempts']);
        $this->assertCount(2, $http->requests);
    }

    public function testConnectFailureTwiceIsAFailureThatIsSafeToResend(): void
    {
        $http = (new FakeHttpTransport())->on('POST', '#/fire$#', new HttpResponse(0, '', [], 'Failed to connect to api.anthropic.com port 443'));
        $r = $this->client($http)->fire(self::URL, self::TOKEN, 'x');

        $this->assertSame('failed', $r['outcome']);
        $this->assertTrue($r['retryable']);
        $this->assertCount(2, $http->requests);
        $this->assertStringContainsString('Nothing was sent', (string) $r['error']);
    }

    public function testTimeoutAfterSendingIsUnknownAndNotRetried(): void
    {
        $http = (new FakeHttpTransport())->on('POST', '#/fire$#', new HttpResponse(0, '', [], 'Operation timed out after 45001 milliseconds with 0 bytes received'));
        $r = $this->client($http)->fire(self::URL, self::TOKEN, 'x');

        $this->assertSame('outcome_unknown', $r['outcome']);
        $this->assertCount(1, $http->requests);
        $this->assertStringContainsString('check claude.ai', (string) $r['error']);
    }

    public function testTextOverTheLimitIsNeverSent(): void
    {
        $http = new FakeHttpTransport();
        $r    = $this->client($http)->fire(self::URL, self::TOKEN, str_repeat('a', 65537));
        $this->assertSame('failed', $r['outcome']);
        $this->assertCount(0, $http->requests);
    }
}
