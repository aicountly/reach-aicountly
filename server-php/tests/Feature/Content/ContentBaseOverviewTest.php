<?php

namespace Tests\Feature\Content;

use Config\Database;
use Tests\Support\ApiTestCase;

/**
 * The content base feeds three surfaces; the console must show all three,
 * with statuses derived from the pipeline (planned/queued/produced/published/retired).
 *
 * Regressions: the KB index was returned raw and the community seeds not at
 * all; later, "produced" meant "some item exists" — so idea items stuck in
 * Reach's retired drafting read as produced blogs.
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

        $this->assertSame('blog/index.json', $data['surfaces']['blog']['seed_file']);
        $this->assertSame('knowledge-base/index.json', $data['surfaces']['knowledge_base']['seed_file']);
        $this->assertSame('community/question-seeds.json', $data['surfaces']['community']['seed_file']);

        // The repo ships a populated base, so a zero total means the deployed
        // directory was not found — exactly the failure this view exists for.
        $this->assertGreaterThan(0, $data['totals']['entries']);
    }

    public function testABlogTopicIsProducedOnlyWhenVettingApprovedItNotWhenAnyItemExists(): void
    {
        $db    = Database::connect();
        $entry = $this->fetch()['surfaces']['blog']['entries'][0] ?? null;
        $this->assertNotNull($entry, 'The repo base ships blog entries.');
        $this->assertSame('planned', $entry['status'], 'Nothing is written on a fresh database.');

        // Reach's retired in-house drafting left an idea item on the candidate:
        // that used to read "Produced".
        $db->table('reach_content_items')->insert([
            'content_type' => 'blog', 'title' => $entry['title'], 'slug' => 'legacy-' . bin2hex(random_bytes(4)), 'workflow_status' => 'brief_ready',
        ]);
        $itemId = (int) $db->insertID();
        // The first read imported the seeds and mirrored them into the roadmap.
        $db->table('reach_topic_candidates')->where('content_base_key', $entry['key'])->update([
            'content_item_id' => $itemId,
            'status'          => 'roadmap_selected',
        ]);

        $after = $this->fetch()['surfaces']['blog']['entries'];
        $after = array_values(array_filter($after, static fn ($e) => $e['key'] === $entry['key']))[0];
        $this->assertSame('queued', $after['status'], 'held, not produced');
        $this->assertStringContainsString('brief_ready', (string) $after['status_reason']);
    }

    public function testKnowledgeTopicsResolveAgainstRoutineSubmissions(): void
    {
        $db      = Database::connect();
        $section = $this->fetch()['surfaces']['knowledge_base'];
        $this->assertNotEmpty($section['products'], 'The KB base is grouped per product.');

        $product = $section['products'][0];
        $this->assertArrayHasKey('daily_quota', $product, 'Cadence must ride along with the topic list.');

        $topic = $product['topics'][0] ?? null;
        $this->assertNotNull($topic);
        $this->assertSame('planned', $topic['status']);

        $db->table('reach_content_items')->insert([
            'content_type'     => 'knowledge_base',
            'title'            => $topic['title'],
            'slug'             => 'kb-' . bin2hex(random_bytes(5)),
            'workflow_status'  => 'draft',
            'content_base_key' => $topic['key'],
        ]);
        $itemId = (int) $db->insertID();
        $db->table('reach_routine_submissions')->insert(['content_type' => 'knowledge_base', 'content_item_id' => $itemId, 'content_key' => $topic['key'], 'status' => 'pending_vetting']);
        $this->assertSame('queued', $this->topic($topic['key'])['status']);

        $db->table('reach_routine_submissions')->where('content_item_id', $itemId)->update(['status' => 'live']);
        $this->assertSame('published', $this->topic($topic['key'])['status']);
    }

    private function topic(string $key): array
    {
        foreach ($this->fetch()['surfaces']['knowledge_base']['entries'] as $e) {
            if ($e['key'] === $key) {
                return $e;
            }
        }
        $this->fail("topic {$key} missing");
    }

    public function testCommunitySeedsResolveAgainstCuratedQuestions(): void
    {
        $section = $this->fetch()['surfaces']['community'];
        $seed    = $section['entries'][0] ?? null;

        $this->assertNotNull($seed, 'The repo base ships community question seeds.');
        $this->assertNotSame('', $seed['title'], 'A seed renders its question text as the title.');
        $this->assertSame('planned', $seed['status']);
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
