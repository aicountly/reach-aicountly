<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Ai;

use App\Controllers\BaseApiController;

/**
 * Phase 3 — AI Model API.
 *
 * GET /api/v1/ai/models — list all models
 */
class AiModelController extends BaseApiController
{
    public function index(): \CodeIgniter\HTTP\ResponseInterface
    {
        $db = \Config\Database::connect();
        $rows = $db->query(
            "SELECT m.id, m.model_key, m.model_family, m.display_name,
                    m.provider_id, p.provider_key, p.display_name AS provider_name,
                    m.context_limit, m.max_output_tokens,
                    m.input_cost_per_unit, m.output_cost_per_unit, m.cost_unit_size,
                    m.enabled, m.approval_status,
                    m.supports_structured_output
             FROM reach_ai_models m
             LEFT JOIN reach_ai_providers p ON p.id = m.provider_id
             WHERE m.deleted_at IS NULL
             ORDER BY p.display_name, m.model_key"
        )->getResultArray();

        return $this->ok(['models' => $rows, 'total' => count($rows)]);
    }
}
