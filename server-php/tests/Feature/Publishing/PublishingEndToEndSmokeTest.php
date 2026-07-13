<?php

namespace Tests\Feature\Publishing;

use App\Libraries\Publishing\Connector\MockPublicSitePublisher;
use App\Libraries\Publishing\Connector\HmacSigner;
use App\Libraries\Publishing\Connector\PublishingErrorClassifier;
use App\Libraries\Publishing\Seo\StructuredDataBuilder;
use App\Libraries\Publishing\Seo\StructuredDataValidator;
use App\Libraries\Publishing\Seo\CanonicalUrlPolicy;
use App\Libraries\Publishing\Blog\BlogMetadataService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * End-to-end smoke tests exercising all Phase 4 components together.
 *
 * @group publishing
 */
class PublishingEndToEndSmokeTest extends CIUnitTestCase
{
    public function testFullBlogPublishingPipelineSmokeTest(): void
    {
        // 1. Generate structured data
        $builder   = new StructuredDataBuilder();
        $validator = new StructuredDataValidator();

        $schema = $builder->buildFAQPage([
            ['question' => 'What is bank reconciliation?', 'answer' => 'Matching your bank statement with your accounting records.'],
        ]);
        $validResult = $validator->validate($schema);
        $this->assertTrue($validResult['valid']);

        // 2. Build canonical URL
        $urlPolicy = new CanonicalUrlPolicy('https://aicountly.com');
        $canonical = $urlPolicy->resolve('blog', 'what-is-bank-reconciliation', 'self_canonical');
        $this->assertSame('https://aicountly.com/blog/what-is-bank-reconciliation', $canonical);

        // 3. Generate metadata
        $metadata = new BlogMetadataService();
        $excerpt  = $metadata->deriveExcerpt('<p>Bank reconciliation matches your bank statement with accounting records.</p>');
        $time     = $metadata->estimateReadingTime('<p>' . str_repeat('word ', 400) . '</p>');
        $this->assertNotEmpty($excerpt);
        $this->assertSame(2, $time);

        // 4. Sign the request
        $signer  = new HmacSigner();
        $body    = json_encode(['title' => 'What is Bank Reconciliation?', 'structured_data' => $schema]);
        $headers = $signer->buildAuthHeaders('POST', '/api/internal/reach/v1/content/drafts', $body, 'ikey', 'req', 'bt', 'sk', 'kid', 1);
        $this->assertArrayHasKey('X-Reach-Signature', $headers);

        // 5. Publish via mock
        $publisher = new MockPublicSitePublisher();
        $draft     = $publisher->createDraft(['reach_content_version_number' => 1, 'payload_checksum' => 'c', 'request_id' => 'r', 'idempotency_key' => 'k']);
        $pub       = $publisher->publish($draft['public_content_id'], []);
        $this->assertTrue($pub['success']);
        $this->assertSame('published', $pub['public_status']);

        // 6. Verify
        $ver = $publisher->getVerification($draft['public_content_id']);
        $this->assertTrue($ver['success']);
        $this->assertSame('included', $ver['sitemap_status']);
    }

    public function testFullKbPublishingPipelineSmokeTest(): void
    {
        $builder    = new StructuredDataBuilder();
        $validator  = new StructuredDataValidator();
        $urlPolicy  = new CanonicalUrlPolicy('https://aicountly.com');
        $publisher  = new MockPublicSitePublisher();
        $classifier = new PublishingErrorClassifier();

        // Build HowTo schema
        $steps  = [['title' => 'Step 1', 'description' => 'Enable GST in Settings']];
        $schema = $builder->buildHowTo('How to Enable GST', $steps);
        $this->assertTrue($validator->validate($schema)['valid']);

        // Canonical URL
        $canonical = $urlPolicy->resolve('knowledge_base', 'enable-gst', 'self_canonical');
        $this->assertStringContainsString('/help/', $canonical);

        // Publish
        $draft = $publisher->createDraft(['reach_content_version_number' => 1, 'payload_checksum' => 'c', 'request_id' => 'r', 'idempotency_key' => 'k']);
        $pub   = $publisher->publish($draft['public_content_id'], []);
        $this->assertTrue($pub['success']);

        // Error classification
        $this->assertTrue($classifier->isRetryable('server_error'));
        $this->assertFalse($classifier->isRetryable('authentication_error'));
    }
}
