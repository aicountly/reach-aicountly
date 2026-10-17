<?php

declare(strict_types=1);

namespace Tests\Unit\Video\Automation;

use App\Libraries\Video\Automation\Render\CaptionBuilder;
use App\Libraries\Video\Automation\Render\TimelineBuilder;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @covers \App\Libraries\Video\Automation\Render\TimelineBuilder
 * @covers \App\Libraries\Video\Automation\Render\CaptionBuilder
 */
final class TimelineBuilderTest extends CIUnitTestCase
{
    private static function scene(int $order, string $kind, ?float $audio, string $onScreen = '', string $voice = 'File GSTR-1 by the eleventh, then pay tax through GSTR-3B.'): array
    {
        return [
            'order' => $order, 'voice_over_text' => $audio === null ? '' : $voice, 'on_screen_text' => $onScreen, 'duration_hint_secs' => 4.0,
            'visual_kind' => $kind, 'image_url' => $kind === 'image' ? "https://reach.example/api/v1/public/video-media/{$order}.jpg?sig=x" : null,
            'image_source' => $kind === 'image' ? ($order === 3 ? 'dalle' : 'screenshot') : null,
            'audio_url' => $audio === null ? null : "https://reach.example/api/v1/public/video-media/a{$order}.mp3?sig=y", 'audio_secs' => $audio,
        ];
    }

    private static function script(string $format): array
    {
        return ['format' => $format, 'title' => 'GST returns in 3 minutes', 'cta_text' => 'Try AICOUNTLY Smart Books at aicountly.com', 'chapters' => []];
    }

    /** @return list<array<string, mixed>> */
    private static function clips(array $edit, string $type): array
    {
        $out = [];
        foreach ($edit['timeline']['tracks'] as $track) {
            foreach ($track['clips'] as $clip) {
                if ($clip['asset']['type'] === $type) {
                    $out[] = $clip;
                }
            }
        }
        return $out;
    }

    public function testVlogTimelineHasIntroScenesOutroAndBrandedLayers(): void
    {
        $scenes = [self::scene(1, 'image', 10.0, 'Two returns every month'), self::scene(2, 'title_card', 8.0, 'GSTR-1 vs GSTR-3B'), self::scene(3, 'image', null, 'Dashboard')];
        $out    = TimelineBuilder::build(self::script('vlog'), $scenes, [
            'logo_url' => 'https://aicountly.com/assests/images/ai-logo.png',
            'music_url' => 'https://cdn.example/music.mp3',
            'callback_url' => 'https://reach.example/api/v1/public/video/shotstack/callback/u?sig=s',
        ]);
        $edit = $out['edit'];

        $this->assertSame(['width' => 1920, 'height' => 1080], $edit['output']['size']);
        $this->assertSame('mp4', $edit['output']['format']);
        $this->assertSame('#ffffff', $edit['timeline']['background']);
        $this->assertSame('https://reach.example/api/v1/public/video/shotstack/callback/u?sig=s', $edit['callback']);
        $this->assertSame('https://cdn.example/music.mp3', $edit['timeline']['soundtrack']['src']);

        // 3.5 intro + (10+0.4) + (8+0.4) + 4 (hint, no voice) + 3.5 outro
        $this->assertEqualsWithDelta(29.8, $out['duration'], 0.001);
        $this->assertSame([1 => 3.5, 2 => 13.9, 3 => 22.3], $out['scene_starts']);

        $audio = self::clips($edit, 'audio');
        $this->assertCount(2, $audio);
        $this->assertSame(3.5, $audio[0]['start']);
        $this->assertSame(10.0, $audio[0]['length']);
        $this->assertSame(13.9, $audio[1]['start']);

        $images = self::clips($edit, 'image');
        $visuals = array_values(array_filter($images, static fn ($c) => str_contains($c['asset']['src'], 'video-media')));
        $this->assertCount(2, $visuals);
        $this->assertSame('cover', $visuals[0]['fit']);
        $this->assertArrayNotHasKey('effect', $visuals[0], 'screenshots stay still');
        $this->assertSame('zoomInSlow', $visuals[1]['effect'], 'stills get a slow zoom');
        $logos = array_filter($images, static fn ($c) => str_contains($c['asset']['src'], 'ai-logo'));
        $this->assertCount(5, $logos, 'intro + 3 scenes + outro');

        $texts = array_map(static fn ($c) => $c['asset']['text'], self::clips($edit, 'text'));
        $this->assertContains('GST returns in 3 minutes', $texts, 'intro title');
        $this->assertContains('Two returns every month', $texts, 'lower third');
        $this->assertContains('GSTR-1 vs GSTR-3B', $texts, 'title card');
        $this->assertContains('Try AICOUNTLY Smart Books at aicountly.com', $texts, 'CTA end card');
        $this->assertContains('aicountly.com', $texts);

        // Captions are the same cues sent to YouTube, inside each voice-over.
        $this->assertNotEmpty($out['cues']);
        foreach ($out['cues'] as $cue) {
            $this->assertContains($cue['text'], $texts);
            $this->assertTrue(($cue['start'] >= 3.5 && $cue['end'] <= 13.5 + 0.001) || ($cue['start'] >= 13.9 && $cue['end'] <= 21.9 + 0.001));
        }
        foreach (self::clips($edit, 'text') as $clip) {
            $this->assertSame('Montserrat', substr($clip['asset']['font']['family'], 0, 10));
        }
    }

    public function testShortIsVerticalWithoutAnIntro(): void
    {
        $out = TimelineBuilder::build(self::script('short'), [self::scene(1, 'image', 20.0, 'TDS due on the 7th'), self::scene(2, 'image', 15.0)], []);
        $this->assertSame(['width' => 1080, 'height' => 1920], $out['edit']['output']['size']);
        $this->assertSame([1 => 0.0, 2 => 20.4], $out['scene_starts']);
        $this->assertEqualsWithDelta(20.4 + 15.4 + 3.0, $out['duration'], 0.001);
        $this->assertSame('contain', self::clips($out['edit'], 'image')[0]['fit'], 'screenshots are letterboxed in 9:16');
        $this->assertArrayNotHasKey('callback', $out['edit']);
        $this->assertArrayNotHasKey('soundtrack', $out['edit']['timeline']);
    }

    public function testNonHttpsUrlsAreDropped(): void
    {
        $out = TimelineBuilder::build(self::script('vlog'), [self::scene(1, 'title_card', 5.0, 'Hi')], ['logo_url' => 'http://insecure/logo.png', 'music_url' => 'javascript:alert(1)']);
        $this->assertSame([], array_filter(self::clips($out['edit'], 'image'), static fn ($c) => str_contains($c['asset']['src'], 'insecure')));
        $this->assertArrayNotHasKey('soundtrack', $out['edit']['timeline']);
    }

    public function testChapterLinesFollowYouTubeRules(): void
    {
        $starts   = [1 => 3.5, 2 => 40.0, 3 => 95.0, 4 => 150.0];
        $chapters = [['title' => 'Intro', 'scene_order' => 1], ['title' => 'GSTR-1', 'scene_order' => 2], ['title' => 'GSTR-3B', 'scene_order' => 3]];
        $this->assertSame(['0:00 Intro', '0:40 GSTR-1', '1:35 GSTR-3B'], TimelineBuilder::chapterLines($chapters, $starts, 200.0));
        $this->assertSame([], TimelineBuilder::chapterLines(array_slice($chapters, 0, 2), $starts, 200.0), 'fewer than three');
        $this->assertSame([], TimelineBuilder::chapterLines($chapters, [1 => 0.0, 2 => 5.0, 3 => 95.0], 200.0), 'a chapter shorter than 10 s');
    }

    public function testCaptionChunksAndSrt(): void
    {
        $chunks = CaptionBuilder::chunks('Every registered business files GSTR-1, which reports sales, and GSTR-3B, which pays the tax due for the month.');
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(CaptionBuilder::MAX_WORDS, count(explode(' ', $chunk)));
            $this->assertLessThanOrEqual(CaptionBuilder::MAX_CHARS + 20, mb_strlen($chunk));
        }
        $this->assertSame('Every registered business files GSTR-1,', $chunks[0]);

        $cues = CaptionBuilder::cues('One two three. Four five six.', 2.0, 4.0);
        $this->assertCount(2, $cues);
        $this->assertSame(2.0, $cues[0]['start']);
        $this->assertEqualsWithDelta(6.0, $cues[1]['end'], 0.001);

        $srt = CaptionBuilder::srt([['start' => 3.5, 'end' => 61.25, 'text' => 'Hello'], ['start' => 3661.0, 'end' => 3662.5, 'text' => 'Later']]);
        $this->assertSame("1\n00:00:03,500 --> 00:01:01,250\nHello\n\n2\n01:01:01,000 --> 01:01:02,500\nLater\n", $srt);
    }
}
