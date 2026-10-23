<?php

declare(strict_types=1);

namespace Tests\Feature\Blog;

use App\Libraries\Blog\BlogRecategoriseService;
use App\Libraries\Database\SchemaGuard;
use App\Libraries\Publishing\Jobs\PublicationDeploymentService;
use Config\Database;
use Tests\Support\DatabaseTestCase;

/**
 * `reach:blog-recategorise`: old categories are canonicalised, product_slug is
 * filled only with evidence (never from a mere product affinity of a topic
 * article), the dry run writes nothing, article text is never touched, and
 * --republish queues the normal deployment only for changed live posts.
 *
 * @internal
 */
final class ReachBlogRecategoriseCommandTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        SchemaGuard::reset();
    }

    private function seedBlog(string $title, ?string $category, string $workflow = 'live', ?string $stream = null, ?string $key = null, ?string $product = null): int
    {
        $db = Database::connect();
        $db->table('reach_content_items')->insert([
            'content_type' => 'blog', 'title' => $title, 'slug' => strtolower(trim(preg_replace('/\W+/', '-', $title), '-')),
            'workflow_status' => $workflow, 'approval_status' => 'approved', 'content_base_key' => $key,
        ]);
        $id = (int) $db->insertID();
        $db->table('reach_blog_publication_profiles')->insert(['content_item_id' => $id, 'category' => $category, 'product_slug' => $product]);
        if ($stream !== null) {
            $db->table('reach_content_blog_details')->insert(['content_item_id' => $id, 'portfolio_stream' => $stream]);
        }
        $db->table('reach_content_versions')->insert(['content_item_id' => $id, 'version_number' => 1, 'title' => $title, 'body_html' => '<p>Original text.</p>']);
        $db->table('reach_content_items')->where('id', $id)->update(['current_version_id' => (int) $db->insertID()]);

        return $id;
    }

    private function profile(int $id): array
    {
        return Database::connect()->table('reach_blog_publication_profiles')->where('content_item_id', $id)->get()->getRowArray();
    }

    public function testDryRunReportsAndApplyRefilesWithEvidence(): void
    {
        $db   = Database::connect();
        $gst  = $this->seedBlog('GSTR-3B late fee explained', 'GST');
        $tds  = $this->seedBlog('TDS on rent: who deducts and when', 'TDS', 'draft');
        $book = $this->seedBlog('Bank reconciliation in Smart Books, step by step', 'Product guides', 'live', 'product');
        $none = $this->seedBlog('How to read your GSTR-2B statement', 'marketing');
        $odd  = $this->seedBlog('Five habits of calm founders', 'engineering');
        $mkt  = $this->seedBlog('Schedule III financial statements explained', 'financial-reporting', 'live', 'marketing', 'financial-reporting-schedule-iii');
        $hr   = $this->seedBlog('Employee self-service that HR trusts', 'payroll', 'live', 'product', 'our-people-employee-selfservice');
        $db->table('reach_content_base_entries')->insert(['content_type' => 'blog', 'entry_key' => 'financial-reporting-schedule-iii', 'title' => 'Schedule III', 'product_slug' => 'financial-reporting', 'category' => 'marketing', 'origin' => 'seed', 'status' => 'published']);
        $db->table('reach_content_base_entries')->insert(['content_type' => 'blog', 'entry_key' => 'our-people-employee-selfservice', 'title' => 'ESS', 'product_slug' => 'our-people', 'category' => 'product', 'origin' => 'seed', 'status' => 'published']);
        $versionsBefore = $db->table('reach_content_versions')->get()->getResultArray();

        command('reach:blog-recategorise');
        $this->assertSame('GST', $this->profile($gst)['category'], 'a dry run writes nothing');

        $service = new BlogRecategoriseService($db);
        $plan    = $service->plan();
        $rows    = array_column($plan['rows'], null, 'content_item_id');
        $this->assertSame('gst', $rows[$gst]['category_after']);
        $this->assertStringContainsString('(alias)', $rows[$gst]['evidence'][0]);
        $this->assertSame('tds-tcs', $rows[$tds]['category_after']);
        $this->assertSame('product-guides', $rows[$book]['category_after']);
        $this->assertSame('books', $rows[$book]['product_after']);
        $this->assertStringContainsString('title names the app', implode(' ', $rows[$book]['evidence']));
        $this->assertSame('gst', $rows[$none]['category_after'], 'an unmappable category falls back to an unambiguous title word');
        $this->assertNull($rows[$odd]['category_after'], 'nothing certain: left as is');
        $this->assertFalse($rows[$odd]['changed']);
        $this->assertSame('accounting', $rows[$mkt]['category_after']);
        $this->assertNull($rows[$mkt]['product_after'], 'a marketing-stream post with only a product affinity stays a topic article');
        $this->assertStringContainsString('not applied', $rows[$mkt]['notes'][0]);
        $this->assertSame('payroll-hr', $rows[$hr]['category_after']);
        $this->assertSame('our-people', $rows[$hr]['product_after'], 'a product-stream post takes the content-base entry\'s app');
        $this->assertSame(['GST' => 1, 'Product guides' => 1, 'TDS' => 1, 'engineering' => 1, 'financial-reporting' => 1, 'marketing' => 1, 'payroll' => 1], $plan['before']['by_category']);
        $this->assertSame(2, $plan['after']['app_led']);
        $this->assertSame(1, $plan['after']['app_led_beta']);
        $this->assertSame([$odd], array_column($plan['unmapped'], 'content_item_id'));

        command('reach:blog-recategorise --apply');
        $this->assertSame('gst', $this->profile($gst)['category']);
        $this->assertSame('books', $this->profile($book)['product_slug']);
        $this->assertSame('engineering', $this->profile($odd)['category']);
        $this->assertSame($versionsBefore, $db->table('reach_content_versions')->get()->getResultArray(), 'article text is never touched');
        $this->assertSame(0, (new BlogRecategoriseService($db))->plan()['changed'], 'idempotent');
        $this->assertSame(0, $db->table('reach_publication_deployments')->countAllResults(), 'no republish without --republish');
    }

    public function testRepublishQueuesOnlyChangedLivePosts(): void
    {
        $live  = $this->seedBlog('GSTR-1 amendments made simple', 'GST');
        $draft = $this->seedBlog('TDS on rent: who deducts and when', 'TDS', 'draft');
        $same  = $this->seedBlog('Advance tax for freelancers', 'income-tax');

        $queued = [];
        $deployments = new class ($queued) extends PublicationDeploymentService {
            public function __construct(public array &$seen)
            {
            }

            public function enqueuePublication(int $contentItemId, int $contentVersionId, string $connectionKey = 'aicountly_com', string $operation = 'publish', ?string $scheduledAt = null, ?int $createdBy = null): int
            {
                $this->seen[] = [$contentItemId, $contentVersionId, $operation];

                return 500 + $contentItemId;
            }
        };

        $service = new BlogRecategoriseService(Database::connect());
        $plan    = $service->plan();
        $service->apply($plan);
        $out = $service->republish($plan, $deployments);

        $this->assertSame([$live], array_column($out, 'content_item_id'), 'only changed posts that are live are republished');
        $this->assertTrue($out[0]['queued']);
        $this->assertSame(500 + $live, $out[0]['deployment_id']);
        $this->assertSame('publish', $queued[0][2]);
        $this->assertNotContains($draft, array_column($queued, 0));
        $this->assertNotContains($same, array_column($queued, 0));
    }

    public function testRepublishNeedsApply(): void
    {
        $id = $this->seedBlog('GSTR-1 amendments made simple', 'GST');
        command('reach:blog-recategorise --republish');
        $this->assertSame('GST', $this->profile($id)['category']);
    }

    public function testTheOwnerCanFileOnePostByHand(): void
    {
        $stock = $this->seedBlog('Physical stock verification: a practical year-end routine', 'Accounting');
        $hr    = $this->seedBlog('The complete payroll compliance checklist for Indian startups', null, 'publish_queued', 'problem_to_product');
        $other = $this->seedBlog('GSTR-1 amendments made simple', 'GST');

        command("reach:blog-recategorise --id {$stock} --category inventory-stock --product inventory --apply");
        $this->assertSame('inventory-stock', $this->profile($stock)['category']);
        $this->assertSame('inventory', $this->profile($stock)['product_slug']);
        $this->assertSame('GST', $this->profile($other)['category'], 'only the --id post is touched');

        command("reach:blog-recategorise --id {$hr} --category Payroll --product none --apply");
        $this->assertSame('payroll-hr', $this->profile($hr)['category'], 'a known label is accepted');
        $this->assertNull($this->profile($hr)['product_slug'], '--product=none makes it a topic article');

        command("reach:blog-recategorise --id {$stock} --category astrology --apply");
        $this->assertSame('inventory-stock', $this->profile($stock)['category'], 'an unknown category is refused');
        command("reach:blog-recategorise --category gst --apply");
        $this->assertSame('GST', $this->profile($other)['category'], '--category without --id is refused');
    }
}
