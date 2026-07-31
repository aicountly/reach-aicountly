<?php

namespace Tests\Unit\Blog;

use App\Libraries\Blog\BlogFeatureFlags;
use PHPUnit\Framework\TestCase;

final class BlogFeatureFlagsTest extends TestCase
{
    public function testSafeDefaultsWhenEnvUnset(): void
    {
        $flags = new BlogFeatureFlags();

        $this->assertTrue($flags->isEnabled('command_centre'));
        $this->assertTrue($flags->isEnabled('legacy_create_disabled'));
        $this->assertFalse($flags->isEnabled('automation'));
        $this->assertFalse($flags->isEnabled('auto_publish'));
        $this->assertTrue($flags->isEnabled('db_body_fallback'));
    }

    public function testAssertHighRiskAutoPublishForbiddenThrowsForHigh(): void
    {
        $flags = new BlogFeatureFlags();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('High-risk content cannot be auto-published.');

        $flags->assertHighRiskAutoPublishForbidden('HIGH');
    }

    public function testAssertHighRiskAutoPublishForbiddenReturnsFalseForLow(): void
    {
        $flags = new BlogFeatureFlags();

        $this->assertFalse($flags->isEnabled('auto_publish'));
        $this->assertFalse($flags->assertHighRiskAutoPublishForbidden('LOW'));
    }
}
