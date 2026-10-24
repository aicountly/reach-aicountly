<?php

declare(strict_types=1);

namespace Tests\Unit\Automation;

use App\Libraries\Automation\Structured\ProductCatalog;
use App\Libraries\Automation\Structured\StructuredPayloadValidator;
use App\Libraries\Blog\AutomationDraftIngestService;
use App\Libraries\Blog\BlogTaxonomy;
use PHPUnit\Framework\TestCase;

/**
 * Aicountly Academy and its courses (Edge, Aspire) are a "course" kind in the
 * catalogue: valid product_slugs that are never Beta software, never get
 * release notes or workflow guides, and leave the launch line-up alone.
 *
 * @covers \App\Libraries\Automation\Structured\ProductCatalog
 * @covers \App\Libraries\Blog\BlogTaxonomy
 *
 * @internal
 */
final class AcademyCatalogueTest extends TestCase
{
    public function testAcademyAndCoursesAreCataloguedAsCoursesNotBeta(): void
    {
        $this->assertSame(['academy', 'edge', 'aspire'], ProductCatalog::ACADEMY);
        foreach (ProductCatalog::ACADEMY as $slug) {
            $this->assertTrue(ProductCatalog::has($slug), $slug);
            $this->assertTrue(ProductCatalog::isAcademy($slug), $slug);
            $this->assertFalse(ProductCatalog::isBeta($slug), "{$slug} is not Beta");
            $this->assertFalse(ProductCatalog::isLaunch($slug), $slug);
            $this->assertFalse(ProductCatalog::isSoftware($slug), $slug);
            $this->assertSame('course', ProductCatalog::status($slug));
            $p = ProductCatalog::get($slug);
            $this->assertSame(ProductCatalog::KIND_COURSE, $p['kind']);
            $this->assertTrue($p['academy']);
            $this->assertFalse($p['launch']);
            $this->assertNull($p['hand_release']);
            $this->assertStringStartsWith('Aicountly ', $p['name']);
        }

        $this->assertSame('https://www.aicountly.com/academy', ProductCatalog::get('academy')['public_url']);
        $this->assertSame('https://www.aicountly.com/edge', ProductCatalog::get('edge')['public_url']);
        $this->assertSame('https://www.aicountly.com/aspire', ProductCatalog::get('aspire')['public_url']);
        $this->assertNull(ProductCatalog::get('academy')['repo'], 'the Academy repository is the internal staff app — never a source for public copy');
        $this->assertSame('aicountly/edge-aicountly', ProductCatalog::get('edge')['repo_full_name']);
        $this->assertStringContainsString('6–12 month', ProductCatalog::get('edge')['summary']);

        // Software is unchanged: launch apps first, Beta after, and Beta stays Beta.
        $this->assertSame(ProductCatalog::LAUNCH, array_column(ProductCatalog::launch(), 'slug'));
        $this->assertSame('beta', ProductCatalog::status('hrms'));
        $this->assertSame('live', ProductCatalog::status('books'));
        $this->assertSame(ProductCatalog::KIND_APP, ProductCatalog::get('hrms')['kind']);
        $this->assertNull(ProductCatalog::status('not-a-product'));

        $software = array_column(ProductCatalog::software(), 'slug');
        $all      = array_column(ProductCatalog::all(), 'slug');
        $this->assertSame(array_merge($software, ProductCatalog::ACADEMY), $all, 'courses come last in all()');
        $this->assertEmpty(array_intersect(ProductCatalog::ACADEMY, $software));
    }

    public function testCoursesGetNoReleaseNotesOrWorkflowGuides(): void
    {
        foreach (['edge', 'aspire', 'academy'] as $slug) {
            $errors = [];
            StructuredPayloadValidator::productSlug($slug, $errors);
            $this->assertCount(1, $errors, $slug);
            $this->assertStringContainsString('Aicountly Academy course, not an app', $errors[0]);
        }
        $errors = [];
        StructuredPayloadValidator::productSlug('hrms', $errors);
        $this->assertSame([], $errors, 'Beta apps still take release notes');
    }

    public function testBlogTaxonomyAcceptsCoursesWithoutBetaWording(): void
    {
        foreach (['academy', 'edge', 'aspire'] as $slug) {
            $this->assertSame($slug, BlogTaxonomy::canonicalProduct($slug));
            $this->assertFalse(BlogTaxonomy::isBetaProduct($slug));
            $this->assertFalse(BlogTaxonomy::isLiveProduct($slug));
            $this->assertTrue(BlogTaxonomy::isAcademyProduct($slug));
            $this->assertSame('course', BlogTaxonomy::productStatus($slug));
            $this->assertContains($slug, BlogTaxonomy::productSlugs());
        }
        $this->assertSame('edge', BlogTaxonomy::canonicalProduct('Aicountly Edge'));
        $this->assertSame('aspire', BlogTaxonomy::canonicalProduct('aicountly_aspire'));
        $this->assertSame('academy', BlogTaxonomy::canonicalProduct('Aicountly Academy'));
        $this->assertSame('Aicountly Edge', BlogTaxonomy::presentAs('edge'));
        $this->assertSame('HRMS (Beta)', BlogTaxonomy::presentAs('hrms'));

        // The draft ingest no longer asks a course article to say "Beta".
        $ok = AutomationDraftIngestService::resolveTaxonomy(
            ['category' => 'accounting', 'product_slug' => 'edge'],
            'What a year of hands-on accounting training looks like',
            'Aicountly Edge is a long-term programme run by Aicountly Academy.'
        );
        $this->assertSame(['category' => 'accounting', 'product_slug' => 'edge'], $ok);

        // …while a Beta app still must.
        $this->expectException(\InvalidArgumentException::class);
        AutomationDraftIngestService::resolveTaxonomy(['category' => 'payroll-hr', 'product_slug' => 'hrms'], 'Leave in HRMS', 'No such word here.');
    }

    public function testRoutineListsTheCoursesSeparatelyFromLiveAndBeta(): void
    {
        $routine = BlogTaxonomy::productsForRoutine();
        $this->assertSame(['academy', 'edge', 'aspire'], array_column($routine['academy'], 'product_slug'));
        $this->assertNotContains('edge', array_column($routine['beta'], 'product_slug'));
        $this->assertNotContains('aspire', array_column($routine['beta'], 'product_slug'));
        $this->assertNotContains('Aicountly Edge (Beta)', array_column($routine['beta'], 'present_as'));
        $edge = $routine['academy'][1];
        $this->assertSame('Aicountly Edge', $edge['present_as']);
        $this->assertSame('https://www.aicountly.com/edge', $edge['public_url']);

        $hint = BlogTaxonomy::queueHints('product', 'aspire', ['topic_category' => 'accounting']);
        $this->assertTrue($hint['app_led']);
        $this->assertSame('course', $hint['product_status']);
        $this->assertSame('Aicountly Aspire', $hint['present_product_as']);
    }

    public function testMixCountsCourseArticlesApartFromBeta(): void
    {
        $m = BlogTaxonomy::mix([
            ['category' => 'accounting', 'product_slug' => 'books'],
            ['category' => 'accounting', 'product_slug' => 'edge'],
            ['category' => 'payroll-hr', 'product_slug' => 'hrms'],
            ['category' => 'gst'],
        ]);
        $this->assertSame(3, $m['app_led']);
        $this->assertSame(1, $m['app_led_beta']);
        $this->assertSame(1, $m['app_led_academy']);
        $this->assertSame(1, $m['app_led_live']);
    }
}
