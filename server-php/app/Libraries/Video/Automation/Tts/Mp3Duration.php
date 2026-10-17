<?php

declare(strict_types=1);

namespace App\Libraries\Video\Automation\Tts;

/**
 * Duration of an MP3 (MPEG-1/2/2.5 Layer III) by walking its frame headers.
 * The host has no ffmpeg, and the timeline needs each voice-over's real
 * length to cut the scenes, so this reads it from the bytes. Returns null when
 * the data is not a Layer III stream it can walk.
 */
final class Mp3Duration
{
    private const BITRATES_V1 = [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320];
    private const BITRATES_V2 = [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160];
    private const RATES = [
        3 => [44100, 48000, 32000], // MPEG-1
        2 => [22050, 24000, 16000], // MPEG-2
        0 => [11025, 12000, 8000],  // MPEG-2.5
    ];

    public static function milliseconds(string $bytes): ?int
    {
        $len    = strlen($bytes);
        $offset = self::skipId3($bytes);
        $frames = 0;
        $secs   = 0.0;

        while ($offset + 4 <= $len) {
            $h = unpack('N', substr($bytes, $offset, 4))[1];
            if (($h & 0xFFE00000) !== 0xFFE00000) {
                // Not a frame header (trailing ID3v1 tag, junk): resync forward.
                $offset++;
                continue;
            }
            $version  = ($h >> 19) & 0x3;   // 3 = MPEG-1, 2 = MPEG-2, 0 = MPEG-2.5, 1 = reserved
            $layer    = ($h >> 17) & 0x3;   // 1 = Layer III
            $brIndex  = ($h >> 12) & 0xF;
            $srIndex  = ($h >> 10) & 0x3;
            $padding  = ($h >> 9) & 0x1;
            if ($version === 1 || $layer !== 1 || $brIndex === 0 || $brIndex === 15 || $srIndex === 3) {
                $offset++;
                continue;
            }
            $bitrate    = ($version === 3 ? self::BITRATES_V1 : self::BITRATES_V2)[$brIndex] * 1000;
            $sampleRate = self::RATES[$version][$srIndex];
            $samples    = $version === 3 ? 1152 : 576;
            $frameLen   = intdiv(($version === 3 ? 144 : 72) * $bitrate, $sampleRate) + $padding;
            if ($frameLen < 4) {
                $offset++;
                continue;
            }
            $frames++;
            $secs   += $samples / $sampleRate;
            $offset += $frameLen;
        }

        return $frames > 0 ? (int) round($secs * 1000) : null;
    }

    private static function skipId3(string $bytes): int
    {
        if (strlen($bytes) >= 10 && substr($bytes, 0, 3) === 'ID3') {
            $size = ((ord($bytes[6]) & 0x7F) << 21) | ((ord($bytes[7]) & 0x7F) << 14) | ((ord($bytes[8]) & 0x7F) << 7) | (ord($bytes[9]) & 0x7F);
            return 10 + $size + ((ord($bytes[5]) & 0x10) ? 10 : 0);
        }
        return 0;
    }

    /**
     * A silent, valid MPEG-1 Layer III stream of about $ms milliseconds
     * (128 kbps, 44.1 kHz). Used by the mock TTS so the timeline code runs
     * against real-shaped audio in development and tests.
     */
    public static function silentStream(int $ms): string
    {
        $frameLen = intdiv(144 * 128000, 44100); // 417 bytes, no padding
        $frames   = max(1, (int) ceil(($ms / 1000) / (1152 / 44100)));
        $frame    = "\xFF\xFB\x90\xC4" . str_repeat("\x00", $frameLen - 4);
        return str_repeat($frame, $frames);
    }
}
