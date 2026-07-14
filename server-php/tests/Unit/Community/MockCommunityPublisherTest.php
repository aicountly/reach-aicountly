<?php

namespace Tests\Unit\Community;

use App\Libraries\Community\MockCommunityPublisher;
use PHPUnit\Framework\TestCase;

final class MockCommunityPublisherTest extends TestCase
{
    private MockCommunityPublisher $publisher;

    protected function setUp(): void
    {
        $this->publisher = new MockCommunityPublisher();
    }

    public function testCreateAnswerRecordsCall(): void
    {
        $this->publisher->createAnswer(['reach_answer_uuid' => 'test-uuid']);
        $calls = $this->publisher->getCallsFor('createAnswer');
        $this->assertCount(1, $calls);
        $this->assertSame('test-uuid', $calls[0]['args'][0]['reach_answer_uuid']);
    }

    public function testPublishAnswerReturnsSuccessResponse(): void
    {
        $result = $this->publisher->publishAnswer('test-uuid', []);
        $this->assertTrue($result['success']);
        $this->assertSame('publish', $result['operation']);
    }

    public function testWithdrawAnswerRecordsCall(): void
    {
        $this->publisher->withdrawAnswer('test-uuid', ['reason' => 'outdated']);
        $calls = $this->publisher->getCallsFor('withdrawAnswer');
        $this->assertCount(1, $calls);
    }

    public function testRestoreAnswerReturnsSuccess(): void
    {
        $result = $this->publisher->restoreAnswer('test-uuid', []);
        $this->assertTrue($result['success']);
        $this->assertSame('restore', $result['operation']);
    }

    public function testSetErrorForCausesErrorResponse(): void
    {
        $this->publisher->setErrorFor('publishAnswer', 'server_error');
        $result = $this->publisher->publishAnswer('uuid', []);
        $this->assertFalse($result['success']);
        $this->assertSame('server_error', $result['error_category']);
    }

    public function testGetAnswerStatusReturnsAnswerData(): void
    {
        $this->publisher->createAnswer(['reach_answer_uuid' => 'uuid-1']);
        $status = $this->publisher->getAnswerStatus('uuid-1');
        $this->assertTrue($status['success']);
        $this->assertArrayHasKey('public_status', $status);
    }

    public function testCallCountTracking(): void
    {
        $this->publisher->createAnswer(['reach_answer_uuid' => 'a']);
        $this->publisher->createAnswer(['reach_answer_uuid' => 'b']);
        $this->assertCount(2, $this->publisher->getCallsFor('createAnswer'));
        $this->assertSame(2, $this->publisher->callCount());
    }

    public function testResetClearsCalls(): void
    {
        $this->publisher->createAnswer(['reach_answer_uuid' => 'x']);
        $this->publisher->reset();
        $this->assertCount(0, $this->publisher->getCallsFor('createAnswer'));
        $this->assertSame(0, $this->publisher->callCount());
    }

    public function testWasCalledWithReturnsTrueAfterCall(): void
    {
        $this->publisher->updateAnswer('uuid', []);
        $this->assertTrue($this->publisher->wasCalledWith('updateAnswer'));
    }

    public function testWasCalledWithReturnsFalseBeforeCall(): void
    {
        $this->assertFalse($this->publisher->wasCalledWith('updateAnswer'));
    }

    public function testHealthCheckReturnsTrue(): void
    {
        $this->assertTrue($this->publisher->healthCheck());
    }
}
