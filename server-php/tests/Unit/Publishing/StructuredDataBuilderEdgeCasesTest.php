<?php

namespace Tests\Unit\Publishing;

use App\Libraries\Publishing\Seo\StructuredDataBuilder;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Additional edge-case tests for StructuredDataBuilder.
 *
 * @covers \App\Libraries\Publishing\Seo\StructuredDataBuilder
 */
class StructuredDataBuilderEdgeCasesTest extends CIUnitTestCase
{
    private StructuredDataBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new StructuredDataBuilder();
    }

    public function testHowToStepsHaveRequiredFields(): void
    {
        $steps  = [
            ['title' => 'Log in', 'description' => 'Navigate to portal'],
            ['title' => 'Open Banking', 'description' => 'Click Banking module'],
        ];
        $schema = $this->builder->buildHowTo('How to set up banking', $steps);

        foreach ($schema['step'] as $step) {
            $this->assertArrayHasKey('@type', $step);
            $this->assertSame('HowToStep', $step['@type']);
            $this->assertArrayHasKey('name', $step);
            $this->assertArrayHasKey('text', $step);
        }
    }

    public function testFaqPageMainEntityHasQAStructure(): void
    {
        $items = [['question' => 'Q1?', 'answer' => 'A1'], ['question' => 'Q2?', 'answer' => 'A2']];
        $schema = $this->builder->buildFAQPage($items);

        foreach ($schema['mainEntity'] as $entity) {
            $this->assertSame('Question', $entity['@type']);
            $this->assertArrayHasKey('name', $entity);
            $this->assertSame('Answer', $entity['acceptedAnswer']['@type']);
            $this->assertArrayHasKey('text', $entity['acceptedAnswer']);
        }
    }

    public function testBreadcrumbLastItemHasNoUrl(): void
    {
        $crumbs = [
            ['name' => 'Home', 'url' => 'https://aicountly.com'],
            ['name' => 'Last Page'],
        ];
        $schema = $this->builder->buildBreadcrumbs($crumbs);
        $last   = end($schema['itemListElement']);

        $this->assertArrayNotHasKey('item', $last);
    }

    public function testBreadcrumbItemsHaveListItemType(): void
    {
        $crumbs = [
            ['name' => 'Home', 'url' => 'https://aicountly.com'],
            ['name' => 'Blog'],
        ];
        $schema = $this->builder->buildBreadcrumbs($crumbs);

        foreach ($schema['itemListElement'] as $item) {
            $this->assertSame('ListItem', $item['@type']);
        }
    }

    public function testHowToDescriptionIsOptional(): void
    {
        $steps  = [['title' => 'Step 1', 'description' => 'Do it']];
        $schema = $this->builder->buildHowTo('Guide', $steps);
        $this->assertArrayNotHasKey('description', $schema);
    }

    public function testHowToWithDescriptionIncludesIt(): void
    {
        $steps  = [['title' => 'Step 1', 'description' => 'Do it']];
        $schema = $this->builder->buildHowTo('Guide', $steps, 'Here is the guide description.');
        $this->assertArrayHasKey('description', $schema);
        $this->assertSame('Here is the guide description.', $schema['description']);
    }

    public function testWebPageHasNameAndDescription(): void
    {
        $schema = $this->builder->buildWebPage(
            'Test Page',
            'https://aicountly.com/test',
            'Test description.'
        );
        $this->assertSame('Test Page', $schema['name']);
        $this->assertSame('Test description.', $schema['description']);
    }

    public function testSingleFaqItemSchema(): void
    {
        $schema = $this->builder->buildFAQPage([['question' => 'Q?', 'answer' => 'A.']]);
        $this->assertCount(1, $schema['mainEntity']);
    }

    public function testHowToStepsCountMatches(): void
    {
        $steps  = array_map(fn($i) => ['title' => "S{$i}", 'description' => "D{$i}"], range(1, 5));
        $schema = $this->builder->buildHowTo('Big Guide', $steps);
        $this->assertCount(5, $schema['step']);
    }

    public function testBreadcrumbsPositionStartsAtOne(): void
    {
        $crumbs = [['name' => 'A', 'url' => 'https://example.com/a'], ['name' => 'B']];
        $schema = $this->builder->buildBreadcrumbs($crumbs);
        $this->assertSame(1, $schema['itemListElement'][0]['position']);
    }
}
