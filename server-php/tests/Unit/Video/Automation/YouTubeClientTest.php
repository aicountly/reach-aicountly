<?php

declare(strict_types=1);

namespace Tests\Unit\Video\Automation;

use App\Libraries\Video\Automation\Http\HttpResponse;
use App\Libraries\Video\Automation\VideoProviderException;
use App\Libraries\Video\Automation\YouTube\YouTubeDataClient;
use App\Libraries\Video\Automation\YouTube\YouTubeMetadata;
use App\Libraries\Video\Automation\YouTube\YouTubeOAuth;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Video\FakeHttpTransport;
use Tests\Support\Video\VideoEnv;

/**
 * @covers \App\Libraries\Video\Automation\YouTube\YouTubeOAuth
 * @covers \App\Libraries\Video\Automation\YouTube\YouTubeDataClient
 * @covers \App\Libraries\Video\Automation\YouTube\YouTubeMetadata
 */
final class YouTubeClientTest extends CIUnitTestCase
{
    use VideoEnv;

    private const SESSION = 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&upload_id=XYZ';
    private const SOURCE  = 'https://cdn.shotstack.io/au/v1/abc/render.mp4';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setVideoEnv([
            'YOUTUBE_CLIENT_ID'     => 'client-123.apps.googleusercontent.com',
            'YOUTUBE_CLIENT_SECRET' => 'gocspx-secret',
            'YOUTUBE_REFRESH_TOKEN' => '1//refresh-token',
            'REACH_APP_URL'         => 'https://reach.aicountly.org',
        ]);
    }

    protected function tearDown(): void
    {
        $this->restoreVideoEnv();
        parent::tearDown();
    }

    private function http(): FakeHttpTransport
    {
        return (new FakeHttpTransport())->on('POST', '#^https://oauth2\.googleapis\.com/token$#', FakeHttpTransport::json(200, ['access_token' => 'ya29.access', 'expires_in' => 3599, 'token_type' => 'Bearer']));
    }

    private function client(FakeHttpTransport $http, int $chunk = 10): YouTubeDataClient
    {
        return new YouTubeDataClient($http, new YouTubeOAuth($http), $chunk);
    }

    // ── OAuth ───────────────────────────────────────────────────────────────

    public function testConsentUrlAsksForOfflineAccessWithTheRegisteredRedirect(): void
    {
        $url = (new YouTubeOAuth(new FakeHttpTransport()))->consentUrl('state-nonce_1');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('client-123.apps.googleusercontent.com', $q['client_id']);
        $this->assertSame('https://reach.aicountly.org/api/v1/public/video/youtube/oauth-callback', $q['redirect_uri']);
        $this->assertSame('code', $q['response_type']);
        $this->assertSame('offline', $q['access_type']);
        $this->assertSame('consent', $q['prompt']);
        $this->assertSame('state-nonce_1', $q['state']);
        $this->assertStringContainsString('https://www.googleapis.com/auth/youtube.upload', $q['scope']);
        $this->assertStringContainsString('https://www.googleapis.com/auth/youtube.force-ssl', $q['scope']);
    }

    public function testCodeExchangeReturnsTheRefreshToken(): void
    {
        $http = (new FakeHttpTransport())->on('POST', '#oauth2\.googleapis\.com/token$#', FakeHttpTransport::json(200, [
            'access_token' => 'ya29.a', 'refresh_token' => '1//new-refresh', 'expires_in' => 3599, 'scope' => implode(' ', YouTubeOAuth::SCOPES),
        ]));
        $tokens = (new YouTubeOAuth($http))->exchangeCode('4/0Ab-code');
        $this->assertSame('1//new-refresh', $tokens['refresh_token']);
        parse_str((string) $http->requests[0]['body'], $form);
        $this->assertSame('authorization_code', $form['grant_type']);
        $this->assertSame('4/0Ab-code', $form['code']);
        $this->assertSame('gocspx-secret', $form['client_secret']);
        $this->assertSame(YouTubeOAuth::redirectUri(), $form['redirect_uri']);
    }

    public function testCodeExchangeWithoutARefreshTokenOrScopesIsRefused(): void
    {
        $http = (new FakeHttpTransport())->on('POST', '#token$#', FakeHttpTransport::json(200, ['access_token' => 'ya29.a', 'expires_in' => 3599]));
        try {
            (new YouTubeOAuth($http))->exchangeCode('code');
            $this->fail('expected exception');
        } catch (VideoProviderException $e) {
            $this->assertStringContainsString('refresh token', $e->getMessage());
        }

        $http = (new FakeHttpTransport())->on('POST', '#token$#', FakeHttpTransport::json(200, ['access_token' => 'a', 'refresh_token' => 'r', 'scope' => 'https://www.googleapis.com/auth/youtube.upload']));
        $this->expectException(VideoProviderException::class);
        (new YouTubeOAuth($http))->exchangeCode('code');
    }

    public function testRevokedRefreshTokenBlocksAsAuth(): void
    {
        $http = (new FakeHttpTransport())->on('POST', '#token$#', FakeHttpTransport::json(400, ['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.']));
        try {
            (new YouTubeOAuth($http))->accessToken();
            $this->fail('expected exception');
        } catch (VideoProviderException $e) {
            $this->assertSame(VideoProviderException::AUTH, $e->kind);
            $this->assertTrue($e->blocksPipeline());
        }
    }

    public function testNoChannelConnectedIsNotConfigured(): void
    {
        $this->setVideoEnv(['YOUTUBE_REFRESH_TOKEN' => null]);
        try {
            (new YouTubeOAuth(new FakeHttpTransport()))->accessToken();
            $this->fail('expected exception');
        } catch (VideoProviderException $e) {
            $this->assertSame(VideoProviderException::NOT_CONFIGURED, $e->kind);
        }
    }

    public function testAccessTokenIsReusedWithinItsLifetime(): void
    {
        $http  = $this->http();
        $oauth = new YouTubeOAuth($http);
        $this->assertSame('ya29.access', $oauth->accessToken());
        $this->assertSame('ya29.access', $oauth->accessToken());
        $this->assertCount(1, $http->to('#token$#'));
        parse_str((string) $http->requests[0]['body'], $form);
        $this->assertSame('refresh_token', $form['grant_type']);
        $this->assertSame('1//refresh-token', $form['refresh_token']);
    }

    // ── resumable upload ────────────────────────────────────────────────────

    public function testStartUploadOpensAResumableSession(): void
    {
        $http = $this->http()->on('POST', '#^https://www\.googleapis\.com/upload/youtube/v3/videos\?uploadType=resumable&part=snippet,status#', new HttpResponse(200, '', ['location' => self::SESSION]));
        $resource = ['snippet' => ['title' => 'T'], 'status' => ['privacyStatus' => 'public']];

        $this->assertSame(self::SESSION, $this->client($http)->startUpload($resource, 25));
        $req = $http->to('#upload/youtube/v3/videos#')[0];
        $this->assertSame('Bearer ya29.access', $req['headers']['Authorization']);
        $this->assertSame('25', $req['headers']['X-Upload-Content-Length']);
        $this->assertSame('video/mp4', $req['headers']['X-Upload-Content-Type']);
        $this->assertSame($resource, json_decode((string) $req['body'], true));
    }

    public function testUploadStreamsRangesFromTheRenderInChunks(): void
    {
        $video  = 'ABCDEFGHIJKLMNOPQRSTUVWXY'; // 25 bytes, 10-byte chunks
        $google = 0;
        $http   = $this->http()
            ->on('GET', '#^' . preg_quote(self::SOURCE, '#') . '$#', static function ($m, $u, array $h) use ($video) {
                preg_match('/bytes=(\d+)-(\d+)/', $h['Range'], $r);
                return new HttpResponse(206, substr($video, (int) $r[1], (int) $r[2] - (int) $r[1] + 1));
            })
            ->on('PUT', '#upload_id=XYZ#', static function ($m, $u, array $h, ?string $body) use (&$google) {
                if ($h['Content-Range'] === 'bytes */25') {
                    return new HttpResponse(308, '');
                }
                $google++;
                return match ($h['Content-Range']) {
                    'bytes 0-9/25'   => new HttpResponse(308, '', ['range' => 'bytes=0-9']),
                    'bytes 10-19/25' => new HttpResponse(308, '', ['range' => 'bytes=0-19']),
                    'bytes 20-24/25' => FakeHttpTransport::json(201, ['id' => 'dQw4w9WgXcQ', 'status' => ['privacyStatus' => 'private', 'uploadStatus' => 'uploaded']]),
                };
            });

        $p = $this->client($http)->continueUpload(self::SESSION, self::SOURCE, 25, 0, time() + 60);

        $this->assertTrue($p->complete);
        $this->assertSame('dQw4w9WgXcQ', $p->videoId);
        $this->assertSame('private', $p->video['status']['privacyStatus']);
        $this->assertSame(3, $google);
        $puts = array_values(array_filter($http->to('#upload_id=XYZ#', 'PUT'), static fn ($r) => $r['body'] !== ''));
        $this->assertSame(['ABCDEFGHIJ', 'KLMNOPQRST', 'UVWXY'], array_column($puts, 'body'));
        $this->assertSame('10', $puts[0]['headers']['Content-Length']);
        $this->assertSame(['bytes=0-9', 'bytes=10-19', 'bytes=20-24'], array_map(static fn ($r) => $r['headers']['Range'], $http->to('#shotstack#', 'GET')));
    }

    public function testUploadResumesFromWhatGoogleHoldsAndStopsAtTheDeadline(): void
    {
        $video = str_repeat('x', 40);
        $http  = $this->http()
            ->on('GET', '#shotstack#', static function ($m, $u, array $h) use ($video) {
                preg_match('/bytes=(\d+)-(\d+)/', $h['Range'], $r);
                return new HttpResponse(206, substr($video, (int) $r[1], (int) $r[2] - (int) $r[1] + 1));
            })
            ->on('PUT', '#upload_id=XYZ#', static function ($m, $u, array $h) {
                if ($h['Content-Range'] === 'bytes */40') {
                    return new HttpResponse(308, '', ['range' => 'bytes=0-19']); // a previous step got 20 bytes in
                }
                preg_match('#bytes (\d+)-(\d+)/40#', $h['Content-Range'], $r);
                return new HttpResponse(308, '', ['range' => 'bytes=0-' . $r[2]]);
            });

        // Deadline already passed: exactly one chunk is sent, then the step yields.
        $p = $this->client($http)->continueUpload(self::SESSION, self::SOURCE, 40, 0, time() - 1);
        $this->assertFalse($p->complete);
        $this->assertSame(30, $p->offset);
        $this->assertSame(['bytes */40', 'bytes 20-29/40'], array_map(static fn ($r) => $r['headers']['Content-Range'], $http->to('#upload_id=XYZ#', 'PUT')));
    }

    public function testExpiredSessionAndExpiredRenderAreDistinguished(): void
    {
        $http = $this->http()->on('PUT', '#upload_id=XYZ#', new HttpResponse(404, 'Not Found'));
        try {
            $this->client($http)->continueUpload(self::SESSION, self::SOURCE, 25, 0, time() + 60);
            $this->fail('expected exception');
        } catch (VideoProviderException $e) {
            $this->assertSame(VideoProviderException::SESSION_EXPIRED, $e->kind);
        }

        $http = $this->http()
            ->on('PUT', '#upload_id=XYZ#', new HttpResponse(308, ''))
            ->on('GET', '#shotstack#', new HttpResponse(403, '<Error>AccessDenied</Error>'));
        try {
            $this->client($http)->continueUpload(self::SESSION, self::SOURCE, 25, 0, time() + 60);
            $this->fail('expected exception');
        } catch (VideoProviderException $e) {
            $this->assertSame(VideoProviderException::SOURCE_EXPIRED, $e->kind);
        }
    }

    public function testQuotaExceededIsQuotaNotAuth(): void
    {
        $http = $this->http()->on('POST', '#upload/youtube/v3/videos#', FakeHttpTransport::json(403, ['error' => ['code' => 403, 'message' => 'The request cannot be completed because you have exceeded your quota.', 'errors' => [['reason' => 'quotaExceeded']]]]));
        try {
            $this->client($http)->startUpload(['snippet' => []], 100);
            $this->fail('expected exception');
        } catch (VideoProviderException $e) {
            $this->assertSame(VideoProviderException::QUOTA, $e->kind);
        }
    }

    public function testSourceSizeComesFromHead(): void
    {
        $http = $this->http()->on('HEAD', '#shotstack#', new HttpResponse(200, '', ['content-length' => '73400320']));
        $this->assertSame(73400320, $this->client($http)->sourceSize(self::SOURCE));
    }

    public function testThumbnailCaptionsAndChannel(): void
    {
        $http = $this->http()
            ->on('POST', '#upload/youtube/v3/thumbnails/set\?videoId=vid123&uploadType=media$#', FakeHttpTransport::json(200, ['items' => []]))
            ->on('POST', '#upload/youtube/v3/captions\?part=snippet&uploadType=multipart$#', FakeHttpTransport::json(200, ['id' => 'cap-1']))
            ->on('GET', '#youtube/v3/channels\?part=snippet&mine=true$#', FakeHttpTransport::json(200, ['items' => [['id' => 'UC123', 'snippet' => ['title' => 'Aicountly']]]]));
        $client = $this->client($http);

        $client->setThumbnail('vid123', 'JPEGBYTES', 'image/jpeg');
        $thumb = $http->to('#thumbnails/set#')[0];
        $this->assertSame('JPEGBYTES', $thumb['body']);
        $this->assertSame('image/jpeg', $thumb['headers']['Content-Type']);

        $this->assertSame('cap-1', $client->insertCaption('vid123', 'en', 'English', "1\n00:00:00,000 --> 00:00:01,000\nHi\n"));
        $cap = $http->to('#captions#')[0];
        $this->assertMatchesRegularExpression('#^multipart/related; boundary=reach[0-9a-f]+$#', $cap['headers']['Content-Type']);
        $this->assertStringContainsString('"videoId":"vid123"', (string) $cap['body']);
        $this->assertStringContainsString("00:00:00,000 --> 00:00:01,000\nHi", (string) $cap['body']);

        $this->assertSame(['id' => 'UC123', 'title' => 'Aicountly'], $client->channel());
    }

    // ── metadata ────────────────────────────────────────────────────────────

    public function testMetadataDisclosesTheAiVoiceAndMarksShorts(): void
    {
        $item = ['script_json' => [
            'format' => 'short', 'title' => 'TDS due on the 7th', 'description' => 'What is due and who pays it.', 'tags' => ['TDS', 'India'],
            'chapters' => [], 'cta_text' => 'Try Aicountly free at aicountly.com',
        ]];
        $r = YouTubeMetadata::resource($item, ['duration' => 45]);
        $this->assertSame('TDS due on the 7th #Shorts', $r['snippet']['title']);
        $this->assertStringContainsString('#Shorts', $r['snippet']['description']);
        $this->assertStringContainsString('Narrated with an AI voice', $r['snippet']['description']);
        $this->assertStringContainsString('https://aicountly.com', $r['snippet']['description']);
        $this->assertSame(['TDS', 'India'], $r['snippet']['tags']);
        $this->assertSame('28', $r['snippet']['categoryId']);
        $this->assertSame('public', $r['status']['privacyStatus'], 'registry default');
        $this->assertTrue($r['status']['containsSyntheticMedia']);
        $this->assertFalse($r['status']['selfDeclaredMadeForKids']);
        $this->assertSame('https://www.youtube.com/shorts/abc', YouTubeMetadata::watchUrl('abc', true));
        $this->assertSame('https://www.youtube.com/watch?v=abc', YouTubeMetadata::watchUrl('abc', false));
    }

    public function testVlogDescriptionCarriesChaptersAndFitsTheByteLimit(): void
    {
        $item = ['script_json' => [
            'format' => 'vlog', 'title' => 'GST basics', 'description' => str_repeat('ग', 3000), 'tags' => [],
            'chapters' => [['title' => 'Start', 'scene_order' => 1], ['title' => 'GSTR-1', 'scene_order' => 2], ['title' => 'GSTR-3B', 'scene_order' => 3]],
            'cta_text' => 'Visit aicountly.com',
        ]];
        $r = YouTubeMetadata::resource($item, ['duration' => 200, 'scene_starts' => ['1' => 3.5, '2' => 60, '3' => 120]]);
        $this->assertLessThanOrEqual(YouTubeMetadata::DESCRIPTION_MAX_BYTES, strlen($r['snippet']['description']));
        $this->assertTrue(mb_check_encoding($r['snippet']['description'], 'UTF-8'));

        $item['script_json']['description'] = 'Short.';
        $r = YouTubeMetadata::resource($item, ['duration' => 200, 'scene_starts' => ['1' => 3.5, '2' => 60, '3' => 120]]);
        $this->assertStringContainsString("Chapters\n0:00 Start\n1:00 GSTR-1\n2:00 GSTR-3B", $r['snippet']['description']);
        $this->assertSame('GST basics', $r['snippet']['title']);
    }
}
