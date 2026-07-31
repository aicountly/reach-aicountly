<?php

namespace App\Models;

use CodeIgniter\Model;

class CommunityAgentRunModel extends Model
{
    protected $table         = 'reach_community_agent_runs';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'run_uuid', 'identity_id', 'operational_role', 'action',
        'target_type', 'target_id', 'target_external_ref',
        'style_profile_id', 'prompt_version', 'provider', 'model_route',
        'outcome', 'block_reason', 'metadata', 'actor_id', 'created_at',
    ];

    /** How many successful runs of this action has this identity performed today (UTC calendar day)? */
    public function countSuccessfulToday(int $identityId, string $action): int
    {
        return $this->where('identity_id', $identityId)
            ->where('action', $action)
            ->where('outcome', 'success')
            ->where('created_at >=', gmdate('Y-m-d 00:00:00'))
            ->countAllResults();
    }

    /** Most recent successful run of this action by this identity, if any. */
    public function lastSuccessful(int $identityId, string $action): ?array
    {
        return $this->where('identity_id', $identityId)
            ->where('action', $action)
            ->where('outcome', 'success')
            ->orderBy('created_at', 'DESC')
            ->first();
    }

    /** Was this external reference (e.g. a comment) authored by this identity? Used for self-reply detection. */
    public function wasAuthoredBy(int $identityId, string $externalRef): bool
    {
        return $this->where('identity_id', $identityId)
            ->where('target_external_ref', $externalRef)
            ->where('outcome', 'success')
            ->countAllResults() > 0;
    }
}
