<?php

declare(strict_types=1);

namespace App\Libraries\Ai;

use CodeIgniter\Database\BaseConnection;

/**
 * Strict OpenAI ⇄ Gemini alternation for scheduled generation.
 *
 * The preference returned by preferredNext() is a soft routing hint; the
 * provider that actually produced the accepted output is recorded afterwards
 * via recordActual(), so mid-run failover (e.g. OpenAI quota → Gemini) counts
 * as Gemini's turn and the next run swings back to OpenAI.
 *
 * Each scope alternates independently — blog drafts and community answers
 * keep separate turn state, so a busy blog schedule never starves the
 * community side of one provider or the other.
 */
class ProviderRotationService
{
    public const PROVIDER_OPENAI = 'openai';
    public const PROVIDER_GEMINI = 'gemini';

    public const SCOPE_BLOG_DRAFT      = 'blog_draft';
    public const SCOPE_COMMUNITY_ANSWER = 'community_answer';

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? db_connect();
    }

    public function preferredNext(string $scope): string
    {
        $row = $this->db->query(
            'SELECT last_provider_key FROM reach_ai_provider_rotation WHERE scope = ? FOR UPDATE',
            [$scope]
        )->getRowArray();

        if ($row === null) {
            $this->db->query(
                'INSERT INTO reach_ai_provider_rotation (scope, created_at, updated_at) VALUES (?, NOW(), NOW()) ON CONFLICT (scope) DO NOTHING',
                [$scope]
            );

            return self::PROVIDER_OPENAI;
        }

        return ($row['last_provider_key'] ?? '') === self::PROVIDER_OPENAI
            ? self::PROVIDER_GEMINI
            : self::PROVIDER_OPENAI;
    }

    /**
     * Record the provider that actually generated the accepted output.
     * Non-alternating providers (mock, perplexity) are ignored so test runs
     * and verification calls never disturb the rotation.
     */
    public function recordActual(string $scope, string $providerKey, ?int $generationRequestId = null): void
    {
        if (! in_array($providerKey, [self::PROVIDER_OPENAI, self::PROVIDER_GEMINI], true)) {
            return;
        }

        $this->db->query(
            'INSERT INTO reach_ai_provider_rotation (scope, last_provider_key, last_generation_request_id, created_at, updated_at)
             VALUES (?, ?, ?, NOW(), NOW())
             ON CONFLICT (scope) DO UPDATE SET
                last_provider_key = EXCLUDED.last_provider_key,
                last_generation_request_id = EXCLUDED.last_generation_request_id,
                updated_at = NOW()',
            [$scope, $providerKey, $generationRequestId]
        );
    }
}
