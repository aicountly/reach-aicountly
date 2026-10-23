<?php

declare(strict_types=1);

namespace Tests\Unit\Blog;

use App\Libraries\Automation\Routine\LaunchApps;
use App\Libraries\Automation\Structured\ProductCatalog;
use App\Libraries\Blog\AutomationDraftIngestService;
use App\Libraries\Blog\BlogTaxonomy;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The blog taxonomy contract shared with aicountly.com (23 Oct 2026): 15
 * topic slugs in three groups, every old value / routine label mapping to
 * one of them, product slugs from the site catalogue, and the 50/50 mix.
 *
 * @covers \App\Libraries\Blog\BlogTaxonomy
 * @covers \App\Libraries\Blog\AutomationDraftIngestService::resolveTaxonomy
 *
 * @internal
 */
final class BlogTaxonomyTest extends CIUnitTestCase
{
    public function testTheFifteenSlugsLabelsAndGroupsMatchTheContract(): void
    {
        $this->assertSame([
            'accounting' => 'Accounting', 'gst' => 'GST', 'income-tax' => 'Income Tax', 'tds-tcs' => 'TDS/TCS',
            'audit-assurance' => 'Audit & Assurance', 'company-law' => 'Company Law', 'payroll-hr' => 'Payroll & HR',
            'billing-invoicing' => 'Billing & Invoicing', 'inventory-stock' => 'Inventory & Stock', 'sales-crm' => 'Sales & CRM',
            'productivity-collaboration' => 'Productivity & Collaboration', 'business-operations' => 'Business Operations',
            'product-guides' => 'Product Guides', 'product-updates' => 'Product Updates', 'ai-for-finance' => 'AI for Finance',
        ], array_map(static fn (array $t): string => $t[0], BlogTaxonomy::TOPICS));

        $groups = array_count_values(array_column(BlogTaxonomy::TOPICS, 1));
        $this->assertSame(['compliance' => 7, 'business' => 5, 'aicountly' => 3], $groups);
        $this->assertSame(['compliance' => 'Compliance', 'business' => 'Business', 'aicountly' => 'AICOUNTLY'], BlogTaxonomy::GROUPS);
    }

    /** @return array<string, array{0:string, 1:string}> */
    public static function aliasProvider(): array
    {
        return [
            // Labels the routine sent before 23 Oct 2026.
            'GST'                  => ['GST', 'gst'],
            'Income Tax'           => ['Income Tax', 'income-tax'],
            'TDS'                  => ['TDS', 'tds-tcs'],
            'Payroll'              => ['Payroll', 'payroll-hr'],
            'Company Law'          => ['Company Law', 'company-law'],
            'Accounting'           => ['Accounting', 'accounting'],
            'Product guides'       => ['Product guides', 'product-guides'],
            // Contract aliases.
            'financial-reporting'  => ['financial-reporting', 'accounting'],
            'financial reporting'  => ['Financial Reporting', 'accounting'],
            'audit'                => ['audit', 'audit-assurance'],
            'ai-finance-compliance' => ['ai-finance-compliance', 'ai-for-finance'],
            'ai'                   => ['AI', 'ai-for-finance'],
            'ai_promotions'        => ['ai_promotions', 'ai-for-finance'],
            'tcs'                  => ['TCS', 'tds-tcs'],
            'tds/tcs'              => ['TDS/TCS', 'tds-tcs'],
            'payroll & hr'         => ['Payroll & HR', 'payroll-hr'],
            'product updates'      => ['Product Updates', 'product-updates'],
            'billing'              => ['billing', 'billing-invoicing'],
            'invoicing'            => ['Invoicing', 'billing-invoicing'],
            'inventory'            => ['inventory', 'inventory-stock'],
            'stock'                => ['Stock', 'inventory-stock'],
            'crm'                  => ['CRM', 'sales-crm'],
            'sales'                => ['sales', 'sales-crm'],
            'productivity'         => ['Productivity', 'productivity-collaboration'],
            'collaboration'        => ['collaboration', 'productivity-collaboration'],
            'funding_promotions'   => ['funding_promotions', 'business-operations'],
            'subsidies_promotions' => ['subsidies_promotions', 'business-operations'],
            'new_laws_provisions'  => ['new_laws_provisions', 'income-tax'],
            'erp'                  => ['erp', 'product-guides'],
            'platform'             => ['platform', 'product-updates'],
            // Tolerated spellings.
            'and for &'            => ['Audit and Assurance', 'audit-assurance'],
            'extra spaces'         => ['  sales  &  crm ', 'sales-crm'],
            'label'                => ['AI for Finance', 'ai-for-finance'],
            'slug'                 => ['productivity-collaboration', 'productivity-collaboration'],
        ];
    }

    /** @dataProvider aliasProvider */
    public function testEveryOldValueAndLabelMapsToItsCanonicalSlug(string $value, string $expected): void
    {
        $this->assertSame($expected, BlogTaxonomy::canonical($value));
    }

    public function testUnknownAndEmptyValuesMapToNothing(): void
    {
        foreach (['', '   ', 'marketing', 'engineering', 'product', 'tax'] as $v) {
            $this->assertNull(BlogTaxonomy::canonical($v), $v);
        }
        $this->assertNull(BlogTaxonomy::canonical(null));
        $this->assertSame('marketing', BlogTaxonomy::displayLabel('marketing'));
        $this->assertSame('TDS/TCS', BlogTaxonomy::displayLabel('tds'));
    }

    public function testSpellingsCoverOldAndNewForms(): void
    {
        $this->assertContains('income tax', BlogTaxonomy::spellings('income-tax'));
        $this->assertContains('tds', BlogTaxonomy::spellings('TDS/TCS'));
        $this->assertContains('payroll', BlogTaxonomy::spellings('payroll-hr'));
    }

    public function testProductSlugsComeFromTheSiteCatalogueWithReachSynonyms(): void
    {
        $this->assertSame('books', BlogTaxonomy::canonicalProduct('smart_books'));
        $this->assertSame('books', BlogTaxonomy::canonicalProduct('smart-books'));
        $this->assertSame('docs', BlogTaxonomy::canonicalProduct('drive'));
        $this->assertSame('docs', BlogTaxonomy::canonicalProduct('Drive'));
        $this->assertSame('manage', BlogTaxonomy::canonicalProduct('manage_account'));
        $this->assertSame('our-people', BlogTaxonomy::canonicalProduct('our_people'));
        $this->assertSame('hrms', BlogTaxonomy::canonicalProduct('hrms'));
        $this->assertNull(BlogTaxonomy::canonicalProduct('photoshop'), 'unknown products are dropped, never invented');
        $this->assertNull(BlogTaxonomy::canonicalProduct(''));

        $this->assertSame(['books', 'notes', 'billing', 'inventory', 'docs', 'manage'], BlogTaxonomy::liveProductSlugs());
        foreach (BlogTaxonomy::liveProductSlugs() as $live) {
            $this->assertTrue(BlogTaxonomy::isLiveProduct($live));
            $this->assertSame('live', BlogTaxonomy::productStatus($live));
        }
        $this->assertTrue(BlogTaxonomy::isBetaProduct('hrms'));
        $this->assertSame('beta', BlogTaxonomy::productStatus('auditor'));
        $this->assertSame(count(ProductCatalog::all()), count(BlogTaxonomy::productSlugs()));

        $routine = BlogTaxonomy::productsForRoutine();
        $this->assertCount(6, $routine['live']);
        $this->assertSame('aicountly/drive-react-app', $routine['live'][4]['repo']);
        $this->assertContains('HRMS (Beta)', array_column($routine['beta'], 'present_as'));
    }

    public function testLaunchAppsResolveToTheSiteSlugs(): void
    {
        // LaunchApps keeps the KB slug (drive); the blog/site slug is docs.
        $sites = array_column(LaunchApps::forRoutine('https://aicountly.com'), 'site_product_slug', 'product_slug');
        $this->assertSame('docs', $sites['drive']);
        $this->assertSame(BlogTaxonomy::liveProductSlugs(), array_values(array_intersect(BlogTaxonomy::liveProductSlugs(), array_values($sites))));
    }

    public function testTitlesNameLiveAppsOnlyUnambiguously(): void
    {
        $this->assertSame('books', BlogTaxonomy::productInTitle('Bank reconciliation in Smart Books: from import to matching')['slug'] ?? null);
        $this->assertSame('billing', BlogTaxonomy::productInTitle('Recurring invoices with AICOUNTLY Billing')['slug'] ?? null);
        $this->assertSame('docs', BlogTaxonomy::productInTitle('Sharing files from the Drive app')['slug'] ?? null);
        $this->assertNull(BlogTaxonomy::productInTitle('GST billing mistakes small businesses make'));
        $this->assertNull(BlogTaxonomy::productInTitle('Taking notes in a board meeting'));

        $this->assertSame('gst', BlogTaxonomy::categoryFromTitle('How to fix a GSTR-3B mismatch')['slug'] ?? null);
        $this->assertSame('tds-tcs', BlogTaxonomy::categoryFromTitle('TDS on rent explained')['slug'] ?? null);
        $this->assertNull(BlogTaxonomy::categoryFromTitle('TDS and GST on the same invoice'), 'two categories match: unsure, so none');
        $this->assertNull(BlogTaxonomy::categoryFromTitle('Five habits of growing companies'));
    }

    public function testMixCountsAppLedVsTopicAndStepsTowardsTheTarget(): void
    {
        $empty = BlogTaxonomy::mix([]);
        $this->assertSame('either', $empty['next']['kind']);
        $this->assertSame('live', $empty['next']['app_status']);
        $this->assertSame(['app_led_share' => 0.5, 'topic_share' => 0.5, 'beta_share_of_app_led' => 0.2], $empty['target']);

        $topicHeavy = BlogTaxonomy::mix([['category' => 'GST'], ['category' => 'tds'], ['category' => 'inventory-stock', 'product_slug' => 'inventory']]);
        $this->assertSame(2, $topicHeavy['topic']);
        $this->assertSame(1, $topicHeavy['app_led']);
        $this->assertSame(['compliance' => 2, 'business' => 1, 'aicountly' => 0, 'uncategorised' => 0], $topicHeavy['by_group']);
        $this->assertSame('app_led', $topicHeavy['next']['kind']);
        $this->assertSame('live', $topicHeavy['next']['app_status']);
        $this->assertNotContains('inventory', $topicHeavy['next']['least_covered_live_apps'], 'the app just covered is not first in line');
        $this->assertSame('books', $topicHeavy['next']['least_covered_live_apps'][0]);

        // Four live app-led posts: the fifth app-led one is Beta.
        $live4 = [
            ['category' => 'accounting', 'product_slug' => 'books'], ['category' => 'billing', 'product_slug' => 'billing'],
            ['category' => 'productivity', 'product_slug' => 'notes'], ['category' => 'productivity', 'product_slug' => 'drive'],
            ['category' => 'gst'], ['category' => 'gst'], ['category' => 'gst'], ['category' => 'gst'], ['category' => 'gst'],
        ];
        $m = BlogTaxonomy::mix($live4);
        $this->assertSame('app_led', $m['next']['kind']);
        $this->assertSame('beta', $m['next']['app_status']);
        $this->assertSame(['books' => 1, 'billing' => 1, 'notes' => 1, 'docs' => 1], $m['by_product']);

        $withBeta = BlogTaxonomy::mix(array_merge($live4, [['category' => 'payroll-hr', 'product_slug' => 'hrms']]));
        $this->assertSame(1, $withBeta['app_led_beta']);
        $this->assertSame(0.2, $withBeta['beta_share_of_app_led']);
        $this->assertSame('either', $withBeta['next']['kind']);
        $this->assertSame('live', $withBeta['next']['app_status']);

        $appHeavy = BlogTaxonomy::mix([['product_slug' => 'books'], ['product_slug' => 'notes']]);
        $this->assertSame('topic', $appHeavy['next']['kind']);
        $this->assertNull($appHeavy['next']['app_status']);
        $this->assertSame(2, $appHeavy['by_group']['uncategorised']);
    }

    public function testQueueHintsSeparateProductAffinityFromAppLed(): void
    {
        $marketing = BlogTaxonomy::queueHints('marketing', 'books', ['topic_category' => 'gst']);
        $this->assertSame('gst', $marketing['category']);
        $this->assertFalse($marketing['app_led'], 'a marketing topic that ends on a Smart Books mention is a topic article');
        $this->assertNull($marketing['product_slug']);
        $this->assertSame('books', $marketing['product_affinity']);

        $product = BlogTaxonomy::queueHints('product', 'our-people', ['topic_category' => 'payroll-hr']);
        $this->assertTrue($product['app_led']);
        $this->assertSame('our-people', $product['product_slug']);
        $this->assertSame('beta', $product['product_status']);
        $this->assertSame('Our People (Beta)', $product['present_product_as']);

        $proposed = BlogTaxonomy::queueHints('Income Tax', null);
        $this->assertSame('income-tax', $proposed['category']);
        $this->assertNull($proposed['portfolio_stream']);
    }

    public function testIngestRefusesUnknownValuesWithTheAllowedList(): void
    {
        $ok = AutomationDraftIngestService::resolveTaxonomy(['category' => 'Income Tax', 'product_slug' => 'smart_books'], 't', 'body');
        $this->assertSame(['category' => 'income-tax', 'product_slug' => 'books'], $ok);
        $this->assertSame(['category' => 'gst', 'product_slug' => null], AutomationDraftIngestService::resolveTaxonomy(['category' => 'gst', 'product_slug' => ''], 't', 'b'));

        foreach ([
            [['title' => 'x'], 'category is required'],
            [['category' => 'Marketing'], 'is not a blog category. Use one of: accounting, gst, income-tax'],
            [['category' => 'gst', 'product_slug' => 'excel'], 'is not an aicountly.com app. Use one of: books, notes, billing'],
            [['category' => 'payroll-hr', 'product_slug' => 'hrms'], 'HRMS is a Beta app: present it as Beta'],
        ] as [$payload, $message]) {
            try {
                AutomationDraftIngestService::resolveTaxonomy($payload, 'A title', 'A body that never says the word.');
                $this->fail('expected a refusal for ' . json_encode($payload));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }

        $beta = AutomationDraftIngestService::resolveTaxonomy(['category' => 'payroll-hr', 'product_slug' => 'hrms'], 'Leave policies in HRMS (Beta)', 'body');
        $this->assertSame('hrms', $beta['product_slug']);
    }
}
