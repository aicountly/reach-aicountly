<?php

namespace Tests\Feature\Blog;

use App\Libraries\Blog\BlogStateMachine;
use App\Libraries\Blog\WorkBlockService;
use Config\Database;
use RuntimeException;
use Tests\Support\DatabaseTestCase;

/**
 * publish_queued must mean "this is going out", not "this is stuck".
 *
 * Seven items sat in publish_queued for days with no SEO profile and no cover.
 * Every dispatch failed the publication gate, the workflow status kept saying
 * queued, and nothing reconciled the two — the failure was only discoverable
 * by reading worker logs. The gate now runs at the transition, so an item that
 * cannot be published stays where it is and the caller is told why.
 */
final class BlogStateMachinePublishQueuedGateTest extends DatabaseTestCase
{
    private function seedApproved(string $title): int
    {
        $db = Database::connect();
        $db->table('reach_content_items')->insert([
            'title'           => $title,
            'slug'            => strtolower(str_replace(' ', '-', $title)),
            'content_type'    => 'blog',
            'workflow_status' => 'approved',
        ]);
        $itemId = (int) $db->insertID();

        $db->table('reach_content_blog_details')->insert(['content_item_id' => $itemId]);

        return $itemId;
    }

    private function statusOf(int $id): string
    {
        return (string) Database::connect()->table('reach_content_items')
            ->select('workflow_status')->where('id', $id)->get()->getRowArray()['workflow_status'];
    }

    public function testAnItemThatCannotPublishIsRefusedTheTransition(): void
    {
        $id = $this->seedApproved('MCA annual filing calendar');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Content is not ready for publication');

        (new BlogStateMachine(new WorkBlockService()))
            ->transition($id, BlogStateMachine::PUBLISH_QUEUED);
    }

    public function testTheRefusalNamesTheBlockers(): void
    {
        $id = $this->seedApproved('MCA annual filing calendar');

        try {
            (new BlogStateMachine(new WorkBlockService()))
                ->transition($id, BlogStateMachine::PUBLISH_QUEUED);
            $this->fail('The transition should have been refused');
        } catch (RuntimeException $e) {
            // The reasons are the whole point — a bare refusal would be no
            // better than the silent dead end it replaces.
            $this->assertMatchesRegularExpression('/SEO profile|Featured image|approved|Body/i', $e->getMessage());
        }
    }

    public function testTheItemStaysWhereItWas(): void
    {
        $id = $this->seedApproved('MCA annual filing calendar');

        try {
            (new BlogStateMachine(new WorkBlockService()))
                ->transition($id, BlogStateMachine::PUBLISH_QUEUED);
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame('approved', $this->statusOf($id), 'A refused transition must not half-apply');
    }

    public function testNoDoomedPublishBlockIsQueued(): void
    {
        $id = $this->seedApproved('MCA annual filing calendar');

        try {
            (new BlogStateMachine(new WorkBlockService()))
                ->transition($id, BlogStateMachine::PUBLISH_QUEUED);
        } catch (RuntimeException) {
            // expected
        }

        $blocks = Database::connect()->table('reach_work_blocks')
            ->where('content_item_id', $id)
            ->where('block_type', 'PUBLISH_BLOG')
            ->countAllResults();

        $this->assertSame(0, $blocks, 'The successor block is what kept re-failing');
    }

    public function testOtherTransitionsAreUnaffected(): void
    {
        $id = $this->seedApproved('MCA annual filing calendar');

        (new BlogStateMachine(new WorkBlockService()))
            ->transition($id, BlogStateMachine::ARCHIVED);

        $this->assertSame('archived', $this->statusOf($id));
    }
}
