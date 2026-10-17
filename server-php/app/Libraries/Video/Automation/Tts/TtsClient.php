<?php

declare(strict_types=1);

namespace App\Libraries\Video\Automation\Tts;

interface TtsClient
{
    /**
     * Speak $text and return MP3 bytes.
     *
     * @throws \App\Libraries\Video\Automation\VideoProviderException
     */
    public function synthesize(string $text): string;

    public function name(): string;
}
