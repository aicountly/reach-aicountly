<?php

namespace Tests\Feature\Publishing;

use App\Libraries\Publishing\Connector\MockPublicSitePublisher;
use App\Libraries\Publishing\Connector\PublishingErrorClassifier;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Integration tests for various error scenarios with MockPublicSitePublisher.
 *
 * @group publishing
 */
class MockPublisherErrorScenariosTest extends CIUnitTestCase
{
    private MockPublicSitePublisher $publisher;
    private PublishingErrorClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->publisher  = new MockPublicSitePublisher();
        $this->classifier = new PublishingErrorClassifier();
    }

    /** @dataProvider errorCategoryProvider */
    public function testForcedErrorsReturnCorrectCategory(string $category): void
    {
        $this->publisher->forceError($category);
        $result = $this->publisher->createDraft($this->env());

        $this->assertFalse($result['success']);
        $this->assertSame($category, $result['error_category']);
    }

    public static function errorCategoryProvider(): array
    {
        return [
            ['server_error'],
            ['rate_limited'],
            ['timeout'],
            ['network_error'],
            ['authentication_error'],
            ['validation_error'],
            ['not_found'],
        ];
    }

    public function testRetryableErrorAllowsRetry(): void
    {
        $this->publisher->forceError('server_error');
        $r = $this->publisher->createDraft($this->env());
        $this->assertTrue($this->classifier->isRetryable($r['error_category']));
    }

    public function testNonRetryableErrorBlocksRetry(): void
    {
        $this->publisher->forceError('authentication_error');
        $r = $this->publisher->createDraft($this->env());
        $this->assertFalse($this->classifier->isRetryable($r['error_category']));
    }

    public function testErrorResponseHasSafeMessage(): void
    {
        $this->publisher->forceError('server_error');
        $r = $this->publisher->createDraft($this->env());
        $this->assertArrayHasKey('safe_error_message', $r);
        $this->assertNotEmpty($r['safe_error_message']);
    }

    public function testCallsAreRecordedEvenOnError(): void
    {
        $this->publisher->forceError('timeout');
        $this->publisher->createDraft($this->env());
        $this->publisher->publish(1, $this->env());

        $this->assertCount(2, $this->publisher->getCalls());
    }

    public function testResetClearsForceError(): void
    {
        $this->publisher->forceError('server_error');
        $this->publisher->reset();

        $r = $this->publisher->createDraft($this->env());
        $this->assertTrue($r['success']);
    }

    public function testAllOperationsFailUnderForceError(): void
    {
        $this->publisher->forceError('server_error');
        $d = $this->publisher->createDraft($this->env()); // This records but returns error

        // Even operations that don't go through forceError check: let's reset and do a real check
        $this->publisher->reset();
        $this->publisher->forceError('server_error');

        $ops = [
            $this->publisher->createDraft($this->env()),
            $this->publisher->updateDraft(1, $this->env()),
            $this->publisher->publish(1, $this->env()),
            $this->publisher->schedule(1, $this->env(), '2026-01-01T00:00:00Z'),
            $this->publisher->unpublish(1, 'reason'),
            $this->publisher->restore(1, $this->env()),
        ];

        foreach ($ops as $op) {
            $this->assertFalse($op['success'], 'All ops should fail under forceError');
        }
    }

    private function env(): array
    {
        return [
            'reach_content_version_number' => 1,
            'payload_checksum' => 'c', 'request_id' => 'r', 'idempotency_key' => 'k',
        ];
    }
}
