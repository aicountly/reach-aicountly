<?php

namespace Tests\Feature\Content;

use Config\Database;
use Tests\Support\ApiTestCase;

/**
 * The content base feeds three surfaces; the console must show all three.
 *
 * Regression: the only view of the base was a Blog Command Centre roadmap tab
 * that returned blog entries with sync state, the knowledge-base index raw,
 * and the community seeds not at all — so "what has Claude got queued for the
 * KB?" could only be answered by opening git.
 */
final class ContentBaseOverviewTest extends ApiTestCase
{
    private function fetch(): array
    {
        $headers  = $this->authAs('super_admin');
        $response = $this->withHeaders($headers)->call('GET', 'v1/content-base');
        $this->assertSame(200, $response->response()->getStatusCode());

        $body = json_decode((string) $response->getJSON(), true);
        $this->assertTrue($body['ok']);

        return $body['data'];
    }

    public function testEverySurfaceIsReported(): void
    {
        $data = $this->fetch();

        $this->assertArrayHasKey('blog', $data['surfaces']);
        $this->assertArrayHasKey('knowledge_base', $data['surfaces']);
        $this->assertArrayHasKey('community', $data['surfaces']);

        $this->assertSame('blog/index.json', $data['surfaces']['blog']['source_file']);
        $this->assertSame('knowledge-base/index.json', $data['surfaces']['knowledge_base']['source_file']);
        $this->assertSame('community/question-seeds.json', $data['surfaces']['community']['source_file']);

        // The repo ships a populated base, so a zero total means the deployed
        // directory was not found — exactly the failure this view exists for.
        $this->assertGreaterThan(0, $data['totals']['entries']);
    }

    public function testBlogEntriesCarryTheirRoadmapSyncState(): void
    {
        $db    = Database::connect();
        $entry = $this->fetch()['surfaces']['blog']['entries'][0] ?? null;
        $this->assertNotNull($entry, 'The repo base ships blog entries.');
        $this->assertSame('pending_sync', $entry['sync']['state'], 'Nothing is synced on a fresh database.');

        $db->table('reach_topic_candidates')->insert([
            'title'            => $entry['title'],
            'normalized_title' => strtolower($entry['title']),
            'content_base_key' => $entry['key'],
            'status'           => 'pinned',
            'created_at'       => date('Y-m-d H:i:s'),
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);

        $after = $this->fetch()['surfaces']['blog']['entries'][0];
        $this->assertSame('queued', $after['sync']['state'], 'A candidate without a content item is queued, not produced.');
        $this->assertSame('pinned', $after['sync']['candidate_status']);
    }

    public function testKnowledgeTopicsResolveAgainstIngestedContentItems(): void
    {
        $db      = Database::connect();
        $section = $this->fetch()['surfaces']['knowledge_base'];
        $this->assertNotEmpty($section['products'], 'The KB base is grouped per product.');

        $product = $section['products'][0];
        $this->assertArrayHasKey('daily_quota', $product, 'Cadence must ride along with the topic list.');

        $topic = $product['topics'][0] ?? null;
        $this->assertNotNull($topic);
        $this->assertSame('pending_sync', $topic['sync']['state']);

        $db->table('reach_content_items')->insert([
            'content_type'     => 'knowledge_base',
            'title'            => $topic['title'],
            'slug'             => 'kb-' . bin2hex(random_bytes(5)),
            'workflow_status'  => 'draft',
            'content_base_key' => $topic['key'],
        ]);

        $after = $this->fetch()['surfaces']['knowledge_base']['products'][0]['topics'][0];
        $this->assertSame('produced', $after['sync']['state']);
        $this->assertSame('draft', $after['sync']['workflow_status']);
    }

    public function testCommunitySeedsResolveAgainstCuratedQuestions(): void
    {
        $section = $this->fetch()['surfaces']['community'];
        $seed    = $section['entries'][0] ?? null;

        $this->assertNotNull($seed, 'The repo base ships community question seeds.');
        $this->assertNotSame('', $seed['title'], 'A seed renders its question text as the title.');
        $this->assertSame('pending_sync', $seed['sync']['state']);
    }

    public function testCountsAggregateAcrossSurfaces(): void
    {
        $data = $this->fetch();

        $sum = 0;
        foreach ($data['surfaces'] as $surface) {
            $this->assertSame($surface['counts']['total'], count($surface['entries']));
            $sum += $surface['counts']['total'];
        }

        $this->assertSame($sum, $data['totals']['entries']);
    }

    public function testTheBlogOnlyViewStillWorksForTheCommandCentreTab(): void
    {
        $headers  = $this->authAs('super_admin');
        $response = $this->withHeaders($headers)->call('GET', 'v1/blog/content-base');

        $this->assertSame(200, $response->response()->getStatusCode());
        $body = json_decode((string) $response->getJSON(), true);
        $this->assertArrayHasKey('entries', $body['data']);
    }
}
