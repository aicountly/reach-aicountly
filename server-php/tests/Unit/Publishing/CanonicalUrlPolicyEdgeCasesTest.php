<?php

namespace Tests\Unit\Publishing;

use App\Libraries\Publishing\Seo\CanonicalUrlPolicy;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Edge-case tests for CanonicalUrlPolicy.
 *
 * @covers \App\Libraries\Publishing\Seo\CanonicalUrlPolicy
 */
class CanonicalUrlPolicyEdgeCasesTest extends CIUnitTestCase
{
    private CanonicalUrlPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new CanonicalUrlPolicy('https://aicountly.com');
    }

    public function testBuildUrlForBlog(): void
    {
        $url = $this->policy->buildUrl('blog', 'my-post');
        $this->assertSame('https://aicountly.com/blog/my-post', $url);
    }

    public function testBuildUrlForKnowledgeBase(): void
    {
        $url = $this->policy->buildUrl('knowledge_base', 'setup-guide');
        $this->assertSame('https://aicountly.com/help/setup-guide', $url);
    }

    public function testBuildUrlForUnknownTypeFallsBackToRoot(): void
    {
        $url = $this->policy->buildUrl('other', 'page');
        $this->assertStringStartsWith('https://aicountly.com/', $url);
    }

    public function testSlugLeadingSlashIsStripped(): void
    {
        $url = $this->policy->buildUrl('blog', '/my-post');
        $this->assertSame('https://aicountly.com/blog/my-post', $url);
    }

    public function testHistoricalArchivePreferenceReturnsUrl(): void
    {
        $url = $this->policy->resolve('blog', 'old-post', 'historical_archive');
        $this->assertSame('https://aicountly.com/blog/old-post', $url);
    }

    public function testRedirectToExistingReturnsTargetUrl(): void
    {
        $target = 'https://aicountly.com/blog/main-post';
        $url    = $this->policy->resolve('blog', 'redirect-slug', 'redirect_to_existing', $target);
        $this->assertSame($target, $url);
    }

    public function testRedirectToExistingWithoutUrlThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->policy->resolve('blog', 'slug', 'redirect_to_existing');
    }

    public function testBaseUrlTrailingSlashIsStripped(): void
    {
        $policy = new CanonicalUrlPolicy('https://aicountly.com/');
        $url    = $policy->buildUrl('blog', 'post');
        $this->assertSame('https://aicountly.com/blog/post', $url);
    }

    /** @dataProvider slugEdgeCases */
    public function testSlugEdgeCases(string $slug, bool $expected): void
    {
        $this->assertSame($expected, $this->policy->isValidSlug($slug));
    }

    public static function slugEdgeCases(): array
    {
        return [
            ['a', true],
            ['a1', true],
            ['1a', true],
            ['a-b', true],
            ['a-b-c', true],
            ['a-1-b', true],
            ['--double-hyphen', false],
            ['ends-with-hyphen-', false],
            [str_repeat('a', 100), true],
        ];
    }

    public function testRequiresRedirectWithNullOldSlug(): void
    {
        $this->assertFalse($this->policy->requiresRedirect('', 'new-slug'));
    }

    public function testRequiresRedirectWithSameSlug(): void
    {
        $this->assertFalse($this->policy->requiresRedirect('slug', 'slug'));
    }

    public function testRequiresRedirectWithDifferentSlug(): void
    {
        $this->assertTrue($this->policy->requiresRedirect('old', 'new'));
    }
}
