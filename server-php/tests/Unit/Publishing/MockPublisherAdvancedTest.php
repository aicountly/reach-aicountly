<?php

namespace Tests\Unit\Publishing;

use App\Libraries\Publishing\Connector\MockPublicSitePublisher;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Advanced tests for MockPublicSitePublisher.
 *
 * @covers \App\Libraries\Publishing\Connector\MockPublicSitePublisher
 */
class MockPublisherAdvancedTest extends CIUnitTestCase
{
    private MockPublicSitePublisher $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->publisher = new MockPublicSitePublisher();
    }

    public function testRestoreReturnsPublishedStatus(): void
    {
        $draft   = $this->publisher->createDraft($this->envelope());
        $result  = $this->publisher->restore($draft['public_content_id'], $this->envelope());

        $this->assertTrue($result['success']);
        $this->assertSame('published', $result['public_status']);
    }

    public function testGetStatusReturnsMockedPublishedStatus(): void
    {
        $draft  = $this->publisher->createDraft($this->envelope());
        $status = $this->publisher->getStatus($draft['public_content_id']);

        $this->assertTrue($status['success']);
        $this->assertSame('published', $status['public_status']);
    }

    public function testTriggerVerificationDelegatesToGetVerification(): void
    {
        $draft = $this->publisher->createDraft($this->envelope());
        $v1    = $this->publisher->getVerification($draft['public_content_id']);
        $v2    = $this->publisher->triggerVerification($draft['public_content_id']);

        $this->assertSame($v1['public_status'], $v2['public_status']);
        $this->assertSame($v1['robots_directive'], $v2['robots_directive']);
    }

    public function testCallsRecordAllMethods(): void
    {
        $d = $this->publisher->createDraft($this->envelope());
        $this->publisher->updateDraft($d['public_content_id'], $this->envelope());
        $this->publisher->publish($d['public_content_id'], $this->envelope());
        $this->publisher->getVerification($d['public_content_id']);
        $this->publisher->unpublish($d['public_content_id'], 'test');

        $calls   = array_column($this->publisher->getCalls(), 'method');
        $this->assertContains('createDraft', $calls);
        $this->assertContains('updateDraft', $calls);
        $this->assertContains('publish', $calls);
        $this->assertContains('getVerification', $calls);
        $this->assertContains('unpublish', $calls);
    }

    public function testForceErrorThenResetAllowsSuccess(): void
    {
        $this->publisher->forceError('server_error');
        $fail = $this->publisher->createDraft($this->envelope());
        $this->assertFalse($fail['success']);

        $this->publisher->reset();
        $ok = $this->publisher->createDraft($this->envelope());
        $this->assertTrue($ok['success']);
    }

    public function testForceNullClearsError(): void
    {
        $this->publisher->forceError('timeout');
        $this->publisher->forceError(null);

        $result = $this->publisher->createDraft($this->envelope());
        $this->assertTrue($result['success']);
    }

    public function testPublicContentUuidIsPresent(): void
    {
        $result = $this->publisher->createDraft($this->envelope());
        $this->assertArrayHasKey('public_content_uuid', $result);
        $this->assertNotEmpty($result['public_content_uuid']);
    }

    public function testIdempotentReplayFlagDefaultsFalse(): void
    {
        $result = $this->publisher->createDraft($this->envelope());
        $this->assertFalse($result['idempotent_replay']);
    }

    public function testVerificationStructuredDataTypesIsArray(): void
    {
        $d = $this->publisher->createDraft($this->envelope());
        $v = $this->publisher->getVerification($d['public_content_id']);
        $this->assertIsArray($v['structured_data_types']);
    }

    public function testPublishCanonicalUrlContainsContentId(): void
    {
        $d = $this->publisher->createDraft($this->envelope());
        $p = $this->publisher->publish($d['public_content_id'], $this->envelope());
        $this->assertStringContainsString((string) $d['public_content_id'], $p['canonical_url']);
    }

    private function envelope(): array
    {
        return [
            'reach_content_version_number' => 1,
            'payload_checksum'             => 'checksum-xyz',
            'request_id'                   => 'req-' . uniqid(),
            'idempotency_key'              => 'ikey-' . uniqid(),
        ];
    }
}
