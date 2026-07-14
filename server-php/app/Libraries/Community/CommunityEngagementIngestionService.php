<?php

namespace App\Libraries\Community;

use App\Models\CommunityEngagementEventModel;
use App\Libraries\AuditLogger;

/**
 * Phase 5 — Community Engagement Ingestion Service.
 *
 * Records genuine engagement events (views, helpful votes, shares) for
 * published official answers. Enforces deduplication and validation.
 *
 * Rules:
 *  - No synthetic/fabricated engagement may be recorded.
 *  - Deduplication key prevents double-counting the same event.
 *  - Events are soft-stored with is_validated = FALSE until the
 *    reconciliation job validates them.
 */
class CommunityEngagementIngestionService
{
    private CommunityEngagementEventModel $model;

    public function __construct()
    {
        $this->model = new CommunityEngagementEventModel();
    }

    /**
     * Ingest a genuine engagement event.
     *
     * @param array{
     *   answer_external_id: string,
     *   event_type: string,
     *   source_platform: string,
     *   dedup_key?: string,
     *   metadata?: array
     * } $event
     */
    public function ingest(array $event): array
    {
        $answerUuid    = $event['answer_external_id'] ?? '';
        $eventType     = $event['event_type'] ?? '';
        $sourcePlatform = $event['source_platform'] ?? 'unknown';
        $dedupKey      = $event['dedup_key'] ?? null;

        if (empty($answerUuid) || empty($eventType)) {
            throw new \InvalidArgumentException('answer_external_id and event_type are required.');
        }

        $allowedTypes = ['view', 'helpful_vote', 'share', 'click'];
        if (!in_array($eventType, $allowedTypes, true)) {
            throw new \InvalidArgumentException("Unknown event_type '{$eventType}'.");
        }

        // Deduplication check
        if ($dedupKey !== null && $this->model->existsByDedupKey($dedupKey)) {
            return ['duplicate' => true, 'dedup_key' => $dedupKey];
        }

        $db = db_connect();
        $db->table('reach_community_engagement_events')->insert([
            'answer_external_id' => $answerUuid,
            'event_type'         => $eventType,
            'source_platform'    => $sourcePlatform,
            'dedup_key'          => $dedupKey,
            'metadata_json'      => json_encode($event['metadata'] ?? []),
            'is_validated'       => false,
            'created_at'         => date('Y-m-d H:i:s'),
        ]);

        $id = $db->insertID();

        AuditLogger::log(AuditLogger::COMMUNITY_ENGAGEMENT_RECORDED, [
            'answer_uuid' => $answerUuid,
            'event_type'  => $eventType,
            'event_id'    => $id,
        ]);

        return ['ok' => true, 'event_id' => $id, 'duplicate' => false];
    }

    /**
     * Validate pending engagement events.
     * Marks bot/invalid events as invalid, real events as validated.
     */
    public function validatePending(int $limit = 500): int
    {
        $db   = db_connect();
        $rows = $db->table('reach_community_engagement_events')
            ->where('is_validated', false)
            ->limit($limit)
            ->get()->getResultArray();

        $validated = 0;
        foreach ($rows as $row) {
            // Basic heuristic: events with a dedup_key are treated as validated
            // (they come from trusted SDK calls). Anonymous events without dedup
            // keys get a basic bot-check (placeholder for real bot detection).
            $isValid = $this->passesBasicValidation($row);
            $db->table('reach_community_engagement_events')
                ->where('id', $row['id'])
                ->update(['is_validated' => $isValid]);
            if ($isValid) {
                $validated++;
            }
        }

        return $validated;
    }

    private function passesBasicValidation(array $row): bool
    {
        // Reject events without a proper answer reference
        if (empty($row['answer_external_id'])) {
            return false;
        }
        // Accept events with dedup keys from trusted sources
        if (!empty($row['dedup_key'])) {
            return true;
        }
        // Accept events from known platforms
        $trustedPlatforms = ['reach_sdk', 'aicountly_com', 'reach_api'];
        return in_array($row['source_platform'] ?? '', $trustedPlatforms, true);
    }
}
