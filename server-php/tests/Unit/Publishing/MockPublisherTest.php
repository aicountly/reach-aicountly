<?php

namespace Tests\Unit\Publishing;

use App\Libraries\Publishing\Connector\MockPublicSitePublisher;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @covers \App\Libraries\Publishing\Connector\MockPublicSitePublisher
 */
class MockPublisherTest extends CIUnitTestCase
{
    private MockPublicSitePublisher $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->publisher = new MockPublicSitePublisher();
    }

    public function testCreateDraftReturnsSuccessWithId(): void
    {
        $result = $this->publisher->createDraft($this->envelope());

        $this->assertTrue($result['success']);
        $this->assertSame('create_draft', $result['operation']);
        $this->assertIsInt($result['public_content_id']);
        $this->assertSame('draft', $result['public_status']);
        $this->assertFalse($result['idempotent_replay']);
    }

    public function testMultipleDraftCreationsGetIncrementingIds(): void
    {
        $r1 = $this->publisher->createDraft($this->envelope());
        $r2 = $this->publisher->createDraft($this->envelope());

        $this->assertSame(1, $r1['public_content_id']);
        $this->assertSame(2, $r2['public_content_id']);
    }

    public function testUpdateDraftReturnsSuccess(): void
    {
        $create = $this->publisher->createDraft($this->envelope());
        $result = $this->publisher->updateDraft($create['public_content_id'], $this->envelope());

        $this->assertTrue($result['success']);
        $this->assertSame('update_draft', $result['operation']);
    }

    public function testPublishReturnsPublishedStatus(): void
    {
        $create = $this->publisher->createDraft($this->envelope());
        $result = $this->publisher->publish($create['public_content_id'], $this->envelope());

        $this->assertTrue($result['success']);
        $this->assertSame('published', $result['public_status']);
        $this->assertNotEmpty($result['canonical_url']);
    }

    public function testScheduleReturnsScheduledStatus(): void
    {
        $create = $this->publisher->createDraft($this->envelope());
        $result = $this->publisher->schedule($create['public_content_id'], $this->envelope(), '2026-08-01T10:00:00Z');

        $this->assertTrue($result['success']);
        $this->assertSame('scheduled', $result['public_status']);
        $this->assertSame('2026-08-01T10:00:00Z', $result['scheduled_at']);
    }

    public function testUnpublishReturnsDraftStatus(): void
    {
        $create = $this->publisher->createDraft($this->envelope());
        $this->publisher->publish($create['public_content_id'], $this->envelope());
        $result = $this->publisher->unpublish($create['public_content_id'], 'Testing rollback');

        $this->assertTrue($result['success']);
        $this->assertSame('draft', $result['public_status']);
    }

    public function testGetVerificationReturnsAllRequiredFields(): void
    {
        $create = $this->publisher->createDraft($this->envelope());
        $result = $this->publisher->getVerification($create['public_content_id']);

        $requiredFields = ['success','operation','public_content_id','public_status','canonical_url',
                           'public_version','payload_checksum','reach_content_version',
                           'title','body_hash','structured_data_types','sitemap_status',
                           'robots_directive','updated_at'];

        foreach ($requiredFields as $field) {
            $this->assertArrayHasKey($field, $result, "Missing field: {$field}");
        }
    }

    public function testHealthCheckReturnsTrue(): void
    {
        $this->assertTrue($this->publisher->healthCheck());
    }

    public function testForceErrorReturnsFailure(): void
    {
        $this->publisher->forceError('network_error');
        $result = $this->publisher->createDraft($this->envelope());

        $this->assertFalse($result['success']);
        $this->assertSame('network_error', $result['error_category']);
    }

    public function testCallsAreRecorded(): void
    {
        $this->publisher->createDraft($this->envelope());
        $this->publisher->createDraft($this->envelope());

        $calls = $this->publisher->getCalls();
        $this->assertCount(2, $calls);
        $this->assertSame('createDraft', $calls[0]['method']);
    }

    public function testResetClearsState(): void
    {
        $this->publisher->createDraft($this->envelope());
        $this->publisher->reset();

        $this->assertEmpty($this->publisher->getCalls());
        $result = $this->publisher->createDraft($this->envelope());
        $this->assertSame(1, $result['public_content_id']);
    }

    private function envelope(): array
    {
        return [
            'reach_content_version_number' => 1,
            'payload_checksum'             => 'test-checksum',
            'request_id'                   => 'test-req',
            'idempotency_key'              => 'test-ikey',
        ];
    }
}
