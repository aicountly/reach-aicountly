<?php

namespace Tests\Feature\Blog;

use App\Libraries\Blog\BlogStateMachine;
use App\Libraries\Blog\WorkBlockService;
use App\Libraries\Publishing\Blog\BlogReadinessService;
use Config\Database;
use Tests\Support\ApiTestCase;

/**
 * No blog reaches aicountly.com without a topically suitable cover.
 *
 * Two regressions are pinned here:
 *
 *  1. BLOG_IMAGE_GENERATION_ENABLED used to return before the gallery branch,
 *     so an operator who chose "gallery, not paid API" got no cover at all —
 *     and the article published with a blank listing tile anyway.
 *  2. A missing cover was only a readiness *warning*, so the pipeline walked
 *     a coverless article straight through to publication.
 */
final class BlogCoverRequirementTest extends ApiTestCase
{
    private WorkBlockService $workBlocks;

    protected function setUp(): void
    {
        parent::setUp();
        if (! self::hasTestDatabase()) {
            return;
        }

        $_ENV['REACH_AI_MOCK'] = 'true';
        $_ENV['CI_ENVIRONMENT'] = 'testing';
        $_ENV['BLOG_AUTOMATION_ENABLED'] = 'true';
        $_ENV['BLOG_REQUIRE_COVER_IMAGE'] = 'true';
        $_ENV['MEDIA_SIGNING_KEY'] = str_repeat('a', 64);
        // The paid leg stays off: this is the gallery-only configuration.
        unset($_ENV['BLOG_IMAGE_GENERATION_ENABLED']);

        $this->workBlocks = new WorkBlockService();
    }

    private function seedItem(string $title, string $status = BlogStateMachine::FACT_VERIFIED): int
    {
        $db   = Database::connect();
        $now  = date('Y-m-d H:i:s');
        $slug = 'cover-' . bin2hex(random_bytes(6));

        $db->table('reach_content_items')->insert([
            'uuid'               => bin2hex(random_bytes(16)),
            'content_type'       => 'blog',
            'title'              => $title,
            'slug'               => $slug,
            'workflow_status'    => $status,
            'created_actor_type' => 'system',
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);
        $id = (int) $db->insertID();

        $db->table('reach_content_blog_details')->insert([
            'content_item_id'  => $id,
            'origin'           => 'claude_routine',
            'portfolio_stream' => 'marketing',
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        return $id;
    }

    /**
     * The binary must exist: assignment refuses a row whose file is gone,
     * because a URL that 404s is not a cover.
     */
    private function seedGalleryAsset(array $tags): int
    {
        $db  = Database::connect();
        $key = bin2hex(random_bytes(8));
        $path = sys_get_temp_dir() . '/cover-' . $key . '.webp';
        file_put_contents($path, 'fake-webp-binary');

        $db->table('reach_media_gallery_assets')->insert([
            'asset_uuid'      => 'cov-' . $key,
            'kind'            => 'gallery_upload',
            'file_path'       => $path,
            'mime'            => 'image/webp',
            'bytes'           => 1024,
            'checksum_sha256' => hash('sha256', $key),
            'category_tags'   => json_encode($tags),
            'status'          => 'active',
        ]);

        return (int) $db->insertID();
    }

    private function runCoverBlock(int $contentItemId): array
    {
        $blockId = $this->workBlocks->create([
            'block_type'      => WorkBlockService::TYPE_GENERATE_IMAGE,
            'scope'           => 'blog',
            'content_item_id' => $contentItemId,
        ]);

        return $this->workBlocks->execute($blockId);
    }

    public function testGalleryStillAssignsACoverWhenPaidGenerationIsDisabled(): void
    {
        if (! self::hasTestDatabase()) {
            $this->markTestSkipped(self::missingTestDatabaseReason());
        }

        $itemId = $this->seedItem('GST returns filing deadlines');
        $this->seedGalleryAsset(['gst', 'returns']);

        $result = $this->runCoverBlock($itemId);

        $this->assertArrayNotHasKey('image_failed', $result, json_encode($result));
        $this->assertTrue($result['gallery_assigned'] ?? false);

        $profile = Database::connect()->table('reach_blog_publication_profiles')
            ->where('content_item_id', $itemId)->get()->getRowArray();
        $this->assertNotEmpty($profile['featured_image_reference'] ?? '');
    }

    public function testAnUnmatchedArticleIsParkedForReviewInsteadOfWalkingOn(): void
    {
        if (! self::hasTestDatabase()) {
            $this->markTestSkipped(self::missingTestDatabaseReason());
        }

        $db     = Database::connect();
        $itemId = $this->seedItem('GST returns filing deadlines');
        // Deliberately unrelated to the article — must not be assigned.
        $this->seedGalleryAsset(['travel', 'landscape']);

        $result = $this->runCoverBlock($itemId);

        $this->assertTrue($result['image_failed'] ?? false);
        $this->assertTrue($result['cover_required'] ?? false);
        $this->assertTrue($result['parked'] ?? false);

        $this->assertSame(
            BlogStateMachine::INTERNAL_REVIEW,
            $db->table('reach_content_items')->where('id', $itemId)->get()->getRowArray()['workflow_status'],
            'A coverless article must sit in the human review queue.',
        );

        // The SEO block is what walks an item towards publication; parking
        // must not chain it.
        $chained = $db->table('reach_work_blocks')
            ->where('content_item_id', $itemId)
            ->where('block_type', WorkBlockService::TYPE_SEO_OPTIMIZE)
            ->countAllResults();
        $this->assertSame(0, $chained, 'Parking must not chain the SEO step.');

        $validation = $db->table('reach_content_validations')
            ->where('content_item_id', $itemId)
            ->where('validation_type', 'cover_image')
            ->get()->getRowArray();
        $this->assertSame('failed', $validation['validation_status'] ?? null);
        $this->assertStringContainsString('No suitable cover', (string) ($validation['message'] ?? ''));
    }

    public function testReadinessBlocksPublicationWithoutACover(): void
    {
        if (! self::hasTestDatabase()) {
            $this->markTestSkipped(self::missingTestDatabaseReason());
        }

        $db     = Database::connect();
        $itemId = $this->seedItem('GST returns filing deadlines', BlogStateMachine::APPROVED);
        $db->table('reach_blog_publication_profiles')->insert([
            'content_item_id'  => $itemId,
            'author_reference' => 'aicountly-editorial',
            'created_at'       => date('Y-m-d H:i:s'),
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);

        $blocking = (new BlogReadinessService())->evaluate($itemId)['blocking'];
        $this->assertContains(
            'Featured image is not assigned; a suitable cover is required before publication',
            $blocking,
        );
    }

    public function testWaivingTheCoverValidationDowngradesTheGateToAWarning(): void
    {
        if (! self::hasTestDatabase()) {
            $this->markTestSkipped(self::missingTestDatabaseReason());
        }

        $db     = Database::connect();
        $itemId = $this->seedItem('GST returns filing deadlines', BlogStateMachine::APPROVED);
        $db->table('reach_blog_publication_profiles')->insert([
            'content_item_id'  => $itemId,
            'author_reference' => 'aicountly-editorial',
            'created_at'       => date('Y-m-d H:i:s'),
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);
        $db->table('reach_content_validations')->insert([
            'content_item_id'   => $itemId,
            'validation_type'   => 'cover_image',
            'validation_status' => 'failed',
            'message'           => 'No suitable cover image could be sourced',
            'waiver_reason'     => 'Editorial decision: text-only announcement',
            'waived_at'         => date('Y-m-d H:i:s'),
            'run_at'            => date('Y-m-d H:i:s'),
            'created_at'        => date('Y-m-d H:i:s'),
            'updated_at'        => date('Y-m-d H:i:s'),
        ]);

        $readiness = (new BlogReadinessService())->evaluate($itemId);

        $this->assertNotContains(
            'Featured image is not assigned; a suitable cover is required before publication',
            $readiness['blocking'],
        );
        $this->assertContains(
            'Featured image is not assigned; the listing card will render without a cover',
            $readiness['warnings'],
        );
    }

    public function testFlagOffRestoresTheOldPublishWithoutCoverBehaviour(): void
    {
        if (! self::hasTestDatabase()) {
            $this->markTestSkipped(self::missingTestDatabaseReason());
        }

        $_ENV['BLOG_REQUIRE_COVER_IMAGE'] = 'false';

        $db     = Database::connect();
        $itemId = $this->seedItem('GST returns filing deadlines');
        $this->seedGalleryAsset(['travel', 'landscape']);

        $result = $this->runCoverBlock($itemId);

        $this->assertTrue($result['image_failed'] ?? false);
        $this->assertFalse($result['cover_required'] ?? true);
        $this->assertSame(
            BlogStateMachine::FACT_VERIFIED,
            $db->table('reach_content_items')->where('id', $itemId)->get()->getRowArray()['workflow_status'],
            'With the requirement off the item is not parked.',
        );

        $chained = $db->table('reach_work_blocks')
            ->where('content_item_id', $itemId)
            ->where('block_type', WorkBlockService::TYPE_SEO_OPTIMIZE)
            ->countAllResults();
        $this->assertSame(1, $chained, 'Old behaviour chains SEO regardless of the cover.');
    }
}
