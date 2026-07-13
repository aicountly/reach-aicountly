<?php

namespace Tests\Unit\Publishing;

use App\Libraries\Publishing\Seo\StructuredDataBuilder;
use App\Libraries\Publishing\Seo\StructuredDataValidator;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @covers \App\Libraries\Publishing\Seo\StructuredDataBuilder
 */
class StructuredDataBuilderTest extends CIUnitTestCase
{
    private StructuredDataBuilder $builder;
    private StructuredDataValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder   = new StructuredDataBuilder();
        $this->validator = new StructuredDataValidator();
    }

    public function testBuildHowToPassesValidation(): void
    {
        $steps = [
            ['title' => 'Navigate to Banking', 'description' => 'Click on Banking module'],
            ['title' => 'Enable Reconciliation', 'description' => 'Toggle the switch'],
        ];

        $schema = $this->builder->buildHowTo('Setup Guide', $steps, 'Learn how to set up.');
        $result = $this->validator->validate($schema);

        $this->assertTrue($result['valid'], json_encode($result['errors']));
        $this->assertSame('HowTo', $schema['@type']);
        $this->assertCount(2, $schema['step']);
    }

    public function testBuildFAQPagePassesValidation(): void
    {
        $faqItems = [
            ['question' => 'What is AICOUNTLY?', 'answer' => 'An AI accounting platform.'],
            ['question' => 'Is it cloud-based?', 'answer' => 'Yes, fully SaaS.'],
        ];

        $schema = $this->builder->buildFAQPage($faqItems);
        $result = $this->validator->validate($schema);

        $this->assertTrue($result['valid'], json_encode($result['errors']));
        $this->assertSame('FAQPage', $schema['@type']);
        $this->assertCount(2, $schema['mainEntity']);
    }

    public function testBuildBreadcrumbsPassesValidation(): void
    {
        $crumbs = [
            ['name' => 'Home', 'url' => 'https://aicountly.com'],
            ['name' => 'Blog', 'url' => 'https://aicountly.com/blog'],
            ['name' => 'Article'],
        ];

        $schema = $this->builder->buildBreadcrumbs($crumbs);
        $result = $this->validator->validate($schema);

        $this->assertTrue($result['valid'], json_encode($result['errors']));
        $this->assertSame('BreadcrumbList', $schema['@type']);
        $this->assertSame(1, $schema['itemListElement'][0]['position']);
    }

    public function testBreadcrumbPositionsAreSequential(): void
    {
        $crumbs = [
            ['name' => 'Home', 'url' => 'https://aicountly.com'],
            ['name' => 'Blog', 'url' => 'https://aicountly.com/blog'],
            ['name' => 'Post'],
        ];

        $schema = $this->builder->buildBreadcrumbs($crumbs);
        $positions = array_column($schema['itemListElement'], 'position');
        $this->assertSame([1, 2, 3], $positions);
    }

    public function testBuildHowToWithNoDescription(): void
    {
        $steps  = [['title' => 'Step 1', 'description' => 'Do it']];
        $schema = $this->builder->buildHowTo('Guide', $steps);
        $this->assertArrayNotHasKey('description', $schema);
    }

    public function testBuildWebPagePassesValidation(): void
    {
        $schema = $this->builder->buildWebPage(
            'AICOUNTLY Home',
            'https://aicountly.com',
            'AI-powered accounting for Indian businesses.'
        );

        $result = $this->validator->validate($schema);
        $this->assertTrue($result['valid'], json_encode($result['errors']));
        $this->assertSame('WebPage', $schema['@type']);
    }

    public function testBuiltSchemaContainsSchemaOrgContext(): void
    {
        $schema = $this->builder->buildFAQPage([
            ['question' => 'Test?', 'answer' => 'Answer.'],
        ]);

        $this->assertSame('https://schema.org', $schema['@context']);
    }
}
