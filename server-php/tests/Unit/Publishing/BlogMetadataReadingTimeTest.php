<?php

namespace Tests\Unit\Publishing;

use App\Libraries\Publishing\Blog\BlogMetadataService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Reading time calculation tests for BlogMetadataService.
 *
 * @covers \App\Libraries\Publishing\Blog\BlogMetadataService
 */
class BlogMetadataReadingTimeTest extends CIUnitTestCase
{
    private BlogMetadataService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BlogMetadataService();
    }

    /** @dataProvider wordCountMinuteProvider */
    public function testReadingTimeForWordCount(int $wordCount, int $expectedMinutes): void
    {
        $html   = '<p>' . str_repeat('word ', $wordCount) . '</p>';
        $actual = $this->service->estimateReadingTime($html);
        $this->assertSame($expectedMinutes, $actual, "Expected {$wordCount} words → {$expectedMinutes} min");
    }

    public static function wordCountMinuteProvider(): array
    {
        return [
            [0,    1],   // Empty → min 1
            [1,    1],   // 1 word → 1 min
            [100,  1],   // 100 words → 1 min
            [200,  1],   // 200 words → 1 min
            [201,  2],   // 201 words → 2 min
            [400,  2],   // 400 words → 2 min
            [401,  3],   // 401 words → 3 min
            [600,  3],   // 600 words → 3 min
            [601,  4],   // 601 words → 4 min
            [1000, 5],   // 1000 words → 5 min
            [2000, 10],  // 2000 words → 10 min
        ];
    }

    public function testReadingTimeAlwaysPositive(): void
    {
        for ($words = 0; $words <= 1000; $words += 100) {
            $html   = '<p>' . str_repeat('word ', $words) . '</p>';
            $result = $this->service->estimateReadingTime($html);
            $this->assertGreaterThanOrEqual(1, $result, "Reading time must be ≥ 1 for {$words} words");
        }
    }

    public function testNestedHtmlTagsDoNotAffectWordCount(): void
    {
        $plainHtml  = '<p>' . str_repeat('word ', 200) . '</p>';
        $nestedHtml = '<article><section><p>' . str_repeat('<em>word</em> ', 200) . '</p></section></article>';

        $plain  = $this->service->estimateReadingTime($plainHtml);
        $nested = $this->service->estimateReadingTime($nestedHtml);
        $this->assertSame($plain, $nested);
    }
}
