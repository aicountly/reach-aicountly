<?php

namespace Tests\Feature\Blog;

use Config\Database;
use Tests\Support\DatabaseTestCase;

/**
 * Cover for the repair that unblocks items stuck at the publication gate.
 *
 * Seven blog items reached publish_queued without ever running SEO_OPTIMIZE,
 * so they had no SEO profile and no publication profile, and every dispatch
 * died on "SEO profile is missing; Blog publication profile is missing".
 * Re-queuing could never clear that — the missing rows were the problem.
 */
final class ReachBlogFixReadinessCommandTest extends DatabaseTestCase
{
    private function seedItem(string $title, string $workflowStatus = 'publish_queued'): int
    {
        $db = Database::connect();
        $db->table('reach_content_items')->insert([
            'title'           => $title,
            'slug'            => strtolower(str_replace(' ', '-', $title)),
            'content_type'    => 'blog',
            'workflow_status' => $workflowStatus,
        ]);
        $itemId = (int) $db->insertID();

        $db->table('reach_content_versions')->insert([
            'content_item_id' => $itemId,
            'version_number'  => 1,
            'title'           => $title,
            'summary'         => 'A summary long enough to be a real meta description for this article, '
                . 'covering what the piece explains and who it is for.',
            'is_current'      => true,
        ]);

        return $itemId;
    }

    private function hasSeoProfile(int $id): bool
    {
        return Database::connect()->table('reach_content_seo_profiles')
            ->where('content_item_id', $id)->countAllResults() > 0;
    }

    private function hasPublicationProfile(int $id): bool
    {
        return Database::connect()->table('reach_blog_publication_profiles')
            ->where('content_item_id', $id)->countAllResults() > 0;
    }

    private function seoProfile(int $id): array
    {
        return Database::connect()->table('reach_content_seo_profiles')
            ->where('content_item_id', $id)->get()->getRowArray() ?? [];
    }

    public function testDryRunCreatesNothing(): void
    {
        $id = $this->seedItem('MCA annual filing calendar');

        command('reach:blog-fix-readiness');

        $this->assertFalse($this->hasSeoProfile($id));
        $this->assertFalse($this->hasPublicationProfile($id));
    }

    public function testApplyCreatesBothProfiles(): void
    {
        $id = $this->seedItem('MCA annual filing calendar');

        command('reach:blog-fix-readiness --apply');

        $this->assertTrue($this->hasSeoProfile($id), 'The SEO profile the publish gate requires');
        $this->assertTrue($this->hasPublicationProfile($id), 'The publication profile the publish gate requires');
    }

    public function testTheCreatedProfileClearsTheGatesItWasMissing(): void
    {
        $id = $this->seedItem('MCA annual filing calendar');

        command('reach:blog-fix-readiness --apply');

        $seo = $this->seoProfile($id);
        $this->assertNotEmpty($seo['meta_description'], 'Gate: [SEO] Meta description is missing');
        $this->assertNotEmpty($seo['canonical_preference'], 'Gate: Canonical preference is not defined');
        $this->assertNotEmpty($seo['slug'], 'Gate: SEO slug is not defined');
    }

    public function testAuthoredSeoIsNeverOverwritten(): void
    {
        $id = $this->seedItem('MCA annual filing calendar');
        Database::connect()->table('reach_content_seo_profiles')->insert([
            'content_item_id'      => $id,
            'meta_title'           => 'Hand written title',
            'meta_description'     => 'A meta description somebody actually wrote for search results.',
            'slug'                 => 'hand-picked-slug',
            'canonical_preference' => 'self_canonical',
            'seo_status'           => 'ready',
        ]);

        command('reach:blog-fix-readiness --apply');

        $seo = $this->seoProfile($id);
        $this->assertSame('Hand written title', $seo['meta_title']);
        $this->assertSame('hand-picked-slug', $seo['slug']);
        $this->assertStringContainsString('somebody actually wrote', $seo['meta_description']);
    }

    public function testItemsBeforeApprovalAreOutOfScope(): void
    {
        // A draft has no business carrying publication data yet.
        $id = $this->seedItem('Still a draft', 'draft');

        command('reach:blog-fix-readiness --apply');

        $this->assertFalse($this->hasSeoProfile($id));
    }

    public function testItemsThatAlreadyHaveBothProfilesAreLeftAlone(): void
    {
        $id = $this->seedItem('Already complete');
        command('reach:blog-fix-readiness --apply');
        $before = $this->seoProfile($id)['updated_at'];

        // Re-running must be a no-op rather than churning rows.
        command('reach:blog-fix-readiness --apply');

        $this->assertSame($before, $this->seoProfile($id)['updated_at']);
    }

    public function testTheDerivedMetaDescriptionIsNotPaddedWithDots(): void
    {
        // The builder used to str_pad(..., 100, '.') to silence a *warning*,
        // writing a run of dots into copy that reaches search results.
        $db = Database::connect();
        $db->table('reach_content_items')->insert([
            'title'           => 'Short one',
            'slug'            => 'short-one',
            'content_type'    => 'blog',
            'workflow_status' => 'publish_queued',
        ]);
        $id = (int) $db->insertID();
        $db->table('reach_content_versions')->insert([
            'content_item_id' => $id,
            'version_number'  => 1,
            'title'           => 'Short one',
            'summary'         => 'Very short summary.',
            'is_current'      => true,
        ]);

        command('reach:blog-fix-readiness --apply');

        $description = $this->seoProfile($id)['meta_description'];
        $this->assertStringNotContainsString('....', $description);
        $this->assertSame('Very short summary.', $description);
    }

    public function testNonBlogContentIsNotTouched(): void
    {
        $db = Database::connect();
        $db->table('reach_content_items')->insert([
            'title'           => 'KB article',
            'slug'            => 'kb-article',
            'content_type'    => 'knowledge_base',
            'workflow_status' => 'publish_queued',
        ]);
        $kbId = (int) $db->insertID();

        command('reach:blog-fix-readiness --apply');

        $this->assertFalse($this->hasSeoProfile($kbId));
    }
}
