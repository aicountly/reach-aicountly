<?php

declare(strict_types=1);

namespace App\Models\Intelligence;

use CodeIgniter\Model;

class SearchMetricFactModel extends Model
{
    protected $table      = 'reach_search_metric_facts';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;

    protected $allowedFields = [
        'content_identity_id', 'connection_id', 'ingestion_run_id', 'metric_date',
        'query', 'page_url', 'device', 'country', 'clicks', 'impressions',
        'ctr', 'avg_position', 'provider_freshness_at', 'collected_at', 'is_revised',
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
        $sql = "INSERT INTO reach_search_metric_facts
                (content_identity_id, connection_id, ingestion_run_id, metric_date, query, page_url,
                 device, country, clicks, impressions, ctr, avg_position, provider_freshness_at, collected_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
                ON CONFLICT (content_identity_id, connection_id, metric_date, query, page_url, device, country)
                DO UPDATE SET
                    clicks = EXCLUDED.clicks,
                    impressions = EXCLUDED.impressions,
                    ctr = EXCLUDED.ctr,
                    avg_position = EXCLUDED.avg_position,
                    provider_freshness_at = EXCLUDED.provider_freshness_at,
                    collected_at = NOW(),
                    is_revised = TRUE
                WHERE reach_search_metric_facts.is_revised = FALSE";

        $this->db->query($sql, [
            $data['content_identity_id'], $data['connection_id'], $data['ingestion_run_id'] ?? null,
            $data['metric_date'], $data['query'] ?? null, $data['page_url'],
            $data['device'] ?? 'UNKNOWN', $data['country'] ?? null,
            $data['clicks'], $data['impressions'], $data['ctr'] ?? null, $data['avg_position'] ?? null,
            $data['provider_freshness_at'] ?? null,
        ]);
        return true;
    }
}
