<?php

namespace Tests\Unit\Community;

use App\Libraries\Community\CommunityPublicSitePublisher;
use App\Libraries\Community\CommunityPublisherFactory;
use App\Libraries\Community\MockCommunityPublisher;
use PHPUnit\Framework\TestCase;

/**
 * MockCommunityPublisher::publishAnswer() answers success: true,
 * public_status: 'published' and a canonical_url that does not exist. Standing
 * in for the real publisher in production would therefore record answers as
 * live on aicountly.com without anything having been published.
 *
 * The flag was read with !empty(), so REACH_PUB_COMMUNITY_MOCK=false turned the
 * mock ON — and an empty '=' is exactly how the variable is written in
 * docs/architecture/REACH_COMMUNITY_PUBLISHING_CONTRACT.md, one keystroke away.
 * The sibling PublicSitePublisherFactory already parsed its flag as a boolean;
 * this one did not.
 */
final class CommunityPublisherFactoryTest extends TestCase
{
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->saved = [
            'APP_ENV'                  => $_ENV['APP_ENV'] ?? null,
            'REACH_PUB_COMMUNITY_MOCK' => $_ENV['REACH_PUB_COMMUNITY_MOCK'] ?? null,
        ];
        CommunityPublisherFactory::setOverride(null);
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            if ($v === null) {
                unset($_ENV[$k]);
            } else {
                $_ENV[$k] = $v;
            }
        }
        CommunityPublisherFactory::setOverride(null);
        parent::tearDown();
    }

    public function testFalseStringDoesNotEnableTheMock(): void
    {
        $_ENV['APP_ENV']                  = 'development';
        $_ENV['REACH_PUB_COMMUNITY_MOCK'] = 'false';

        $this->assertInstanceOf(CommunityPublicSitePublisher::class, CommunityPublisherFactory::create());
    }

    public function testProductionNeverTakesTheMockEvenWhenAsked(): void
    {
        $_ENV['APP_ENV']                  = 'production';
        $_ENV['REACH_PUB_COMMUNITY_MOCK'] = 'true';

        $this->assertInstanceOf(CommunityPublicSitePublisher::class, CommunityPublisherFactory::create());
    }

    public function testTruthyFlagOutsideProductionStillGivesTheMock(): void
    {
        $_ENV['APP_ENV']                  = 'development';
        $_ENV['REACH_PUB_COMMUNITY_MOCK'] = 'true';

        $this->assertInstanceOf(MockCommunityPublisher::class, CommunityPublisherFactory::create());
    }

    public function testTestingEnvironmentAlwaysGivesTheMock(): void
    {
        $_ENV['APP_ENV'] = 'testing';
        unset($_ENV['REACH_PUB_COMMUNITY_MOCK']);

        $this->assertInstanceOf(MockCommunityPublisher::class, CommunityPublisherFactory::create());
    }
}
