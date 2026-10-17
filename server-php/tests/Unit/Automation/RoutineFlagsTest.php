<?php

declare(strict_types=1);

namespace Tests\Unit\Automation;

use App\Libraries\Automation\Routine\RoutineFlags;
use App\Libraries\Settings\ReachConfig;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Settings decide; an explicit false in .env is a kill-switch.
 *
 * @covers \App\Libraries\Automation\Routine\RoutineFlags
 */
final class RoutineFlagsTest extends CIUnitTestCase
{
    private const VARS = ['BLOG_AUTO_PUBLISH_ENABLED', 'BLOG_PUBLIC_PUBLISHER_ENABLED', 'BLOG_IMAGE_GENERATION_ENABLED', 'KB_AUTO_PUBLISH_ENABLED'];
    private array $backup = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::VARS as $k) {
            $this->backup[$k] = $_ENV[$k] ?? null;
            unset($_ENV[$k], $_SERVER[$k]);
            putenv($k);
        }
        ReachConfig::flush();
    }

    protected function tearDown(): void
    {
        foreach ($this->backup as $k => $v) {
            if ($v === null) {
                unset($_ENV[$k]);
            } else {
                $_ENV[$k] = $v;
            }
        }
        ReachConfig::flush();
        parent::tearDown();
    }

    public function testDefaultsAreOnWithoutTouchingEnv(): void
    {
        $this->assertTrue(RoutineFlags::enabled('blog.auto_publish'));
        $this->assertTrue(RoutineFlags::enabled('blog.ai_cover'));
        $this->assertTrue(RoutineFlags::enabled('knowledge_base.auto_publish'));
    }

    public function testAnExplicitFalseIsAKillSwitchButTrueDefersToSettings(): void
    {
        $_ENV['BLOG_PUBLIC_PUBLISHER_ENABLED'] = 'false';
        $this->assertSame(['enabled' => false, 'source' => 'env_kill_switch', 'kill_switch' => 'BLOG_PUBLIC_PUBLISHER_ENABLED'], RoutineFlags::explain('blog.auto_publish'));

        $_ENV['BLOG_PUBLIC_PUBLISHER_ENABLED'] = 'true';
        $_ENV['KB_AUTO_PUBLISH_ENABLED']       = '0';
        $this->assertTrue(RoutineFlags::enabled('blog.auto_publish'));
        $this->assertFalse(RoutineFlags::enabled('knowledge_base.auto_publish'));
    }
}
