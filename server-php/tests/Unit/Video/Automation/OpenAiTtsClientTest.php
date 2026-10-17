<?php

declare(strict_types=1);

namespace Tests\Unit\Video\Automation;

use App\Libraries\Video\Automation\Http\HttpResponse;
use App\Libraries\Video\Automation\Tts\Mp3Duration;
use App\Libraries\Video\Automation\Tts\OpenAiTtsClient;
use App\Libraries\Video\Automation\VideoProviderException;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Video\FakeHttpTransport;
use Tests\Support\Video\VideoEnv;

/**
 * @covers \App\Libraries\Video\Automation\Tts\OpenAiTtsClient
 * @covers \App\Libraries\Video\Automation\Tts\Mp3Duration
 */
final class OpenAiTtsClientTest extends CIUnitTestCase
{
    use VideoEnv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setVideoEnv(['AI_OPENAI_API_KEY' => 'sk-test-tts', 'AI_OPENAI_BASE_URL' => null, 'VIDEO_TTS_MODEL' => null, 'VIDEO_TTS_VOICE' => null]);
    }

    protected function tearDown(): void
    {
        $this->restoreVideoEnv();
        parent::tearDown();
    }

    public function testSpeechRequestUsesTheConfiguredModelVoiceAndKey(): void
    {
        $mp3  = Mp3Duration::silentStream(2000);
        $http = (new FakeHttpTransport())->on('POST', '#^https://api\.openai\.com/v1/audio/speech$#', new HttpResponse(200, $mp3, ['content-type' => 'audio/mpeg']));

        $bytes = (new OpenAiTtsClient($http))->synthesize('Namaste! Today we file GSTR-1.');

        $this->assertSame($mp3, $bytes);
        $req  = $http->requests[0];
        $body = json_decode((string) $req['body'], true);
        $this->assertSame('Bearer sk-test-tts', $req['headers']['Authorization']);
        $this->assertSame('gpt-4o-mini-tts', $body['model'], 'registry default');
        $this->assertSame('coral', $body['voice'], 'registry default');
        $this->assertSame('mp3', $body['response_format']);
        $this->assertSame('Namaste! Today we file GSTR-1.', $body['input']);
        $this->assertStringContainsString('Indian English', $body['instructions']);
    }

    public function testClassicTtsModelsGetNoInstructions(): void
    {
        $this->setVideoEnv(['VIDEO_TTS_MODEL' => 'tts-1', 'VIDEO_TTS_VOICE' => 'nova']);
        $body = (new OpenAiTtsClient(new FakeHttpTransport()))->requestBody('Hello');
        $this->assertSame('tts-1', $body['model']);
        $this->assertSame('nova', $body['voice']);
        $this->assertArrayNotHasKey('instructions', $body, 'tts-1 rejects instructions');
    }

    public function testFailuresAreClassifiedAndRedacted(): void
    {
        $http = (new FakeHttpTransport())->on('POST', '#/audio/speech$#', FakeHttpTransport::json(401, ['error' => ['message' => 'Incorrect API key provided: sk-test-tts']]));
        try {
            (new OpenAiTtsClient($http))->synthesize('Hello there');
            $this->fail('expected exception');
        } catch (VideoProviderException $e) {
            $this->assertSame(VideoProviderException::AUTH, $e->kind);
            $this->assertStringNotContainsString('sk-test-tts', $e->getMessage());
        }

        $http = (new FakeHttpTransport())->on('POST', '#/audio/speech$#', FakeHttpTransport::json(200, ['error' => 'not audio']));
        try {
            (new OpenAiTtsClient($http))->synthesize('Hello there');
            $this->fail('expected exception');
        } catch (VideoProviderException $e) {
            $this->assertSame(VideoProviderException::TRANSIENT, $e->kind, 'a JSON body is not audio');
        }
    }

    public function testNoKeyFailsClosed(): void
    {
        $this->setVideoEnv(['AI_OPENAI_API_KEY' => null]);
        $http = new FakeHttpTransport();
        try {
            (new OpenAiTtsClient($http))->synthesize('Hello');
            $this->fail('expected exception');
        } catch (VideoProviderException $e) {
            $this->assertSame(VideoProviderException::NOT_CONFIGURED, $e->kind);
        }
        $this->assertSame([], $http->requests);
    }

    public function testOverlongInputIsRefusedBeforeCallingOpenAi(): void
    {
        $http = new FakeHttpTransport();
        $this->expectException(VideoProviderException::class);
        (new OpenAiTtsClient($http))->synthesize(str_repeat('a', OpenAiTtsClient::MAX_INPUT_CHARS + 1));
    }

    public function testMp3DurationWalksFrames(): void
    {
        $this->assertEqualsWithDelta(3000, Mp3Duration::milliseconds(Mp3Duration::silentStream(3000)), 30);
        $this->assertEqualsWithDelta(12000, Mp3Duration::milliseconds(Mp3Duration::silentStream(12000)), 30);

        // ID3v2 tag in front (size 0x0100 = 128 bytes, synchsafe) is skipped.
        $id3 = 'ID3' . "\x04\x00\x00" . "\x00\x00\x01\x00" . str_repeat("\x00", 128);
        $this->assertEqualsWithDelta(1000, Mp3Duration::milliseconds($id3 . Mp3Duration::silentStream(1000)), 30);

        // MPEG-2 Layer III, 24 kHz, 64 kbps (what TTS services often emit): 576 samples per frame.
        $frameLen = intdiv(72 * 64000, 24000);
        $frame    = "\xFF\xF3\x84\xC4" . str_repeat("\x00", $frameLen - 4); // version 2, layer 3, br idx 8 (64k), sr idx 1 (24k)
        $this->assertEqualsWithDelta(2400, Mp3Duration::milliseconds(str_repeat($frame, 100)), 1);

        $this->assertNull(Mp3Duration::milliseconds('not an mp3 at all'));
    }
}
