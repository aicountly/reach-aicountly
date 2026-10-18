<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution\SocialInbox;

use App\Libraries\Distribution\Providers\SocialApi\SocialApiAiClient;
use App\Libraries\Distribution\Providers\SocialApi\SocialApiException;
use App\Libraries\Video\Automation\Http\HttpResponse;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Video\FakeHttpTransport;

/**
 * The inbox methods of the social-api.ai client (list / reply / hide) against
 * scripted HTTP: paths, query, auth, retries, and honest results.
 *
 * @covers \App\Libraries\Distribution\Providers\SocialApi\SocialApiAiClient
 *
 * @internal
 */
final class SocialApiInboxClientTest extends CIUnitTestCase
{
    private const KEY = 'sapi_key_inbox_unit_secret_42';

    private function client(FakeHttpTransport $http): SocialApiAiClient
    {
        return new SocialApiAiClient(self::KEY, null, $http, static function (int $s): void {
        });
    }

    public function testTheListSendsTheQueryAndBearerAndRetriesA5xx(): void
    {
        $http = (new FakeHttpTransport())
            ->on('GET', '#/v1/inbox/comments\?#', FakeHttpTransport::json(503, []), 1)
            ->on('GET', '#/v1/inbox/comments\?#', FakeHttpTransport::json(200, ['data' => []]));
        $json = $this->client($http)->inbox(['account_id' => 'acc_li', 'since' => '2026-10-17T00:00:00Z', 'limit' => 50, 'cursor' => null]);

        $this->assertSame(['data' => []], $json);
        $this->assertCount(2, $http->requests, 'one retry after the 503');
        $url = $http->requests[1]['url'];
        $this->assertSame('https://api.social-api.ai/v1/inbox/comments?account_id=acc_li&since=2026-10-17T00%3A00%3A00Z&limit=50', $url, 'empty cursor not sent');
        $this->assertSame('Bearer ' . self::KEY, $http->requests[1]['headers']['Authorization']);
    }

    public function testAccountIdCanBeAPathParameterAndUnsafePathsFallBack(): void
    {
        $http = (new FakeHttpTransport())->on('GET', '#.#', FakeHttpTransport::json(200, []));
        $this->client($http)->inbox(['account_id' => 'acc/1', 'limit' => 5], '/accounts/{account_id}/inbox');
        $this->assertSame('https://api.social-api.ai/v1/accounts/acc%2F1/inbox?limit=5', $http->requests[0]['url']);

        foreach (['https://evil.example/x', '../secrets', 'inbox', '/in box', '/inbox/comments,/inbox/conversations'] as $bad) {
            $this->assertSame('/inbox', SocialApiAiClient::path($bad, '/inbox'), $bad);
        }
    }

    public function testA404IsAnExceptionNamingThePathAndNonJsonIsRefused(): void
    {
        try {
            $this->client((new FakeHttpTransport())->on('GET', '#.#', FakeHttpTransport::json(404, ['error' => ['code' => 'resource.not_found', 'message' => 'Not found']])))->inbox([]);
            $this->fail('404 accepted');
        } catch (SocialApiException $e) {
            $this->assertSame(404, $e->httpStatus);
            $this->assertStringContainsString('inbox list (GET /inbox/comments) failed (HTTP 404): resource.not_found Not found', $e->getMessage());
        }
        $this->expectException(SocialApiException::class);
        $this->client((new FakeHttpTransport())->on('GET', '#.#', new HttpResponse(200, '<html>login</html>')))->inbox([]);
    }

    public function testAReplyIsAcceptedOnlyOnA2xxAndNeverRetried(): void
    {
        $http = (new FakeHttpTransport())->on('POST', '#/v1/inbox/comments/cmt%2F1/reply$#', FakeHttpTransport::json(201, ['data' => ['id' => 'rep_9']]));
        $r = $this->client($http)->replyToInboxItem('cmt/1', 'Thanks! — AICOUNTLY team', SocialApiAiClient::INBOX_REPLY_PATH, 'reach-social-inbox:7');
        $this->assertTrue($r['accepted']);
        $this->assertSame('rep_9', $r['reply_id']);
        $this->assertSame(['text' => 'Thanks! — AICOUNTLY team'], json_decode((string) $http->requests[0]['body'], true));
        $this->assertSame(40, strlen($http->requests[0]['headers']['Idempotency-Key']));

        $refused = $this->client((new FakeHttpTransport())->on('POST', '#reply#', FakeHttpTransport::json(422, ['error' => ['code' => 'platform.comment_closed', 'message' => 'Comments are closed']])))->replyToInboxItem('c', 't');
        $this->assertFalse($refused['accepted']);
        $this->assertSame('platform.comment_closed', $refused['error_code']);
        $this->assertFalse($refused['unknown_outcome']);

        $down = new FakeHttpTransport();
        $down->on('POST', '#reply#', new HttpResponse(0, '', [], 'timeout'));
        $unknown = $this->client($down)->replyToInboxItem('c', 't');
        $this->assertFalse($unknown['accepted']);
        $this->assertTrue($unknown['unknown_outcome']);
        $this->assertStringContainsString('outcome is unknown', (string) $unknown['message']);
        $this->assertCount(1, $down->requests, 'a reply POST is never retried');

        $fake2xx = $this->client((new FakeHttpTransport())->on('POST', '#reply#', FakeHttpTransport::json(200, ['success' => false, 'error' => 'not allowed'])))->replyToInboxItem('c', 't');
        $this->assertFalse($fake2xx['accepted'], 'a 2xx that says success=false is not a reply');
    }

    public function testHideUsesItsOwnPath(): void
    {
        $http = (new FakeHttpTransport())->on('POST', '#/v1/moderation/c1/hide$#', FakeHttpTransport::json(200, ['ok' => true]));
        $this->assertTrue($this->client($http)->hideInboxItem('c1', '/moderation/{id}/hide')['accepted']);
    }

    public function testTheListSettingIsSplitIntoSafePathsAndConversationsAreDirectMessages(): void
    {
        $this->assertSame(['/inbox/comments', '/inbox/conversations'], SocialApiAiClient::listPaths(SocialApiAiClient::INBOX_LIST_PATH));
        $this->assertSame(['/inbox/comments'], SocialApiAiClient::listPaths(' /inbox/comments , https://evil.example/x, ../up '));
        $this->assertSame(['/inbox/comments', '/inbox/conversations'], SocialApiAiClient::listPaths(''), 'nothing usable falls back to the confirmed defaults');
        $this->assertSame('message', SocialApiAiClient::listPathKind('/inbox/conversations'));
        $this->assertNull(SocialApiAiClient::listPathKind('/inbox/comments'));
    }
}
