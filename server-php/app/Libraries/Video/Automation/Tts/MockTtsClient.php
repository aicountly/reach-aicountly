<?php

declare(strict_types=1);

namespace App\Libraries\Video\Automation\Tts;

use App\Libraries\Video\Automation\VideoProviders;

/**
 * Development/test stand-in: silent MP3 sized at ~150 words per minute.
 * Refuses in production — a mock voice-over must never reach YouTube.
 */
final class MockTtsClient implements TtsClient
{
    public function __construct()
    {
        VideoProviders::refuseMockInProduction('voice-over (TTS)');
    }

    public function name(): string
    {
        return 'mock_tts';
    }

    public function synthesize(string $text): string
    {
        $words = max(1, str_word_count($text));
        return Mp3Duration::silentStream((int) round($words / 2.5 * 1000));
    }
}
