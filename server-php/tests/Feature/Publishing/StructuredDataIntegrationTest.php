<?php

namespace Tests\Feature\Publishing;

use App\Libraries\Publishing\Seo\StructuredDataBuilder;
use App\Libraries\Publishing\Seo\StructuredDataValidator;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Integration tests ensuring StructuredDataBuilder output always passes StructuredDataValidator.
 *
 * @group publishing
 */
class StructuredDataIntegrationTest extends CIUnitTestCase
{
    private StructuredDataBuilder $builder;
    private StructuredDataValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder   = new StructuredDataBuilder();
        $this->validator = new StructuredDataValidator();
    }

    public function testHowToAlwaysValid(): void
    {
        $steps  = [
            ['title' => 'Open Module', 'description' => 'Click the module button'],
            ['title' => 'Configure', 'description' => 'Enter configuration values'],
            ['title' => 'Save', 'description' => 'Click save to persist'],
        ];
        $schema = $this->builder->buildHowTo('How to Configure AICOUNTLY', $steps, 'Step by step guide.');
        $result = $this->validator->validate($schema);
        $this->assertTrue($result['valid'], json_encode($result['errors']));
    }

    public function testFaqPageAlwaysValid(): void
    {
        $items  = [
            ['question' => 'What is AICOUNTLY?', 'answer' => 'AI-powered accounting platform.'],
            ['question' => 'Is there a free trial?', 'answer' => 'Yes, 14 days free.'],
            ['question' => 'Does it support GST?', 'answer' => 'Yes, full GST support.'],
        ];
        $schema = $this->builder->buildFAQPage($items);
        $result = $this->validator->validate($schema);
        $this->assertTrue($result['valid'], json_encode($result['errors']));
    }

    public function testBreadcrumbsAlwaysValid(): void
    {
        $crumbs = [
            ['name' => 'Home', 'url' => 'https://aicountly.com'],
            ['name' => 'Blog', 'url' => 'https://aicountly.com/blog'],
            ['name' => 'How AICOUNTLY Works'],
        ];
        $schema = $this->builder->buildBreadcrumbs($crumbs);
        $result = $this->validator->validate($schema);
        $this->assertTrue($result['valid'], json_encode($result['errors']));
    }

    public function testWebPageAlwaysValid(): void
    {
        $schema = $this->builder->buildWebPage(
            'AICOUNTLY — AI Accounting Platform',
            'https://aicountly.com',
            'Cloud-based AI accounting for Indian SMBs.'
        );
        $result = $this->validator->validate($schema);
        $this->assertTrue($result['valid'], json_encode($result['errors']));
    }

    public function testBuiltSchemaDoesNotContainProhibitedProps(): void
    {
        $items  = [['question' => 'Q?', 'answer' => 'A.']];
        $schema = $this->builder->buildFAQPage($items);

        $prohibited = ['aggregateRating', 'review', 'offers', 'price', 'priceRange'];
        foreach ($prohibited as $prop) {
            $this->assertArrayNotHasKey($prop, $schema, "Schema must not contain {$prop}");
        }
    }

    public function testHowToBuilderWithSingleStep(): void
    {
        $steps  = [['title' => 'Only Step', 'description' => 'Do this']];
        $schema = $this->builder->buildHowTo('Single Step Guide', $steps);
        $result = $this->validator->validate($schema);
        $this->assertTrue($result['valid'], json_encode($result['errors']));
    }

    public function testSingleBreadcrumbIsValid(): void
    {
        $crumbs = [['name' => 'Home', 'url' => 'https://aicountly.com']];
        $schema = $this->builder->buildBreadcrumbs($crumbs);
        $result = $this->validator->validate($schema);
        $this->assertTrue($result['valid'], json_encode($result['errors']));
    }

    public function testBuiltSchemasPassBatchValidation(): void
    {
        $schemas = [
            $this->builder->buildFAQPage([['question' => 'Q?', 'answer' => 'A.']]),
            $this->builder->buildWebPage('Page', 'https://aicountly.com/page', 'Desc'),
            $this->builder->buildBreadcrumbs([['name' => 'Home', 'url' => 'https://aicountly.com'], ['name' => 'Page']]),
        ];

        $result = $this->validator->validateAll($schemas);
        $this->assertTrue($result['valid'], json_encode($result['errors']));
    }

    public function testLargeFaqPageIsValid(): void
    {
        $items = [];
        for ($i = 1; $i <= 10; $i++) {
            $items[] = ['question' => "Question {$i}?", 'answer' => "Answer {$i}."];
        }
        $schema = $this->builder->buildFAQPage($items);
        $result = $this->validator->validate($schema);
        $this->assertTrue($result['valid'], json_encode($result['errors']));
    }
}
