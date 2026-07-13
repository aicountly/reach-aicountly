<?php

declare(strict_types=1);

namespace App\Libraries\Ai;

/**
 * Phase 3 — Resolves the next fallback provider+model when a generation run fails.
 *
 * Fallback chains are loaded from reach_ai_model_fallbacks.
 * Circular fallback prevention: each fallback_model_id must differ from source_model_id
 * (enforced by DB CHECK constraint). The orchestrator tracks visited model IDs at
 * runtime to prevent infinite recursion across multi-hop chains.
 */
class AiFallbackResolver
{
    private AiProviderRegistry $registry;

    public function __construct(AiProviderRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * Returns the next AiRouteDecision for a failed model within a route, or null if none.
     *
     * @param int[] $alreadyAttemptedModelIds model IDs tried in this request (prevents loops)
     */
    public function resolveNext(
        int $routeId,
        int $failedModelId,
        string $errorCategory,
        array $alreadyAttemptedModelIds = [],
    ): ?AiRouteDecision {
        $db = db_connect();

        $placeholders = implode(',', array_fill(0, max(count($alreadyAttemptedModelIds), 1), '?'));
        $params       = array_merge([$routeId, $failedModelId], $alreadyAttemptedModelIds ?: [0]);

        $query = "
            SELECT
                f.fallback_model_id,
                f.allowed_error_categories,
                m.model_key,
                p.provider_key
            FROM reach_ai_model_fallbacks f
            JOIN reach_ai_models m ON m.id = f.fallback_model_id AND m.deleted_at IS NULL AND m.enabled = TRUE
            JOIN reach_ai_providers p ON p.id = m.provider_id AND p.deleted_at IS NULL AND p.status = 'enabled'
            WHERE f.route_id = ?
              AND f.source_model_id = ?
              AND f.enabled = TRUE
              AND f.fallback_model_id NOT IN ({$placeholders})
            ORDER BY f.fallback_order ASC
            LIMIT 10
        ";

        $rows = $db->query($query, $params)->getResultArray();

        foreach ($rows as $row) {
            $allowed = json_decode($row['allowed_error_categories'], true) ?? [];
            if (! empty($allowed) && ! in_array($errorCategory, $allowed, true)) {
                continue;
            }

            try {
                $provider = $this->registry->get($row['provider_key']);
            } catch (\Throwable) {
                continue;
            }

            return new AiRouteDecision(
                $provider,
                $row['model_key'],
                $routeId,
                false,
            );
        }

        return null;
    }
}
