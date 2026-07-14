<?php

namespace App\Controllers\Api\V1\Community;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;

class CommunityAnalyticsController extends BaseController
{
    /** GET /community/analytics/overview */
    public function overview(): ResponseInterface
    {
        $db = db_connect();

        $questionStats = $db->query("
            SELECT status, COUNT(*) AS cnt
            FROM reach_community_questions
            GROUP BY status
        ")->getResultArray();

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

        $openFindings = (int) $db->table('reach_community_moderation_findings')
            ->where('status', 'open')
            ->countAllResults();

        return $this->response->setJSON([
            'data' => [
                'questions_by_status'  => array_column($questionStats, 'cnt', 'status'),
                'answers_by_status'    => array_column($answerStats, 'cnt', 'status'),
                'published_answers'    => $publishedCount,
                'pending_approval'     => $pendingApproval,
                'open_moderation_flags' => $openFindings,
            ],
        ]);
    }

    /** GET /community/analytics/engagement */
    public function engagement(): ResponseInterface
    {
        $days = min((int) ($this->request->getGet('days') ?? 30), 90);
        $db   = db_connect();

        $rows = $db->query("
            SELECT DATE(created_at) AS day, event_type, COUNT(*) AS cnt
            FROM reach_community_engagement_events
            WHERE is_validated = TRUE
              AND created_at >= NOW() - INTERVAL '{$days} days'
            GROUP BY DATE(created_at), event_type
            ORDER BY day ASC
        ")->getResultArray();

        return $this->response->setJSON(['data' => $rows, 'days' => $days]);
    }

    /** GET /community/analytics/coverage */
    public function sourceCoverage(): ResponseInterface
    {
        $db = db_connect();

        $rows = $db->query("
            SELECT sc.source_type, sc.source_id, COUNT(DISTINCT sc.answer_version_id) AS usage_count
            FROM reach_community_source_coverage sc
            GROUP BY sc.source_type, sc.source_id
            ORDER BY usage_count DESC
            LIMIT 50
        ")->getResultArray();

        return $this->response->setJSON(['data' => $rows]);
    }

    /** GET /community/analytics/cache */
    public function cache(): ResponseInterface
    {
        $db   = db_connect();
        $rows = $db->table('reach_community_analytics_cache')
            ->orderBy('cache_date', 'DESC')
            ->limit(30)
            ->get()->getResultArray();

        return $this->response->setJSON(['data' => $rows]);
    }
}
