<?php

namespace App\Controllers\Api\V1\Community;

use App\Controllers\BaseApiController;
use App\Enums\CommunityAnswerStatus;
use App\Libraries\Community\CommunityAnalyticsDrill;
use CodeIgniter\HTTP\ResponseInterface;
use App\Libraries\Database\SchemaGuard;

class CommunityAnalyticsController extends BaseApiController
{
    /** GET /community/analytics/overview */
    public function overview(): ResponseInterface
    {
        $empty = [
            'questions_by_status'   => [],
            'answers_by_status'     => [],
            'published_answers'     => 0,
            'pending_approval'      => 0,
            'open_moderation_flags' => 0,
        ];

        try {
            $db = db_connect();
            if (! SchemaGuard::hasTable($db, 'reach_community_questions')) {
                return $this->response->setJSON(['data' => $empty]);
            }

            $questionStats = $db->query("
                SELECT status, COUNT(*) AS cnt
                FROM reach_community_questions
                GROUP BY status
            ")->getResultArray();

            $answerStats = [];
            $publishedCount = 0;
            $pendingApproval = 0;
            if (SchemaGuard::hasTable($db, 'reach_community_official_answers')) {
                $answerStats = $db->query("
                    SELECT status, COUNT(*) AS cnt
                    FROM reach_community_official_answers
                    GROUP BY status
                ")->getResultArray();

                $publishedCount = (int) $db->table('reach_community_official_answers')
                    ->where('status', 'published')
                    ->countAllResults();

                // There is no 'pending_approval' answer status; counting it made
                // this tile a permanent 0 while answers sat in review.
                $pendingApproval = (int) $db->table('reach_community_official_answers')
                    ->whereIn('status', array_column(CommunityAnswerStatus::awaitingApproval(), 'value'))
                    ->countAllResults();
            }

            $openFindings = 0;
            if (SchemaGuard::hasTable($db, 'reach_community_moderation_findings')) {
                $openFindings = (int) $db->table('reach_community_moderation_findings')
                    ->where('status', 'open')
                    ->countAllResults();
            }

            return $this->response->setJSON([
                'data' => [
                    'questions_by_status'   => array_column($questionStats ?: [], 'cnt', 'status'),
                    'answers_by_status'     => array_column($answerStats ?: [], 'cnt', 'status'),
                    'published_answers'     => $publishedCount,
                    'pending_approval'      => $pendingApproval,
                    'open_moderation_flags' => $openFindings,
                ],
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'CommunityAnalyticsController::overview: ' . $e->getMessage());
            return $this->response->setJSON(['data' => $empty]);
        }
    }

    /** GET /community/analytics/engagement */
    public function engagement(): ResponseInterface
    {
        $days = CommunityAnalyticsDrill::days($this->request->getGet('days'));

        try {
            $db = db_connect();
            if (! SchemaGuard::hasTable($db, 'reach_community_engagement_events')) {
                return $this->response->setJSON(['data' => [], 'days' => $days]);
            }

            // The same window engagementEvents() lists, so a count opens its events.
            [$window, $binds] = CommunityAnalyticsDrill::engagementWindow($days, '');
            $rows = $db->query("
                SELECT DATE(event_timestamp) AS day, event_type, COUNT(*) AS cnt
                FROM reach_community_engagement_events
                WHERE {$window}
                GROUP BY DATE(event_timestamp), event_type
                ORDER BY day ASC
            ", $binds)->getResultArray();

            return $this->response->setJSON(['data' => $rows ?: [], 'days' => $days]);
        } catch (\Throwable $e) {
            log_message('error', 'CommunityAnalyticsController::engagement: ' . $e->getMessage());
            return $this->response->setJSON(['data' => [], 'days' => $days]);
        }
    }

    /** GET /community/analytics/coverage */
    public function sourceCoverage(): ResponseInterface
    {
        try {
            $db = db_connect();
            if (! SchemaGuard::hasTable($db, 'reach_community_source_coverage')) {
                return $this->response->setJSON(['data' => []]);
            }

            // Avoid table aliases in Query Builder / raw SQL that CI4 may re-escape.
            $rows = $db->query("
                SELECT source_type, source_id, MAX(source_title) AS source_title, COUNT(DISTINCT answer_version_id) AS usage_count
                FROM reach_community_source_coverage
                GROUP BY source_type, source_id
                ORDER BY usage_count DESC
                LIMIT 50
            ")->getResultArray();

            return $this->response->setJSON(['data' => $rows ?: []]);
        } catch (\Throwable $e) {
            log_message('error', 'CommunityAnalyticsController::sourceCoverage: ' . $e->getMessage());
            return $this->response->setJSON(['data' => []]);
        }
    }

    /**
     * GET /community/analytics/engagement/events?event_type=&day=YYYY-MM-DD&days=&page=&limit=
     * The validated events an engagement count summed, newest first, each with
     * the question / answer it was recorded on.
     */
    public function engagementEvents(): ResponseInterface
    {
        [$page, $limit, $offset] = $this->pagination(25, 100);
        $db = db_connect();
        if (! SchemaGuard::hasTable($db, 'reach_community_engagement_events')) {
            return $this->ok(['items' => [], 'total' => 0, 'page' => $page, 'limit' => $limit, 'days' => CommunityAnalyticsDrill::days(null)]);
        }
        $out = (new CommunityAnalyticsDrill($db))->events([
            'event_type' => (string) $this->request->getGet('event_type'),
            'day'        => (string) $this->request->getGet('day'),
            'days'       => $this->request->getGet('days'),
        ], $limit, $offset);

        return $this->ok($out + ['page' => $page, 'limit' => $limit]);
    }

    /**
     * GET /community/analytics/coverage/answers?source_type=&source_id=<id|none>&page=&limit=
     * The answer versions grounded on one "Top grounding sources" row.
     */
    public function coverageAnswers(): ResponseInterface
    {
        [$page, $limit, $offset] = $this->pagination(25, 100);
        $type = (string) $this->request->getGet('source_type');
        $rawId = trim((string) $this->request->getGet('source_id'));
        if (! in_array($type, CommunityAnalyticsDrill::SOURCE_TYPES, true) || ($rawId !== 'none' && ! ctype_digit($rawId))) {
            return $this->fail('source_type and source_id (a number, or none) are required.', 422);
        }
        $db = db_connect();
        if (! SchemaGuard::hasTable($db, 'reach_community_source_coverage')) {
            return $this->ok(['items' => [], 'total' => 0, 'source' => null, 'page' => $page, 'limit' => $limit]);
        }
        $out = (new CommunityAnalyticsDrill($db))->groundedAnswers($type, $rawId === 'none' ? null : (int) $rawId, $limit, $offset);

        return $this->ok($out + ['page' => $page, 'limit' => $limit]);
    }

    /** GET /community/analytics/cache */
    public function cache(): ResponseInterface
    {
        try {
            $db = db_connect();
            if (! SchemaGuard::hasTable($db, 'reach_community_analytics_cache')) {
                return $this->response->setJSON(['data' => []]);
            }

            $rows = $db->table('reach_community_analytics_cache')
                ->orderBy('computed_at', 'DESC')
                ->limit(30)
                ->get()->getResultArray();

            return $this->response->setJSON(['data' => $rows ?: []]);
        } catch (\Throwable $e) {
            log_message('error', 'CommunityAnalyticsController::cache: ' . $e->getMessage());
            return $this->response->setJSON(['data' => []]);
        }
    }
}
