<?php

declare(strict_types=1);

namespace Tests\Unit\Video\Automation;

use App\Libraries\Ai\Vetting\Rubrics;
use App\Libraries\Settings\ReachConfig;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @covers \App\Libraries\Ai\Vetting\Rubrics
 */
final class VideoScriptRubricTest extends CIUnitTestCase
{
    public function testTheVideoScriptRubricPinsTheOwnersRules(): void
    {
        $this->assertContains('video_script', Rubrics::TYPES);
        $r = Rubrics::for('video_script');
        $this->assertStringContainsString('Nothing you approve is reviewed again', $r);
        foreach (['Accuracy', 'Brand voice', 'No claims about features that do not exist', 'YouTube policy-safe', 'No guarantees', 'CTA'] as $rule) {
            $this->assertStringContainsStringIgnoringCase($rule, $r);
        }
        $this->assertStringContainsString('60 seconds', $r);
    }

    public function testVideoScriptsHaveTheirOwnVettingModelSetting(): void
    {
        ReachConfig::flush();
        $this->assertArrayHasKey('ai.vetting.model.video_script', ReachConfig::registry());
        $this->assertSame(ReachConfig::vettingModel('blog') !== '' ? ReachConfig::vettingModel('blog') : '', ReachConfig::vettingModel('video_script'), 'blank falls back to the default vetting model');
    }

    public function testStreamVSettingsExistWithTheirDefaults(): void
    {
        $reg = ReachConfig::registry();
        foreach (['video.shotstack.api_key', 'video.youtube.client_secret', 'video.youtube.refresh_token'] as $secret) {
            $this->assertSame('secret', $reg[$secret]['type'], $secret);
        }
        $this->assertSame('gpt-4o-mini-tts', $reg['video.tts.model']['default']);
        $this->assertSame('stage', $reg['video.shotstack.env']['default']);
        $this->assertSame(1, $reg['video.daily.vlogs']['default']);
        $this->assertSame(1, $reg['video.daily.shorts']['default']);
        $this->assertTrue($reg['automation.video.enabled']['default']);
        $this->assertSame('public', $reg['video.youtube.privacy']['default']);
    }
}
