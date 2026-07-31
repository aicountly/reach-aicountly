<?php

namespace App\Libraries\Community;

use App\Models\CommunityEngagementEventModel;
use App\Libraries\AuditLogger;

/**
 * Phase 5 — Community Engagement Ingestion Service.
 *
 * Records genuine engagement events (page views, helpful votes, replies,
 * reports, clicks) against the schema created by migration
 * 2026-07-13-100100_CreateReachCommunityEngagementEvents. Enforces
 * deduplication, event-type validation and a real bot filter: events
 * attributable to an official identity, a service account or an automation
 * source are never counted as genuine community engagement, because Reach
 * itself is never allowed to manufacture engagement on its own content
 * (see CommunityReceiverController::guardEngagement on the public side —
 * this is the mirror-image guarantee on the ingestion side).
 *
 * Events are soft-stored with validated = FALSE until validatePending()
 * (invoked by the reconciliation job) confirms them.
 */
class CommunityEngagementIngestionService
{
    private const ALLOWED_EVENT_TYPES = ['page_view', 'helpful', 'not_helpful', 'reply', 'report', 'click'];

    /** Sources that can never produce genuine engagement — mirrors the
     *  receiver-side ENGAGEMENT_OPERATIONS guard so a bot/service caller can
     *  never inflate its own metrics from the ingestion side either. */
    private const BOT_SOURCES = ['reach_service', 'reach_bot', 'reach_automation', 'official_identity', 'service_account'];

    private CommunityEngagementEventModel $model;

    public function __construct()
    {
        $this->model = new CommunityEngagementEventModel();
    }

    /**
     * Ingest a genuine engagement event.
     *
     * @param array{
     *   answer_id?: int,
     *   question_id?: int,
     *   event_type: string,
     *   source?: string,
     *   deduplication_key?: string,
     *   session_reference?: string,
     *   event_timestamp?: string,
     *   is_bot?: bool
     * } $event
     */
    public function ingest(array $event): array
    {
        $answerId   = isset($event['answer_id']) ? (int) $event['answer_id'] : null;
        $questionId = isset($event['question_id']) ? (int) $event['question_id'] : null;
        $eventType  = (string) ($event['event_type'] ?? '');
        $source     = (string) ($event['source'] ?? 'public_site');
        $dedupKey   = $event['deduplication_key'] ?? null;

        if ($answerId === null && $questionId === null) {
            throw new \InvalidArgumentException('Either answer_id or question_id is required.');
        }

        if (!in_array($eventType, self::ALLOWED_EVENT_TYPES, true)) {
            throw new \InvalidArgumentException("Unknown event_type '{$eventType}'.");
        }

        if ($dedupKey === null || trim((string) $dedupKey) === '') {
            throw new \InvalidArgumentException('deduplication_key is required.');
        }
        $dedupKey = (string) $dedupKey;

        if ($this->model->existsByDedupKey($dedupKey)) {
            return ['duplicate' => true, 'deduplication_key' => $dedupKey];
        }

        $botFiltered = $this->isBotSourced($event, $source);

        $id = $this->model->insert([
            'event_type'        => $eventType,
            'answer_id'         => $answerId,
            'question_id'       => $questionId,
            'source'            => $source,
            'event_timestamp'   => $event['event_timestamp'] ?? date('Y-m-d H:i:s'),
            'deduplication_key' => $dedupKey,
            'session_reference' => $event['session_reference'] ?? null,
            'bot_filtered'      => $botFiltered,
            'validated'         => false,
            'ingested_at'       => date('Y-m-d H:i:s'),
        ], true);

        AuditLogger::record(AuditLogger::COMMUNITY_ENGAGEMENT_RECORDED, [
            'answer_id'    => $answerId,
            'question_id'  => $questionId,
            'event_type'   => $eventType,
            'event_id'     => $id,
            'bot_filtered' => $botFiltered,
        ]);

        return ['ok' => true, 'event_id' => $id, 'duplicate' => false, 'bot_filtered' => $botFiltered];
    }

    /**
     * Validate pending engagement events. Bot-filtered events are never
     * validated regardless of any other signal — they simply do not count.
     */
    public function validatePending(int $limit = 500): int
    {
        $db   = db_connect();
        $rows = $db->table('reach_community_engagement_events')
            ->where('validated', false)
            ->limit($limit)
            ->get()->getResultArray();

        $validated = 0;
        foreach ($rows as $row) {
            $isValid = $this->passesBasicValidation($row);
            $db->table('reach_community_engagement_events')
                ->where('id', $row['id'])
                ->update(['validated' => $isValid]);
            if ($isValid) {
                $validated++;
            }
        }

        return $validated;
    }

    private function isBotSourced(array $event, string $source): bool
    {
        if (!empty($event['is_bot']) || !empty($event['is_official_identity'])) {
            return true;
        }

        return in_array(strtolower(trim($source)), self::BOT_SOURCES, true);
    }

    private function passesBasicValidation(array $row): bool
    {
        if (!empty($row['bot_filtered'])) {
            return false;
        }

        if (empty($row['answer_id']) && empty($row['question_id'])) {
            return false;
        }

        if (!empty($row['deduplication_key'])) {
            return true;
        }

        $trustedSources = ['reach_sdk', 'aicountly_com', 'public_site'];
        return in_array($row['source'] ?? '', $trustedSources, true);
    }
}
