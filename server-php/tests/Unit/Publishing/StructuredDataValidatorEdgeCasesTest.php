<?php

namespace Tests\Unit\Publishing;

use App\Libraries\Publishing\Seo\StructuredDataValidator;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Edge-case tests for StructuredDataValidator.
 *
 * @covers \App\Libraries\Publishing\Seo\StructuredDataValidator
 */
class StructuredDataValidatorEdgeCasesTest extends CIUnitTestCase
{
    private StructuredDataValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new StructuredDataValidator();
    }

    public function testValidArticlePasses(): void
    {
        $schema = [
            '@context'    => 'https://schema.org',
            '@type'       => 'Article',
            'headline'    => 'Test Article',
            'author'      => ['@type' => 'Person', 'name' => 'Test Author'],
            'datePublished' => '2026-07-13',
        ];
        $result = $this->validator->validate($schema);
        $this->assertTrue($result['valid']);
    }

    public function testValidBreadcrumbListPasses(): void
    {
        $schema = [
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home'],
            ],
        ];
        $result = $this->validator->validate($schema);
        $this->assertTrue($result['valid']);
    }

    public function testValidWebPagePasses(): void
    {
        $schema = [
            '@context' => 'https://schema.org',
            '@type'    => 'WebPage',
            'name'     => 'AICOUNTLY Platform',
        ];
        $result = $this->validator->validate($schema);
        $this->assertTrue($result['valid']);
    }

    public function testValidSoftwareApplicationPasses(): void
    {
        $schema = [
            '@context'            => 'https://schema.org',
            '@type'               => 'SoftwareApplication',
            'name'                => 'AICOUNTLY',
            'applicationCategory' => 'BusinessApplication',
        ];
        $result = $this->validator->validate($schema);
        $this->assertTrue($result['valid']);
    }

    public function testFakeReviewProhibited(): void
    {
        $schema = [
            '@context' => 'https://schema.org',
            '@type'    => 'BlogPosting',
            'headline' => 'Test',
            'review'   => ['reviewRating' => ['ratingValue' => '5']],
            'datePublished' => '2026-07-13',
        ];
        $result = $this->validator->validate($schema);
        $this->assertFalse($result['valid']);
    }

    public function testOfferProhibited(): void
    {
        $schema = [
            '@context' => 'https://schema.org',
            '@type'    => 'BlogPosting',
            'headline' => 'Test',
            'offers'   => ['price' => '99'],
            'datePublished' => '2026-07-13',
        ];
        $result = $this->validator->validate($schema);
        $this->assertFalse($result['valid']);
    }

    public function testFaqPageWithEmptyMainEntityFails(): void
    {
        $schema = [
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'mainEntity' => [],
        ];
        $result = $this->validator->validate($schema);
        $this->assertFalse($result['valid']);
    }

    public function testHowToWithNoStepFails(): void
    {
        $schema = [
            '@context' => 'https://schema.org',
            '@type'    => 'HowTo',
            'name'     => 'Guide',
        ];
        $result = $this->validator->validate($schema);
        $this->assertFalse($result['valid']);
    }

    public function testValidateAllWithNoSchemasReturnsValid(): void
    {
        $result = $this->validator->validateAll([]);
        $this->assertTrue($result['valid']);
    }

    public function testValidateAllWithMultipleValidSchemas(): void
    {
        $schemas = [
            [
                '@context'      => 'https://schema.org',
                '@type'         => 'Article',
                'headline'      => 'A',
                'author'        => ['@type' => 'Person', 'name' => 'Test'],
                'datePublished' => '2026-01-01',
            ],
            ['@context' => 'https://schema.org', '@type' => 'WebPage', 'name' => 'W'],
        ];
        $result = $this->validator->validateAll($schemas);
        $this->assertTrue($result['valid']);
    }

    public function testOrganizationIsAllowedType(): void
    {
        $schema = ['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => 'AICOUNTLY'];
        $result = $this->validator->validate($schema);
        $this->assertTrue($result['valid']);
    }

    public function testProhibitedTypeJobPosting(): void
    {
        $schema = ['@context' => 'https://schema.org', '@type' => 'JobPosting', 'title' => 'Dev'];
        $result = $this->validator->validate($schema);
        $this->assertFalse($result['valid']);
    }
}
