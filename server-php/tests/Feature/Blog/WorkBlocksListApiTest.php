<?php

declare(strict_types=1);

namespace Tests\Feature\Blog;

use Tests\Support\ApiTestCase;

/**
 * Blog Command Centre AI Ops "work blocks queued" → v1/blog-command-centre/work-blocks.
 * Same predicate as the overview count; ?run_id= lists the blocks one
 * optimiser run's decisions queued. Route registered here until it lands in
 * Routes.php.
 *
 * @internal
 */
final class WorkBlocksListApiTest extends ApiTestCase
{
    /** @var array<string, int> */
    private array $blocks = [];
    private int $itemId;

    protected function setUp(): void
    {
        parent::setUp();
        $db = \Config\Database::connect();

        $db->table('reach_content_items')->insert(['content_type' => 'blog', 'title' => 'Queued post', 'slug' => 'queued-post', 'workflow_status' => 'brief_ready']);
        $this->itemId = (int) $db->insertID();

        foreach (['q1' => ['queued', 'brief'], 'q2' => ['queued', 'draft'], 'p1' => ['pending', 'brief'], 'c1' => ['completed', 'brief']] as $key => [$status, $type]) {
            $db->table('reach_work_blocks')->insert([
                'block_type' => $type, 'eligibility_status' => $status,
                'content_item_id' => $key === 'q1' ? $this->itemId : null,
            ]);
            $this->blocks[$key] = (int) $db->insertID();
        }

        $db->table('reach_optimizer_runs')->insert(['run_for_date' => '2026-10-20', 'status' => 'completed']);
        $run = (int) $db->insertID();
        $db->table('reach_roadmap_decisions')->insert([
            'decision' => 'queue_brief', 'optimizer_run_id' => $run, 'work_block_id' => $this->blocks['q1'],
            'decided_for_date' => '2026-10-20', 'content_item_id' => $this->itemId,
        ]);
        $db->table('reach_roadmap_decisions')->insert([
            'decision' => 'queue_brief', 'optimizer_run_id' => $run, 'work_block_id' => $this->blocks['c1'], 'decided_for_date' => '2026-10-20',
        ]);
        $db->table('reach_roadmap_decisions')->insert(['decision' => 'skip', 'optimizer_run_id' => $run, 'decided_for_date' => '2026-10-20']);
        $this->blocks['run'] = $run;
    }

    /** @return array<string, mixed> */
    private function fetch(string $path): array
    {
        $headers = $this->authAs('super_admin');
        $this->withRoutes([
            ['GET', 'v1/blog-command-centre/work-blocks', '\App\Controllers\BlogCommandCentreController::workBlocks', ['filter' => ['jwt', 'permission:blog.view']]],
        ]);
        $res = $this->withHeaders($headers)->get($path);
        $res->assertStatus(200);

        return json_decode((string) $res->getJSON(), true)['data'];
    }

    public function testQueuedCountMatchesTheList(): void
    {
        $overview = $this->fetch('v1/blog-command-centre/overview');
        $list     = $this->fetch('v1/blog-command-centre/work-blocks?status=queued');

        $this->assertSame(2, $overview['work_blocks']['queued']);
        $this->assertSame($overview['work_blocks']['queued'], $list['total']);
        $this->assertEqualsCanonicalizing([$this->blocks['q1'], $this->blocks['q2']], array_map('intval', array_column($list['items'], 'id')));
    }

    public function testRowsCarryTheirContentAndDecision(): void
    {
        $items = $this->fetch('v1/blog-command-centre/work-blocks?status=queued&block_type=brief')['items'];
        $this->assertCount(1, $items);
        $this->assertSame('Queued post', $items[0]['content_title']);
        $this->assertSame($this->itemId, (int) $items[0]['content_item_id']);
        $this->assertSame('queue_brief', $items[0]['decision']);
        $this->assertSame($this->blocks['run'], (int) $items[0]['optimizer_run_id']);
    }

    public function testRunFilterListsTheBlocksItsDecisionsQueued(): void
    {
        $list = $this->fetch('v1/blog-command-centre/work-blocks?run_id=' . $this->blocks['run']);
        $this->assertSame(2, $list['total']);
        $this->assertEqualsCanonicalizing([$this->blocks['q1'], $this->blocks['c1']], array_map('intval', array_column($list['items'], 'id')));

        $this->assertSame(4, $this->fetch('v1/blog-command-centre/work-blocks')['total']);
        $this->assertSame(2, $this->fetch('v1/blog-command-centre/work-blocks?status=pending,completed')['total']);
    }
}
