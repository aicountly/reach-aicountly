<?php

declare(strict_types=1);

namespace Tests\Unit\Blog;

use App\Libraries\Blog\BlogAutoPublishService;
use App\Libraries\Blog\BlogFeatureFlags;
use App\Libraries\Blog\BlogStateMachine;
use PHPUnit\Framework\TestCase;

final class BlogAutoPublishServiceTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['BLOG_AUTO_PUBLISH_ENABLED', 'BLOG_PUBLIC_PUBLISHER_ENABLED'] as $key) {
            $this->envBackup[$key] = $_ENV[$key] ?? null;
            unset($_ENV[$key]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
        parent::tearDown();
    }

    public function testIsEligibleRequiresBothFlagsAndNonHighRisk(): void
    {
        $_ENV['BLOG_AUTO_PUBLISH_ENABLED'] = 'true';
        $_ENV['BLOG_PUBLIC_PUBLISHER_ENABLED'] = 'true';

        $svc = new BlogAutoPublishService(new BlogFeatureFlags());
        $item = [
            'id'              => 1,
            'workflow_status' => BlogStateMachine::INTERNAL_REVIEW,
            'risk_level'      => 'low',
        ];

        $this->assertFalse($svc->isEligible(array_merge($item, [
            'risk_level' => 'HIGH',
        ])));
    }

    public function testIsEligibleFalseWhenPublisherOff(): void
    {
        $_ENV['BLOG_AUTO_PUBLISH_ENABLED'] = 'true';
        $_ENV['BLOG_PUBLIC_PUBLISHER_ENABLED'] = 'false';

        $svc = new BlogAutoPublishService(new BlogFeatureFlags());
        $this->assertFalse($svc->isEligible([
            'id'              => 1,
            'workflow_status' => BlogStateMachine::INTERNAL_REVIEW,
            'risk_level'      => 'low',
        ]));
    }
}
