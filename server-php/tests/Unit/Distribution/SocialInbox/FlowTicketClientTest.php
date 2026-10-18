<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution\SocialInbox;

use App\Libraries\Distribution\SocialInbox\FlowTicketClient;
use App\Libraries\Video\Automation\Http\HttpResponse;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Video\FakeHttpTransport;

/**
 * Reach → Flow ticket lodging against scripted HTTP (the contract of Flow's
 * POST /api/internal/reach/social-tickets).
 *
 * @covers \App\Libraries\Distribution\SocialInbox\FlowTicketClient
 *
 * @internal
 */
final class FlowTicketClientTest extends CIUnitTestCase
{
    private const BASE = 'https://flow.aicountly.org/api';
    private const KEY  = 'flow-reach-key-0123456789abcdef0123456789';

    private function client(FakeHttpTransport $http, ?string $key = self::KEY, ?string $base = self::BASE): FlowTicketClient
    {
        return new FlowTicketClient($base, $key, $http);
    }

    public function testACreatedTicketIsReturnedWithItsNumber(): void
    {
        $http = (new FakeHttpTransport())->on('POST', '#^https://flow\.aicountly\.org/api/internal/reach/social-tickets$#', FakeHttpTransport::json(201, [
            'success' => true, 'message' => 'Ticket created.', 'data' => ['created' => true, 'appended' => false, 'duplicate' => false, 'ticket_id' => 42, 'ticket_number' => 'TCK-2026-00042', 'priority' => 'high'],
        ]));
        $r = $this->client($http)->lodge(['source_message_id' => 'cmt_1', 'network' => 'linkedin', 'text' => 'Broken!']);

        $this->assertTrue($r['ok']);
        $this->assertSame(['created', 'TCK-2026-00042', 42], [$r['kind'], $r['ticket_number'], $r['ticket_id']]);
        $req = $http->requests[0];
        $this->assertSame('Bearer ' . self::KEY, $req['headers']['Authorization']);
        $this->assertSame('cmt_1', json_decode((string) $req['body'], true)['source_message_id']);
    }

    public function testDuplicateAndAppendedAreSuccesses(): void
    {
        $http = (new FakeHttpTransport())
            ->on('POST', '#social-tickets#', FakeHttpTransport::json(200, ['data' => ['duplicate' => true, 'ticket_id' => 42, 'ticket_number' => 'TCK-2026-00042']]), 1)
            ->on('POST', '#social-tickets#', FakeHttpTransport::json(200, ['data' => ['appended' => true, 'ticket_id' => 42, 'ticket_number' => 'TCK-2026-00042']]));
        $this->assertSame('duplicate', $this->client($http)->lodge([])['kind']);
        $this->assertSame('appended', $this->client($http)->lodge([])['kind']);
    }

    public function testFailuresAreClassifiedHonestly(): void
    {
        $cases = [
            [new HttpResponse(0, '', [], 'Connection timed out'), 'transient', 'network error'],
            [FakeHttpTransport::json(502, ['message' => 'Bad gateway']), 'transient', 'HTTP 502'],
            [FakeHttpTransport::json(401, ['message' => 'Invalid Reach key.']), 'auth', 'refused the Reach key'],
            [FakeHttpTransport::json(503, ['message' => 'Reach social tickets are not configured on this server (FLOW_REACH_INBOUND_KEY, at least 32 characters).']), 'not_configured', 'not set up for Reach'],
            [FakeHttpTransport::json(404, ['message' => 'Route not found.']), 'not_configured', 'no /internal/reach/social-tickets route'],
            [FakeHttpTransport::json(422, ['message' => 'Invalid social ticket.', 'errors' => ['text' => 'Required: the complaint as posted.']]), 'invalid', 'text: Required'],
            [FakeHttpTransport::json(200, ['data' => []]), 'error', 'without a ticket number'],
        ];
        foreach ($cases as [$answer, $kind, $text]) {
            $r = $this->client((new FakeHttpTransport())->on('POST', '#social-tickets#', $answer))->lodge(['x' => 1]);
            $this->assertFalse($r['ok'], $kind);
            $this->assertSame($kind, $r['kind'], $text);
            $this->assertStringContainsString($text, $r['message']);
            $this->assertStringNotContainsString(self::KEY, $r['message']);
        }
    }

    public function testNothingIsSentWithoutAUsableKeyOrUrl(): void
    {
        $http = new FakeHttpTransport();
        $this->assertStringContainsString('key is not set', $this->client($http, null)->lodge([])['message']);
        $this->assertStringContainsString('shorter than 32', $this->client($http, 'short-key')->lodge([])['message']);
        $this->assertStringContainsString('https only', $this->client($http, self::KEY, 'http://flow.example/api')->lodge([])['message']);
        $this->assertSame([], $http->requests);
    }

    public function testTheCheckProvesTheKeyWithoutLodging(): void
    {
        $http = (new FakeHttpTransport())->on('POST', '#social-tickets#', FakeHttpTransport::json(422, ['message' => 'Invalid social ticket.', 'errors' => ['source_message_id' => 'Required']]));
        $r = $this->client($http)->check();
        $this->assertTrue($r['ok']);
        $this->assertStringContainsString('Nothing was lodged', $r['message']);
        $this->assertSame('{}', $http->requests[0]['body']);

        $bad = $this->client((new FakeHttpTransport())->on('POST', '#social-tickets#', FakeHttpTransport::json(401, [])))->check();
        $this->assertFalse($bad['ok']);
        $this->assertSame('auth', $bad['kind']);
    }
}
