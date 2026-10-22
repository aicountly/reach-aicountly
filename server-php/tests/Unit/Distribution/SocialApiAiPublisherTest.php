<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution;

use App\Libraries\Distribution\Providers\ChannelMessage;
use App\Libraries\Distribution\Providers\ProviderNotConfiguredException;
use App\Libraries\Distribution\Providers\SocialApi\SocialApiAccounts;
use App\Libraries\Distribution\Providers\SocialApi\SocialApiAiClient;
use App\Libraries\Distribution\Providers\SocialApi\SocialApiException;
use App\Libraries\Distribution\Providers\SocialApi\SocialApiPlatforms;
use App\Libraries\Distribution\Providers\SocialApi\SocialApiPostResult;
use App\Libraries\Distribution\Providers\SocialApi\SocialApiRedactor;
use App\Libraries\Distribution\Providers\SocialApiAiPublisher;
use App\Libraries\Distribution\SocialPublisherService;
use App\Libraries\Video\Automation\Http\HttpResponse;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Video\FakeHttpTransport;
use Tests\Support\Video\VideoEnv;

/**
 * social-api.ai client + publisher against scripted HTTP (nothing reaches the
 * network: FakeHttpTransport answers 599 to anything unscripted).
 *
 * @covers \App\Libraries\Distribution\Providers\SocialApiAiPublisher
 * @covers \App\Libraries\Distribution\Providers\SocialApi\SocialApiAiClient
 * @covers \App\Libraries\Distribution\Providers\SocialApi\SocialApiAccounts
 * @covers \App\Libraries\Distribution\Providers\SocialApi\SocialApiPostResult
 * @covers \App\Libraries\Distribution\Providers\SocialApi\SocialApiRedactor
 * @covers \App\Libraries\Distribution\Providers\SocialApi\SocialApiPlatforms
 *
 * @internal
 */
final class SocialApiAiPublisherTest extends CIUnitTestCase
{
    use VideoEnv;

    private const KEY  = 'sapi_key_unit_secret_0123456789';
    private const BASE = 'https://api.social-api.ai/v1';
    private const MP4  = 'https://cdn.shotstack.example/out/render-1.mp4';
    private const PUT  = 'https://uploads.social-api.example/m/med_1?X-Amz-Signature=deadbeefsig&X-Amz-Expires=900';

    private const ACCOUNTS = [
        ['id' => 'acc_yt', 'name' => 'AICOUNTLY', 'username' => 'aicountly', 'platform' => 'youtube', 'status' => 'active'],
        ['id' => 'acc_ig', 'name' => 'AICOUNTLY IG', 'platform' => 'instagram', 'status' => 'connected'],
        ['id' => 'acc_li', 'name' => 'AICOUNTLY', 'platform' => 'linkedin', 'status' => 'active'],
    ];

    private int $now = 1_800_000_000;
    /** @var list<int> */
    private array $slept = [];

    protected function setUp(): void
    {
        parent::setUp();
        $env = ['SOCIALAPI_API_KEY' => null, 'SOCIALAPI_BASE_URL' => null, 'SOCIAL_PROVIDER' => null];
        foreach (SocialApiPlatforms::PLATFORMS as $p) {
            $env['SOCIALAPI_ACCOUNT_' . strtoupper($p)]       = null;
            $env['SOCIALAPI_PLATFORM_DATA_' . strtoupper($p)] = null;
        }
        $this->setVideoEnv($env);
        SocialApiAccounts::forget();
        $this->now   = 1_800_000_000;
        $this->slept = [];
    }

    protected function tearDown(): void
    {
        SocialApiAccounts::forget();
        $this->restoreVideoEnv();
        parent::tearDown();
    }

    private function http(array $accounts = self::ACCOUNTS): FakeHttpTransport
    {
        return (new FakeHttpTransport())->on('GET', '#^' . preg_quote(self::BASE, '#') . '/accounts$#', FakeHttpTransport::json(200, $accounts));
    }

    /** The three media calls plus the source download. */
    private function withMedia(FakeHttpTransport $http, array $verify = [['status' => 'ready']]): FakeHttpTransport
    {
        $http->on('GET', '#^' . preg_quote(self::MP4, '#') . '$#', new HttpResponse(200, 'MP4BYTES-0123456789', ['content-type' => 'video/mp4']))
            ->on('GET', '#/media/upload-url\?#', FakeHttpTransport::json(200, ['media_id' => 'med_1', 'upload_url' => self::PUT, 'expires_in' => 900]))
            ->on('PUT', '#^https://uploads\.social-api\.example/#', new HttpResponse(200, ''));
        foreach ($verify as $i => $answer) {
            $http->on('POST', '#/media/med_1/verify$#', FakeHttpTransport::json(200, $answer), $i < count($verify) - 1 ? 1 : null);
        }
        return $http;
    }

    private function publisher(FakeHttpTransport $http): SocialApiAiPublisher
    {
        $sleep   = function (int $s): void {
            $this->slept[] = $s;
        };
        $client  = new SocialApiAiClient(self::KEY, null, $http, $sleep);
        $clock   = fn (): int => $this->now;
        return new SocialApiAiPublisher(self::KEY, null, $client, new SocialApiAccounts($client, null, $clock, false), $sleep);
    }

    private static function message(string $content = 'Hello from Reach', array $media = [], array $meta = [], string $key = 'social-dispatch:7:abc'): ChannelMessage
    {
        return new ChannelMessage(idempotencyKey: $key, recipientAddress: 'default-page', content: $content, mediaRefs: $media, metadata: $meta);
    }

    /** @return array<string, mixed> */
    private static function body(FakeHttpTransport $http): array
    {
        $posts = $http->to('#/v1/posts$#', 'POST');
        return json_decode((string) end($posts)['body'], true);
    }

    // ── account resolution ─────────────────────────────────────────────────

    public function testTheSingleActiveAccountOfThePlatformIsPicked(): void
    {
        $http = $this->http()->on('POST', '#/v1/posts$#', FakeHttpTransport::json(201, ['id' => 'post_1', 'status' => 'pending', 'targets' => [['account_id' => 'acc_li', 'status' => 'pending']]]));
        $receipt = $this->publisher($http)->publish(self::message(), 'linkedin');

        $this->assertTrue($receipt->isSubmitted());
        $this->assertFalse($receipt->isAccepted(), 'a 201 is not "published"');
        $this->assertSame('post_1', $receipt->providerMessageId);
        $this->assertSame('acc_li', $receipt->meta['account_id']);
        $this->assertSame('auto', $receipt->meta['account_source']);
        $this->assertSame([['account_id' => 'acc_li']], self::body($http)['targets']);
        $this->assertSame('Bearer ' . self::KEY, $http->to('#/accounts$#')[0]['headers']['Authorization']);
    }

    public function testNoActiveAccountFailsClosedNamingWhatWasFound(): void
    {
        $http = $this->http([
            ['id' => 'acc_li', 'name' => 'Old page', 'platform' => 'linkedin', 'status' => 'expired'],
            ['id' => 'acc_ig', 'platform' => 'instagram', 'status' => 'active'],
        ]);
        try {
            $this->publisher($http)->publish(self::message(), 'linkedin');
            $this->fail('no account must not post');
        } catch (ProviderNotConfiguredException $e) {
            $this->assertStringContainsString('No active LinkedIn account is connected in social-api.ai', $e->getMessage());
            $this->assertStringContainsString('Found but not active: linkedin “Old page” acc_li (expired)', $e->getMessage());
            $this->assertStringContainsString('set social.socialapi.account.linkedin', $e->getMessage());
        }
        $this->assertSame([], $http->to('#/posts#'), 'nothing was posted');
    }

    public function testAmbiguousAccountsFailClosedListingTheIds(): void
    {
        $http = $this->http([
            ['id' => 'acc_fb_1', 'name' => 'AICOUNTLY', 'platform' => 'facebook', 'status' => 'active'],
            ['id' => 'acc_fb_2', 'name' => 'AICOUNTLY Test', 'platform' => 'facebook'],
        ]);
        $this->expectException(ProviderNotConfiguredException::class);
        $this->expectExceptionMessageMatches('/2 active Facebook accounts .*acc_fb_1.*acc_fb_2.*Set social\.socialapi\.account\.facebook/');
        $this->publisher($http)->publish(self::message(), 'facebook');
    }

    public function testTheSettingOverrideWinsWithoutListingAccounts(): void
    {
        $this->setVideoEnv(['SOCIALAPI_ACCOUNT_FACEBOOK' => 'acc_fb_2']);
        $http = (new FakeHttpTransport())->on('POST', '#/v1/posts$#', FakeHttpTransport::json(201, ['id' => 'post_9']));
        $receipt = $this->publisher($http)->publish(self::message(), 'facebook');

        $this->assertSame('acc_fb_2', $receipt->meta['account_id']);
        $this->assertSame('setting', $receipt->meta['account_source']);
        $this->assertSame([], $http->to('#/accounts#'));
        $this->assertSame([['account_id' => 'acc_fb_2']], self::body($http)['targets']);
    }

    public function testThePlatformFieldIsReadTolerantlyAndTwitterIsX(): void
    {
        $http = $this->http([
            ['id' => 'a1', 'provider' => 'YouTube_Channel'],
            ['id' => 'a2', 'platform' => ['slug' => 'twitter', 'name' => 'X']],
            ['id' => 'a3', 'network' => 'threads'],
            ['id' => 'a4', 'display_name' => 'no platform at all'],
        ]);
        $accounts = $this->publisher($http)->accounts();
        $this->assertSame('a1', $accounts->resolve('youtube')['id']);
        $this->assertSame('a2', $accounts->resolve('x')['id']);
        $this->assertSame('x', SocialApiPlatforms::forChannel('twitter'));

        $all = array_column($accounts->all(), null, 'id');
        $this->assertNull($all['a3']['platform'], 'a platform Reach does not publish to is not guessed');
        $this->assertSame('network', $all['a3']['platform_field']);
        $this->assertNull($all['a4']['platform']);
    }

    public function testTheAccountListIsCachedForTenMinutes(): void
    {
        $http      = $this->http();
        $publisher = $this->publisher($http);
        $publisher->accounts()->resolve('youtube');
        $publisher->accounts()->resolve('linkedin');
        $this->assertCount(1, $http->to('#/accounts$#'));

        $this->now += SocialApiAccounts::CACHE_TTL_SECS + 1;
        $publisher->accounts()->resolve('youtube');
        $this->assertCount(2, $http->to('#/accounts$#'));
    }

    public function testAccountListingRetriesIdempotentGetsOnlyOn5xx(): void
    {
        $http = (new FakeHttpTransport())
            ->on('GET', '#/accounts$#', FakeHttpTransport::json(502, ['error' => 'bad gateway']), 1)
            ->on('GET', '#/accounts$#', FakeHttpTransport::json(200, ['data' => self::ACCOUNTS]));
        $this->assertSame('acc_yt', $this->publisher($http)->accounts()->resolve('youtube')['id']);
        $this->assertCount(2, $http->to('#/accounts$#'));
        $this->assertSame([1], $this->slept);

        SocialApiAccounts::forget();
        $denied = (new FakeHttpTransport())->on('GET', '#/accounts$#', FakeHttpTransport::json(401, ['error' => ['code' => 'auth.invalid', 'message' => 'bad key']]));
        try {
            $this->publisher($denied)->publish(self::message(), 'youtube');
            $this->fail('a refused key must not post');
        } catch (ProviderNotConfiguredException $e) {
            $this->assertStringContainsString('the API key was refused', $e->getMessage());
        }
        $this->assertCount(1, $denied->to('#/accounts$#'), '4xx is not retried');
    }

    // ── media ──────────────────────────────────────────────────────────────

    public function testAVideoIsDownloadedUploadedToThePresignedUrlVerifiedAndAttached(): void
    {
        $http = $this->withMedia($this->http(), [['status' => 'pending'], ['data' => ['status' => 'ready']]])
            ->on('POST', '#/v1/posts$#', FakeHttpTransport::json(201, ['post_id' => 'post_v', 'status' => 'processing']));
        $media = [
            ['type' => 'video', 'kind' => 'youtube_short', 'url' => 'https://www.youtube.com/shorts/ABCDEFG1234'],
            ['type' => 'video', 'kind' => 'mp4', 'url' => self::MP4],
        ];
        $receipt = $this->publisher($http)->publish(self::message('Description', $media, ['title' => 'GST in 60 seconds #Shorts']), 'youtube');

        $this->assertSame('post_v', $receipt->providerMessageId);
        $this->assertSame(['med_1'], $receipt->meta['media_ids']);

        // 1. source fetched without Reach credentials, streamed to a file
        $get = $http->to('#shotstack#')[0];
        $this->assertArrayNotHasKey('Authorization', $get['headers']);
        $this->assertArrayHasKey('sink_file', $get['options']);
        $this->assertFileDoesNotExist($get['options']['sink_file'], 'the temp file is removed');
        $this->assertSame([], $http->to('#youtube\.com#'), 'a YouTube link is not a file to upload');

        // 2. presigned upload URL for the right type
        $q = [];
        parse_str((string) parse_url($http->to('#upload-url#')[0]['url'], PHP_URL_QUERY), $q);
        $this->assertSame('video/mp4', $q['media_type']);
        $this->assertMatchesRegularExpression('/^reach-[0-9a-f]{12}\.mp4$/', $q['filename']);

        // 3. bytes PUT to the presigned URL — without the API key
        $put = $http->to('#uploads\.social-api#', 'PUT')[0];
        $this->assertSame('MP4BYTES-0123456789', $put['body']);
        $this->assertSame('video/mp4', $put['headers']['Content-Type']);
        $this->assertArrayNotHasKey('Authorization', $put['headers']);
        $this->assertArrayHasKey('body_file', $put['options']);

        // 4. verify until ready (pending → ready)
        $this->assertCount(2, $http->to('#/media/med_1/verify$#', 'POST'));
        $this->assertSame([SocialApiAiPublisher::VERIFY_PAUSE_SECS], $this->slept);

        $body = self::body($http);
        // social-api.ai needs `source` on each media item (publishing.media.missing_source otherwise).
        $this->assertSame([['source_type' => 'media_id', 'source' => 'med_1', 'media_id' => 'med_1']], $body['media']);
        $this->assertSame('GST in 60 seconds #Shorts', $body['title']);
        $this->assertSame('Description', $body['text']);
        $this->assertSame([['account_id' => 'acc_yt', 'visibility' => 'public']], $body['targets'], 'social-api.ai defaults YouTube to private');
    }

    public function testRejectedMediaStopsBeforeAnyPost(): void
    {
        $http = $this->withMedia($this->http(), [['status' => 'failed']]);
        try {
            $this->publisher($http)->publish(self::message('x', [['type' => 'video', 'url' => self::MP4]]), 'youtube');
            $this->fail('rejected media must not post');
        } catch (SocialApiException $e) {
            $this->assertSame(SocialApiException::REJECTED, $e->kind);
        }
        $this->assertSame([], $http->to('#/v1/posts$#'));
    }

    public function testAnExpiredSourceIsAMediaSourceError(): void
    {
        $http = $this->http()->on('GET', '#shotstack#', new HttpResponse(403, '<Error>AccessDenied</Error>'));
        try {
            $this->publisher($http)->publish(self::message('x', [['type' => 'video', 'url' => self::MP4]]), 'youtube');
            $this->fail('expected a media error');
        } catch (SocialApiException $e) {
            $this->assertSame(SocialApiException::MEDIA_SOURCE, $e->kind);
            $this->assertStringContainsString('HTTP 403', $e->getMessage());
        }
    }

    public function testYouTubeWithoutAVideoIsRefusedBeforeCallingTheApi(): void
    {
        $http = $this->http();
        $this->expectException(SocialApiException::class);
        $this->expectExceptionMessage('YouTube needs a video');
        try {
            $this->publisher($http)->publish(self::message('text only'), 'youtube');
        } finally {
            $this->assertSame([], $http->to('#/posts|/media#'));
        }
    }

    // ── create post: status handling ───────────────────────────────────────

    public function testPublishNowIsAlwaysSentUnlessARealFutureTimeIsScheduled(): void
    {
        $http = $this->http()->on('POST', '#/v1/posts$#', FakeHttpTransport::json(201, ['id' => 'p']));
        $pub  = $this->publisher($http);

        $pub->publish(self::message(), 'linkedin');
        $b = self::body($http);
        $this->assertTrue($b['publish_now']);
        $this->assertArrayNotHasKey('scheduled_at', $b);

        $pub->publish(self::message('past', [], ['scheduled_at' => gmdate('Y-m-d H:i:s', time() - 3600)]), 'linkedin');
        $this->assertTrue(self::body($http)['publish_now'], 'a past schedule would be a 400 (or a draft) — publish now instead');

        $future = time() + 7200;
        $pub->publish(self::message('later', [], ['scheduled_at' => gmdate('Y-m-d H:i:s', $future)]), 'linkedin');
        $b = self::body($http);
        $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', $future), $b['scheduled_at']);
        $this->assertArrayNotHasKey('publish_now', $b);

        foreach ($http->to('#/v1/posts$#', 'POST') as $r) {
            $sent = json_decode((string) $r['body'], true);
            $this->assertTrue(isset($sent['publish_now']) || isset($sent['scheduled_at']), 'never a draft');
        }
    }

    public function testPlatformDataIsTheOperatorJsonMergedVerbatim(): void
    {
        $http = $this->http()->on('POST', '#/v1/posts$#', FakeHttpTransport::json(201, ['id' => 'p']));
        $pub  = $this->publisher($http);

        $pub->publish(self::message(), 'linkedin');
        $this->assertArrayNotHasKey('platform_data', self::body($http), 'no platform keys are invented');

        $this->setVideoEnv(['SOCIALAPI_PLATFORM_DATA_LINKEDIN' => '{"linkedin":{"visibility":"PUBLIC","nested":{"a":1}}}', 'SOCIALAPI_PLATFORM_DATA_INSTAGRAM' => '{"instagram":{"type":"reel"}}']);
        $pub->publish(self::message('m', [], ['platform_data' => ['linkedin' => ['nested' => ['b' => 2]]]]), 'linkedin');
        $this->assertSame(['linkedin' => ['visibility' => 'PUBLIC', 'nested' => ['a' => 1, 'b' => 2]]], self::body($http)['platform_data'], 'only the target platform\'s object, deep-merged');

        $this->setVideoEnv(['SOCIALAPI_PLATFORM_DATA_LINKEDIN' => '[1,2]']);
        $pub->publish(self::message(), 'linkedin');
        $this->assertArrayNotHasKey('platform_data', self::body($http), 'a stored value that is not an object is ignored, not sent');
    }

    public function testYouTubeVisibilityGoesOnTheTargetAndDefaultsToPublic(): void
    {
        // social-api.ai's own dashboard sends {targets:[{account_id, visibility:"public"}]} and
        // defaults to private, so Reach always sends a visibility for YouTube targets.
        $pub = $this->publisher($this->http());

        $b = $pub->postBody(self::message('v'), 'youtube', 'acc_yt', ['med_1']);
        $this->assertSame([['account_id' => 'acc_yt', 'visibility' => 'public']], $b['targets']);
        $this->assertArrayNotHasKey('platform_data', $b);

        $this->setVideoEnv(['SOCIALAPI_YOUTUBE_VISIBILITY' => 'unlisted']);
        $this->assertSame('unlisted', $pub->postBody(self::message('v'), 'youtube', 'acc_yt', ['med_1'])['targets'][0]['visibility']);

        $this->setVideoEnv(['SOCIALAPI_YOUTUBE_VISIBILITY' => 'everyone']);
        $this->assertSame('public', $pub->postBody(self::message('v'), 'youtube', 'acc_yt', ['med_1'])['targets'][0]['visibility'], 'an invalid value falls back to public');

        $this->assertSame([['account_id' => 'acc_li']], $pub->postBody(self::message(), 'linkedin', 'acc_li', [])['targets'], 'no visibility invented for other networks');
    }

    public function testPerTargetOptionsAreMergedButCannotChangeTheAccount(): void
    {
        $pub = $this->publisher($this->http());
        $this->setVideoEnv(['SOCIALAPI_TARGET_OPTIONS_YOUTUBE' => '{"category_id":"28","made_for_kids":false,"account_id":"acc_evil","visibility":"private"}']);

        $t = $pub->postBody(self::message('v'), 'youtube', 'acc_yt', ['med_1'])['targets'][0];
        $this->assertSame('acc_yt', $t['account_id'], 'the resolved account always wins');
        $this->assertSame('28', $t['category_id']);
        $this->assertFalse($t['made_for_kids']);
        $this->assertSame('public', $t['visibility'], 'the visibility setting wins over target options');
    }

    public function testHashtagsAreAppendedOnceAndTheIdempotencyKeyIsSent(): void
    {
        $http = $this->http()->on('POST', '#/v1/posts$#', FakeHttpTransport::json(201, ['id' => 'p']));
        $this->publisher($http)->publish(self::message('Close your books faster #GST', [], ['hashtags' => '["#GST","Accounting"]']), 'linkedin');
        $this->assertSame("Close your books faster #GST\n\n#Accounting", self::body($http)['text']);
        $post = $http->to('#/v1/posts$#', 'POST')[0];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $post['headers']['Idempotency-Key']);
        $this->assertSame('application/json', $post['headers']['Content-Type']);
    }

    public function testA207WithOurTargetFailedIsRecordedWithTheErrorCode(): void
    {
        $http = $this->http()->on('POST', '#/v1/posts$#', FakeHttpTransport::json(207, ['id' => 'p2', 'status' => 'partial', 'results' => [
            ['account_id' => 'acc_li', 'status' => 'failed', 'error' => ['code' => 'publishing.delivery.timeout', 'message' => 'LinkedIn did not answer']],
            ['account_id' => 'acc_other', 'status' => 'published'],
        ]]));
        try {
            $this->publisher($http)->publish(self::message(), 'linkedin');
            $this->fail('a failed target is not submitted');
        } catch (SocialApiException $e) {
            $this->assertSame(SocialApiException::REJECTED, $e->kind);
            $this->assertSame('publishing.delivery.timeout', $e->errorCode);
            $this->assertStringContainsString('LinkedIn did not answer', $e->getMessage());
            $this->assertSame(422, $e->getCode());
        }
    }

    public function testA207WithOurTargetPendingIsSubmitted(): void
    {
        $http = $this->http()->on('POST', '#/v1/posts$#', FakeHttpTransport::json(207, ['id' => 'p3', 'targets' => [['account_id' => 'acc_li', 'status' => 'queued'], ['account_id' => 'x', 'status' => 'failed']]]));
        $this->assertTrue($this->publisher($http)->publish(self::message(), 'linkedin')->isSubmitted());
    }

    public function testA422IsRejectedWithEveryTargetCode(): void
    {
        $http = $this->http()->on('POST', '#/v1/posts$#', FakeHttpTransport::json(422, ['status' => 'failed', 'targets' => [
            ['account_id' => 'acc_li', 'platform' => 'linkedin', 'status' => 'failed', 'error' => ['code' => 'publishing.media.unsupported', 'message' => 'format']],
        ]]));
        try {
            $this->publisher($http)->publish(self::message(), 'linkedin');
            $this->fail();
        } catch (SocialApiException $e) {
            $this->assertSame(SocialApiException::REJECTED, $e->kind);
            $this->assertSame('publishing.media.unsupported', $e->errorCode);
            $this->assertStringContainsString('linkedin: publishing.media.unsupported — format', $e->getMessage());
            $this->assertSame('failed', $e->targets[0]['status']);
        }
    }

    public function testA400ValidationErrorNamesTheField(): void
    {
        $http = $this->http()->on('POST', '#/v1/posts$#', FakeHttpTransport::json(400, ['error' => ['code' => 'validation.field_invalid', 'message' => 'Invalid field', 'details' => [['field' => 'scheduled_at', 'message' => 'must be in the future']]]]));
        try {
            $this->publisher($http)->publish(self::message(), 'linkedin');
            $this->fail();
        } catch (SocialApiException $e) {
            $this->assertSame(SocialApiException::VALIDATION, $e->kind);
            $this->assertSame('validation.field_invalid', $e->errorCode);
            $this->assertStringContainsString('scheduled_at must be in the future', $e->getMessage());
            $this->assertSame('permanent', SocialPublisherService::failureClass($e));
        }
    }

    public function testA409DuplicateIsAConflictOrFollowsTheExistingPost(): void
    {
        $http = $this->http()->on('POST', '#/v1/posts$#', FakeHttpTransport::json(409, ['error' => ['code' => 'resource.conflict', 'message' => 'Duplicate post']]), 1)
            ->on('POST', '#/v1/posts$#', FakeHttpTransport::json(409, ['error' => ['code' => 'resource.conflict'], 'post_id' => 'post_existing']));
        try {
            $this->publisher($http)->publish(self::message(), 'linkedin');
            $this->fail();
        } catch (SocialApiException $e) {
            $this->assertSame(SocialApiException::CONFLICT, $e->kind);
            $this->assertSame('resource.conflict', $e->errorCode);
            $this->assertStringContainsString('same title and text from the last 24 hours', $e->getMessage());
            $this->assertSame('rejected', SocialPublisherService::failureClass($e));
        }

        $receipt = $this->publisher($http)->publish(self::message(), 'linkedin');
        $this->assertSame('post_existing', $receipt->providerMessageId);
        $this->assertTrue($receipt->meta['duplicate_of_existing']);
    }

    public function testA5xxOnCreateIsNeverRetriedAndSaysTheOutcomeIsUnknown(): void
    {
        $http = $this->http()->on('POST', '#/v1/posts$#', FakeHttpTransport::json(503, ['error' => 'unavailable']));
        try {
            $this->publisher($http)->publish(self::message(), 'linkedin');
            $this->fail();
        } catch (SocialApiException $e) {
            $this->assertSame(SocialApiException::TRANSIENT, $e->kind);
            $this->assertStringContainsString('Outcome unknown', $e->getMessage());
            $this->assertSame('transient', SocialPublisherService::failureClass($e));
        }
        $this->assertCount(1, $http->to('#/v1/posts$#', 'POST'), 'POST /posts is never retried blindly');
        $this->assertSame([], $this->slept);
    }

    public function testA401OnCreateIsNotConfiguredAnd429IsRateLimited(): void
    {
        $http = $this->http()->on('POST', '#/v1/posts$#', FakeHttpTransport::json(401, ['error' => ['message' => 'nope']]), 1)
            ->on('POST', '#/v1/posts$#', FakeHttpTransport::json(429, ['error' => ['code' => 'rate_limited']]));
        try {
            $this->publisher($http)->publish(self::message(), 'linkedin');
            $this->fail();
        } catch (ProviderNotConfiguredException $e) {
            $this->assertStringContainsString('rejected the API key (HTTP 401)', $e->getMessage());
            $this->assertSame('not_configured', SocialPublisherService::failureClass($e));
        }
        try {
            $this->publisher($http)->publish(self::message(), 'linkedin');
            $this->fail();
        } catch (SocialApiException $e) {
            $this->assertSame(429, $e->getCode());
            $this->assertSame('rate_limit', SocialPublisherService::failureClass($e));
        }
    }

    public function testA201WithoutAPostIdIsNotTreatedAsTracked(): void
    {
        $http = $this->http()->on('POST', '#/v1/posts$#', FakeHttpTransport::json(201, ['ok' => true]));
        $this->expectException(SocialApiException::class);
        $this->expectExceptionMessage('returned no post id Reach recognises');
        $this->publisher($http)->publish(self::message(), 'linkedin');
    }

    // ── status ─────────────────────────────────────────────────────────────

    public function testStatusReadsTheTargetAndItsUrl(): void
    {
        $http = $this->http()
            ->on('GET', '#/posts/post_a$#', FakeHttpTransport::json(200, ['id' => 'post_a', 'status' => 'published', 'targets' => [
                ['account_id' => 'acc_yt', 'status' => 'published', 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'platform_post_id' => 'dQw4w9WgXcQ'],
            ]]))
            ->on('GET', '#/posts/post_b$#', FakeHttpTransport::json(200, ['data' => ['id' => 'post_b', 'status' => 'failed', 'results' => [
                ['account_id' => 'acc_yt', 'status' => 'error', 'error_code' => 'publishing.delivery.timeout', 'error_message' => 'YouTube timed out'],
            ]]]))
            ->on('GET', '#/posts/post_c$#', FakeHttpTransport::json(200, ['id' => 'post_c', 'status' => 'processing']))
            ->on('GET', '#/posts/post_d$#', FakeHttpTransport::json(200, ['id' => 'post_d', 'status' => 'partial']));
        $pub = $this->publisher($http);

        $a = $pub->statusFor('post_a', 'acc_yt');
        $this->assertSame('published', $a->normalisedStatus);
        $this->assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $a->raw['target']['url']);
        $this->assertSame('dQw4w9WgXcQ', $a->raw['target']['external_id']);

        $b = $pub->statusFor('post_b', 'acc_yt');
        $this->assertSame('failed', $b->normalisedStatus);
        $this->assertSame('publishing.delivery.timeout', $b->failureClass);
        $this->assertSame('YouTube timed out', $b->raw['target']['error_message']);

        $this->assertSame('pending', $pub->statusFor('post_c', null)->normalisedStatus);
        $this->assertSame('pending', $pub->getStatus('post_d')->normalisedStatus, 'a partial post says nothing about one target');
        $this->assertSame(SocialApiPostResult::UNKNOWN, SocialApiPostResult::normalizeStatus('frobnicated'));
    }

    // ── secrets ────────────────────────────────────────────────────────────

    public function testSecretsAndSignedUrlsAreRedactedFromStoredResponsesAndErrors(): void
    {
        $http = $this->withMedia($this->http())
            ->on('POST', '#/v1/posts$#', FakeHttpTransport::json(201, [
                'id' => 'post_s', 'echo' => 'Authorization: Bearer ' . self::KEY, 'api_key' => self::KEY,
                'media' => [['media_id' => 'med_1', 'upload_url' => self::PUT]], 'note' => 'key was ' . self::KEY,
            ]));
        $receipt = $this->publisher($http)->publish(self::message('v', [['type' => 'video', 'url' => self::MP4]]), 'youtube');
        $stored  = (string) $receipt->rawResponse . json_encode($receipt->meta);
        $this->assertStringNotContainsString(self::KEY, $stored);
        $this->assertStringNotContainsString('sapi_key_', $stored);
        $this->assertStringNotContainsString('deadbeefsig', $stored);
        $this->assertStringContainsString('https://uploads.social-api.example/m/med_1?[REDACTED]', $stored, 'host and path kept for diagnosis');
        $this->assertStringContainsString('post_s', $stored, 'ids are not redacted');
        $this->assertStringContainsString('med_1', $stored);

        $leaky = $this->http()->on('POST', '#/v1/posts$#', FakeHttpTransport::json(400, ['error' => ['code' => 'validation', 'message' => 'bad header Bearer ' . self::KEY]]));
        try {
            $this->publisher($leaky)->publish(self::message(), 'linkedin');
            $this->fail();
        } catch (SocialApiException $e) {
            $this->assertStringNotContainsString(self::KEY, $e->getMessage() . json_encode($e->response));
        }

        $r = new SocialApiRedactor(['my-secret-value']);
        $this->assertSame(['token' => '[REDACTED]', 'idempotency_key' => 'k', 'media_id' => 'a8f3c9d2e1b7a6f5c4d3e2f1a0b9c8d7e6f5'], $r->value(['token' => 'abc', 'idempotency_key' => 'k', 'media_id' => 'a8f3c9d2e1b7a6f5c4d3e2f1a0b9c8d7e6f5']));
        $this->assertSame('x [REDACTED] y', $r->string('x my-secret-value y'));
        $this->assertSame('https://ok.example/a?page=2', $r->string('https://ok.example/a?page=2'), 'an unsigned URL is kept whole');
    }

    // ── capabilities / readiness ───────────────────────────────────────────

    public function testCapabilitiesListOnlyChannelsWhoseAccountResolves(): void
    {
        $caps = $this->publisher($this->http())->getCapabilities();
        $this->assertSame(['linkedin', 'instagram', 'youtube'], $caps['platforms']);
        $this->assertArrayHasKey('facebook', $caps['unavailable']);
        $this->assertArrayHasKey('twitter', $caps['unavailable']);
        $this->assertFalse($caps['webhooks']);
    }
}
