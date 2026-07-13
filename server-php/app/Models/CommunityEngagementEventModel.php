<?php

namespace App\Models;

use CodeIgniter\Model;

class CommunityEngagementEventModel extends Model
{
    protected $table         = 'reach_community_engagement_events';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'uuid', 'event_type', 'answer_id', 'question_id', 'source',
        'event_timestamp', 'deduplication_key', 'session_reference',
        'bot_filtered', 'validated', 'ingested_at',
    ];

    protected array $casts = [
        'bot_filtered' => 'boolean',
        'validated'    => 'boolean',
    ];

    public function existsByDedupKey(string $key): bool
    {
        return $this->where('deduplication_key', $key)->countAllResults() > 0;
    }

    public function countValidatedForAnswer(int $answerId, string $eventType): int
    {
        return $this->where('answer_id', $answerId)
            ->where('event_type', $eventType)
            ->where('validated', true)
            ->countAllResults();
    }
}
