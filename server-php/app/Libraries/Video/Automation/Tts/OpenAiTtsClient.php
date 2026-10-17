<?php

declare(strict_types=1);

namespace App\Libraries\Video\Automation\Tts;

use App\Libraries\Settings\ReachConfig;
use App\Libraries\Video\Automation\Http\CurlTransport;
use App\Libraries\Video\Automation\Http\HttpTransport;
use App\Libraries\Video\Automation\VideoProviderException;

/**
 * Voice-over through OpenAI text-to-speech: POST /v1/audio/speech, MP3 out.
 * Key = ai.openai.api_key; model / voice = video.tts.model / video.tts.voice
 * (Settings → AI & Automation → Video).
 */
final class OpenAiTtsClient implements TtsClient
{
    public const MAX_INPUT_CHARS = 4096;

    private const INSTRUCTIONS = 'Speak as a warm, clear, professional Indian English narrator explaining business software to small-business owners. Natural pace, friendly and confident, no exaggeration.';

    private HttpTransport $http;

    public function __construct(?HttpTransport $http = null, private readonly ?string $baseUrl = null)
    {
        $this->http = $http ?? new CurlTransport();
    }

    public function name(): string
    {
        return 'openai_tts';
    }

    public function synthesize(string $text): string
    {
        $key = ReachConfig::secret('ai.openai.api_key');
        if ($key === null) {
            throw VideoProviderException::notConfigured('OpenAI API key is not set (Settings → AI & Automation) — voice-overs cannot be generated.');
        }
        $text = trim($text);
        if ($text === '') {
            throw new VideoProviderException('Nothing to speak.', VideoProviderException::PERMANENT);
        }
        if (mb_strlen($text) > self::MAX_INPUT_CHARS) {
            throw new VideoProviderException('Voice-over text exceeds ' . self::MAX_INPUT_CHARS . ' characters.', VideoProviderException::PERMANENT);
        }

        $res = $this->http->request('POST', $this->base() . '/v1/audio/speech', [
            'Authorization' => 'Bearer ' . $key,
            'Content-Type'  => 'application/json',
            'Accept'        => 'audio/mpeg',
        ], json_encode($this->requestBody($text), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ['timeout' => 120]);

        if (! $res->ok()) {
            throw VideoProviderException::fromResponse('OpenAI TTS', 'speech', $res);
        }
        if (strlen($res->body) < 256 || str_starts_with(ltrim($res->body), '{')) {
            throw new VideoProviderException('OpenAI TTS returned no audio.', VideoProviderException::TRANSIENT);
        }

        return $res->body;
    }

    /** @return array<string, mixed> */
    public function requestBody(string $text): array
    {
        $model = trim((string) ReachConfig::get('video.tts.model')) ?: 'gpt-4o-mini-tts';
        $voice = trim((string) ReachConfig::get('video.tts.voice')) ?: 'coral';
        $body  = [
            'model'           => $model,
            'voice'           => $voice,
            'input'           => $text,
            'response_format' => 'mp3',
        ];
        // Only the gpt-4o TTS models take style instructions; tts-1 rejects them.
        if (str_starts_with(strtolower($model), 'gpt-')) {
            $body['instructions'] = self::INSTRUCTIONS;
        }
        return $body;
    }

    private function base(): string
    {
        $base = $this->baseUrl ?? (string) ($_ENV['AI_OPENAI_BASE_URL'] ?? getenv('AI_OPENAI_BASE_URL') ?: 'https://api.openai.com');
        return preg_replace('#/v1/?$#', '', rtrim($base, '/')) ?? 'https://api.openai.com';
    }
}
