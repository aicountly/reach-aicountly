<?php

declare(strict_types=1);

namespace Tests\Unit\Bot;

use App\Libraries\Bot\BotCommandPayloadBuilder;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * What the reach-command routine receives: the owner's words verbatim, the
 * channels that can really post, the rules, and where to report.
 *
 * @internal
 */
final class BotCommandPayloadBuilderTest extends CIUnitTestCase
{
    private const CMD = [
        'uuid'             => '6f1c2d3e-4b5a-4c6d-8e9f-0a1b2c3d4e5f',
        'kind'             => 'command',
        'activity_key'     => null,
        'instruction'      => "Post a 30-second soft-launch reel announcing AICOUNTLY on all connected social platforms today\n\"quoted\" <tags>",
        'channels_json'    => '[]',
        'attachments_json' => '["https://aicountly.com/logo.png"]',
        'created_by_name'  => 'Owner',
        'created_by_email' => 'owner@aicountly.com',
    ];

    private static function channels(): array
    {
        return [
            ['channel' => 'youtube', 'label' => 'YouTube', 'ready' => true, 'account' => 'AICOUNTLY', 'reason' => null],
            ['channel' => 'instagram', 'label' => 'Instagram', 'ready' => true, 'account' => 'aicountly', 'reason' => null],
            ['channel' => 'twitter', 'label' => 'X / Twitter', 'ready' => false, 'account' => null, 'reason' => 'No active X account is connected in social-api.ai.'],
        ];
    }

    public function testCarriesTheInstructionVerbatimWithContextAndRules(): void
    {
        $text = BotCommandPayloadBuilder::build(self::CMD, self::channels(), new \DateTimeImmutable('2026-10-18 05:00:00', new \DateTimeZone('UTC')));

        $this->assertStringContainsString(self::CMD['instruction'], $text);
        $this->assertStringContainsString('command_id: ' . self::CMD['uuid'], $text);
        $this->assertStringContainsString('TARGET CHANNELS (not chosen: every channel that is ready now): youtube, instagram', $text);
        $this->assertStringContainsString('https://aicountly.com/logo.png', $text);
        $this->assertStringContainsString('"today_ist": "2026-10-18"', $text);
        $this->assertStringContainsString('10:30 IST', $text);
        $this->assertStringContainsString('No active X account', $text);
        $this->assertStringContainsString('/v1/automation/commands/' . self::CMD['uuid'] . '/report', $text);
        $this->assertStringContainsString('/v1/automation/video-scripts', $text);
        $this->assertStringContainsString('/v1/automation/social-posts', $text);
        $this->assertStringContainsString('Gemini vets', $text);
        $this->assertStringContainsString('Never invent features', $text);
        $this->assertStringContainsString('AI-assisted', $text);
        $this->assertStringContainsString('never ask a person to approve', $text);
        $this->assertStringContainsString('"plan_briefing_endpoint"', $text);
        $this->assertLessThan(20000, mb_strlen($text));
    }

    public function testOwnerChosenChannelsWin(): void
    {
        $text = BotCommandPayloadBuilder::build(['channels_json' => '["linkedin","twitter"]'] + self::CMD, self::channels());
        $this->assertStringContainsString('TARGET CHANNELS (chosen by the owner): linkedin, twitter', $text);
    }

    public function testApiBaseFollowsReachAppUrl(): void
    {
        $prev = getenv('REACH_APP_URL');
        putenv('REACH_APP_URL=https://reach.example.test/');
        $_ENV['REACH_APP_URL'] = 'https://reach.example.test/';
        try {
            $this->assertSame('https://reach.example.test/api', BotCommandPayloadBuilder::apiBase());
        } finally {
            if ($prev === false) {
                putenv('REACH_APP_URL');
                unset($_ENV['REACH_APP_URL']);
            } else {
                putenv('REACH_APP_URL=' . $prev);
                $_ENV['REACH_APP_URL'] = $prev;
            }
        }
    }
}
