<?php

declare(strict_types=1);

namespace Tests\Unit\Video\Automation;

use App\Libraries\Video\Automation\Http\HttpResponse;
use App\Libraries\Video\Automation\Render\ShotstackClient;
use App\Libraries\Video\Automation\VideoProviderException;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Video\FakeHttpTransport;
use Tests\Support\Video\VideoEnv;

/**
 * @covers \App\Libraries\Video\Automation\Render\ShotstackClient
 * @covers \App\Libraries\Video\Automation\VideoProviderException
 */
final class ShotstackClientTest extends CIUnitTestCase
{
    use VideoEnv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setVideoEnv(['SHOTSTACK_API_KEY' => 'ss-test-key', 'SHOTSTACK_ENV' => null, 'SHOTSTACK_BASE_URL' => null]);
    }

    protected function tearDown(): void
    {
        $this->restoreVideoEnv();
        parent::tearDown();
    }

    public function testSubmitPostsTheEditToTheStageRenderEndpointWithTheKeyHeader(): void
    {
        $http = (new FakeHttpTransport())->on('POST', '#^https://api\.shotstack\.io/edit/stage/render$#', FakeHttpTransport::json(201, [
            'success' => true, 'message' => 'Created', 'response' => ['message' => 'Render Successfully Queued', 'id' => 'd2b46ed6-998a-4d6b-9d91-b8cf0193a655'],
        ]));
        $edit = ['timeline' => ['tracks' => []], 'output' => ['format' => 'mp4']];

        $id = (new ShotstackClient($http))->submit($edit);

        $this->assertSame('d2b46ed6-998a-4d6b-9d91-b8cf0193a655', $id);
        $req = $http->requests[0];
        $this->assertSame('ss-test-key', $req['headers']['x-api-key']);
        $this->assertSame('application/json', $req['headers']['Content-Type']);
        $this->assertSame($edit, json_decode((string) $req['body'], true));
    }

    public function testProductionEnvironmentUsesV1(): void
    {
        $this->setVideoEnv(['SHOTSTACK_ENV' => 'v1']);
        $http = (new FakeHttpTransport())->on('POST', '#/edit/v1/render$#', FakeHttpTransport::json(201, ['response' => ['id' => 'abcdef12-0000-0000-0000-000000000000']]));
        $this->assertSame('abcdef12-0000-0000-0000-000000000000', (new ShotstackClient($http))->submit(['timeline' => []]));
        $this->assertSame('shotstack:v1', (new ShotstackClient($http))->name());
    }

    public function testUnknownEnvironmentFallsBackToStage(): void
    {
        $this->setVideoEnv(['SHOTSTACK_ENV' => 'prod']);
        $this->assertSame('stage', ShotstackClient::environment());
    }

    public function testStatusDoneCarriesTheOutputUrls(): void
    {
        $http = (new FakeHttpTransport())->on('GET', '#/edit/stage/render/abcdef12-aaaa\?data=false$#', FakeHttpTransport::json(200, ['success' => true, 'response' => [
            'id' => 'abcdef12-aaaa', 'status' => 'done', 'url' => 'https://cdn.shotstack.io/au/stage/x/abcdef12.mp4',
            'poster' => 'https://cdn.shotstack.io/au/stage/x/abcdef12-poster.jpg', 'thumbnail' => 'https://cdn.shotstack.io/au/stage/x/abcdef12-thumb.jpg', 'duration' => 182.4,
        ]]));
        $r = (new ShotstackClient($http))->status('abcdef12-aaaa');
        $this->assertTrue($r->done());
        $this->assertSame('https://cdn.shotstack.io/au/stage/x/abcdef12.mp4', $r->url);
        $this->assertSame('https://cdn.shotstack.io/au/stage/x/abcdef12-poster.jpg', $r->posterUrl);
        $this->assertSame(182.4, $r->durationSecs);
        $this->assertSame('ss-test-key', $http->requests[0]['headers']['x-api-key']);
    }

    public function testStatusInProgressAndFailed(): void
    {
        $http = (new FakeHttpTransport())
            ->on('GET', '#/render/aaaaaaaa-1#', FakeHttpTransport::json(200, ['response' => ['status' => 'fetching']]))
            ->on('GET', '#/render/aaaaaaaa-2#', FakeHttpTransport::json(200, ['response' => ['status' => 'queued']]))
            ->on('GET', '#/render/aaaaaaaa-3#', FakeHttpTransport::json(200, ['response' => ['status' => 'failed', 'error' => 'Asset could not be fetched']]));
        $client = new ShotstackClient($http);

        $this->assertSame('rendering', $client->status('aaaaaaaa-1')->status);
        $this->assertSame('fetching', $client->status('aaaaaaaa-1')->providerStatus);
        $this->assertSame('queued', $client->status('aaaaaaaa-2')->status);
        $failed = $client->status('aaaaaaaa-3');
        $this->assertTrue($failed->failed());
        $this->assertSame('Asset could not be fetched', $failed->error);
    }

    public function testDoneWithoutAnUrlIsNotTreatedAsDone(): void
    {
        $http = (new FakeHttpTransport())->on('GET', '#/render/#', FakeHttpTransport::json(200, ['response' => ['status' => 'done']]));
        try {
            (new ShotstackClient($http))->status('abcdef12-bbbb');
            $this->fail('expected exception');
        } catch (VideoProviderException $e) {
            $this->assertSame(VideoProviderException::TRANSIENT, $e->kind);
        }
    }

    public function testNoKeyFailsClosedWithoutCallingShotstack(): void
    {
        $this->setVideoEnv(['SHOTSTACK_API_KEY' => null]);
        $http = new FakeHttpTransport();
        try {
            (new ShotstackClient($http))->submit(['timeline' => []]);
            $this->fail('expected exception');
        } catch (VideoProviderException $e) {
            $this->assertSame(VideoProviderException::NOT_CONFIGURED, $e->kind);
            $this->assertTrue($e->blocksPipeline());
        }
        $this->assertSame([], $http->requests);
        $this->assertFalse(ShotstackClient::isConfigured());
    }

    public function testErrorsAreClassified(): void
    {
        $cases = [
            [new HttpResponse(401, '{"message":"Unauthorized"}'), VideoProviderException::AUTH],
            [new HttpResponse(429, '{"message":"slow down"}'), VideoProviderException::QUOTA],
            [new HttpResponse(400, '{"response":{"error":"Invalid timeline"}}'), VideoProviderException::PERMANENT],
            [new HttpResponse(502, ''), VideoProviderException::TRANSIENT],
            [new HttpResponse(0, '', [], 'Could not resolve host'), VideoProviderException::TRANSIENT],
        ];
        foreach ($cases as [$response, $kind]) {
            $http = (new FakeHttpTransport())->on('POST', '#/render$#', $response);
            try {
                (new ShotstackClient($http))->submit(['timeline' => []]);
                $this->fail('expected exception');
            } catch (VideoProviderException $e) {
                $this->assertSame($kind, $e->kind, $e->getMessage());
                $this->assertStringNotContainsString('ss-test-key', $e->getMessage());
            }
        }
    }
}
