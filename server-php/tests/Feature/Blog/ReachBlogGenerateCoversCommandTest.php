<?php

namespace Tests\Feature\Blog;

use Config\Database;
use Tests\Support\DatabaseTestCase;

/**
 * Cover for queueing cover-image generation.
 *
 * Seven blog items sat in publish_queued with no featured image, so every
 * dispatch died on "Featured image is not assigned; a suitable cover is
 * required before publication". A cover cannot be derived from existing data
 * the way SEO metadata can — it has to be generated, which costs provider
 * spend. So this command only ever queues work, never generates inline, and
 * refuses to queue blocks that are certain to fail.
 */
final class ReachBlogGenerateCoversCommandTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['BLOG_IMAGE_GENERATION_ENABLED'] = 'true';
        $_ENV['AI_OPENAI_API_KEY']             = 'test-key-for-configured-check';
    }

    protected function tearDown(): void
    {
        unset($_ENV['BLOG_IMAGE_GENERATION_ENABLED'], $_ENV['AI_OPENAI_API_KEY']);
        parent::tearDown();
    }

    private function seedItem(string $title, ?string $cover = null, string $state = 'publish_queued'): int
    {
        $db = Database::connect();
        $db->table('reach_content_items')->insert([
            'title'           => $title,
            'slug'            => strtolower(str_replace(' ', '-', $title)),
            'content_type'    => 'blog',
            'workflow_status' => $state,
        ]);
        $itemId = (int) $db->insertID();

        $db->table('reach_blog_publication_profiles')->insert([
            'content_item_id'          => $itemId,
            'featured_image_reference' => $cover,
        ]);

        return $itemId;
    }

    private function coverBlocks(int $itemId): array
    {
        return Database::connect()->table('reach_work_blocks')
            ->where('content_item_id', $itemId)
            ->where('block_type', 'GENERATE_IMAGE')
            ->get()->getResultArray();
    }

    public function testDryRunQueuesNothing(): void
    {
        $id = $this->seedItem('MCA annual filing calendar');

        command('reach:blog-generate-covers');

        $this->assertSame([], $this->coverBlocks($id));
    }

    public function testApplyQueuesOneBlockPerItem(): void
    {
        $a = $this->seedItem('MCA annual filing calendar');
        $b = $this->seedItem('Income tax compliance');

        command('reach:blog-generate-covers --apply');

        $this->assertCount(1, $this->coverBlocks($a));
        $this->assertCount(1, $this->coverBlocks($b));
        $this->assertSame('eligible', $this->coverBlocks($a)[0]['eligibility_status']);
    }

    public function testItemsThatAlreadyHaveACoverAreSkipped(): void
    {
        $withCover = $this->seedItem('Has a cover', 'https://cdn.example.test/cover.png');

        command('reach:blog-generate-covers --apply');

        $this->assertSame([], $this->coverBlocks($withCover));
    }

    public function testRunningTwiceDoesNotStackDuplicateBlocks(): void
    {
        $id = $this->seedItem('MCA annual filing calendar');

        command('reach:blog-generate-covers --apply');
        command('reach:blog-generate-covers --apply');

        $this->assertCount(1, $this->coverBlocks($id), 'The idempotency key must re-open, not duplicate');
    }

    public function testAFailedCoverBlockIsReopenedRatherThanDuplicated(): void
    {
        $id = $this->seedItem('MCA annual filing calendar');
        command('reach:blog-generate-covers --apply');

        Database::connect()->table('reach_work_blocks')
            ->where('id', (int) $this->coverBlocks($id)[0]['id'])
            ->update(['eligibility_status' => 'failed']);

        command('reach:blog-generate-covers --apply');

        $blocks = $this->coverBlocks($id);
        $this->assertCount(1, $blocks);
        $this->assertSame('eligible', $blocks[0]['eligibility_status'], 'A failed attempt must become runnable again');
    }

    public function testNothingIsQueuedWhenImageGenerationIsDisabled(): void
    {
        // Queueing blocks that will certainly fail just moves the failure and
        // leaves work-block noise behind.
        $_ENV['BLOG_IMAGE_GENERATION_ENABLED'] = 'false';
        $id = $this->seedItem('MCA annual filing calendar');

        command('reach:blog-generate-covers --apply');

        $this->assertSame([], $this->coverBlocks($id));
    }

    public function testNothingIsQueuedWhenNoImageProviderIsConfigured(): void
    {
        unset($_ENV['AI_OPENAI_API_KEY']);
        $_ENV['AI_GEMINI_API_KEY'] = '';
        $id = $this->seedItem('MCA annual filing calendar');

        command('reach:blog-generate-covers --apply');

        $this->assertSame([], $this->coverBlocks($id));
    }

    public function testItemsBeforeApprovalAreOutOfScope(): void
    {
        $draft = $this->seedItem('Still drafting', null, 'draft');

        command('reach:blog-generate-covers --apply');

        $this->assertSame([], $this->coverBlocks($draft));
    }
}
