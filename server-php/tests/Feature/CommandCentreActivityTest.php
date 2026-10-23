<?php

namespace Tests\Feature;

use App\Libraries\Dashboard\CommandCentreActivity;
use App\Libraries\Dashboard\CommandCentreService;
use App\Libraries\Database\SchemaGuard;
use Config\Database;
use Tests\Support\ApiTestCase;

/**
 * GET v1/dashboard/command-centre/activity — the drill-down behind each
 * "What the bot did" counter: exactly the rows the counter counts, every one
 * reachable by paging, failed jobs grouped by type.
 */
final class CommandCentreActivityTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        SchemaGuard::reset();
        foreach (CommandCentreService::SECTIONS as $section) {
            \Config\Services::cache()->delete('reach_cc_' . $section);
        }
    }

    /** @var array<string, array<string, string>> auth headers per role, minted once per test */
    private array $authHeaders = [];

    /** @return array<string, mixed> */
    private function drill(string $query, string $role = 'super_admin', int $status = 200): array
    {
        $this->authHeaders[$role] ??= $this->authAs($role);
        $res = $this->withHeaders($this->authHeaders[$role])->call('GET', 'v1/dashboard/command-centre/activity?' . $query);
        $this->assertSame($status, $res->response()->getStatusCode(), (string) $res->getJSON());

        return json_decode((string) $res->getJSON(), true)['data'] ?? [];
    }

    /** @return array<string, mixed> */
    private function section(): array
    {
        $res = $this->withHeaders($this->authAs('super_admin'))->call('GET', 'v1/dashboard/command-centre?fresh=1&sections=activity');
        $this->assertSame(200, $res->response()->getStatusCode());

        return json_decode((string) $res->getJSON(), true)['data']['activity'];
    }

    private function ts(string $rel): string
    {
        return gmdate('Y-m-d H:i:s+00', strtotime($rel));
    }

    private function seedActivity(): void
    {
        $db = Database::connect();
        foreach (['-2 hours', '-25 hours', '-3 days', '-10 days', '-29 days', '-31 days', '-40 days'] as $i => $when) {
            $db->table('reach_content_items')->insert([
                'content_type' => 'blog', 'title' => "Blog {$i}", 'slug' => 'b-' . $i . '-' . bin2hex(random_bytes(3)),
                'workflow_status' => 'live', 'published_at' => $this->ts($when),
            ]);
        }
        // Not published / deleted: never counted.
        $db->table('reach_content_items')->insert(['content_type' => 'blog', 'title' => 'Draft', 'slug' => 'd-' . bin2hex(random_bytes(3)), 'workflow_status' => 'draft', 'published_at' => $this->ts('-1 hour')]);

        for ($i = 0; $i < 60; $i++) {
            $db->table('reach_community_automation_submissions')->insert([
                'external_key' => 'cq-' . $i, 'kind' => 'faq', 'status' => 'published', 'question_title' => "How do I file GSTR-{$i}?",
                'category_slug' => 'gst', 'public_url' => "https://aicountly.com/community/q-{$i}", 'published_at' => $this->ts('-' . ($i * 5) . ' hours'),
            ]);
        }

        // 30 historic failures of one type, 3 recent of another, one completed (not counted).
        for ($i = 0; $i < 30; $i++) {
            $db->table('reach_jobs')->insert([
                'job_type' => 'reach.old_broken', 'status' => 'dead_letter', 'error_message' => "old error {$i}",
                'completed_at' => $this->ts('-' . (6 + $i % 10) . ' days'), 'updated_at' => $this->ts('-1 day'),
            ]);
        }
        foreach (['-1 hour', '-2 hours', '-3 hours'] as $i => $when) {
            $db->table('reach_jobs')->insert([
                'job_type' => 'reach.seo_tracker', 'status' => 'dead_letter', 'error_message' => "HTTP 402 balance {$i}",
                'completed_at' => $this->ts($when), 'updated_at' => $this->ts($when),
            ]);
        }
        $db->table('reach_jobs')->insert(['job_type' => 'reach.seo_tracker', 'status' => 'completed', 'completed_at' => $this->ts('-1 hour'), 'updated_at' => $this->ts('-1 hour')]);

        $db->table('reach_content_items')->insert(['content_type' => 'blog', 'title' => 'Rejected GST draft', 'slug' => 'r-' . bin2hex(random_bytes(3)), 'workflow_status' => 'draft']);
        $itemId = (int) $db->insertID();
        $db->table('reach_ai_vetting_runs')->insert([
            'content_type' => 'blog', 'content_ref' => "blog:{$itemId}", 'model' => 'gemini-2.5-flash', 'decision' => 'reject',
            'reasons_json' => json_encode(['Unsupported claim about GST rates', 'Cites a repealed rule']), 'input_sha256' => str_repeat('a', 64),
            'created_at' => $this->ts('-3 days'),
        ]);
        $db->table('reach_ai_vetting_runs')->insert([
            'content_type' => 'social', 'content_ref' => 'social_submission:00000000-0000-0000-0000-000000000000', 'model' => 'gemini-2.5-flash', 'decision' => 'reject',
            'reasons_json' => json_encode(['Too salesy']), 'input_sha256' => str_repeat('b', 64),
            'final_json' => json_encode(['title' => 'Close your books in a day']), 'created_at' => $this->ts('-5 hours'),
        ]);
        $db->table('reach_ai_vetting_runs')->insert([
            'content_type' => 'blog', 'content_ref' => 'blog:1', 'model' => 'gemini-2.5-flash', 'decision' => 'approve',
            'reasons_json' => '[]', 'input_sha256' => str_repeat('c', 64), 'created_at' => $this->ts('-1 hour'),
        ]);
    }

    public function testEveryCounterOpensExactlyTheRowsItCounts(): void
    {
        $this->seedActivity();
        $counters = $this->section()['counters'];

        $this->assertSame(['label' => 'Blog published', 'h24' => 1, 'd7' => 3, 'd30' => 5], $counters['blog_published']);
        $this->assertSame(['label' => 'Community answer published', 'h24' => 5, 'd7' => 34, 'd30' => 60], $counters['community_answer_published']);
        $this->assertSame(33, $counters['job_failed']['d30']);
        $this->assertSame(3, $counters['job_failed']['h24']);

        foreach (['24h' => 'h24', '7d' => 'd7', '30d' => 'd30'] as $window => $key) {
            foreach (array_merge(['all'], array_keys($counters)) as $kind) {
                $expected = $kind === 'all' ? array_sum(array_column($counters, $key)) : $counters[$kind][$key];
                $seen     = [];
                $rows     = 0;
                $page     = 1;
                do {
                    $d = $this->drill("kind={$kind}&window={$window}&page={$page}&per_page=7");
                    $this->assertSame($expected, $d['total'], "{$kind} {$window}: drill-down total = counter");
                    $this->assertSame($counters, $d['counters'], 'same counters in both responses');
                    foreach ($d['items'] as $it) {
                        $id = $kind === 'job_failed' ? $it['job_type'] : $it['kind'] . ':' . $it['id'];
                        $this->assertArrayNotHasKey($id, $seen, "{$kind} {$window}: no duplicates across pages");
                        $seen[$id] = true;
                        $rows += $kind === 'job_failed' ? $it['count'] : 1;
                    }
                    $page++;
                } while ($d['has_more'] && $page < 50);
                $this->assertSame($expected, $rows, "{$kind} {$window}: every counted row is reachable");
            }
        }
    }

    public function testItemsCarryTitleTimeAndLinks(): void
    {
        $this->seedActivity();
        $d = $this->drill('kind=community_answer_published&window=30d&per_page=25');

        $this->assertSame(60, $d['total']);
        $this->assertCount(25, $d['items']);
        $this->assertTrue($d['has_more']);
        $first = $d['items'][0];
        $this->assertSame('How do I file GSTR-0?', $first['title'], 'newest first');
        $this->assertSame('https://aicountly.com/community/q-0', $first['public_url']);
        $this->assertSame('/community/automated', $first['link']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $first['at'], 'ISO 8601 UTC, readable by any browser');

        $last = $this->drill('kind=community_answer_published&window=30d&page=3&per_page=25');
        $this->assertCount(10, $last['items']);
        $this->assertFalse($last['has_more']);

        $blog = $this->drill('kind=blog_published&window=7d')['items'];
        $this->assertSame(['Blog 0', 'Blog 1', 'Blog 2'], array_column($blog, 'title'));
        $this->assertStringStartsWith('/content/', $blog[0]['link']);
    }

    public function testFailedJobsAreGroupedByTypeWithTheJobMonitorFilter(): void
    {
        $this->seedActivity();
        $d = $this->drill('kind=job_failed&window=30d');

        $this->assertTrue($d['grouped']);
        $this->assertSame(33, $d['total']);
        $this->assertSame(2, $d['groups_total']);
        [$recent, $old] = $d['items'];
        $this->assertSame('reach.seo_tracker', $recent['job_type'], 'still-failing types first');
        $this->assertSame(3, $recent['count']);
        $this->assertSame(3, $recent['last_24h']);
        $this->assertSame('HTTP 402 balance 0', $recent['latest_error']);
        $this->assertSame('reach.old_broken', $old['job_type']);
        $this->assertSame(30, $old['count']);
        $this->assertSame(0, $old['last_24h'], 'stopped: none in the last 24 h');
        $this->assertSame(gmdate('Y-m-d', strtotime('-6 days')), substr($old['latest_at'], 0, 10));
        $this->assertSame('/admin/jobs?status=dead_letter,failed&job_type=reach.old_broken&since_hours=720', $old['link']);

        // The Job Monitor, given that filter, lists exactly the rows counted.
        parse_str(parse_url($old['link'], PHP_URL_QUERY), $q);
        $res = $this->withHeaders($this->authAs('super_admin'))->call('GET', 'v1/jobs?' . http_build_query($q));
        $this->assertSame(200, $res->response()->getStatusCode());
        $this->assertSame(30, json_decode((string) $res->getJSON(), true)['data']['total']);

        $week = $this->drill('kind=job_failed&window=7d');
        $this->assertSame(array_sum(array_column($week['items'], 'count')), $week['total']);
    }

    public function testVettingRejectionsNameTheContentAndGeminisReasons(): void
    {
        $this->seedActivity();
        $items = $this->drill('kind=vetting_rejected&window=7d')['items'];

        $this->assertCount(2, $items, 'approvals are not rejections');
        $this->assertSame('social', $items[0]['content_type']);
        $this->assertSame('Close your books in a day', $items[0]['title'], 'from the vetted draft when the row is gone');
        $this->assertSame('/social/queue', $items[0]['link']);
        $this->assertSame(['Too salesy'], $items[0]['reasons']);

        $this->assertSame('blog', $items[1]['content_type']);
        $this->assertSame('Rejected GST draft', $items[1]['title']);
        $this->assertStringStartsWith('blog:', $items[1]['ref']);
        $this->assertSame('/content/' . explode(':', $items[1]['ref'])[1], $items[1]['link']);
        $this->assertSame(['Unsupported claim about GST rates', 'Cites a repealed rule'], $items[1]['reasons']);
        $this->assertSame('gemini-2.5-flash', $items[1]['model']);
    }

    public function testRejectsUnknownKindOrWindowAndNeedsDashboardView(): void
    {
        $this->drill('kind=nope&window=7d', 'super_admin', 422);
        $this->drill('kind=blog_published&window=1y', 'super_admin', 422);
        $this->drill('kind=all&window=24h', 'viewer', 403);
        $ok = $this->drill('window=d7', 'analyst');
        $this->assertSame('all', $ok['kind']);
        $this->assertSame(168, $ok['window_hours']);
        $this->assertSame(0, $ok['total']);
        $this->assertSame([], $ok['items']);
    }

    public function testWindowNamesMatchTheUi(): void
    {
        $this->assertSame('24h', CommandCentreActivity::window('h24'));
        $this->assertSame('30d', CommandCentreActivity::window('30d'));
        $this->assertNull(CommandCentreActivity::window('90d'));
    }
}
