<?php

namespace Tests\Feature\Publishing;

use App\Libraries\Publishing\Seo\StructuredDataValidator;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Integration tests for all 10 allowed schema types.
 *
 * @group publishing
 */
class StructuredDataSchemaTypesIntegrationTest extends CIUnitTestCase
{
    private StructuredDataValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new StructuredDataValidator();
    }

    public function testAllTenAllowedTypesValidate(): void
    {
        $schemas = [
            ['@context' => 'https://schema.org', '@type' => 'Article', 'headline' => 'T', 'author' => ['@type' => 'Person', 'name' => 'A'], 'datePublished' => '2026-01-01'],
            ['@context' => 'https://schema.org', '@type' => 'BlogPosting', 'headline' => 'T', 'datePublished' => '2026-01-01'],
            ['@context' => 'https://schema.org', '@type' => 'TechArticle', 'headline' => 'T', 'author' => ['@type' => 'Person', 'name' => 'A'], 'datePublished' => '2026-01-01'],
            ['@context' => 'https://schema.org', '@type' => 'HowTo', 'name' => 'N', 'step' => [['@type' => 'HowToStep', 'name' => 'S', 'text' => 'T']]],
            ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => [['@type' => 'Question', 'name' => 'Q?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'A.']]]],
            ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [['@type' => 'ListItem', 'position' => 1, 'name' => 'H']]],
            ['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => 'AICOUNTLY'],
            ['@context' => 'https://schema.org', '@type' => 'Person', 'name' => 'John Doe'],
            ['@context' => 'https://schema.org', '@type' => 'WebPage', 'name' => 'Home'],
            ['@context' => 'https://schema.org', '@type' => 'SoftwareApplication', 'name' => 'AICOUNTLY', 'applicationCategory' => 'BusinessApplication'],
        ];

        $result = $this->validator->validateAll($schemas);
        $this->assertTrue($result['valid'], json_encode($result['errors']));
        $this->assertCount(10, $schemas);
    }

    public function testFakePriceRejectedInAllContexts(): void
    {
        foreach (StructuredDataValidator::ALLOWED_TYPES as $type) {
            $schema = [
                '@context' => 'https://schema.org',
                '@type'    => $type,
                'name'     => 'Test',
                'price'    => '999',
            ];
            $result = $this->validator->validate($schema);
            $this->assertFalse($result['valid'], "Type {$type} with 'price' should be invalid");
        }
    }

    public function testFakeAggregateRatingRejectedInAllContexts(): void
    {
        foreach (StructuredDataValidator::ALLOWED_TYPES as $type) {
            $schema = [
                '@context'        => 'https://schema.org',
                '@type'           => $type,
                'name'            => 'Test',
                'aggregateRating' => ['ratingValue' => '4.9'],
            ];
            $result = $this->validator->validate($schema);
            $this->assertFalse($result['valid'], "Type {$type} with 'aggregateRating' should be invalid");
        }
    }

    public function testAllowedTypeCountIsExactlyTen(): void
    {
        $this->assertCount(10, StructuredDataValidator::ALLOWED_TYPES);
    }
}
