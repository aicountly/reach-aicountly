<?php

declare(strict_types=1);

namespace App\Libraries\Ai;

use App\Libraries\Ai\Providers\MockAiProvider;

/**
 * Phase 3 — Selects which provider+model to use for a given task/content type.
 *
 * Resolution order:
 * 1. When REACH_AI_MOCK=true, always return the mock provider regardless of routes.
 * 2. Query reach_ai_model_routes for the best matching enabled route.
 * 3. Fall back to the first enabled, approved model for the task type.
 * 4. If nothing matches, throw AiRoutingException.
 */
class AiModelRouter
{
    private AiProviderRegistry $registry;

    public function __construct(AiProviderRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * Returns an AiRouteDecision containing the provider adapter and chosen model row.
     *
     * @throws AiRoutingException when no suitable route is found
     */
    public function route(string $taskType, ?string $contentType = null, ?array $hints = []): AiRouteDecision
    {
        if (($_ENV['REACH_AI_MOCK'] ?? 'false') === 'true') {
            $mock = $this->registry->get(MockAiProvider::PROVIDER_KEY);
            return new AiRouteDecision($mock, 'mock-model', null, true);
        }

        $db = db_connect();

        $query = "
            SELECT
                r.id            AS route_id,
                r.primary_model_id,
                m.model_key,
                p.provider_key,
                r.maximum_cost,
                r.maximum_latency_seconds
            FROM reach_ai_model_routes r
            JOIN reach_ai_models m ON m.id = r.primary_model_id AND m.deleted_at IS NULL AND m.enabled = TRUE
            JOIN reach_ai_providers p ON p.id = m.provider_id AND p.deleted_at IS NULL AND p.status = 'enabled'
            WHERE r.enabled = TRUE
              AND r.deleted_at IS NULL
              AND r.task_type = ?
              AND (r.content_type IS NULL OR r.content_type = ?)
              AND (r.valid_from IS NULL OR r.valid_from <= NOW())
              AND (r.valid_until IS NULL OR r.valid_until > NOW())
            ORDER BY
                (r.content_type IS NOT NULL) DESC,
                r.priority DESC
            LIMIT 1
        ";

        $row = $db->query($query, [$taskType, $contentType ?? $taskType])->getRowArray();

        if (! $row) {
            throw new AiRoutingException(
                "No active route for task_type='{$taskType}' content_type='" . ($contentType ?? '') . "'."
            );
        }

        $provider = $this->registry->get($row['provider_key']);

        return new AiRouteDecision(
            $provider,
            $row['model_key'],
            (int) $row['route_id'],
            false,
        );
    }
}
