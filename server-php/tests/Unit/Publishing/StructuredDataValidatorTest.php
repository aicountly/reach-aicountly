<?php

namespace Tests\Unit\Publishing;

use App\Libraries\Publishing\Seo\StructuredDataValidator;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @covers \App\Libraries\Publishing\Seo\StructuredDataValidator
 */
class StructuredDataValidatorTest extends CIUnitTestCase
{
    private StructuredDataValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new StructuredDataValidator();
    }

    public function testValidBlogPostingPasses(): void
    {
        $schema = [
            '@context'      => 'https://schema.org',
            '@type'         => 'BlogPosting',
            'headline'      => 'How AICOUNTLY Simplifies Compliance',
            'datePublished' => '2026-07-13',
            'author'        => ['@type' => 'Person', 'name' => 'Rahul'],
        ];

        $result = $this->validator->validate($schema);
        $this->assertTrue($result['valid'], json_encode($result['errors']));
    }

    public function testValidHowToPasses(): void
    {
        $schema = [
            '@context' => 'https://schema.org',
            '@type'    => 'HowTo',
            'name'     => 'Set up bank reconciliation',
            'step'     => [
                ['@type' => 'HowToStep', 'name' => 'Step 1', 'text' => 'Click banking'],
            ],
        ];

        $result = $this->validator->validate($schema);
        $this->assertTrue($result['valid'], json_encode($result['errors']));
    }

    public function testFaqPageWithValidEntriesPasses(): void
    {
        $schema = [
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'mainEntity' => [
                [
                    '@type'          => 'Question',
                    'name'           => 'What is AICOUNTLY?',
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'An AI accounting platform'],
                ],
            ],
        ];

        $result = $this->validator->validate($schema);
        $this->assertTrue($result['valid'], json_encode($result['errors']));
    }

    public function testDisallowedSchemaTypeIsRejected(): void
    {
        $schema = ['@context' => 'https://schema.org', '@type' => 'Product', 'name' => 'Test'];
        $result = $this->validator->validate($schema);
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function testAggregateRatingIsProhibited(): void
    {
        $schema = [
            '@context'        => 'https://schema.org',
            '@type'           => 'Article',
            'headline'        => 'Test',
            'author'          => ['@type' => 'Person', 'name' => 'X'],
            'datePublished'   => '2026-07-13',
            'aggregateRating' => ['ratingValue' => '4.8'],
        ];

        $result = $this->validator->validate($schema);
        $this->assertFalse($result['valid']);
        $hasRatingError = false;
        foreach ($result['errors'] as $err) {
            if (str_contains($err, 'aggregateRating')) {
                $hasRatingError = true;
            }
        }
        $this->assertTrue($hasRatingError);
    }

    public function testMissingContextIsRejected(): void
    {
        $schema = ['@type' => 'BlogPosting', 'headline' => 'Test', 'datePublished' => '2026-07-13'];
        $result = $this->validator->validate($schema);
        $this->assertFalse($result['valid']);
    }

    public function testMissingRequiredHeadlineForBlogPosting(): void
    {
        $schema = ['@context' => 'https://schema.org', '@type' => 'BlogPosting', 'datePublished' => '2026-07-13'];
        $result = $this->validator->validate($schema);
        $this->assertFalse($result['valid']);
    }

    public function testAllowedSchemaTypesListIsComplete(): void
    {
        $allowed = StructuredDataValidator::ALLOWED_TYPES;
        $this->assertContains('BlogPosting', $allowed);
        $this->assertContains('HowTo', $allowed);
        $this->assertContains('FAQPage', $allowed);
        $this->assertContains('Article', $allowed);
        $this->assertContains('WebPage', $allowed);
        $this->assertNotContains('Product', $allowed);
        $this->assertNotContains('LocalBusiness', $allowed);
        $this->assertNotContains('JobPosting', $allowed);
    }

    public function testValidateAllReturnsAggregatedErrors(): void
    {
        $schemas = [
            ['@context' => 'https://schema.org', '@type' => 'BlogPosting', 'headline' => 'OK', 'datePublished' => '2026-01-01'],
            ['@type' => 'Product', 'name' => 'Bad schema'],
        ];

        $result = $this->validator->validateAll($schemas);
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function testPriceProhibited(): void
    {
        $schema = [
            '@context'      => 'https://schema.org',
            '@type'         => 'SoftwareApplication',
            'name'          => 'AICOUNTLY',
            'applicationCategory' => 'BusinessApplication',
            'price'         => '0',
        ];

        $result = $this->validator->validate($schema);
        $this->assertFalse($result['valid']);
    }
}
