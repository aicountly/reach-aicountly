<?php

namespace App\Controllers\Api\V1\Community;

use App\Controllers\BaseApiController;
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

                $pendingApproval = (int) $db->table('reach_community_official_answers')
                    ->where('status', 'pending_approval')
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
        $days = min((int) ($this->request->getGet('days') ?? 30), 90);

        try {
            $db = db_connect();
            if (! SchemaGuard::hasTable($db, 'reach_community_engagement_events')) {
                return $this->response->setJSON(['data' => [], 'days' => $days]);
            }

            $rows = $db->query("
                SELECT DATE(event_timestamp) AS day, event_type, COUNT(*) AS cnt
                FROM reach_community_engagement_events
                WHERE validated = TRUE
                  AND event_timestamp >= NOW() - INTERVAL '{$days} days'
                GROUP BY DATE(event_timestamp), event_type
                ORDER BY day ASC
            ")->getResultArray();

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
                SELECT source_type, source_id, COUNT(DISTINCT answer_version_id) AS usage_count
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
