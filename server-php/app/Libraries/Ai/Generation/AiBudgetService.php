<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Generation;

/**
 * Phase 3 — Budget enforcement for AI generation.
 *
 * Checks daily/monthly budgets before allowing a generation run.
 * AI must never bypass budget checks.
 */
class AiBudgetService
{
    /**
     * The usage-ledger predicate for one budget scope, on a ledger aliased
     * `$alias`: [sql, binds], sql '' for global, null for a scope the ledger
     * cannot count. Product and user budgets name their scope by reference
     * (a product slug or id, a user id or email); the ledger stores ids. check() sums with it and GET v1/ai/usage filters with it,
     * so a budget's used amount and the records it opens are the same rows.
     *
     * @return array{0: string, 1: list<string>}|null
     */
    public static function ledgerScope(string $scope, string $ref, string $alias = 'l'): ?array
    {
        $ref = mb_substr(trim($ref), 0, 128);

        return match ($scope) {
            'global'       => ['', []],
            'provider'     => ["{$alias}.provider_id IN (SELECT id FROM reach_ai_providers WHERE provider_key = ?)", [$ref]],
            'model'        => ["{$alias}.model_id IN (SELECT id FROM reach_ai_models WHERE model_key = ?)", [$ref]],
            'content_type' => ["{$alias}.content_type = ?", [$ref]],
            'task_type'    => ["{$alias}.task_type = ?", [$ref]],
            'product'      => ["{$alias}.product_id IN (SELECT id FROM reach_products WHERE slug = ? OR id::text = ?)", [$ref, $ref]],
            'user'         => ["{$alias}.user_id IN (SELECT id FROM reach_users WHERE id::text = ? OR LOWER(email) = LOWER(?))", [$ref, $ref]],
            default        => null,
        };
    }

    /**
     * Ledger spend for one scope in a period: daily = that usage_date,
     * monthly = that billing_month. Null for a period the ledger has no key
     * for (weekly / custom).
     */
    public static function usedAmount($db, string $scope, string $ref, string $period, ?string $today = null): ?float
    {
        $today ??= date('Y-m-d');
        $filter = self::ledgerScope($scope, $ref);
        $periodSql = match ($period) {
            'daily'   => ['l.usage_date = ?', $today],
            'monthly' => ['l.billing_month = ?', substr($today, 0, 7)],
            default   => null,
        };
        if ($filter === null || $periodSql === null) {
            return null;
        }
        $where = $periodSql[0] . ($filter[0] !== '' ? ' AND ' . $filter[0] : '');
        $row = $db->query(
            "SELECT COALESCE(SUM(l.estimated_cost), 0) AS total FROM reach_ai_usage_ledger l WHERE {$where}",
            [$periodSql[1], ...$filter[1]]
        )->getRowArray();

        return (float) ($row['total'] ?? 0);
    }

    /**
     * Check if a generation would exceed any applicable budgets.
     *
     * @return BudgetCheckResult
     */
    public function check(array $context): BudgetCheckResult
    {
        $db = db_connect();

        $today  = date('Y-m-d');

        // Each scope with the references a budget row may name it by.
        $scope_checks = [
            ['global',       ['global']],
            ['provider',     [$context['provider_key'] ?? 'unknown']],
            ['model',        [$context['model_key'] ?? 'unknown']],
            ['content_type', [$context['content_type'] ?? 'unknown']],
        ];
        if (! empty($context['task_type'])) {
            $scope_checks[] = ['task_type', [(string) $context['task_type']]];
        }
        $product = array_values(array_filter([$context['product_slug'] ?? null, isset($context['product_id']) ? (string) $context['product_id'] : null]));
        if ($product !== []) {
            $scope_checks[] = ['product', $product];
        }
        if (! empty($context['user_id'])) {
            $scope_checks[] = ['user', [(string) $context['user_id']]];
        }

        foreach ($scope_checks as [$scope, $refs]) {
            // Daily
            $budget = $db->table('reach_ai_budgets')
                ->where('scope_type', $scope)
                ->whereIn('scope_reference', $refs)
                ->where('period_type', 'daily')
                ->where('enabled', true)
                ->get()
                ->getRowArray();

            if ($budget) {
                $ref  = (string) $budget['scope_reference'];
                $used = self::usedAmount($db, $scope, $ref, 'daily', $today) ?? 0.0;
                if ($budget['hard_limit'] > 0 && $used >= (float) $budget['hard_limit']) {
                    return new BudgetCheckResult(false, true, $scope, $ref, 'daily', $used, (float) $budget['hard_limit']);
                }
                if ($budget['warning_limit'] > 0 && $used >= (float) $budget['warning_limit']) {
                    return new BudgetCheckResult(true, false, $scope, $ref, 'daily', $used, (float) $budget['hard_limit'], true);
                }
            }

            // Monthly
            $budget = $db->table('reach_ai_budgets')
                ->where('scope_type', $scope)
                ->whereIn('scope_reference', $refs)
                ->where('period_type', 'monthly')
                ->where('enabled', true)
                ->get()
                ->getRowArray();

            if ($budget) {
                $ref  = (string) $budget['scope_reference'];
                $used = self::usedAmount($db, $scope, $ref, 'monthly', $today) ?? 0.0;
                if ($budget['hard_limit'] > 0 && $used >= (float) $budget['hard_limit']) {
                    return new BudgetCheckResult(false, true, $scope, $ref, 'monthly', $used, (float) $budget['hard_limit']);
                }
            }
        }

        return new BudgetCheckResult(true, false);
    }

    /**
     * Record usage after a successful generation run.
     */
    public function recordUsage(array $usageData): void
    {
        $db = db_connect();
        $db->table('reach_ai_usage_ledger')->insert(array_merge($usageData, [
            'usage_date'    => date('Y-m-d'),
            'billing_month' => date('Y-m'),
            'created_at'    => date('Y-m-d H:i:s'),
        ]));
    }
}
