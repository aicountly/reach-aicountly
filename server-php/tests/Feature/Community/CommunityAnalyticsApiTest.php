<?php

namespace Tests\Feature\Community;

use App\Libraries\Community\CommunityQuestionIntakeService;
use App\Libraries\Community\OfficialAnswerLifecycleService;
use Config\Database;
use Tests\Support\ApiTestCase;

/**
 * Feature tests for community analytics API.
 */
final class CommunityAnalyticsApiTest extends ApiTestCase
{
    public function testAnalyticsOverviewRequiresAuth(): void
    {
        $response = $this->call('GET', 'v1/community/analytics/overview');
        $this->assertSame(401, $response->response()->getStatusCode());
    }

    public function testAnalyticsOverviewReturns200(): void
    {
        $headers  = $this->authAs('reach_admin');
        $response = $this->withHeaders($headers)->call('GET', 'v1/community/analytics/overview');
        $this->assertSame(200, $response->response()->getStatusCode());
        $body = json_decode((string) $response->getJSON(), true);
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('published_answers', $body['data']);
        $this->assertArrayHasKey('open_moderation_flags', $body['data']);
    }

    public function testAnalyticsEngagementReturns200(): void
    {
        $headers  = $this->authAs('reach_admin');
        $response = $this->withHeaders($headers)->call('GET', 'v1/community/analytics/engagement?days=7');
        $this->assertSame(200, $response->response()->getStatusCode());
        $body = json_decode((string) $response->getJSON(), true);
        $this->assertArrayHasKey('data', $body);
        $this->assertSame(7, $body['days']);
    }

    public function testAnalyticsCoverageReturns200(): void
    {
        $headers  = $this->authAs('reach_admin');
        $response = $this->withHeaders($headers)->call('GET', 'v1/community/analytics/coverage');
        $this->assertSame(200, $response->response()->getStatusCode());
    }

    public function testAnalyticsCacheReturns200(): void
    {
        $headers  = $this->authAs('reach_admin');
        $response = $this->withHeaders($headers)->call('GET', 'v1/community/analytics/cache');
        $this->assertSame(200, $response->response()->getStatusCode());
    }

    public function testAnalyticsRequiresViewAnalyticsPermission(): void
    {
        $headers  = $this->authAs('blog_author');
        $response = $this->withHeaders($headers)->call('GET', 'v1/community/analytics/overview');
        $this->assertContains($response->response()->getStatusCode(), [401, 403]);
    }

    /**
     * The tile counted a 'pending_approval' status no answer can have, so it
     * read 0 however many answers were queued. Both review queues count; the
     * statuses either side of review do not.
     */
    public function testPendingApprovalCountsAnswersWaitingOnAnApprover(): void
    {
        foreach (['editorial_review', 'professional_review', 'draft_generated', 'approved'] as $n => $status) {
            $this->seedAnswerIn($status, $n);
        }

        $headers  = $this->authAs('reach_admin');
        $response = $this->withHeaders($headers)->call('GET', 'v1/community/analytics/overview');
        $body     = json_decode((string) $response->getJSON(), true);

        $this->assertSame(2, $body['data']['pending_approval']);
    }

    private function seedAnswerIn(string $status, int $n): void
    {
        $question = (new CommunityQuestionIntakeService())->intake([
            'source_type' => 'manual',
            'title'       => "Analytics fixture question {$n}",
            'category'    => 'gst',
        ]);
        (new OfficialAnswerLifecycleService())->createDraft($question['uuid'], 'aicountly-gst-guide');

        Database::connect()->table('reach_community_official_answers')
            ->where('question_id', $question['id'])
            ->update(['status' => $status]);
    }
}

