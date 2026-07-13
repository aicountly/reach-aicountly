<?php

namespace Tests\Feature\Publishing;

use App\Libraries\Publishing\Connector\MockPublicSitePublisher;
use App\Libraries\Publishing\Connector\PublishingErrorClassifier;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * End-to-end workflow tests using MockPublicSitePublisher.
 *
 * @group publishing
 */
class MockPublisherWorkflowTest extends CIUnitTestCase
{
    private MockPublicSitePublisher $publisher;
    private PublishingErrorClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->publisher  = new MockPublicSitePublisher();
        $this->classifier = new PublishingErrorClassifier();
    }

    public function testCompletePublishLifecycle(): void
    {
        // 1. Create draft
        $draft = $this->publisher->createDraft($this->envelope('blog-post'));
        $this->assertTrue($draft['success']);
        $this->assertSame('draft', $draft['public_status']);
        $id = $draft['public_content_id'];

        // 2. Update draft
        $update = $this->publisher->updateDraft($id, $this->envelope('blog-post-v2'));
        $this->assertTrue($update['success']);

        // 3. Publish
        $pub = $this->publisher->publish($id, $this->envelope('blog-post-v2'));
        $this->assertTrue($pub['success']);
        $this->assertSame('published', $pub['public_status']);
        $this->assertNotEmpty($pub['canonical_url']);

        // 4. Verify
        $ver = $this->publisher->getVerification($id);
        $this->assertSame('included', $ver['sitemap_status']);
        $this->assertSame('index,follow', $ver['robots_directive']);
    }

    public function testKbPublishLifecycle(): void
    {
        $draft = $this->publisher->createDraft([
            'reach_content_version_number' => 1,
            'payload_checksum'             => 'kb-checksum',
            'request_id'                   => 'kb-req-1',
            'idempotency_key'              => 'kb-ikey-1',
            'content_type'                 => 'knowledge_base',
        ]);

        $this->assertTrue($draft['success']);
        $pub = $this->publisher->publish($draft['public_content_id'], []);
        $this->assertSame('published', $pub['public_status']);
    }

    public function testRetryAfterTransientError(): void
    {
        // First attempt fails
        $this->publisher->forceError('server_error');
        $attempt1 = $this->publisher->createDraft($this->envelope('test'));
        $this->assertFalse($attempt1['success']);
        $this->assertTrue($this->classifier->isRetryable($attempt1['error_category']));

        // Reset and retry succeeds
        $this->publisher->reset();
        $attempt2 = $this->publisher->createDraft($this->envelope('test'));
        $this->assertTrue($attempt2['success']);
    }

    public function testNonRetryableErrorIsNotRetried(): void
    {
        $this->publisher->forceError('validation_error');
        $result = $this->publisher->createDraft($this->envelope('test'));
        $this->assertFalse($result['success']);
        $this->assertFalse($this->classifier->isRetryable($result['error_category']));
    }

    public function testVerificationReturnsRequiredChecks(): void
    {
        $d = $this->publisher->createDraft($this->envelope('verify-test'));
        $v = $this->publisher->getVerification($d['public_content_id']);

        $required = [
            'public_status', 'canonical_url', 'public_version',
            'payload_checksum', 'title', 'body_hash',
            'structured_data_types', 'sitemap_status', 'robots_directive',
        ];

        foreach ($required as $key) {
            $this->assertArrayHasKey($key, $v, "Missing verification key: {$key}");
        }
    }

    public function testRollbackAfterPublish(): void
    {
        $d = $this->publisher->createDraft($this->envelope('rollback-test'));
        $this->publisher->publish($d['public_content_id'], []);
        $r = $this->publisher->unpublish($d['public_content_id'], 'Reverting due to error');
        $this->assertTrue($r['success']);
        $this->assertSame('draft', $r['public_status']);
    }

    public function testScheduledPublicationCanBeFutureDate(): void
    {
        $d    = $this->publisher->createDraft($this->envelope('scheduled-test'));
        $when = '2027-01-01T09:00:00Z';
        $s    = $this->publisher->schedule($d['public_content_id'], [], $when);

        $this->assertTrue($s['success']);
        $this->assertSame('scheduled', $s['public_status']);
        $this->assertSame($when, $s['scheduled_at']);
    }

    public function testMultiplePublicationsGetUniqueIds(): void
    {
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $d     = $this->publisher->createDraft($this->envelope("content-{$i}"));
            $ids[] = $d['public_content_id'];
        }

        $this->assertCount(5, array_unique($ids), 'All content IDs must be unique');
    }

    public function testGetStatusReturnsExpectedStructure(): void
    {
        $d    = $this->publisher->createDraft($this->envelope('status-test'));
        $stat = $this->publisher->getStatus($d['public_content_id']);

        $this->assertTrue($stat['success']);
        $this->assertArrayHasKey('public_status', $stat);
        $this->assertArrayHasKey('public_content_id', $stat);
    }

    private function envelope(string $contentType = 'blog'): array
    {
        return [
            'reach_content_version_number' => 1,
            'payload_checksum'             => 'checksum-' . md5($contentType),
            'request_id'                   => 'req-' . uniqid(),
            'idempotency_key'              => 'ikey-' . uniqid(),
            'content_type'                 => $contentType,
        ];
    }
}
