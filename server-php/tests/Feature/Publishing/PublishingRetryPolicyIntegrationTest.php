<?php

namespace Tests\Feature\Publishing;

use App\Libraries\Publishing\Connector\MockPublicSitePublisher;
use App\Libraries\Publishing\Connector\PublishingErrorClassifier;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Integration tests for retry policy with exponential backoff simulation.
 *
 * @group publishing
 */
class PublishingRetryPolicyIntegrationTest extends CIUnitTestCase
{
    private MockPublicSitePublisher $publisher;
    private PublishingErrorClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->publisher  = new MockPublicSitePublisher();
        $this->classifier = new PublishingErrorClassifier();
    }

    public function testRetryAfterRateLimitError(): void
    {
        // Simulate rate-limited then successful
        $this->publisher->forceError('rate_limited');
        $attempt1 = $this->publisher->createDraft($this->env());

        $this->assertFalse($attempt1['success']);
        $this->assertTrue($this->classifier->isRetryable('rate_limited'));

        $this->publisher->reset();
        $attempt2 = $this->publisher->createDraft($this->env());
        $this->assertTrue($attempt2['success']);
    }

    public function testMaxRetryAttempts(): void
    {
        // After max retries, should fail permanently
        $attempts = 0;
        $maxAttempts = 5;
        $succeeded = false;

        $this->publisher->forceError('server_error');

        while ($attempts < $maxAttempts && !$succeeded) {
            $result = $this->publisher->createDraft($this->env());
            if ($result['success']) {
                $succeeded = true;
            }
            $attempts++;
        }

        $this->assertFalse($succeeded, 'Should not succeed when publisher keeps returning server_error');
        $this->assertSame($maxAttempts, $attempts);
    }

    public function testTerminalErrorDoesNotRetry(): void
    {
        $terminalErrors = ['authentication_error', 'validation_error', 'not_found', 'publication_blocked'];

        foreach ($terminalErrors as $errorCategory) {
            $this->assertFalse(
                $this->classifier->isRetryable($errorCategory),
                "{$errorCategory} should be terminal"
            );
        }
    }

    public function testBackoffCategoriesAllCovered(): void
    {
        $retryable = ['rate_limited', 'timeout', 'network_error', 'server_error'];
        foreach ($retryable as $cat) {
            $this->assertTrue($this->classifier->isRetryable($cat));
        }
    }

    public function testSuccessAfterRecovery(): void
    {
        // First 2 fail, then succeed
        $attempts = 0;
        $maxFails = 2;

        for ($i = 0; $i < $maxFails; $i++) {
            $this->publisher->forceError('server_error');
            $r = $this->publisher->createDraft($this->env());
            $this->assertFalse($r['success']);
        }

        $this->publisher->reset();
        $r = $this->publisher->createDraft($this->env());
        $this->assertTrue($r['success']);
    }

    private function env(): array
    {
        return ['reach_content_version_number' => 1, 'payload_checksum' => 'c', 'request_id' => 'r', 'idempotency_key' => 'k'];
    }
}
