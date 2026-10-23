<?php

declare(strict_types=1);

namespace Tests\Unit\Video\Automation;

use App\Libraries\Video\Automation\VideoScriptValidator;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @covers \App\Libraries\Video\Automation\VideoScriptValidator
 */
final class VideoScriptValidatorTest extends CIUnitTestCase
{
    public static function vlog(array $over = []): array
    {
        $words = static fn (int $n) => trim(str_repeat('GST return filing explained simply here. ', (int) ceil($n / 6)));
        $scenes = [];
        foreach ([1, 2, 3, 4, 5] as $i) {
            $scenes[] = [
                'order' => $i,
                'voice_over_text' => $words(110),
                'on_screen_text' => "Step {$i}",
                'visual' => $i === 1 ? ['type' => 'title_card', 'url_or_prompt' => ''] : ($i % 2 ? ['type' => 'screenshot', 'url_or_prompt' => 'https://aicountly.com/smart-books'] : ['type' => 'image', 'url_or_prompt' => 'An accountant reviewing GST invoices on a laptop in a bright office']),
                'duration_hint_secs' => 30,
            ];
        }
        return array_replace([
            'external_key' => 'vlog-2026-10-17-gst',
            'format' => 'vlog',
            'title' => 'GST returns explained <in 4 minutes>',
            'description' => "GSTR-1 and GSTR-3B for small businesses.\nWith Aicountly Smart Books.",
            'tags' => ['GST', 'gst', 'GSTR-1', 'Smart Books', ''],
            'chapters' => [['title' => 'Intro', 'scene_order' => 1], ['title' => 'GSTR-1', 'scene_order' => 2], ['title' => 'GSTR-3B', 'scene_order' => 4]],
            'scenes' => $scenes,
            'cta_text' => 'Start with Aicountly Smart Books at aicountly.com',
            'thumbnail_prompt' => 'A clean illustration of a GST return form with a green tick',
            'product_slug' => 'smart_books',
        ], $over);
    }

    public static function short(array $over = []): array
    {
        return array_replace([
            'external_key' => 'short-2026-10-17-tds',
            'format' => 'short',
            'title' => 'TDS is due on the 7th',
            'description' => 'A 30-second reminder.',
            'tags' => ['TDS'],
            'scenes' => [
                ['order' => 1, 'voice_over_text' => 'TDS you deducted last month is usually due by the seventh.', 'on_screen_text' => 'TDS: usually the 7th', 'visual' => ['type' => 'image', 'url_or_prompt' => 'A calendar with the 7th circled in green'], 'duration_hint_secs' => 5],
                ['order' => 2, 'voice_over_text' => 'Aicountly Smart Books lists what is due so you can plan the payment.', 'on_screen_text' => '', 'visual' => ['type' => 'screenshot', 'url_or_prompt' => 'https://aicountly.com/smart-books'], 'duration_hint_secs' => 6],
            ],
            'cta_text' => 'See aicountly.com',
            'thumbnail_prompt' => '',
        ], $over);
    }

    public function testNormalisesAVlog(): void
    {
        $s = VideoScriptValidator::normalize(self::vlog());
        $this->assertSame('vlog', $s['format']);
        $this->assertSame('GST returns explained in 4 minutes', $s['title'], '< and > are removed');
        $this->assertSame(['GST', 'GSTR-1', 'Smart Books'], $s['tags'], 'duplicates and empties dropped');
        $this->assertSame('Step 1', $s['scenes'][0]['on_screen_text']);
        $this->assertSame('title_card', $s['scenes'][0]['visual']['type']);
        $this->assertCount(3, $s['chapters']);
        $this->assertGreaterThan(VideoScriptValidator::VLOG_MIN_SECS, $s['estimated_secs']);
        $this->assertSame('smart_books', $s['product_slug']);
    }

    public function testNormalisesAShort(): void
    {
        $s = VideoScriptValidator::normalize(self::short());
        $this->assertSame('short', $s['format']);
        $this->assertSame([], $s['chapters']);
        $this->assertNull($s['product_slug']);
        $this->assertLessThanOrEqual(60, $s['estimated_secs']);
    }

    /** @return array<string, array{0: array, 1: string}> */
    public static function invalid(): array
    {
        $longShort = self::short();
        $longShort['scenes'][0]['voice_over_text'] = str_repeat('word ', 160);
        $tinyVlog = self::vlog();
        foreach ($tinyVlog['scenes'] as &$sc) {
            $sc['voice_over_text'] = 'Short line.';
        }
        unset($sc);
        $evil = self::short();
        $evil['scenes'][1]['visual']['url_or_prompt'] = 'https://aicountly.com.evil.example/x';
        $http = self::short();
        $http['scenes'][1]['visual']['url_or_prompt'] = 'http://aicountly.com/pricing';
        $userinfo = self::short();
        $userinfo['scenes'][1]['visual']['url_or_prompt'] = 'https://user:pw@aicountly.com/';
        $imageUrl = self::short();
        $imageUrl['scenes'][0]['visual'] = ['type' => 'stock', 'url_or_prompt' => 'https://stock.example/photo.jpg'];
        $badType = self::short();
        $badType['scenes'][0]['visual']['type'] = 'video';
        $dupOrder = self::short();
        $dupOrder['scenes'][1]['order'] = 1;
        $badChapter = self::vlog(['chapters' => [['title' => 'X', 'scene_order' => 99]]]);
        $silent = self::short();
        $silent['scenes'][0]['voice_over_text'] = '';
        $silent['scenes'][1]['voice_over_text'] = '';

        return [
            'no key'             => [self::short(['external_key' => '']), 'external_key'],
            'bad format'         => [self::short(['format' => 'reel']), 'format'],
            'long title'         => [self::short(['title' => str_repeat('a', 101)]), 'title'],
            'no cta'             => [self::short(['cta_text' => '']), 'cta_text'],
            'short too long'     => [$longShort, 'at most 60 s'],
            'vlog too short'     => [$tinyVlog, '2–6 minutes'],
            'lookalike host'     => [$evil, 'aicountly.com'],
            'plain http'         => [$http, 'https'],
            'userinfo'           => [$userinfo, 'aicountly.com'],
            'stock url'          => [$imageUrl, 'send a prompt'],
            'unknown visual'     => [$badType, 'visual.type'],
            'duplicate order'    => [$dupOrder, 'unique'],
            'chapter off-script' => [$badChapter, 'not a scene'],
            'no voice at all'    => [$silent, 'voice_over_text'],
            'bad product slug'   => [self::short(['product_slug' => 'Smart Books!']), 'product_slug'],
        ];
    }

    /** @dataProvider invalid */
    public function testRefusesInvalidScripts(array $payload, string $needle): void
    {
        try {
            VideoScriptValidator::normalize($payload);
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
        }
    }

    public function testScreenshotHosts(): void
    {
        $this->assertTrue(VideoScriptValidator::screenshotHostAllowed('https://aicountly.com/pricing'));
        $this->assertTrue(VideoScriptValidator::screenshotHostAllowed('https://books.aicountly.com/dashboard'));
        $this->assertFalse(VideoScriptValidator::screenshotHostAllowed('https://aicountly.com:8443/'));
        $this->assertFalse(VideoScriptValidator::screenshotHostAllowed('https://notaicountly.com/'));
        $this->assertFalse(VideoScriptValidator::screenshotHostAllowed('file:///etc/passwd'));
    }

    public function testRevisionChangesTextButNeverVisualsOrOrder(): void
    {
        $script = VideoScriptValidator::normalize(self::short());
        $final  = VideoScriptValidator::vettingDraft($script);
        $final['title'] = 'TDS is usually due on the 7th';
        $final['scenes'][0]['voice_over_text'] = 'TDS deducted last month is usually due by the seventh — check the portal.';
        $final['scenes'][0]['visual'] = ['type' => 'screenshot', 'url_or_prompt' => 'https://evil.example/'];
        $final['scenes'][1]['order'] = 7;
        $final['cta_text'] = '';

        $revised = VideoScriptValidator::applyRevision($script, $final);
        $this->assertSame('TDS is usually due on the 7th', $revised['title']);
        $this->assertStringContainsString('check the portal', $revised['scenes'][0]['voice_over_text']);
        $this->assertSame('image', $revised['scenes'][0]['visual']['type'], 'visuals stay as the routine sent them');
        $this->assertSame([1, 2], array_column($revised['scenes'], 'order'));
        $this->assertSame('See aicountly.com', $revised['cta_text'], 'an emptied field keeps the original');
    }

    public function testARevisionThatBreaksTheFormatIsRefused(): void
    {
        $script = VideoScriptValidator::normalize(self::short());
        $final  = VideoScriptValidator::vettingDraft($script);
        $final['scenes'][0]['voice_over_text'] = str_repeat('longer words ', 100);
        $this->expectException(\InvalidArgumentException::class);
        VideoScriptValidator::applyRevision($script, $final);
    }
}
