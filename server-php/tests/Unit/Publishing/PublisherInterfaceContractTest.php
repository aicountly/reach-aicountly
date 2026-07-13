<?php

namespace Tests\Unit\Publishing;

use App\Libraries\Publishing\Connector\MockPublicSitePublisher;
use App\Libraries\Publishing\Connector\PublicSitePublisherInterface;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Contract tests verifying MockPublicSitePublisher implements the full PublicSitePublisherInterface.
 *
 * @covers \App\Libraries\Publishing\Connector\MockPublicSitePublisher
 */
class PublisherInterfaceContractTest extends CIUnitTestCase
{
    private MockPublicSitePublisher $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->publisher = new MockPublicSitePublisher();
    }

    public function testMockImplementsInterface(): void
    {
        $this->assertInstanceOf(PublicSitePublisherInterface::class, $this->publisher);
    }

    public function testCreateDraftSignatureAcceptsArray(): void
    {
        $result = $this->publisher->createDraft(['reach_content_version_number' => 1, 'payload_checksum' => 'c', 'request_id' => 'r', 'idempotency_key' => 'k']);
        $this->assertIsArray($result);
    }

    public function testUpdateDraftSignatureAcceptsIntAndArray(): void
    {
        $d = $this->publisher->createDraft(['reach_content_version_number' => 1, 'payload_checksum' => 'c', 'request_id' => 'r', 'idempotency_key' => 'k']);
        $result = $this->publisher->updateDraft($d['public_content_id'], []);
        $this->assertIsArray($result);
    }

    public function testPublishSignatureAcceptsIntAndArray(): void
    {
        $d = $this->publisher->createDraft(['reach_content_version_number' => 1, 'payload_checksum' => 'c', 'request_id' => 'r', 'idempotency_key' => 'k']);
        $result = $this->publisher->publish($d['public_content_id'], []);
        $this->assertIsArray($result);
    }

    public function testScheduleSignatureAcceptsIntArrayAndString(): void
    {
        $d = $this->publisher->createDraft(['reach_content_version_number' => 1, 'payload_checksum' => 'c', 'request_id' => 'r', 'idempotency_key' => 'k']);
        $result = $this->publisher->schedule($d['public_content_id'], [], '2027-01-01T00:00:00Z');
        $this->assertIsArray($result);
    }

    public function testUnpublishSignatureAcceptsIntAndString(): void
    {
        $d = $this->publisher->createDraft(['reach_content_version_number' => 1, 'payload_checksum' => 'c', 'request_id' => 'r', 'idempotency_key' => 'k']);
        $result = $this->publisher->unpublish($d['public_content_id'], 'Test reason');
        $this->assertIsArray($result);
    }

    public function testRestoreSignatureAcceptsIntAndArray(): void
    {
        $d = $this->publisher->createDraft(['reach_content_version_number' => 1, 'payload_checksum' => 'c', 'request_id' => 'r', 'idempotency_key' => 'k']);
        $result = $this->publisher->restore($d['public_content_id'], []);
        $this->assertIsArray($result);
    }

    public function testGetStatusSignatureAcceptsInt(): void
    {
        $d = $this->publisher->createDraft(['reach_content_version_number' => 1, 'payload_checksum' => 'c', 'request_id' => 'r', 'idempotency_key' => 'k']);
        $result = $this->publisher->getStatus($d['public_content_id']);
        $this->assertIsArray($result);
    }

    public function testGetVerificationSignatureAcceptsInt(): void
    {
        $d = $this->publisher->createDraft(['reach_content_version_number' => 1, 'payload_checksum' => 'c', 'request_id' => 'r', 'idempotency_key' => 'k']);
        $result = $this->publisher->getVerification($d['public_content_id']);
        $this->assertIsArray($result);
    }

    public function testHealthCheckReturnsBool(): void
    {
        $result = $this->publisher->healthCheck();
        $this->assertIsBool($result);
    }

    public function testAllResponsesHaveSuccessKey(): void
    {
        $d = $this->publisher->createDraft(['reach_content_version_number' => 1, 'payload_checksum' => 'c', 'request_id' => 'r', 'idempotency_key' => 'k']);
        $id = $d['public_content_id'];

        foreach ([
            $this->publisher->updateDraft($id, []),
            $this->publisher->publish($id, []),
            $this->publisher->getVerification($id),
            $this->publisher->unpublish($id, 'reason'),
        ] as $result) {
            $this->assertArrayHasKey('success', $result);
        }
    }
}
