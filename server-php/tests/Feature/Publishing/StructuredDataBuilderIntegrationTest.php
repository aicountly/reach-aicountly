<?php

namespace Tests\Feature\Publishing;

use App\Libraries\Publishing\Seo\StructuredDataBuilder;
use App\Libraries\Publishing\Seo\StructuredDataValidator;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Integration tests for StructuredDataBuilder with end-to-end schema validation.
 *
 * @group publishing
 */
class StructuredDataBuilderIntegrationTest extends CIUnitTestCase
{
    private StructuredDataBuilder $builder;
    private StructuredDataValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder   = new StructuredDataBuilder();
        $this->validator = new StructuredDataValidator();
    }

    public function testHowToForBankingSetupPassesValidation(): void
    {
        $steps = [
            ['title' => 'Open Banking Module', 'description' => 'Click Banking in the main navigation'],
            ['title' => 'Add Bank Account', 'description' => 'Click Add Account and enter details'],
            ['title' => 'Upload Statement', 'description' => 'Click Upload Statement and select CSV file'],
            ['title' => 'Run Reconciliation', 'description' => 'Click Reconcile to match transactions'],
        ];

        $schema = $this->builder->buildHowTo('How to Reconcile Bank Statements in AICOUNTLY', $steps,
            'Step by step guide for bank reconciliation in AICOUNTLY accounting software.'
        );

        $result = $this->validator->validate($schema);
        $this->assertTrue($result['valid'], json_encode($result['errors']));
        $this->assertSame(4, count($schema['step']));
    }

    public function testFaqForGstFilingPassesValidation(): void
    {
        $items = [
            ['question' => 'How does AICOUNTLY handle GST filing?', 'answer' => 'AICOUNTLY automates GSTR-1 and GSTR-3B generation.'],
            ['question' => 'Can AICOUNTLY file GST directly?', 'answer' => 'Yes, AICOUNTLY integrates with the GST portal for direct filing.'],
            ['question' => 'What happens if my GST filing fails?', 'answer' => 'AICOUNTLY shows detailed error messages and retry options.'],
        ];

        $schema = $this->builder->buildFAQPage($items);
        $result = $this->validator->validate($schema);
        $this->assertTrue($result['valid'], json_encode($result['errors']));
    }

    public function testBreadcrumbsForBlogPostPassesValidation(): void
    {
        $crumbs = [
            ['name' => 'Home', 'url' => 'https://aicountly.com'],
            ['name' => 'Blog', 'url' => 'https://aicountly.com/blog'],
            ['name' => 'Bank Reconciliation Guide'],
        ];

        $schema = $this->builder->buildBreadcrumbs($crumbs);
        $result = $this->validator->validate($schema);
        $this->assertTrue($result['valid'], json_encode($result['errors']));
    }

    public function testBreadcrumbsForKbArticlePassesValidation(): void
    {
        $crumbs = [
            ['name' => 'Home', 'url' => 'https://aicountly.com'],
            ['name' => 'Help Center', 'url' => 'https://aicountly.com/help'],
            ['name' => 'Banking', 'url' => 'https://aicountly.com/help/banking'],
            ['name' => 'How to Set Up Bank Reconciliation'],
        ];

        $schema = $this->builder->buildBreadcrumbs($crumbs);
        $result = $this->validator->validate($schema);
        $this->assertTrue($result['valid'], json_encode($result['errors']));
        $this->assertSame(4, count($schema['itemListElement']));
    }

    public function testWebPageForLandingPagePassesValidation(): void
    {
        $schema = $this->builder->buildWebPage(
            'AICOUNTLY — AI-Powered Accounting Software for India',
            'https://aicountly.com',
            'Cloud-based AI accounting platform for Indian businesses. Automate GST, TDS, and bank reconciliation.'
        );

        $result = $this->validator->validate($schema);
        $this->assertTrue($result['valid'], json_encode($result['errors']));
    }

    public function testSchemaNotContainingPersonalData(): void
    {
        $schema = $this->builder->buildFAQPage([
            ['question' => 'Q?', 'answer' => 'A.'],
        ]);

        $encoded = json_encode($schema);
        $this->assertStringNotContainsString('@gmail.com', $encoded);
        $this->assertStringNotContainsString('password', strtolower($encoded));
    }
}
