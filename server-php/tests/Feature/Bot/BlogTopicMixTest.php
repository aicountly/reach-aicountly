<?php

declare(strict_types=1);

namespace Tests\Feature\Bot;

use App\Libraries\Automation\Routine\BlogPlanService;
use App\Libraries\Blog\BlogMixService;
use App\Libraries\Bot\Plan\PerformanceService;
use App\Libraries\Bot\Plan\SelfTuner;
use App\Libraries\Database\SchemaGuard;
use App\Libraries\Settings\ReachConfig;
use Config\Database;
use Tests\Support\DatabaseTestCase;

/**
 * The blog topic mix on the canonical taxonomy: categories come from the
 * publication profile (old labels folded into their slug), so prefer/avoid
 * are real slugs and nothing is "Uncategorised" just because the submission
 * JSON never carried a category; the app-led vs topic share is measured
 * against the owner's 50/50 target and the steer follows it.
 *
 * @internal
 */
final class BlogTopicMixTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        SchemaGuard::reset();
        ReachConfig::flush();
    }

    private function post(string $title, ?string $category, ?string $product, string $status = 'live', bool $categoryInSubmission = false): int
    {
        $db  = Database::connect();
        $now = gmdate('Y-m-d H:i:sP', time() - 3600);
        $db->table('reach_content_items')->insert([
            'content_type' => 'blog', 'title' => $title, 'slug' => strtolower(preg_replace('/\W+/', '-', $title)),
            'workflow_status' => $status === 'rejected' ? 'rejected' : 'live', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $id = (int) $db->insertID();
        $db->table('reach_blog_publication_profiles')->insert(['content_item_id' => $id, 'category' => $category, 'product_slug' => $product]);
        $db->table('reach_routine_submissions')->insert([
            'content_type' => 'blog', 'content_item_id' => $id, 'status' => $status,
            // Before 23 Oct 2026 the category never reached the submission JSON.
            'submission_json' => json_encode($categoryInSubmission ? ['category' => $category] : ['words' => 900]),
            'created_at' => $now, 'updated_at' => $now,
        ]);

        return $id;
    }

    public function testTopicMixGroupsByCanonicalCategoryAndSteersTheAppLedShare(): void
    {
        // Old labels and new slugs for the same category, all topic articles.
        $this->post('GST one', 'GST', null);
        $this->post('GST two', 'gst', null);
        $this->post('GST three', 'gst', null, 'live', true);
        $this->post('TDS one', 'TDS', null);
        $this->post('Payroll rejected one', 'Payroll', null, 'rejected');
        $this->post('Payroll rejected two', 'payroll-hr', null, 'rejected');
        $this->post('Payroll rejected three', 'Payroll & HR', null, 'rejected');
        $this->post('Smart Books reconciliation', 'accounting', 'books');

        $mix = (new PerformanceService(Database::connect()))->topicMix(gmdate('Y-m-d'));
        $cats = array_column($mix['blog']['categories'], null, 'category');

        $this->assertArrayNotHasKey('Uncategorised', $cats);
        $this->assertArrayNotHasKey('uncategorised', $cats);
        $this->assertSame(3, $cats['gst']['submitted'], 'GST and gst are one category');
        $this->assertSame('GST', $cats['gst']['label']);
        $this->assertSame('compliance', $cats['gst']['group']);
        $this->assertSame(1, $cats['tds-tcs']['submitted']);
        $this->assertSame(3, $cats['payroll-hr']['rejected']);
        $this->assertContains('gst', $mix['blog']['prefer']);
        $this->assertSame(['payroll-hr'], $mix['blog']['avoid']);

        // Five live/in-flight posts (rejected ones do not count): 1 app-led.
        $this->assertSame(5, $mix['blog']['mix']['posts']);
        $this->assertSame(1, $mix['blog']['mix']['app_led']);
        $this->assertSame('app_led', $mix['blog']['steer']['kind']);
        $this->assertSame('live', $mix['blog']['steer']['app_status']);
        $this->assertNotContains('books', $mix['blog']['steer']['least_covered_live_apps']);
        $this->assertStringContainsString('below the 50% target', $mix['blog']['steer']['why']);
    }

    public function testSelfTunerLogsAChangedSteerAndTheBlogPlanShowsTheLiveMix(): void
    {
        $this->post('GST one', 'gst', null);
        $this->post('Billing reminders', 'billing-invoicing', 'billing');
        $this->post('Stock counts', 'inventory-stock', 'inventory');
        $this->post('Notes for meetings', 'productivity-collaboration', 'notes');
        $this->post('Drive sharing', 'productivity', 'drive');

        $db   = Database::connect();
        $mix  = (new PerformanceService($db))->topicMix(gmdate('Y-m-d'));
        $this->assertSame('topic', $mix['blog']['steer']['kind']);

        $prev = ['blog' => ['prefer' => $mix['blog']['prefer'], 'avoid' => $mix['blog']['avoid'], 'steer' => ['kind' => 'app_led', 'app_status' => 'live']], 'knowledge_base' => $mix['knowledge_base']];
        $out  = (new SelfTuner($db))->run(gmdate('Y-m-d'), ['metrics' => []], ['integrations' => []], $mix, $prev);
        $logged = array_values(array_filter($out['actions'], static fn ($a) => $a['action'] === 'topic_mix'));
        $this->assertCount(1, $logged);
        $this->assertSame('topic', $logged[0]['after']['blog_steer']);
        $this->assertSame('app_led:live', $logged[0]['before']['blog_steer']);
        $this->assertStringContainsString('Blog mix:', $logged[0]['reason']);

        $plan = (new BlogPlanService($db))->plan();
        $this->assertCount(15, $plan['taxonomy']['categories']);
        $this->assertSame(['books', 'notes', 'billing', 'inventory', 'docs', 'manage'], array_column($plan['taxonomy']['products']['live'], 'product_slug'));
        $this->assertSame(4, $plan['mix']['last_30_days']['app_led']);
        $this->assertSame(['billing' => 1, 'inventory' => 1, 'notes' => 1, 'docs' => 1], $plan['mix']['last_30_days']['by_product']);
        $this->assertSame('topic', $plan['mix']['next']['kind']);
        $this->assertSame(1, $plan['mix']['last_7_days']['by_group']['compliance']);
        $existing = array_column($plan['existing_titles'], null, 'title');
        $this->assertSame('docs', $existing['Drive sharing']['product_slug']);
        $this->assertSame('productivity-collaboration', $existing['Drive sharing']['category']);

        $this->assertSame((new BlogMixService($db))->summary()['next']['kind'], $plan['mix']['next']['kind']);
    }
}
