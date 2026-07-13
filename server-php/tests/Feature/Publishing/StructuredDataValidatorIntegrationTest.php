<?php

namespace Tests\Feature\Publishing;

use App\Libraries\Publishing\Seo\StructuredDataValidator;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Integration tests for StructuredDataValidator covering edge cases and security.
 *
 * @group publishing
 */
class StructuredDataValidatorIntegrationTest extends CIUnitTestCase
{
    private StructuredDataValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new StructuredDataValidator();
    }

    public function testProhibitedPropertiesAreRejected(): void
    {
        $prohibited = ['aggregateRating', 'review', 'offers', 'price', 'priceRange', 'ratingValue'];

        foreach ($prohibited as $prop) {
            $schema = [
                '@context' => 'https://schema.org',
                '@type'    => 'BlogPosting',
                'headline' => 'Test',
                'datePublished' => '2026-01-01',
                $prop => 'value',
            ];
            $result = $this->validator->validate($schema);
            $this->assertFalse($result['valid'], "Schema with '{$prop}' should be invalid");
        }
    }

    public function testFaqPageMainEntityValidation(): void
    {
        // Valid FAQ
        $valid = [
            '@context' => 'https://schema.org', '@type' => 'FAQPage',
            'mainEntity' => [
                ['@type' => 'Question', 'name' => 'Q?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'A.']],
            ],
        ];
        $this->assertTrue($this->validator->validate($valid)['valid']);

        // Invalid: missing question name
        $missingName = [
            '@context' => 'https://schema.org', '@type' => 'FAQPage',
            'mainEntity' => [
                ['@type' => 'Question', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'A.']],
            ],
        ];
        $this->assertFalse($this->validator->validate($missingName)['valid']);

        // Invalid: missing answer text
        $missingAnswer = [
            '@context' => 'https://schema.org', '@type' => 'FAQPage',
            'mainEntity' => [
                ['@type' => 'Question', 'name' => 'Q?', 'acceptedAnswer' => ['@type' => 'Answer']],
            ],
        ];
        $this->assertFalse($this->validator->validate($missingAnswer)['valid']);
    }

    public function testHowToWithMissingStepNameAndTextFails(): void
    {
        $schema = [
            '@context' => 'https://schema.org', '@type' => 'HowTo',
            'name' => 'Guide',
            'step' => [['@type' => 'HowToStep']],
        ];
        $result = $this->validator->validate($schema);
        $this->assertFalse($result['valid']);
    }

    public function testInvalidUrlFormatIsRejected(): void
    {
        $schema = [
            '@context' => 'https://schema.org', '@type' => 'WebPage',
            'name' => 'Test',
            'url' => 'not-a-valid-url',
        ];
        $result = $this->validator->validate($schema);
        $this->assertFalse($result['valid']);
    }

    public function testValidUrlIsAccepted(): void
    {
        $schema = [
            '@context' => 'https://schema.org', '@type' => 'WebPage',
            'name' => 'Test',
            'url' => 'https://aicountly.com/page',
        ];
        $result = $this->validator->validate($schema);
        $this->assertTrue($result['valid']);
    }

    public function testValidateAllAggregatesErrorsFromMultipleSchemas(): void
    {
        $schemas = [
            ['@context' => 'https://schema.org', '@type' => 'WebPage', 'name' => 'OK'],
            ['@type' => 'Product', 'name' => 'Bad'],
            ['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => 'Good'],
        ];

        $result = $this->validator->validateAll($schemas);
        $this->assertFalse($result['valid']);
        $errorMessages = implode(' ', $result['errors']);
        $this->assertStringContainsString('Product', $errorMessages);
    }
}
