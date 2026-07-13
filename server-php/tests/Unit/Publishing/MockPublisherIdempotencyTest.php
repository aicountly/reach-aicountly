<?php

namespace Tests\Unit\Publishing;

use App\Libraries\Publishing\Connector\MockPublicSitePublisher;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Tests for MockPublicSitePublisher idempotency and call recording behavior.
 *
 * @covers \App\Libraries\Publishing\Connector\MockPublicSitePublisher
 */
class MockPublisherIdempotencyTest extends CIUnitTestCase
{
    private MockPublicSitePublisher $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->publisher = new MockPublicSitePublisher();
    }

    public function testCallsAreRecordedInOrder(): void
    {
        $this->publisher->createDraft($this->e());
        $this->publisher->createDraft($this->e());
        $this->publisher->healthCheck();

        $calls = array_column($this->publisher->getCalls(), 'method');
        $this->assertSame(['createDraft', 'createDraft', 'healthCheck'], $calls);
    }

    public function testCallTimestampsArePresent(): void
    {
        $this->publisher->createDraft($this->e());
        $calls = $this->publisher->getCalls();
        $this->assertArrayHasKey('at', $calls[0]);
        $this->assertGreaterThan(0, $calls[0]['at']);
    }

    public function testResetClearsCallsAndIdCounter(): void
    {
        $d1 = $this->publisher->createDraft($this->e());
        $this->publisher->reset();
        $d2 = $this->publisher->createDraft($this->e());

        $this->assertSame(1, $d1['public_content_id']);
        $this->assertSame(1, $d2['public_content_id']);
        $this->assertCount(1, $this->publisher->getCalls());
    }

    public function testForceErrorReplacesSuccessWithErrorResponse(): void
    {
        $this->publisher->forceError('rate_limited');
        $result = $this->publisher->publish(1, $this->e());

        $this->assertFalse($result['success']);
        $this->assertSame('rate_limited', $result['error_category']);
        $this->assertArrayHasKey('safe_error_message', $result);
    }

    public function testMultipleMethodsRecordedWithArgs(): void
    {
        $env1 = array_merge($this->e(), ['title' => 'Article One']);
        $env2 = array_merge($this->e(), ['title' => 'Article Two']);

        $this->publisher->createDraft($env1);
        $this->publisher->createDraft($env2);

        $calls = $this->publisher->getCalls();
        $this->assertSame('createDraft', $calls[0]['method']);
        $this->assertSame('createDraft', $calls[1]['method']);
    }

    public function testContentIdIncrementsCrossMultipleCalls(): void
    {
        $r1 = $this->publisher->createDraft($this->e());
        $r2 = $this->publisher->createDraft($this->e());
        $r3 = $this->publisher->createDraft($this->e());

        $this->assertSame(1, $r1['public_content_id']);
        $this->assertSame(2, $r2['public_content_id']);
        $this->assertSame(3, $r3['public_content_id']);
    }

    public function testHealthCheckCallIsAlsoRecorded(): void
    {
        $this->publisher->healthCheck();
        $calls = array_column($this->publisher->getCalls(), 'method');
        $this->assertContains('healthCheck', $calls);
    }

    private function e(): array
    {
        return [
            'reach_content_version_number' => 1,
            'payload_checksum'             => 'chk-' . uniqid(),
            'request_id'                   => 'req-' . uniqid(),
            'idempotency_key'              => 'ik-' . uniqid(),
        ];
    }
}
