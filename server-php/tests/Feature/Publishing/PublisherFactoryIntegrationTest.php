<?php

namespace Tests\Feature\Publishing;

use App\Libraries\Publishing\Connector\MockPublicSitePublisher;
use App\Libraries\Publishing\Connector\PublicSitePublisherFactory;
use App\Libraries\Publishing\Connector\PublicSitePublisherInterface;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Integration tests for PublicSitePublisherFactory.
 *
 * @group publishing
 */
class PublisherFactoryIntegrationTest extends CIUnitTestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        unset($_ENV['REACH_PUB_MOCK'], $_ENV['CI_ENVIRONMENT']);
    }

    public function testTestingEnvironmentAlwaysReturnsMock(): void
    {
        $_ENV['REACH_PUB_MOCK'] = 'false';
        $_ENV['CI_ENVIRONMENT'] = 'testing';

        $publisher = PublicSitePublisherFactory::make();
        $this->assertInstanceOf(MockPublicSitePublisher::class, $publisher);
    }

    public function testReachPubMockTrueOverridesEnvironment(): void
    {
        $_ENV['REACH_PUB_MOCK'] = 'true';
        $_ENV['CI_ENVIRONMENT'] = 'production';

        $publisher = PublicSitePublisherFactory::make();
        $this->assertInstanceOf(MockPublicSitePublisher::class, $publisher);
    }

    public function testFactoryAlwaysReturnsInterface(): void
    {
        $_ENV['REACH_PUB_MOCK'] = 'true';
        $publisher = PublicSitePublisherFactory::make();
        $this->assertInstanceOf(PublicSitePublisherInterface::class, $publisher);
    }

    public function testMockFromFactoryIsFullyFunctional(): void
    {
        $_ENV['REACH_PUB_MOCK'] = 'true';
        $publisher = PublicSitePublisherFactory::make();

        $d = $publisher->createDraft([
            'reach_content_version_number' => 1,
            'payload_checksum' => 'chk', 'request_id' => 'req', 'idempotency_key' => 'k',
        ]);
        $this->assertTrue($d['success']);
        $this->assertSame('draft', $d['public_status']);
    }

    public function testMockHealthCheckReturnsTrue(): void
    {
        $_ENV['REACH_PUB_MOCK'] = 'true';
        $publisher = PublicSitePublisherFactory::make();
        $this->assertTrue($publisher->healthCheck());
    }
}
