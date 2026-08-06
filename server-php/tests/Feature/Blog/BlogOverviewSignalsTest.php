<?php

namespace Tests\Feature\Blog;

use Config\Database;
use Tests\Support\ApiTestCase;

/**
 * Cover for the Overview cards reporting an empty system when it is not.
 *
 * The Production card sums pre-approval stages only, so once every blog had
 * moved past review it showed 0/0/0 and a NO DATA badge — on a library of
 * published posts — and its click-through landed on the Drafts filter, which
 * confirmed the lie with "No blog content items match this filter".
 *
 * The card can only tell "nothing exists" from "nothing is in production" if
 * the payload carries a total, so that is what these pin.
 */
final class BlogOverviewSignalsTest extends ApiTestCase
{
    private function seedBlog(string $title, string $workflowStatus): int
    {
        $db = Database::connect();
        $db->table('reach_content_items')->insert([
            'title'           => $title,
            'slug'            => strtolower(str_replace(' ', '-', $title)),
            'content_type'    => 'blog',
            'workflow_status' => $workflowStatus,
        ]);

        return (int) $db->insertID();
    }

    private function overview(array $headers): array
    {
        $response = $this->withHeaders($headers)->call('GET', 'v1/blog-command-centre/overview');
        $this->assertSame(200, $response->response()->getStatusCode(), $response->response()->getBody());

        return json_decode($response->response()->getBody(), true)['data'] ?? [];
    }

    public function testEmptyLibraryReportsZeroTotal(): void
    {
        $this->assertSame(0, $this->overview($this->authAs('reach_admin'))['content']['blog_total']);
    }

    public function testLibraryPastProductionStillReportsItsTotal(): void
    {
        $headers = $this->authAs('reach_admin');

        // Exactly the production shape: nothing in brief/draft/review, but a
        // real library sitting downstream.
        $this->seedBlog('Published one', 'published');
        $this->seedBlog('Published two', 'live');
        $this->seedBlog('Queued one', 'publish_queued');

        $overview = $this->overview($headers);

        $this->assertSame(3, $overview['content']['blog_total'], 'The card must not read NO DATA here');
        $this->assertSame(0, $overview['workflow_counts']['draft'], 'Nothing is in draft — the old card stopped here');
    }

    public function testDeletedBlogsAreExcludedFromTheTotal(): void
    {
        $headers = $this->authAs('reach_admin');

        $keep = $this->seedBlog('Kept', 'published');
        $drop = $this->seedBlog('Deleted', 'published');
        Database::connect()->table('reach_content_items')
            ->where('id', $drop)
            ->update(['deleted_at' => date('Y-m-d H:i:s')]);

        $this->assertSame(1, $this->overview($headers)['content']['blog_total']);
        $this->assertGreaterThan(0, $keep);
    }

    public function testNonBlogContentDoesNotCountTowardsTheBlogTotal(): void
    {
        $headers = $this->authAs('reach_admin');

        $this->seedBlog('A blog', 'published');

        $db = Database::connect();
        $db->table('reach_content_items')->insert([
            'title'           => 'A KB article',
            'slug'            => 'a-kb-article',
            'content_type'    => 'knowledge_base',
            'workflow_status' => 'published',
        ]);

        $this->assertSame(1, $this->overview($headers)['content']['blog_total']);
    }
}
