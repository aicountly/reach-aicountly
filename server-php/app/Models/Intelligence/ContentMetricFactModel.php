<?php

declare(strict_types=1);

namespace App\Models\Intelligence;

use CodeIgniter\Model;

class ContentMetricFactModel extends Model
{
    protected $table      = 'reach_content_metric_facts';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;

    protected $allowedFields = [
        'content_identity_id', 'connection_id', 'ingestion_run_id', 'metric_date',
        'source', 'medium', 'campaign_name', 'sessions', 'users', 'new_users',
        'engaged_sessions', 'engagement_rate', 'avg_engagement_time_secs',
        'entrances', 'page_views', 'scroll_depth_pct', 'provider_freshness_at',
        'collected_at', 'is_revised',
    ];

    public function getForContent(int $contentIdentityId, string $fromDate, string $toDate): array
    {
        return $this->where('content_identity_id', $contentIdentityId)
                    ->where('metric_date >=', $fromDate)
                    ->where('metric_date <=', $toDate)
                    ->orderBy('metric_date', 'DESC')
                    ->findAll();
    }

    public function upsertFact(array $data): bool
    {
        $sql = "INSERT INTO reach_content_metric_facts
                (content_identity_id, connection_id, ingestion_run_id, metric_date, source, medium,
                 campaign_name, sessions, users, new_users, engaged_sessions, engagement_rate,
                 avg_engagement_time_secs, entrances, page_views, provider_freshness_at, collected_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
                ON CONFLICT (content_identity_id, connection_id, metric_date, source, medium)
                DO UPDATE SET
                    sessions = EXCLUDED.sessions,
                    users = EXCLUDED.users,
                    engaged_sessions = EXCLUDED.engaged_sessions,
                    engagement_rate = EXCLUDED.engagement_rate,
                    avg_engagement_time_secs = EXCLUDED.avg_engagement_time_secs,
                    provider_freshness_at = EXCLUDED.provider_freshness_at,
                    collected_at = NOW(),
                    is_revised = TRUE";

        $this->db->query($sql, [
            $data['content_identity_id'], $data['connection_id'], $data['ingestion_run_id'] ?? null,
            $data['metric_date'], $data['source'] ?? '(direct)', $data['medium'] ?? '(none)',
            $data['campaign_name'] ?? null, $data['sessions'] ?? 0, $data['users'] ?? 0,
            $data['new_users'] ?? 0, $data['engaged_sessions'] ?? 0, $data['engagement_rate'] ?? null,
            $data['avg_engagement_time_secs'] ?? null, $data['entrances'] ?? 0, $data['page_views'] ?? 0,
            $data['provider_freshness_at'] ?? null,
        ]);
        return true;
    }
}
