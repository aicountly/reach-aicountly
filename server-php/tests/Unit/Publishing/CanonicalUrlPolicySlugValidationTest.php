<?php

namespace Tests\Unit\Publishing;

use App\Libraries\Publishing\Seo\CanonicalUrlPolicy;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Comprehensive slug validation tests for CanonicalUrlPolicy.
 *
 * @covers \App\Libraries\Publishing\Seo\CanonicalUrlPolicy
 */
class CanonicalUrlPolicySlugValidationTest extends CIUnitTestCase
{
    private CanonicalUrlPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new CanonicalUrlPolicy('https://aicountly.com');
    }

    /** @dataProvider validSlugsProvider */
    public function testValidSlugsAreAccepted(string $slug): void
    {
        $this->assertTrue($this->policy->isValidSlug($slug), "Slug '{$slug}' should be valid");
    }

    public static function validSlugsProvider(): array
    {
        return [
            ['how-to-use-banking-module'],
            ['bank-reconciliation-setup'],
            ['gst-filing-guide'],
            ['tds-management'],
            ['ai-powered-accounting'],
            ['2024-annual-report'],
            ['q1-performance-review'],
            ['a'],
            ['abc'],
            ['a1b2c3'],
        ];
    }

    /** @dataProvider invalidSlugsProvider */
    public function testInvalidSlugsAreRejected(string $slug): void
    {
        $this->assertFalse($this->policy->isValidSlug($slug), "Slug '{$slug}' should be invalid");
    }

    public static function invalidSlugsProvider(): array
    {
        return [
            [''],
            ['UPPERCASE'],
            ['has spaces'],
            ['has_underscore'],
            ['has.dot'],
            ['has@symbol'],
            ['-leading'],
            ['trailing-'],
            ['--double'],
            ['a--b'],
            ['/slash/in/slug'],
            ['hello world'],
        ];
    }

    public function testUrlBuiltForAllContentTypes(): void
    {
        $types    = ['blog', 'knowledge_base', 'other'];
        $slug     = 'test-slug';
        foreach ($types as $type) {
            $url = $this->policy->buildUrl($type, $slug);
            $this->assertStringContainsString($slug, $url);
            $this->assertStringStartsWith('https://aicountly.com/', $url);
        }
    }

    public function testRedirectRequiredOnSlugUpdate(): void
    {
        $testCases = [
            ['old-slug',   'new-slug',   true],
            ['same-slug',  'same-slug',  false],
            ['',           'new-slug',   false],
            ['old-slug',   '',           true],
        ];

        foreach ($testCases as [$old, $new, $expected]) {
            $result = $this->policy->requiresRedirect($old, $new);
            $this->assertSame($expected, $result, "requiresRedirect('{$old}', '{$new}') should be " . ($expected ? 'true' : 'false'));
        }
    }
}
