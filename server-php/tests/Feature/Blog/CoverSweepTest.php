<?php

namespace Tests\Feature\Blog;

use App\Libraries\Blog\BlogStateMachine;
use App\Libraries\Blog\CoverSweepService;
use App\Libraries\Blog\WorkBlockService;
use Config\Database;
use Tests\Support\ApiTestCase;

/**
 * Parking a coverless article stops it publishing; the sweep is what lets it
 * go once a cover exists.
 *
 * Without this, uploading covers fixed the gallery and left the queue exactly
 * where it was — the only thing that creates a cover work block is the state
 * machine's fact_verified successor, and a parked article is past that.
 */
final class CoverSweepTest extends ApiTestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! self::hasTestDatabase()) {
            return;
        }

        $_ENV['REACH_AI_MOCK'] = 'true';
        $_ENV['BLOG_AUTOMATION_ENABLED'] = 'true';
        $_ENV['BLOG_REQUIRE_COVER_IMAGE'] = 'true';
        $_ENV['MEDIA_SIGNING_KEY'] = str_repeat('a', 64);
        unset($_ENV['BLOG_IMAGE_GENERATION_ENABLED']);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->tempFiles = [];
        parent::tearDown();
    }

    private function seedParkedArticle(string $title): int
    {
        $db  = Database::connect();
        $now = date('Y-m-d H:i:s');

        $db->table('reach_content_items')->insert([
            'uuid'            => bin2hex(random_bytes(16)),
            'content_type'    => 'blog',
            'title'           => $title,
            'slug'            => 'sweep-' . bin2hex(random_bytes(5)),
            'workflow_status' => BlogStateMachine::INTERNAL_REVIEW,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);
        $itemId = (int) $db->insertID();

        $db->table('reach_content_blog_details')->insert([
            'content_item_id' => $itemId,
            'origin'          => 'claude_routine',
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        // The verdict the cover block left behind when it parked the article.
        $db->table('reach_content_validations')->insert([
            'content_item_id'   => $itemId,
            'validation_type'   => 'cover_image',
            'validation_status' => 'failed',
            'message'           => 'No suitable cover image could be sourced: gallery_no_relevant_cover',
            'run_at'            => $now,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        return $itemId;
    }

    private function seedGalleryAsset(array $tags): int
    {
        $db   = Database::connect();
        $key  = bin2hex(random_bytes(8));
        $path = sys_get_temp_dir() . '/sweep-' . $key . '.webp';
        file_put_contents($path, 'fake-webp-binary');
        $this->tempFiles[] = $path;

        $db->table('reach_media_gallery_assets')->insert([
            'asset_uuid'      => 'swp-' . $key,
            'kind'            => 'gallery_upload',
            'file_path'       => $path,
            'mime'            => 'image/webp',
            'bytes'           => 16,
            'checksum_sha256' => hash('sha256', $key),
            'category_tags'   => json_encode($tags),
            'status'          => 'active',
        ]);

        return (int) $db->insertID();
    }

    public function testAParkedArticleIsRequeuedForAnotherAttempt(): void
    {
        if (! self::hasTestDatabase()) {
            $this->markTestSkipped(self::missingTestDatabaseReason());
        }

        $itemId = $this->seedParkedArticle('GST returns filing deadlines');

        $result = (new CoverSweepService())->sweep();

        $this->assertSame(1, $result['requeued']);
        $this->assertContains($itemId, $result['content_item_ids']);

        $block = Database::connect()->table('reach_work_blocks')
            ->where('content_item_id', $itemId)
            ->where('block_type', WorkBlockService::TYPE_GENERATE_IMAGE)
            ->get()->getRowArray();

        $this->assertNotNull($block);
        $this->assertSame('eligible', $block['eligibility_status']);
    }

    public function testTheRequeuedBlockAssignsACoverOnceOneExists(): void
    {
        if (! self::hasTestDatabase()) {
            $this->markTestSkipped(self::missingTestDatabaseReason());
        }

        $db     = Database::connect();
        $itemId = $this->seedParkedArticle('GST returns filing deadlines');
        $this->seedGalleryAsset(['gst', 'returns']);

        (new CoverSweepService())->sweep();

        $blockId = (int) $db->table('reach_work_blocks')
            ->where('content_item_id', $itemId)
            ->where('block_type', WorkBlockService::TYPE_GENERATE_IMAGE)
            ->get()->getRowArray()['id'];

        $output = (new WorkBlockService())->execute($blockId);

        $this->assertTrue($output['gallery_assigned'] ?? false, json_encode($output));

        $profile = $db->table('reach_blog_publication_profiles')
            ->where('content_item_id', $itemId)->get()->getRowArray();
        $this->assertNotEmpty($profile['featured_image_reference'] ?? '');

        // The failure that blocked publication must clear, not linger.
        $validation = $db->table('reach_content_validations')
            ->where('content_item_id', $itemId)
            ->where('validation_type', 'cover_image')
            ->get()->getRowArray();
        $this->assertSame('passed', $validation['validation_status']);
    }

    public function testAnArticleThatStillHasNoCoverStaysParked(): void
    {
        if (! self::hasTestDatabase()) {
            $this->markTestSkipped(self::missingTestDatabaseReason());
        }

        $db     = Database::connect();
        $itemId = $this->seedParkedArticle('GST returns filing deadlines');
        $this->seedGalleryAsset(['travel', 'landscape']); // irrelevant to the article

        (new CoverSweepService())->sweep();
        $blockId = (int) $db->table('reach_work_blocks')
            ->where('content_item_id', $itemId)->get()->getRowArray()['id'];

        $output = (new WorkBlockService())->execute($blockId);

        $this->assertTrue($output['image_failed'] ?? false);
        $this->assertSame(
            'failed',
            $db->table('reach_content_validations')
                ->where('content_item_id', $itemId)
                ->where('validation_type', 'cover_image')
                ->get()->getRowArray()['validation_status'],
        );
    }

    public function testSweepingTwiceInADayDoesNotPileUpWorkBlocks(): void
    {
        if (! self::hasTestDatabase()) {
            $this->markTestSkipped(self::missingTestDatabaseReason());
        }

        $itemId = $this->seedParkedArticle('GST returns filing deadlines');

        $first  = (new CoverSweepService())->sweep();
        $second = (new CoverSweepService())->sweep();

        $this->assertSame(1, $first['requeued']);
        $this->assertSame(0, $second['requeued'], 'An hourly sweep must not accumulate blocks against one article.');
        $this->assertSame(1, $second['skipped']);

        $blocks = Database::connect()->table('reach_work_blocks')
            ->where('content_item_id', $itemId)
            ->countAllResults();
        $this->assertSame(1, $blocks);
    }

    public function testAWaivedCoverFailureIsLeftAlone(): void
    {
        if (! self::hasTestDatabase()) {
            $this->markTestSkipped(self::missingTestDatabaseReason());
        }

        $db     = Database::connect();
        $itemId = $this->seedParkedArticle('GST returns filing deadlines');
        $db->table('reach_content_validations')
            ->where('content_item_id', $itemId)
            ->update(['waiver_reason' => 'Text-only announcement', 'waived_at' => date('Y-m-d H:i:s')]);

        $result = (new CoverSweepService())->sweep();

        $this->assertSame(0, $result['requeued'], 'A superadmin decided this one may publish without a hero.');
    }

    public function testArticlesThatAlreadyHaveACoverAreNotSwept(): void
    {
        if (! self::hasTestDatabase()) {
            $this->markTestSkipped(self::missingTestDatabaseReason());
        }

        $db     = Database::connect();
        $itemId = $this->seedParkedArticle('GST returns filing deadlines');
        $db->table('reach_blog_publication_profiles')->insert([
            'content_item_id'          => $itemId,
            'featured_image_reference' => 'https://reach.aicountly.org/api/v1/public/media/x.webp?sig=abc',
            'created_at'               => date('Y-m-d H:i:s'),
            'updated_at'               => date('Y-m-d H:i:s'),
        ]);

        $this->assertSame(0, (new CoverSweepService())->sweep()['requeued']);
    }
}
