<?php

namespace Tests\Unit\Publishing;

use App\Libraries\Publishing\Seo\CanonicalUrlPolicy;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @covers \App\Libraries\Publishing\Seo\CanonicalUrlPolicy
 */
class CanonicalUrlPolicyTest extends CIUnitTestCase
{
    private CanonicalUrlPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new CanonicalUrlPolicy('https://aicountly.com');
    }

    public function testSelfCanonicalBuildsCorrectUrl(): void
    {
        $url = $this->policy->resolve('blog', 'my-blog-post', 'self_canonical');
        $this->assertSame('https://aicountly.com/blog/my-blog-post', $url);
    }

    public function testKnowledgeBaseUsesHelpPath(): void
    {
        $url = $this->policy->resolve('knowledge_base', 'setup-guide', 'self_canonical');
        $this->assertSame('https://aicountly.com/help/setup-guide', $url);
    }

    public function testCanonicalToExistingUsesProvidedUrl(): void
    {
        $existing = 'https://aicountly.com/blog/canonical-post';
        $url = $this->policy->resolve('blog', 'new-slug', 'canonical_to_existing', $existing);
        $this->assertSame($existing, $url);
    }

    public function testCanonicalToExistingWithoutUrlThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->policy->resolve('blog', 'slug', 'canonical_to_existing');
    }

    public function testNoindexResolvesToSelfPath(): void
    {
        $url = $this->policy->resolve('blog', 'hidden-post', 'noindex');
        $this->assertSame('https://aicountly.com/blog/hidden-post', $url);
    }

    public function testUnknownPreferenceThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->policy->resolve('blog', 'slug', 'unknown_preference');
    }

    public function testRequiresRedirectWhenSlugChanges(): void
    {
        $this->assertTrue($this->policy->requiresRedirect('old-slug', 'new-slug'));
    }

    public function testNoRedirectRequiredWhenSlugUnchanged(): void
    {
        $this->assertFalse($this->policy->requiresRedirect('same-slug', 'same-slug'));
    }

    public function testNoRedirectRequiredWhenOldSlugIsEmpty(): void
    {
        $this->assertFalse($this->policy->requiresRedirect('', 'new-slug'));
    }

    /** @dataProvider validSlugProvider */
    public function testValidSlugAccepted(string $slug): void
    {
        $this->assertTrue($this->policy->isValidSlug($slug));
    }

    public static function validSlugProvider(): array
    {
        return [
            ['valid-slug'],
            ['slug123'],
            ['a-b-c-d'],
            ['singleword'],
        ];
    }

    /** @dataProvider invalidSlugProvider */
    public function testInvalidSlugRejected(string $slug): void
    {
        $this->assertFalse($this->policy->isValidSlug($slug));
    }

    public static function invalidSlugProvider(): array
    {
        return [
            [''],
            ['UPPERCASE'],
            ['has spaces'],
            ['has_underscore'],
            ['has.dot'],
            ['-starts-with-hyphen'],
        ];
    }
}
