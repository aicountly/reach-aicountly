<?php

namespace Tests\Feature\Blog;

use Config\Database;
use Tests\Support\DatabaseTestCase;

/**
 * Cover for the repair pass over slugs mangled by the case-sensitive filter.
 *
 * The old builders lowercased after filtering `[^a-z0-9]`, so capitals became
 * separators and titles reached the public site with letters missing —
 * "TDS Compliance Basics for Growing Companies" published as
 * "/ompliance-asics-for-rowing-ompanies". This command rewrites those, and
 * because it rewrites live URLs the guarantees it must hold are: never touch a
 * hand-picked slug, never collide, and record a redirect for anything already
 * public.
 */
final class ReachBlogRepairSlugsCommandTest extends DatabaseTestCase
{
    private function seedItem(string $title, string $slug, string $workflowStatus = 'draft'): int
    {
        $db = Database::connect();
        $db->table('reach_content_items')->insert([
            'title'           => $title,
            'slug'            => $slug,
            'content_type'    => 'blog',
            'workflow_status' => $workflowStatus,
        ]);

        return (int) $db->insertID();
    }

    private function slugOf(int $id): string
    {
        return (string) Database::connect()->table('reach_content_items')
            ->select('slug')->where('id', $id)->get()->getRowArray()['slug'];
    }

    private function redirectsFor(int $id): array
    {
        return Database::connect()->table('reach_publication_redirects')
            ->where('content_item_id', $id)->get()->getResultArray();
    }

    public function testDryRunChangesNothing(): void
    {
        $id = $this->seedItem(
            'TDS Compliance Basics for Growing Companies',
            'ompliance-asics-for-rowing-ompanies',
        );

        command('reach:blog-repair-slugs');

        $this->assertSame('ompliance-asics-for-rowing-ompanies', $this->slugOf($id));
    }

    public function testApplyRepairsTheCorruptedSlug(): void
    {
        $id = $this->seedItem(
            'TDS Compliance Basics for Growing Companies',
            'ompliance-asics-for-rowing-ompanies',
        );

        command('reach:blog-repair-slugs --apply');

        $this->assertSame('tds-compliance-basics-for-growing-companies', $this->slugOf($id));
    }

    public function testHandEditedSlugsAreNeverRewritten(): void
    {
        // Not what the broken builder would have produced, so somebody chose
        // it — the repair pass has no business touching it.
        $id = $this->seedItem('TDS Compliance Basics for Growing Companies', 'tds-compliance-guide');

        command('reach:blog-repair-slugs --apply');

        $this->assertSame('tds-compliance-guide', $this->slugOf($id));
    }

    public function testAlreadyCorrectSlugsAreLeftAlone(): void
    {
        $id = $this->seedItem('Plain lowercase title', 'plain-lowercase-title');

        command('reach:blog-repair-slugs --apply');

        $this->assertSame('plain-lowercase-title', $this->slugOf($id));
    }

    public function testPublishedItemsGetARedirectRecorded(): void
    {
        $id = $this->seedItem('MCA annual filing calendar', 'annual-filing-calendar', 'published');

        command('reach:blog-repair-slugs --apply');

        $this->assertSame('mca-annual-filing-calendar', $this->slugOf($id));

        $redirects = $this->redirectsFor($id);
        $this->assertCount(1, $redirects, 'A live URL changed — that must be recorded');
        $this->assertSame('annual-filing-calendar', $redirects[0]['from_slug']);
        $this->assertSame('mca-annual-filing-calendar', $redirects[0]['to_slug']);
        $this->assertSame(301, (int) $redirects[0]['redirect_type']);
    }

    public function testUnpublishedItemsGetNoRedirect(): void
    {
        $id = $this->seedItem('MCA annual filing calendar', 'annual-filing-calendar', 'draft');

        command('reach:blog-repair-slugs --apply');

        $this->assertSame([], $this->redirectsFor($id), 'Nothing public changed, so nothing to redirect');
    }

    public function testRepairsDoNotCollideWithAnExistingSlug(): void
    {
        // Something already owns the slug the repair wants.
        $squatter = $this->seedItem('Incumbent', 'mca-annual-filing-calendar');
        $broken   = $this->seedItem('MCA annual filing calendar', 'annual-filing-calendar');

        command('reach:blog-repair-slugs --apply');

        $this->assertSame('mca-annual-filing-calendar', $this->slugOf($squatter), 'The incumbent keeps its slug');
        $this->assertSame('mca-annual-filing-calendar-1', $this->slugOf($broken));
    }

    public function testTwoTitlesThatCorruptedToDifferentSlugsBothRepair(): void
    {
        $a = $this->seedItem('GST filing guide', 'filing-guide');
        $b = $this->seedItem('TDS payment rules', 'payment-rules');

        command('reach:blog-repair-slugs --apply');

        $this->assertSame('gst-filing-guide', $this->slugOf($a));
        $this->assertSame('tds-payment-rules', $this->slugOf($b));
    }

    public function testTypeFilterLimitsTheRepair(): void
    {
        $db = Database::connect();
        $db->table('reach_content_items')->insert([
            'title'           => 'KB article title',
            'slug'            => 'article-title',
            'content_type'    => 'knowledge_base',
            'workflow_status' => 'draft',
        ]);
        $kbId  = (int) $db->insertID();
        $blogId = $this->seedItem('Blog article title', 'log-article-title');

        command('reach:blog-repair-slugs --apply --type blog');

        $this->assertSame('blog-article-title', $this->slugOf($blogId));
        $this->assertSame('article-title', $this->slugOf($kbId), 'Out of scope for --type=blog');
    }
}
