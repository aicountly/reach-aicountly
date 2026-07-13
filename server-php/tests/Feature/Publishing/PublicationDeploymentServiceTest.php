<?php

namespace Tests\Feature\Publishing;

use App\Libraries\Publishing\Connector\MockPublicSitePublisher;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Feature tests for publication deployment service using MockPublicSitePublisher.
 *
 * All tests use MockPublicSitePublisher; no real DB or HTTP calls are made.
 *
 * @group publishing
 */
class PublicationDeploymentServiceTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['REACH_PUB_MOCK'] = 'true';
        $_ENV['CI_ENVIRONMENT'] = 'testing';
    }

    public function testMockPublisherIsUsedInTestEnvironment(): void
    {
        $factory   = \App\Libraries\Publishing\Connector\PublicSitePublisherFactory::make();
        $this->assertInstanceOf(MockPublicSitePublisher::class, $factory);
    }

    public function testMockPublisherCreateDraftReturnsSuccess(): void
    {
        $publisher = new MockPublicSitePublisher();
        $result    = $publisher->createDraft([
            'reach_content_version_number' => 1,
            'payload_checksum'             => 'checksum-abc',
            'request_id'                   => 'test-req',
            'idempotency_key'              => 'test-key',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('draft', $result['public_status']);
    }

    public function testMockPublisherVerificationPassesAllCriteria(): void
    {
        $publisher = new MockPublicSitePublisher();
        $result    = $publisher->createDraft([
            'reach_content_version_number' => 1,
            'payload_checksum'             => 'checksum',
            'request_id'                   => 'req',
            'idempotency_key'              => 'ikey',
        ]);

        $ver = $publisher->getVerification($result['public_content_id']);
        $this->assertTrue($ver['success']);
        $this->assertSame('published', $ver['public_status']);
        $this->assertSame('included', $ver['sitemap_status']);
        $this->assertNotEmpty($ver['canonical_url']);
    }

    public function testPublicationWorkflowCreatePublishVerify(): void
    {
        $publisher = new MockPublicSitePublisher();

        // Create
        $draft = $publisher->createDraft([
            'reach_content_version_number' => 1,
            'payload_checksum'             => 'checksum-abc',
            'request_id'                   => 'test-req',
            'idempotency_key'              => 'test-key-1',
        ]);

        $this->assertTrue($draft['success']);
        $this->assertSame('draft', $draft['public_status']);

        // Publish
        $published = $publisher->publish($draft['public_content_id'], []);
        $this->assertTrue($published['success']);
        $this->assertSame('published', $published['public_status']);
        $this->assertNotEmpty($published['canonical_url']);

        // Verify
        $verification = $publisher->triggerVerification($draft['public_content_id']);
        $this->assertTrue($verification['success']);
        $this->assertSame('published', $verification['public_status']);
    }

    public function testPublicationRollback(): void
    {
        $publisher = new MockPublicSitePublisher();

        $draft     = $publisher->createDraft([
            'reach_content_version_number' => 1,
            'payload_checksum' => 'checksum',
            'request_id' => 'req',
            'idempotency_key' => 'ikey',
        ]);
        $publisher->publish($draft['public_content_id'], []);

        $result = $publisher->unpublish($draft['public_content_id'], 'Testing rollback');
        $this->assertTrue($result['success']);
        $this->assertSame('draft', $result['public_status']);
    }

    public function testForcedErrorIsRetryable(): void
    {
        $publisher = new MockPublicSitePublisher();
        $publisher->forceError('server_error');

        $result = $publisher->createDraft([
            'reach_content_version_number' => 1,
            'payload_checksum' => 'c',
            'request_id' => 'r',
            'idempotency_key' => 'k',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('server_error', $result['error_category']);

        $classifier = new \App\Libraries\Publishing\Connector\PublishingErrorClassifier();
        $this->assertTrue($classifier->isRetryable('server_error'));
    }

    public function testForcedAuthErrorIsNotRetryable(): void
    {
        $publisher = new MockPublicSitePublisher();
        $publisher->forceError('authentication_error');

        $result = $publisher->createDraft([
            'reach_content_version_number' => 1,
            'payload_checksum' => 'c',
            'request_id' => 'r',
            'idempotency_key' => 'k',
        ]);

        $this->assertFalse($result['success']);

        $classifier = new \App\Libraries\Publishing\Connector\PublishingErrorClassifier();
        $this->assertFalse($classifier->isRetryable('authentication_error'));
    }

    public function testHealthCheckReturnsTrueFromMock(): void
    {
        $publisher = new MockPublicSitePublisher();
        $this->assertTrue($publisher->healthCheck());
    }

    public function testScheduleWorkflow(): void
    {
        $publisher = new MockPublicSitePublisher();
        $draft     = $publisher->createDraft([
            'reach_content_version_number' => 1,
            'payload_checksum' => 'c',
            'request_id' => 'r',
            'idempotency_key' => 'ik',
        ]);

        $result = $publisher->schedule($draft['public_content_id'], [], '2026-09-01T09:00:00Z');
        $this->assertTrue($result['success']);
        $this->assertSame('scheduled', $result['public_status']);
        $this->assertSame('2026-09-01T09:00:00Z', $result['scheduled_at']);
    }
}
