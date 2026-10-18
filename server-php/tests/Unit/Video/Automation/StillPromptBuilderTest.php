<?php

declare(strict_types=1);

namespace Tests\Unit\Video\Automation;

use App\Libraries\Video\Automation\Visuals\OpenAiStillSource;
use App\Libraries\Video\Automation\Visuals\StillPromptBuilder;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @covers \App\Libraries\Video\Automation\Visuals\StillPromptBuilder
 */
final class StillPromptBuilderTest extends CIUnitTestCase
{
    private static function script(string $format = 'vlog'): array
    {
        return [
            'format' => $format, 'title' => 'GST returns explained in 4 minutes',
            'description' => 'How GSTR-1 and GSTR-3B fit together each month. Plus the due dates.',
            'thumbnail_prompt' => 'Two return forms side by side with a calendar',
            'product_slug' => 'books',
        ];
    }

    public function testASceneStillIsDrawnFromWhatTheSceneSays(): void
    {
        $scene = [
            'order' => 2, 'voice_over_text' => 'GSTR-1 reports your outward supplies by the eleventh of the next month.',
            'on_screen_text' => 'GSTR-1 by the 11th', 'visual' => ['type' => 'image', 'url_or_prompt' => 'Sales invoices flowing into a return form'],
        ];
        $prompt = StillPromptBuilder::forScene(self::script(), $scene);

        $this->assertStringContainsString('GST returns explained in 4 minutes', $prompt, 'the video');
        $this->assertStringContainsString('How GSTR-1 and GSTR-3B fit together each month.', $prompt, 'the topic');
        $this->assertStringNotContainsString('Plus the due dates', $prompt, 'only the topic sentence');
        $this->assertStringContainsString('GSTR-1 reports your outward supplies by the eleventh', $prompt, 'the scene\'s own words');
        $this->assertStringContainsString('Depict: Sales invoices flowing into a return form.', $prompt, 'the routine\'s visual note');
        $this->assertStringContainsString('do not draw it', $prompt);
        $this->assertStringContainsString('landscape 16:9', $prompt);
        $this->assertStringContainsString('9:16', StillPromptBuilder::forScene(self::script('short'), $scene));
        $this->assertStringContainsString('No text, no letters, no logos', OpenAiStillSource::STYLE);
    }

    public function testTheThumbnailPromptNamesTheVideoAndItsTopic(): void
    {
        $prompt = StillPromptBuilder::forThumbnail(self::script());

        $this->assertStringContainsString('"GST returns explained in 4 minutes"', $prompt);
        $this->assertStringContainsString('Two return forms side by side with a calendar', $prompt);
        $this->assertStringContainsString('bottom-right corner calm', $prompt);
    }
}
