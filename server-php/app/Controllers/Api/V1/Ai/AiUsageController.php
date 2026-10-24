<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Ai;

use App\Controllers\BaseApiController;
use App\Libraries\Ai\Generation\AiBudgetService;

/**
 * Phase 3 — AI Usage Ledger + Budgets API.
 *
 * GET /api/v1/ai/usage   — paginated ledger
 * GET /api/v1/ai/budgets — list configured budgets
 * PUT /api/v1/ai/budgets/:id — update a budget
 */
class AiUsageController extends BaseApiController
{
    /**
     * GET /api/v1/ai/usage
     *
     * Filters: provider (provider_key), model (model_key), task_type,
     * content_type, product (slug or id), user (id or email), date / from /
     * to (usage_date, Y-m-d or "today"), billing_month (YYYY-MM or
     * "current"). Each scope filter is AiBudgetService::ledgerScope(), the
     * predicate the budget check sums with, so a budget row opens exactly
     * the spend it counts.
     */
    public function usage(): \CodeIgniter\HTTP\ResponseInterface
    {
        $page    = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = 50;
        $offset  = ($page - 1) * $perPage;

        $where = [];
        $binds = [];
        // URL param → budget scope_type.
        $scopes = [
            'provider'     => 'provider',
            'model'        => 'model',
            'task_type'    => 'task_type',
            'content_type' => 'content_type',
            'product'      => 'product',
            'user'         => 'user',
        ];
        foreach ($scopes as $param => $scope) {
            $value = trim((string) ($this->request->getGet($param) ?? ''));
            if ($value !== '' && ($filter = AiBudgetService::ledgerScope($scope, $value)) !== null) {
                $where[] = $filter[0];
                array_push($binds, ...$filter[1]);
            }
        }
        foreach (['date' => '=', 'from' => '>=', 'to' => '<='] as $param => $op) {
            if (($day = self::day($this->request->getGet($param))) !== null) {
                $where[] = "l.usage_date {$op} ?";
                $binds[] = $day;
            }
        }
        $month = trim((string) ($this->request->getGet('billing_month') ?? ''));
        if ($month === 'current') {
            $month = date('Y-m');
        }
        if (preg_match('/^\d{4}-\d{2}$/', $month) === 1) {
            $where[] = 'l.billing_month = ?';
            $binds[] = $month;
        }

        $from = "FROM reach_ai_usage_ledger l
             LEFT JOIN reach_ai_providers p ON p.id = l.provider_id
             LEFT JOIN reach_ai_models m    ON m.id = l.model_id";
        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $db = \Config\Database::connect();

        $total = (int) $db->query("SELECT COUNT(*) AS cnt {$from}{$whereSql}", $binds)->getRowArray()['cnt'];

        $rows = $db->query(
            "SELECT l.id, l.usage_date, l.billing_month, l.task_type, l.content_type,
                    l.input_tokens, l.output_tokens, l.total_tokens,
                    l.estimated_cost, l.currency, l.product_id, pr.slug AS product_slug, l.user_id,
                    p.provider_key, m.model_key, r.uuid AS generation_uuid
             {$from}
             LEFT JOIN reach_ai_generation_requests r ON r.id = l.generation_request_id
             LEFT JOIN reach_products pr ON pr.id = l.product_id
             {$whereSql}
             ORDER BY l.created_at DESC
             LIMIT ? OFFSET ?",
            [...$binds, $perPage, $offset]
        )->getResultArray();

        return $this->ok(['usage' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage]);
    }

    /** A Y-m-d day from a query value; "today" is the server's today. Anything else is ignored. */
    private static function day(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === 'today') {
            return date('Y-m-d');
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value ? $value : null;
    }

    /**
     * Budgets with what each has used in its current period, summed from the
     * ledger by AiBudgetService (the stored used_amount column is never
     * maintained). used_source says which one a row shows: 'ledger' for
     * daily / monthly budgets, 'stored' for periods the ledger has no key for.
     */
    public function budgets(): \CodeIgniter\HTTP\ResponseInterface
    {
        $db = \Config\Database::connect();
        $rows = $db->query(
            "SELECT b.id, b.scope_type, b.scope_reference, b.period_type,
                    b.warning_limit, b.hard_limit, b.used_amount, b.currency, b.enabled, b.created_at
             FROM reach_ai_budgets b
             ORDER BY b.scope_type, b.period_type"
        )->getResultArray();

        foreach ($rows as &$b) {
            $used = AiBudgetService::usedAmount($db, (string) $b['scope_type'], (string) $b['scope_reference'], (string) $b['period_type']);
            $b['used_source'] = $used === null ? 'stored' : 'ledger';
            if ($used !== null) {
                $b['used_amount'] = number_format($used, 8, '.', '');
            }
        }
        unset($b);

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
