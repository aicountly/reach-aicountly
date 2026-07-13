<?php

namespace Tests\Feature\Publishing;

use App\Libraries\Publishing\Seo\CanonicalUrlPolicy;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Integration tests for CanonicalUrlPolicy, verifying URL format and redirect logic.
 *
 * @group publishing
 */
class CanonicalUrlPolicyIntegrationTest extends CIUnitTestCase
{
    private CanonicalUrlPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new CanonicalUrlPolicy('https://aicountly.com');
    }

    public function testFullBlogUrlStructure(): void
    {
        $url = $this->policy->resolve('blog', 'how-to-use-banking-module', 'self_canonical');
        $this->assertSame('https://aicountly.com/blog/how-to-use-banking-module', $url);
        $this->assertStringStartsWith('https://', $url);
    }

    public function testFullKbUrlStructure(): void
    {
        $url = $this->policy->resolve('knowledge_base', 'bank-reconciliation-setup', 'self_canonical');
        $this->assertSame('https://aicountly.com/help/bank-reconciliation-setup', $url);
    }

    public function testCanonicalToExistingPreservesExactUrl(): void
    {
        $existing = 'https://aicountly.com/blog/original-article-slug';
        $url      = $this->policy->resolve('blog', 'new-slug', 'canonical_to_existing', $existing);
        $this->assertSame($existing, $url);
    }

    public function testNoIndexContentStillHasUrl(): void
    {
        $url = $this->policy->resolve('blog', 'draft-preview', 'noindex');
        $this->assertStringContainsString('draft-preview', $url);
        $this->assertStringStartsWith('https://aicountly.com', $url);
    }

    public function testRedirectToExistingReturnsTarget(): void
    {
        $target = 'https://aicountly.com/blog/primary-post';
        $url    = $this->policy->resolve('blog', 'old-slug', 'redirect_to_existing', $target);
        $this->assertSame($target, $url);
    }

    public function testSlugChangeAlwaysRequiresRedirect(): void
    {
        $this->assertTrue($this->policy->requiresRedirect('old-guide', 'new-guide'));
        $this->assertTrue($this->policy->requiresRedirect('setup-v1', 'setup-v2'));
    }

    public function testSlugValidationCoverage(): void
    {
        $valid = ['ai-accounting', 'bank-reconciliation', 'gst-setup', 'tds'];
        foreach ($valid as $slug) {
            $this->assertTrue($this->policy->isValidSlug($slug), "{$slug} should be valid");
        }

        $invalid = ['AI-Accounting', 'bank reconciliation', 'gst_setup', '-leading-hyphen', ''];
        foreach ($invalid as $slug) {
            $this->assertFalse($this->policy->isValidSlug($slug), "{$slug} should be invalid");
        }
    }

    public function testUrlDoesNotHaveDoubleSlash(): void
    {
        $url = $this->policy->resolve('blog', 'test-post', 'self_canonical');
        $this->assertStringNotContainsString('//blog', $url);
        $this->assertStringNotContainsString('com//', $url);
    }

    public function testKbUrlDoesNotHaveBlogPath(): void
    {
        $url = $this->policy->resolve('knowledge_base', 'kb-article', 'self_canonical');
        $this->assertStringNotContainsString('/blog/', $url);
        $this->assertStringContainsString('/help/', $url);
    }

    public function testHistoricalArchiveUrlIsCorrect(): void
    {
        $url = $this->policy->resolve('blog', 'archived-post', 'historical_archive');
        $this->assertStringContainsString('archived-post', $url);
        $this->assertStringStartsWith('https://aicountly.com', $url);
    }
}
