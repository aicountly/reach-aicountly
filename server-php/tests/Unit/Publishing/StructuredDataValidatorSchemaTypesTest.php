<?php

namespace Tests\Unit\Publishing;

use App\Libraries\Publishing\Seo\StructuredDataValidator;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Tests all allowed and disallowed schema types in StructuredDataValidator.
 *
 * @covers \App\Libraries\Publishing\Seo\StructuredDataValidator
 */
class StructuredDataValidatorSchemaTypesTest extends CIUnitTestCase
{
    private StructuredDataValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new StructuredDataValidator();
    }

    /** @dataProvider allowedTypes */
    public function testAllAllowedTypesPass(array $schema): void
    {
        $result = $this->validator->validate($schema);
        $this->assertTrue($result['valid'], json_encode($result['errors']));
    }

    public static function allowedTypes(): array
    {
        return [
            'Article' => [[
                '@context' => 'https://schema.org', '@type' => 'Article',
                'headline' => 'Test', 'author' => ['@type' => 'Person', 'name' => 'A'], 'datePublished' => '2026-01-01',
            ]],
            'BlogPosting' => [[
                '@context' => 'https://schema.org', '@type' => 'BlogPosting',
                'headline' => 'Test Blog', 'datePublished' => '2026-01-01',
            ]],
            'TechArticle' => [[
                '@context' => 'https://schema.org', '@type' => 'TechArticle',
                'headline' => 'Tech Guide', 'author' => ['@type' => 'Person', 'name' => 'B'], 'datePublished' => '2026-01-01',
            ]],
            'HowTo' => [[
                '@context' => 'https://schema.org', '@type' => 'HowTo',
                'name' => 'How To Guide',
                'step' => [['@type' => 'HowToStep', 'name' => 'Step 1', 'text' => 'Do it']],
            ]],
            'FAQPage' => [[
                '@context' => 'https://schema.org', '@type' => 'FAQPage',
                'mainEntity' => [['@type' => 'Question', 'name' => 'Q?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'A.']]],
            ]],
            'BreadcrumbList' => [[
                '@context' => 'https://schema.org', '@type' => 'BreadcrumbList',
                'itemListElement' => [['@type' => 'ListItem', 'position' => 1, 'name' => 'Home']],
            ]],
            'Organization' => [[
                '@context' => 'https://schema.org', '@type' => 'Organization', 'name' => 'AICOUNTLY',
            ]],
            'Person' => [[
                '@context' => 'https://schema.org', '@type' => 'Person', 'name' => 'John Doe',
            ]],
            'WebPage' => [[
                '@context' => 'https://schema.org', '@type' => 'WebPage', 'name' => 'Home',
            ]],
            'SoftwareApplication' => [[
                '@context' => 'https://schema.org', '@type' => 'SoftwareApplication',
                'name' => 'AICOUNTLY', 'applicationCategory' => 'BusinessApplication',
            ]],
        ];
    }

    /** @dataProvider disallowedTypes */
    public function testDisallowedTypesAreRejected(string $type): void
    {
        $schema = ['@context' => 'https://schema.org', '@type' => $type, 'name' => 'Test'];
        $result = $this->validator->validate($schema);
        $this->assertFalse($result['valid'], "{$type} should not be allowed");
    }

    public static function disallowedTypes(): array
    {
        return [
            ['Product'], ['LocalBusiness'], ['Restaurant'], ['Event'], ['Movie'],
            ['VideoObject'], ['Course'], ['JobPosting'], ['Offer'], ['Review'],
        ];
    }

    public function testAllAllowedTypeCount(): void
    {
        $this->assertCount(10, StructuredDataValidator::ALLOWED_TYPES);
    }
}
