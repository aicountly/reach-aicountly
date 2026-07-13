<?php

namespace Tests\Unit\Publishing;

use App\Libraries\Publishing\Connector\MockPublicSitePublisher;
use App\Libraries\Publishing\Connector\PublicSitePublisherFactory;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @covers \App\Libraries\Publishing\Connector\PublicSitePublisherFactory
 */
class PublicSitePublisherFactoryTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unset($_ENV['REACH_PUB_MOCK'], $_ENV['CI_ENVIRONMENT']);
    }

    public function testReturnsMockWhenEnvFlagSet(): void
    {
        $_ENV['REACH_PUB_MOCK'] = 'true';
        $publisher = PublicSitePublisherFactory::make();
        $this->assertInstanceOf(MockPublicSitePublisher::class, $publisher);
    }

    public function testReturnsMockForTestingEnvironment(): void
    {
        unset($_ENV['REACH_PUB_MOCK']);
        $_ENV['CI_ENVIRONMENT'] = 'testing';
        $publisher = PublicSitePublisherFactory::make();
        $this->assertInstanceOf(MockPublicSitePublisher::class, $publisher);
    }

    public function testDevelopmentEnvironmentUsesProductionPublisher(): void
    {
        unset($_ENV['REACH_PUB_MOCK']);
        $_ENV['CI_ENVIRONMENT'] = 'development';
        $publisher = PublicSitePublisherFactory::make();
        // development env uses the real publisher (not mock); just confirm it implements interface
        $this->assertInstanceOf(
            \App\Libraries\Publishing\Connector\PublicSitePublisherInterface::class,
            $publisher
        );
    }

    public function testMockEnvFlagFalseInTestingStillReturnsMock(): void
    {
        $_ENV['REACH_PUB_MOCK'] = 'false';
        $_ENV['CI_ENVIRONMENT'] = 'testing';
        $publisher = PublicSitePublisherFactory::make();
        // CI_ENVIRONMENT=testing always uses mock regardless of REACH_PUB_MOCK=false
        $this->assertInstanceOf(MockPublicSitePublisher::class, $publisher);
    }
}
