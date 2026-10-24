<?php

namespace Tests\Feature\Community;

use App\Controllers\Api\V1\Community\CommunityAnalyticsController;
use App\Libraries\Community\CommunityAnalyticsDrill;
use App\Libraries\Community\CommunityQuestionIntakeService;
use App\Libraries\Community\OfficialAnswerLifecycleService;
use Config\Database;
use Tests\Support\ApiTestCase;

/**
 * Community → Analytics: an engagement count opens the events it summed, and a
 * "Top grounding sources" row opens the answer versions grounded on it, with
 * the same predicates (CommunityAnalyticsDrill), so the totals match.
 */
final class CommunityAnalyticsDrillTest extends ApiTestCase
{
    /** @return array{question: array<string, mixed>, answer: array<string, mixed>} */
    private function answered(string $title): array
    {
        $question = (new CommunityQuestionIntakeService())->intake(['source_type' => 'manual', 'title' => $title, 'category' => 'gst']);
        $answer = (new OfficialAnswerLifecycleService())->createDraft($question['uuid'], 'aicountly-gst-guide');

        return ['question' => $question, 'answer' => $answer];
    }

    private function version(int $answerId, int $n): int
    {
        $db = Database::connect();
        $db->table('reach_community_answer_versions')->insert([
            'answer_id' => $answerId, 'version_number' => $n, 'content' => "v{$n}", 'checksum' => hash('sha256', "{$answerId}-{$n}"),
        ]);

        return (int) $db->insertID();
    }

    private function event(string $type, array $row, string $when = 'now', bool $validated = true): void
    {
        static $n = 0;
        $n++;
        Database::connect()->table('reach_community_engagement_events')->insert($row + [
            'event_type' => $type, 'event_timestamp' => date('Y-m-d H:i:sP', strtotime($when)),
            'deduplication_key' => "drill-{$n}-" . bin2hex(random_bytes(4)), 'validated' => $validated,
        ]);
    }

    private function api(string $path): array
    {
        $response = $this->withHeaders($this->authAs('reach_admin'))->call('GET', $path);
        $this->assertSame(200, $response->response()->getStatusCode(), $path);

        return json_decode((string) $response->getJSON(), true);
    }

    public function testEngagementCountsOpenTheirEvents(): void
    {
        $one = $this->answered('Drill: how do I file GSTR-1?');
        $two = $this->answered('Drill: e-invoice limits');
        $this->event('helpful', ['answer_id' => $one['answer']['id']]);
        $this->event('helpful', ['question_id' => $two['question']['id']]);
        $this->event('helpful', ['answer_id' => $one['answer']['id']], '-3 days');
        $this->event('helpful', ['answer_id' => $one['answer']['id']], '-20 days');
        $this->event('helpful', ['answer_id' => $one['answer']['id']], 'now', false); // not validated: not counted
        $this->event('page_view', ['answer_id' => $two['answer']['id']]);

        $counts = $this->api('v1/community/analytics/engagement?days=14')['data'];
        $helpful = array_sum(array_map(static fn (array $r): int => (int) $r['cnt'], array_filter($counts, static fn (array $r): bool => $r['event_type'] === 'helpful')));
        $this->assertSame(3, $helpful);

        $drill = new CommunityAnalyticsDrill(Database::connect());
        $events = $drill->events(['event_type' => 'helpful', 'days' => 14], 25, 0);
        $this->assertSame($helpful, $events['total']);
        $this->assertCount(3, $events['items']);

        // Each event leads to its question; an answer-only event via the answer.
        $byQuestion = array_count_values(array_column($events['items'], 'question_uuid'));
        $this->assertSame(2, $byQuestion[$one['question']['uuid']]);
        $this->assertSame(1, $byQuestion[$two['question']['uuid']]);
        $this->assertSame($one['answer']['uuid'], $events['items'][0]['answer_uuid'] ?? $events['items'][1]['answer_uuid']);

        // A day row: DATE(event_timestamp), as the counts group it.
        $today = array_values(array_filter($counts, static fn (array $r): bool => $r['event_type'] === 'helpful' && $r['day'] === date('Y-m-d')));
        $this->assertSame((int) $today[0]['cnt'], $drill->events(['event_type' => 'helpful', 'day' => date('Y-m-d'), 'days' => 14], 25, 0)['total']);

        // Unknown types are ignored, the window is clamped to 90 days.
        $this->assertSame(5, $drill->events(['event_type' => 'bogus', 'days' => 999], 25, 0)['total']);
        $this->assertSame(90, $drill->events(['days' => 999], 25, 0)['days']);
    }

    public function testGroundingSourceOpensTheAnswersGroundedOnIt(): void
    {
        $one = $this->answered('Drill: GST rate on SaaS');
        $two = $this->answered('Drill: TDS on rent');
        $v1 = $this->version((int) $one['answer']['id'], 1);
        $v2 = $this->version((int) $one['answer']['id'], 2);
        $v3 = $this->version((int) $two['answer']['id'], 1);
        $db = Database::connect();
        $cover = static fn (int $version, string $type, ?int $id, string $claim) => $db->table('reach_community_source_coverage')->insert([
            'answer_version_id' => $version, 'source_type' => $type, 'source_id' => $id, 'source_title' => 'GST rates KB',
            'source_url' => 'https://aicountly.com/kb/gst-rates', 'claim_reference' => $claim,
        ]);
        $cover($v1, 'kb_article', 77, 'SaaS is taxed at 18%');
        $cover($v1, 'kb_article', 77, 'Place of supply is the recipient');
        $cover($v2, 'kb_article', 77, 'SaaS is taxed at 18%');
        $cover($v3, 'kb_article', 78, 'Other article');
        $cover($v3, 'external', null, 'No id');

        $rows = $this->api('v1/community/analytics/coverage')['data'];
        $kb77 = array_values(array_filter($rows, static fn (array $r): bool => $r['source_type'] === 'kb_article' && (int) $r['source_id'] === 77))[0];
        $this->assertSame(2, (int) $kb77['usage_count']);
        $this->assertSame('GST rates KB', $kb77['source_title']);

        $drill = new CommunityAnalyticsDrill($db);
        $grounded = $drill->groundedAnswers('kb_article', 77, 25, 0);
        $this->assertSame((int) $kb77['usage_count'], $grounded['total']);
        $this->assertEqualsCanonicalizing([$v1, $v2], array_map('intval', array_column($grounded['items'], 'answer_version_id')));
        $this->assertSame([$one['answer']['uuid']], array_values(array_unique(array_column($grounded['items'], 'answer_uuid'))));
        $this->assertSame($one['question']['uuid'], $grounded['items'][0]['question_uuid']);
        $first = array_values(array_filter($grounded['items'], static fn (array $r): bool => (int) $r['answer_version_id'] === $v1))[0];
        $this->assertSame(['SaaS is taxed at 18%', 'Place of supply is the recipient'], array_column($first['claims'], 'claim'));
        $this->assertSame('https://aicountly.com/kb/gst-rates', $grounded['source']['url']);

        $this->assertSame(1, $drill->groundedAnswers('external', null, 25, 0)['total'], 'a source with no id');
        $this->assertSame(0, $drill->groundedAnswers('not_a_type', 77, 25, 0)['total']);
    }

    public function testControllerEndpointsValidateAndRespond(): void
    {
        $this->authAs('reach_admin');
        $one = $this->answered('Drill: controller');
        $this->event('click', ['answer_id' => $one['answer']['id']]);

        $controller = new CommunityAnalyticsController();
        $request = service('request');
        $controller->initController($request, service('response'), service('logger'));

        $request->setGlobal('get', ['event_type' => 'click', 'days' => '7']);
        $body = json_decode((string) $controller->engagementEvents()->getBody(), true);
        $this->assertTrue($body['ok']);
        $this->assertSame(1, $body['data']['total']);
        $this->assertSame($one['question']['uuid'], $body['data']['items'][0]['question_uuid']);

        $request->setGlobal('get', ['source_type' => 'kb_article', 'source_id' => 'abc']);
        $this->assertSame(422, $controller->coverageAnswers()->getStatusCode());
        $request->setGlobal('get', ['source_type' => 'kb_article', 'source_id' => 'none']);
        $body = json_decode((string) $controller->coverageAnswers()->getBody(), true);
        $this->assertSame(0, $body['data']['total']);
    }
}
