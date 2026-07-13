<?php

namespace Tests\Unit\Publishing;

use App\Libraries\Publishing\Seo\StructuredDataBuilder;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Tests that all schemas built by StructuredDataBuilder include proper @context and @type.
 *
 * @covers \App\Libraries\Publishing\Seo\StructuredDataBuilder
 */
class StructuredDataBuilderSchemaContextTest extends CIUnitTestCase
{
    private StructuredDataBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new StructuredDataBuilder();
    }

    public function testHowToHasSchemaOrgContext(): void
    {
        $schema = $this->builder->buildHowTo('Guide', [['title' => 'S', 'description' => 'D']]);
        $this->assertSame('https://schema.org', $schema['@context']);
    }

    public function testHowToHasCorrectType(): void
    {
        $schema = $this->builder->buildHowTo('Guide', [['title' => 'S', 'description' => 'D']]);
        $this->assertSame('HowTo', $schema['@type']);
    }

    public function testFaqPageHasSchemaOrgContext(): void
    {
        $schema = $this->builder->buildFAQPage([['question' => 'Q?', 'answer' => 'A.']]);
        $this->assertSame('https://schema.org', $schema['@context']);
    }

    public function testFaqPageHasCorrectType(): void
    {
        $schema = $this->builder->buildFAQPage([['question' => 'Q?', 'answer' => 'A.']]);
        $this->assertSame('FAQPage', $schema['@type']);
    }

    public function testBreadcrumbListHasSchemaOrgContext(): void
    {
        $schema = $this->builder->buildBreadcrumbs([['name' => 'H', 'url' => 'https://aicountly.com']]);
        $this->assertSame('https://schema.org', $schema['@context']);
    }

    public function testBreadcrumbListHasCorrectType(): void
    {
        $schema = $this->builder->buildBreadcrumbs([['name' => 'H', 'url' => 'https://aicountly.com']]);
        $this->assertSame('BreadcrumbList', $schema['@type']);
    }

    public function testWebPageHasSchemaOrgContext(): void
    {
        $schema = $this->builder->buildWebPage('Page', 'https://aicountly.com', 'Desc');
        $this->assertSame('https://schema.org', $schema['@context']);
    }

    public function testWebPageHasCorrectType(): void
    {
        $schema = $this->builder->buildWebPage('Page', 'https://aicountly.com', 'Desc');
        $this->assertSame('WebPage', $schema['@type']);
    }

    public function testWebPageHasUrl(): void
    {
        $schema = $this->builder->buildWebPage('Page', 'https://aicountly.com/test', 'Desc');
        $this->assertSame('https://aicountly.com/test', $schema['url']);
    }

    public function testHowToHasName(): void
    {
        $schema = $this->builder->buildHowTo('Bank Reconciliation Guide', [
            ['title' => 'Open Banking', 'description' => 'Navigate to the Banking module'],
        ]);
        $this->assertSame('Bank Reconciliation Guide', $schema['name']);
    }
}
