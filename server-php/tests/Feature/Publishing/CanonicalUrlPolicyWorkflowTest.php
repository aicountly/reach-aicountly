<?php

namespace Tests\Feature\Publishing;

use App\Libraries\Publishing\Seo\CanonicalUrlPolicy;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Workflow tests for CanonicalUrlPolicy in real publishing scenarios.
 *
 * @group publishing
 */
class CanonicalUrlPolicyWorkflowTest extends CIUnitTestCase
{
    private CanonicalUrlPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new CanonicalUrlPolicy('https://aicountly.com');
    }

    public function testBlogPostUrlFormat(): void
    {
        $url = $this->policy->resolve('blog', 'how-aicountly-automates-gst-filing', 'self_canonical');
        $this->assertSame('https://aicountly.com/blog/how-aicountly-automates-gst-filing', $url);
        $this->assertStringNotContainsString('/help/', $url);
    }

    public function testKbArticleUrlFormat(): void
    {
        $url = $this->policy->resolve('knowledge_base', 'bank-reconciliation-setup', 'self_canonical');
        $this->assertSame('https://aicountly.com/help/bank-reconciliation-setup', $url);
        $this->assertStringNotContainsString('/blog/', $url);
    }

    public function testSlugUpdateRequiresRedirect(): void
    {
        $oldSlug = 'gst-filing-guide-v1';
        $newSlug = 'gst-filing-guide';

        $this->assertTrue($this->policy->requiresRedirect($oldSlug, $newSlug));
    }

    public function testNewContentDoesNotRequireRedirect(): void
    {
        // No old slug (first publication)
        $this->assertFalse($this->policy->requiresRedirect('', 'new-article'));
    }

    public function testCanonicalToExistingForUpdatedContent(): void
    {
        $existingUrl = 'https://aicountly.com/blog/original-article-title';
        $url         = $this->policy->resolve('blog', 'new-article-slug', 'canonical_to_existing', $existingUrl);
        $this->assertSame($existingUrl, $url);
    }

    public function testNoIndexContentHasPathUrl(): void
    {
        $url = $this->policy->resolve('blog', 'internal-preview-post', 'noindex');
        $this->assertStringContainsString('internal-preview-post', $url);
    }

    public function testHistoricalArchivePreservesPath(): void
    {
        $url = $this->policy->resolve('blog', 'archived-2023-post', 'historical_archive');
        $this->assertStringContainsString('archived-2023-post', $url);
        $this->assertStringStartsWith('https://aicountly.com', $url);
    }

    public function testAllValidSlugsResolveToUrls(): void
    {
        $slugs = ['ai-accounting', 'gst-guide', 'tds-setup', 'bank-reconciliation'];
        foreach ($slugs as $slug) {
            $url = $this->policy->resolve('blog', $slug, 'self_canonical');
            $this->assertStringEndsWith("/{$slug}", $url);
        }
    }
}
