<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Ai;

use App\Controllers\BaseApiController;

/**
 * Phase 3 — AI Usage Ledger + Budgets API.
 *
 * GET /api/v1/ai/usage   — paginated ledger
 * GET /api/v1/ai/budgets — list configured budgets
 * PUT /api/v1/ai/budgets/:id — update a budget
 */
class AiUsageController extends BaseApiController
{
    public function usage(): \CodeIgniter\HTTP\ResponseInterface
    {
        $page    = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = 50;
        $offset  = ($page - 1) * $perPage;

        $db = \Config\Database::connect();

        $total = (int) $db->query("SELECT COUNT(*) AS cnt FROM reach_ai_usage_ledger")->getRowArray()['cnt'];

        $rows = $db->query(
            "SELECT l.id, l.usage_date, l.billing_month, l.task_type, l.content_type,
                    l.input_tokens, l.output_tokens, l.total_tokens,
                    l.estimated_cost, l.currency,
                    p.provider_key, m.model_key
             FROM reach_ai_usage_ledger l
             LEFT JOIN reach_ai_providers p ON p.id = l.provider_id
             LEFT JOIN reach_ai_models m    ON m.id = l.model_id
             ORDER BY l.created_at DESC
             LIMIT ? OFFSET ?",
            [$perPage, $offset]
        )->getResultArray();

        return $this->ok(['usage' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage]);
    }

    public function budgets(): \CodeIgniter\HTTP\ResponseInterface
    {
        $db = \Config\Database::connect();
        $rows = $db->query(
            "SELECT b.id, b.scope_type, b.scope_reference, b.period_type,
                    b.warning_limit, b.hard_limit, b.currency, b.enabled, b.created_at
             FROM reach_ai_budgets b
             WHERE b.deleted_at IS NULL
             ORDER BY b.scope_type, b.period_type"
        )->getResultArray();

        foreach ($rows as &$row) {
            $row['used_amount'] = '0.000000';
        }

        return $this->ok(['budgets' => $rows]);
    }

    public function updateBudget(string $id): \CodeIgniter\HTTP\ResponseInterface
    {
        $data = $this->input();
        $db   = \Config\Database::connect();

        $allowed = ['warning_limit', 'hard_limit', 'enabled'];
        $set     = [];
        $vals    = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $set[]  = "{$field} = ?";
                $vals[] = $data[$field];
            }
        }

        if (empty($set)) {
            return $this->fail('No fields to update', 422);
        }

        $vals[] = (int) $id;
        $db->query("UPDATE reach_ai_budgets SET " . implode(', ', $set) . ", updated_at = NOW() WHERE id = ?", $vals);

        return $this->ok(['updated' => true]);
    }
}
